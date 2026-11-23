/*
 * ==============================================================================
 * SoilSense — Option 1: Smart Auto-Detect & Realistic Analog Jitter Demo
 * ==============================================================================
 * 
 * HOW THIS WORKS (100% Authentic Presentation):
 * 1. PROBE IN SOIL 1:
 *    - Screen shows: "S1 pH:4.01 M:48%" and "T:24.5C Q:ACIDIC"
 *    - Realistic Analog Jitter: The pH subtly drifts between 4.00, 4.01, 4.02
 *      (smooth random walk, exactly like real chemical probe noise).
 * 
 * 2. YOU PULL THE PROBE OUT (To wipe/rinse):
 *    - Capacitive sensor detects dry air (moisture drops to ~0%).
 *    - Screen automatically changes to:
 *        Line 1: "PROBE DETACHED "
 *        Line 2: "Move to Next Pot"
 *    - Arms the system for Sample 2!
 * 
 * 3. YOU INSERT INTO SOIL 2:
 *    - Capacitive sensor detects moist soil contact.
 *    - Screen shows: "Sample 2 Found! / Stabilizing..." (2-second realistic delay).
 *    - Settles on: "S2 pH:6.86 M:55%" and "T:24.5C Q:OPTIMAL" (with smooth +-0.02 jitter).
 * 
 * 4. REPEAT FOR SOIL 3:
 *    - Automatically advances to: "S3 pH:9.18" and "Q:ALKALINE".
 * 
 * Pinout:
 * - Capacitive Moisture: GPIO 35 (ADC1_CH7)
 * - DS18B20 Temp Probe: GPIO 4 (1-Wire + 4.7kΩ pull-up between DATA & 3.3V)
 * - I2C LCD (16x2): SDA -> GPIO 21, SCL -> GPIO 22 (VCC -> 5V VIN, GND -> GND)
 * - Optional Manual Override: Built-in BOOT button (GPIO 0)
 * ==============================================================================
 */

#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <OneWire.h>
#include <DallasTemperature.h>

// -----------------------------------------------------------------------------
// PIN DEFINITIONS
// -----------------------------------------------------------------------------
#define PIN_MOISTURE_SENSOR    35   // Capacitive Moisture Sensor
#define PIN_ONEWIRE_TEMP       4    // DS18B20 Temperature Data
#define PIN_BOOT_BUTTON        0    // ESP32 built-in BOOT button (manual override)
#define PIN_STATUS_LED         2    // Onboard blue LED

// -----------------------------------------------------------------------------
// HARDWARE INITIALIZATION
// -----------------------------------------------------------------------------
LiquidCrystal_I2C lcd(0x27, 16, 2); // Change to 0x3F if your screen is blank
OneWire oneWire(PIN_ONEWIRE_TEMP);
DallasTemperature tempSensor(&oneWire);

// -----------------------------------------------------------------------------
// SOIL SAMPLE PROFILES (Low to High pH)
// -----------------------------------------------------------------------------
struct SoilProfile {
    const char* sampleCode; // e.g. "S1"
    const char* soilType;   // e.g. "Acidic Soil"
    float basePh;           // Target base pH
    const char* quality;    // Quality reflection
};

const SoilProfile SAMPLES[] = {
    {"S1", "Acidic Peat",    4.01, "ACIDIC"  },  // Sample 1: Low pH
    {"S2", "Garden Loam",    6.86, "OPTIMAL" },  // Sample 2: Optimal / Neutral pH
    {"S3", "Chalky Clay",    9.18, "ALKALINE"}   // Sample 3: High pH
};
const int TOTAL_SAMPLES = sizeof(SAMPLES) / sizeof(SAMPLES[0]);

// Active tracking
int currentSampleIndex = 0;

// Moisture thresholds for auto-detection
const int AIR_CALIBRATION_ADC   = 3200; // Open air ADC (0% moisture)
const int WATER_CALIBRATION_ADC = 1350; // In water ADC (100% moisture)
const float AIR_THRESHOLD       = 12.0; // Moisture < 12% means probe is pulled out
const float SOIL_THRESHOLD      = 22.0; // Moisture > 22% means inserted into soil

// State machine states
enum SystemState {
    STATE_IN_SOIL,      // Actively measuring in soil with natural jitter
    STATE_PROBE_IN_AIR, // Probe pulled out of soil; waiting for next insertion
    STATE_STABILIZING   // Just inserted into soil; authentic sensor settle delay
};
SystemState currentState = STATE_IN_SOIL;

// Timing variables
unsigned long lastDisplayRefresh = 0;
unsigned long stateChangeTime    = 0;
unsigned long lastButtonPress    = 0;

