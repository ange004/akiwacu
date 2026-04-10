<?php
// ==================== DATABASE CONNECTION ====================
$host = "localhost";
$user = "root";
$pass = "";
$db = "livestock";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ==================== CREATE TABLES ====================
$conn->query("CREATE TABLE IF NOT EXISTS animals (
    tagId VARCHAR(20) PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    animalType VARCHAR(30),
    sex VARCHAR(10),
    breed VARCHAR(50),
    birthdate DATE,
    isPregnant TINYINT(1) DEFAULT 0,
    isSick TINYINT(1) DEFAULT 0,
    ownerContact VARCHAR(20),
    ownerEmail VARCHAR(100),
    registrationDate TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS scan_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tagId VARCHAR(20),
    scanTime DATETIME DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20)
)");

$conn->query("CREATE TABLE IF NOT EXISTS pending_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tagId VARCHAR(20),
    scanTime DATETIME DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) DEFAULT 'pending'
)");

$conn->query("CREATE TABLE IF NOT EXISTS HealthRecords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tagId VARCHAR(20),
    recordType VARCHAR(50),
    recordDate DATE,
    description TEXT,
    vetName VARCHAR(100),
    vetContact VARCHAR(20),
    nextVisitDate DATE,
    medicine VARCHAR(100),
    cost DECIMAL(10,2),
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Insert sample animals if none exist
$check = $conn->query("SELECT COUNT(*) as c FROM animals");
$row = $check->fetch_assoc();
if($row['c'] == 0) {
    $conn->query("INSERT INTO animals (tagId, name, animalType, sex, breed, isPregnant, isSick, ownerContact) VALUES 
        ('F31B621A', 'MUKECURU', 'Cow', 'Female', 'Friesian', 0, 0, '0788123456'),
        ('735677FA', 'GASORE', 'Cow', 'Female', 'Ankole', 0, 1, '0788234567'),
        ('639102F8', 'NDAGIJIMANA', 'Goat', 'Male', 'Boer', 0, 0, '0788345678')");
}

// ==================== ESP32 API ENDPOINT ====================
if(isset($_GET['esp32']) && $_GET['esp32'] == 'animal') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    
    $tagId = isset($_GET['tagId']) ? trim($_GET['tagId']) : '';
    
    if(empty($tagId)) {
        echo json_encode(['error' => 'No tagId provided']);
        exit;
    }
    
    $tagId = $conn->real_escape_string($tagId);
    $result = $conn->query("SELECT * FROM animals WHERE tagId = '$tagId'");
    
    if($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $status = ($row['isSick'] == 1) ? 'SICK' : (($row['isPregnant'] == 1) ? 'PREGNANT' : 'HEALTHY');
        
        $conn->query("INSERT INTO scan_logs (tagId, scanTime, status) VALUES ('$tagId', NOW(), '$status')");
        
        echo json_encode([
            'exists' => true,
            'tagId' => $row['tagId'],
            'name' => $row['name'],
            'animalType' => $row['animalType'],
            'sex' => $row['sex'],
            'breed' => $row['breed'],
            'isPregnant' => (bool)$row['isPregnant'],
            'isSick' => (bool)$row['isSick'],
            'ownerContact' => $row['ownerContact']
        ]);
    } else {
        $conn->query("INSERT INTO pending_registrations (tagId, scanTime, status) VALUES ('$tagId', NOW(), 'pending')");
        $conn->query("INSERT INTO scan_logs (tagId, scanTime, status) VALUES ('$tagId', NOW(), 'PENDING')");
        
        echo json_encode([
            'exists' => false,
            'tagId' => $tagId,
            'message' => 'Tag not registered',
            'needsRegistration' => true
        ]);
    }
    exit;
}

// ==================== REGISTER ANIMAL ====================
if($_SERVER['REQUEST_METHOD'] == "POST" && isset($_GET['action']) && $_GET['action'] == "register") {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents("php://input"), true);
    
    $tagId = $conn->real_escape_string(trim($input['tagId']));
    $name = $conn->real_escape_string($input['name']);
    $animalType = $conn->real_escape_string($input['animalType']);
    $sex = $conn->real_escape_string($input['sex']);
    $breed = $conn->real_escape_string($input['breed']);
    $birthdate = !empty($input['birthdate']) ? $conn->real_escape_string($input['birthdate']) : null;
    $isPregnant = $input['isPregnant'] ? 1 : 0;
    $isSick = $input['isSick'] ? 1 : 0;
    $ownerContact = $conn->real_escape_string($input['ownerContact']);
    $ownerEmail = $conn->real_escape_string($input['ownerEmail']);
    
    if(empty($tagId) || empty($name)) {
        echo json_encode(["status" => "error", "message" => "Tag ID and Name required"]);
        exit;
    }
    
    $check = $conn->query("SELECT tagId FROM animals WHERE tagId = '$tagId'");
    if($check && $check->num_rows > 0) {
        echo json_encode(["status" => "error", "message" => "Tag ID already exists!"]);
        exit;
    }
    
    $sql = "INSERT INTO animals (tagId, name, animalType, sex, breed, birthdate, isPregnant, isSick, ownerContact, ownerEmail) 
            VALUES ('$tagId', '$name', '$animalType', '$sex', '$breed', " . ($birthdate ? "'$birthdate'" : "NULL") . ", $isPregnant, $isSick, '$ownerContact', '$ownerEmail')";
    
    if($conn->query($sql)) {
        $conn->query("DELETE FROM pending_registrations WHERE tagId='$tagId'");
        echo json_encode(["status" => "success", "message" => "Animal registered successfully!"]);
    } else {
        echo json_encode(["status" => "error", "message" => $conn->error]);
    }
    exit;
}

