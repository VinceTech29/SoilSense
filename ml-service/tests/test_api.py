"""FastAPI endpoint tests for SoilSense 4-sensor fertilizer classification service."""

from __future__ import annotations

import sys
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.main import create_app
from app.schemas import PredictRequest


@pytest.fixture
def api_client(monkeypatch: pytest.MonkeyPatch) -> TestClient:
    monkeypatch.setenv("ML_API_KEY", "test-secret-key-123")
    monkeypatch.setenv("LOW_CONFIDENCE_THRESHOLD", "0.55")
    monkeypatch.setenv("CORS_ALLOWED_ORIGINS", "")
    app = create_app()
    with TestClient(app) as client:
        yield client


def test_predict_request_schema_has_no_npk_fields() -> None:
    assert set(PredictRequest.model_fields.keys()) == {
        "ph",
        "ec",
        "moisture",
        "temperature",
        "crop",
        "soil_type",
    }
    for forbidden in ("n", "p", "k", "nitrogen", "phosphorus", "potassium"):
        assert forbidden not in PredictRequest.model_fields


def test_health_endpoint_unauthenticated(api_client: TestClient) -> None:
    resp = api_client.get("/health")
    assert resp.status_code == 200
    body = resp.json()
    assert body["status"] == "ok"
    assert body["model_loaded"] is True
    assert body["model_version"] == "2.0.0"


def test_auth_missing_and_invalid_key_returns_401(api_client: TestClient) -> None:
    payload = {
        "ph": 6.4,
        "ec": 1.2,
        "moisture": 35.0,
        "temperature": 26.5,
        "crop": "Tomato",
    }

    assert api_client.get("/model-info").status_code == 401
    assert api_client.get("/model-info", headers={"X-API-KEY": "wrong"}).status_code == 401
    assert api_client.post("/predict", json=payload).status_code == 401
    assert (
        api_client.post("/predict", json=payload, headers={"X-API-KEY": "wrong"}).status_code
        == 401
    )