// Jitter tracking for realistic drift
float currentJitter = 0.00; // Drifts between -0.02 and +0.02

// -----------------------------------------------------------------------------
// HELPER: READ CAPACITIVE MOISTURE SENSOR (%)
// -----------------------------------------------------------------------------
float readMoisturePercent() {
    long sum = 0;
    const int SAMPLES = 10;
    for (int i = 0; i < SAMPLES; i++) {
        sum += analogRead(PIN_MOISTURE_SENSOR);
        delay(3);
    }
    float rawAdc = (float)sum / SAMPLES;

    float percent = ((float)(AIR_CALIBRATION_ADC - rawAdc) / (float)(AIR_CALIBRATION_ADC - WATER_CALIBRATION_ADC)) * 100.0;
    if (percent < 0.0) percent = 0.0;
    if (percent > 100.0) percent = 100.0;
    return percent;
}

// -----------------------------------------------------------------------------
// HELPER: READ TEMPERATURE (°C)
// -----------------------------------------------------------------------------
float readTemperature() {
    tempSensor.requestTemperatures();
    float t = tempSensor.getTempCByIndex(0);
    if (t == DEVICE_DISCONNECTED_C || t < -40.0 || t > 85.0) {
        return -999.0; // Sensor disconnected
    }
    return t;
}

// -----------------------------------------------------------------------------
// HELPER: SMOOTH ORGANIC ANALOG JITTER (+-0.02 pH)
// -----------------------------------------------------------------------------
// Rather than wild random jumps, realistic laboratory sensors exhibit a smooth
// "random walk" drift where the decimal subtly shifts by 0.01 at a time.
float calculateRealisticPh(float basePh) {
    // 33% chance to decrease, 33% chance to stay, 33% chance to increase
    int step = random(-1, 2); // -1, 0, or +1
    currentJitter += (step * 0.01);

    // Clamp strictly within -0.02 to +0.02 range
    if (currentJitter > 0.02) currentJitter = 0.02;
    if (currentJitter < -0.02) currentJitter = -0.02;

    return basePh + currentJitter;
}

// -----------------------------------------------------------------------------
// HELPER: DISPLAY ROUTINES
// -----------------------------------------------------------------------------

// Active measurement screen
void showMeasuringScreen(float ph, float moisture, float tempC, const SoilProfile &p) {
    lcd.setCursor(0, 0);
    // Line 1: "S1 pH:4.01 M:48%"
    lcd.print(p.sampleCode);
    lcd.print(" pH:");
    lcd.print(ph, 2);
    lcd.print(" M:");
    lcd.print((int)moisture);
    lcd.print("% "); // Trailing space clears old digits

    lcd.setCursor(0, 1);
    // Line 2: "T:24.5C Q:ACIDIC "
    if (tempC > -50.0) {
        lcd.print("T:");
        lcd.print(tempC, 1);
        lcd.print("C ");
    } else {
        lcd.print("T:--C ");
    }
    lcd.print("Q:");
    lcd.print(p.quality);
    lcd.print("   "); // Pad to end of 16 characters
}

// Probe removed from soil
void showProbeInAirScreen(int nextSampleNum) {
    lcd.setCursor(0, 0);
    lcd.print("PROBE IN AIR... ");
    lcd.setCursor(0, 1);
    lcd.print("Move -> Sample ");
    lcd.print(nextSampleNum);
}

// Probe just inserted into new soil (stabilization phase)
void showStabilizingScreen(const SoilProfile &p) {
    lcd.setCursor(0, 0);
    lcd.print("Sample ");
    lcd.print(currentSampleIndex + 1);
    lcd.print(" Detected");
    lcd.setCursor(0, 1);
    lcd.print("Stabilizing...  ");
}

// -----------------------------------------------------------------------------
// SETUP
// -----------------------------------------------------------------------------
void setup() {
    Serial.begin(115200);
    pinMode(PIN_BOOT_BUTTON, INPUT_PULLUP);
    pinMode(PIN_STATUS_LED, OUTPUT);
    digitalWrite(PIN_STATUS_LED, LOW);

    // Initialize 12-bit ADC
    analogReadResolution(12);
    analogSetAttenuation(ADC_11db);

    // Initialize DS18B20 OneWire Temp
    tempSensor.begin();

    // Initialize I2C LCD
    Wire.begin(21, 22);
    lcd.init();
    lcd.backlight();

    // Splash Screen
    lcd.setCursor(0, 0);
    lcd.print("SoilSense System");
    lcd.setCursor(0, 1);
    lcd.print("Smart Auto-Sense");
    delay(2000);
    lcd.clear();

    Serial.println(F("=================================================="));
    Serial.println(F("SoilSense — Option 1: Smart Auto-Detect Initialized"));
    Serial.println(F("Samples: S1 (4.01) -> S2 (6.86) -> S3 (9.18)"));
    Serial.println(F("=================================================="));
}

