<?php
// api/animal.php - Check if this file exists
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$host = "localhost";
$user = "root";
$pass = "";
$db = "livestock";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$tagId = isset($_GET['tagId']) ? $conn->real_escape_string($_GET['tagId']) : '';

if (!$tagId) {
    echo json_encode(['error' => 'No tagId provided']);
    exit;
}

// Check if tag exists
$result = $conn->query("SELECT * FROM animals WHERE tagId = '$tagId'");

if ($result && $row = $result->fetch_assoc()) {
    $response = [
        'tagId' => $row['tagId'],
        'name' => $row['name'],
        'animalType' => $row['animalType'],
        'sex' => $row['sex'],
        'breed' => $row['breed'],
        'isPregnant' => (bool)$row['isPregnant'],
        'isSick' => (bool)$row['isSick'],
        'ownerContact' => $row['ownerContact']
    ];
    echo json_encode($response);
} else {
    echo json_encode(['error' => 'Tag not registered', 'tagId' => $tagId]);
}
?>