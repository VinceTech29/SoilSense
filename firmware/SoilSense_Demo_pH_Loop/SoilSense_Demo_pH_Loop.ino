/*
 * ==============================================================================
 * SoilSense — ESP32 Hardware Test Sketch (Demo pH Loop + Real Sensors + LCD)
 * ==============================================================================
 * Features:
 * 1. Looping Mock pH Levels: 4.01 (Acidic) -> 6.86 (Optimal) -> 9.18 (Alkaline)
 * 2. 16x2 I2C LCD Display (SDA = GPIO 21, SCL = GPIO 22)
 * 3. Real DS18B20 Temperature reading (GPIO 4 with 4.7kΩ pull-up)
 * 4. Real Capacitive Moisture reading (GPIO 35)
 * 5. Dynamic Soil Quality status reflection based on active pH & sensor readings
 * ==============================================================================
 */

#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <OneWire.h>
#include <DallasTemperature.h>

// -----------------------------------------------------------------------------
// PIN DEFINITIONS
// -----------------------------------------------------------------------------
#define PIN_MOISTURE_SENSOR    35   // Capacitive Moisture Sensor (ADC1_CH7)
#define PIN_ONEWIRE_TEMP       4    // DS18B20 Data wire (with 4.7kΩ pull-up to 3.3V)
#define PIN_STATUS_LED         2    // On-board blue LED

// -----------------------------------------------------------------------------
// HARDWARE MODULE INITIALIZATION
// -----------------------------------------------------------------------------
// 16x2 LCD at I2C address 0x27 (change to 0x3F if your LCD screen stays blank)
LiquidCrystal_I2C lcd(0x27, 16, 2);

// DS18B20 Temperature Sensor
OneWire oneWire(PIN_ONEWIRE_TEMP);
DallasTemperature tempSensor(&oneWire);

// -----------------------------------------------------------------------------
// SIMULATED pH LOOP CONFIGURATION
// -----------------------------------------------------------------------------
// Looping sequence from low to high: 4.01 -> 6.86 -> 9.18 -> repeat
const float SIMULATED_PH_LEVELS[] = {4.01, 6.86, 9.18};
const int TOTAL_PH_LEVELS = sizeof(SIMULATED_PH_LEVELS) / sizeof(SIMULATED_PH_LEVELS[0]);
int currentPhIndex = 0;

// Update intervals
const unsigned long DISPLAY_INTERVAL_MS = 3500; // Change pH step every 3.5 seconds
unsigned long lastUpdateTime = 0;

// Moisture Sensor Calibration constants
const int AIR_VALUE   = 3200;  // Dry air (0% moisture)
const int WATER_VALUE = 1350;  // Clean water (100% moisture)

// -----------------------------------------------------------------------------
// HELPER: EVALUATE SOIL QUALITY
// -----------------------------------------------------------------------------
const char* evaluateSoilQuality(float ph, float moisture) {
    if (ph < 5.50) {
        return "ACIDIC";     // 4.01 triggers this
    }
    if (ph > 8.00) {
        return "ALKALINE";   // 9.18 triggers this
    }
    if (moisture < 20.0) {
        return "TOO DRY";
    }
    if (moisture > 85.0) {
        return "WATERLOG";
    }
    if (ph >= 6.00 && ph <= 7.20) {
        return "OPTIMAL";    // 6.86 triggers this
    }
    return "GOOD";
}

// -----------------------------------------------------------------------------
// HELPER: READ CAPACITIVE MOISTURE SENSOR
// -----------------------------------------------------------------------------
float readSoilMoisture() {
    long adcSum = 0;
    const int SAMPLES = 10;
    for (int i = 0; i < SAMPLES; i++) {
        adcSum += analogRead(PIN_MOISTURE_SENSOR);
        delay(5);
    }
    float rawAdc = (float)adcSum / SAMPLES;

    // Convert raw ADC to 0-100%
    float moisture = ((float)(AIR_VALUE - rawAdc) / (float)(AIR_VALUE - WATER_VALUE)) * 100.0;
    if (moisture < 0.0) moisture = 0.0;
    if (moisture > 100.0) moisture = 100.0;
    return moisture;
}

