<?php
/**
 * LicenseManager Mejorado con Manejo de Errores
 * Version: 1.3 - Con manejo robusto de errores y logs
 */

class LicenseManager {
    private $conn;
    private $whatsapp_config = null;
    private static $logFile = "license_system.log";

    public function __construct($db_config, $whatsapp_config = null) {
        try {
            // Validar configuración
            if (!isset($db_config['host']) || !isset($db_config['username']) || 
                !isset($db_config['password']) || !isset($db_config['database'])) {
                throw new Exception("Configuración de base de datos incompleta");
            }

            // Intentar conexión
            $this->conn = new mysqli(
                $db_config['host'],
                $db_config['username'],
                $db_config['password'],
                $db_config['database']
            );

            if ($this->conn->connect_error) {
                $this->logError("Database connection failed", [
                    'host' => $db_config['host'],
                    'username' => $db_config['username'],
                    'error' => $this->conn->connect_error
                ]);
                throw new Exception("Error de conexión a la base de datos: " . $this->conn->connect_error);
            }

            $this->conn->set_charset("utf8mb4");
            $this->whatsapp_config = $whatsapp_config;
            
            $this->logInfo("LicenseManager initialized successfully");

        } catch (mysqli_sql_exception $e) {
            $this->logError("MySQL Exception during initialization", [
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            throw new Exception("Error de conexión MySQL: " . $e->getMessage());
        } catch (Exception $e) {
            $this->logError("General exception during initialization", [
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function logError($message, $context = []) {
        $this->log("ERROR", $message, $context);
    }

    private function logInfo($message, $context = []) {
        $this->log("INFO", $message, $context);
    }

    private function log($level, $message, $context = []) {
        $timestamp = date("Y-m-d H:i:s");
        $contextStr = $context ? " | Context: " . json_encode($context) : "";
        $logEntry = "[$timestamp] [$level] $message$contextStr\n";
        
        @file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public function getDbConnection() {
        if (!$this->conn || $this->conn->ping() === false) {
            throw new Exception("Base de datos no disponible");
        }
        return $this->conn;
    }

    // ... resto de métodos de la clase original ...
}