// ==================== UPDATE HEALTH STATUS ====================
if(isset($_GET['action']) && $_GET['action'] == 'updateHealth') {
    header('Content-Type: application/json');
    $tagId = $conn->real_escape_string($_GET['tagId']);
    $type = $conn->real_escape_string($_GET['type']);
    
    if($type == 'sick') {
        $conn->query("UPDATE animals SET isSick = 1, isPregnant = 0 WHERE tagId='$tagId'");
    } elseif($type == 'pregnant') {
        $conn->query("UPDATE animals SET isPregnant = 1, isSick = 0 WHERE tagId='$tagId'");
    } elseif($type == 'healthy') {
        $conn->query("UPDATE animals SET isSick = 0, isPregnant = 0 WHERE tagId='$tagId'");
    }
    
    echo json_encode(["status" => "success"]);
    exit;
}

// ==================== ADD HEALTH RECORD ====================
if($_SERVER['REQUEST_METHOD'] == "POST" && isset($_GET['action']) && $_GET['action'] == "addHealth") {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents("php://input"), true);
    
    $tagId = $conn->real_escape_string($input['tagId']);
    $recordType = $conn->real_escape_string($input['recordType']);
    $recordDate = !empty($input['recordDate']) ? $conn->real_escape_string($input['recordDate']) : date('Y-m-d');
    $description = $conn->real_escape_string($input['description']);
    $vetName = $conn->real_escape_string($input['vetName']);
    $vetContact = $conn->real_escape_string($input['vetContact']);
    $nextVisitDate = !empty($input['nextVisitDate']) ? $conn->real_escape_string($input['nextVisitDate']) : null;
    $medicine = $conn->real_escape_string($input['medicine']);
    $cost = !empty($input['cost']) ? floatval($input['cost']) : 0;
    
    if(empty($tagId) || empty($recordType)) {
        echo json_encode(["status" => "error", "message" => "Tag ID and Record Type required"]);
        exit;
    }
    
    $sql = "INSERT INTO HealthRecords (tagId, recordType, recordDate, description, vetName, vetContact, nextVisitDate, medicine, cost) 
            VALUES ('$tagId', '$recordType', '$recordDate', '$description', '$vetName', '$vetContact', " . ($nextVisitDate ? "'$nextVisitDate'" : "NULL") . ", '$medicine', $cost)";
    
    if($conn->query($sql)) {
        echo json_encode(["status" => "success", "message" => "Health record added"]);
    } else {
        echo json_encode(["status" => "error", "message" => $conn->error]);
    }
    exit;
}

// ==================== DELETE HEALTH RECORD ====================
if(isset($_GET['action']) && $_GET['action'] == 'deleteHealth') {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $conn->query("DELETE FROM HealthRecords WHERE id=$id");
    echo json_encode(["status" => "success"]);
    exit;
}

// ==================== DELETE ANIMAL ====================
if(isset($_GET['action']) && $_GET['action'] == 'delete') {
    header('Content-Type: application/json');
    $tagId = $conn->real_escape_string($_GET['tagId']);
    $conn->query("DELETE FROM HealthRecords WHERE tagId='$tagId'");
    $conn->query("DELETE FROM scan_logs WHERE tagId='$tagId'");
    $conn->query("DELETE FROM pending_registrations WHERE tagId='$tagId'");
    $conn->query("DELETE FROM animals WHERE tagId='$tagId'");
    echo json_encode(["status" => "success"]);
    exit;
}