def test_auth_fails_closed_with_503_when_api_key_unset(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.delenv("ML_API_KEY", raising=False)
    app = create_app()
    with TestClient(app) as client:
        resp = client.get("/model-info", headers={"X-API-KEY": "anything"})
        assert resp.status_code == 503
        assert resp.json()["error"] == "http_error"

        pred_resp = client.post(
            "/predict",
            json={
                "ph": 6.4,
                "ec": 1.2,
                "moisture": 35.0,
                "temperature": 26.5,
                "crop": "Tomato",
            },
            headers={"X-API-KEY": "anything"},
        )
        assert pred_resp.status_code == 503


def test_model_info_payload(api_client: TestClient) -> None:
    resp = api_client.get("/model-info", headers={"X-API-KEY": "test-secret-key-123"})
    assert resp.status_code == 200
    body = resp.json()
    assert body["model_version"] == "2.0.0"
    assert body["best_model"] == "GradientBoostingClassifier"
    assert body["task"] == "fertilizer_classification_only"
    assert body["features"] == [
        "Soil_pH",
        "Electrical_Conductivity",
        "Soil_Moisture",
        "Temperature",
        "Crop_Type",
        "Soil_Type",
    ]
    assert len(body["classes"]) == 7
    assert "cv_6_features" in body["metrics"]
    assert "cv_19_features" in body["metrics"]
    assert body["dataset_name"] == "fertilizer_recommendation.csv"
    assert body["low_confidence_threshold"] == pytest.approx(0.55)
    assert body["synthetic_dataset_notes"]["is_synthetic"] is True


@pytest.mark.parametrize(
    ("crop_input", "expected_mapped_from", "expect_crop_warning"),
    [
        ("Tomato", None, False),
        ("Sweet Corn", "Sweet Corn", False),
        ("Pechay", None, True),
    ],
)
def test_predict_valid_soilsense_crops(
    api_client: TestClient,
    crop_input: str,
    expected_mapped_from: str | None,
    expect_crop_warning: bool,
) -> None:
    resp = api_client.post(
        "/predict",
        headers={"X-API-KEY": "test-secret-key-123"},
        json={
            "ph": 4.8,
            "ec": 1.2,
            "moisture": 35.0,
            "temperature": 26.5,
            "crop": crop_input,
            "soil_type": "loamy",
        },
    )
    assert resp.status_code == 200
    body = resp.json()
    assert body["predicted_fertilizer"] == "Compost"
    assert 0.0 < body["confidence"] <= 1.0
    assert body["model_version"] == "2.0.0"
    assert body["crop_mapped_from"] == expected_mapped_from

    top3 = body["top_predictions"]
    assert len(top3) == 3
    probs = [item["probability"] for item in top3]
    assert probs == sorted(probs, reverse=True)
    assert 0.0 < sum(probs) <= 1.0001
    assert "dosage" not in body and "kg_per_ha" not in body

    has_crop_warning = any("outside training categories" in w for w in body["warnings"])
    assert has_crop_warning is expect_crop_warning


def test_predict_boundary_values_and_domain_warnings(api_client: TestClient) -> None:
    resp = api_client.post(
        "/predict",
        headers={"X-API-KEY": "test-secret-key-123"},
        json={
            "ph": 0.0,
            "ec": 30.0,
            "moisture": 100.0,
            "temperature": 60.0,
            "crop": "Tomato",
        },
    )
    assert resp.status_code == 200
    body = resp.json()
    assert len(body["top_predictions"]) == 3
    assert any("Electrical_Conductivity" in w for w in body["warnings"])
    assert any("Soil_pH" in w for w in body["warnings"])


@pytest.mark.parametrize(
    ("ph_value", "expected_fertilizer"),
    [
        (4.8, "Compost"),
        (6.5, "Urea"),
        (8.2, "Zinc Sulphate"),
    ],
)
def test_predict_extreme_vs_neutral_ph_and_low_confidence_flag(
    api_client: TestClient,
    ph_value: float,
    expected_fertilizer: str,
) -> None:
    # Without N-P-K, honest synthetic posterior probabilities peak around ~0.30-0.34,
    # so default threshold (0.55) flags low_confidence=True across both extreme and neutral pH.
    resp = api_client.post(
        "/predict",
        headers={"X-API-KEY": "test-secret-key-123"},
        json={
            "ph": ph_value,
            "ec": 1.5,
            "moisture": 35.0,
            "temperature": 25.0,
            "crop": "Tomato",
            "soil_type": "Clay",
        },
    )
    assert resp.status_code == 200
    body = resp.json()
    assert body["predicted_fertilizer"] == expected_fertilizer
    assert body["confidence"] < 0.55
    assert body["low_confidence"] is True
    assert any("below threshold" in w for w in body["warnings"])


def test_predict_low_confidence_clears_when_threshold_below_confidence(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("ML_API_KEY", "test-secret-key-123")
    monkeypatch.setenv("LOW_CONFIDENCE_THRESHOLD", "0.25")
    app = create_app()
    with TestClient(app) as client:
        resp = client.post(
            "/predict",
            headers={"X-API-KEY": "test-secret-key-123"},
            json={
                "ph": 4.8,
                "ec": 1.2,
                "moisture": 35.0,
                "temperature": 26.5,
                "crop": "Tomato",
            },
        )
        assert resp.status_code == 200
        body = resp.json()
        assert body["predicted_fertilizer"] == "Compost"
        assert body["confidence"] >= 0.25
        assert body["low_confidence"] is False
        assert not any("below threshold" in w for w in body["warnings"])


@pytest.mark.parametrize(
    "bad_payload",
    [
        {"ph": -0.1, "ec": 1.0, "moisture": 30, "temperature": 25, "crop": "Tomato"},
        {"ph": 14.5, "ec": 1.0, "moisture": 30, "temperature": 25, "crop": "Tomato"},
        {"ph": 6.5, "ec": -0.5, "moisture": 30, "temperature": 25, "crop": "Tomato"},
        {"ph": 6.5, "ec": 31.0, "moisture": 30, "temperature": 25, "crop": "Tomato"},
        {"ph": 6.5, "ec": 1.0, "moisture": -1, "temperature": 25, "crop": "Tomato"},
        {"ph": 6.5, "ec": 1.0, "moisture": 105, "temperature": 25, "crop": "Tomato"},
        {"ph": 6.5, "ec": 1.0, "moisture": 30, "temperature": -11, "crop": "Tomato"},
        {"ph": 6.5, "ec": 1.0, "moisture": 30, "temperature": 65, "crop": "Tomato"},
        {"ph": 6.5, "ec": 1.0, "moisture": 30, "temperature": 25, "crop": "   "},
        {"ph": 6.5, "ec": 1.0, "moisture": 30, "temperature": 25, "crop": "Tomato", "soil_type": "Gravel"},
        {"ec": 1.0, "moisture": 30, "temperature": 25, "crop": "Tomato"},
    ],
)
def test_predict_invalid_inputs_return_422(
    api_client: TestClient, bad_payload: dict
) -> None:
    resp = api_client.post(
        "/predict",
        headers={"X-API-KEY": "test-secret-key-123"},
        json=bad_payload,
    )
    assert resp.status_code == 422
    body = resp.json()
    assert body["error"] == "validation_error"
    assert body["status_code"] == 422


def test_cors_disabled_by_default_no_wildcard(api_client: TestClient) -> None:
    resp = api_client.get("/health", headers={"Origin": "https://evil.example.com"})
    assert resp.status_code == 200
    assert "access-control-allow-origin" not in resp.headers
