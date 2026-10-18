# SoilSense ESP32 Hardware Wiring & Setup Guide

This guide is designed for when your physical hardware arrives. It details the required components, exact GPIO pin connections, calibration steps, and how to upload the firmware.

---

## 1. Hardware Bill of Materials (BOM)

| Component | Recommended Model | Interface | Operating Voltage |
| :--- | :--- | :--- | :--- |
| **Microcontroller** | ESP32 DevKit V1 (30-pin or 38-pin) | Micro-USB / USB-C | 5V via USB (3.3V logic) |
| **Soil pH Sensor** | Analog pH Sensor kit (pH-4502C or DFRobot SEN0161) | Analog (0–3.3V) | 5V / 3.3V |
| **Soil Moisture Sensor** | Capacitive Soil Moisture Sensor v1.2 / v2.0 | Analog (0–3.3V) | 3.3V |
| **Soil Temperature Sensor**| DS18B20 Waterproof Probe | OneWire (Digital) | 3.3V – 5.0V |
| **Soil EC Sensor** | Analog Electrical Conductivity Sensor Probe | Analog (0–3.3V) | 3.3V – 5.0V |
| **Resistor** | 4.7 kΩ (Yellow-Purple-Red) | Pull-up | DS18B20 Data Line |
| **Breadboard & Jumpers**| Standard breadboard with male-to-male & male-to-female wires | — | — |

> [!IMPORTANT]
> **Why Capacitive Soil Moisture?** Avoid cheap resistive soil fork sensors (the ones with exposed copper traces). Resistive sensors corrode within days due to electrolysis. Capacitive sensors are coated and measure dielectric permittivity without direct metal contact.

---

## 2. Wiring Diagram & Pin Connections

### Pin Assignment Table

| Sensor | Sensor Pin | ESP32 Pin | Notes |
| :--- | :--- | :--- | :--- |
| **Capacitive Moisture** | VCC | 3V3 | Power sensor from 3.3V |
| | GND | GND | Common ground |
| | AOUT | **GPIO 35** (ADC1_CH7) | Analog input |
| **Soil pH Sensor** | VCC | 5V (VIN) | Some modules require 5V |
| | GND | GND | Common ground |
| | PO (Analog) | **GPIO 34** (ADC1_CH6) | pH voltage signal |
| **DS18B20 Temperature**| VDD (Red) | 3V3 | Power |
| | GND (Black) | GND | Common ground |
| | DATA (Yellow/White)| **GPIO 4** | OneWire bus (Connect 4.7kΩ between VDD & DATA) |
| **Analog EC Probe** | VCC | 3V3 or 5V | Power |
| | GND | GND | Common ground |
| | AOUT | **GPIO 32** (ADC1_CH4) | EC voltage signal |

> [!NOTE]
> On the ESP32, **ADC1** pins (GPIO 32, 33, 34, 35, 36, 39) must be used for analog sensors. **ADC2** pins cannot be used for analog readings while WiFi is active.

---

## 3. Arduino IDE Setup & Flashing

1. **Install Arduino IDE** (version 2.x recommended).
2. **Add ESP32 Board Support**:
   - Go to `File` > `Preferences`.
   - In *Additional Board Manager URLs*, add:
     `https://raw.githubusercontent.com/espressif/arduino-esp32/gh-pages/package_esp32_index.json`
   - Open `Tools` > `Board` > `Boards Manager`, search for `esp32` by Espressif Systems, and click **Install**.
3. **Install Required Libraries**:
   - In Arduino IDE, go to `Tools` > `Manage Libraries...`
   - Search and install:
     - `OneWire` by Jim Studt, Paul Stoffregen
     - `DallasTemperature` by Miles Burton
4. **Open Firmware**:
   - Open `firmware/SoilSense_ESP32.ino`.
5. **Configure WiFi and Server URL**:
   ```cpp
   const char* WIFI_SSID     = "Your_WiFi_Name";
   const char* WIFI_PASSWORD = "Your_WiFi_Password";
   const char* SERVER_URL    = "http://192.168.1.xxx:8000/api/v1/readings";
   ```
   *(Replace `192.168.1.xxx` with your computer's local IP address, found by running `ipconfig` in PowerShell).*
6. **Upload**:
   - Select your board: `Tools` > `Board` > `esp32` > `ESP32 Dev Module`.
   - Select the COM port: `Tools` > `Port` > `COMx`.
   - Click the **Upload** arrow button.

---

## 4. Calibration Instructions

### 1. Capacitive Moisture Sensor
1. Hold the sensor in dry air, note the raw ADC reading in the Serial Monitor (e.g. `3200`). Update `AIR_VALUE = 3200`.
2. Submerge the sensor blade into a cup of clean water up to the marked line (do not submerge electronics), note the reading (e.g. `1350`). Update `WATER_VALUE = 1350`.

### 2. Soil pH Sensor
1. Dip the probe into pH 6.86 buffer solution.
2. Turn the small blue potentiometer on the module board until the output voltage produces ~6.86 pH.
3. Rinse probe with distilled water and verify in pH 4.01 buffer solution.
