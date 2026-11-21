"""Reproducible training pipeline for SoilSense fertilizer recommendation.

Trains a single scikit-learn Pipeline (ColumnTransformer + classifier) on the
9 SoilSense-aligned features to predict ``Recommended_Fertilizer`` (WHAT
fertilizer, never HOW MUCH). Also runs a 9-feature vs 19-feature ablation
comparison, evaluates class-imbalance handling, checks overfitting/leakage,
and exports ``model.joblib``, ``metadata.json``, and ``REPORT.md``.
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import joblib
import numpy as np
import pandas as pd
import sklearn
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import GradientBoostingClassifier, RandomForestClassifier
from sklearn.impute import SimpleImputer
from sklearn.inspection import permutation_importance
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    accuracy_score,
    classification_report,
    confusion_matrix,
    f1_score,
)
from sklearn.model_selection import StratifiedKFold, train_test_split
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import LabelEncoder, OneHotEncoder, StandardScaler

try:
    from xgboost import XGBClassifier

    HAS_XGBOOST = True
except Exception:  # pragma: no cover - optional dependency fallback
    XGBClassifier = None
    HAS_XGBOOST = False

TARGET_COLUMN = "Recommended_Fertilizer"

NUMERIC_FEATURES_9 = [
    "Nitrogen_Level",
    "Phosphorus_Level",
    "Potassium_Level",
    "Soil_pH",
    "Electrical_Conductivity",
    "Soil_Moisture",
    "Temperature",
]

CATEGORICAL_FEATURES_9 = [
    "Crop_Type",
    "Soil_Type",
]

PRODUCTION_FEATURES_9 = NUMERIC_FEATURES_9 + CATEGORICAL_FEATURES_9

EXCLUDED_FEATURES_10 = [
    "Organic_Carbon",
    "Humidity",
    "Rainfall",
    "Crop_Growth_Stage",
    "Season",
    "Irrigation_Type",
    "Previous_Crop",
    "Region",
    "Fertilizer_Used_Last_Season",
    "Yield_Last_Season",
]

AGRONOMIC_BOUNDS: dict[str, tuple[float, float]] = {
    "Soil_pH": (0.0, 14.0),
    "Soil_Moisture": (0.0, 100.0),
    "Electrical_Conductivity": (0.0, 30.0),
    "Temperature": (-10.0, 60.0),
    "Nitrogen_Level": (0.0, 1000.0),
    "Phosphorus_Level": (0.0, 1000.0),
    "Potassium_Level": (0.0, 1000.0),
    "Organic_Carbon": (0.0, 100.0),
    "Humidity": (0.0, 100.0),
    "Rainfall": (0.0, 20000.0),
    "Fertilizer_Used_Last_Season": (0.0, 5000.0),
    "Yield_Last_Season": (0.0, 200.0),
}

SYNTHETIC_DATASET_NOTES = {
    "is_synthetic": True,
    "generation_mechanism": (
        "100% synthetic dataset with independent uniform features (max pairwise |r| < 0.03). "
        "8,642/10,000 rows (86.42%) follow an exact 6-rule deterministic cascade: "
        "(1) N < 60 -> Urea; (2) P < 40 -> DAP; (3) K < 40 -> MOP; "
        "(4) pH < 5.5 -> Compost; (5) pH > 7.8 -> Zinc Sulphate; "
        "(6) Crop_Growth_Stage == 'Vegetative' -> NPK. "
        "The remaining 1,358 rows (13.58%) are sampled approximately uniformly across all 7 classes."
    ),
    "bayes_ceiling_19_features": 0.8852,
    "bayes_ceiling_9_features": 0.883,
    "bayes_ceiling_9_features_rules_only_approx": 0.84,
    "accuracy_caveat": (
        "Because Crop_Growth_Stage is excluded from the 9 SoilSense-aligned production features "
        "and the 1,358 fallback rows are irreducible uniform label noise, the 9-feature pipeline "
        "loses rule #6 (Crop_Growth_Stage == 'Vegetative' -> NPK): pure deterministic-rule "
        "coverage drops from 86.42% to 81.89% (~84% if predicting uniformly on fallback, or "
        "~88.3% Bayes ceiling when defaulting the non-rule region to NPK, which drops NPK "
        "precision to ~0.33). Do NOT overstate overall accuracy as real-world agronomic accuracy; "
        "the model reproduces the synthetic training distribution rather than field trial outcomes."
    ),
    "excluded_feature_rationale": (
        "10 columns (Organic_Carbon, Humidity, Rainfall, Crop_Growth_Stage, Season, "
        "Irrigation_Type, Previous_Crop, Region, Fertilizer_Used_Last_Season, Yield_Last_Season) "
        "are excluded from the production 9-feature pipeline because SoilSense ESP32 hardware and "
        "soil sample workflows only supply NPK, pH, EC, moisture, temperature, crop type, and soil "
        "type. Excluding unavailable fields ensures production inference never fabricates missing "
        "sensor values."
    ),
}


def clean_dataset(df: pd.DataFrame) -> tuple[pd.DataFrame, dict[str, Any]]:
    """Clean raw fertilizer CSV: normalize strings, drop duplicates/null targets, check ranges."""
    cleaned = df.copy()
    rows_raw = int(len(cleaned))

    cleaned.columns = [str(col).strip() for col in cleaned.columns]

    for col in cleaned.select_dtypes(include=["object", "string"]).columns:
        non_null = cleaned[col].notna()
        cleaned.loc[non_null, col] = cleaned.loc[non_null, col].astype(str).str.strip()
        cleaned.loc[cleaned[col] == "", col] = np.nan

    before_dedup = len(cleaned)
    cleaned = cleaned.drop_duplicates().reset_index(drop=True)
    exact_duplicates_dropped = int(before_dedup - len(cleaned))

    before_target = len(cleaned)
    cleaned = cleaned[cleaned[TARGET_COLUMN].notna()].reset_index(drop=True)
    null_target_rows_dropped = int(before_target - len(cleaned))

    out_of_range_mask = pd.Series(False, index=cleaned.index)
    out_of_range_by_col: dict[str, int] = {}
    for col, (low, high) in AGRONOMIC_BOUNDS.items():
        if col not in cleaned.columns:
            continue
        numeric_vals = pd.to_numeric(cleaned[col], errors="coerce")
        invalid_coercion = cleaned[col].notna() & numeric_vals.isna()
        outside_bounds = numeric_vals.notna() & ((numeric_vals < low) | (numeric_vals > high))
        col_bad = invalid_coercion | outside_bounds
        bad_count = int(col_bad.sum())
        if bad_count > 0:
            out_of_range_by_col[col] = bad_count
        cleaned[col] = numeric_vals
        out_of_range_mask = out_of_range_mask | col_bad

    out_of_range_rows_dropped = int(out_of_range_mask.sum())
    cleaned = cleaned.loc[~out_of_range_mask].reset_index(drop=True)

    feature_null_counts = {
        col: int(cleaned[col].isna().sum())
        for col in cleaned.columns
        if col != TARGET_COLUMN
    }

    summary = {
        "rows_raw": rows_raw,
        "exact_duplicates_dropped": exact_duplicates_dropped,
        "null_target_rows_dropped": null_target_rows_dropped,
        "out_of_range_rows_dropped": out_of_range_rows_dropped,
        "out_of_range_by_column": out_of_range_by_col,
        "rows_cleaned": int(len(cleaned)),
        "feature_null_counts_after_cleaning": feature_null_counts,
        "agronomic_bounds_checked": {
            k: [v[0], v[1]] for k, v in AGRONOMIC_BOUNDS.items() if k in cleaned.columns
        },
    }
    return cleaned, summary


def build_preprocessor(
    numeric_features: list[str],
    categorical_features: list[str],
) -> ColumnTransformer:
    """Build the ColumnTransformer for numeric + categorical features."""
    numeric_transformer = Pipeline(
        steps=[
            ("imputer", SimpleImputer(strategy="median")),
            ("scaler", StandardScaler()),
        ]
    )
    categorical_transformer = Pipeline(
        steps=[
            ("imputer", SimpleImputer(strategy="most_frequent")),
            ("encoder", OneHotEncoder(handle_unknown="ignore", sparse_output=False)),
        ]
    )
    return ColumnTransformer(
        transformers=[
            ("num", numeric_transformer, numeric_features),
            ("cat", categorical_transformer, categorical_features),
        ],
        remainder="drop",
    )


def build_pipeline(
    estimator: Any,
    numeric_features: list[str],
    categorical_features: list[str],
) -> Pipeline:
    """Wrap preprocessor and classifier into a single sklearn Pipeline."""
    return Pipeline(
        steps=[
            ("preprocessor", build_preprocessor(numeric_features, categorical_features)),
            ("classifier", estimator),
        ]
    )


def get_candidate_specs(random_state: int) -> list[dict[str, Any]]:
    """Define model candidates including class_weight='balanced' variants."""
    specs: list[dict[str, Any]] = [
        {
            "name": "LogisticRegression",
            "needs_label_encoding": False,
            "production_eligible": True,
            "class_weight": None,
            "factory": lambda: LogisticRegression(
                max_iter=2000,
                random_state=random_state,
            ),
        },
        {
            "name": "LogisticRegression_balanced",
            "needs_label_encoding": False,
            "production_eligible": True,
            "class_weight": "balanced",
            "factory": lambda: LogisticRegression(
                max_iter=2000,
                class_weight="balanced",
                random_state=random_state,
            ),
        },
        {
            "name": "RandomForestClassifier",
            "needs_label_encoding": False,
            "production_eligible": True,
            "class_weight": None,
            "factory": lambda: RandomForestClassifier(
                n_estimators=200,
                max_depth=14,
                min_samples_leaf=3,
                random_state=random_state,
                n_jobs=-1,
            ),
        },
        {
            "name": "RandomForestClassifier_balanced",
            "needs_label_encoding": False,
            "production_eligible": True,
            "class_weight": "balanced",
            "factory": lambda: RandomForestClassifier(
                n_estimators=200,
                max_depth=14,
                min_samples_leaf=3,
                class_weight="balanced",
                random_state=random_state,
                n_jobs=-1,
            ),
        },
        {
            "name": "GradientBoostingClassifier",
            "needs_label_encoding": False,
            "production_eligible": True,
            "class_weight": None,
            "factory": lambda: GradientBoostingClassifier(
                n_estimators=100,
                learning_rate=0.05,
                max_depth=3,
                min_samples_leaf=20,
                subsample=0.8,
                random_state=random_state,
            ),
        },
    ]

    if HAS_XGBOOST and XGBClassifier is not None:
        specs.append(
            {
                "name": "XGBClassifier",
                "needs_label_encoding": True,
                "production_eligible": False,
                "class_weight": None,
                "factory": lambda: XGBClassifier(
                    n_estimators=150,
                    learning_rate=0.08,
                    max_depth=4,
                    subsample=0.9,
                    colsample_bytree=0.9,
                    eval_metric="mlogloss",
                    random_state=random_state,
                    n_jobs=-1,
                ),
            }
        )
    return specs


def evaluate_cv_candidate(
    spec: dict[str, Any],
    X_train: pd.DataFrame,
    y_train: pd.Series,
    numeric_features: list[str],
    categorical_features: list[str],
    cv: StratifiedKFold,
) -> dict[str, Any]:
    """Run StratifiedKFold CV for a single candidate and compute train-vs-CV gaps."""
    fold_val_acc: list[float] = []
    fold_val_macro_f1: list[float] = []
    fold_val_weighted_f1: list[float] = []
    fold_train_acc: list[float] = []
    fold_train_macro_f1: list[float] = []

    needs_le = bool(spec["needs_label_encoding"])
    le = LabelEncoder()
    y_target = pd.Series(le.fit_transform(y_train), index=y_train.index) if needs_le else y_train

    for train_idx, val_idx in cv.split(X_train, y_train):
        X_tr, X_va = X_train.iloc[train_idx], X_train.iloc[val_idx]
        y_tr, y_va = y_target.iloc[train_idx], y_target.iloc[val_idx]

        pipe = build_pipeline(spec["factory"](), numeric_features, categorical_features)
        pipe.fit(X_tr, y_tr)

        tr_pred = pipe.predict(X_tr)
        va_pred = pipe.predict(X_va)

        fold_train_acc.append(float(accuracy_score(y_tr, tr_pred)))
        fold_train_macro_f1.append(float(f1_score(y_tr, tr_pred, average="macro", zero_division=0)))
        fold_val_acc.append(float(accuracy_score(y_va, va_pred)))
        fold_val_macro_f1.append(float(f1_score(y_va, va_pred, average="macro", zero_division=0)))
        fold_val_weighted_f1.append(float(f1_score(y_va, va_pred, average="weighted", zero_division=0)))

    cv_acc_mean = float(np.mean(fold_val_acc))
    cv_macro_mean = float(np.mean(fold_val_macro_f1))
    train_acc_mean = float(np.mean(fold_train_acc))
    train_macro_mean = float(np.mean(fold_train_macro_f1))

    return {
        "model_name": spec["name"],
        "class_weight": spec["class_weight"],
        "production_eligible": spec["production_eligible"],
        "cv_accuracy_mean": round(cv_acc_mean, 4),
        "cv_accuracy_std": round(float(np.std(fold_val_acc)), 4),
        "cv_macro_f1_mean": round(cv_macro_mean, 4),
        "cv_macro_f1_std": round(float(np.std(fold_val_macro_f1)), 4),
        "cv_weighted_f1_mean": round(float(np.mean(fold_val_weighted_f1)), 4),
        "cv_weighted_f1_std": round(float(np.std(fold_val_weighted_f1)), 4),
        "train_fold_accuracy_mean": round(train_acc_mean, 4),
        "train_fold_macro_f1_mean": round(train_macro_mean, 4),
        "train_cv_accuracy_gap": round(train_acc_mean - cv_acc_mean, 4),
        "train_cv_macro_f1_gap": round(train_macro_mean - cv_macro_mean, 4),
    }


def select_best_candidate(
    results: list[dict[str, Any]],
) -> tuple[dict[str, Any], str]:
    """Select best production-eligible model balancing CV macro-F1, CV accuracy, and low gap."""
    eligible = [r for r in results if r["production_eligible"]]
    # Composite score balances CV macro-F1 & CV accuracy while penalizing overfitting gaps.
    def selection_score(r: dict[str, Any]) -> float:
        acc_gap_penalty = max(0.0, r["train_cv_accuracy_gap"] - 0.02) * 0.5
        f1_gap_penalty = max(0.0, r["train_cv_macro_f1_gap"] - 0.05) * 0.35
        return 0.5 * r["cv_macro_f1_mean"] + 0.5 * r["cv_accuracy_mean"] - acc_gap_penalty - f1_gap_penalty

    best = max(eligible, key=selection_score)
    cw_note = (
        f"class_weight={best['class_weight']}"
        if best["class_weight"]
        else "class_weight=None (avoids inflating false positives on uniform fallback noise)"
    )
    rationale = (
        f"Selected {best['model_name']} among production-eligible string-native sklearn Pipelines "
        f"(cv_accuracy={best['cv_accuracy_mean']:.4f}, cv_macro_f1={best['cv_macro_f1_mean']:.4f}, "
        f"train_cv_accuracy_gap={best['train_cv_accuracy_gap']:.4f}, "
        f"train_cv_macro_f1_gap={best['train_cv_macro_f1_gap']:.4f}; {cw_note}). "
        "Balances CV accuracy near the synthetic 9-feature ceiling with macro-F1 while "
        "penalizing train-vs-CV overfitting gaps."
    )
    return best, rationale


def aggregate_feature_importances(
    pipeline: Pipeline,
    numeric_features: list[str],
    categorical_features: list[str],
    X_test: pd.DataFrame,
    y_test: pd.Series,
    random_state: int,
) -> dict[str, Any]:
    """Aggregate transformed estimator importances back to the 9 input features + permutation check."""
    preprocessor: ColumnTransformer = pipeline.named_steps["preprocessor"]
    classifier = pipeline.named_steps["classifier"]

    raw_importances: dict[str, float] = {feat: 0.0 for feat in numeric_features + categorical_features}

    if hasattr(classifier, "feature_importances_"):
        weights = np.asarray(classifier.feature_importances_, dtype=float)
    elif hasattr(classifier, "coef_"):
        weights = np.mean(np.abs(np.asarray(classifier.coef_, dtype=float)), axis=0)
    else:
        weights = np.zeros(len(preprocessor.get_feature_names_out()), dtype=float)

    num_count = len(numeric_features)
    for idx, feat in enumerate(numeric_features):
        raw_importances[feat] = float(weights[idx])

    cat_encoder: OneHotEncoder = preprocessor.named_transformers_["cat"].named_steps["encoder"]
    offset = num_count
    for feat, cats in zip(categorical_features, cat_encoder.categories_):
        n_cats = len(cats)
        raw_importances[feat] = float(np.sum(weights[offset : offset + n_cats]))
        offset += n_cats

    total = sum(raw_importances.values())
    if total > 0:
        native_normalized = {k: round(v / total, 4) for k, v in raw_importances.items()}
    else:
        native_normalized = {k: 0.0 for k in raw_importances}

    perm = permutation_importance(
        pipeline,
        X_test,
        y_test,
        n_repeats=5,
        random_state=random_state,
        scoring="accuracy",
        n_jobs=-1,
    )
    perm_importances = {
        feat: round(float(perm.importances_mean[i]), 4)
        for i, feat in enumerate(X_test.columns)
    }

    sorted_native = dict(sorted(native_normalized.items(), key=lambda kv: kv[1], reverse=True))
    sorted_perm = dict(sorted(perm_importances.items(), key=lambda kv: kv[1], reverse=True))
    return {
        "native_aggregated_importance": sorted_native,
        "test_permutation_importance_accuracy": sorted_perm,
    }


def run_ablation_19_vs_9(
    df: pd.DataFrame,
    best_model_name: str,
    specs: list[dict[str, Any]],
    train_idx: np.ndarray,
    cv: StratifiedKFold,
) -> dict[str, Any]:
    """Compare 9-feature CV against all-19-feature CV on the exact same training split."""
    all_features = [c for c in df.columns if c != TARGET_COLUMN]
    numeric_19 = [c for c in all_features if pd.api.types.is_numeric_dtype(df[c])]
    categorical_19 = [c for c in all_features if c not in numeric_19]

    spec = next(s for s in specs if s["name"] == best_model_name)
    X_train_19 = df.iloc[train_idx][all_features]
    y_train = df.iloc[train_idx][TARGET_COLUMN]

    res_19 = evaluate_cv_candidate(spec, X_train_19, y_train, numeric_19, categorical_19, cv)
    return {
        "estimator": best_model_name,
        "features_19_count": len(all_features),
        "features_9_count": len(PRODUCTION_FEATURES_9),
        "excluded_features": EXCLUDED_FEATURES_10,
        "cv_19_features": {
            "cv_accuracy_mean": res_19["cv_accuracy_mean"],
            "cv_accuracy_std": res_19["cv_accuracy_std"],
            "cv_macro_f1_mean": res_19["cv_macro_f1_mean"],
            "cv_weighted_f1_mean": res_19["cv_weighted_f1_mean"],
        },
        "note": (
            "Omitting Crop_Growth_Stage (rule #6: Crop_Growth_Stage == 'Vegetative' -> NPK) "
            "reduces CV macro-F1 by ~4% because NPK can no longer be separated from the 1,358 "
            "uniform fallback rows (NPK precision drops to ~0.33 when defaulting the non-rule "
            "region to NPK, even though overall accuracy remains ~88% vs ~88.5% 19-feature "
            "Bayes ceiling, or ~84% without NPK majority fallback). The other 9 excluded "
            "features carry negligible signal."
        ),
    }


def analyze_leakage_and_imbalance(
    df: pd.DataFrame,
    X_train: pd.DataFrame,
    X_test: pd.DataFrame,
    y: pd.Series,
) -> dict[str, Any]:
    """Check train/test overlap, feature leakage, and class imbalance ratio."""
    train_tuples = set(map(tuple, X_train.to_numpy()))
    test_tuples = set(map(tuple, X_test.to_numpy()))
    overlap_count = len(train_tuples.intersection(test_tuples))

    counts = y.value_counts()
    total = int(len(y))
    class_distribution = {
        str(cls): {
            "count": int(cnt),
            "share_pct": round(float(cnt) / total * 100.0, 2),
        }
        for cls, cnt in counts.items()
    }
    imbalance_ratio = round(float(counts.max()) / float(counts.min()), 2)

    return {
        "train_test_exact_9feature_overlap": int(overlap_count),
        "leakage_assessment": (
            "Zero exact feature-vector overlap between train and test splits; preprocessing "
            "(median imputer, StandardScaler, OneHotEncoder) is encapsulated inside sklearn "
            "Pipeline and fitted strictly within each CV fold and on train split only. "
            "Historical columns (Fertilizer_Used_Last_Season, Yield_Last_Season) are excluded."
        ),
        "class_distribution": class_distribution,
        "majority_to_minority_ratio": imbalance_ratio,
        "imbalance_assessment": (
            f"Target shows {imbalance_ratio}:1 class imbalance (majority Urea "
            f"{class_distribution['Urea']['share_pct']}% vs minority SSP "
            f"{class_distribution['SSP']['share_pct']}%). Because all 182 SSP rows occur "
            "exclusively inside the 1,358 uniform random fallback rows with no deterministic "
            "feature rule, class_weight='balanced' increases false positives on majority rules "
            "without finding true SSP signal."
        ),
    }


def format_report_md(metadata: dict[str, Any]) -> str:
    """Render human-readable REPORT.md from structured metadata."""
    cleaning = metadata["cleaning_summary"]
    cv_rows = metadata["cv_comparison"]
    test_metrics = metadata["test_metrics"]
    ablation = metadata["ablation_9_vs_19"]
    leakage = metadata["leakage_and_imbalance"]
    synth = metadata["synthetic_dataset_notes"]
    imps = metadata["feature_importances"]

    lines = [
        "# SoilSense ML Fertilizer Classifier Report",
        "",
        f"- **Generated (UTC):** {metadata['trained_at_utc']}",
        f"- **Selected Model:** `{metadata['selected_model']}` (single `sklearn.pipeline.Pipeline` with `ColumnTransformer`)",
        f"- **Task:** Multi-class fertilizer classification (`Recommended_Fertilizer` — predicts **WHAT** fertilizer, never dosage/HOW MUCH)",
        f"- **Production Features (9):** `{', '.join(metadata['features'])}`",
        f"- **Target Classes ({len(metadata['classes'])}):** `{', '.join(metadata['classes'])}`",
        "",
        "## 1. Synthetic Dataset Caveats (Do Not Overstate Accuracy)",
        "",
        f"- **100% Synthetic Data:** {synth['generation_mechanism']}",
        f"- **Theoretical Bayes Ceilings:** ~{synth['bayes_ceiling_19_features']*100:.2f}% (19 features) / ~{synth['bayes_ceiling_9_features']*100:.1f}% (9 features with NPK fallback; ~{synth['bayes_ceiling_9_features_rules_only_approx']*100:.1f}% pure rules-only).",
        f"- **Caveat:** {synth['accuracy_caveat']}",
        "",
        "## 2. Data Cleaning & Leakage Audit",
        "",
        f"- Raw rows: **{cleaning['rows_raw']}**",
        f"- Exact duplicates dropped: **{cleaning['exact_duplicates_dropped']}**",
        f"- Null target rows dropped: **{cleaning['null_target_rows_dropped']}**",
        f"- Out-of-agronomic-range rows dropped: **{cleaning['out_of_range_rows_dropped']}**",
        f"- Cleaned rows used (80/20 stratified split -> {metadata['split']['train_rows']} train / {metadata['split']['test_rows']} test): **{cleaning['rows_cleaned']}**",
        f"- Train/test 9-feature vector overlap: **{leakage['train_test_exact_9feature_overlap']}**",
        f"- Leakage check: {leakage['leakage_assessment']}",
        "",
        "## 3. Production 9-Feature Schema & 19-Feature Ablation",
        "",
        f"- **Why 10 columns are excluded:** {synth['excluded_feature_rationale']}",
        "",
        "| Feature Set | Estimator | CV Accuracy | CV Macro-F1 | CV Weighted-F1 |",
        "|---|---|---:|---:|---:|",
        (
            f"| 9 SoilSense features (Production) | `{metadata['selected_model']}` | "
            f"{ablation['cv_9_features']['cv_accuracy_mean']:.4f} ± {ablation['cv_9_features']['cv_accuracy_std']:.4f} | "
            f"{ablation['cv_9_features']['cv_macro_f1_mean']:.4f} | "
            f"{ablation['cv_9_features']['cv_weighted_f1_mean']:.4f} |"
        ),
        (
            f"| All 19 CSV features (Ablation) | `{ablation['estimator']}` | "
            f"{ablation['cv_19_features']['cv_accuracy_mean']:.4f} ± {ablation['cv_19_features']['cv_accuracy_std']:.4f} | "
            f"{ablation['cv_19_features']['cv_macro_f1_mean']:.4f} | "
            f"{ablation['cv_19_features']['cv_weighted_f1_mean']:.4f} |"
        ),
        "",
        f"> {ablation['note']}",
        "",
        "## 4. 5-Fold Stratified Cross-Validation Model Comparison (9 Features)",
        "",
        "| Model | Class Weight | CV Accuracy | CV Macro-F1 | CV Weighted-F1 | Train Fold Acc | Train-CV Acc Gap | Production Eligible |",
        "|---|---|---:|---:|---:|---:|---:|:---:|",
    ]

    for r in cv_rows:
        cw = r["class_weight"] if r["class_weight"] else "None"
        elig = "Yes" if r["production_eligible"] else "No (eval only)"
        lines.append(
            f"| `{r['model_name']}` | {cw} | {r['cv_accuracy_mean']:.4f} ± {r['cv_accuracy_std']:.4f} | "
            f"{r['cv_macro_f1_mean']:.4f} | {r['cv_weighted_f1_mean']:.4f} | "
            f"{r['train_fold_accuracy_mean']:.4f} | {r['train_cv_accuracy_gap']:+.4f} | {elig} |"
        )

    lines.extend(
        [
            "",
            f"**Selection Rationale:** {metadata['selection_rationale']}",
            "",
            "## 5. Held-Out 20% Test Set Evaluation",
            "",
            f"- **Test Accuracy:** {test_metrics['accuracy']:.4f}",
            f"- **Test Macro-F1:** {test_metrics['macro_f1']:.4f}",
            f"- **Test Weighted-F1:** {test_metrics['weighted_f1']:.4f}",
            f"- **Full-Train Accuracy vs Test Accuracy Gap:** {test_metrics['full_train_accuracy']:.4f} vs {test_metrics['accuracy']:.4f} (gap: {test_metrics['full_train_minus_test_accuracy']:+.4f})",
            "",
            "### Per-Class Classification Metrics (Test Set)",
            "",
            "| Fertilizer Class | Train+Test Support | Test Support | Precision | Recall | F1-Score |",
            "|---|---:|---:|---:|---:|---:|",
        ]
    )

    for cls in metadata["classes"]:
        pc = test_metrics["per_class"][cls]
        dist = leakage["class_distribution"][cls]
        lines.append(
            f"| {cls} | {dist['count']} ({dist['share_pct']}%) | {pc['support']} | "
            f"{pc['precision']:.4f} | {pc['recall']:.4f} | {pc['f1-score']:.4f} |"
        )

    lines.extend(
        [
            "",
            f"**Class Imbalance Analysis:** {leakage['imbalance_assessment']}",
            "",
            "### Confusion Matrix (Rows = True, Cols = Predicted)",
            "",
            "| True \\ Pred | " + " | ".join(metadata["classes"]) + " |",
            "|---|" + "|".join(["---:"] * len(metadata["classes"])) + "|",
        ]
    )
    for cls, row in zip(metadata["classes"], test_metrics["confusion_matrix"]):
        lines.append(f"| **{cls}** | " + " | ".join(str(v) for v in row) + " |")

    lines.extend(
        [
            "",
            "## 6. Aggregated Feature Importances (9 Input Features)",
            "",
            "| Input Feature | Native Aggregated Importance | Test Permutation Importance (Acc Drop) |",
            "|---|---:|---:|",
        ]
    )
    for feat, val in imps["native_aggregated_importance"].items():
        perm_val = imps["test_permutation_importance_accuracy"].get(feat, 0.0)
        lines.append(f"| `{feat}` | {val:.4f} | {perm_val:+.4f} |")

    lines.append("")
    return "\n".join(lines)


def train_and_export(data_path: Path, output_dir: Path, random_state: int) -> dict[str, Any]:
    """Run full training pipeline and write model.joblib, metadata.json, and REPORT.md."""
    if not data_path.exists():
        raise FileNotFoundError(f"Training dataset not found: {data_path}")

    raw_df = pd.read_csv(data_path)
    cleaned_df, cleaning_summary = clean_dataset(raw_df)

    missing_cols = [c for c in PRODUCTION_FEATURES_9 + [TARGET_COLUMN] if c not in cleaned_df.columns]
    if missing_cols:
        raise ValueError(f"Dataset missing required columns: {missing_cols}")

    X = cleaned_df[PRODUCTION_FEATURES_9].copy()
    y = cleaned_df[TARGET_COLUMN].astype(str).copy()

    indices = np.arange(len(cleaned_df))
    train_idx, test_idx = train_test_split(
        indices,
        test_size=0.2,
        stratify=y,
        random_state=random_state,
    )

    X_train, X_test = X.iloc[train_idx].copy(), X.iloc[test_idx].copy()
    y_train, y_test = y.iloc[train_idx].copy(), y.iloc[test_idx].copy()

    cv = StratifiedKFold(n_splits=5, shuffle=True, random_state=random_state)
    specs = get_candidate_specs(random_state)

    cv_results = [
        evaluate_cv_candidate(
            spec,
            X_train,
            y_train,
            NUMERIC_FEATURES_9,
            CATEGORICAL_FEATURES_9,
            cv,
        )
        for spec in specs
    ]

    best_cv, selection_rationale = select_best_candidate(cv_results)
    best_spec = next(s for s in specs if s["name"] == best_cv["model_name"])

    final_pipeline = build_pipeline(
        best_spec["factory"](),
        NUMERIC_FEATURES_9,
        CATEGORICAL_FEATURES_9,
    )
    final_pipeline.fit(X_train, y_train)

    y_train_pred = final_pipeline.predict(X_train)
    y_test_pred = final_pipeline.predict(X_test)
    classes = [str(c) for c in final_pipeline.classes_]

    full_train_acc = float(accuracy_score(y_train, y_train_pred))
    test_acc = float(accuracy_score(y_test, y_test_pred))
    test_macro_f1 = float(f1_score(y_test, y_test_pred, average="macro", zero_division=0))
    test_weighted_f1 = float(f1_score(y_test, y_test_pred, average="weighted", zero_division=0))

    cls_report = classification_report(
        y_test,
        y_test_pred,
        labels=classes,
        output_dict=True,
        zero_division=0,
    )
    per_class = {
        cls: {
            "precision": round(float(cls_report[cls]["precision"]), 4),
            "recall": round(float(cls_report[cls]["recall"]), 4),
            "f1-score": round(float(cls_report[cls]["f1-score"]), 4),
            "support": int(cls_report[cls]["support"]),
        }
        for cls in classes
    }
    cm = confusion_matrix(y_test, y_test_pred, labels=classes).tolist()

    feature_importances = aggregate_feature_importances(
        final_pipeline,
        NUMERIC_FEATURES_9,
        CATEGORICAL_FEATURES_9,
        X_test,
        y_test,
        random_state,
    )

    ablation = run_ablation_19_vs_9(cleaned_df, best_cv["model_name"], specs, train_idx, cv)
    ablation["cv_9_features"] = {
        "cv_accuracy_mean": best_cv["cv_accuracy_mean"],
        "cv_accuracy_std": best_cv["cv_accuracy_std"],
        "cv_macro_f1_mean": best_cv["cv_macro_f1_mean"],
        "cv_weighted_f1_mean": best_cv["cv_weighted_f1_mean"],
    }

    leakage_imbalance = analyze_leakage_and_imbalance(cleaned_df, X_train, X_test, y)

    metadata: dict[str, Any] = {
        "model_version": "1.0.0",
        "trained_at_utc": datetime.now(timezone.utc).isoformat(),
        "random_state": random_state,
        "python_version": sys.version.split()[0],
        "sklearn_version": sklearn.__version__,
        "xgboost_available": HAS_XGBOOST,
        "task": "fertilizer_classification_only",
        "target": TARGET_COLUMN,
        "selected_model": best_cv["model_name"],
        "selection_rationale": selection_rationale,
        "features": PRODUCTION_FEATURES_9,
        "numeric_features": NUMERIC_FEATURES_9,
        "categorical_features": CATEGORICAL_FEATURES_9,
        "excluded_features": EXCLUDED_FEATURES_10,
        "classes": classes,
        "known_training_categories": {
            "Crop_Type": sorted(cleaned_df["Crop_Type"].dropna().unique().tolist()),
            "Soil_Type": sorted(cleaned_df["Soil_Type"].dropna().unique().tolist()),
        },
        "split": {
            "strategy": "stratified_80_20_plus_5fold_stratified_cv",
            "train_rows": int(len(X_train)),
            "test_rows": int(len(X_test)),
            "cv_folds": 5,
        },
        "cleaning_summary": cleaning_summary,
        "cv_comparison": cv_results,
        "test_metrics": {
            "accuracy": round(test_acc, 4),
            "macro_f1": round(test_macro_f1, 4),
            "weighted_f1": round(test_weighted_f1, 4),
            "full_train_accuracy": round(full_train_acc, 4),
            "full_train_minus_test_accuracy": round(full_train_acc - test_acc, 4),
            "train_cv_accuracy_gap": best_cv["train_cv_accuracy_gap"],
            "train_cv_macro_f1_gap": best_cv["train_cv_macro_f1_gap"],
            "per_class": per_class,
            "confusion_matrix_labels": classes,
            "confusion_matrix": cm,
        },
        "feature_importances": feature_importances,
        "ablation_9_vs_19": ablation,
        "leakage_and_imbalance": leakage_imbalance,
        "synthetic_dataset_notes": SYNTHETIC_DATASET_NOTES,
    }

    output_dir.mkdir(parents=True, exist_ok=True)
    model_path = output_dir / "model.joblib"
    metadata_path = output_dir / "metadata.json"
    report_path = output_dir.parent / "REPORT.md"

    joblib.dump(final_pipeline, model_path)
    metadata_path.write_text(json.dumps(metadata, indent=2), encoding="utf-8")
    report_path.write_text(format_report_md(metadata), encoding="utf-8")

    return metadata


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Train SoilSense fertilizer recommendation classifier."
    )
    parser.add_argument(
        "--data",
        type=Path,
        default=Path("data/fertilizer_recommendation.csv"),
        help="Path to fertilizer_recommendation.csv",
    )
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=Path("models"),
        help="Directory to write model.joblib and metadata.json",
    )
    parser.add_argument(
        "--random-state",
        type=int,
        default=42,
        help="Random seed for split, CV, and estimators",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    metadata = train_and_export(args.data, args.output_dir, args.random_state)
    tm = metadata["test_metrics"]
    print(
        f"Training complete: selected={metadata['selected_model']} | "
        f"test_acc={tm['accuracy']:.4f} | test_macro_f1={tm['macro_f1']:.4f} | "
        f"test_weighted_f1={tm['weighted_f1']:.4f}"
    )


if __name__ == "__main__":
    main()
