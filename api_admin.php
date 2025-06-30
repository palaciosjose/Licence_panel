<?php
/**
 * API Administrativo Mejorado con Rate Limiting y Monitoreo
 * Version: 2.0
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

session_start();

// Incluir clases necesarias
require_once "RateLimiter.class.php";
require_once "LicenseMonitor.class.php";

// Configuración de la base de datos
$license_db_config = [
    "host" => "localhost",
    "username" => "warsup_sdcode",
    "password" => "warsup_sdcode",
    "database" => "warsup_sdcode"
];

// Verificar rate limiting
$clientIP = $_SERVER["REMOTE_ADDR"] ?? "unknown";
$action = $_GET["action"] ?? $_POST["action"] ?? "default";

if (!RateLimiter::checkLimit($clientIP, $action)) {
    http_response_code(429);
    echo json_encode([
        "success" => false,
        "error" => "Rate limit exceeded",
        "retry_after" => 3600
    ]);
    exit;
}

// Incluir LicenseManager mejorado si existe, sino el original
if (file_exists("LicenseManager.improved.php")) {
    require_once "LicenseManager.improved.php";
    // Renombrar la clase si es necesario
    if (!class_exists("LicenseManager") && class_exists("ImprovedLicenseManager")) {
        class_alias("ImprovedLicenseManager", "LicenseManager");
    }
} else {
    require_once "LicenseManager.class.php";
}

if (file_exists("whatsapp_config.php")) {
    require_once "whatsapp_config.php";
} else {
    $whatsapp_config = ["enabled" => false];
}

try {
    $licenseManager = new LicenseManager($license_db_config, $whatsapp_config);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Database connection failed",
        "debug" => $e->getMessage()
    ]);
    exit;
}

// Verificar autenticación para acciones protegidas
$protectedActions = ["get_license_details", "update_license", "delete_license", "get_stats"];
if (in_array($action, $protectedActions)) {
    if (!isset($_SESSION["license_admin"]) || empty($_SESSION["license_admin"]["id"])) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "error" => "Authentication required"
        ]);
        exit;
    }
}

// Manejar acciones

    
function calculateMemoryUsage() {
    $memoryLimit = ini_get("memory_limit");
    
    // Convertir memory_limit a bytes
    if (preg_match("/^(\d+)(.)$/", $memoryLimit, $matches)) {
        $number = (int)$matches[1];
        $unit = strtolower($matches[2]);
        
        switch ($unit) {
            case "g": $memoryLimitBytes = $number * 1024 * 1024 * 1024; break;
            case "m": $memoryLimitBytes = $number * 1024 * 1024; break;
            case "k": $memoryLimitBytes = $number * 1024; break;
            default: $memoryLimitBytes = $number; break;
        }
    } else {
        $memoryLimitBytes = (int)$memoryLimit;
    }
    
    $memoryUsed = memory_get_usage(true);
    
    // Verificar que los valores sean válidos
    if ($memoryLimitBytes <= 0 || $memoryUsed <= 0) {
        return 0;
    }
    
    $percentage = ($memoryUsed / $memoryLimitBytes) * 100;
    
    // Limitar a un máximo de 100% para evitar valores absurdos
    return min(100, round($percentage, 2));
}

switch ($action) {
    case "monitor":
        $health = LicenseMonitor::checkSystemHealth();
        echo json_encode([
            "success" => true,
            "health" => $health,
            "rate_limits" => [
                "remaining" => RateLimiter::getRemainingRequests($clientIP, $action),
                "blocked_ips" => RateLimiter::getBlockedIPs(1)
            ]
        ]);
        break;
        
    case "alerts":
        $hours = (int)($_GET["hours"] ?? 24);
        $alerts = LicenseMonitor::getRecentAlerts($hours);
        echo json_encode([
            "success" => true,
            "alerts" => $alerts
        ]);
        break;
        
    case "rate_limit_status":
        echo json_encode([
            "success" => true,
            "remaining_requests" => RateLimiter::getRemainingRequests($clientIP, "default"),
            "blocked_ips" => RateLimiter::getBlockedIPs(1)
        ]);
        break;
        
    case "system_stats":
        $stats = [
            "disk_usage" => round((1 - disk_free_space(".") / disk_total_space(".")) * 100, 2),
            "memory_usage" => calculateMemoryUsage(),
            "php_version" => PHP_VERSION,
            "server_time" => date("Y-m-d H:i:s"),
            "uptime" => sys_getloadavg()
        ];
        
        echo json_encode([
            "success" => true,
            "stats" => $stats
        ]);
        break;
    
    case "get_verification_stats":
        $stats = $licenseManager->getVerificationStats();
        echo json_encode(["success" => true, "stats" => $stats]);
        break;

    case "get_recent_verifications":
        $limit = (int)($_GET['limit'] ?? 50);
        $status_filter = $_GET['status_filter'] ?? null;
        $verifications = $licenseManager->getRecentVerifications($limit, $status_filter);
        echo json_encode(["success" => true, "verifications" => $verifications]);
        break;

    case "get_live_activity":
        $minutes = (int)($_GET['minutes'] ?? 5);
        $activity = $licenseManager->getLiveActivity($minutes);
        echo json_encode(["success" => true, "activity" => $activity]);
        break;

    case "get_activations":
        $license_id = isset($_GET['license_id']) ? (int)$_GET['license_id'] : null;
        $limit = (int)($_GET['limit'] ?? 100);
        $activations = $licenseManager->getActivations($license_id, $limit);
        echo json_encode(["success" => true, "activations" => $activations]);
        break;
    case "get_license_details":
    $license_id = (int)($_GET['id'] ?? 0);
    if ($license_id <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "ID de licencia invlido."]);
        exit;
    }

    // Usamos el mtodo que ya existe en tu LicenseManager
    $license = $licenseManager->getLicenseDetails($license_id);

    if ($license) {
        echo json_encode(["success" => true, "license" => $license]);
    } else {
        http_response_code(404);
        echo json_encode(["success" => false, "error" => "Licencia no encontrada."]);
    }
    break;
    
    case "update_license":
    $data = json_decode(file_get_contents('php://input'), true);

    // **AQU01 EST09 LA L01NEA CLAVE DEL ARREGLO:**
    // Le decimos a la API que use el valor de 'edit_license_id' como 'id'.
    if (!empty($data['edit_license_id'])) {
        $data['id'] = $data['edit_license_id'];
    }

    if (empty($data) || empty($data['id'])) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "No se recibieron datos o falta el ID de la licencia."]);
        exit;
    }

    $result = $licenseManager->updateLicense($data);

    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(500);
        echo json_encode($result);
    }
    break;
    
    case "get_activation_details":
    $activation_id = (int)($_GET['id'] ?? 0);
    if ($activation_id <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "error" => "ID de activacin invlido."]);
        exit;
    }

    $activation = $licenseManager->getActivationDetails($activation_id);

    if ($activation) {
        echo json_encode(["success" => true, "activation" => $activation]);
    } else {
        http_response_code(404);
        echo json_encode(["success" => false, "error" => "Activacin no encontrada."]);
    }
    break;
    
    case "clear_old_logs":
    $result = $licenseManager->clearOldLogs();
    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode($result);
    }
    break;
        
    default:
        // Delegar a la API original para otras acciones
        if (method_exists($licenseManager, "handleAdminRequest")) {
            $licenseManager->handleAdminRequest();
        } else {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "error" => "Invalid action: " . $action
            ]);
        }
        break;
}
