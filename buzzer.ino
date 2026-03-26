#include <Keypad.h>
#define BUZZER_PIN 25

// Keypad setup
const byte ROWS = 4;
const byte COLS = 4;

char keys[ROWS][COLS] = {
  {'1','2','3','A'},
  {'4','5','6','B'},
  {'7','8','9','C'},
  {'*','0','#','D'}
};

// ESP32 pins
byte rowPins[ROWS] = {19, 18, 5, 17};
byte colPins[COLS] = {16, 4, 2, 15};

Keypad keypad = Keypad(makeKeymap(keys), rowPins, colPins, ROWS, COLS);

String inputCode = "";

// Passwords
String codeON = "1234";
String codeOFF = "0000";

void setup() {
  Serial.begin(115200);
  pinMode(BUZZER_PIN, OUTPUT);
}

void loop() {
  char key = keypad.getKey();

  if (key) {
    Serial.print("Key: ");
    Serial.println(key);

    if (key == '#') {
      checkCode();
      inputCode = ""; 
    } 
    else if (key == '*') {
      inputCode = ""; 
      Serial.println("Cleared");
    } 
    else {
      inputCode += key;
    }
  }
}

void checkCode() {
  Serial.print("Entered: ");
  Serial.println(inputCode);

  if (inputCode == codeON) {
    digitalWrite(BUZZER_PIN, HIGH);
    Serial.println("Buzzer ON");
  } 
  else if (inputCode == codeOFF) {
    digitalWrite(BUZZER_PIN, LOW);
    Serial.println("Buzzer OFF");
  } 
  else {
    Serial.println("Wron)
    digitalWrite(BUZZER_PIN, HIGH);
    delay(200);
    digitalWrite(BUZZER_PIN, LOW);
  }
}