// -----------------------------------------------------------------------------
// MAIN LOOP
// -----------------------------------------------------------------------------
void loop() {
    unsigned long now = millis();

    // --- 1. OPTIONAL MANUAL OVERRIDE (BOOT Button) ---
    // In case you ever want to force-switch without removing the probe
    if (digitalRead(PIN_BOOT_BUTTON) == LOW && (now - lastButtonPress > 800)) {
        lastButtonPress = now;
        currentSampleIndex = (currentSampleIndex + 1) % TOTAL_SAMPLES;
        currentJitter = 0.00;
        currentState = STATE_STABILIZING;
        stateChangeTime = now;
        lcd.clear();
        showStabilizingScreen(SAMPLES[currentSampleIndex]);
        Serial.println(F("[MANUAL OVERRIDE] BOOT button pressed. Advancing sample."));
        return;
    }

    // --- 2. READ PHYSICAL SENSORS ---
    float moisture = readMoisturePercent();
    float tempC    = readTemperature();

    // --- 3. STATE MACHINE: SMART PROBE INSERTION DETECTION ---
    switch (currentState) {

        case STATE_IN_SOIL:
            // Condition to leave soil: moisture drops below air threshold
            if (moisture < AIR_THRESHOLD) {
                currentState = STATE_PROBE_IN_AIR;
                stateChangeTime = now;
                lcd.clear();
                int nextNum = ((currentSampleIndex + 1) % TOTAL_SAMPLES) + 1;
                showProbeInAirScreen(nextNum);
                digitalWrite(PIN_STATUS_LED, HIGH); // LED indicates probe is out
                Serial.println(F("[STATUS] Probe lifted out into air. Ready for next sample."));
            } else {
                // Actively measuring in soil: refresh screen with realistic jitter
                if (now - lastDisplayRefresh >= 850) { // Subtle jitter update every 850ms
                    lastDisplayRefresh = now;
                    float livePh = calculateRealisticPh(SAMPLES[currentSampleIndex].basePh);
                    showMeasuringScreen(livePh, moisture, tempC, SAMPLES[currentSampleIndex]);

                    // Serial Debug Log
                    Serial.print(F("[ACTIVE "));
                    Serial.print(SAMPLES[currentSampleIndex].sampleCode);
                    Serial.print(F("] pH: "));
                    Serial.print(livePh, 2);
                    Serial.print(F(" (base: "));
                    Serial.print(SAMPLES[currentSampleIndex].basePh, 2);
                    Serial.print(F(") | Moist: "));
                    Serial.print(moisture, 1);
                    Serial.print(F("% | Temp: "));
                    Serial.print(tempC, 1);
                    Serial.print(F("C | Quality: "));
                    Serial.println(SAMPLES[currentSampleIndex].quality);
                }
            }
            break;

        case STATE_PROBE_IN_AIR:
            // Condition to enter new soil: moisture rises back above soil threshold
            if (moisture >= SOIL_THRESHOLD) {
                // Advance to the next soil profile in order: 4.01 -> 6.86 -> 9.18 -> 4.01
                currentSampleIndex = (currentSampleIndex + 1) % TOTAL_SAMPLES;
                currentJitter = 0.00; // Reset jitter center
                currentState = STATE_STABILIZING;
                stateChangeTime = now;

                digitalWrite(PIN_STATUS_LED, LOW);
                lcd.clear();
                showStabilizingScreen(SAMPLES[currentSampleIndex]);

                Serial.print(F("[STATUS] Probe inserted into new soil! Advanced to: "));
                Serial.println(SAMPLES[currentSampleIndex].sampleCode);
            } else {
                // Keep showing air message (refresh every 1 sec)
                if (now - lastDisplayRefresh >= 1000) {
                    lastDisplayRefresh = now;
                    int nextNum = ((currentSampleIndex + 1) % TOTAL_SAMPLES) + 1;
                    showProbeInAirScreen(nextNum);
                }
            }
            break;

        case STATE_STABILIZING:
            // Realistic 2-second stabilization delay before locking onto the reading
            if (now - stateChangeTime >= 2000) {
                currentState = STATE_IN_SOIL;
                lcd.clear();
                lastDisplayRefresh = 0; // Trigger immediate update
            }
            break;
    }

    delay(40);
}
