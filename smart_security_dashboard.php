<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
class DatabaseConfig {
    private $host = 'localhost';
    private $username = 'root';
    private $password = '';
    private $database = 'smart_security_system';
    private $connection = null;
    private static $instance = null;

    private function __construct() {
        try {
            $this->connection = new mysqli(
                $this->host, 
                $this->username, 
                $this->password, 
                $this->database
            );

            if ($this->connection->connect_error) {
                throw new Exception("Database connection failed: " . $this->connection->connect_error);
            }

            $this->connection->set_charset("utf8mb4");
            $this->initializeDatabase();
        } catch (Exception $e) {
            error_log("Database Error: " . $e->getMessage());
            die("System initialization failed. Please contact administrator.");
        }
    }
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    public function getConnection() {
        return $this->connection;
    }

    private function initializeDatabase() {
        // Create database if not exists
        $this->connection->query("CREATE DATABASE IF NOT EXISTS {$this->database}");
        $this->connection->select_db($this->database);

        // Create tables
        $tables = [
            "CREATE TABLE IF NOT EXISTS distance_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                distance_cm DECIMAL(5,2) NOT NULL,
                object_detected BOOLEAN DEFAULT FALSE,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR(50) DEFAULT 'Normal',
                INDEX idx_timestamp (timestamp),
                INDEX idx_detection (object_detected)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS photos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                filepath VARCHAR(500) NOT NULL,
                size_kb DECIMAL(10,2),
                detection_event_id INT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (detection_event_id) REFERENCES distance_logs(id) ON DELETE SET NULL,
                INDEX idx_timestamp (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS gsm_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                phone_number VARCHAR(20) NOT NULL,
                message TEXT NOT NULL,
                status ENUM('sent', 'failed', 'delivered') DEFAULT 'sent',
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                response_code VARCHAR(10),
                INDEX idx_timestamp (timestamp),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS system_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(50) NOT NULL,
                description TEXT,
                severity ENUM('info', 'warning', 'critical') DEFAULT 'info',
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_timestamp (timestamp),
                INDEX idx_severity (severity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];

        foreach ($tables as $sql) {
            $this->connection->query($sql);
        }

        // Create upload directory
        $uploadDir = __DIR__ . '/uploads/photos/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
    }
}
define('SYSTEM_NAME', 'Smart Security System Pro');
define('SYSTEM_VERSION', '2.0');
define('ALERT_THRESHOLD', 50);
define('UPLOAD_DIR', __DIR__ . '/uploads/photos/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024);
class SecurityAPI {
    private $db;

    public function __construct() {
        $this->db = DatabaseConfig::getInstance()->getConnection();
    }

    public function handleRequest() {
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'];

        header('Content-Type: application/json');

        try {
            switch ($action) {
                case 'add_distance':
                    $this->addDistanceReading();
                    break;
                case 'upload_photo':
                    $this->uploadPhoto();
                    break;
                case 'add_alert':
                    $this->addGSMAlert();
                    break;
                case 'get_stats':
                    $this->getStatistics();
                    break;
                case 'get_distance_data':
                    $this->getDistanceData();
                    break;
                case 'get_photos':
                    $this->getPhotos();
                    break;
                case 'get_alerts':
                    $this->getAlerts();
                    break;
                case 'delete_photo':
                    $this->deletePhoto();
                    break;
                case 'test_connection':
                    $this->testConnection();
                    break;
                default:
                    $this->sendResponse(false, 'Invalid action specified');
            }
        } catch (Exception $e) {
            $this->sendResponse(false, $e->getMessage());
            $this->logEvent('api_error', $e->getMessage(), 'warning');
        }
    }

    private function addDistanceReading() {
        $distance = floatval($_POST['distance'] ?? 0);
        $objectDetected = intval($_POST['object_detected'] ?? 0);
        
        $stmt = $this->db->prepare("INSERT INTO distance_logs (distance_cm, object_detected) VALUES (?, ?)");
        $stmt->bind_param("di", $distance, $objectDetected);
        
        if ($stmt->execute()) {
            $insertId = $this->db->insert_id;
            $this->logEvent('distance_reading', "Distance: {$distance}cm, Detection: {$objectDetected}");
            $this->sendResponse(true, 'Distance reading added', ['id' => $insertId]);
        } else {
            throw new Exception("Failed to add distance reading");
        }
        $stmt->close();
    }

    private function uploadPhoto() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new Exception("Invalid request method");
        }

        $imageData = file_get_contents('php://input');
        
        if (empty($imageData)) {
            throw new Exception("No image data received");
        }

        $filename = 'capture_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.jpg';
        $filepath = UPLOAD_DIR . $filename;

        if (file_put_contents($filepath, $imageData)) {
            $sizeKB = round(filesize($filepath) / 1024, 2);
            
            // Get last detection event
            $result = $this->db->query("SELECT id FROM distance_logs WHERE object_detected = 1 ORDER BY timestamp DESC LIMIT 1");
            $eventId = null;
            if ($row = $result->fetch_assoc()) {
                $eventId = $row['id'];
            }

            $relativePath = 'uploads/photos/' . $filename;
            $stmt = $this->db->prepare("INSERT INTO photos (filename, filepath, size_kb, detection_event_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssdi", $filename, $relativePath, $sizeKB, $eventId);
            
            if ($stmt->execute()) {
                $this->logEvent('photo_capture', "Photo saved: {$filename}");
                $this->sendResponse(true, 'Photo uploaded successfully', ['filename' => $filename]);
            } else {
                throw new Exception("Failed to save photo record");
            }
            $stmt->close();
        } else {
            throw new Exception("Failed to save image file");
        }
    }

    private function addGSMAlert() {
        $message = $_POST['message'] ?? 'Security Alert!';
        $phone = $_POST['phone'] ?? '+1234567890';
        
        $stmt = $this->db->prepare("INSERT INTO gsm_alerts (phone_number, message, status) VALUES (?, ?, 'sent')");
        $stmt->bind_param("ss", $phone, $message);
        
        if ($stmt->execute()) {
            $this->logEvent('gsm_alert', "Alert sent to: {$phone}");
            $this->sendResponse(true, 'Alert logged successfully');
        } else {
            throw new Exception("Failed to log alert");
        }
        $stmt->close();
    }

    private function getStatistics() {
        $stats = [];
        
        // Today's events
        $result = $this->db->query("SELECT COUNT(*) as total FROM distance_logs WHERE DATE(timestamp) = CURDATE()");
        $stats['today_events'] = $result->fetch_assoc()['total'];
        
        // Today's detections
        $result = $this->db->query("SELECT COUNT(*) as total FROM distance_logs WHERE object_detected = 1 AND DATE(timestamp) = CURDATE()");
        $stats['today_detections'] = $result->fetch_assoc()['total'];
        
        // Latest distance
        $result = $this->db->query("SELECT distance_cm, object_detected FROM distance_logs ORDER BY timestamp DESC LIMIT 1");
        if ($row = $result->fetch_assoc()) {
            $stats['latest_distance'] = $row['distance_cm'];
            $stats['object_detected'] = $row['object_detected'];
        }
        
        // Total photos
        $result = $this->db->query("SELECT COUNT(*) as total FROM photos");
        $stats['total_photos'] = $result->fetch_assoc()['total'];
        
        // Today's alerts
        $result = $this->db->query("SELECT COUNT(*) as total FROM gsm_alerts WHERE DATE(timestamp) = CURDATE()");
        $stats['today_alerts'] = $result->fetch_assoc()['total'];
        
        // System uptime
        $result = $this->db->query("SELECT timestamp FROM system_logs ORDER BY timestamp ASC LIMIT 1");
        if ($row = $result->fetch_assoc()) {
            $uptime = time() - strtotime($row['timestamp']);
            $stats['uptime_hours'] = round($uptime / 3600, 1);
        }
        
        $this->sendResponse(true, 'Statistics retrieved', ['stats' => $stats]);
    }

    private function getDistanceData() {
        $limit = intval($_GET['limit'] ?? 50);
        $offset = intval($_GET['offset'] ?? 0);
        
        $stmt = $this->db->prepare("SELECT * FROM distance_logs ORDER BY timestamp DESC LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        $this->sendResponse(true, 'Distance data retrieved', ['data' => $data]);
        $stmt->close();
    }

    private function getPhotos() {
        $limit = intval($_GET['limit'] ?? 20);
        $offset = intval($_GET['offset'] ?? 0);
        
        $stmt = $this->db->prepare("SELECT p.*, d.distance_cm FROM photos p LEFT JOIN distance_logs d ON p.detection_event_id = d.id ORDER BY p.timestamp DESC LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        $this->sendResponse(true, 'Photos retrieved', ['data' => $data]);
        $stmt->close();
    }

    private function getAlerts() {
        $limit = intval($_GET['limit'] ?? 50);
        
        $stmt = $this->db->prepare("SELECT * FROM gsm_alerts ORDER BY timestamp DESC LIMIT ?");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        $this->sendResponse(true, 'Alerts retrieved', ['data' => $data]);
        $stmt->close();
    }

    private function deletePhoto() {
        $photoId = intval($_POST['photo_id'] ?? 0);
        
        $stmt = $this->db->prepare("SELECT filepath FROM photos WHERE id = ?");
        $stmt->bind_param("i", $photoId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($photo = $result->fetch_assoc()) {
            $fullPath = __DIR__ . '/' . $photo['filepath'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
            
            $stmt = $this->db->prepare("DELETE FROM photos WHERE id = ?");
            $stmt->bind_param("i", $photoId);
            
            if ($stmt->execute()) {
                $this->logEvent('photo_delete', "Photo ID {$photoId} deleted");
                $this->sendResponse(true, 'Photo deleted successfully');
            } else {
                throw new Exception("Failed to delete photo");
            }
        }
        $stmt->close();
    }

    private function testConnection() {
        $this->sendResponse(true, 'Connection successful', [
            'database' => 'Connected',
            'server_time' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION
        ]);
    }

    private function logEvent($type, $description, $severity = 'info') {
        try {
            $stmt = $this->db->prepare("INSERT INTO system_logs (event_type, description, severity) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $type, $description, $severity);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("Failed to log event: " . $e->getMessage());
        }
    }

    private function sendResponse($success, $message, $data = []) {
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
}

// ============================================
// DASHBOARD CLASS
// ============================================
class SecurityDashboard {
    private $db;
    private $api;
    private $currentPage;

    public function __construct() {
        $this->db = DatabaseConfig::getInstance()->getConnection();
        $this->api = new SecurityAPI();
        $this->currentPage = $_GET['page'] ?? 'dashboard';
    }

    public function render() {
        // Check if it's an API request
        if (isset($_GET['action']) || isset($_POST['action'])) {
            $this->api->handleRequest();
            return;
        }

        // Render the appropriate page
        switch ($this->currentPage) {
            case 'distance_logs':
                $this->renderDistanceLogs();
                break;
            case 'photo_gallery':
                $this->renderPhotoGallery();
                break;
            case 'gsm_alerts':
                $this->renderGSMAlerts();
                break;
            default:
                $this->renderDashboard();
        }
    }

    private function renderHeader($title) {
        $currentPage = $this->currentPage;
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo $title; ?> - <?php echo SYSTEM_NAME; ?></title>
            
            <!-- Bootstrap CSS -->arduino
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <!-- Font Awesome -->
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
            <!-- DataTables -->
            <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
            <!-- Chart.js -->
            <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.0/dist/chart.min.js"></script>
            
            <style>
                :root {
                    --primary-color: #2c3e50;
                    --secondary-color: #3498db;
                    --success-color: #27ae60;
                    --danger-color: #e74c3c;
                    --warning-color: #f39c12;
                    --info-color: #2980b9;
                }

                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                }

                .wrapper {
                    display: flex;
                    width: 100%;
                }

                /* Sidebar Styles */
                .sidebar {
                    width: 250px;
                    position: fixed;
                    top: 0;
                    left: 0;
                    height: 100vh;
                    background: var(--primary-color);
                    color: white;
                    transition: all 0.3s;
                    z-index: 999;
                    box-shadow: 2px 0 5px rgba(0,0,0,0.1);
                }

                .sidebar-header {
                    padding: 20px;
                    background: rgba(0,0,0,0.1);
                    text-align: center;
                }

                .sidebar-header h3 {
                    margin: 0;
                    font-size: 1.5rem;
                    font-weight: 600;
                }

                .sidebar-menu {
                    padding: 20px 0;
                }

                .sidebar-menu a {
                    padding: 15px 25px;
                    display: block;
                    color: white;
                    text-decoration: none;
                    transition: all 0.3s;
                    border-left: 3px solid transparent;
                }

                .sidebar-menu a:hover, .sidebar-menu a.active {
                    background: rgba(255,255,255,0.1);
                    border-left-color: var(--secondary-color);
                }

                .sidebar-menu a i {
                    margin-right: 10px;
                    width: 20px;
                    text-align: center;
                }

                /* Main Content Styles */
                .main-content {
                    margin-left: 250px;
                    width: calc(100% - 250px);
                    min-height: 100vh;
                    transition: all 0.3s;
                }

                .top-bar {
                    background: white;
                    padding: 15px 30px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }

                .content-area {
                    padding: 30px;
                }

                .card {
                    border: none;
                    border-radius: 10px;
                    box-shadow: 0 0 20px rgba(0,0,0,0.1);
                    margin-bottom: 20px;
                    transition: transform 0.3s;
                }

                .card:hover {
                    transform: translateY(-5px);
                }

                .stat-card {
                    background: white;
                    border-radius: 10px;
                    padding: 20px;
                    margin-bottom: 20px;
                    box-shadow: 0 0 20px rgba(0,0,0,0.1);
                    transition: all 0.3s;
                }

                .stat-card:hover {
                    transform: translateY(-5px);
                    box-shadow: 0 5px 30px rgba(0,0,0,0.2);
                }

                .stat-icon {
                    font-size: 2.5rem;
                    opacity: 0.7;
                }

                .stat-value {
                    font-size: 2rem;
                    font-weight: bold;
                    margin: 10px 0;
                }

                .badge {
                    padding: 8px 12px;
                    font-size: 0.9rem;
                }

                .system-status {
                    display: inline-block;
                    width: 12px;
                    height: 12px;
                    border-radius: 50%;
                    margin-right: 8px;
                }

                .status-online {
                    background: var(--success-color);
                    animation: pulse 2s infinite;
                }

                .status-offline {
                    background: var(--danger-color);
                }

                @keyframes pulse {
                    0% { box-shadow: 0 0 0 0 rgba(39, 174, 96, 0.7); }
                    70% { box-shadow: 0 0 0 10px rgba(39, 174, 96, 0); }
                    100% { box-shadow: 0 0 0 0 rgba(39, 174, 96, 0); }
                }

                .table {
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                }

                .table thead th {
                    background: var(--primary-color);
                    color: white;
                    border: none;
                    padding: 15px;
                }

                .photo-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                    gap: 20px;
                    padding: 20px 0;
                }

                .photo-card {
                    background: white;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 0 20px rgba(0,0,0,0.1);
                    transition: all 0.3s;
                }

                .photo-card:hover {
                    transform: scale(1.02);
                }

                .photo-card img {
                    width: 100%;
                    height: 200px;
                    object-fit: cover;
                }

                .photo-info {
                    padding: 15px;
                }

                .loading-spinner {
                    display: none;
                    text-align: center;
                    padding: 20px;
                }

                .loading-spinner.active {
                    display: block;
                }

                @media (max-width: 768px) {
                    .sidebar {
                        margin-left: -250px;
                    }
                    .sidebar.active {
                        margin-left: 0;
                    }
                    .main-content {
                        margin-left: 0;
                        width: 100%;
                    }
                    #sidebarCollapse {
                        display: block;
                    }
                }
            </style>
        </head>
        <body>
            <div class="wrapper">
                <!-- Sidebar -->
                <nav class="sidebar">
                    <div class="sidebar-header">
                        <h3><i class="fas fa-shield-alt"></i> Security System</h3>
                        <small>Version <?php echo SYSTEM_VERSION; ?></small>
                    </div>
                    <div class="sidebar-menu">
                        <a href="?page=dashboard" class="<?php echo $currentPage == 'dashboard' ? 'active' : ''; ?>">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                        <a href="?page=distance_logs" class="<?php echo $currentPage == 'distance_logs' ? 'active' : ''; ?>">
                            <i class="fas fa-ruler"></i> Distance Logs
                        </a>
                        <a href="?page=photo_gallery" class="<?php echo $currentPage == 'photo_gallery' ? 'active' : ''; ?>">
                            <i class="fas fa-images"></i> Photo Gallery
                        </a>
                        <a href="?page=gsm_alerts" class="<?php echo $currentPage == 'gsm_alerts' ? 'active' : ''; ?>">
                            <i class="fas fa-sms"></i> GSM Alerts
                        </a>
                        <a href="#" onclick="testConnection()">
                            <i class="fas fa-plug"></i> Test Connection
                        </a>
                    </div>
                </nav>

                <!-- Main Content -->
                <div class="main-content">
                    <div class="top-bar">
                        <button type="button" id="sidebarCollapse" class="btn btn-dark d-md-none">
                            <i class="fas fa-bars"></i>
                        </button>
                        <div>
                            <span class="system-status status-online"></span>
                            System Status: <strong>Online</strong>
                        </div>
                        <div>
                            <span id="currentTime"><?php echo date('Y-m-d H:i:s'); ?></span>
                            <button class="btn btn-sm btn-outline-primary ms-2" onclick="refreshPage()">
                                <i class="fas fa-sync"></i>
                            </button>
                        </div>
                    </div>
                     <img id="camera" src="http://192.168.137.225/capture">
                    <div class="content-area">
        <?php
    }

    private function renderFooter() {
        ?>
                    </div><!-- content-area -->
                </div><!-- main-content -->
            </div><!-- wrapper -->

            <!-- Scripts -->
            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
            <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
            <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

            <script>
                // Global JavaScript Functions
                let autoRefreshInterval;

                // Update current time
                function updateTime() {
                    const now = new Date();
                    document.getElementById('currentTime').textContent = now.toLocaleString();
                }
                setInterval(updateTime, 1000);

                // Toggle sidebar on mobile
                document.getElementById('sidebarCollapse')?.addEventListener('click', () => {
                    document.querySelector('.sidebar').classList.toggle('active');
                });

                // Refresh current page
                function refreshPage() {
                    location.reload();
                }

                // Auto refresh statistics every 5 seconds
                function startAutoRefresh() {
                    autoRefreshInterval = setInterval(() => {
                        loadStatistics();
                        if (typeof loadChartData === 'function') {
                            loadChartData();
                        }
                    }, 5000);
                }

                // Load real-time statistics
                function loadStatistics() {
                    $.get('?action=get_stats', function(response) {
                        if (response.success && response.data.stats) {
                            const stats = response.data.stats;
                            $('#todayEvents').text(stats.today_events);
                            $('#todayDetections').text(stats.today_detections);
                            $('#latestDistance').text(stats.latest_distance + ' cm');
                            $('#totalPhotos').text(stats.total_photos);
                            $('#todayAlerts').text(stats.today_alerts);
                            
                            // Update detection status
                            if (stats.object_detected) {
                                $('#detectionStatus').html('<span class="badge bg-danger">Object Detected</span>');
                            } else {
                                $('#detectionStatus').html('<span class="badge bg-success">Area Clear</span>');
                            }
                        }
                    });
                }

                // Test system connection
                function testConnection() {
                    $.get('?action=test_connection', function(response) {
                        if (response.success) {
                            alert('System Connection Successful!\n\n' +
                                  'Database: ' + response.data.database + '\n' +
                                  'Server Time: ' + response.data.server_time + '\n' +
                                  'PHP Version: ' + response.data.php_version);
                        } else {
                            alert('Connection failed: ' + response.message);
                        }
                    });
                }

                // Delete photo
                function deletePhoto(photoId) {
                    if (confirm('Are you sure you want to delete this photo?')) {
                        $.post('?action=delete_photo', { photo_id: photoId }, function(response) {
                            if (response.success) {
                                alert('Photo deleted successfully!');
                                location.reload();
                            } else {
                                alert('Failed to delete photo: ' + response.message);
                            }
                        });
                    }
                }

                // Send test alert
                function sendTestAlert() {
                    const phone = prompt('Enter phone number for test SMS:', '+1234567890');
                    if (phone) {
                        $.post('?action=add_alert', {
                            message: 'Test alert from Smart Security System',
                            phone: phone
                        }, function(response) {
                            if (response.success) {
                                alert('Test alert sent successfully!');
                                location.reload();
                            } else {
                                alert('Failed to send alert: ' + response.message);
                            }
                        });
                    }
                }

                // Initialize on page load
                $(document).ready(function() {
                    startAutoRefresh();
                    loadStatistics();
                    
                    // Initialize DataTables if table exists
                    if ($.fn.DataTable && $('.datatable').length) {
                        $('.datatable').DataTable({
                            responsive: true,
                            pageLength: 25,
                            order: [[0, 'desc']]
                        });
                    }
                });
            </script>
        </body>
        </html>
        <?php
    }

    private function renderDashboard() {
        $this->renderHeader('Dashboard');
        
        // Get statistics
        $stats = $this->getStatistics();
        ?>
        
        <h2 class="mb-4">Security System Dashboard</h2>
        
        <!-- Statistics Row -->
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <i class="fas fa-chart-line stat-icon text-primary"></i>
                    <div class="stat-value text-primary" id="todayEvents"><?php echo $stats['today_events']; ?></div>
                    <div>Today's Events</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <i class="fas fa-exclamation-triangle stat-icon text-danger"></i>
                    <div class="stat-value text-danger" id="todayDetections"><?php echo $stats['today_detections']; ?></div>
                    <div>Object Detections</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <i class="fas fa-ruler stat-icon text-success"></i>
                    <div class="stat-value text-success" id="latestDistance"><?php echo $stats['latest_distance']; ?> cm</div>
                    <div>Latest Distance</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card text-center">
                    <i class="fas fa-sms stat-icon text-warning"></i>
                    <div class="stat-value text-warning" id="todayAlerts"><?php echo $stats['today_alerts']; ?></div>
                    <div>GSM Alerts Today</div>
                </div>
            </div>
        </div>

        <!-- Status and Chart Row -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> System Status</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <td><strong>Detection Status:</strong></td>
                                <td id="detectionStatus">
                                    <?php if ($stats['object_detected']): ?>
                                        <span class="badge bg-danger">Object Detected</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Area Clear</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Green LED:</strong></td>
                                <td>
                                    <?php if (!$stats['object_detected']): ?>
                                        <span class="badge bg-success">ON</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">OFF</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Red LED:</strong></td>
                                <td>
                                    <?php if ($stats['object_detected']): ?>
                                        <span class="badge bg-danger">ON</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">OFF</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Buzzer:</strong></td>
                                <td>
                                    <?php if ($stats['object_detected']): ?>
                                        <span class="badge bg-danger">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">OFF</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Total Photos:</strong></td>
                                <td><span class="badge bg-info"><?php echo $stats['total_photos']; ?></span></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-chart-line"></i> Distance Monitoring</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="distanceChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="card mt-4">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-history"></i> Recent Activity</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Event Type</th>
                                <th>Details</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $query = "
                                SELECT timestamp, 'Distance Reading' as type, 
                                       CONCAT(distance_cm, ' cm') as details,
                                       IF(object_detected, 'Warning', 'Normal') as status
                                FROM distance_logs 
                                UNION ALL
                                SELECT timestamp, 'Photo Captured' as type,
                                       CONCAT(size_kb, ' KB') as details, 'Info' as status
                                FROM photos
                                UNION ALL
                                SELECT timestamp, 'GSM Alert' as type,
                                       CONCAT('Sent to: ', phone_number) as details, status
                                FROM gsm_alerts
                                ORDER BY timestamp DESC LIMIT 20
                            ";
                            
                            $result = $this->db->query($query);
                            if ($result) {
                                while ($row = $result->fetch_assoc()) {
                                    $badgeClass = 'bg-secondary';
                                    switch ($row['status']) {
                                        case 'Warning': $badgeClass = 'bg-warning'; break;
                                        case 'Normal': $badgeClass = 'bg-success'; break;
                                        case 'Info': $badgeClass = 'bg-info'; break;
                                        case 'sent': $badgeClass = 'bg-primary'; break;
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo date('Y-m-d H:i:s', strtotime($row['timestamp'])); ?></td>
                                        <td><?php echo $row['type']; ?></td>
                                        <td><?php echo $row['details']; ?></td>
                                        <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $row['status']; ?></span></td>
                                    </tr>
                                    <?php
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
            // Load chart data
            function loadChartData() {
                $.get('?action=get_distance_data&limit=20', function(response) {
                    if (response.success && response.data.data) {
                        const data = response.data.data.reverse();
                        const labels = data.map(d => new Date(d.timestamp).toLocaleTimeString());
                        const values = data.map(d => d.distance_cm);
                        
                        if (window.distanceChart) {
                            window.distanceChart.data.labels = labels;
                            window.distanceChart.data.datasets[0].data = values;
                            window.distanceChart.update();
                        }
                    }
                });
            }

            // Initialize chart
            $(document).ready(function() {
                const ctx = document.getElementById('distanceChart').getContext('2d');
                window.distanceChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: [],
                        datasets: [{
                            label: 'Distance (cm)',
                            data: [],
                            borderColor: '#3498db',
                            backgroundColor: 'rgba(52, 152, 219, 0.1)',
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Distance (cm)'
                                }
                            }
                        }
                    }
                });
                
                loadChartData();
                setInterval(loadChartData, 5000);
            });
        </script>

        <?php
        $this->renderFooter();
    }

    private function renderDistanceLogs() {
        $this->renderHeader('Distance Logs');
        ?>
        
        <h2 class="mb-4"><i class="fas fa-ruler"></i> Distance Logs</h2>
        
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Distance (cm)</th>
                                <th>Object Detected</th>
                                <th>Timestamp</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $result = $this->db->query("SELECT * FROM distance_logs ORDER BY timestamp DESC LIMIT 100");
                            if ($result) {
                                while ($row = $result->fetch_assoc()) {
                                    $badgeClass = $row['object_detected'] ? 'bg-danger' : 'bg-success';
                                    $statusText = $row['object_detected'] ? 'Detected' : 'Clear';
                                    $distanceStatus = $row['distance_cm'] <= 50 ? 'Warning' : 'Normal';
                                    ?>
                                    <tr>
                                        <td><?php echo $row['id']; ?></td>
                                        <td><strong><?php echo number_format($row['distance_cm'], 1); ?></strong></td>
                                        <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $statusText; ?></span></td>
                                        <td><?php echo date('Y-m-d H:i:s', strtotime($row['timestamp'])); ?></td>
                                        <td><span class="badge bg-<?php echo $distanceStatus == 'Warning' ? 'warning' : 'success'; ?>"><?php echo $distanceStatus; ?></span></td>
                                    </tr>
                                    <?php
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php
        $this->renderFooter();
    }

    private function renderPhotoGallery() {
        $this->renderHeader('Photo Gallery');
        ?>
        
        <h2 class="mb-4"><i class="fas fa-images"></i> Photo Gallery</h2>
        
        <div class="photo-grid" id="photoGrid">
            <?php
            $result = $this->db->query("SELECT * FROM photos ORDER BY timestamp DESC LIMIT 12");
            if ($result) {
                while ($photo = $result->fetch_assoc()) {
                    ?>
                    <div class="photo-card">
                        <img src="<?php echo $photo['filepath']; ?>" alt="Security Photo" loading="lazy">
                        <div class="photo-info">
                            <p class="mb-1"><small class="text-muted">
                                <i class="far fa-clock"></i> <?php echo date('Y-m-d H:i:s', strtotime($photo['timestamp'])); ?>
                            </small></p>
                            <p class="mb-2"><small class="text-muted">
                                <i class="fas fa-file"></i> <?php echo $photo['size_kb']; ?> KB
                            </small></p>
                            <div class="btn-group">
                                <a href="<?php echo $photo['filepath']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <button class="btn btn-sm btn-danger" onclick="deletePhoto(<?php echo $photo['id']; ?>)">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php
                }
            }
            ?>
        </div>
        
        <?php if ($result && $result->num_rows == 0): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No photos captured yet. Photos will appear here when the security system detects objects.
            </div>
        <?php endif; ?>

        <?php
        $this->renderFooter();
    }

    private function renderGSMAlerts() {
        $this->renderHeader('GSM Alerts');
        ?>
        
        <h2 class="mb-4"><i class="fas fa-sms"></i> GSM Alerts</h2>
        
        <button class="btn btn-warning mb-3" onclick="sendTestAlert()">
            <i class="fas fa-paper-plane"></i> Send Test Alert
        </button>
        
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Phone Number</th>
                                <th>Message</th>
                                <th>Status</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $result = $this->db->query("SELECT * FROM gsm_alerts ORDER BY timestamp DESC");
                            if ($result) {
                                while ($alert = $result->fetch_assoc()) {
                                    $statusClass = 'bg-secondary';
                                    switch ($alert['status']) {
                                        case 'sent': $statusClass = 'bg-success'; break;
                                        case 'failed': $statusClass = 'bg-danger'; break;
                                        case 'delivered': $statusClass = 'bg-primary'; break;
                                    }
                                    ?>
                                    <tr>
                                        <td><?php echo $alert['id']; ?></td>
                                        <td><?php echo htmlspecialchars($alert['phone_number']); ?></td>
                                        <td><?php echo htmlspecialchars($alert['message']); ?></td>
                                        <td><span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst($alert['status']); ?></span></td>
                                        <td><?php echo date('Y-m-d H:i:s', strtotime($alert['timestamp'])); ?></td>
                                    </tr>
                                    <?php
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php
        $this->renderFooter();
    }

    private function getStatistics() {
        $stats = [
            'today_events' => 0,
            'today_detections' => 0,
            'latest_distance' => 0,
            'object_detected' => 0,
            'total_photos' => 0,
            'today_alerts' => 0
        ];

        $result = $this->db->query("SELECT COUNT(*) as total FROM distance_logs WHERE DATE(timestamp) = CURDATE()");
        if ($row = $result->fetch_assoc()) $stats['today_events'] = $row['total'];

        $result = $this->db->query("SELECT COUNT(*) as total FROM distance_logs WHERE object_detected = 1 AND DATE(timestamp) = CURDATE()");
        if ($row = $result->fetch_assoc()) $stats['today_detections'] = $row['total'];

        $result = $this->db->query("SELECT distance_cm, object_detected FROM distance_logs ORDER BY timestamp DESC LIMIT 1");
        if ($row = $result->fetch_assoc()) {
            $stats['latest_distance'] = $row['distance_cm'];
            $stats['object_detected'] = $row['object_detected'];
        }

        $result = $this->db->query("SELECT COUNT(*) as total FROM photos");
        if ($row = $result->fetch_assoc()) $stats['total_photos'] = $row['total'];

        $result = $this->db->query("SELECT COUNT(*) as total FROM gsm_alerts WHERE DATE(timestamp) = CURDATE()");
        if ($row = $result->fetch_assoc()) $stats['today_alerts'] = $row['total'];

        return $stats;
    }
}
try {
    $dashboard = new SecurityDashboard();
    $dashboard->render();
} catch (Exception $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    die("System Error: " . $e->getMessage());
}
?>