"""Tests verifying the trained SoilSense fertilizer Pipeline and metadata."""

from __future__ import annotations

import json
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
import pytest
from sklearn.compose import ColumnTransformer
from sklearn.pipeline import Pipeline

ML_SERVICE_DIR = Path(__file__).resolve().parent.parent
MODEL_PATH = ML_SERVICE_DIR / "models" / "model.joblib"
METADATA_PATH = ML_SERVICE_DIR / "models" / "metadata.json"
REPORT_PATH = ML_SERVICE_DIR / "REPORT.md"

EXPECTED_FEATURES_9 = [
    "Nitrogen_Level",
    "Phosphorus_Level",
    "Potassium_Level",
    "Soil_pH",
    "Electrical_Conductivity",
    "Soil_Moisture",
    "Temperature",
    "Crop_Type",
    "Soil_Type",
]


@pytest.fixture(scope="module")
def model_pipeline() -> Pipeline:
    assert MODEL_PATH.exists(), f"Missing model artifact: {MODEL_PATH}"
    return joblib.load(MODEL_PATH)


@pytest.fixture(scope="module")
def metadata() -> dict:
    assert METADATA_PATH.exists(), f"Missing metadata artifact: {METADATA_PATH}"
    return json.loads(METADATA_PATH.read_text(encoding="utf-8"))


def test_model_is_single_sklearn_pipeline_with_column_transformer(
    model_pipeline: Pipeline,
) -> None:
    assert isinstance(model_pipeline, Pipeline)
    assert "preprocessor" in model_pipeline.named_steps
    assert isinstance(model_pipeline.named_steps["preprocessor"], ColumnTransformer)
    assert hasattr(model_pipeline, "predict_proba")
    assert hasattr(model_pipeline, "classes_")
    assert len(model_pipeline.classes_) == 7


def test_predict_probabilities_sum_to_one_and_top3(model_pipeline: Pipeline) -> None:
    sample = pd.DataFrame(
        [
            {
                "Nitrogen_Level": 40.0,
                "Phosphorus_Level": 55.0,
                "Potassium_Level": 70.0,
                "Soil_pH": 6.4,
                "Electrical_Conductivity": 1.2,
                "Soil_Moisture": 35.0,
                "Temperature": 26.5,
                "Crop_Type": "Tomato",
                "Soil_Type": "Loamy",
            }
        ]
    )
    probs = model_pipeline.predict_proba(sample)[0]
    assert np.isclose(np.sum(probs), 1.0, atol=1e-6)
    assert np.all(probs >= 0.0) and np.all(probs <= 1.0)

    top3_idx = np.argsort(probs)[::-1][:3]
    top3_probs = probs[top3_idx]
    top3_labels = [str(model_pipeline.classes_[i]) for i in top3_idx]

    assert len(top3_labels) == 3
    assert top3_probs[0] >= top3_probs[1] >= top3_probs[2]
    assert 0.0 < float(np.sum(top3_probs)) <= 1.0 + 1e-6
    assert top3_labels[0] == "Urea"


@pytest.mark.parametrize(
    "unseen_crop",
    ["Pechay", "Eggplant", "Okra", "Bitter Gourd", "String Beans"],
)
def test_handles_unseen_soilsense_crops_and_missing_values(
    model_pipeline: Pipeline, unseen_crop: str
) -> None:
    sample = pd.DataFrame(
        [
            {
                "Nitrogen_Level": 95.0,
                "Phosphorus_Level": 25.0,
                "Potassium_Level": 65.0,
                "Soil_pH": 6.5,
                "Electrical_Conductivity": np.nan,
                "Soil_Moisture": 40.0,
                "Temperature": 28.0,
                "Crop_Type": unseen_crop,
                "Soil_Type": "UnknownSoil",
            }
        ]
    )
    probs = model_pipeline.predict_proba(sample)[0]
    pred = model_pipeline.predict(sample)[0]
    assert np.isclose(np.sum(probs), 1.0, atol=1e-6)
    assert str(pred) in set(map(str, model_pipeline.classes_))
    assert str(pred) == "DAP"


def test_metadata_schema_and_synthetic_disclosures(
    model_pipeline: Pipeline, metadata: dict
) -> None:
    required_keys = {
        "model_version",
        "trained_at_utc",
        "random_state",
        "task",
        "target",
        "selected_model",
        "selection_rationale",
        "features",
        "numeric_features",
        "categorical_features",
        "excluded_features",
        "classes",
        "split",
        "cleaning_summary",
        "cv_comparison",
        "test_metrics",
        "feature_importances",
        "ablation_9_vs_19",
        "leakage_and_imbalance",
        "synthetic_dataset_notes",
    }
    missing = required_keys - set(metadata.keys())
    assert not missing, f"metadata.json missing required keys: {missing}"

    assert metadata["task"] == "fertilizer_classification_only"
    assert metadata["target"] == "Recommended_Fertilizer"
    assert metadata["features"] == EXPECTED_FEATURES_9
    assert metadata["classes"] == [str(c) for c in model_pipeline.classes_]

    tm = metadata["test_metrics"]
    for metric_key in ("accuracy", "macro_f1", "weighted_f1", "per_class", "confusion_matrix"):
        assert metric_key in tm
    assert 0.5 <= tm["accuracy"] <= 0.90

    synth = metadata["synthetic_dataset_notes"]
    assert synth["is_synthetic"] is True
    assert "bayes_ceiling_9_features" in synth
    assert "bayes_ceiling_19_features" in synth

    assert REPORT_PATH.exists() and REPORT_PATH.stat().st_size > 500
