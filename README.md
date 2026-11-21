# SoilSense — IoT Soil Assessment & Crop Suitability Recommendation System

SoilSense is an academic IoT and decision-support web application that ingests soil measurements from an ESP32 microcontroller, analyzes soil conditions according to agronomic standards, and provides crop suitability recommendations.

---

## Tech Stack
- **Backend Framework**: Laravel 12 (PHP 8.2+)
- **Database**: MySQL
- **Frontend / Templating**: Blade templates, Tailwind CSS (via Vite)
- **Data Visualization**: Chart.js (vanilla JS integration)
- **IoT Hardware**: ESP32 with sensors for:
  - Soil pH
  - Soil Moisture (%)
  - Soil Temperature (°C)
  - Electrical Conductivity (EC in µS/cm)
- **IoT Communication**: HTTP REST API with JSON payloads and token authentication

---

## Core Capabilities
1. **IoT Ingestion API**:
   - Secure endpoint (`POST /api/v1/readings`) with device token authentication.
   - Strict sensor data validation, anomaly bounds checking, and calibration offset support.
   - Clear distinction between live hardware telemetry and simulated demonstration data (`is_demo`).
2. **Soil Assessment Engine**:
   - Classifies pH (acidic, neutral, alkaline), moisture (deficit, optimal, saturation), temperature, and electrical conductivity (salinity risk).
   - Generates actionable agronomic recommendations (e.g., lime application, irrigation adjustments, salinity flushing).
3. **Crop Suitability Engine**:
   - Compares real-time soil data against verified agricultural reference values (FAO / USDA guidelines).
   - Ranks crops into four tiers: **Highly Suitable**, **Moderately Suitable**, **Marginally Suitable**, and **Unsuitable**.
4. **Interactive Dashboard**:
   - Live telemetry status cards with dynamic color alerts.
   - Historical trend analysis charts (24 hours, 7 days, 30 days).
   - Crop matrix with match percentage and limiting factor diagnosis.
5. **Data Export & Reporting**:
   - Filterable reading logs with CSV export.

---

## Getting Started

### 1. Prerequisites
- PHP >= 8.2 (with `pdo_mysql`, `mbstring`, `openssl`, `curl` extensions enabled)
- Composer >= 2.0
- Node.js >= 18.0 & npm
- MySQL Server (e.g., XAMPP MySQL)

### 2. Environment Configuration
Ensure MySQL is running, then copy the environment file and generate the application key if not already done:
```bash
cp .env.example .env
php artisan key:generate
```

In `.env`, configure your database connection:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=soilsense
DB_USERNAME=root
DB_PASSWORD=
```

### 3. Install Dependencies & Build Frontend
```bash
composer install
npm install
npm run build
```

### 4. Database Setup & Seeding
```bash
# Run database migrations
php artisan migrate

# Seed reference crop data and demonstration device
php artisan db:seed
```

### 5. Running the Application
```bash
# Start local development server
php artisan serve
```

Access the application in your browser at `http://127.0.0.1:8000`.

---

## Project Structure
- `app/Services/SoilAssessmentService.php`: Core soil evaluation and limiting factor identification.
- `app/Services/CropSuitabilityService.php`: Crop requirement comparison and suitability scoring.
- `app/Http/Controllers/Api/SensorReadingApiController.php`: IoT REST API endpoint for ESP32.
- `app/Http/Controllers/Api/MobileApiController.php`: Mobile REST API endpoints for Flutter integration.
- `app/Http/Controllers/SoilSenseController.php`: Dashboard and web interface controller.
- `resources/views/`: Blade views styled with Tailwind CSS.
- `database/seeders/CropSeeder.php`: Documented crop requirements (FAO standards).

---

## Mobile API
The SoilSense mobile REST API (`/api/v1/mobile/*`) provides structured JSON endpoints consumed by the Flutter mobile application:

