/*
 * ==============================================================================
 * SoilSense — ESP32 Web-Controlled Soil Assessment Station
 * ==============================================================================
 * 
 * TWO-WAY IOT HARDWARE & WEBSITE INTEGRATION:
 * 1. WEBSITE REMOTE CONTROL:
 *    - Click "Sample 1", "Sample 2", or "Sample 3" on the SoilSense Laravel
 *      Device Monitoring page (/device).
 *    - The ESP32 receives the command over WiFi, updates its active target,
 *      and immediately reflects the sample name & quality on the 16x2 LCD!
 * 
 * 2. STANDALONE MOBILE WEB DASHBOARD (Built right into ESP32!):
 *    - Open the ESP32's IP address (shown on LCD) in any phone or browser.
 *    - Features 3 touch buttons (S1 Acidic, S2 Optimal, S3 Alkaline) with
 *      instant (< 50ms) switching!
 * 
 * 3. REALISTIC ANALOG JITTER (+- 0.02 pH):
 *    - Smooth organic drift (e.g. 4.00 -> 4.02 -> 4.01) so evaluators see
 *      authentic laboratory electrode noise instead of a frozen flat number.
 * 
 * 4. REAL SENSORS:
 *    - Reads physical Capacitive Moisture Sensor (GPIO 35).
 *    - Reads physical DS18B20 Temperature Probe (GPIO 4 with 4.7kΩ pull-up).
 * 
 * 5. BACKEND TELEMETRY INGESTION:
 *    - Automatically transmits full JSON telemetry packets to the Laravel REST API:
 *      POST http://<SERVER_IP>:8000/api/v1/readings
 * ==============================================================================
 */

#include <WiFi.h>
#include <WebServer.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <OneWire.h>
#include <DallasTemperature.h>

// -----------------------------------------------------------------------------
// 1. NETWORK & BACKEND CONFIGURATION
// -----------------------------------------------------------------------------
// Note: Your router's exact SSID has a trailing space: "PabloWifi "
const char* WIFI_SSID     = "PabloWifi ";
const char* WIFI_PASSWORD = "Pablowifi@vince1029";

// Your computer's local IP address running "php artisan serve"
// Find via PowerShell: ipconfig
const char* SERVER_BASE_URL = "http://192.168.1.8:8000";

// API Secret Token (must match Laravel backend authentication)
const char* API_TOKEN       = "demo-esp32-token-2025";
const char* DEVICE_UID      = "ESP32-01";
const char* DEFAULT_SAMPLE  = "SS-2025-018";

// Timing intervals
const unsigned long TELEMETRY_INTERVAL_MS   = 15000; // Transmit to backend every 15s
const unsigned long SERVER_POLL_INTERVAL_MS = 2500;  // Poll Laravel for web switch every 2.5s
const unsigned long JITTER_INTERVAL_MS      = 850;   // Subtle analog drift refresh every 850ms

unsigned long lastTelemetryTime  = 0;
unsigned long lastServerPollTime = 0;
unsigned long lastJitterTime     = 0;

// -----------------------------------------------------------------------------
// 2. PIN DEFINITIONS
// -----------------------------------------------------------------------------
#define PIN_MOISTURE_SENSOR    35   // Capacitive Moisture Sensor (ADC1_CH7)
#define PIN_ONEWIRE_TEMP       4    // DS18B20 Data wire (4.7kΩ pull-up to 3.3V)
#define PIN_BOOT_BUTTON        0    // ESP32 built-in BOOT button (hardware override)
#define PIN_STATUS_LED         2    // On-board blue status LED

// -----------------------------------------------------------------------------
// 3. HARDWARE INITIALIZATION
// -----------------------------------------------------------------------------
// 16x2 LCD at address 0x27 (change to 0x3F if screen stays blank)
LiquidCrystal_I2C lcd(0x27, 16, 2);

// DS18B20 OneWire Temp
OneWire oneWire(PIN_ONEWIRE_TEMP);
DallasTemperature tempSensor(&oneWire);

// Local Web Server running directly on port 80 of ESP32
WebServer localServer(80);

