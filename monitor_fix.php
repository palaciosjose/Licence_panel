<?php
/**
 * Corrección específica para el monitor de sistema
 * Mejora los cálculos de métricas del sistema
 */

header("Content-Type: application/json");

// Función mejorada para calcular uso de memoria
function getImprovedMemoryUsage() {
    $memoryLimit = ini_get("memory_limit");
    
    // Si el límite es -1 (sin límite), usar memoria del sistema
    if ($memoryLimit === "-1") {
        // En sistemas Unix, intentar obtener memoria del sistema
        if (function_exists("sys_getloadavg") && is_readable("/proc/meminfo")) {
            $meminfo = file_get_contents("/proc/meminfo");
            if (preg_match("/MemTotal:\s+(\d+) kB/", $meminfo, $matches)) {
                $totalMem = $matches[1] * 1024; // Convertir a bytes
                $usedMem = memory_get_usage(true);
                return min(100, round(($usedMem / $totalMem) * 100, 2));
            }
        }
        return 0; // No se puede determinar
    }
    
    // Convertir memory_limit a bytes
    $memoryLimitBytes = convertToBytes($memoryLimit);
    $memoryUsed = memory_get_usage(true);
    
    if ($memoryLimitBytes <= 0) {
        return 0;
    }
    
    $percentage = ($memoryUsed / $memoryLimitBytes) * 100;
    return min(100, round($percentage, 2));
}

function convertToBytes($value) {
    $value = trim($value);
    $number = (int) $value;
    $unit = strtolower(substr($value, -1));
    
    switch ($unit) {
        case "g": return $number * 1024 * 1024 * 1024;
        case "m": return $number * 1024 * 1024;
        case "k": return $number * 1024;
        default: return $number;
    }
}

// Función mejorada para obtener uso de disco
function getImprovedDiskUsage() {
    $freeBytes = disk_free_space(".");
    $totalBytes = disk_total_space(".");
    
    if (!$freeBytes || !$totalBytes || $totalBytes <= 0) {
        return 0;
    }
    
    $usedBytes = $totalBytes - $freeBytes;
    return round(($usedBytes / $totalBytes) * 100, 2);
}

// Verificar estado de la base de datos
function checkDatabaseStatus() {
    try {
        $config = [
            "host" => "localhost",
            "username" => "warsup_sdcode",
            "password" => "warsup_sdcode",
            "database" => "warsup_sdcode"
        ];
        
        $conn = new mysqli($config["host"], $config["username"], $config["password"], $config["database"]);
        
        if ($conn->connect_error) {
            return ["status" => "Error", "error" => $conn->connect_error];
        }
        
        // Probar una consulta simple
        $result = $conn->query("SELECT 1");
        if (!$result) {
            return ["status" => "Error", "error" => "Query failed"];
        }
        
        $conn->close();
        return ["status" => "Activa"];
        
    } catch (Exception $e) {
        return ["status" => "Error", "error" => $e->getMessage()];
    }
}

$action = $_GET["action"] ?? "status";

switch ($action) {
    case "status":
        $response = [
            "success" => true,
            "health" => [
                "status" => "healthy",
                "timestamp" => date("Y-m-d H:i:s")
            ],
            "rate_limits" => [
                "remaining" => 50, // Valor por defecto
                "blocked_ips" => []
            ]
        ];
        break;
        
    case "system_stats":
        $stats = [
            "disk_usage" => getImprovedDiskUsage(),
            "memory_usage" => getImprovedMemoryUsage(),
            "php_version" => PHP_VERSION,
            "server_time" => date("Y-m-d H:i:s"),
            "database" => checkDatabaseStatus()
        ];
        
        $response = [
            "success" => true,
            "stats" => $stats
        ];
        break;
        
    default:
        $response = [
            "success" => false,
            "error" => "Invalid action"
        ];
        break;
}

echo json_encode($response);
?>