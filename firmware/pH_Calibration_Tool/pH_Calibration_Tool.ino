/*
 * ==============================================================================
 * SoilSense — pH Sensor Calibration & Diagnostic Tool
 * ==============================================================================
 * Upload this sketch to your ESP32 to calibrate your pH-4502C sensor module.
 * 
 * Connections:
 *   pH-4502C VCC -> ESP32 VIN (5V)
 *   pH-4502C GND -> ESP32 GND
 *   pH-4502C PO  -> ESP32 GPIO 34 (ADC1_CH6)
 * 
 * Instructions:
 * 1. Open Tools -> Serial Monitor at 115200 baud.
 * 2. Dip probe into pH 6.86 buffer solution.
 * 3. Turn the blue potentiometer (closest to BNC port) until pH reads ~6.86.
 * 4. Rinse in distilled water, then dip in pH 4.01 buffer to verify slope.
 * ==============================================================================
 */

#define PIN_PH_SENSOR 34

// Default slope and offset
// Formula: pH = (PH_SLOPE * voltage) + PH_OFFSET
float PH_SLOPE  = -5.70;
float PH_OFFSET = 21.34;

void setup() {
  Serial.begin(115200);
  delay(1000);
  
  analogReadResolution(12); // ESP32 12-bit ADC (0 - 4095)
  
  Serial.println(F("\n=================================================="));
  Serial.println(F("     SoilSense pH Sensor Calibration Utility      "));
  Serial.println(F("=================================================="));
  Serial.println(F("Dip probe into pH 6.86 buffer solution."));
  Serial.println(F("Turn blue trimpot near BNC until reading is 6.86.\n"));
}

void loop() {
  // Take 30 samples to filter analog electrical noise
  long sum = 0;
  const int SAMPLES = 30;
  for (int i = 0; i < SAMPLES; i++) {
    sum += analogRead(PIN_PH_SENSOR);
    delay(10);
  }
  
  float rawAdc = (float)sum / SAMPLES;
  float voltage = (rawAdc / 4095.0) * 3.3; // Measured voltage
  float ph = (PH_SLOPE * voltage) + PH_OFFSET;

  // Print live diagnostics
  Serial.print(F("Raw ADC: "));
  Serial.print((int)rawAdc);
  Serial.print(F(" | Voltage: "));
  Serial.print(voltage, 3);
  Serial.print(F(" V | Calculated pH: "));
  Serial.print(ph, 2);

  // Status guide
  if (ph >= 6.75 && ph <= 6.95) {
    Serial.println(F("  <-- [PERFECT FOR pH 6.86]"));
  } else if (ph >= 3.90 && ph <= 4.10) {
    Serial.println(F("  <-- [PERFECT FOR pH 4.01]"));
  } else {
    Serial.println();
  }

  delay(1000);
}
