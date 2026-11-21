/*
 * ==============================================================================
 * SoilSense — IoT Soil Assessment & Crop Recommendation System
 * ESP32 Hardware Firmware Sketch
 * ==============================================================================
 * Compatible Boards: ESP32 DevKit V1 (30-pin / 38-pin), NodeMCU-32S, ESP32-WROOM-32
 * 
 * Hardware Sensors Supported:
 * 1. Soil pH Sensor (Analog, e.g. DFRobot Gravity SEN0161 or pH-4502C) -> GPIO 34 (ADC1_CH6)
 * 2. Capacitive Soil Moisture Sensor v1.2 / v2.0 -> GPIO 35 (ADC1_CH7)
 * 3. DS18B20 Waterproof Temperature Sensor (OneWire) -> GPIO 4 (with 4.7k pullup)
 * 4. Electrical Conductivity (EC) Sensor (Analog) -> GPIO 32 (ADC1_CH4)
 * 
 * Communication Protocol:
 * HTTP POST application/json with Bearer Token Authentication to Laravel Backend
 * ==============================================================================
 */

#include <WiFi.h>
#include <HTTPClient.h>
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
// 2. PIN DEFINITIONS (ESP32 ADC1 Pins only, ADC2 is disabled when WiFi is on)
// -----------------------------------------------------------------------------
#define PIN_PH_SENSOR          34   // Analog Input (ADC1_CH6)
#define PIN_MOISTURE_SENSOR    35   // Analog Input (ADC1_CH7)
#define PIN_EC_SENSOR          32   // Analog Input (ADC1_CH4)
#define PIN_ONEWIRE_TEMP       4    // Digital I/O with 4.7kΩ pull-up resistor
#define PIN_STATUS_LED         2    // On-board blue LED

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
// 3B. OPTIONAL NPK SENSOR CONFIGURATION (RS-485 Modbus RTU — Disabled by Default)
// -----------------------------------------------------------------------------
// Set ENABLE_NPK_SENSOR to true only when an RS-485 Soil NPK probe (mg/kg)
// is wired via a MAX485 transceiver. When false (default), the firmware omits
// "n", "p", "k" from the JSON payload for 100% 4-sensor backward compatibility.
#define ENABLE_NPK_SENSOR false

// RS-485 UART2 & MAX485 direction-control pins (only used if ENABLE_NPK_SENSOR is true)
#define PIN_NPK_RS485_RX       16   // ESP32 UART2 RX <- MAX485 RO
#define PIN_NPK_RS485_TX       17   // ESP32 UART2 TX -> MAX485 DI
#define PIN_NPK_RS485_DE_RE    5    // MAX485 DE & RE tied together (HIGH = TX, LOW = RX)

// Standard Soil NPK Modbus RTU settings (verify against your probe datasheet)
const uint8_t  NPK_MODBUS_ADDR = 0x01;
const uint32_t NPK_MODBUS_BAUD = 4800; // Common probes use 4800 or 9600 baud (8N1)
const uint16_t NPK_REG_N       = 0x001E; // Nitrogen (mg/kg)
const uint16_t NPK_REG_P       = 0x001F; // Phosphorus (mg/kg)
const uint16_t NPK_REG_K       = 0x0020; // Potassium (mg/kg)

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

// -----------------------------------------------------------------------------
// 4B. OPTIONAL SOIL NPK SENSOR FUNCTIONS (mg/kg via RS-485 Modbus RTU)
// -----------------------------------------------------------------------------
#if ENABLE_NPK_SENSOR
// Compute standard Modbus RTU CRC-16 (polynomial 0xA001)
uint16_t modbusCRC16(const uint8_t* buf, size_t len) {
    uint16_t crc = 0xFFFF;
    for (size_t pos = 0; pos < len; pos++) {
        crc ^= (uint16_t)buf[pos];
        for (int i = 0; i < 8; i++) {
            if (crc & 0x0001) {
                crc >>= 1;
                crc ^= 0xA001;
            } else {
                crc >>= 1;
            }
        }
    }
    return crc;
}

// Query a single 16-bit holding register (mg/kg); returns -1.0f if unconnected or invalid
float readModbusNPKRegister(uint16_t regAddr) {
    uint8_t req[8];
    req[0] = NPK_MODBUS_ADDR;
    req[1] = 0x03; // Read Holding Registers
    req[2] = (uint8_t)((regAddr >> 8) & 0xFF);
    req[3] = (uint8_t)(regAddr & 0xFF);
    req[4] = 0x00;
    req[5] = 0x01; // Read 1 register
    uint16_t crc = modbusCRC16(req, 6);
    req[6] = (uint8_t)(crc & 0xFF);
    req[7] = (uint8_t)((crc >> 8) & 0xFF);

    while (Serial2.available()) {
        Serial2.read();
    }

    digitalWrite(PIN_NPK_RS485_DE_RE, HIGH);
    delay(2);
    Serial2.write(req, sizeof(req));
    Serial2.flush();
    delay(2);
    digitalWrite(PIN_NPK_RS485_DE_RE, LOW);

    uint8_t resp[7];
    size_t idx = 0;
    unsigned long startMs = millis();
    while (idx < sizeof(resp) && (millis() - startMs) < 250) {
        if (Serial2.available()) {
            resp[idx++] = (uint8_t)Serial2.read();
        }
    }

    if (idx < sizeof(resp) || resp[0] != NPK_MODBUS_ADDR || resp[1] != 0x03 || resp[2] != 0x02) {
        return -1.0f;
    }

    uint16_t expectedCrc = modbusCRC16(resp, 5);
    uint16_t receivedCrc = (uint16_t)resp[5] | ((uint16_t)resp[6] << 8);
    if (expectedCrc != receivedCrc) {
        return -1.0f;
    }

    uint16_t rawMgKg = ((uint16_t)resp[3] << 8) | (uint16_t)resp[4];
    if (rawMgKg > 1999) {
        return -1.0f;
    }
    return (float)rawMgKg;
}
#endif

