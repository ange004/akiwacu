/*
  Smart Livestock RFID Tracker - COMPLETE WORKING CODE
  Features:
  - RFID tag scanning
  - Green LED for healthy animals
  - Red LED for sick animals
  - OLED display showing animal info
  - Auto-detect unknown tags
  - Buzzer feedback
  - WiFi connection with auto-reconnect
*/

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>

// ==================== WiFi CONFIGURATION ====================
const char* ssid = "ngoga";           // Your WiFi SSID
const char* password = "12345678";     // Your WiFi Password

// ==================== SERVER CONFIGURATION ====================
// Use your computer's IP address (run 'ipconfig' in CMD to find it)
// In your ESP32 code, make sure the URL is correct:
const char* serverUrl = "http://192.168.137.1/Fridaus";

// ==================== PIN DEFINITIONS ====================
// RFID Pins
#define RST_PIN     27    // RFID reset pin
#define SS_PIN      5     // RFID SPI slave select

// LED Pins
#define GREEN_LED   14    // Green LED - lights up for healthy animals
#define RED_LED     13    // Red LED - lights up for sick animals

// Buzzer Pin
#define BUZZER      26    // Buzzer for audio feedback

// Button Pin (optional - for system reset)
#define BUTTON_PIN  25    // Button to reset system (connect to GND)

// SPI Pins for RFID
#define SPI_MOSI    23
#define SPI_MISO    19
#define SPI_SCK     18

// I2C Pins for OLED
#define I2C_SDA     21
#define I2C_SCL     22

// ==================== OLED CONFIGURATION ====================
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
#define OLED_RESET -1
#define SCREEN_ADDRESS 0x3C

// ==================== OBJECTS ====================
MFRC522 mfrc522(SS_PIN, RST_PIN);
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, OLED_RESET);
HTTPClient http;

// ==================== VARIABLES ====================
String currentTagId = "";
unsigned long lastScanTime = 0;
unsigned long lastWiFiCheck = 0;
int scanCount = 0;
bool wifiConnected = false;

// ==================== SETUP FUNCTION ====================
void setup() {
  Serial.begin(115200);
  Serial.println("\n\n========================================");
  Serial.println("Smart Livestock RFID Tracker Starting...");
  Serial.println("========================================\n");
  
  // Initialize I2C for OLED
  Wire.begin(I2C_SDA, I2C_SCL);
  
  // Initialize LED pins
  pinMode(GREEN_LED, OUTPUT);
  pinMode(RED_LED, OUTPUT);
  pinMode(BUZZER, OUTPUT);
  pinMode(BUTTON_PIN, INPUT_PULLUP);
  
  // Turn off all LEDs initially
  digitalWrite(GREEN_LED, LOW);
  digitalWrite(RED_LED, LOW);
  digitalWrite(BUZZER, LOW);
  
  // Initialize OLED Display
  if(!display.begin(SSD1306_SWITCHCAPVCC, SCREEN_ADDRESS)) {
    Serial.println("OLED initialization failed!");
    for(;;);
  }
  
  display.clearDisplay();
  display.setTextSize(1);
  display.setTextColor(SSD1306_WHITE);
  display.setCursor(0, 0);
  display.println("Smart Livestock");
  display.println("RFID Tracker");
  display.println();
  display.println("Initializing...");
  display.display();
  
  // Initialize SPI for RFID
  SPI.begin(SPI_SCK, SPI_MISO, SPI_MOSI);
  mfrc522.PCD_Init();
  Serial.println("RFID reader initialized");
  
  // Connect to WiFi
  connectToWiFi();
  
  delay(2000);
  showReadyScreen();
  Serial.println("System ready! Scan an RFID tag...\n");
}

