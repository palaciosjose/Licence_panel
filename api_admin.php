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
