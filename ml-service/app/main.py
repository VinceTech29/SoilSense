"""FastAPI inference service for SoilSense fertilizer classification."""

from __future__ import annotations

import hmac
import json
import logging
import os
from contextlib import asynccontextmanager
from pathlib import Path
from typing import Any

import joblib
import numpy as np
import pandas as pd
from fastapi import Depends, FastAPI, HTTPException, Request, Security, status
from fastapi.encoders import jsonable_encoder
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from fastapi.security import APIKeyHeader
from starlette.exceptions import HTTPException as StarletteHTTPException

from app.schemas import (
    ErrorResponse,
    HealthResponse,
    ModelInfoResponse,
    PredictRequest,
    PredictResponse,
    TopPrediction,
)

logger = logging.getLogger("soilsense.ml")

SERVICE_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_MODEL_PATH = SERVICE_ROOT / "models" / "model.joblib"
DEFAULT_METADATA_PATH = SERVICE_ROOT / "models" / "metadata.json"

TRAINING_CROP_ALIASES: dict[str, str] = {
    "sweet corn": "Maize",
    "corn": "Maize",
    "maize": "Maize",
    "cotton": "Cotton",
    "potato": "Potato",
    "rice": "Rice",
    "sugarcane": "Sugarcane",
    "tomato": "Tomato",
    "wheat": "Wheat",
}

TRAINING_NUMERIC_BOUNDS: dict[str, tuple[float, float, str]] = {
    "Nitrogen_Level": (20.0, 159.0, "mg/kg"),
    "Phosphorus_Level": (10.0, 89.0, "mg/kg"),
    "Potassium_Level": (10.0, 119.0, "mg/kg"),
    "Soil_pH": (4.5, 8.5, "pH"),
    "Electrical_Conductivity": (0.1, 3.0, "dS/m"),
    "Soil_Moisture": (10.0, 60.0, "%"),
    "Temperature": (10.0, 40.0, "°C"),
}

api_key_header = APIKeyHeader(name="X-API-KEY", auto_error=False)


def _resolve_path(env_var: str, default_path: Path) -> Path:
    raw = os.environ.get(env_var, "").strip()
    if not raw:
        return default_path
    candidate = Path(raw)
    return candidate if candidate.is_absolute() else (SERVICE_ROOT / candidate)


def _get_low_confidence_threshold() -> float:
    raw = os.environ.get("LOW_CONFIDENCE_THRESHOLD", "0.55").strip()
    try:
        val = float(raw)
    except ValueError:
        return 0.55
    return min(max(val, 0.0), 1.0)


def _get_cors_origins() -> list[str]:
    raw = os.environ.get("CORS_ALLOWED_ORIGINS", "").strip()
    if not raw:
        return []
    return [origin.strip() for origin in raw.split(",") if origin.strip() and origin.strip() != "*"]


def verify_api_key(provided_key: str | None = Security(api_key_header)) -> str:
    """Fail-closed X-API-KEY check using constant-time comparison."""
    expected_key = os.environ.get("ML_API_KEY", "").strip()
    if not expected_key:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="ML_API_KEY is not configured on the server.",
        )
    if not provided_key or not hmac.compare_digest(provided_key, expected_key):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing X-API-KEY header.",
        )
    return provided_key


def _normalize_crop(crop_raw: str, known_crops: set[str]) -> tuple[str, str | None, list[str]]:
    """Normalize SoilSense crop names to Kaggle training vocabulary where applicable."""
    warnings: list[str] = []
    cleaned = crop_raw.strip()
    alias = TRAINING_CROP_ALIASES.get(cleaned.lower())

    if alias is not None:
        mapped_from = cleaned if cleaned != alias else None
        return alias, mapped_from, warnings

    if cleaned not in known_crops:
        known_list = ", ".join(sorted(known_crops))
        warnings.append(
            f"Crop '{cleaned}' is outside training categories ({known_list}); "
            "prediction relies on soil chemistry features."
        )
    return cleaned, None, warnings