// ==================== GET ALL DATA ====================
if(isset($_GET['action']) && $_GET['action'] == 'getAll') {
    header('Content-Type: application/json');
    
    $total = $conn->query("SELECT COUNT(*) as c FROM animals")->fetch_assoc()['c'];
    $sick = $conn->query("SELECT COUNT(*) as c FROM animals WHERE isSick = 1")->fetch_assoc()['c'];
    $pregnant = $conn->query("SELECT COUNT(*) as c FROM animals WHERE isPregnant = 1")->fetch_assoc()['c'];
    $healthy = $total - $sick;
    $pending = $conn->query("SELECT COUNT(*) as c FROM pending_registrations")->fetch_assoc()['c'];
    $scansToday = $conn->query("SELECT COUNT(*) as c FROM scan_logs WHERE DATE(scanTime) = CURDATE()")->fetch_assoc()['c'];
    
    $recentScans = [];
    $result = $conn->query("SELECT s.*, a.name FROM scan_logs s LEFT JOIN animals a ON s.tagId = a.tagId ORDER BY s.scanTime DESC LIMIT 10");
    while($row = $result->fetch_assoc()) $recentScans[] = $row;
    
    $pendingList = [];
    $result = $conn->query("SELECT * FROM pending_registrations ORDER BY scanTime DESC");
    while($row = $result->fetch_assoc()) $pendingList[] = $row;
    
    $alerts = [];
    $result = $conn->query("SELECT tagId, name FROM animals WHERE isSick = 1");
    while($row = $result->fetch_assoc()) $alerts[] = $row;
    
    $animals = [];
    $result = $conn->query("SELECT * FROM animals ORDER BY name");
    while($row = $result->fetch_assoc()) $animals[] = $row;
    
    $healthRecords = [];
    $result = $conn->query("
        SELECT h.*, a.name as animal_name 
        FROM HealthRecords h 
        LEFT JOIN animals a ON h.tagId = a.tagId 
        ORDER BY h.recordDate DESC 
        LIMIT 50
    ");
    while($row = $result->fetch_assoc()) $healthRecords[] = $row;
    
    echo json_encode([
        'stats' => compact('total', 'sick', 'pregnant', 'healthy', 'pending', 'scansToday'),
        'recentScans' => $recentScans,
        'pendingRegistrations' => $pendingList,
        'alerts' => $alerts,
        'animals' => $animals,
        'healthRecords' => $healthRecords
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Livestock RFID Tracker</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; }
        .navbar { background: #1a5f3a; color: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .navbar a { color: white; text-decoration: none; padding: 0.5rem 1rem; border-radius: 5px; cursor: pointer; }
        .navbar a:hover { background: #0d3b22; }
        .container { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: white; border-radius: 12px; padding: 1rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .stat-number { font-size: 2rem; font-weight: bold; }
        .stat-card.blue .stat-number { color: #3b82f6; }
        .stat-card.red .stat-number { color: #ef4444; }
        .stat-card.green .stat-number { color: #10b981; }
        .stat-card.purple .stat-number { color: #8b5cf6; }
        .stat-card.orange .stat-number { color: #f59e0b; }
        .stat-card.yellow .stat-number { color: #eab308; }
        .card { background: white; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .card-title { font-size: 1.2rem; font-weight: bold; margin-bottom: 1rem; border-bottom: 2px solid #1a5f3a; padding-bottom: 0.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        input, select, textarea { width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 6px; }
        button { background: #1a5f3a; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; }
        button.danger { background: #dc2626; }
        button.warning { background: #f59e0b; }
        button.primary { background: #3b82f6; }
        button.success { background: #10b981; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; }
        .badge { padding: 0.2rem 0.5rem; border-radius: 20px; font-size: 0.7rem; font-weight: bold; display: inline-block; }
        .badge-sick { background: #fee2e2; color: #dc2626; }
        .badge-pregnant { background: #f3e8ff; color: #9333ea; }
        .badge-healthy { background: #dcfce7; color: #16a34a; }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .scan-item { padding: 0.5rem; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; transition: background 0.3s; }
        .scan-item:hover { background: #f8f9fa; }
        .pending-item { background: #fef3c7; padding: 0.75rem; margin-bottom: 0.5rem; border-radius: 8px; border-left: 4px solid #f59e0b; display: flex; justify-content: space-between; align-items: center; }
        .alert-item { background: #fee2e2; padding: 0.75rem; margin-bottom: 0.5rem; border-radius: 8px; border-left: 4px solid #dc2626; }
        .health-item { background: #f8f9fa; padding: 0.75rem; margin-bottom: 0.5rem; border-radius: 8px; border-left: 4px solid #1a5f3a; }
        .refresh-btn { background: #3b82f6; padding: 0.2rem 0.5rem; font-size: 0.8rem; border-radius: 5px; }
        .btn-sm { padding: 0.2rem 0.5rem; font-size: 0.7rem; margin: 0.1rem; border-radius: 4px; cursor: pointer; }
        .auto-refresh { position: fixed; bottom: 20px; right: 20px; background: #1a5f3a; color: white; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.7rem; z-index: 1000; }
        .test-scan { background: #e0e7ff; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
        .delete-health { color: #dc2626; cursor: pointer; float: right; }
        .loading { opacity: 0.6; pointer-events: none; }
        .scan-countdown { font-size: 0.7rem; color: #666; margin-left: 0.5rem; }
        .live-badge { background: #dc2626; color: white; padding: 0.2rem 0.5rem; border-radius: 20px; font-size: 0.6rem; margin-left: 0.5rem; animation: pulse 1s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
    </style>
</head>
<body>
    <nav class="navbar">
        <div><h1><i class="fas fa-tachometer-alt"></i> Smart Livestock RFID Tracker</h1></div>
        <div>
            <a onclick="showDashboard()"><i class="fas fa-home"></i> Dashboard</a>
            <a onclick="showAnimals()"><i class="fas fa-paw"></i> Animals</a>
            <a onclick="showHealth()"><i class="fas fa-heartbeat"></i> Health</a>
            <a onclick="showScans()"><i class="fas fa-history"></i> Scan History</a>
        </div>
    </nav>

    <div class="container">
        <!-- Dashboard Section -->
        <div id="dashboardSection">
            <div class="card test-scan">
                <i class="fas fa-qrcode fa-2x"></i>
                <strong>Test Scan:</strong>
                <select id="simulateTag">
                    <option value="F31B621A">F31B621A - Cow1</option>
                    <option value="735677FA">735677FA - Cow2</option>
                    <option value="639102F8">639102F8 - Goat1</option>
                    <option value="TEST123">TEST123 - New Animal</option>
                </select>
                <button class="primary" onclick="simulateScan()"><i class="fas fa-play"></i> Test Scan</button>
                <button class="refresh-btn" onclick="refreshDashboard()"><i class="fas fa-sync-alt"></i> Refresh</button>
                <span class="live-badge"><i class="fas fa-circle"></i> LIVE</span>
            </div>

            <div class="stats-grid" id="statsGrid"></div>
            
            <!-- Pending Registrations Notification -->
            <div id="pendingAlert" style="display:none;" class="card">
                <div class="card-title">
                    <span><i class="fas fa-exclamation-triangle"></i> Pending Registrations - Tags need to be registered!</span>
                    <button class="refresh-btn" onclick="refreshDashboard()"><i class="fas fa-sync-alt"></i> Refresh</button>
                </div>
                <div id="pendingList"></div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <div class="card">
                    <div class="card-title">
                        <span><i class="fas fa-history"></i> Recent RFID Scans <span id="scanRefreshTimer" class="scan-countdown"></span></span>
                        <button class="refresh-btn" onclick="refreshRecentScans()"><i class="fas fa-sync-alt"></i> Refresh</button>
                    </div>
                    <div id="recentScansList" style="max-height: 400px; overflow-y: auto;">
                        <div style="text-align:center;padding:2rem;">Loading scans...</div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-title"><i class="fas fa-bell"></i> Health Alerts (Sick Animals)</div>
                    <div id="alertsList" style="max-height: 400px; overflow-y: auto;"></div>
                </div>
            </div>
        </div>

        <!-- Animals Section with ADD ANIMAL Form -->
        <div id="animalsSection" style="display:none;">
            <div class="card">
                <div class="card-title"><i class="fas fa-plus-circle"></i> Add New Animal</div>
                <div id="registerSuccess" style="display:none; background:#dcfce7; color:#16a34a; padding:0.5rem; border-radius:6px; margin-bottom:1rem;"></div>
                <div id="registerError" style="display:none; background:#fee2e2; color:#dc2626; padding:0.5rem; border-radius:6px; margin-bottom:1rem;"></div>
                <div class="form-row">
                    <div><label>RFID Tag ID *</label><input type="text" id="tagId" placeholder="Enter tag ID"></div>
                    <div><label>Animal Name *</label><input type="text" id="name" placeholder="Enter name"></div>
                </div>
                <div class="form-row">
                    <div><label>Type</label>
                        <select id="animalType">
                            <option value="">Select Type</option>
                            <option value="Cow">🐄 Cow</option>
                            <option value="Goat">🐐 Goat</option>
                            <option value="Sheep">🐑 Sheep</option>
                            <option value="Pig">🐷 Pig</option>
                            <option value="Chicken">🐔 Chicken</option>
                            <option value="Rabbit">🐰 Rabbit</option>
                        </select>
                    </div>
                    <div><label>Breed</label><input type="text" id="breed" placeholder="Breed"></div>
                    <div><label>Sex</label>
                        <select id="sex">
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div><label>Birthdate</label><input type="date" id="birthdate"></div>
                    <div><label>Owner Contact</label><input type="text" id="ownerContact" placeholder="Phone"></div>
                    <div><label>Owner Email</label><input type="email" id="ownerEmail" placeholder="Email"></div>
                </div>
                <div style="margin: 1rem 0;">
                    <label><input type="checkbox" id="isPregnant"> 🤰 Pregnant</label>
                    <label style="margin-left:1rem;"><input type="checkbox" id="isSick"> 🤒 Sick</label>
                </div>
                <button id="registerBtn" onclick="registerAnimal()"><i class="fas fa-save"></i> Register Animal</button>
                <button onclick="clearAnimalForm()" style="background:#6b7280;">Clear</button>
            </div>

            <div class="card">
                <div class="card-title">Registered Animals <button class="refresh-btn" onclick="refreshAnimals()"><i class="fas fa-sync-alt"></i> Refresh</button></div>
                <div style="overflow-x:auto;">
                    <table style="width:100%">
                        <thead>
                            <tr><th>Tag ID</th><th>Name</th><th>Type</th><th>Sex</th><th>Breed</th><th>Status</th><th>Owner</th><th>Actions</th></tr>
                        </thead>
                        <tbody id="animalsTable"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Health Section -->
        <div id="healthSection" style="display:none;">
            <div class="card">
                <div class="card-title"><i class="fas fa-notes-medical"></i> Add Health Record</div>
                <div id="healthSuccess" style="display:none; background:#dcfce7; color:#16a34a; padding:0.5rem; border-radius:6px; margin-bottom:1rem;"></div>
                <div id="healthError" style="display:none; background:#fee2e2; color:#dc2626; padding:0.5rem; border-radius:6px; margin-bottom:1rem;"></div>
                <div class="form-row">
                    <div><label>Animal *</label><select id="healthTagId"></select></div>
                    <div><label>Record Type *</label>
                        <select id="recordType">
                            <option value="">Select</option>
                            <option value="Vaccination">💉 Vaccination</option>
                            <option value="Treatment">💊 Treatment</option>
                            <option value="Checkup">🩺 Checkup</option>
                            <option value="Pregnancy Check">🤰 Pregnancy Check</option>
                            <option value="Deworming">🐛 Deworming</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div><label>Record Date</label><input type="date" id="recordDate"></div>
                    <div><label>Next Visit Date</label><input type="date" id="nextVisitDate"></div>
                    <div><label>Cost (RWF)</label><input type="number" id="cost" placeholder="0"></div>
                </div>
                <div class="form-row">
                    <div><label>Vet Name</label><input type="text" id="vetName" placeholder="Veterinarian name"></div>
                    <div><label>Vet Contact</label><input type="text" id="vetContact" placeholder="Phone"></div>
                    <div><label>Medicine</label><input type="text" id="medicine" placeholder="Medicine given"></div>
                </div>
                <div><label>Description</label><textarea id="description" rows="3" placeholder="Detailed description..."></textarea></div>
                <button onclick="addHealthRecord()" style="margin-top:1rem;"><i class="fas fa-save"></i> Save Health Record</button>
            </div>
            
            <div class="card">
                <div class="card-title">
                    <span><i class="fas fa-table"></i> Health Records History</span>
                    <button class="refresh-btn" onclick="refreshHealthRecords()"><i class="fas fa-sync-alt"></i> Refresh</button>
                </div>
                <div id="healthRecordsList" style="max-height: 500px; overflow-y: auto;"></div>
            </div>
        </div>

        <!-- Scans Section -->
        <div id="scansSection" style="display:none;">
            <div class="card">
                <div class="card-title">All Scan History <button class="refresh-btn" onclick="refreshScans()"><i class="fas fa-sync-alt"></i> Refresh</button></div>
                <div style="overflow-x:auto;">
                    <table style="width:100%">
                        <thead><tr><th>Date & Time</th><th>Tag ID</th><th>Animal Name</th><th>Status</th></tr></thead>
                        <tbody id="scansTable"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="auto-refresh">
        <i class="fas fa-sync-alt fa-spin"></i> Auto-refresh every <span id="refreshCounter">5</span>s
    </div>

    <script>
        let isLoading = false;
        let refreshInterval;
        let secondsLeft = 5;

        // Navigation
        function showDashboard() { $('#dashboardSection').show(); $('#animalsSection').hide(); $('#healthSection').hide(); $('#scansSection').hide(); startAutoRefresh(); loadDashboard(); }
        function showAnimals() { $('#dashboardSection').hide(); $('#animalsSection').show(); $('#healthSection').hide(); $('#scansSection').hide(); stopAutoRefresh(); loadAnimals(); loadHealthTagOptions(); }
        function showHealth() { $('#dashboardSection').hide(); $('#animalsSection').hide(); $('#healthSection').show(); $('#scansSection').hide(); stopAutoRefresh(); loadHealthRecords(); loadHealthTagOptions(); }
        function showScans() { $('#dashboardSection').hide(); $('#animalsSection').hide(); $('#healthSection').hide(); $('#scansSection').show(); stopAutoRefresh(); loadScans(); }

        // Auto refresh functions for Recent RFID Scans only
        function startAutoRefresh() {
            if(refreshInterval) clearInterval(refreshInterval);
            secondsLeft = 5;
            updateTimerDisplay();
            refreshInterval = setInterval(function() {
                secondsLeft--;
                updateTimerDisplay();
                if(secondsLeft <= 0) {
                    secondsLeft = 5;
                    refreshRecentScansOnly();
                }
            }, 1000);
        }

        function stopAutoRefresh() {
            if(refreshInterval) clearInterval(refreshInterval);
        }

        function updateTimerDisplay() {
            $('#refreshCounter').text(secondsLeft);
            $('#scanRefreshTimer').html('(auto-refresh in ' + secondsLeft + 's)');
        }

        // Refresh only Recent RFID Scans - NOT the whole dashboard
        function refreshRecentScansOnly() {
            if(isLoading) return;
            $.get('?action=getAll', function(data) {
                let scansHtml = '';
                if(data.recentScans && data.recentScans.length > 0) {
                    data.recentScans.forEach(s => {
                        let badge = s.status == 'SICK' ? '<span class="badge badge-sick">🤒 SICK</span>' : 
                                   (s.status == 'PREGNANT' ? '<span class="badge badge-pregnant">🤰 PREGNANT</span>' : 
                                   (s.status == 'HEALTHY' ? '<span class="badge badge-healthy">✅ HEALTHY</span>' : 
                                   '<span class="badge badge-pending">⏳ PENDING</span>'));
                        scansHtml += `<div class="scan-item">
                            <div><strong>${s.name || s.tagId}</strong><br><small>${s.tagId}</small></div>
                            <div>${badge}<br><small>${s.scanTime}</small></div>
                        </div>`;
                    });
                } else {
                    scansHtml = '<div style="text-align:center;padding:2rem;">⚠️ No scans yet! Click "Test Scan" or scan with ESP32</div>';
                }
                $('#recentScansList').html(scansHtml);
                
                // Also update pending registrations count on stats
                $('#statsGrid .stat-card.yellow .stat-number').text(data.stats.pending);
                $('#statsGrid .stat-card.orange .stat-number').text(data.stats.scansToday);
            });
        }

        // Refresh full dashboard (stats and alerts)
        function refreshDashboard() {
            if(isLoading) return;
            $.get('?action=getAll', function(data) {
                $('#statsGrid').html(`
                    <div class="stat-card blue"><div><h3>Total</h3><div class="stat-number">${data.stats.total}</div></div><i class="fas fa-database"></i></div>
                    <div class="stat-card red"><div><h3>Sick</h3><div class="stat-number">${data.stats.sick}</div></div><i class="fas fa-ambulance"></i></div>
                    <div class="stat-card green"><div><h3>Healthy</h3><div class="stat-number">${data.stats.healthy}</div></div><i class="fas fa-check"></i></div>
                    <div class="stat-card purple"><div><h3>Pregnant</h3><div class="stat-number">${data.stats.pregnant}</div></div><i class="fas fa-baby"></i></div>
                    <div class="stat-card yellow"><div><h3>Pending Reg</h3><div class="stat-number">${data.stats.pending}</div></div><i class="fas fa-clock"></i></div>
                    <div class="stat-card orange"><div><h3>Scans Today</h3><div class="stat-number">${data.stats.scansToday}</div></div><i class="fas fa-qrcode"></i></div>
                `);

                // Recent Scans
                let scansHtml = '';
                if(data.recentScans && data.recentScans.length > 0) {
                    data.recentScans.forEach(s => {
                        let badge = s.status == 'SICK' ? '<span class="badge badge-sick">🤒 SICK</span>' : 
                                   (s.status == 'PREGNANT' ? '<span class="badge badge-pregnant">🤰 PREGNANT</span>' : 
                                   (s.status == 'HEALTHY' ? '<span class="badge badge-healthy">✅ HEALTHY</span>' : 
                                   '<span class="badge badge-pending">⏳ PENDING</span>'));
                        scansHtml += `<div class="scan-item">
                            <div><strong>${s.name || s.tagId}</strong><br><small>${s.tagId}</small></div>
                            <div>${badge}<br><small>${s.scanTime}</small></div>
                        </div>`;
                    });
                } else {
                    scansHtml = '<div style="text-align:center;padding:2rem;">⚠️ No scans yet! Click "Test Scan" or scan with ESP32</div>';
                }
                $('#recentScansList').html(scansHtml);

                // Pending Registrations
                if(data.pendingRegistrations && data.pendingRegistrations.length > 0) {
                    let pendingHtml = '<div style="margin-bottom:0.5rem; color:#d97706;"><i class="fas fa-bell"></i> The following tags need to be registered:</div>';
                    data.pendingRegistrations.forEach(p => {
                        pendingHtml += `<div class="pending-item">
                            <div><i class="fas fa-rfid"></i> <strong>${p.tagId}</strong> - Scanned at ${p.scanTime}</div>
                            <button class="warning btn-sm" onclick="quickRegister('${p.tagId}')">Register Now</button>
                        </div>`;
                    });
                    $('#pendingList').html(pendingHtml);
                    $('#pendingAlert').show();
                } else {
                    $('#pendingAlert').hide();
                }

                // Health Alerts
                let alertsHtml = '';
                if(data.alerts && data.alerts.length > 0) {
                    data.alerts.forEach(a => alertsHtml += `<div class="alert-item"><i class="fas fa-exclamation-triangle"></i> <strong>${a.name}</strong> - Sick! Needs immediate attention</div>`);
                } else {
                    alertsHtml = '<div style="padding:1rem;background:#dcfce7;border-radius:8px;text-align:center;">✅ No active alerts</div>';
                }
                $('#alertsList').html(alertsHtml);
            });
        }

        function refreshRecentScans() {
            refreshRecentScansOnly();
        }

        function refreshAnimals() { if(!isLoading) loadAnimals(); }
        function refreshHealthRecords() { if(!isLoading) { loadHealthRecords(); loadHealthTagOptions(); } }
        function refreshScans() { if(!isLoading) loadScans(); }

        function simulateScan() {
            if(isLoading) return;
            isLoading = true;
            var tagId = $('#simulateTag').val();
            $.get('?esp32=animal&tagId=' + tagId, function(data) {
                if(data.exists) {
                    alert("✅ Scan successful!\nAnimal: " + data.name + "\nStatus: " + (data.isSick ? "SICK" : (data.isPregnant ? "PREGNANT" : "HEALTHY")));
                } else {
                    alert("⚠️ Tag not registered! Please register this animal.\nTag ID: " + data.tagId);
                }
                refreshDashboard();
                refreshRecentScansOnly();
                isLoading = false;
                // Reset timer
                secondsLeft = 5;
                updateTimerDisplay();
            }).fail(function() { alert("Error: Check if server is running"); isLoading = false; });
        }

        function updateHealthStatus(tagId, type) {
            if(isLoading) return;
            if(confirm("Update health status?")) {
                isLoading = true;
                $.get('?action=updateHealth&tagId=' + tagId + '&type=' + type, function() {
                    refreshAnimals();
                    refreshDashboard();
                    isLoading = false;
                }).fail(function() { isLoading = false; });
            }
        }

        function registerAnimal() {
            if(isLoading) return;
            
            var data = {
                tagId: $('#tagId').val().trim(),
                name: $('#name').val().trim(),
                animalType: $('#animalType').val(),
                sex: $('#sex').val(),
                breed: $('#breed').val(),
                birthdate: $('#birthdate').val(),
                isPregnant: $('#isPregnant').is(':checked'),
                isSick: $('#isSick').is(':checked'),
                ownerContact: $('#ownerContact').val(),
                ownerEmail: $('#ownerEmail').val()
            };
            
            if(!data.tagId) { alert("Enter Tag ID"); return; }
            if(!data.name) { alert("Enter Animal Name"); return; }
            
            isLoading = true;
            $('#registerBtn').html('<i class="fas fa-spinner fa-spin"></i> Registering...').prop('disabled', true);
            
            $.ajax({
                url: '?action=register',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: function(res) {
                    if(res.status === 'success') {
                        $('#registerSuccess').text(res.message).show();
                        setTimeout(function() { $('#registerSuccess').fadeOut(); }, 3000);
                        clearAnimalForm();
                        refreshAnimals();
                        refreshDashboard();
                        loadHealthTagOptions();
                    } else {
                        $('#registerError').text(res.message).show();
                        setTimeout(function() { $('#registerError').fadeOut(); }, 3000);
                    }
                    $('#registerBtn').html('<i class="fas fa-save"></i> Register Animal').prop('disabled', false);
                    isLoading = false;
                },
                error: function() {
                    $('#registerError').text("Error registering animal").show();
                    setTimeout(function() { $('#registerError').fadeOut(); }, 3000);
                    $('#registerBtn').html('<i class="fas fa-save"></i> Register Animal').prop('disabled', false);
                    isLoading = false;
                }
            });
        }

        function addHealthRecord() {
            if(isLoading) return;
            
            var data = {
                tagId: $('#healthTagId').val(),
                recordType: $('#recordType').val(),
                recordDate: $('#recordDate').val(),
                nextVisitDate: $('#nextVisitDate').val(),
                cost: $('#cost').val(),
                vetName: $('#vetName').val(),
                vetContact: $('#vetContact').val(),
                medicine: $('#medicine').val(),
                description: $('#description').val()
            };
            
            if(!data.tagId) { alert("Select an animal"); return; }
            if(!data.recordType) { alert("Select record type"); return; }
            if(!data.recordDate) data.recordDate = new Date().toISOString().split('T')[0];
            
            isLoading = true;
            
            $.ajax({
                url: '?action=addHealth',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: function(res) {
                    if(res.status === 'success') {
                        $('#healthSuccess').text(res.message).show();
                        setTimeout(function() { $('#healthSuccess').fadeOut(); }, 3000);
                        $('#recordType').val('');
                        $('#description').val('');
                        $('#vetName').val('');
                        $('#vetContact').val('');
                        $('#nextVisitDate').val('');
                        $('#cost').val('');
                        $('#medicine').val('');
                        refreshHealthRecords();
                        refreshDashboard();
                    } else {
                        $('#healthError').text(res.message).show();
                        setTimeout(function() { $('#healthError').fadeOut(); }, 3000);
                    }
                    isLoading = false;
                },
                error: function() {
                    $('#healthError').text("Error adding health record").show();
                    setTimeout(function() { $('#healthError').fadeOut(); }, 3000);
                    isLoading = false;
                }
            });
        }

        function deleteHealthRecord(id) {
            if(isLoading) return;
            if(confirm("Delete this health record?")) {
                isLoading = true;
                $.get('?action=deleteHealth&id=' + id, function() {
                    refreshHealthRecords();
                    refreshDashboard();
                    isLoading = false;
                }).fail(function() { isLoading = false; });
            }
        }

        function deleteAnimal(tagId) {
            if(isLoading) return;
            if(confirm("Delete this animal? All health records and scans will be deleted!")) {
                isLoading = true;
                $.get('?action=delete&tagId=' + tagId, function() {
                    refreshAnimals();
                    refreshDashboard();
                    refreshHealthRecords();
                    loadHealthTagOptions();
                    isLoading = false;
                }).fail(function() { isLoading = false; });
            }
        }

        function clearAnimalForm() {
            $('#tagId,#name,#breed,#ownerContact,#ownerEmail').val('');
            $('#animalType').val('');
            $('#birthdate').val('');
            $('#sex').val('Male');
            $('#isPregnant,#isSick').prop('checked', false);
        }

        function loadDashboard() {
            if(isLoading) return;
            $.get('?action=getAll', function(data) {
                $('#statsGrid').html(`
                    <div class="stat-card blue"><div><h3>Total</h3><div class="stat-number">${data.stats.total}</div></div><i class="fas fa-database"></i></div>
                    <div class="stat-card red"><div><h3>Sick</h3><div class="stat-number">${data.stats.sick}</div></div><i class="fas fa-ambulance"></i></div>
                    <div class="stat-card green"><div><h3>Healthy</h3><div class="stat-number">${data.stats.healthy}</div></div><i class="fas fa-check"></i></div>
                    <div class="stat-card purple"><div><h3>Pregnant</h3><div class="stat-number">${data.stats.pregnant}</div></div><i class="fas fa-baby"></i></div>
                    <div class="stat-card yellow"><div><h3>Pending Reg</h3><div class="stat-number">${data.stats.pending}</div></div><i class="fas fa-clock"></i></div>
                    <div class="stat-card orange"><div><h3>Scans Today</h3><div class="stat-number">${data.stats.scansToday}</div></div><i class="fas fa-qrcode"></i></div>
                `);

                let scansHtml = '';
                if(data.recentScans && data.recentScans.length > 0) {
                    data.recentScans.forEach(s => {
                        let badge = s.status == 'SICK' ? '<span class="badge badge-sick">🤒 SICK</span>' : 
                                   (s.status == 'PREGNANT' ? '<span class="badge badge-pregnant">🤰 PREGNANT</span>' : 
                                   (s.status == 'HEALTHY' ? '<span class="badge badge-healthy">✅ HEALTHY</span>' : 
                                   '<span class="badge badge-pending">⏳ PENDING</span>'));
                        scansHtml += `<div class="scan-item">
                            <div><strong>${s.name || s.tagId}</strong><br><small>${s.tagId}</small></div>
                            <div>${badge}<br><small>${s.scanTime}</small></div>
                        </div>`;
                    });
                } else {
                    scansHtml = '<div style="text-align:center;padding:2rem;">⚠️ No scans yet! Click "Test Scan" or scan with ESP32</div>';
                }
                $('#recentScansList').html(scansHtml);

                if(data.pendingRegistrations && data.pendingRegistrations.length > 0) {
                    let pendingHtml = '<div style="margin-bottom:0.5rem; color:#d97706;"><i class="fas fa-bell"></i> The following tags need to be registered:</div>';
                    data.pendingRegistrations.forEach(p => {
                        pendingHtml += `<div class="pending-item">
                            <div><i class="fas fa-rfid"></i> <strong>${p.tagId}</strong> - Scanned at ${p.scanTime}</div>
                            <button class="warning btn-sm" onclick="quickRegister('${p.tagId}')">Register Now</button>
                        </div>`;
                    });
                    $('#pendingList').html(pendingHtml);
                    $('#pendingAlert').show();
                } else {
                    $('#pendingAlert').hide();
                }

                let alertsHtml = '';
                if(data.alerts && data.alerts.length > 0) {
                    data.alerts.forEach(a => alertsHtml += `<div class="alert-item"><i class="fas fa-exclamation-triangle"></i> <strong>${a.name}</strong> - Sick! Needs immediate attention</div>`);
                } else {
                    alertsHtml = '<div style="padding:1rem;background:#dcfce7;border-radius:8px;text-align:center;">✅ No active alerts</div>';
                }
                $('#alertsList').html(alertsHtml);
            });
        }

        function loadAnimals() {
            if(isLoading) return;
            $.get('?action=getAll', function(data) {
                let html = '';
                data.animals.forEach(a => {
                    let statusBadge = a.isSick ? '<span class="badge badge-sick">SICK</span>' : (a.isPregnant ? '<span class="badge badge-pregnant">PREGNANT</span>' : '<span class="badge badge-healthy">HEALTHY</span>');
                    html += `<tr>
                        <td class="font-mono">${a.tagId}</td>
                        <td><strong>${a.name}</strong></td>
                        <td>${a.animalType || '-'}</td>
                        <td>${a.sex || '-'}</td>
                        <td>${a.breed || '-'}</td>
                        <td>${statusBadge}</td>
                        <td>${a.ownerContact || '-'}</td>
                        <td>
                            <button class="success btn-sm" onclick="updateHealthStatus('${a.tagId}', 'healthy')">Healthy</button>
                            <button class="warning btn-sm" onclick="updateHealthStatus('${a.tagId}', 'sick')">Sick</button>
                            <button class="primary btn-sm" onclick="updateHealthStatus('${a.tagId}', 'pregnant')">Pregnant</button>
                            <button class="danger btn-sm" onclick="deleteAnimal('${a.tagId}')">Delete</button>
                        </td>
                    </tr>`;
                });
                $('#animalsTable').html(html);
            });
        }

        function loadHealthTagOptions() {
            $.get('?action=getAll', function(data) {
                let opts = '<option value="">Select Animal</option>';
                data.animals.forEach(a => opts += `<option value="${a.tagId}">${a.tagId} - ${a.name}</option>`);
                $('#healthTagId').html(opts);
            });
        }

        function loadHealthRecords() {
            if(isLoading) return;
            $.get('?action=getAll', function(data) {
                let html = '';
                if(data.healthRecords && data.healthRecords.length > 0) {
                    data.healthRecords.forEach(h => {
                        html += `<div class="health-item">
                            <span class="delete-health" onclick="deleteHealthRecord(${h.id})"><i class="fas fa-trash"></i></span>
                            <strong><i class="fas fa-${h.recordType == 'Vaccination' ? 'syringe' : (h.recordType == 'Treatment' ? 'capsules' : 'stethoscope')}"></i> ${h.recordType}</strong> - ${h.recordDate}
                            <div><small>Animal: ${h.animal_name || h.tagId}</small></div>
                            <div><small>${h.description || 'No description'}</small></div>
                            ${h.vetName ? `<div><small><i class="fas fa-user-md"></i> Dr. ${h.vetName} ${h.vetContact ? '('+h.vetContact+')' : ''}</small></div>` : ''}
                            ${h.nextVisitDate ? `<div><small><i class="fas fa-calendar-alt"></i> Next: ${h.nextVisitDate}</small></div>` : ''}
                            ${h.medicine ? `<div><small><i class="fas fa-pills"></i> Medicine: ${h.medicine}</small></div>` : ''}
                            ${h.cost > 0 ? `<div><small><i class="fas fa-money-bill"></i> Cost: ${h.cost} RWF</small></div>` : ''}
                        </div>`;
                    });
                } else {
                    html = '<div style="text-align:center;padding:2rem;">No health records yet. Add your first health record above!</div>';
                }
                $('#healthRecordsList').html(html);
            });
        }

        function loadScans() {
            if(isLoading) return;
            $.get('?action=getAll', function(data) {
                let html = '';
                if(data.recentScans && data.recentScans.length > 0) {
                    data.recentScans.forEach(s => {
                        let badge = s.status == 'SICK' ? '<span class="badge badge-sick">SICK</span>' : 
                                   (s.status == 'PREGNANT' ? '<span class="badge badge-pregnant">PREGNANT</span>' : 
                                   (s.status == 'HEALTHY' ? '<span class="badge badge-healthy">HEALTHY</span>' : 
                                   '<span class="badge badge-pending">PENDING</span>'));
                        html += `<tr>
                            <td>${s.scanTime}</td>
                            <td class="font-mono">${s.tagId}</td>
                            <td>${s.name || 'Unknown'}</td>
                            <td>${badge}</td>
                        </tr>`;
                    });
                } else {
                    html = '<tr><td colspan="4" style="text-align:center">No scans yet. Click "Test Scan" to test!</td></tr>';
                }
                $('#scansTable').html(html);
            });
        }

        function quickRegister(tagId) {
            $('#tagId').val(tagId);
            showAnimals();
            refreshDashboard();
        }

        // Start auto-refresh when page loads
        startAutoRefresh();
        loadDashboard();
    </script>
</body>
</html>