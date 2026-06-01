#include <WiFi.h>
#include <HTTPClient.h>
#include "esp_camera.h"
#include <ArduinoJson.h>
#define TRIG_PIN 12
#define ECHO_PIN 13
#define BUZZER_PIN 15
#define RED_LED_PIN 14
#define GREEN_LED_PIN 2
#define FLASH_PIN 4
#define GSM_RX 16
#define GSM_TX 17
#define GSM_RST 5
const char* ssid = "YOUR_WIFI_SSID";
const char* password = "YOUR_WIFI_PASSWORD";
const char* serverUrl = "http://your-server-ip/smart-security-system/api.php";
float distanceCm;
const int distanceThreshold = 50; 
bool objectDetected = false;
bool lastDetectionState = false;
unsigned long lastUploadTime = 0;
const unsigned long photoInterval = 5000;
unsigned long lastAlertTime = 0;
const unsigned long alertInterval = 10000; 
const char* phoneNumber = "+1234567890"; 

void setup() {
  Serial.begin(115200);
  Serial2.begin(9600, SERIAL_8N1, GSM_RX, GSM_TX); 
  
  pinMode(TRIG_PIN, OUTPUT);
  pinMode(ECHO_PIN, INPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(RED_LED_PIN, OUTPUT);
  pinMode(GREEN_LED_PIN, OUTPUT);
  pinMode(FLASH_PIN, OUTPUT);
  
  digitalWrite(GREEN_LED_PIN, HIGH);
  digitalWrite(RED_LED_PIN, LOW);
  digitalWrite(BUZZER_PIN, LOW);
  digitalWrite(FLASH_PIN, LOW);
  
  WiFi.begin(ssid, password);
  Serial.print("Connecting to WiFi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nWiFi Connected!");
  Serial.print("IP Address: ");
  Serial.println(WiFi.localIP());
  
  if (!initCamera()) {
    Serial.println("Camera initialization failed!");
    while(true) {
      digitalWrite(RED_LED_PIN, HIGH);
      delay(500);
      digitalWrite(RED_LED_PIN, LOW);
      delay(500);
    }
  }
  
  if (!initGSM()) {
    Serial.println("GSM initialization failed!");
  }
  
  Serial.println("System Ready!");
}

void loop() {
  distanceCm = readUltrasonic();
  
  objectDetected = (distanceCm > 0 && distanceCm < distanceThreshold);
  
  updateLEDs();
  
  sendDistanceData();
  
  if (objectDetected) {
    if (millis() - lastUploadTime > photoInterval) {
      captureAndUploadPhoto();
      lastUploadTime = millis();
    }
    
    if (millis() - lastAlertTime > alertInterval) {
      sendGSMAlert();
      lastAlertTime = millis();
    }
  }
  
  lastDetectionState = objectDetected;
  
  delay(500); // Main loop delay
}

float readUltrasonic() {
  digitalWrite(TRIG_PIN, LOW);
  delayMicroseconds(2);
  digitalWrite(TRIG_PIN, HIGH);
  delayMicroseconds(10);
  digitalWrite(TRIG_PIN, LOW);
  
  long duration = pulseIn(ECHO_PIN, HIGH);
  float distance = duration * 0.034 / 2;
  
  return distance;
}

void updateLEDs() {
  if (objectDetected) {
    digitalWrite(GREEN_LED_PIN, LOW);
    digitalWrite(RED_LED_PIN, HIGH);
    digitalWrite(BUZZER_PIN, HIGH);
  } else {
    digitalWrite(RED_LED_PIN, LOW);
    digitalWrite(GREEN_LED_PIN, HIGH);
    digitalWrite(BUZZER_PIN, LOW);
  }
}

bool initCamera() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = 5;
  config.pin_d1 = 18;
  config.pin_d2 = 19;
  config.pin_d3 = 21;
  config.pin_d4 = 36;
  config.pin_d5 = 39;
  config.pin_d6 = 34;
  config.pin_d7 = 35;
  config.pin_xclk = 0;
  config.pin_pclk = 22;
  config.pin_vsync = 25;
  config.pin_href = 23;
  config.pin_sscb_sda = 26;
  config.pin_sscb_scl = 27;
  config.pin_pwdn = 32;
  config.pin_reset = -1;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;
  config.frame_size = FRAMESIZE_SVGA;
  config.jpeg_quality = 12;
  config.fb_count = 1;
  
  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    return false;
  }
  
  return true;
}

void captureAndUploadPhoto() {
  camera_fb_t *fb = esp_camera_fb_get();
  if (!fb) {
    Serial.println("Camera capture failed");
    return;
  }
  
  digitalWrite(FLASH_PIN, HIGH);
  delay(100);
  
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    http.begin(String(serverUrl) + "?action=upload_photo");
    http.addHeader("Content-Type", "image/jpeg");
    
    int httpResponseCode = http.POST(fb->buf, fb->len);
    
    if (httpResponseCode > 0) {
      Serial.print("Photo uploaded. Response code: ");
      Serial.println(httpResponseCode);
    } else {
      Serial.print("Upload failed: ");
      Serial.println(httpResponseCode);
    }
    http.end();
  }
  
  digitalWrite(FLASH_PIN, LOW);
  esp_camera_fb_return(fb);
}

void sendDistanceData() {
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    
    String url = String(serverUrl) + "?action=add_distance";
    http.begin(url);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    
    String postData = "distance=" + String(distanceCm) + 
                      "&object_detected=" + String(objectDetected ? 1 : 0);
    
    int httpResponseCode = http.POST(postData);
    
    if (httpResponseCode > 0) {
      Serial.println("Distance data sent successfully");
    }
    http.end();
  }
}

bool initGSM() {
  Serial2.println("AT");
  delay(1000);
  
  if (Serial2.available()) {
    String response = Serial2.readString();
    if (response.indexOf("OK") != -1) {
      Serial.println("GSM Module Initialized");
      return true;
    }
  }
  return false;
}

void sendGSMAlert() {
  Serial2.println("AT+CMGF=1");
  delay(1000);
  
  Serial2.print("AT+CMGS=\"");
  Serial2.print(phoneNumber);
  Serial2.println("\"");
  delay(1000);
  
  String message = "ALERT: Intruder detected! Distance: " + String(distanceCm) + "cm";
  Serial2.print(message);
  delay(100);
  
  Serial2.write(26); 
  delay(3000);
  
  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    String url = String(serverUrl) + "?action=add_alert";
    http.begin(url);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    
    String postData = "message=" + message + "&phone=" + phoneNumber;
    http.POST(postData);
    http.end();
  }
}