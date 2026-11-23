"""Tests verifying the trained SoilSense 4-sensor (6-feature) Pipeline and metadata."""

from __future__ import annotations

import json
import sys
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
import pytest
from sklearn.compose import ColumnTransformer
from sklearn.pipeline import Pipeline

ML_SERVICE_DIR = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ML_SERVICE_DIR))

from training.train import train_and_export

MODEL_PATH = ML_SERVICE_DIR / "models" / "model.joblib"
METADATA_PATH = ML_SERVICE_DIR / "models" / "metadata.json"
REPORT_PATH = ML_SERVICE_DIR / "REPORT.md"

EXPECTED_FEATURES_6 = [
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


@pytest.mark.parametrize(
    ("soil_ph", "expected_top_class"),
    [
        (4.8, "Compost"),
        (6.5, "Urea"),
        (8.2, "Zinc Sulphate"),
    ],
)
def test_predict_probabilities_sum_to_one_and_ph_rules(
    model_pipeline: Pipeline,
    soil_ph: float,
    expected_top_class: str,
) -> None:
    sample = pd.DataFrame(
        [
            {
                "Soil_pH": soil_ph,
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
    assert top3_labels[0] == expected_top_class
    # Without N-P-K, honest synthetic posterior probabilities remain around 0.28-0.40
    assert 0.25 <= float(top3_probs[0]) <= 0.45


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
                "Soil_pH": 4.9,
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
    assert str(pred) == "Compost"


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
        "ablation_6_vs_19",
        "leakage_and_imbalance",
        "synthetic_dataset_notes",
    }
    missing = required_keys - set(metadata.keys())
    assert not missing, f"metadata.json missing required keys: {missing}"

    assert metadata["task"] == "fertilizer_classification_only"
    assert metadata["target"] == "Recommended_Fertilizer"
    assert metadata["features"] == EXPECTED_FEATURES_6
    for npk_col in ("Nitrogen_Level", "Phosphorus_Level", "Potassium_Level"):
        assert npk_col not in metadata["features"]
        assert npk_col in metadata["excluded_features"]
    assert metadata["classes"] == [str(c) for c in model_pipeline.classes_]

    tm = metadata["test_metrics"]
    for metric_key in ("accuracy", "macro_f1", "weighted_f1", "per_class", "confusion_matrix"):
        assert metric_key in tm
    assert 0.25 <= tm["accuracy"] <= 0.40

    ablation = metadata["ablation_6_vs_19"]
    assert ablation["ablation_skipped"] is False
    assert ablation["features_6_count"] == 6
    assert ablation["features_19_count"] == 19
    assert ablation["cv_19_features"]["cv_accuracy_mean"] > ablation["cv_6_features"]["cv_accuracy_mean"] + 0.4

    synth = metadata["synthetic_dataset_notes"]
    assert synth["is_synthetic"] is True
    assert "bayes_ceiling_6_features" in synth
    assert "bayes_ceiling_19_features" in synth
    assert "npk_rule_share" in synth

    assert REPORT_PATH.exists() and REPORT_PATH.stat().st_size > 500


def test_training_pipeline_accepts_6_feature_csv_without_npk_columns(tmp_path: Path) -> None:
    rng = np.random.default_rng(42)
    rows = []
    for i in range(60):
        if i % 3 == 0:
            ph, label = float(rng.uniform(4.6, 5.3)), "Compost"
        elif i % 3 == 1:
            ph, label = float(rng.uniform(7.9, 8.4)), "Zinc Sulphate"
        else:
            ph, label = float(rng.uniform(6.0, 7.2)), "NPK"
        rows.append(
            {
                "Soil_pH": round(ph, 2),
                "Electrical_Conductivity": round(float(rng.uniform(0.4, 2.2)), 2),
                "Soil_Moisture": round(float(rng.uniform(20.0, 55.0)), 1),
                "Temperature": round(float(rng.uniform(20.0, 32.0)), 1),
                "Crop_Type": "Tomato" if i % 2 == 0 else "Maize",
                "Soil_Type": "Loamy" if i % 2 == 0 else "Clay",
                "Recommended_Fertilizer": label,
            }
        )

    csv_path = tmp_path / "field_6sensor.csv"
    pd.DataFrame(rows).to_csv(csv_path, index=False)

    out_dir = tmp_path / "models"
    meta = train_and_export(csv_path, out_dir, random_state=42, dataset_origin="auto")
    assert meta["features"] == EXPECTED_FEATURES_6
    assert meta["ablation_6_vs_19"]["ablation_skipped"] is True
    assert meta["synthetic_dataset_notes"]["is_synthetic"] is False
    assert (out_dir / "model.joblib").exists()
    assert (out_dir / "metadata.json").exists()
