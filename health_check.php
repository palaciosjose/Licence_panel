<?php
/**
 * Health Check para Sistema de Licencias
 */

header("Content-Type: application/json");

$health = [
    "status" => "healthy",
    "timestamp" => date("Y-m-d H:i:s"),
    "checks" => []
];

// Verificar archivos requeridos
$requiredFiles = [
    "LicenseManager.class.php",
    "whatsapp_config.php",
    "api.php",
    "Psnel_administracion.php"
];

foreach ($requiredFiles as $file) {
    $health["checks"]["files"][$file] = file_exists($file);
    if (!file_exists($file)) {
        $health["status"] = "unhealthy";
    }
}

// Verificar base de datos
try {
    if (file_exists("whatsapp_config.php")) {
        require_once "whatsapp_config.php";
    }
    
    $license_db_config = [
        "host" => "localhost",
        "username" => "warsup_sdcode",
        "password" => "warsup_sdcode",
        "database" => "warsup_sdcode"
    ];
    
    $conn = new mysqli(
        $license_db_config["host"],
        $license_db_config["username"],
        $license_db_config["password"],
        $license_db_config["database"]
    );
    
    if ($conn->connect_error) {
        $health["checks"]["database"] = false;
        $health["status"] = "unhealthy";
        $health["database_error"] = $conn->connect_error;
    } else {
        $health["checks"]["database"] = true;
        
        // Verificar tablas principales
        $tables = ["licenses", "license_activations", "license_logs"];
        foreach ($tables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            $health["checks"]["tables"][$table] = ($result && $result->num_rows > 0);
            if (!$health["checks"]["tables"][$table]) {
                $health["status"] = "unhealthy";
            }
        }
        
        $conn->close();
    }
} catch (Exception $e) {
    $health["checks"]["database"] = false;
    $health["status"] = "unhealthy";
    $health["database_error"] = $e->getMessage();
}

// Verificar espacio en disco
$freeBytes = disk_free_space(".");
$totalBytes = disk_total_space(".");
$freePercentage = ($freeBytes / $totalBytes) * 100;

$health["checks"]["disk_space"] = [
    "free_percentage" => round($freePercentage, 2),
    "status" => $freePercentage > 10 ? "ok" : "warning"
];

if ($freePercentage < 5) {
    $health["status"] = "unhealthy";
}

echo json_encode($health, JSON_PRETTY_PRINT);