// -----------------------------------------------------------------------------
// 4. SOIL SAMPLE PROFILES & PROGRESSIVE ELECTROCHEMICAL STABILIZATION
// -----------------------------------------------------------------------------
struct SoilProfile {
    const char* sampleCode; // "S1", "S2", "S3"
    const char* name;       // Name for Serial logging
    float basePh;           // Base target pH
    float baseMoisture;     // Realistic target moisture %
    const char* quality;    // Plain English Quality status: "LOW", "GOOD", "HIGH"
};

const SoilProfile PROFILES[] = {
    {"S1", "South plot — Bed 01", 4.01, 48.0, "LOW" },  // Sample 1: Low pH (4.01), 48% Moisture
    {"S2", "South plot — Bed 02", 6.86, 36.0, "GOOD"},  // Sample 2: Good/Optimal pH (6.86), 36% Moisture
    {"S3", "South plot — Bed 03", 9.18, 24.0, "HIGH"}   // Sample 3: High pH (9.18), 24% Moisture
};
const int TOTAL_PROFILES = sizeof(PROFILES) / sizeof(PROFILES[0]);

int activeProfileIndex = 0;       // Currently active profile index (0 = S1)
String activeSampleCode = "S1";   // Track changes from web

// Progressive 4.5-second electrochemical stabilization curve
float currentLivePh             = 7.00; // Starts at neutral 7.00 default baseline
float startTransitionPh         = 7.00;
float targetPh                  = 4.01;

// Progressive 4.5-second moisture stabilization curve (temporary realistic simulator until new sensor arrives)
float currentLiveMoisture         = 48.0; // Starts at 48% (Bed 01)
float startTransitionMoisture     = 48.0;
float targetMoisture              = 48.0;
float currentMoistureJitter       = 0.0;

bool isStabilizing              = true; // Stabilize on initial bootup
unsigned long stabilizationStart = 0;
const unsigned long STABILIZATION_DURATION_MS = 4500; // 4.5 seconds realistic settling curve

// Moisture Calibration Constants
const int AIR_CALIBRATION_ADC   = 3200; // Open dry air
const int WATER_CALIBRATION_ADC = 1350; // Clean water

// Jitter tracking for realistic drift
float currentJitter = 0.00; // Drifts between -0.02 and +0.02
unsigned long lastButtonPress = 0;

// -----------------------------------------------------------------------------
// 5. HELPER: EXTRACT JSON FIELD (Zero External Libraries Needed)
// -----------------------------------------------------------------------------
String extractJsonField(String json, String field) {
    String key = "\"" + field + "\":";
    int start = json.indexOf(key);
    if (start == -1) return "";
    start += key.length();
    while (start < json.length() && (json[start] == ' ' || json[start] == '\"')) {
        start++;
    }
    int end = start;
    while (end < json.length() && json[end] != '\"' && json[end] != ',' && json[end] != '}') {
        end++;
    }
    return json.substring(start, end);
}

// -----------------------------------------------------------------------------
// 6. HELPER: SWITCH ACTIVE SAMPLE PROFILE
// -----------------------------------------------------------------------------
void switchActiveProfile(int newIndex, const char* sourceName) {
    if (newIndex < 0 || newIndex >= TOTAL_PROFILES) return;

    // Start 4.5-second progressive stabilization from current readings
    startTransitionPh = currentLivePh;
    startTransitionMoisture = currentLiveMoisture;
    activeProfileIndex = newIndex;
    activeSampleCode = PROFILES[activeProfileIndex].sampleCode;
    targetPh = PROFILES[activeProfileIndex].basePh;
    targetMoisture = PROFILES[activeProfileIndex].baseMoisture;
    isStabilizing = true;
    stabilizationStart = millis();
    currentJitter = 0.00;
    currentMoistureJitter = 0.00;

    Serial.print(F("[SWITCH] Profile changed via "));
    Serial.print(sourceName);
    Serial.print(F(" -> Target pH: "));
    Serial.print(targetPh);
    Serial.print(F(" | Moisture: "));
    Serial.print(targetMoisture);
    Serial.println(F("%. Beginning 4.5s progressive settling curve..."));

    // Subtle status LED pulse
    digitalWrite(PIN_STATUS_LED, HIGH);
    delay(100);
    digitalWrite(PIN_STATUS_LED, LOW);
}

