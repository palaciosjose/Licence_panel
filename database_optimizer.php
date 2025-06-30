<?php
/**
 * Optimizador de Base de Datos - Versión Web
 * Interfaz web para optimización de la base de datos
 */

// Verificar autenticación básica (opcional)
session_start();

// Si quieres proteger esta página, descomenta esto:
/*
if (!isset($_SESSION["license_admin"])) {
    header("Location: Psnel_administracion.php");
    exit;
}
*/

$action = $_GET["action"] ?? "";
$results = [];

if ($action === "optimize") {
    ob_start();
    
    class DatabaseOptimizerWeb {
        private $conn;
        private $results = [];
        
        public function __construct($db_config) {
            $this->conn = new mysqli(
                $db_config["host"],
                $db_config["username"],
                $db_config["password"],
                $db_config["database"]
            );
            
            if ($this->conn->connect_error) {
                throw new Exception("Database connection failed: " . $this->conn->connect_error);
            }
            
            $this->conn->set_charset("utf8mb4");
        }
        
        public function optimize() {
            $this->results[] = "🔧 Iniciando optimización de base de datos...";
            
            $this->cleanOldLogs();
            $this->optimizeTables();
            $this->updateStatistics();
            $this->checkIndexes();
            
            $this->results[] = "✅ Optimización completada exitosamente";
            return $this->results;
        }
        
        private function cleanOldLogs() {
            $this->results[] = "🧹 Limpiando logs antiguos...";
            
            try {
                // Mantener solo logs de los últimos 90 días
                $stmt = $this->conn->prepare("DELETE FROM license_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
                $stmt->execute();
                $deleted = $this->conn->affected_rows;
                $this->results[] = "  ✅ Eliminados $deleted registros de logs antiguos";
                
                // Limpiar activaciones inactivas antiguas
                $stmt = $this->conn->prepare("DELETE FROM license_activations WHERE status = ? AND activated_at < DATE_SUB(NOW(), INTERVAL 180 DAY)");
                $stmt->bind_param("s", $inactive = "inactive");
                $stmt->execute();
                $deleted = $this->conn->affected_rows;
                $this->results[] = "  ✅ Eliminadas $deleted activaciones inactivas antiguas";
                
            } catch (Exception $e) {
                $this->results[] = "  ❌ Error limpiando logs: " . $e->getMessage();
            }
        }
        
        private function optimizeTables() {
            $this->results[] = "⚡ Optimizando tablas...";
            
            $tables = ["licenses", "license_activations", "license_logs", "license_admins"];
            foreach ($tables as $table) {
                try {
                    $this->conn->query("OPTIMIZE TABLE $table");
                    $this->results[] = "  ✅ Tabla $table optimizada";
                } catch (Exception $e) {
                    $this->results[] = "  ⚠️ Error optimizando $table: " . $e->getMessage();
                }
            }
        }
        
        private function updateStatistics() {
            $this->results[] = "📊 Actualizando estadísticas...";
            
            $tables = ["licenses", "license_activations", "license_logs", "license_admins"];
            foreach ($tables as $table) {
                try {
                    $this->conn->query("ANALYZE TABLE $table");
                    $this->results[] = "  ✅ Estadísticas de $table actualizadas";
                } catch (Exception $e) {
                    $this->results[] = "  ⚠️ Error analizando $table: " . $e->getMessage();
                }
            }
        }
        
        private function checkIndexes() {
            $this->results[] = "🔍 Verificando índices...";
            
            $indexes = [
                "license_logs" => [
                    "idx_created_at" => "CREATE INDEX idx_created_at ON license_logs(created_at)",
                    "idx_license_action" => "CREATE INDEX idx_license_action ON license_logs(license_id, action)",
                    "idx_ip_address" => "CREATE INDEX idx_ip_address ON license_logs(ip_address)"
                ],
                "license_activations" => [
                    "idx_domain_status" => "CREATE INDEX idx_domain_status ON license_activations(domain, status)",
                    "idx_last_check" => "CREATE INDEX idx_last_check ON license_activations(last_check)"
                ]
            ];
            
            foreach ($indexes as $table => $tableIndexes) {
                foreach ($tableIndexes as $indexName => $createSQL) {
                    try {
                        // Verificar si el índice ya existe
                        $result = $this->conn->query("SHOW INDEX FROM $table WHERE Key_name = \"$indexName\"");
                        if ($result && $result->num_rows == 0) {
                            $this->conn->query($createSQL);
                            $this->results[] = "  ✅ Índice $indexName creado en tabla $table";
                        } else {
                            $this->results[] = "  ℹ️ Índice $indexName ya existe en tabla $table";
                        }
                    } catch (Exception $e) {
                        $this->results[] = "  ⚠️ Error con índice $indexName: " . $e->getMessage();
                    }
                }
            }
        }
        
        public function getTableSizes() {
            $sql = "
                SELECT 
                    table_name,
                    table_rows,
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE()
                ORDER BY (data_length + index_length) DESC
            ";
            
            $result = $this->conn->query($sql);
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
    }
    
    // Configuración de la base de datos
    $db_config = [
        "host" => "localhost",
        "username" => "warsup_sdcode",
        "password" => "warsup_sdcode",
        "database" => "warsup_sdcode"
    ];
    
    try {
        $optimizer = new DatabaseOptimizerWeb($db_config);
        $results = $optimizer->optimize();
        
        $sizes = $optimizer->getTableSizes();
        $results[] = "";
        $results[] = "📊 Tamaños de tablas después de la optimización:";
        foreach ($sizes as $table) {
            $results[] = "  - {$table[\"table_name\"]}: {$table[\"table_rows\"]} filas, {$table[\"size_mb\"]} MB";
        }
        
        $success = true;
        
    } catch (Exception $e) {
        $results[] = "❌ Error en optimización: " . $e->getMessage();
        $success = false;
    }
    
    ob_end_clean();
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Optimizador de Base de Datos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .results-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            max-height: 500px;
            overflow-y: auto;
            font-family: "Courier New", monospace;
            white-space: pre-wrap;
        }
        .success { background: #d4edda; border-color: #c3e6cb; }
        .error { background: #f8d7da; border-color: #f5c6cb; }
    </style>
</head>
<body class="bg-light">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h3><i class="fas fa-database me-2"></i>Optimizador de Base de Datos</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($action === "optimize"): ?>
                            <div class="results-box <?= $success ? \"success\" : \"error\" ?>">
                                <?= implode("\n", $results) ?>
                            </div>
                            <div class="mt-3">
                                <a href="database_optimizer.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Volver
                                </a>
                                <a href="monitor_dashboard.php" class="btn btn-info">
                                    <i class="fas fa-chart-line me-2"></i>Ver Monitor
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="text-center">
                                <i class="fas fa-tools fa-4x text-primary mb-3"></i>
                                <h4>Optimización de Base de Datos</h4>
                                <p class="text-muted">
                                    Esta herramienta optimizará la base de datos del sistema de licencias:
                                </p>
                                <ul class="list-unstyled text-start">
                                    <li><i class="fas fa-check text-success me-2"></i>Limpia logs antiguos (>90 días)</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Optimiza todas las tablas</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Actualiza estadísticas</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Verifica y crea índices</li>
                                </ul>
                                
                                <div class="alert alert-warning" role="alert">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Importante:</strong> Esta operación puede tomar varios minutos dependiendo del tamaño de la base de datos.
                                </div>
                                
                                <a href="?action=optimize" class="btn btn-primary btn-lg">
                                    <i class="fas fa-play me-2"></i>Iniciar Optimización
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>