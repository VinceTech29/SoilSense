# SoilSense ML Fertilizer Classifier Report

- **Generated (UTC):** 2026-11-21T12:04:04.884201+00:00
- **Selected Model:** `GradientBoostingClassifier` (single `sklearn.pipeline.Pipeline` with `ColumnTransformer`)
- **Task:** Multi-class fertilizer classification (`Recommended_Fertilizer` — predicts **WHAT** fertilizer, never dosage/HOW MUCH)
- **Production Features (9):** `Nitrogen_Level, Phosphorus_Level, Potassium_Level, Soil_pH, Electrical_Conductivity, Soil_Moisture, Temperature, Crop_Type, Soil_Type`
- **Target Classes (7):** `Compost, DAP, MOP, NPK, SSP, Urea, Zinc Sulphate`

## 1. Synthetic Dataset Caveats (Do Not Overstate Accuracy)

- **100% Synthetic Data:** 100% synthetic dataset with independent uniform features (max pairwise |r| < 0.03). 8,642/10,000 rows (86.42%) follow an exact 6-rule deterministic cascade: (1) N < 60 -> Urea; (2) P < 40 -> DAP; (3) K < 40 -> MOP; (4) pH < 5.5 -> Compost; (5) pH > 7.8 -> Zinc Sulphate; (6) Crop_Growth_Stage == 'Vegetative' -> NPK. The remaining 1,358 rows (13.58%) are sampled approximately uniformly across all 7 classes.
- **Theoretical Bayes Ceilings:** ~88.52% (19 features) / ~88.3% (9 features with NPK fallback; ~84.0% pure rules-only).
- **Caveat:** Because Crop_Growth_Stage is excluded from the 9 SoilSense-aligned production features and the 1,358 fallback rows are irreducible uniform label noise, the 9-feature pipeline loses rule #6 (Crop_Growth_Stage == 'Vegetative' -> NPK): pure deterministic-rule coverage drops from 86.42% to 81.89% (~84% if predicting uniformly on fallback, or ~88.3% Bayes ceiling when defaulting the non-rule region to NPK, which drops NPK precision to ~0.33). Do NOT overstate overall accuracy as real-world agronomic accuracy; the model reproduces the synthetic training distribution rather than field trial outcomes.

## 2. Data Cleaning & Leakage Audit

- Raw rows: **10000**
- Exact duplicates dropped: **0**
- Null target rows dropped: **0**
- Out-of-agronomic-range rows dropped: **0**
- Cleaned rows used (80/20 stratified split -> 8000 train / 2000 test): **10000**
- Train/test 9-feature vector overlap: **0**
- Leakage check: Zero exact feature-vector overlap between train and test splits; preprocessing (median imputer, StandardScaler, OneHotEncoder) is encapsulated inside sklearn Pipeline and fitted strictly within each CV fold and on train split only. Historical columns (Fertilizer_Used_Last_Season, Yield_Last_Season) are excluded.

## 3. Production 9-Feature Schema & 19-Feature Ablation

- **Why 10 columns are excluded:** 10 columns (Organic_Carbon, Humidity, Rainfall, Crop_Growth_Stage, Season, Irrigation_Type, Previous_Crop, Region, Fertilizer_Used_Last_Season, Yield_Last_Season) are excluded from the production 9-feature pipeline because SoilSense ESP32 hardware and soil sample workflows only supply NPK, pH, EC, moisture, temperature, crop type, and soil type. Excluding unavailable fields ensures production inference never fabricates missing sensor values.

| Feature Set | Estimator | CV Accuracy | CV Macro-F1 | CV Weighted-F1 |
|---|---|---:|---:|---:|
| 9 SoilSense features (Production) | `GradientBoostingClassifier` | 0.8844 ± 0.0081 | 0.7317 | 0.8964 |
| All 19 CSV features (Ablation) | `GradientBoostingClassifier` | 0.8889 ± 0.0066 | 0.7636 | 0.8893 |