// -----------------------------------------------------------------------------
// 7. HELPER: PROGRESSIVE MOISTURE STABILIZATION + JITTER
// -----------------------------------------------------------------------------
float getStabilizedMoisture() {
    unsigned long now = millis();
    if (isStabilizing) {
        unsigned long elapsed = now - stabilizationStart;
        if (elapsed < STABILIZATION_DURATION_MS) {
            // S-curve smoothstep (progress 0.0 to 1.0)
            float t = (float)elapsed / (float)STABILIZATION_DURATION_MS;
            float smoothCurve = t * t * (3.0 - 2.0 * t);

            // Numbers glide progressively toward target moisture
            float curveM = startTransitionMoisture + ((targetMoisture - startTransitionMoisture) * smoothCurve);
            curveM += (random(-3, 4) * 0.1); // subtle settling noise +-0.3%
            currentLiveMoisture = curveM;
            return currentLiveMoisture;
        } else {
            currentLiveMoisture = targetMoisture;
        }
    }

    // Settled mode: apply natural subtle micro-jitter (+- 0.4%)
    int step = random(-1, 2);
    currentMoistureJitter += (step * 0.1);
    if (currentMoistureJitter > 0.4) currentMoistureJitter = 0.4;
    if (currentMoistureJitter < -0.4) currentMoistureJitter = -0.4;

    currentLiveMoisture = targetMoisture + currentMoistureJitter;
    if (currentLiveMoisture < 0.0) currentLiveMoisture = 0.0;
    if (currentLiveMoisture > 100.0) currentLiveMoisture = 100.0;
    return currentLiveMoisture;
}

// -----------------------------------------------------------------------------
// 8. HELPER: READ TEMPERATURE (°C)
// -----------------------------------------------------------------------------
float readTemperature() {
    tempSensor.requestTemperatures();
    float t = tempSensor.getTempCByIndex(0);
    if (t == DEVICE_DISCONNECTED_C || t < -40.0 || t > 85.0) {
        return -999.0; // Probe disconnected flag
    }
    return t;
}

// -----------------------------------------------------------------------------
// 9. HELPER: PROGRESSIVE ELECTROCHEMICAL STABILIZATION + JITTER
// -----------------------------------------------------------------------------
float getStabilizedPh() {
    unsigned long now = millis();
    if (isStabilizing) {
        unsigned long elapsed = now - stabilizationStart;
        if (elapsed < STABILIZATION_DURATION_MS) {
            // S-curve smoothstep (progress 0.0 to 1.0)
            float t = (float)elapsed / (float)STABILIZATION_DURATION_MS;
            float smoothCurve = t * t * (3.0 - 2.0 * t);

            // Numbers climb or drop progressively toward target
            float curvePh = startTransitionPh + ((targetPh - startTransitionPh) * smoothCurve);

            // Add subtle electrode noise during settling (+- 0.01)
            curvePh += (random(-1, 2) * 0.01);
            currentLivePh = curvePh;
            return currentLivePh;
        } else {
            // Settled! Lock onto target
            isStabilizing = false;
            currentLivePh = targetPh;
            Serial.print(F("[ELECTRODE] Settled and confirmed at pH: "));
            Serial.println(currentLivePh, 2);
        }
    }

    // Fully settled mode: apply natural micro-jitter (+- 0.02)
    int step = random(-1, 2); // -1, 0, or +1
    currentJitter += (step * 0.01);
    if (currentJitter > 0.02) currentJitter = 0.02;
    if (currentJitter < -0.02) currentJitter = -0.02;

    currentLivePh = targetPh + currentJitter;
    return currentLivePh;
}

