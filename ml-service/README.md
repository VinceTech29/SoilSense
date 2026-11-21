# SoilSense Fertilizer Classification Service

FastAPI microservice and scikit-learn training pipeline that classifies **which** fertilizer type (`Urea`, `DAP`, `MOP`, `Compost`, `Zinc Sulphate`, `NPK`, or `SSP`) best matches a SoilSense 4-sensor soil reading and crop/soil profile — **without requiring Nitrogen (N), Phosphorus (P), or Potassium (K) inputs**.

> **Important Scope Note:** This service predicts **fertilizer class and probability rankings only**. It never outputs application dosage (`kg/ha` or `g/m²`).

## Architecture

- **Model artifact (`models/model.joblib`):** Single `sklearn.pipeline.Pipeline` (`ColumnTransformer` + `GradientBoostingClassifier`) trained on the **6 SoilSense-available features** — 4 hardware sensors (`Soil_pH`, `Electrical_Conductivity`, `Soil_Moisture`, `Temperature`) plus 2 categorical inputs (`Crop_Type`, `Soil_Type`). Nitrogen, Phosphorus, and Potassium are excluded completely.
- **Crop normalization:** Maps `Sweet Corn` / `Corn` -> `Maize` and passes unseen SoilSense crops (e.g. `Pechay`, `Eggplant`, `Okra`) cleanly through `OneHotEncoder(handle_unknown="ignore")` with an advisory warning.
- **Security:** `X-API-KEY` header checked via constant-time `hmac.compare_digest` on `/model-info` and `/predict`. Fails closed with HTTP `503` if `ML_API_KEY` is not set. CORS is disabled unless `CORS_ALLOWED_ORIGINS` is explicitly configured (wildcard `*` is disallowed).

## Setup (Local Virtual Environment)

```powershell
cd ml-service
uv venv --seed --python 3.12 .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
```

## Training & Evaluation

Copy or place `fertilizer_recommendation.csv` in `ml-service/data/` and run:

```powershell
cd ml-service
.\.venv\Scripts\python.exe training/train.py --data data/fertilizer_recommendation.csv --output-dir models --random-state 42
```

Outputs `models/model.joblib`, `models/metadata.json`, and `REPORT.md`. Custom 6-feature field CSVs (containing only `Soil_pH`, `Electrical_Conductivity`, `Soil_Moisture`, `Temperature`, `Crop_Type`, `Soil_Type`, and `Recommended_Fertilizer` — no N-P-K columns required) are also supported directly.

## Running the API Locally

```powershell
cd ml-service
$env:ML_API_KEY="your-strong-secret-key"
.\.venv\Scripts\uvicorn.exe app.main:app --host 0.0.0.0 --port 8001
```

## Docker Build & Run

```bash
cd ml-service
docker build -t soilsense-ml-service .
docker run --rm -p 8001:8001 \
  -e ML_API_KEY="your-strong-secret-key" \
  -e LOW_CONFIDENCE_THRESHOLD="0.55" \
  soilsense-ml-service
```

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `ML_API_KEY` | *(required)* | Shared secret for `X-API-KEY` auth on `/model-info` and `/predict` (fails closed with 503 if unset) |
| `MODEL_PATH` | `models/model.joblib` | Path to serialized scikit-learn Pipeline |
| `METADATA_PATH` | `models/metadata.json` | Path to model metadata JSON |
| `LOW_CONFIDENCE_THRESHOLD` | `0.55` | Predictions below this probability set `low_confidence: true` |
| `CORS_ALLOWED_ORIGINS` | `""` | Comma-separated origins (empty = CORS middleware disabled; `*` ignored) |

## API Contract

- `GET /health` *(unauthenticated)* -> `{"status": "ok", "model_loaded": true, "model_version": "2.0.0"}`
- `GET /model-info` *(`X-API-KEY`)* -> Model version, selected estimator, 6 production features, 7 classes, 6-feature vs 19-feature CV/test metrics, and synthetic dataset caveats.
- `POST /predict` *(`X-API-KEY`)*:
  - Request JSON (4 sensors + crop/soil type, no N-P-K):
    `{"ph": 4.8, "ec": 1.2, "moisture": 35.0, "temperature": 26.5, "crop": "Sweet Corn", "soil_type": "Loamy"}`
  - Input validation bounds: `ph` `[0, 14]`, `ec` `[0, 30]`, `moisture` `[0, 100]`, `temperature` `[-10, 60]`, `crop` non-empty string, `soil_type` optional (`Clay`, `Loamy`, `Sandy`, `Silt`; defaults to `"Loamy"`).
  - Response JSON:
    `{"predicted_fertilizer": "Compost", "confidence": 0.316..., "low_confidence": true, "top_predictions": [...], "model_version": "2.0.0", "crop_mapped_from": "Sweet Corn", "warnings": [...]}`

## Synthetic Dataset Caveats (Honest Accuracy Disclosure)

`fertilizer_recommendation.csv` is 100% synthetic with independent uniform features: 86.42% of rows follow a 6-rule deterministic cascade where Nitrogen, Phosphorus, and Potassium alone drive **68.38%** of rows (`N<60 -> Urea` 29.02%, `P<40 -> DAP` 27.38%, `K<40 -> MOP` 11.98%), followed by `pH<5.5 -> Compost` (7.98%), `pH>7.8 -> Zinc Sulphate` (5.53%), `Crop_Growth_Stage=='Vegetative' -> NPK` (4.53%), and 13.58% uniform random fallback labels across all 7 classes.

Because SoilSense does not measure N, P, or K and excludes those 3 columns plus 10 other unavailable CSV fields:
- The theoretical Bayes accuracy ceiling drops from **~88.52%** (diagnostic 19-feature ablation: 0.8851 CV accuracy) to **~32.05%** on the 6 SoilSense features (production model: **0.3162** 5-fold CV accuracy, **0.3235** held-out test accuracy, **0.2113** test macro-F1, train-test gap `+0.0099`).
- On this synthetic dataset, the 6-feature classifier mainly reflects the pH thresholds (`pH < 5.5 -> Compost`, `pH > 7.8 -> Zinc Sulphate`) plus neutral-pH class priors (`Urea`), with honest top-class posterior probabilities around `0.30–0.34`.
- At the default `LOW_CONFIDENCE_THRESHOLD=0.55`, predictions therefore set `low_confidence: true` so downstream SoilSense deterministic agronomic rules can provide transparent fallback guidance. Retrain on real regional 4-sensor field-trial data when available. See `REPORT.md` for full metrics, confusion matrix, and ablation tables.

## Running Tests

```powershell
cd ml-service
.\.venv\Scripts\pytest.exe -v
```