| HTTP Method | Endpoint | Route Name | Description |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/v1/mobile/dashboard` | `api.v1.mobile.dashboard` | Structured measurement cards, active device status, recommended crops, and recent samples |
| `GET` | `/api/v1/mobile/telemetry/latest` | `api.v1.mobile.telemetry.latest` | Most recent sensor reading with calibration metadata |
| `GET` | `/api/v1/mobile/telemetry/history` | `api.v1.mobile.telemetry.history` | Telemetry series data for mobile chart widgets |
| `GET` | `/api/v1/mobile/crops` | `api.v1.mobile.crops` | Crop catalog with pH, moisture, and temperature requirements |
| `GET` | `/api/v1/mobile/crops/{name}` | `api.v1.mobile.crops.detail` | Individual crop profile details and reference thresholds |
| `GET` | `/api/v1/mobile/samples` | `api.v1.mobile.samples` | Paginated soil sample history (15 per page) |
| `POST` | `/api/v1/mobile/samples` | `api.v1.mobile.samples.store` | Record a new soil sample and execute automated assessment |
| `GET` | `/api/v1/mobile/assessments/{id}` | `api.v1.mobile.assessments.show` | Assessment details, soil sample, and crop suitability evaluations |
| `GET` | `/api/v1/mobile/reports` | `api.v1.mobile.reports` | Paginated sample assessment reports (15 per page) |
| `GET` | `/api/v1/mobile/reports/{id}` | `api.v1.mobile.reports.show` | Comprehensive sample report with assessment, crop suitability, and measurements |
| `GET` | `/api/v1/mobile/devices` | `api.v1.mobile.devices` | Registered IoT devices with latest sensor telemetry |
| `GET` | `/api/v1/mobile/devices/{id}` | `api.v1.mobile.devices.show` | Single IoT device telemetry details and connection status |
| `GET` | `/api/v1/mobile/samples/{id}/fertilizer` | `api.v1.mobile.samples.fertilizer.show` | Latest fertilizer recommendation, dosage schedule, and Rain Guard advisory (device token auth) |
| `POST` | `/api/v1/mobile/samples/{id}/fertilizer` | `api.v1.mobile.samples.fertilizer.store` | Submit manual N-P-K readings and generate a fertilizer recommendation (device token auth) |

---

## Fertilizer Recommendation Module

SoilSense includes a hybrid **Fertilizer Recommendation Module** that pairs a Python/FastAPI scikit-learn classifier with deterministic agronomic dosage math and a 72-hour rainfall guard.

### Architecture Overview

```text
+-------------------------------------------------------------------+
|  Telemetry & Input Sources                                        |
|  - ESP32 IoT Node (POST /api/v1/readings with optional n, p, k)   |
|  - Web UI Manual N-P-K Form (POST /report/{id}/npk & /analysis)   |
|  - Flutter Mobile App (GET/POST /api/v1/mobile/samples/{id}/...)  |
+---------------------------------+---------------------------------+
                                  |
                                  v
+-------------------------------------------------------------------+
|  Laravel 12 Orchestration Layer                                   |
|  - FertilizerRecommendationService                                |
|    ├── FertilizerPredictionService ──► FastAPI ml-service (/predict)
|    │     (X-API-KEY auth + deterministic rule fallback)           |
|    ├── FertilizerDosageCalculator  ──► config/fertilizer.php      |
|    │     (deterministic kg/ha rate + split application schedule)  |
|    └── RainGuardService            ──► Open-Meteo 72h Forecast    |
|          (cached by rounded lat/lon; fail-safe manual_check)      |
+---------------------------------+---------------------------------+
                                  |
                                  v
+-------------------------------------------------------------------+
|  Persistence & Outputs                                            |
|  - MySQL / SQLite (sensor_readings, fertilizer_recommendations)   |
|  - Blade Report Card + History N-P-K Chart.js Trends              |
|  - CSV Export (/settings/export/samples) & Mobile JSON Resource   |
+-------------------------------------------------------------------+
```

### Environment Variables Reference

#### Laravel (`.env`)

| Variable | Default | Description |
| :--- | :--- | :--- |
| `ML_SERVICE_URL` | `http://127.0.0.1:8001` | Base URL of the FastAPI ML inference service |
| `ML_SERVICE_API_KEY` | *(empty)* | Shared secret sent in `X-API-KEY` header; if unset, Laravel uses deterministic rule fallback |
| `ML_SERVICE_TIMEOUT` | `5` | HTTP timeout in seconds for `/predict` requests |
| `ML_SERVICE_RETRIES` | `1` | Retry count on transient ML service errors |
| `ML_LOW_CONFIDENCE_THRESHOLD` | `0.55` | Predictions below this probability trigger low-confidence fallback |
| `OPEN_METEO_URL` | `https://api.open-meteo.com/v1/forecast` | Open-Meteo hourly precipitation endpoint |
| `OPEN_METEO_TIMEOUT` | `5` | HTTP timeout in seconds for weather queries |
| `RAIN_GUARD_CACHE_TTL` | `3600` | Cache TTL in seconds for successful forecasts (keyed by rounded lat/lon) |
| `RAIN_GUARD_THRESHOLD_MM` | `10.0` | 72-hour cumulative rainfall (mm) threshold that triggers `delay` |
| `RAIN_GUARD_PROBABILITY_THRESHOLD` | `60` | Max hourly rain probability (%) threshold that triggers `delay` |
| `DEFAULT_FIELD_LATITUDE` | `14.1699` | Fallback field latitude (Laguna, PH) when sample coordinates are absent |
| `DEFAULT_FIELD_LONGITUDE` | `121.2441` | Fallback field longitude (Laguna, PH) when sample coordinates are absent |
| `FERTILIZER_SOIL_SUPPLY_FACTOR` | `1.95` | Plough-layer conversion factor (`mg/kg -> kg/ha`, 1.3 g/cm³ × 15 cm × 0.1) |
| `FERTILIZER_MAX_SINGLE_APP_KG_HA` | `150.0` | Maximum single-application rate before splitting across growth stages |

