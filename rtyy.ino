/*
   ESP32-CAM Complete Code for Security System
   Works with your PHP Dashboard
   Camera Model: AI Thinker
*/

#include "esp_camera.h"
#include <WiFi.h>
#include <WebServer.h>
#include <ArduinoJson.h>
#include <HTTPClient.h>
#include <esp_http_server.h>

// ==================== WiFi Configuration ====================
const char* ssid = "sss";           // YOUR WiFi SSID
const char* password = "1234567890"; // YOUR WiFi PASSWORD

// ==================== Dashboard Configuration ====================
const char* dashboard_ip = "192.168.137.1";  // Your PC IP address
const int dashboard_port = 80;
const char* dashboard_path = "/security_system/api/camera_data.php";

// ==================== Pin Definitions for AI Thinker ====================
#define PWDN_GPIO_NUM     32
#define RESET_GPIO_NUM    -1
#define XCLK_GPIO_NUM      0
#define SIOD_GPIO_NUM     26
#define SIOC_GPIO_NUM     27
#define Y9_GPIO_NUM       35
#define Y8_GPIO_NUM       34
#define Y7_GPIO_NUM       39
#define Y6_GPIO_NUM       36
#define Y5_GPIO_NUM       21
#define Y4_GPIO_NUM       19
#define Y3_GPIO_NUM       18
#define Y2_GPIO_NUM        5
#define VSYNC_GPIO_NUM    25
#define HREF_GPIO_NUM     23
#define PCLK_GPIO_NUM     22
#define LED_GPIO_NUM       4

// ==================== Global Variables ====================
WebServer server(81);
bool cameraReady = false;
int currentDistance = 100;
bool alertActive = false;
unsigned long lastHeartbeat = 0;
unsigned long lastPhotoTime = 0;

// ==================== Camera Initialization ====================
bool initCamera() {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sccb_sda = SIOD_GPIO_NUM;
  config.pin_sccb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.frame_size = FRAMESIZE_SVGA;
  config.pixel_format = PIXFORMAT_JPEG;
  config.grab_mode = CAMERA_GRAB_WHEN_EMPTY;
  config.fb_location = CAMERA_FB_IN_PSRAM;
  config.jpeg_quality = 12;
  config.fb_count = 1;

  if (config.pixel_format == PIXFORMAT_JPEG) {
    if (psramFound()) {
      config.jpeg_quality = 10;
      config.fb_count = 2;
      config.grab_mode = CAMERA_GRAB_LATEST;
    } else {
      config.frame_size = FRAMESIZE_SVGA;
      config.fb_location = CAMERA_FB_IN_DRAM;
    }
  }

  esp_err_t err = esp_camera_init(&config);
  if (err != ESP_OK) {
    Serial.printf("Camera init failed with error 0x%x", err);
    return false;
  }

  sensor_t *s = esp_camera_sensor_get();
  s->set_framesize(s, FRAMESIZE_QVGA);
  s->set_quality(s, 10);
  s->set_brightness(s, 0);
  s->set_contrast(s, 0);
  s->set_saturation(s, 0);
  s->set_special_effect(s, 0);
  s->set_whitebal(s, 1);
  s->set_awb_gain(s, 1);
  s->set_wb_mode(s, 0);
  s->set_exposure_ctrl(s, 1);
  s->set_aec2(s, 1);
  s->set_ae_level(s, 0);
  s->set_aec_value(s, 300);
  s->set_gain_ctrl(s, 1);
  s->set_agc_gain(s, 0);
  s->set_gainceiling(s, (gainceiling_t)0);
  s->set_hmirror(s, 0);
  s->set_vflip(s, 0);
  s->set_colorbar(s, 0);

  pinMode(LED_GPIO_NUM, OUTPUT);
  digitalWrite(LED_GPIO_NUM, LOW);
  
  return true;
}