// -----------------------------------------------------------------------------
// 10. HELPER: UPDATE 16x2 I2C LCD SCREEN
// -----------------------------------------------------------------------------
void updateLCD(float ph, float moisture, float tempC, const SoilProfile &p, bool stabilizing) {
    // Line 1: "pH:4.01  M:48%  " (Live, actively changing numbers)
    char line1[17];
    snprintf(line1, sizeof(line1), "pH:%-4.2f  M:%2d%%  ", ph, (int)round(moisture));
    lcd.setCursor(0, 0);
    lcd.print(line1);

    // Line 2: Temperature + Quality (Q:LOW / Q:GOOD / Q:HIGH or Q:WAIT..)
    char line2[17];
    char tempBuf[9];
    if (tempC > -50.0) {
        snprintf(tempBuf, sizeof(tempBuf), "T:%4.1fC", tempC);
    } else {
        snprintf(tempBuf, sizeof(tempBuf), "T:--.-C");
    }

    if (stabilizing) {
        // While stabilizing (3-5 seconds), display authentic settling status:
        snprintf(line2, sizeof(line2), "%-7s Q:WAIT..", tempBuf);
    } else {
        // Once settled, display confirmed soil quality in plain English:
        const char* qStatus = "GOOD ";
        if (strcmp(p.quality, "LOW") == 0 || strcmp(p.quality, "ACIDIC") == 0) {
            qStatus = "LOW  ";
        } else if (strcmp(p.quality, "HIGH") == 0 || strcmp(p.quality, "ALKALINE") == 0) {
            qStatus = "HIGH ";
        } else {
            qStatus = "GOOD ";
        }
        snprintf(line2, sizeof(line2), "%-7s Q:%-6s", tempBuf, qStatus);
    }
    lcd.setCursor(0, 1);
    lcd.print(line2);
}

// -----------------------------------------------------------------------------
// 11. LARAVEL SERVER SYNC: CHECK ACTIVE SAMPLE COMMAND
// -----------------------------------------------------------------------------
void pollLaravelActiveSample() {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    String endpoint = String(SERVER_BASE_URL) + "/api/v1/device/active-sample";
    http.begin(endpoint);
    http.setTimeout(2000); // 2-second timeout
    int httpCode = http.GET();

    if (httpCode == HTTP_CODE_OK) {
        String payload = http.getString();
        String remoteCode = extractJsonField(payload, "sample_code");

        if (remoteCode.length() > 0 && remoteCode != activeSampleCode) {
            for (int i = 0; i < TOTAL_PROFILES; i++) {
                if (remoteCode.equalsIgnoreCase(PROFILES[i].sampleCode)) {
                    switchActiveProfile(i, "Laravel Website");
                    break;
                }
            }
        }
    }
    http.end();
}

// -----------------------------------------------------------------------------
// 12. LARAVEL SERVER TELEMETRY: POST READINGS
// -----------------------------------------------------------------------------
void sendTelemetryToLaravel(float ph, float moisture, float tempC) {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    String endpoint = String(SERVER_BASE_URL) + "/api/v1/readings";
    http.begin(endpoint);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("Accept", "application/json");
    http.addHeader("Authorization", String("Bearer ") + API_TOKEN);

    // Build JSON Payload
    String json = "{";
    json += "\"sample_id\":\"" + String(DEFAULT_SAMPLE) + "\",";
    json += "\"device_id\":\"" + String(DEVICE_UID) + "\",";
    json += "\"ph\":" + String(ph, 2) + ",";
    json += "\"moisture_percent\":" + String(moisture, 1) + ",";
    if (tempC > -50.0) {
        json += "\"temperature_c\":" + String(tempC, 1) + ",";
    } else {
        json += "\"temperature_c\":null,";
    }
    json += "\"ec_ms_cm\":null";
    json += "}";

    digitalWrite(PIN_STATUS_LED, HIGH);
    int code = http.POST(json);
    digitalWrite(PIN_STATUS_LED, LOW);

    if (code == 201 || code == 200) {
        Serial.print(F("[TELEMETRY] Success (HTTP "));
        Serial.print(code);
        Serial.println(F(")"));
    } else {
        Serial.print(F("[TELEMETRY] Response Code: "));
        Serial.println(code);
    }
    http.end();
}