// ==================== MAIN LOOP ====================
void loop() {
  // Check WiFi connection periodically
  if(millis() - lastWiFiCheck > 10000) {
    lastWiFiCheck = millis();
    if(WiFi.status() != WL_CONNECTED) {
      Serial.println("WiFi lost! Reconnecting...");
      connectToWiFi();
    }
  }
  
  // Check button for system reset
  if(digitalRead(BUTTON_PIN) == LOW) {
    delay(50); // Debounce
    if(digitalRead(BUTTON_PIN) == LOW) {
      Serial.println("Button pressed - Resetting system...");
      resetSystem();
      while(digitalRead(BUTTON_PIN) == LOW);
      delay(100);
    }
  }
  
  // Check for RFID tag
  if (mfrc522.PICC_IsNewCardPresent() && mfrc522.PICC_ReadCardSerial()) {
    
    if (millis() - lastScanTime > 2000) { // 2 seconds debounce
      lastScanTime = millis();
      scanCount++;
      
      currentTagId = getTagId();
      Serial.println("\n========================================");
      Serial.print("Scan #"); Serial.print(scanCount);
      Serial.print(" - Tag ID: "); Serial.println(currentTagId);
      Serial.println("========================================");
      
      // Process the scan
      handleScan();
    }
    
    mfrc522.PICC_HaltA();
    mfrc522.PCD_StopCrypto1();
  }
  
  delay(50);
}

// ==================== WiFi FUNCTIONS ====================
void connectToWiFi() {
  displayMessage("Connecting", "to WiFi...");
  Serial.print("Connecting to WiFi: ");
  Serial.println(ssid);
  
  WiFi.begin(ssid, password);
  
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 25) {
    delay(500);
    Serial.print(".");
    attempts++;
  }
  
  Serial.println();
  
  if (WiFi.status() == WL_CONNECTED) {
    wifiConnected = true;
    Serial.println("WiFi connected successfully!");
    Serial.print("IP Address: ");
    Serial.println(WiFi.localIP());
    displayMessage("WiFi OK!", WiFi.localIP().toString());
    delay(1500);
  } else {
    wifiConnected = false;
    Serial.println("WiFi connection failed!");
    displayMessage("WiFi ERROR", "Offline mode");
    delay(1500);
  }
}

// ==================== RFID FUNCTIONS ====================
String getTagId() {
  String tagId = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) tagId += "0";
    tagId += String(mfrc522.uid.uidByte[i], HEX);
  }
  tagId.toUpperCase();
  return tagId;
}