// ==================== WiFi Connection ====================
void connectToWiFi() {
  Serial.print("Connecting to WiFi: ");
  Serial.println(ssid);
  
  WiFi.begin(ssid, password);
  WiFi.setSleep(false);
  
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 30) {
    delay(500);
    Serial.print(".");
    attempts++;
    digitalWrite(LED_GPIO_NUM, !digitalRead(LED_GPIO_NUM));
  }
  
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nWiFi connected!");
    Serial.print("Camera IP Address: ");
    Serial.println(WiFi.localIP());
    Serial.print("Stream URL: http://");
    Serial.print(WiFi.localIP());
    Serial.println(":81/stream");
    Serial.print("Snapshot URL: http://");
    Serial.print(WiFi.localIP());
    Serial.println(":81/capture");
    digitalWrite(LED_GPIO_NUM, HIGH);
  } else {
    Serial.println("\nWiFi connection failed! Starting AP mode...");
    WiFi.softAP("ESP32-CAM-Security", "12345678");
    Serial.print("AP IP address: ");
    Serial.println(WiFi.softAPIP());
    digitalWrite(LED_GPIO_NUM, HIGH);
  }
}

// ==================== Stream Handler using HTTP Server ====================
static esp_err_t stream_handler(httpd_req_t *req) {
  camera_fb_t *fb = NULL;
  esp_err_t res = ESP_OK;
  size_t _jpg_buf_len = 0;
  uint8_t *_jpg_buf = NULL;
  char *part_buf[64];
  
  static const char* _STREAM_CONTENT_TYPE = "multipart/x-mixed-replace;boundary=123456789000000000000987654321";
  static const char* _STREAM_BOUNDARY = "\r\n--123456789000000000000987654321\r\n";
  static const char* _STREAM_PART = "Content-Type: image/jpeg\r\nContent-Length: %u\r\n\r\n";
  
  res = httpd_resp_set_type(req, _STREAM_CONTENT_TYPE);
  if (res != ESP_OK) {
    return res;
  }
  
  while (true) {
    fb = esp_camera_fb_get();
    if (!fb) {
      Serial.println("Camera capture failed");
      res = ESP_FAIL;
    } else {
      if (fb->format != PIXFORMAT_JPEG) {
        bool jpeg_converted = frame2jpg(fb, 80, &_jpg_buf, &_jpg_buf_len);
        esp_camera_fb_return(fb);
        fb = NULL;
        if (!jpeg_converted) {
          Serial.println("JPEG compression failed");
          res = ESP_FAIL;
        }
      } else {
        _jpg_buf_len = fb->len;
        _jpg_buf = fb->buf;
      }
    }
    if (res == ESP_OK) {
      httpd_resp_send_chunk(req, _STREAM_BOUNDARY, strlen(_STREAM_BOUNDARY));
    }
    if (res == ESP_OK) {
      size_t hlen = snprintf((char *)part_buf, 64, _STREAM_PART, _jpg_buf_len);
      res = httpd_resp_send_chunk(req, (const char *)part_buf, hlen);
    }
    if (res == ESP_OK) {
      res = httpd_resp_send_chunk(req, (const char *)_jpg_buf, _jpg_buf_len);
    }
    if (fb) {
      esp_camera_fb_return(fb);
      fb = NULL;
      _jpg_buf = NULL;
    } else if (_jpg_buf) {
      free(_jpg_buf);
      _jpg_buf = NULL;
    }
    if (res != ESP_OK) {
      break;
    }
  }
  
  return res;
}

// ==================== Capture Handler ====================
static esp_err_t capture_handler(httpd_req_t *req) {
  camera_fb_t *fb = NULL;
  esp_err_t res = ESP_OK;
  
  fb = esp_camera_fb_get();
  if (!fb) {
    Serial.println("Camera capture failed");
    httpd_resp_send_500(req);
    return ESP_FAIL;
  }
  
  httpd_resp_set_type(req, "image/jpeg");
  httpd_resp_set_hdr(req, "Content-Disposition", "inline; filename=capture.jpg");
  httpd_resp_set_hdr(req, "Access-Control-Allow-Origin", "*");
  
  if (fb->format == PIXFORMAT_JPEG) {
    res = httpd_resp_send(req, (const char *)fb->buf, fb->len);
  } else {
    uint8_t *buf = NULL;
    size_t buf_len = 0;
    if (frame2jpg(fb, 80, &buf, &buf_len)) {
      res = httpd_resp_send(req, (const char *)buf, buf_len);
      free(buf);
    } else {
      res = ESP_FAIL;
    }
  }
  
  esp_camera_fb_return(fb);
  Serial.println("Photo captured via web");
  return res;
}

