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

---

## License
Academic and open-source project.