// ==================== SCAN PROCESSING ====================
void handleScan() {
  // Show scanning message on OLED
  displayMessage("Scanning...", "Tag: " + currentTagId);
  
  // Turn off both LEDs initially
  digitalWrite(GREEN_LED, LOW);
  digitalWrite(RED_LED, LOW);
  
  if (wifiConnected && WiFi.status() == WL_CONNECTED) {
    // Build URL for animal lookup
    
// The ESP32 calls this endpoint:
String url = String(serverUrl) + "/index.php?esp32=animal&tagId=" + currentTagId;
    Serial.println("Request URL: " + url);
    
    http.begin(url);
    http.setTimeout(5000);
    
    int httpCode = http.GET();
    Serial.print("HTTP Response Code: ");
    Serial.println(httpCode);
    
    if (httpCode == 200) {
      String response = http.getString();
      Serial.println("Server Response: " + response);
      
      // Parse JSON response
      DynamicJsonDocument doc(1024);
      DeserializationError error = deserializeJson(doc, response);
      
      if (!error) {
        // Check if animal exists
        bool exists = doc["exists"] | false;
        
        if (exists) {
          // Animal found - extract data
          String name = doc["name"] | "Unknown";
          bool isSick = doc["isSick"] | false;
          bool isPregnant = doc["isPregnant"] | false;
          String animalType = doc["animalType"] | "";
          
          Serial.println("=== Animal Found ===");
          Serial.println("Name: " + name);
          Serial.println("Type: " + animalType);
          Serial.println("Is Sick: " + String(isSick));
          Serial.println("Is Pregnant: " + String(isPregnant));
          Serial.println("===================");
          
          // Control LEDs based on health status
          if (isSick) {
            // ANIMAL IS SICK - Turn ON RED LED, GREEN LED OFF
            digitalWrite(RED_LED, HIGH);
            digitalWrite(GREEN_LED, LOW);
            Serial.println("LED: RED (SICK ANIMAL)");
            errorBeep(); // Long beep for sick animal
          } else {
            // ANIMAL IS HEALTHY - Turn ON GREEN LED, RED LED OFF
            digitalWrite(GREEN_LED, HIGH);
            digitalWrite(RED_LED, LOW);
            Serial.println("LED: GREEN (HEALTHY ANIMAL)");
            successBeep(); // Happy beep for healthy animal
          }
          
          // Display animal information on OLED
          displayAnimalInfo(name, currentTagId, isSick, isPregnant, animalType);
          
        } else {
          // Animal NOT found - needs registration
          String message = doc["message"] | "Tag not registered";
          Serial.println("Animal not found: " + message);
          
          // Turn on RED LED for unknown tag
          digitalWrite(RED_LED, HIGH);
          digitalWrite(GREEN_LED, LOW);
          Serial.println("LED: RED (UNKNOWN TAG)");
          
          // Show unknown tag message
          displayUnknownTag(currentTagId);
          errorBeep();
        }
      } else {
        Serial.println("JSON parsing error: " + String(error.c_str()));
        showError("JSON Error", "Invalid response");
        digitalWrite(RED_LED, HIGH);
        digitalWrite(GREEN_LED, LOW);
        errorBeep();
      }
    } else if (httpCode == 404) {
      Serial.println("API endpoint not found!");
      showError("API Error", "Check server path");
      digitalWrite(RED_LED, HIGH);
      digitalWrite(GREEN_LED, LOW);
      errorBeep();
    } else if (httpCode == -1) {
      Serial.println("Connection failed!");
      showError("Connection Error", "Server unreachable");
      digitalWrite(RED_LED, HIGH);
      digitalWrite(GREEN_LED, LOW);
      errorBeep();
    } else {
      Serial.println("HTTP Error: " + String(httpCode));
      showError("HTTP Error", String(httpCode));
      digitalWrite(RED_LED, HIGH);
      digitalWrite(GREEN_LED, LOW);
      errorBeep();
    }
    
    http.end();
  } else {
    Serial.println("WiFi not connected!");
    showError("WiFi Error", "Not connected");
    digitalWrite(RED_LED, HIGH);
    digitalWrite(GREEN_LED, LOW);
    errorBeep();
  }
  
  // Keep LED on for 3 seconds so user can see
  delay(3000);
  
  // Reset LEDs and show ready screen
  resetOutputs();
}

// ==================== DISPLAY FUNCTIONS ====================
void displayAnimalInfo(String name, String tagId, bool isSick, bool isPregnant, String animalType) {
  display.clearDisplay();
  
  // Animal Name (bigger font)
  display.setTextSize(2);
  display.setCursor(0, 0);
  display.println(name);
  
  // Animal Type (if available)
  display.setTextSize(1);
  display.setCursor(0, 22);
  if(animalType.length() > 0) {
    display.print("Type: ");
    display.println(animalType);
  }
  
  // Health Status with appropriate icon
  display.setCursor(0, 38);
  if (isSick) {
    display.println("STATUS: SICK 🤒");
  } else if (isPregnant) {
    display.println("STATUS: PREGNANT 🤰");
  } else {
    display.println("STATUS: HEALTHY ✅");
  }
  
  // Tag ID (small font)
  display.setTextSize(1);
  display.setCursor(0, 54);
  display.print("ID: ");
  display.println(tagId);
  
  display.display();
  
  // Print to serial for debugging
  Serial.println("=== OLED DISPLAY ===");
  Serial.println("Name: " + name);
  Serial.println("Status: " + String(isSick ? "SICK" : (isPregnant ? "PREGNANT" : "HEALTHY")));
  Serial.println("LED: " + String(isSick ? "RED" : "GREEN"));
  Serial.println("===================");
}