// ==================== Status Handler ====================
static esp_err_t status_handler(httpd_req_t *req) {
  char response[256];
  snprintf(response, sizeof(response),
    "{\"camera_ready\":%s,\"distance\":%d,\"alert_active\":%s,\"ip\":\"%s\",\"rssi\":%d}",
    cameraReady ? "true" : "false",
    currentDistance,
    alertActive ? "true" : "false",
    WiFi.localIP().toString().c_str(),
    WiFi.RSSI()
  );
  
  httpd_resp_set_type(req, "application/json");
  httpd_resp_set_hdr(req, "Access-Control-Allow-Origin", "*");
  httpd_resp_send(req, response, strlen(response));
  return ESP_OK;
}

// ==================== Data Handler ====================
static esp_err_t data_handler(httpd_req_t *req) {
  char response[128];
  snprintf(response, sizeof(response),
    "{\"distance\":%d,\"alert\":%s,\"timestamp\":%lu}",
    currentDistance,
    alertActive ? "true" : "false",
    millis()
  );
  
  httpd_resp_set_type(req, "application/json");
  httpd_resp_send(req, response, strlen(response));
  return ESP_OK;
}

// ==================== Root Handler ====================
static esp_err_t root_handler(httpd_req_t *req) {
  const char* html = "<!DOCTYPE html>"
  "<html><head><title>ESP32-CAM Security Camera</title>"
  "<meta name='viewport' content='width=device-width, initial-scale=1'>"
  "<style>body{font-family:Arial;margin:0;padding:20px;background:#1e3c72;color:white;}"
  ".container{max-width:800px;margin:auto;background:white;color:#333;padding:20px;border-radius:15px;}"
  "img{width:100%;border-radius:10px;}"
  "button{padding:10px 20px;margin:5px;border:none;border-radius:5px;cursor:pointer;font-weight:bold;}"
  ".btn-primary{background:#007bff;color:white;}"
  ".btn-danger{background:#dc3545;color:white;}</style>"
  "</head><body><div class='container'>"
  "<h1>ESP32-CAM Security Camera</h1>"
  "<img id='stream' src='/stream' style='width:100%;'>"
  "<div style='margin-top:20px;'>"
  "<button class='btn-primary' onclick='capture()'>Capture Photo</button>"
  "<button class='btn-danger' onclick='location.reload()'>Refresh Stream</button>"
  "</div><div id='status'></div>"
  "<script>"
  "function capture(){fetch('/capture').then(()=>alert('Photo captured!'));}"
  "setInterval(()=>{fetch('/status').then(r=>r.json()).then(d=>{"
  "document.getElementById('status').innerHTML='Distance: '+d.distance+'cm | Alert: '+(d.alert_active?'YES':'NO');});},3000);"
  "</script></div></body></html>";
  
  httpd_resp_set_type(req, "text/html");
  httpd_resp_send(req, html, strlen(html));
  return ESP_OK;
}

// ==================== Start HTTP Server ====================
void startCameraServer() {
  httpd_config_t config = HTTPD_DEFAULT_CONFIG();
  config.server_port = 81;
  config.max_uri_handlers = 10;
  
  httpd_handle_t server = NULL;
  
  if (httpd_start(&server, &config) == ESP_OK) {
    httpd_uri_t root_uri = {
      .uri = "/",
      .method = HTTP_GET,
      .handler = root_handler,
      .user_ctx = NULL
    };
    httpd_register_uri_handler(server, &root_uri);
    
    httpd_uri_t stream_uri = {
      .uri = "/stream",
      .method = HTTP_GET,
      .handler = stream_handler,
      .user_ctx = NULL
    };
    httpd_register_uri_handler(server, &stream_uri);
    
    httpd_uri_t capture_uri = {
      .uri = "/capture",
      .method = HTTP_GET,
      .handler = capture_handler,
      .user_ctx = NULL
    };
    httpd_register_uri_handler(server, &capture_uri);
    
    httpd_uri_t status_uri = {
      .uri = "/status",
      .method = HTTP_GET,
      .handler = status_handler,
      .user_ctx = NULL
    };
    httpd_register_uri_handler(server, &status_uri);
    
    httpd_uri_t data_uri = {
      .uri = "/data",
      .method = HTTP_GET,
      .handler = data_handler,
      .user_ctx = NULL
    };
    httpd_register_uri_handler(server, &data_uri);
    
    Serial.println("HTTP server started on port 81");
  }
}

