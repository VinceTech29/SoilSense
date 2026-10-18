# SoilSense — Development Implementation Checklist

## Status Legend
- [ ] Not Started
- [/] In Progress
- [x] Completed

---

## Pre-flight & Environment Setup
- [x] Inspect existing workspace, PHP (8.2.12), Laravel (12.70.0), Node.js, and Composer
- [x] Generate application key (`APP_KEY`) and run baseline unit tests
- [x] Identify environment blockers (MySQL inactive, missing `soilsense` database)
- [ ] User approval of Architectural Specification & Schema Design
- [ ] Start MySQL service (XAMPP) & verify connection
- [ ] Update `.env` to `DB_DATABASE=soilsense` and create database
- [ ] Scaffold Laravel API routing (`php artisan install:api`)
- [x] Install frontend dependencies (`npm install` & `chart.js`)

---

## Phase 1: Database Architecture & Models
- [ ] Create migration for `devices` table
- [ ] Create migration for `sensor_readings` table (pH, Moisture, Temp, EC, `is_demo`, `is_valid`)
- [ ] Create migration for `crops` table (documented FAO/USDA agronomic ranges)
- [ ] Create migration for `soil_assessments` table
- [ ] Implement Eloquent models (`Device`, `SensorReading`, `Crop`, `SoilAssessment`)
- [ ] Implement relationships, casts, and query scopes
- [ ] Create `CropSeeder` with verified agricultural parameters (Rice, Maize, Tomato, etc.)
- [ ] Create `DeviceSeeder` with a demo ESP32 device and sample readings (`is_demo = true`)
- [ ] Run migrations and seeders, then verify database structure

---

## Phase 2: Domain Services Engine
- [ ] Implement `SoilAssessmentService`:
  - [ ] pH classification (acidic, neutral, alkaline)
  - [ ] Moisture classification (deficit, optimal, saturation)
  - [ ] Temperature classification (cold stress, optimal, heat stress)
  - [ ] Electrical conductivity / salinity classification
  - [ ] Overall soil quality index and corrective agronomic advice
- [ ] Implement `CropRecommendationService`:
  - [ ] Matching logic comparing soil readings against crop requirement thresholds
  - [ ] Tiered scoring (Highly, Moderately, Marginally, Unsuitable)
  - [ ] Factor diagnosis highlighting specific limiting parameters
- [ ] Unit tests for `SoilAssessmentService` and `CropRecommendationService`

---

## Phase 3: IoT REST API for ESP32
- [ ] Create `StoreReadingRequest` with validation rules and physical bounds checking
- [ ] Implement `DeviceTokenMiddleware` for secure token authentication
- [ ] Implement `Api/SensorReadingController` (`POST /api/v1/sensors/readings`)
- [ ] Implement device heartbeat endpoint (`GET /api/v1/sensors/ping`)
- [ ] Feature tests for API validation, authentication, and ingestion

---

## Phase 4: Frontend & Interactive Dashboard
- [x] Design base Blade layout with Tailwind CSS navigation and responsive container
- [x] Build **Landing Page View** (`resources/views/landing.blade.php`) with hero, metrics, features, how it works, and footer
- [x] Build **Dashboard View** (`resources/views/dashboard.blade.php`):
  - [x] Live sensor metric cards with status colors, badges, and SVG sparklines
  - [x] Latest soil assessment alert banner with preliminary completeness meter
  - [x] Dynamic pH trend chart with time-range selector
  - [x] Crop suitability recommendation preview
  - [x] Recent soil samples table with search and pagination
- [x] Build **Soil Analysis View** (`resources/views/analysis.blade.php`):
  - [x] 3-step collection workflow
  - [x] Live IoT test vs Manual entry toggle with real-time test simulation
  - [x] Data quality readiness checklist with 2/5 ring score
- [x] Build **Crop Catalog View** (`resources/views/crops.blade.php`): Searchable and filterable list of crops
- [x] Build **Crop Details View** (`resources/views/crop-detail.blade.php`): Range bars and factor diagnosis
- [x] Build **Soil Assessment Report View** (`resources/views/report.blade.php`): Executive summary and printable format
- [x] Build **Sample History View** (`resources/views/history.blade.php`): Multi-select comparison and date filters
- [x] Build **Reports & Exports View** (`resources/views/reports.blade.php`): Document templates and recent reports table
- [x] Build **Device Monitoring View** (`resources/views/device.blade.php`): Interactive status switcher, sensor inventory, and transmission logs
- [x] Build **Settings View** (`resources/views/settings.blade.php`): Researcher profile and security disclosures
- [x] Vanilla JavaScript integration for responsive sidebar drawer, simulation toggle, and search/filtering

---

## Phase 5: Reports & ESP32 Firmware
- [ ] Build **Readings History & Export View** with date and device filters
- [ ] Implement CSV export for historical readings
- [ ] Write well-documented ESP32 firmware sketch (`SoilSense_ESP32.ino`) with:
  - [ ] Sensor reading logic with pin definitions
  - [ ] WiFi multi-reconnect handling
  - [ ] JSON payload formatting
  - [ ] HTTP POST to Laravel API with device token header
  - [ ] Error handling and local status LED indicator

---

## Phase 6: Final Verification & Academic Documentation
- [ ] End-to-end test with simulated ESP32 payload
- [ ] Run full automated test suite (`php artisan test`)
- [ ] Verify no regressions, errors, or security vulnerabilities
- [ ] Document project architecture and results for academic submission