void displayUnknownTag(String tagId) {
  display.clearDisplay();
  display.setTextSize(1);
  display.setCursor(0, 0);
  display.println("⚠️ UNKNOWN TAG!");
  display.println();
  display.print("Tag ID: ");
  display.println(tagId);
  display.println();
  display.println("Please register");
  display.println("this animal in");
  display.println("the dashboard");
  display.display();
  
  Serial.println("Unknown tag displayed on OLED");
}

void showError(String title, String message) {
  display.clearDisplay();
  display.setTextSize(1);
  display.setCursor(0, 0);
  display.println("❌ ERROR");
  display.println(title);
  display.println();
  display.println(message);
  display.display();
  
  Serial.println("ERROR: " + title + " - " + message);
}

void displayMessage(String line1, String line2) {
  display.clearDisplay();
  display.setTextSize(1);
  display.setCursor(0, 0);
  display.println(line1);
  display.println(line2);
  display.display();
  
  Serial.println("OLED: " + line1 + " - " + line2);
}

void showReadyScreen() {
  display.clearDisplay();
  display.setTextSize(1);
  display.setCursor(0, 0);
  display.println("Smart Livestock");
  display.println();
  display.println("Ready to scan");
  
  if (wifiConnected) {
    display.println("WiFi: Connected ✓");
  } else {
    display.println("WiFi: Disconnected ✗");
  }
  
  display.println();
  display.println("Scan RFID tag...");
  display.display();
}

// ==================== LED CONTROL FUNCTIONS ====================
void setHealthyLED() {
  digitalWrite(GREEN_LED, HIGH);
  digitalWrite(RED_LED, LOW);
  Serial.println("LED: GREEN (Healthy Animal)");
}

void setSickLED() {
  digitalWrite(GREEN_LED, LOW);
  digitalWrite(RED_LED, HIGH);
  Serial.println("LED: RED (Sick Animal)");
}

void setUnknownLED() {
  digitalWrite(GREEN_LED, LOW);
  digitalWrite(RED_LED, HIGH);
  Serial.println("LED: RED (Unknown Tag)");
}

void turnOffLEDs() {
  digitalWrite(GREEN_LED, LOW);
  digitalWrite(RED_LED, LOW);
}

// ==================== BUZZER FUNCTIONS ====================
void successBeep() {
  // Happy beep for healthy animal
  tone(BUZZER, 1200, 150);
  delay(150);
  tone(BUZZER, 1500, 150);
}

void errorBeep() {
  // Warning beep for sick animal or unknown tag
  tone(BUZZER, 500, 300);
  delay(300);
  tone(BUZZER, 400, 300);
}

void shortBeep() {
  tone(BUZZER, 1000, 100);
}

// ==================== RESET FUNCTIONS ====================
void resetOutputs() {
  turnOffLEDs();
  showReadyScreen();
}

void resetSystem() {
  // Show reset message
  display.clearDisplay();
  display.setCursor(0, 0);
  display.println("System Reset");
  display.println("Restarting...");
  display.display();
  
  delay(1000);
  
  // Reset all outputs
  turnOffLEDs();
  digitalWrite(BUZZER, LOW);
  
  // Reconnect WiFi
  connectToWiFi();
  
  // Reset scan counter
  scanCount = 0;
  
  // Show ready screen
  showReadyScreen();
  
  Serial.println("System reset complete!");
}

// ==================== TEST FUNCTION (Optional) ====================
void testLEDs() {
  Serial.println("Testing LEDs...");
  
  // Test Green LED
  digitalWrite(GREEN_LED, HIGH);
  digitalWrite(RED_LED, LOW);
  delay(1000);
  
  // Test Red LED
  digitalWrite(GREEN_LED, LOW);
  digitalWrite(RED_LED, HIGH);
  delay(1000);
  
  // Turn off both
  digitalWrite(GREEN_LED, LOW);
  digitalWrite(RED_LED, LOW);
  
  Serial.println("LED test complete");
}