// Read Soil Nitrogen (N in mg/kg); returns -1.0f when disabled or probe unconnected
float readSoilNitrogen() {
#if ENABLE_NPK_SENSOR
    return readModbusNPKRegister(NPK_REG_N);
#else
    return -1.0f;
#endif
}

// Read Soil Phosphorus (P in mg/kg); returns -1.0f when disabled or probe unconnected
float readSoilPhosphorus() {
#if ENABLE_NPK_SENSOR
    return readModbusNPKRegister(NPK_REG_P);
#else
    return -1.0f;
#endif
}

// Read Soil Potassium (K in mg/kg); returns -1.0f when disabled or probe unconnected
float readSoilPotassium() {
#if ENABLE_NPK_SENSOR
    return readModbusNPKRegister(NPK_REG_K);
#else
    return -1.0f;
#endif
}

// -----------------------------------------------------------------------------
// 5. HTTP TELEMETRY TRANSMISSION
// -----------------------------------------------------------------------------
void sendTelemetry(float ph, float moisture, float tempC, float ec,
                   float nitrogen, float phosphorus, float potassium) {
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

#if ENABLE_NPK_SENSOR
    // Optional NPK telemetry (mg/kg) — omitted completely when ENABLE_NPK_SENSOR is false
    if (nitrogen >= 0.0f) {
        jsonPayload += ",\"n\":" + String(nitrogen, 1);
    } else {
        jsonPayload += ",\"n\":null";
    }
    if (phosphorus >= 0.0f) {
        jsonPayload += ",\"p\":" + String(phosphorus, 1);
    } else {
        jsonPayload += ",\"p\":null";
    }
    if (potassium >= 0.0f) {
        jsonPayload += ",\"k\":" + String(potassium, 1);
    } else {
        jsonPayload += ",\"k\":null";
    }
#else
    (void)nitrogen;
    (void)phosphorus;
    (void)potassium;
#endif
    
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

// Backward-compatible 4-sensor overload
void sendTelemetry(float ph, float moisture, float tempC, float ec) {
    sendTelemetry(ph, moisture, tempC, ec, -1.0f, -1.0f, -1.0f);
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

    // Initialize 12-bit ADC and set attenuation (0-3.3V range)
    analogReadResolution(12);
    analogSetAttenuation(ADC_11db);

    // Initialize DS18B20 OneWire temperature bus
    tempSensor.begin();

#if ENABLE_NPK_SENSOR
    // Initialize RS-485 UART2 for optional NPK Modbus RTU probe
    pinMode(PIN_NPK_RS485_DE_RE, OUTPUT);
    digitalWrite(PIN_NPK_RS485_DE_RE, LOW);
    Serial2.begin(NPK_MODBUS_BAUD, SERIAL_8N1, PIN_NPK_RS485_RX, PIN_NPK_RS485_TX);
    Serial.println(F("[NPK] Optional RS-485 Modbus NPK sensor enabled."));
#endif

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
    } else {
        digitalWrite(PIN_STATUS_LED, LOW);
        Serial.println();
        Serial.println(F("[WIFI] Connection failed. Telemetry will retry periodically."));
    }
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

        Serial.print(F(" -> Soil pH: ")); Serial.println(ph, 2);
        Serial.print(F(" -> Moisture: ")); Serial.print(moisture, 1); Serial.println(F(" %"));
        Serial.print(F(" -> Temperature: ")); Serial.print(tempC, 1); Serial.println(F(" °C"));
        Serial.print(F(" -> Electrical Conductivity: ")); Serial.print(ec, 2); Serial.println(F(" mS/cm"));

#if ENABLE_NPK_SENSOR
        float nitrogen = readSoilNitrogen();
        float phosphorus = readSoilPhosphorus();
        float potassium = readSoilPotassium();

        Serial.print(F(" -> Nitrogen (N): ")); Serial.print(nitrogen, 1); Serial.println(F(" mg/kg"));
        Serial.print(F(" -> Phosphorus (P): ")); Serial.print(phosphorus, 1); Serial.println(F(" mg/kg"));
        Serial.print(F(" -> Potassium (K): ")); Serial.print(potassium, 1); Serial.println(F(" mg/kg"));

        sendTelemetry(ph, moisture, tempC, ec, nitrogen, phosphorus, potassium);
#else
        sendTelemetry(ph, moisture, tempC, ec);
#endif
    }

    delay(100);
}