> Omitting Crop_Growth_Stage (rule #6: Crop_Growth_Stage == 'Vegetative' -> NPK) reduces CV macro-F1 by ~4% because NPK can no longer be separated from the 1,358 uniform fallback rows (NPK precision drops to ~0.33 when defaulting the non-rule region to NPK, even though overall accuracy remains ~88% vs ~88.5% 19-feature Bayes ceiling, or ~84% without NPK majority fallback). The other 9 excluded features carry negligible signal.

## 4. 5-Fold Stratified Cross-Validation Model Comparison (9 Features)

| Model | Class Weight | CV Accuracy | CV Macro-F1 | CV Weighted-F1 | Train Fold Acc | Train-CV Acc Gap | Production Eligible |
|---|---|---:|---:|---:|---:|---:|:---:|
| `LogisticRegression` | None | 0.7888 ± 0.0056 | 0.5895 | 0.7700 | 0.7918 | +0.0030 | Yes |
| `LogisticRegression_balanced` | balanced | 0.7816 ± 0.0025 | 0.6246 | 0.7870 | 0.7883 | +0.0067 | Yes |
| `RandomForestClassifier` | None | 0.8860 ± 0.0064 | 0.7307 | 0.8985 | 0.9164 | +0.0304 | Yes |
| `RandomForestClassifier_balanced` | balanced | 0.8816 ± 0.0043 | 0.7459 | 0.9007 | 0.9136 | +0.0320 | Yes |
| `GradientBoostingClassifier` | None | 0.8844 ± 0.0081 | 0.7317 | 0.8964 | 0.9009 | +0.0165 | Yes |

**Selection Rationale:** Selected GradientBoostingClassifier among production-eligible string-native sklearn Pipelines (cv_accuracy=0.8844, cv_macro_f1=0.7317, train_cv_accuracy_gap=0.0165, train_cv_macro_f1_gap=0.0749; class_weight=None (avoids inflating false positives on uniform fallback noise)). Balances CV accuracy near the synthetic 9-feature ceiling with macro-F1 while penalizing train-vs-CV overfitting gaps.

## 5. Held-Out 20% Test Set Evaluation

- **Test Accuracy:** 0.8690
- **Test Macro-F1:** 0.7233
- **Test Weighted-F1:** 0.8865
- **Full-Train Accuracy vs Test Accuracy Gap:** 0.8965 vs 0.8690 (gap: +0.0275)

### Per-Class Classification Metrics (Test Set)

| Fertilizer Class | Train+Test Support | Test Support | Precision | Recall | F1-Score |
|---|---:|---:|---:|---:|---:|
| Compost | 996 (9.96%) | 199 | 0.9809 | 0.7739 | 0.8652 |
| DAP | 2920 (29.2%) | 584 | 0.9944 | 0.9195 | 0.9555 |
| MOP | 1408 (14.08%) | 282 | 0.9915 | 0.8298 | 0.9035 |
| NPK | 641 (6.41%) | 128 | 0.3361 | 0.9609 | 0.4980 |
| SSP | 182 (1.82%) | 37 | 0.2000 | 0.0270 | 0.0476 |
| Urea | 3101 (31.01%) | 620 | 1.0000 | 0.9323 | 0.9649 |
| Zinc Sulphate | 752 (7.52%) | 150 | 0.9407 | 0.7400 | 0.8284 |

**Class Imbalance Analysis:** Target shows 17.04:1 class imbalance (majority Urea 31.01% vs minority SSP 1.82%). Because all 182 SSP rows occur exclusively inside the 1,358 uniform random fallback rows with no deterministic feature rule, class_weight='balanced' increases false positives on majority rules without finding true SSP signal.

### Confusion Matrix (Rows = True, Cols = Predicted)

| True \ Pred | Compost | DAP | MOP | NPK | SSP | Urea | Zinc Sulphate |
|---|---:|---:|---:|---:|---:|---:|---:|
| **Compost** | 154 | 0 | 0 | 44 | 0 | 0 | 1 |
| **DAP** | 0 | 537 | 0 | 42 | 0 | 0 | 5 |
| **MOP** | 2 | 0 | 234 | 44 | 1 | 0 | 1 |
| **NPK** | 0 | 3 | 1 | 123 | 1 | 0 | 0 |
| **SSP** | 1 | 0 | 0 | 35 | 1 | 0 | 0 |
| **Urea** | 0 | 0 | 1 | 41 | 0 | 578 | 0 |
| **Zinc Sulphate** | 0 | 0 | 0 | 37 | 2 | 0 | 111 |

## 6. Aggregated Feature Importances (9 Input Features)

| Input Feature | Native Aggregated Importance | Test Permutation Importance (Acc Drop) |
|---|---:|---:|
| `Nitrogen_Level` | 0.5369 | +0.3743 |
| `Phosphorus_Level` | 0.2979 | +0.2767 |
| `Potassium_Level` | 0.0975 | +0.1286 |
| `Soil_pH` | 0.0621 | +0.1219 |
| `Temperature` | 0.0018 | -0.0003 |
| `Electrical_Conductivity` | 0.0015 | -0.0006 |
| `Soil_Moisture` | 0.0015 | +0.0001 |
| `Crop_Type` | 0.0006 | +0.0003 |
| `Soil_Type` | 0.0003 | +0.0013 |
