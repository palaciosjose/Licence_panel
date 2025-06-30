<?php
/**
 * Sistema de Rate Limiting para API de Licencias
 * Previene abuso y ataques DDoS
 */

class RateLimiter {
    private static $dataFile = "rate_limits.json";
    private static $defaultLimits = [
        "validate" => ["requests" => 100, "window" => 3600], // 100 req/hora
        "activate" => ["requests" => 10, "window" => 3600],  // 10 req/hora
        "verify" => ["requests" => 200, "window" => 3600],   // 200 req/hora
        "default" => ["requests" => 50, "window" => 3600]    // 50 req/hora por defecto
    ];
    
    public static function checkLimit($ip, $action = "default") {
        $limits = self::$defaultLimits[$action] ?? self::$defaultLimits["default"];
        $maxRequests = $limits["requests"];
        $timeWindow = $limits["window"];
        
        $data = self::loadData();
        $now = time();
        $windowStart = $now - $timeWindow;
        
        // Limpiar datos antiguos
        if (isset($data[$ip][$action])) {
            $data[$ip][$action] = array_filter(
                $data[$ip][$action], 
                function($timestamp) use ($windowStart) {
                    return $timestamp > $windowStart;
                }
            );
        }
        
        // Contar requests en la ventana actual
        $currentRequests = count($data[$ip][$action] ?? []);
        
        if ($currentRequests >= $maxRequests) {
            self::logRateLimit($ip, $action, $currentRequests, $maxRequests);
            return false;
        }
        
        // Registrar request actual
        $data[$ip][$action][] = $now;
        self::saveData($data);
        
        return true;
    }
    
    public static function getRemainingRequests($ip, $action = "default") {
        $limits = self::$defaultLimits[$action] ?? self::$defaultLimits["default"];
        $maxRequests = $limits["requests"];
        $timeWindow = $limits["window"];
        
        $data = self::loadData();
        $now = time();
        $windowStart = $now - $timeWindow;
        
        if (!isset($data[$ip][$action])) {
            return $maxRequests;
        }
        
        $recentRequests = array_filter(
            $data[$ip][$action], 
            function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            }
        );
        
        return max(0, $maxRequests - count($recentRequests));
    }
    
    private static function loadData() {
        if (!file_exists(self::$dataFile)) {
            return [];
        }
        
        $content = file_get_contents(self::$dataFile);
        return json_decode($content, true) ?: [];
    }
    
    private static function saveData($data) {
        file_put_contents(self::$dataFile, json_encode($data), LOCK_EX);
    }
    
    private static function logRateLimit($ip, $action, $current, $max) {
        $logEntry = "[" . date("Y-m-d H:i:s") . "] RATE_LIMIT: IP $ip exceeded limit for $action ($current/$max)\n";
        file_put_contents("rate_limit.log", $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    public static function getBlockedIPs($hours = 24) {
        $logFile = "rate_limit.log";
        if (!file_exists($logFile)) {
            return [];
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $blocked = [];
        $cutoff = time() - ($hours * 3600);
        
        foreach ($lines as $line) {
            if (preg_match("/\[(.*?)\].*IP ([\d\.]+)/", $line, $matches)) {
                $timestamp = strtotime($matches[1]);
                $ip = $matches[2];
                
                if ($timestamp > $cutoff) {
                    $blocked[$ip] = ($blocked[$ip] ?? 0) + 1;
                }
            }
        }
        
        return $blocked;
    }
}
