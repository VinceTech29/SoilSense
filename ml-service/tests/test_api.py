"""FastAPI endpoint tests for SoilSense fertilizer classification service."""

from __future__ import annotations

import sys
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app.main import create_app


@pytest.fixture
def api_client(monkeypatch: pytest.MonkeyPatch) -> TestClient:
    monkeypatch.setenv("ML_API_KEY", "test-secret-key-123")
    monkeypatch.setenv("LOW_CONFIDENCE_THRESHOLD", "0.55")
    monkeypatch.setenv("CORS_ALLOWED_ORIGINS", "")
    app = create_app()
    with TestClient(app) as client:
        yield client


def test_health_endpoint_unauthenticated(api_client: TestClient) -> None:
    resp = api_client.get("/health")
    assert resp.status_code == 200
    body = resp.json()
    assert body["status"] == "ok"
    assert body["model_loaded"] is True
    assert body["model_version"] == "1.0.0"


def test_auth_missing_and_invalid_key_returns_401(api_client: TestClient) -> None:
    payload = {
        "n": 40,
        "p": 55,
        "k": 70,
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
                "n": 40,
                "p": 55,
                "k": 70,
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
    assert body["model_version"] == "1.0.0"
    assert body["best_model"] == "GradientBoostingClassifier"
    assert body["task"] == "fertilizer_classification_only"
    assert len(body["features"]) == 9
    assert len(body["classes"]) == 7
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
            "n": 35,
            "p": 55,
            "k": 70,
            "ph": 6.4,
            "ec": 1.2,
            "moisture": 35.0,
            "temperature": 26.5,
            "crop": crop_input,
            "soil_type": "loamy",
        },
    )
    assert resp.status_code == 200
    body = resp.json()
    assert body["predicted_fertilizer"] == "Urea"
    assert body["confidence"] > 0.55
    assert body["low_confidence"] is False
    assert body["model_version"] == "1.0.0"
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
            "n": 0.0,
            "p": 500.0,
            "k": 0.0,
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


def test_predict_low_confidence_flag(api_client: TestClient) -> None:
    # Non-rule region (N>=60, P>=40, K>=40, 5.5<=pH<=7.8) has ~35% NPK vs ~11% others
    resp = api_client.post(
        "/predict",
        headers={"X-API-KEY": "test-secret-key-123"},
        json={
            "n": 100.0,
            "p": 70.0,
            "k": 80.0,
            "ph": 6.5,
            "ec": 1.5,
            "moisture": 35.0,
            "temperature": 25.0,
            "crop": "Tomato",
            "soil_type": "Clay",
        },
    )
    assert resp.status_code == 200
    body = resp.json()
    assert body["confidence"] < 0.55
    assert body["low_confidence"] is True
    assert any("below threshold" in w for w in body["warnings"])


@pytest.mark.parametrize(
    "bad_payload",
    [
        {"n": -1, "p": 40, "k": 40, "ph": 6.5, "ec": 1.0, "moisture": 30, "temperature": 25, "crop": "Tomato"},
        {"n": 501, "p": 40, "k": 40, "ph": 6.5, "ec": 1.0, "moisture": 30, "temperature": 25, "crop": "Tomato"},
        {"n": 80, "p": 40, "k": 40, "ph": 14.5, "ec": 1.0, "moisture": 30, "temperature": 25, "crop": "Tomato"},
        {"n": 80, "p": 40, "k": 40, "ph": 6.5, "ec": 31.0, "moisture": 30, "temperature": 25, "crop": "Tomato"},
        {"n": 80, "p": 40, "k": 40, "ph": 6.5, "ec": 1.0, "moisture": 105, "temperature": 25, "crop": "Tomato"},
        {"n": 80, "p": 40, "k": 40, "ph": 6.5, "ec": 1.0, "moisture": 30, "temperature": 65, "crop": "Tomato"},
        {"n": 80, "p": 40, "k": 40, "ph": 6.5, "ec": 1.0, "moisture": 30, "temperature": 25, "crop": "   "},
        {"n": 80, "p": 40, "k": 40, "ph": 6.5, "ec": 1.0, "moisture": 30, "temperature": 25, "crop": "Tomato", "soil_type": "Gravel"},
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