#### FastAPI ML Microservice (`ml-service/.env`)

| Variable | Default | Description |
| :--- | :--- | :--- |
| `ML_API_KEY` | *(required)* | API key verified via constant-time comparison on `/predict` and `/model-info` (fails closed with HTTP `503` if unset) |
| `MODEL_PATH` | `models/model.joblib` | Path to the serialized scikit-learn `Pipeline` artifact |
| `METADATA_PATH` | `models/metadata.json` | Path to model version, CV metrics, and feature schema metadata |
| `LOW_CONFIDENCE_THRESHOLD` | `0.55` | Probability threshold for setting `low_confidence: true` in responses |
| `CORS_ALLOWED_ORIGINS` | *(empty)* | Comma-separated allowed browser origins (wildcard `*` is disallowed) |

### Training the ML Model

1. Create a Python virtual environment and install dependencies:
   ```bash
   cd ml-service
   python -m venv .venv
   # Windows PowerShell:
   .venv\Scripts\Activate.ps1
   # macOS / Linux:
   # source .venv/bin/activate
   pip install -r requirements.txt
   ```
2. Place the training CSV at `ml-service/data/fertilizer_recommendation.csv` (gitignored) and run the training script:
   ```bash
   python training/train.py --data data/fertilizer_recommendation.csv
   ```
   This trains and evaluates candidate pipelines using 5-fold stratified CV on the 9 SoilSense-aligned features (`Nitrogen_Level`, `Phosphorus_Level`, `Potassium_Level`, `Soil_pH`, `Electrical_Conductivity`, `Soil_Moisture`, `Temperature`, `Crop_Type`, `Soil_Type`), and outputs `models/model.joblib`, `models/metadata.json`, and `REPORT.md`.

### Running the FastAPI ML Service

**Option A — Local Uvicorn:**
```bash
cd ml-service
cp .env.example .env
# Edit .env and set ML_API_KEY (must match Laravel ML_SERVICE_API_KEY)
uvicorn app.main:app --host 127.0.0.1 --port 8001
```

**Option B — Docker:**
```bash
cd ml-service
docker build -t soilsense-ml:latest .
docker run --rm -p 8001:8001 -e ML_API_KEY="replace-with-strong-random-api-key" soilsense-ml:latest
```

Verify the service healthcheck (unauthenticated):
```bash
curl http://127.0.0.1:8001/health
```

### Running Laravel Migrations, Seeders & Web Server

```bash
php artisan migrate
php artisan db:seed
npm run build
php artisan serve
```

### Running Both Test Suites

**Laravel PHPUnit Suite (Unit + Feature + UI integration tests):**
```bash
php artisan test
```

**FastAPI & ML Artifact Pytest Suite:**
```bash
# From project root (Windows):
ml-service\.venv\Scripts\pytest.exe -v ml-service\tests

# Or inside ml-service/ with venv activated:
pytest -v tests
```

### Synthetic Dataset Caveats & Deterministic Dosage Separation

- **Strict Separation of Responsibilities ("WHAT" vs. "HOW MUCH"):** The scikit-learn `GradientBoostingClassifier` predicts **which** fertilizer class to apply (`Urea`, `DAP`, `MOP`, `SSP`, `NPK`, `Compost`, `Zinc Sulphate`) along with class probabilities. It **never** predicts application rates. All dosage rates (`kg/ha`) and split schedules are calculated deterministically in Laravel (`App\Services\FertilizerDosageCalculator`) from documented nutrient fractions and regional crop targets in `config/fertilizer.php`.
- **Synthetic Training Data Caveats:** As audited in `ml-service/REPORT.md`, the 10,000-row reference CSV is 100% synthetic (86.42% follows a 6-rule deterministic cascade and 13.58% is uniform random label noise). Because `Crop_Growth_Stage` is not supplied by SoilSense sensors and is excluded from the 9 production features, `NPK` precision drops to ~0.34 and `SSP` (1.82% support, all inside uniform noise) is unlearnable. Overall CV accuracy (~88.4%) reflects fidelity to the synthetic distribution, not real-world field trial accuracy.
- **Agronomic Advisory Disclaimer:** All SoilSense fertilizer outputs are decision-support advisories only. Always validate fertilizer product selection, kg/ha dosage rates, and weather timing with a local agronomist or accredited soil testing laboratory before field application.

---

## License
Academic and open-source project.
