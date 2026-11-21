"""Pydantic v2 request, response, and error schemas for SoilSense ML API."""

from __future__ import annotations

from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

SoilTypeLiteral = Literal["Clay", "Loamy", "Sandy", "Silt"]

SOIL_TYPE_ALIASES: dict[str, SoilTypeLiteral] = {
    "clay": "Clay",
    "loam": "Loamy",
    "loamy": "Loamy",
    "sand": "Sandy",
    "sandy": "Sandy",
    "silt": "Silt",
    "silty": "Silt",
}


class PredictRequest(BaseModel):
    """Input sensor & sample payload aligned with SoilSense telemetry and samples."""

    model_config = ConfigDict(str_strip_whitespace=True)

    n: float = Field(..., ge=0.0, le=500.0, description="Nitrogen level (mg/kg or kg/ha)")
    p: float = Field(..., ge=0.0, le=500.0, description="Phosphorus level (mg/kg or kg/ha)")
    k: float = Field(..., ge=0.0, le=500.0, description="Potassium level (mg/kg or kg/ha)")
    ph: float = Field(..., ge=0.0, le=14.0, description="Soil pH [0.0, 14.0]")
    ec: float = Field(
        ...,
        ge=0.0,
        le=30.0,
        description="Electrical conductivity in dS/m (mS/cm) [0.0, 30.0]",
    )
    moisture: float = Field(..., ge=0.0, le=100.0, description="Calibrated soil moisture (%)")
    temperature: float = Field(..., ge=-10.0, le=60.0, description="Soil temperature (°C)")
    crop: str = Field(..., min_length=1, max_length=64, description="Target SoilSense crop name")
    soil_type: SoilTypeLiteral = Field(
        default="Loamy",
        description="Soil texture class (Clay, Loamy, Sandy, Silt); defaults to Loamy",
    )

    @field_validator("crop")
    @classmethod
    def validate_crop_non_empty(cls, value: str) -> str:
        cleaned = value.strip()
        if not cleaned:
            raise ValueError("crop must be a non-empty string")
        return cleaned

    @field_validator("soil_type", mode="before")
    @classmethod
    def normalize_soil_type(cls, value: Any) -> SoilTypeLiteral:
        if value is None:
            return "Loamy"
        if isinstance(value, str):
            cleaned = value.strip()
            if not cleaned:
                return "Loamy"
            mapped = SOIL_TYPE_ALIASES.get(cleaned.lower())
            if mapped is not None:
                return mapped
        raise ValueError("soil_type must be one of: Clay, Loamy, Sandy, Silt")


class TopPrediction(BaseModel):
    """Single ranked fertilizer class and probability."""

    fertilizer: str
    probability: float = Field(..., ge=0.0, le=1.0)


class PredictResponse(BaseModel):
    """Fertilizer type classification response (never dosage/kg per ha)."""

    predicted_fertilizer: str
    confidence: float = Field(..., ge=0.0, le=1.0)
    low_confidence: bool
    top_predictions: list[TopPrediction]
    model_version: str
    crop_mapped_from: str | None = None
    warnings: list[str] = Field(default_factory=list)


class HealthResponse(BaseModel):
    """Unauthenticated container healthcheck response."""

    status: str
    model_loaded: bool
    model_version: str | None = None


class ModelInfoResponse(BaseModel):
    """Metadata summary for the loaded SoilSense fertilizer model."""

    model_version: str
    best_model: str
    task: str
    features: list[str]
    classes: list[str]
    metrics: dict[str, Any]
    dataset_name: str
    trained_at_utc: str
    sklearn_version: str
    low_confidence_threshold: float
    synthetic_dataset_notes: dict[str, Any]


class ErrorResponse(BaseModel):
    """Structured error envelope for 401/422/500/503 responses."""

    error: str
    detail: Any
    status_code: int
