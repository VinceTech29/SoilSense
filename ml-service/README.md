# SoilSense Fertilizer Classification Service

FastAPI microservice and scikit-learn training pipeline that classifies **which** fertilizer type (`Urea`, `DAP`, `MOP`, `Compost`, `Zinc Sulphate`, `NPK`, or `SSP`) best matches a SoilSense soil sample or sensor reading.

> **Important Scope Note:** This service predicts **fertilizer class and probability rankings only**. It never outputs application dosage (`kg/ha` or `g/m²`).

## Architecture

- **Model artifact (`models/model.joblib`):** Single `sklearn.pipeline.Pipeline` (`ColumnTransformer` + `GradientBoostingClassifier`) trained on the 9 features available in SoilSense (`Nitrogen_Level`, `Phosphorus_Level`, `Potassium_Level`, `Soil_pH`, `Electrical_Conductivity`, `Soil_Moisture`, `Temperature`, `Crop_Type`, `Soil_Type`).
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

Outputs `models/model.joblib`, `models/metadata.json`, and `REPORT.md`.

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

- `GET /health` *(unauthenticated)* -> `{"status": "ok", "model_loaded": true, "model_version": "1.0.0"}`
- `GET /model-info` *(`X-API-KEY`)* -> Model version, selected estimator, 9 features, 7 classes, CV/test metrics, and synthetic dataset caveats.
- `POST /predict` *(`X-API-KEY`)*:
  - Request JSON: `{"n": 40, "p": 55, "k": 70, "ph": 6.4, "ec": 1.2, "moisture": 35.0, "temperature": 26.5, "crop": "Sweet Corn", "soil_type": "Loamy"}`
  - Response JSON: `{"predicted_fertilizer": "Urea", "confidence": 0.99..., "low_confidence": false, "top_predictions": [...], "model_version": "1.0.0", "crop_mapped_from": "Sweet Corn", "warnings": [...]}`

## Synthetic Dataset Caveats

`fertilizer_recommendation.csv` is 100% synthetic with independent uniform features: 86.42% of rows follow a 6-rule deterministic cascade (`N<60 -> Urea`, `P<40 -> DAP`, `K<40 -> MOP`, `pH<5.5 -> Compost`, `pH>7.8 -> Zinc Sulphate`, `Crop_Growth_Stage=='Vegetative' -> NPK`) and 13.58% are uniform random fallback labels across all 7 classes. Because `Crop_Growth_Stage` and 9 other unavailable fields are excluded from the 9 SoilSense sensor-aligned features, overall accuracy (~86.9% test / ~88.4% CV) reflects synthetic rule alignment rather than real-world agronomic field trials (`NPK` precision is ~0.34 and `SSP` 1.82% has no learnable signal). See `REPORT.md` for details.

## Running Tests

```powershell
cd ml-service
.\.venv\Scripts\pytest.exe -v
```