def _collect_range_warnings(row: dict[str, Any]) -> list[str]:
    """Flag inputs that are agronomically valid but outside the synthetic training domain."""
    warnings: list[str] = []
    for col, (low, high, unit) in TRAINING_NUMERIC_BOUNDS.items():
        val = float(row[col])
        if val < low or val > high:
            warnings.append(
                f"{col}={val:.2f} {unit} is outside synthetic training range [{low:.2f}, {high:.2f}] {unit}."
            )
    return warnings


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Load model.joblib and metadata.json once on startup."""
    model_path = _resolve_path("MODEL_PATH", DEFAULT_MODEL_PATH)
    metadata_path = _resolve_path("METADATA_PATH", DEFAULT_METADATA_PATH)

    app.state.model = None
    app.state.metadata = None
    app.state.model_loaded = False

    try:
        if not model_path.exists():
            raise FileNotFoundError(f"Model artifact not found: {model_path}")
        if not metadata_path.exists():
            raise FileNotFoundError(f"Metadata artifact not found: {metadata_path}")

        app.state.model = joblib.load(model_path)
        app.state.metadata = json.loads(metadata_path.read_text(encoding="utf-8"))
        app.state.model_loaded = True
        logger.info("Loaded model %s from %s", app.state.metadata.get("selected_model"), model_path)
    except Exception:
        logger.exception("Failed to load ML artifacts at startup")

    yield

    app.state.model = None
    app.state.model_loaded = False


def create_app() -> FastAPI:
    """Create and configure the SoilSense ML FastAPI application."""
    application = FastAPI(
        title="SoilSense Fertilizer Classification Service",
        version="1.0.0",
        description=(
            "Classifies recommended fertilizer type (WHAT fertilizer, never kg/ha dosage) "
            "from SoilSense 9-feature soil & crop inputs."
        ),
        lifespan=lifespan,
    )

    cors_origins = _get_cors_origins()
    if cors_origins:
        application.add_middleware(
            CORSMiddleware,
            allow_origins=cors_origins,
            allow_credentials=False,
            allow_methods=["GET", "POST"],
            allow_headers=["Content-Type", "X-API-KEY"],
        )

    @application.exception_handler(RequestValidationError)
    async def validation_exception_handler(
        request: Request, exc: RequestValidationError
    ) -> JSONResponse:
        status_code = getattr(status, "HTTP_422_UNPROCESSABLE_CONTENT", 422)
        payload = ErrorResponse(
            error="validation_error",
            detail=jsonable_encoder(exc.errors()),
            status_code=status_code,
        )
        return JSONResponse(
            status_code=status_code,
            content=payload.model_dump(),
        )

    @application.exception_handler(StarletteHTTPException)
    async def http_exception_handler(
        request: Request, exc: StarletteHTTPException
    ) -> JSONResponse:
        payload = ErrorResponse(
            error="http_error",
            detail=exc.detail,
            status_code=exc.status_code,
        )
        return JSONResponse(status_code=exc.status_code, content=payload.model_dump())

    @application.exception_handler(Exception)
    async def unhandled_exception_handler(request: Request, exc: Exception) -> JSONResponse:
        logger.exception("Unhandled error during %s %s", request.method, request.url.path)
        payload = ErrorResponse(
            error="internal_server_error",
            detail="An unexpected internal error occurred.",
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        )
        return JSONResponse(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            content=payload.model_dump(),
        )

    @application.get("/health", response_model=HealthResponse, tags=["Health"])
    def health(request: Request) -> HealthResponse:
        loaded = bool(getattr(request.app.state, "model_loaded", False))
        metadata = getattr(request.app.state, "metadata", None) or {}
        return HealthResponse(
            status="ok" if loaded else "degraded",
            model_loaded=loaded,
            model_version=metadata.get("model_version") if loaded else None,
        )

    @application.get(
        "/model-info",
        response_model=ModelInfoResponse,
        dependencies=[Depends(verify_api_key)],
        tags=["Model"],
    )
    def model_info(request: Request) -> ModelInfoResponse:
        if not getattr(request.app.state, "model_loaded", False):
            raise HTTPException(
                status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
                detail="Model artifacts are not loaded.",
            )
        meta: dict[str, Any] = request.app.state.metadata
        test_metrics = meta.get("test_metrics", {})
        ablation = meta.get("ablation_9_vs_19", {})
        return ModelInfoResponse(
            model_version=meta["model_version"],
            best_model=meta["selected_model"],
            task=meta["task"],
            features=meta["features"],
            classes=meta["classes"],
            metrics={
                "cv_9_features": ablation.get("cv_9_features", {}),
                "cv_19_features": ablation.get("cv_19_features", {}),
                "test_accuracy": test_metrics.get("accuracy"),
                "test_macro_f1": test_metrics.get("macro_f1"),
                "test_weighted_f1": test_metrics.get("weighted_f1"),
            },
            dataset_name="fertilizer_recommendation.csv",
            trained_at_utc=meta["trained_at_utc"],
            sklearn_version=meta["sklearn_version"],
            low_confidence_threshold=_get_low_confidence_threshold(),
            synthetic_dataset_notes=meta.get("synthetic_dataset_notes", {}),
        )

    @application.post(
        "/predict",
        response_model=PredictResponse,
        dependencies=[Depends(verify_api_key)],
        tags=["Inference"],
    )
    def predict(payload: PredictRequest, request: Request) -> PredictResponse:
        if not getattr(request.app.state, "model_loaded", False):
            raise HTTPException(
                status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
                detail="Model artifacts are not loaded.",
            )

        model = request.app.state.model
        meta: dict[str, Any] = request.app.state.metadata
        known_crops = set(
            meta.get("known_training_categories", {}).get(
                "Crop_Type",
                ["Cotton", "Maize", "Potato", "Rice", "Sugarcane", "Tomato", "Wheat"],
            )
        )

        normalized_crop, crop_mapped_from, crop_warnings = _normalize_crop(
            payload.crop, known_crops
        )

        feature_row = {
            "Nitrogen_Level": payload.n,
            "Phosphorus_Level": payload.p,
            "Potassium_Level": payload.k,
            "Soil_pH": payload.ph,
            "Electrical_Conductivity": payload.ec,
            "Soil_Moisture": payload.moisture,
            "Temperature": payload.temperature,
            "Crop_Type": normalized_crop,
            "Soil_Type": payload.soil_type,
        }

        warnings = crop_warnings + _collect_range_warnings(feature_row)
        input_df = pd.DataFrame([feature_row], columns=meta["features"])

        probs = model.predict_proba(input_df)[0]
        classes = [str(c) for c in model.classes_]
        top_indices = np.argsort(probs)[::-1][:3]
        top_predictions = [
            TopPrediction(
                fertilizer=classes[idx],
                probability=round(float(probs[idx]), 6),
            )
            for idx in top_indices
        ]

        predicted_fertilizer = top_predictions[0].fertilizer
        confidence = top_predictions[0].probability
        threshold = _get_low_confidence_threshold()
        low_confidence = confidence < threshold

        if low_confidence:
            warnings.append(
                f"Top prediction confidence ({confidence:.2f}) is below threshold ({threshold:.2f})."
            )

        return PredictResponse(
            predicted_fertilizer=predicted_fertilizer,
            confidence=confidence,
            low_confidence=low_confidence,
            top_predictions=top_predictions,
            model_version=meta["model_version"],
            crop_mapped_from=crop_mapped_from,
            warnings=warnings,
        )

    return application


app = create_app()
