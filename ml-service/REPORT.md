# SoilSense ML Fertilizer Classifier Report (4-Sensor / 6-Feature)

- **Generated (UTC):** 2026-11-21T14:50:15.055931+00:00
- **Model Version:** `2.0.0`
- **Selected Model:** `GradientBoostingClassifier` (single `sklearn.pipeline.Pipeline` with `ColumnTransformer`)
- **Task:** Multi-class fertilizer classification (`Recommended_Fertilizer` — predicts **WHAT** fertilizer, never dosage/HOW MUCH)
- **Production Features (6 — No N-P-K):** `Soil_pH, Electrical_Conductivity, Soil_Moisture, Temperature, Crop_Type, Soil_Type`
- **Target Classes (7):** `Compost, DAP, MOP, NPK, SSP, Urea, Zinc Sulphate`

## 1. Synthetic Dataset Caveats (Honest Accuracy Disclosure)

- **100% Synthetic Data:** 100% synthetic dataset with independent uniform features (max pairwise |r| < 0.03). 8,642/10,000 rows (86.42%) follow an exact 6-rule deterministic cascade: (1) N < 60 -> Urea (2,902 rows, 29.02%); (2) P < 40 -> DAP (2,738 rows, 27.38%); (3) K < 40 -> MOP (1,198 rows, 11.98%) — together N, P, and K drive 6,838/10,000 rows (68.38%); (4) pH < 5.5 -> Compost (798 rows, 7.98%); (5) pH > 7.8 -> Zinc Sulphate (553 rows, 5.53%); (6) Crop_Growth_Stage == 'Vegetative' -> NPK (453 rows, 4.53%). The remaining 1,358 rows (13.58%) are sampled approximately uniformly across all 7 classes.
- **Theoretical Bayes Ceilings:** ~88.52% (all 19 CSV features including N-P-K) vs **~32.05%** (6 SoilSense features without N-P-K, because N-P-K drove 68.38% of the synthetic CSV's rules).
- **Caveat:** SoilSense collects 4 soil sensors (Soil_pH, Electrical_Conductivity, Soil_Moisture, Temperature) plus Crop_Type and Soil_Type — Nitrogen, Phosphorus, and Potassium are NOT measured or supplied. Removing N, P, and K drops the first three cascade rules that drove 68.38% of labels in this synthetic CSV, collapsing the theoretical Bayes-optimal accuracy ceiling from ~88.52% (19 features) to ~32.05% (6 features). On this synthetic dataset, the 6-feature model primarily learns the pH rules (pH < 5.5 -> Compost at ~31.8% posterior; pH > 7.8 -> Zinc Sulphate at ~31.6% posterior) plus the neutral-pH majority class prior (Urea at ~32.3% posterior for 5.5 <= pH <= 7.8). Because top-class probabilities stay around 0.30-0.34 (below the default 0.55 low_confidence threshold), low_confidence=true flags these predictions honestly so downstream deterministic agronomic guidance can assist. Do NOT overstate accuracy; replace this synthetic dataset with real 4-sensor regional field-trial data whenever available.

## 2. Data Cleaning & Leakage Audit

- Raw rows: **10000**
- Exact duplicates dropped: **0**
- Null target rows dropped: **0**
- Out-of-agronomic-range rows dropped: **0**
- Cleaned rows used (80/20 stratified split -> 8000 train / 2000 test): **10000**
- Train/test 6-feature vector overlap: **0**
- Leakage check: Zero exact feature-vector overlap between train and test splits; preprocessing (median imputer, StandardScaler, OneHotEncoder) is encapsulated inside sklearn Pipeline and fitted strictly within each CV fold and on train split only. Historical columns (Fertilizer_Used_Last_Season, Yield_Last_Season) and unavailable N-P-K columns are excluded.

## 3. Production 6-Feature Schema & 19-Feature Diagnostic Ablation

- **Why 13 columns (including N, P, K) are excluded:** 13 columns (Nitrogen_Level, Phosphorus_Level, Potassium_Level, Organic_Carbon, Humidity, Rainfall, Crop_Growth_Stage, Season, Irrigation_Type, Previous_Crop, Region, Fertilizer_Used_Last_Season, Yield_Last_Season) are excluded from the production 6-feature pipeline because SoilSense ESP32 hardware and soil sample workflows only supply 4 soil sensors (pH, EC, moisture, temperature) plus crop type and soil type. Excluding unavailable fields ensures production inference never fabricates missing N-P-K or lab values.

| Feature Set | Estimator | CV Accuracy | CV Macro-F1 | CV Weighted-F1 |
|---|---|---:|---:|---:|
| 6 SoilSense features (Production — 4 sensors + crop/soil, no N-P-K) | `GradientBoostingClassifier` | 0.3162 ± 0.0087 | 0.2031 | 0.2528 |
| All 19 CSV features (Diagnostic Ablation — includes N-P-K & Growth Stage) | `GradientBoostingClassifier` | 0.8851 ± 0.0064 | 0.7488 | 0.8946 |

> Omitting Nitrogen_Level, Phosphorus_Level, and Potassium_Level (which drive 68.38% of the synthetic CSV's deterministic cascade) plus Crop_Growth_Stage (4.53%) causes CV accuracy to drop sharply from ~88.5% (19 features) to ~31.6% (6 features, matching the 32.05% 6-feature Bayes ceiling). Without N-P-K, only the Soil_pH thresholds (<5.5 -> Compost, >7.8 -> Zinc Sulphate) and overall class priors carry signal in this synthetic CSV.

## 4. 5-Fold Stratified Cross-Validation Model Comparison (6 Features)

| Model | Class Weight | CV Accuracy | CV Macro-F1 | CV Weighted-F1 | Train Fold Acc | Train-CV Acc Gap | Production Eligible |
|---|---|---:|---:|---:|---:|---:|:---:|
| `LogisticRegression` | None | 0.3024 ± 0.0083 | 0.1824 | 0.2534 | 0.3207 | +0.0183 | Yes |
| `LogisticRegression_balanced` | balanced | 0.1904 ± 0.0075 | 0.1655 | 0.1279 | 0.2013 | +0.0110 | Yes |
| `RandomForestClassifier` | None | 0.3051 ± 0.0068 | 0.1783 | 0.2625 | 0.7626 | +0.4575 | Yes |
| `RandomForestClassifier_balanced` | balanced | 0.2203 ± 0.0077 | 0.2058 | 0.1719 | 0.5124 | +0.2922 | Yes |
| `GradientBoostingClassifier` | None | 0.3162 ± 0.0087 | 0.2031 | 0.2528 | 0.3362 | +0.0200 | Yes |

**Selection Rationale:** Selected GradientBoostingClassifier among production-eligible string-native sklearn Pipelines (cv_accuracy=0.3162, cv_macro_f1=0.2031, train_cv_accuracy_gap=0.0200, train_cv_macro_f1_gap=0.0128; class_weight=None (preserves honest posterior probabilities instead of amplifying noise)). Matches the 6-feature synthetic Bayes ceiling (~0.3205) via pH threshold splits (pH < 5.5 -> Compost, pH > 7.8 -> Zinc Sulphate) and class priors with minimal train-vs-CV overfitting gap.

## 5. Held-Out 20% Test Set Evaluation

- **Test Accuracy:** 0.3235
- **Test Macro-F1:** 0.2113
- **Test Weighted-F1:** 0.2624
- **Full-Train Accuracy vs Test Accuracy Gap:** 0.3334 vs 0.3235 (gap: +0.0099)

### Per-Class Classification Metrics (Test Set)

| Fertilizer Class | Train+Test Support | Test Support | Precision | Recall | F1-Score |
|---|---:|---:|---:|---:|---:|
| Compost | 996 (9.96%) | 199 | 0.3035 | 0.6482 | 0.4135 |
| DAP | 2920 (29.2%) | 584 | 0.3394 | 0.1592 | 0.2168 |
| MOP | 1408 (14.08%) | 282 | 0.0000 | 0.0000 | 0.0000 |
| NPK | 641 (6.41%) | 128 | 0.0000 | 0.0000 | 0.0000 |
| SSP | 182 (1.82%) | 37 | 0.0000 | 0.0000 | 0.0000 |
| Urea | 3101 (31.01%) | 620 | 0.3202 | 0.5371 | 0.4012 |
| Zinc Sulphate | 752 (7.52%) | 150 | 0.3525 | 0.6133 | 0.4477 |

**Class Imbalance Analysis:** Target shows 17.04:1 class imbalance (majority Urea 31.01% vs minority SSP 1.82%). Without N, P, K, and Crop_Growth_Stage, Urea, DAP, MOP, NPK, and SSP are indistinguishable except through neutral-pH priors (only Compost at pH < 5.5 and Zinc Sulphate at pH > 7.8 have direct 4-sensor signal). Applying class_weight='balanced' collapses overall accuracy (~19-22%) by over-predicting noise-dominated minority classes.

### Confusion Matrix (Rows = True, Cols = Predicted)

| True \ Pred | Compost | DAP | MOP | NPK | SSP | Urea | Zinc Sulphate |
|---|---:|---:|---:|---:|---:|---:|---:|
| **Compost** | 129 | 9 | 0 | 0 | 0 | 61 | 0 |
| **DAP** | 120 | 93 | 0 | 0 | 0 | 303 | 68 |
| **MOP** | 48 | 44 | 0 | 0 | 0 | 168 | 22 |
| **NPK** | 0 | 30 | 0 | 0 | 0 | 98 | 0 |
| **SSP** | 0 | 7 | 0 | 0 | 0 | 30 | 0 |
| **Urea** | 128 | 80 | 0 | 0 | 0 | 333 | 79 |
| **Zinc Sulphate** | 0 | 11 | 0 | 0 | 0 | 47 | 92 |

## 6. Aggregated Feature Importances (6 Input Features)

| Input Feature | Native Aggregated Importance | Test Permutation Importance (Acc Drop) |
|---|---:|---:|
| `Soil_pH` | 0.9312 | +0.0868 |
| `Soil_Moisture` | 0.0280 | -0.0039 |
| `Temperature` | 0.0274 | +0.0026 |
| `Electrical_Conductivity` | 0.0094 | +0.0021 |
| `Soil_Type` | 0.0023 | +0.0000 |
| `Crop_Type` | 0.0016 | +0.0003 |
