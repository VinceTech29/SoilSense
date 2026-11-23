/*
 * ==============================================================================
 * SoilSense — IoT Soil Assessment & Crop Recommendation System
 * ESP32 Hardware Firmware Sketch
 * ==============================================================================
 * Compatible Boards: ESP32 DevKit V1 (30-pin / 38-pin), NodeMCU-32S, ESP32-WROOM-32
 * 
 * Hardware Sensors & Modules Supported:
 * 1. Soil pH Sensor (Analog, e.g. DFRobot Gravity SEN0161 or pH-4502C) -> GPIO 34 (ADC1_CH6)
 * 2. Capacitive Soil Moisture Sensor v1.2 / v2.0 -> GPIO 35 (ADC1_CH7)
 * 3. DS18B20 Waterproof Temperature Sensor (OneWire) -> GPIO 4 (with 4.7k pullup)
 * 4. Electrical Conductivity (EC) Sensor (Analog, optional) -> GPIO 32 (ADC1_CH4)
 * 5. 16x2 / 20x4 I2C LCD Display Module (PCF8574) -> SDA: GPIO 21, SCL: GPIO 22
 * 
 * Communication Protocol:
 * HTTP POST application/json with Bearer Token Authentication to Laravel Backend
 * ==============================================================================
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <OneWire.h>
#include <DallasTemperature.h>

// -----------------------------------------------------------------------------
// 1. NETWORK & API CONFIGURATION
// -----------------------------------------------------------------------------
const char* WIFI_SSID     = "placeholder";
const char* WIFI_PASSWORD = "placeholder";

// Change this to your computer's local IP address (e.g. 192.168.1.100:8000)
// When testing locally on the same WiFi network, find your IP using ipconfig
const char* SERVER_URL    = "http://192.168.1.100:8000/api/v1/readings";

// Authentication Bearer Token: each device has its own token, stored SHA-256-hashed in iot_devices.api_token_hash (seeded by DeviceSeeder), sent as a Bearer token
const char* API_TOKEN     = "demo-esp32-token-2025";

// Target soil sample identifier (registered in SoilSense) and device identifier
const char* SAMPLE_ID     = "SS-2025-018";
const char* DEVICE_ID     = "ESP32-01";

// Reading transmission interval (e.g., every 30 seconds = 30000 ms)
const unsigned long TELEMETRY_INTERVAL_MS = 30000;
unsigned long lastTelemetryTime = 0;

// -----------------------------------------------------------------------------
// 2. PIN DEFINITIONS & I2C DISPLAY SETUP
// -----------------------------------------------------------------------------
// ESP32 ADC1 Pins only (ADC2 is disabled when WiFi is on)
#define PIN_PH_SENSOR          34   // Analog Input (ADC1_CH6)
#define PIN_MOISTURE_SENSOR    35   // Analog Input (ADC1_CH7)
#define PIN_EC_SENSOR          32   // Analog Input (ADC1_CH4)
#define PIN_ONEWIRE_TEMP       4    // Digital I/O with 4.7kΩ pull-up resistor
#define PIN_STATUS_LED         2    // On-board blue LED

// Hardware I2C Pins (ESP32 default: SDA = GPIO 21, SCL = GPIO 22)
// Initialize 16x2 LCD at address 0x27 (use 0x3F if 0x27 doesn't display)
LiquidCrystal_I2C lcd(0x27, 16, 2);

// Setup OneWire & Dallas Temperature
OneWire oneWire(PIN_ONEWIRE_TEMP);
DallasTemperature tempSensor(&oneWire);

// -----------------------------------------------------------------------------
// 3. CALIBRATION CONSTANTS
// -----------------------------------------------------------------------------
// Capacitive Moisture Sensor Calibration (tune with air vs submerged water readings)
const int AIR_VALUE   = 3200;  // Raw ADC value in open dry air (0% moisture)
const int WATER_VALUE = 1350;  // Raw ADC value in clean water (100% moisture)

// pH Sensor Calibration (based on 3.3V ADC and standard pH buffer solutions)
// Formula: pH = PH_SLOPE * voltage + PH_OFFSET
const float PH_SLOPE  = -5.70;
const float PH_OFFSET = 21.34;

// -----------------------------------------------------------------------------
// 4. SENSOR READING FUNCTIONS
// -----------------------------------------------------------------------------

// Read Soil pH with multi-sample noise filtering
float readSoilPH() {
    long adcSum = 0;
    const int SAMPLES = 20;
    for (int i = 0; i < SAMPLES; i++) {
        adcSum += analogRead(PIN_PH_SENSOR);
        delay(10);
    }
    float rawAdc = (float)adcSum / SAMPLES;
    float voltage = (rawAdc / 4095.0) * 3.3; // ESP32 12-bit ADC (0-4095)
    float ph = (PH_SLOPE * voltage) + PH_OFFSET;
    
    // Physical boundary safety clamps
    if (ph < 0.0) ph = 0.0;
    if (ph > 14.0) ph = 14.0;
    return ph;
}

// Read Soil Moisture percentage (% VWC)
float readSoilMoisturePercent() {
    long adcSum = 0;
    const int SAMPLES = 20;
    for (int i = 0; i < SAMPLES; i++) {
        adcSum += analogRead(PIN_MOISTURE_SENSOR);
        delay(10);
    }
    float rawAdc = (float)adcSum / SAMPLES;
    
    // Map raw ADC to 0-100% volumetric moisture
    float moisture = ((float)(AIR_VALUE - rawAdc) / (float)(AIR_VALUE - WATER_VALUE)) * 100.0;
    if (moisture < 0.0) moisture = 0.0;
    if (moisture > 100.0) moisture = 100.0;
    return moisture;
}

// Read Soil Temperature in Celsius using DS18B20 OneWire probe
float readSoilTemperature() {
    tempSensor.requestTemperatures();
    float tempC = tempSensor.getTempCByIndex(0);
    
    // If sensor disconnected or bus fault (-127°C)
    if (tempC == DEVICE_DISCONNECTED_C || tempC < -50.0 || tempC > 85.0) {
        Serial.println(F("[WARN] DS18B20 temperature probe disconnected or faulty."));
        return -999.0; // Flag as missing
    }
    return tempC;
}

// Read Electrical Conductivity (EC in mS/cm)
float readSoilEC(float tempC) {
    long adcSum = 0;
    const int SAMPLES = 20;
    for (int i = 0; i < SAMPLES; i++) {
        adcSum += analogRead(PIN_EC_SENSOR);
        delay(10);
    }
    float rawAdc = (float)adcSum / SAMPLES;
    float voltage = (rawAdc / 4095.0) * 3.3;
    
    // Estimate EC in mS/cm with temperature compensation to 25°C baseline
    float tempCompensation = 1.0 + 0.019 * (tempC > 0 ? tempC - 25.0 : 0.0);
    float ec = (voltage * 1.5) / tempCompensation;
    
    if (ec < 0.0) ec = 0.0;
    if (ec > 20.0) ec = 20.0;
    return ec;
}

// Evaluate overall soil quality status based on pH, moisture, and temperature
const char* evaluateSoilQuality(float ph, float moisture, float tempC) {
    if (ph < 5.5) return "ACIDIC";
    if (ph > 8.0) return "ALKALINE";
    if (moisture < 25.0) return "TOO DRY";
    if (moisture > 85.0) return "WATERLOG";
    if (ph >= 6.0 && ph <= 7.2 && moisture >= 40.0 && moisture <= 70.0) {
        return "OPTIMAL";
    }
    return "GOOD";
}

// Update 16x2 I2C LCD Display
void updateLCDDisplay(float ph, float moisture, float tempC, float ec, const char* quality) {
    lcd.clear();
    
    // Line 1: "pH:6.85 M:48%"
    lcd.setCursor(0, 0);
    lcd.print("pH:");
    lcd.print(ph, 2);
    lcd.print(" M:");
    lcd.print((int)moisture);
    lcd.print("%");

    // Line 2: "T:24C EC:0.8 Q:OPT" or "T:24.5C Q:GOOD"
    lcd.setCursor(0, 1);
    if (tempC > -50.0) {
        lcd.print("T:");
        lcd.print((int)tempC);
        lcd.print("C ");
    } else {
        lcd.print("T:-- ");
    }

    if (ec > 0.05) {
        lcd.print("E:");
        lcd.print(ec, 1);
        lcd.print(" ");
    }

    lcd.print("Q:");
    lcd.print(quality);
}

// -----------------------------------------------------------------------------
// 5. HTTP TELEMETRY TRANSMISSION
// -----------------------------------------------------------------------------
void sendTelemetry(float ph, float moisture, float tempC, float ec) {
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println(F("[ERROR] WiFi not connected. Transmission skipped."));
        return;
    }

    HTTPClient http;
    http.begin(SERVER_URL);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    
    // Set Authorization header
    String authHeader = String("Bearer ") + API_TOKEN;
    http.addHeader("Authorization", authHeader);

    // Build JSON payload (missing readings sent as null)
    String jsonPayload = "{";
    jsonPayload += "\"sample_id\":\"" + String(SAMPLE_ID) + "\",";
    jsonPayload += "\"device_id\":\"" + String(DEVICE_ID) + "\",";
    
    // pH
    jsonPayload += "\"ph\":" + String(ph, 2) + ",";
    
    // Moisture
    jsonPayload += "\"moisture_percent\":" + String(moisture, 1) + ",";
    
    // Temperature (null if probe disconnected)
    if (tempC > -50.0) {
        jsonPayload += "\"temperature_c\":" + String(tempC, 1) + ",";
    } else {
        jsonPayload += "\"temperature_c\":null,";
    }
    
    // EC (set to null if EC sensor not installed or reading is baseline 0)
    if (ec > 0.05) {
        jsonPayload += "\"ec_ms_cm\":" + String(ec, 2);
    } else {
        jsonPayload += "\"ec_ms_cm\":null";
    }
    
    jsonPayload += "}";

    Serial.println(F("=================================================="));
    Serial.print(F("[INFO] Sending POST to: "));
    Serial.println(SERVER_URL);
    Serial.print(F("[INFO] Payload: "));
    Serial.println(jsonPayload);

    // Turn LED ON during transmission
    digitalWrite(PIN_STATUS_LED, HIGH);
    int httpResponseCode = http.POST(jsonPayload);
    digitalWrite(PIN_STATUS_LED, LOW);

    if (httpResponseCode > 0) {
        String response = http.getString();
        Serial.print(F("[SUCCESS] HTTP Response code: "));
        Serial.println(httpResponseCode);
        Serial.print(F("[RESPONSE] "));
        Serial.println(response);
    } else {
        Serial.print(F("[ERROR] HTTP POST failed, error code: "));
        Serial.println(httpResponseCode);
        Serial.println(http.errorToString(httpResponseCode).c_str());
    }

    http.end();
}

// -----------------------------------------------------------------------------
// 6. SETUP & MAIN LOOP
// -----------------------------------------------------------------------------
void setup() {
    Serial.begin(115200);
    delay(1000);
    Serial.println();
    Serial.println(F("=================================================="));
    Serial.println(F("SoilSense — IoT Station Firmware Starting..."));
    Serial.println(F("=================================================="));

    pinMode(PIN_STATUS_LED, OUTPUT);
    digitalWrite(PIN_STATUS_LED, LOW);

    // Initialize I2C LCD Display (SDA = GPIO 21, SCL = GPIO 22)
    Wire.begin(21, 22);
    lcd.init();
    lcd.backlight();
    lcd.setCursor(0, 0);
    lcd.print("SoilSense Station");
    lcd.setCursor(0, 1);
    lcd.print("Connecting WiFi.");

    // Initialize 12-bit ADC and set attenuation (0-3.3V range)
    analogReadResolution(12);
    analogSetAttenuation(ADC_11db);

    // Initialize DS18B20 OneWire temperature bus
    tempSensor.begin();

    // Connect to WiFi
    Serial.print(F("[WIFI] Connecting to "));
    Serial.println(WIFI_SSID);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
        delay(500);
        Serial.print(F("."));
        digitalWrite(PIN_STATUS_LED, !digitalRead(PIN_STATUS_LED));
        attempts++;
    }

    if (WiFi.status() == WL_CONNECTED) {
        digitalWrite(PIN_STATUS_LED, HIGH);
        Serial.println();
        Serial.print(F("[WIFI] Connected! Assigned IP: "));
        Serial.println(WiFi.localIP());

        lcd.setCursor(0, 1);
        lcd.print("WiFi: Connected ");
    } else {
        digitalWrite(PIN_STATUS_LED, LOW);
        Serial.println();
        Serial.println(F("[WIFI] Connection failed. Telemetry will retry periodically."));

        lcd.setCursor(0, 1);
        lcd.print("WiFi: Offline   ");
    }
    delay(1500);
}

void loop() {
    unsigned long currentMillis = millis();

    // Transmit telemetry periodically
    if (currentMillis - lastTelemetryTime >= TELEMETRY_INTERVAL_MS || lastTelemetryTime == 0) {
        lastTelemetryTime = currentMillis;

        Serial.println();
        Serial.println(F("[SENSOR] Acquiring analog and digital probe readings..."));

        float ph = readSoilPH();
        float moisture = readSoilMoisturePercent();
        float tempC = readSoilTemperature();
        float ec = readSoilEC(tempC);
        const char* quality = evaluateSoilQuality(ph, moisture, tempC);

        Serial.print(F(" -> Soil pH: ")); Serial.println(ph, 2);
        Serial.print(F(" -> Moisture: ")); Serial.print(moisture, 1); Serial.println(F(" %"));
        Serial.print(F(" -> Temperature: ")); Serial.print(tempC, 1); Serial.println(F(" °C"));
        Serial.print(F(" -> Electrical Conductivity: ")); Serial.print(ec, 2); Serial.println(F(" mS/cm"));
        Serial.print(F(" -> Soil Quality: ")); Serial.println(quality);

        // Update physical LCD display
        updateLCDDisplay(ph, moisture, tempC, ec, quality);

        // Send telemetry payload to backend
        sendTelemetry(ph, moisture, tempC, ec);
    }

    delay(100);
}