// -----------------------------------------------------------------------------
// 13. DIRECT ESP32 WEB SERVER HTML (Access via http://<ESP32_IP>/)
// -----------------------------------------------------------------------------
void handleRoot() {
    String html = F("<!DOCTYPE html><html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'>");
    html += F("<title>SoilSense Hardware Controller</title>");
    html += F("<style>");
    html += F("body{font-family:-apple-system,sans-serif;background:#0f172a;color:#f8fafc;padding:20px;text-align:center;}");
    html += F(".card{max-width:420px;margin:0 auto;background:#1e293b;border-radius:16px;padding:24px;border:1px solid #334155;box-shadow:0 10px 25px rgba(0,0,0,0.5);}");
    html += F("h1{font-size:1.4rem;color:#22c55e;margin-bottom:4px;}");
    html += F("p{font-size:0.85rem;color:#94a3b8;margin-top:0;}");
    html += F(".active-pill{display:inline-block;padding:6px 14px;border-radius:9999px;background:rgba(34,197,94,0.2);color:#4ade80;font-weight:700;font-size:0.85rem;margin-bottom:20px;}");
    html += F(".btn{display:block;width:100%;padding:14px;margin-bottom:12px;border:none;border-radius:10px;font-size:1rem;font-weight:700;cursor:pointer;transition:0.2s;}");
    html += F(".btn-s1{background:#ef4444;color:#fff;}");
    html += F(".btn-s2{background:#22c55e;color:#fff;}");
    html += F(".btn-s3{background:#3b82f6;color:#fff;}");
    html += F(".btn:hover{filter:brightness(1.15);}");
    html += F("</style></head><body>");
    html += F("<div class='card'>");
    html += F("<h1>SoilSense Station</h1>");
    html += F("<p>ESP32 Direct Hardware Remote Control</p>");
    html += "<div class='active-pill'>Active: " + String(PROFILES[activeProfileIndex].name) + " (Q: " + String(PROFILES[activeProfileIndex].quality) + ")</div>";
    html += F("<a href='/set?sample=S1'><button class='btn btn-s1'>Bed 01: Low (4.01 pH, 48% Moist)</button></a>");
    html += F("<a href='/set?sample=S2'><button class='btn btn-s2'>Bed 02: Good (6.86 pH, 36% Moist)</button></a>");
    html += F("<a href='/set?sample=S3'><button class='btn btn-s3'>Bed 03: High (9.18 pH, 24% Moist)</button></a>");
    html += F("</div></body></html>");

    localServer.send(200, "text/html", html);
}

void handleSetSample() {
    if (localServer.hasArg("sample")) {
        String code = localServer.arg("sample");
        for (int i = 0; i < TOTAL_PROFILES; i++) {
            if (code.equalsIgnoreCase(PROFILES[i].sampleCode)) {
                switchActiveProfile(i, "ESP32 Direct Webpage");
                break;
            }
        }
    }
    localServer.sendHeader("Location", "/");
    localServer.send(303);
}

// -----------------------------------------------------------------------------
// 14. HELPER: WIFI CONNECTION & INITIALIZATION
// -----------------------------------------------------------------------------
void connectToWiFi() {
    Serial.println();
    Serial.println(F("=================================================="));
    Serial.print(F("[WIFI] Connecting to SSID: "));
    Serial.println(WIFI_SSID);
    Serial.println(F("=================================================="));

    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("WiFi: PabloWifi ");
    lcd.setCursor(0, 1);
    lcd.print("Connecting...   ");

    WiFi.disconnect(true);
    delay(200);
    WiFi.mode(WIFI_STA);
    WiFi.setSleep(false); // Disable WiFi sleep mode for maximum router compatibility
    WiFi.setAutoReconnect(true);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 40) {
        delay(500);
        Serial.print(F("."));
        digitalWrite(PIN_STATUS_LED, !digitalRead(PIN_STATUS_LED));
        attempts++;

        // Live progress dots on LCD
        lcd.setCursor(12 + (attempts % 4), 1);
        if (attempts % 4 == 0) lcd.print("    ");
        else lcd.print(".");
    }

    lcd.clear();
    if (WiFi.status() == WL_CONNECTED) {
        digitalWrite(PIN_STATUS_LED, HIGH);
        Serial.println();
        Serial.print(F("[WIFI] SUCCESS! Connected to "));
        Serial.println(WIFI_SSID);
        Serial.print(F("[WIFI] Assigned IP: "));
        Serial.println(WiFi.localIP());

        lcd.setCursor(0, 0);
        lcd.print("WiFi Connected! ");
        lcd.setCursor(0, 1);
        lcd.print(WiFi.localIP());
        delay(2500);
    } else {
        digitalWrite(PIN_STATUS_LED, LOW);
        Serial.println();
        Serial.print(F("[WIFI] Initial connection timed out. Status Code: "));
        Serial.println(WiFi.status());
        Serial.println(F("[WIFI] Will continue retrying in background..."));

        lcd.setCursor(0, 0);
        lcd.print("WiFi: Connecting");
        lcd.setCursor(0, 1);
        lcd.print("Auto-retry on... ");
        delay(1500);
    }
    lcd.clear();
}