// -----------------------------------------------------------------------------
// HELPER: READ DS18B20 TEMPERATURE SENSOR
// -----------------------------------------------------------------------------
float readSoilTemperature() {
    tempSensor.requestTemperatures();
    float tempC = tempSensor.getTempCByIndex(0);

    // Check if probe is disconnected or error (-127°C)
    if (tempC == DEVICE_DISCONNECTED_C || tempC < -40.0 || tempC > 85.0) {
        return -999.0; // Disconnected flag
    }
    return tempC;
}

// -----------------------------------------------------------------------------
// HELPER: REFRESH 16x2 LCD DISPLAY
// -----------------------------------------------------------------------------
void updateLCD(float ph, float moisture, float tempC, const char* quality) {
    lcd.clear();

    // Line 1: "pH:4.01  M:45%"
    lcd.setCursor(0, 0);
    lcd.print("pH:");
    lcd.print(ph, 2);
    lcd.print("  M:");
    lcd.print((int)moisture);
    lcd.print("%");

    // Line 2: "T:24.5C Q:ACIDIC"
    lcd.setCursor(0, 1);
    if (tempC > -50.0) {
        lcd.print("T:");
        lcd.print(tempC, 1);
        lcd.print("C ");
    } else {
        lcd.print("T:--C "); // Probe not detected
    }
    
    lcd.print("Q:");
    lcd.print(quality);
}

// -----------------------------------------------------------------------------
// SETUP
// -----------------------------------------------------------------------------
void setup() {
    Serial.begin(115200);
    delay(500);

    pinMode(PIN_STATUS_LED, OUTPUT);
    digitalWrite(PIN_STATUS_LED, LOW);

    // Initialize ADC (12-bit, 0-3.3V range)
    analogReadResolution(12);
    analogSetAttenuation(ADC_11db);

    // Initialize 1-Wire Temperature
    tempSensor.begin();

    // Initialize I2C LCD on default ESP32 pins (SDA=21, SCL=22)
    Wire.begin(21, 22);
    lcd.init();
    lcd.backlight();

    // Welcome Screen
    lcd.setCursor(0, 0);
    lcd.print("SoilSense System");
    lcd.setCursor(0, 1);
    lcd.print("Testing Mode ON ");
    delay(2000);

    Serial.println();
    Serial.println(F("=================================================="));
    Serial.println(F("SoilSense — pH Simulation & Live Hardware Test"));
    Serial.println(F("pH Sequence: 4.01 -> 6.86 -> 9.18 (repeating)"));
    Serial.println(F("=================================================="));
}

// -----------------------------------------------------------------------------
// MAIN LOOP
// -----------------------------------------------------------------------------
void loop() {
    unsigned long currentMillis = millis();

    if (currentMillis - lastUpdateTime >= DISPLAY_INTERVAL_MS || lastUpdateTime == 0) {
        lastUpdateTime = currentMillis;

        // 1. Get current simulated pH from the array (Low to High)
        float currentPh = SIMULATED_PH_LEVELS[currentPhIndex];

        // 2. Read physical sensors
        float moisture = readSoilMoisture();
        float tempC = readSoilTemperature();

        // 3. Evaluate soil quality dynamically
        const char* quality = evaluateSoilQuality(currentPh, moisture);

        // 4. Update the 16x2 LCD screen
        updateLCD(currentPh, moisture, tempC, quality);

        // 5. Print to Serial Monitor
        Serial.print(F("[STEP "));
        Serial.print(currentPhIndex + 1);
        Serial.print(F("/"));
        Serial.print(TOTAL_PH_LEVELS);
        Serial.print(F("]  pH: "));
        Serial.print(currentPh, 2);
        Serial.print(F(" | Moisture: "));
        Serial.print(moisture, 1);
        Serial.print(F("% | Temp: "));
        if (tempC > -50.0) {
            Serial.print(tempC, 1);
            Serial.print(F(" C"));
        } else {
            Serial.print(F("DISCONNECTED"));
        }
        Serial.print(F(" | Quality: "));
        Serial.println(quality);

        // Blink Status LED to indicate step change
        digitalWrite(PIN_STATUS_LED, HIGH);
        delay(80);
        digitalWrite(PIN_STATUS_LED, LOW);

        // Move to the next pH level in a loop (4.01 -> 6.86 -> 9.18 -> 4.01 ...)
        currentPhIndex = (currentPhIndex + 1) % TOTAL_PH_LEVELS;
    }

    delay(50);
}
