<?php
/**
 * Sistema de Monitoreo Automático para Licencias
 * Detecta problemas y envía alertas
 */

class LicenseMonitor {
    private static $alertsFile = "monitor_alerts.json";
    private static $thresholds = [
        "error_rate" => 10,        // % máximo de errores por hora
        "response_time" => 5000,   // ms máximo de respuesta
        "memory_usage" => 80,      // % máximo de memoria
        "disk_space" => 90         // % máximo de uso de disco
    ];
    
    public static function checkSystemHealth() {
        $health = [
            "timestamp" => date("Y-m-d H:i:s"),
            "status" => "healthy",
            "alerts" => []
        ];
        
        // Verificar tasa de errores
        $errorRate = self::calculateErrorRate();
        if ($errorRate > self::$thresholds["error_rate"]) {
            $health["alerts"][] = [
                "type" => "high_error_rate",
                "message" => "Tasa de errores alta: {$errorRate}%",
                "severity" => "critical"
            ];
            $health["status"] = "unhealthy";
        }
        
        // Verificar espacio en disco
        $diskUsage = self::getDiskUsage();
        if ($diskUsage > self::$thresholds["disk_space"]) {
            $health["alerts"][] = [
                "type" => "low_disk_space",
                "message" => "Poco espacio en disco: {$diskUsage}%",
                "severity" => "warning"
            ];
            if ($health["status"] !== "unhealthy") {
                $health["status"] = "warning";
            }
        }
        
        // Verificar memoria
        $memoryUsage = self::getMemoryUsage();
        if ($memoryUsage > self::$thresholds["memory_usage"]) {
            $health["alerts"][] = [
                "type" => "high_memory_usage",
                "message" => "Uso alto de memoria: {$memoryUsage}%",
                "severity" => "warning"
            ];
            if ($health["status"] !== "unhealthy") {
                $health["status"] = "warning";
            }
        }
        
        // Verificar base de datos
        $dbStatus = self::checkDatabaseHealth();
        if (!$dbStatus["healthy"]) {
            $health["alerts"][] = [
                "type" => "database_issue",
                "message" => "Problema con base de datos: " . $dbStatus["error"],
                "severity" => "critical"
            ];
            $health["status"] = "unhealthy";
        }
        
        return $health;
    }
    
    private static function calculateErrorRate() {
        $logFile = "license_api.log";
        if (!file_exists($logFile)) {
            return 0;
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $hourAgo = time() - 3600;
        $totalRequests = 0;
        $errorRequests = 0;
        
        foreach ($lines as $line) {
            if (preg_match("/\[(.*?)\]/", $line, $matches)) {
                $timestamp = strtotime($matches[1]);
                if ($timestamp > $hourAgo) {
                    $totalRequests++;
                    if (strpos($line, "[ERROR]") !== false) {
                        $errorRequests++;
                    }
                }
            }
        }
        
        return $totalRequests > 0 ? round(($errorRequests / $totalRequests) * 100, 2) : 0;
    }
    
    private static function getDiskUsage() {
        $freeBytes = disk_free_space(".");
        $totalBytes = disk_total_space(".");
        return round((($totalBytes - $freeBytes) / $totalBytes) * 100, 2);
    }
    
    private static function getMemoryUsage() {
        $memoryLimit = ini_get("memory_limit");
        $memoryLimit = self::convertToBytes($memoryLimit);
        $memoryUsed = memory_get_usage(true);
        
        return round(($memoryUsed / $memoryLimit) * 100, 2);
    }
    
    private static function convertToBytes($value) {
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        
        switch ($unit) {
            case "g": return $number * 1024 * 1024 * 1024;
            case "m": return $number * 1024 * 1024;
            case "k": return $number * 1024;
            default: return $number;
        }
    }
    
    private static function checkDatabaseHealth() {
        try {
            $config = [
                "host" => "localhost",
                "username" => "warsup_sdcode",
                "password" => "warsup_sdcode",
                "database" => "warsup_sdcode"
            ];
            
            $conn = new mysqli($config["host"], $config["username"], $config["password"], $config["database"]);
            
            if ($conn->connect_error) {
                return ["healthy" => false, "error" => $conn->connect_error];
            }
            
            // Probar una consulta simple
            $result = $conn->query("SELECT 1");
            if (!$result) {
                return ["healthy" => false, "error" => "Query test failed"];
            }
            
            $conn->close();
            return ["healthy" => true];
            
        } catch (Exception $e) {
            return ["healthy" => false, "error" => $e->getMessage()];
        }
    }
    
    public static function sendAlert($alert) {
        $alertLog = [
            "timestamp" => date("Y-m-d H:i:s"),
            "alert" => $alert
        ];
        
        $alertsData = [];
        if (file_exists(self::$alertsFile)) {
            $alertsData = json_decode(file_get_contents(self::$alertsFile), true) ?: [];
        }
        
        $alertsData[] = $alertLog;
        
        // Mantener solo las últimas 100 alertas
        if (count($alertsData) > 100) {
            $alertsData = array_slice($alertsData, -100);
        }
        
        file_put_contents(self::$alertsFile, json_encode($alertsData), LOCK_EX);
    }
    
    public static function getRecentAlerts($hours = 24) {
        if (!file_exists(self::$alertsFile)) {
            return [];
        }
        
        $alertsData = json_decode(file_get_contents(self::$alertsFile), true) ?: [];
        $cutoff = time() - ($hours * 3600);
        
        return array_filter($alertsData, function($alert) use ($cutoff) {
            return strtotime($alert["timestamp"]) > $cutoff;
        });
    }
}