// ==================== Send Photo to Dashboard ====================
void sendPhotoToDashboard(uint8_t* photo_data, size_t photo_len) {
  HTTPClient http;
  String url = "http://" + String(dashboard_ip) + ":" + String(dashboard_port) + dashboard_path;
  
  http.begin(url);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  
  String postData = "action=save_photo&photo_data=dummy&distance=" + String(currentDistance);
  
  int httpCode = http.POST(postData);
  if (httpCode > 0 && httpCode == 200) {
    Serial.println("Alert sent to dashboard");
  } else {
    Serial.println("Failed to send to dashboard");
  }
  
  http.end();
}

// ==================== Send Heartbeat ====================
void sendHeartbeat() {
  if (millis() - lastHeartbeat > 10000) {
    lastHeartbeat = millis();
    
    HTTPClient http;
    String url = "http://" + String(dashboard_ip) + ":" + String(dashboard_port) + dashboard_path;
    
    http.begin(url);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    
    String postData = "action=heartbeat&camera_ip=" + WiFi.localIP().toString() + "&distance=" + String(currentDistance);
    
    int httpCode = http.POST(postData);
    if (httpCode > 0 && httpCode == 200) {
      Serial.println("Heartbeat sent");
    }
    
    http.end();
  }
}

// ==================== Send Alert ====================
void sendAlertToDashboard() {
  HTTPClient http;
  String url = "http://" + String(dashboard_ip) + ":" + String(dashboard_port) + dashboard_path;
  
  http.begin(url);
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  
  String postData = "action=alert&distance=" + String(currentDistance) + "&camera_ip=" + WiFi.localIP().toString();
  
  int httpCode = http.POST(postData);
  if (httpCode > 0 && httpCode == 200) {
    Serial.println("Alert sent to dashboard");
  }
  
  http.end();
}

// ==================== Check Serial Commands ====================
void checkSerialCommands() {
  if (Serial.available() > 0) {
    String command = Serial.readStringUntil('\n');
    command.trim();
    
    if (command == "CAPTURE") {
      camera_fb_t *fb = esp_camera_fb_get();
      if (fb) {
        Serial.print("PHOTO_CAPTURED:Size=");
        Serial.println(fb->len);
        esp_camera_fb_return(fb);
      }
      Serial.println("CAPTURE_DONE");
    }
    else if (command.startsWith("DISTANCE:")) {
      currentDistance = command.substring(9).toInt();
      if (currentDistance <= 30 && currentDistance > 0 && !alertActive) {
        alertActive = true;
        sendAlertToDashboard();
      } else if (currentDistance > 30 && alertActive) {
        alertActive = false;
      }
    }
    else if (command == "ALERT:TRIGGERED") {
      alertActive = true;
      sendAlertToDashboard();
    }
    else if (command == "ALERT:CLEARED") {
      alertActive = false;
    }
  }
}

// ==================== Setup ====================
void setup() {
  Serial.begin(115200);
  Serial.println();
  Serial.println("========================================");
  Serial.println("ESP32-CAM Security System Starting...");
  Serial.println("========================================");
  
  // Initialize camera
  if (!initCamera()) {
    Serial.println("Camera init failed!");
    while (1) {
      delay(1000);
    }
  }
  cameraReady = true;
  Serial.println("Camera initialized successfully");
  
  // Connect to WiFi
  connectToWiFi();
  
  // Start HTTP server
  startCameraServer();
  
  Serial.println("========================================");
  Serial.print("Camera Stream: http://");
  Serial.print(WiFi.localIP());
  Serial.println(":81/stream");
  Serial.print("Camera Status: http://");
  Serial.print(WiFi.localIP());
  Serial.println(":81/status");
  Serial.println("========================================");
}

// ==================== Loop ====================
void loop() {
  checkSerialCommands();
  sendHeartbeat();
  delay(100);
}