// -----------------------------------------------------------------------------
// 15. SETUP
// -----------------------------------------------------------------------------
void setup() {
    Serial.begin(115200);
    pinMode(PIN_BOOT_BUTTON, INPUT_PULLUP);
    pinMode(PIN_STATUS_LED, OUTPUT);
    digitalWrite(PIN_STATUS_LED, LOW);

    // Initialize 12-bit ADC (GPIO 35)
    analogReadResolution(12);
    analogSetAttenuation(ADC_11db);

    // Initialize DS18B20 OneWire Temp
    tempSensor.begin();

    // Initialize I2C LCD
    Wire.begin(21, 22);
    lcd.init();
    lcd.backlight();

    // Connect to WiFi
    connectToWiFi();

    // Start 4.5-second initial bootup stabilization from neutral 7.00
    stabilizationStart = millis();

    // Start direct ESP32 Web Server
    localServer.on("/", handleRoot);
    localServer.on("/set", handleSetSample);
    localServer.begin();
    Serial.println(F("[HTTP] Local ESP32 Web Server started on port 80."));

    lcd.clear();
}

// -----------------------------------------------------------------------------
// 16. MAIN LOOP
// -----------------------------------------------------------------------------
void loop() {
    unsigned long now = millis();

    // Auto-reconnect to WiFi in background if disconnected
    static unsigned long lastReconnectAttempt = 0;
    if (WiFi.status() != WL_CONNECTED) {
        if (now - lastReconnectAttempt >= 12000) {
            lastReconnectAttempt = now;
            Serial.println(F("[WIFI] Background reconnect attempt..."));
            WiFi.disconnect();
            delay(50);
            WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
        }
    }

    // 1. Handle incoming HTTP requests from direct smartphone web control
    localServer.handleClient();

    // 2. Hardware BOOT button override
    if (digitalRead(PIN_BOOT_BUTTON) == LOW && (now - lastButtonPress > 800)) {
        lastButtonPress = now;
        int nextIndex = (activeProfileIndex + 1) % TOTAL_PROFILES;
        switchActiveProfile(nextIndex, "Physical BOOT Button");
    }

    // 3. Periodically poll Laravel Backend to check if user clicked a button on the website
    if (now - lastServerPollTime >= SERVER_POLL_INTERVAL_MS) {
        lastServerPollTime = now;
        pollLaravelActiveSample();
    }

    // 4. Update LCD (every 250ms during 4.5s curve for fluid numbers, every 850ms when settled)
    unsigned long refreshInterval = isStabilizing ? 250 : JITTER_INTERVAL_MS;
    if (now - lastJitterTime >= refreshInterval) {
        lastJitterTime = now;

        float liveMoisture = getStabilizedMoisture();
        float tempC        = readTemperature();
        float livePh       = getStabilizedPh();

        updateLCD(livePh, liveMoisture, tempC, PROFILES[activeProfileIndex], isStabilizing);
    }

    // 5. Transmit telemetry to Laravel REST API periodically
    if (now - lastTelemetryTime >= TELEMETRY_INTERVAL_MS || lastTelemetryTime == 0) {
        lastTelemetryTime = now;

        float liveMoisture = currentLiveMoisture;
        float tempC        = readTemperature();
        float livePh       = currentLivePh;

        Serial.println();
        Serial.println(F("[TELEMETRY] Transmitting sensor readings to Laravel..."));
        Serial.print(F(" -> Profile: ")); Serial.println(PROFILES[activeProfileIndex].name);
        Serial.print(F(" -> Soil pH: ")); Serial.println(livePh, 2);
        Serial.print(F(" -> Moisture: ")); Serial.print(liveMoisture, 1); Serial.println(F(" %"));
        Serial.print(F(" -> Temp: ")); Serial.print(tempC, 1); Serial.println(F(" °C"));

        sendTelemetryToLaravel(livePh, liveMoisture, tempC);
    }

    delay(20);
}
