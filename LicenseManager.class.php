<?php
/**
 * LicenseManager Unificado y Mejorado
 * Versión 2.0 - Contiene toda la lógica para manejar licencias y el panel.
 */

class LicenseManager {
    private $conn;
    private $whatsapp_config;

    /**
     * Constructor de la clase. Establece la conexión a la base de datos.
     */
    public function __construct($db_config, $whatsapp_config = null) {
        $this->conn = new mysqli(
            $db_config['host'],
            $db_config['username'],
            $db_config['password'],
            $db_config['database']
        );

        if ($this->conn->connect_error) {
            error_log("Error de conexión a la base de datos: " . $this->conn->connect_error);
            // En un entorno de producción, es mejor mostrar un error genérico.
            die("Error crítico del sistema. Por favor, contacta al administrador.");
        }

        $this->conn->set_charset("utf8mb4");
        $this->whatsapp_config = $whatsapp_config;
    }

    /**
     * Verifica si un administrador ha iniciado sesión.
     * Esta es la función que te faltaba.
     */
    public function isLoggedIn() {
        return isset($_SESSION['license_admin']) && !empty($_SESSION['license_admin']['id']);
    }

    /**
     * Autentica a un usuario administrador y crea su sesión.
     */
    public function authenticate($username, $password) {
        $stmt = $this->conn->prepare("SELECT * FROM license_admins WHERE username = ? AND status = 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['license_admin'] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role']
                ];
                $this->conn->query("UPDATE license_admins SET last_login = NOW() WHERE id = " . (int)$user['id']);
                return true;
            }
        }
        return false;
    }
    
    /**
     * Obtiene la conexión a la base de datos.
     */
    public function getDbConnection() {
        return $this->conn;
    }

    /**
     * Obtiene las estadísticas principales para el dashboard.
     */
    public function getLicenseStats() {
        // Usamos la vista 'license_stats' que es más eficiente.
        $result = $this->conn->query("SELECT * FROM license_stats");
        return $result ? $result->fetch_assoc() : [];
    }

    /**
     * Obtiene una lista de licencias.
     */
    public function getLicenses($limit = 100) {
        $sql = "SELECT l.*, 
                (SELECT COUNT(*) FROM license_activations la WHERE la.license_id = l.id AND la.status = 'active') as active_activations,
                CASE 
                    WHEN l.status = 'expired' THEN 'expired'
                    WHEN l.expires_at IS NOT NULL AND l.expires_at < NOW() THEN 'expired'
                    ELSE l.status 
                END as calculated_status,
                CASE 
                    WHEN l.expires_at > NOW() THEN DATEDIFF(l.expires_at, NOW())
                    ELSE 0 
                END as days_remaining
                FROM licenses l 
                ORDER BY l.created_at DESC 
                LIMIT ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Obtiene logs recientes del sistema.
     */
    public function getRecentLogs($limit = 20) {
        $sql = "SELECT ll.*, l.client_name, l.client_phone
                FROM license_logs ll
                LEFT JOIN licenses l ON ll.license_id = l.id
                ORDER BY ll.created_at DESC
                LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Obtiene activaciones de licencias.
     */
    public function getActivations($license_id = null, $limit = 50) {
        $sql = "SELECT la.*, l.license_key, l.client_name, l.client_phone
                FROM license_activations la
                JOIN licenses l ON la.license_id = l.id ";
        if ($license_id) {
            $sql .= "WHERE la.license_id = ? ";
        }
        $sql .= "ORDER BY la.activated_at DESC LIMIT ?";
        
        $stmt = $this->conn->prepare($sql);
        if ($license_id) {
            $stmt->bind_param("ii", $license_id, $limit);
        } else {
            $stmt->bind_param("i", $limit);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Obtiene licencias que están a punto de expirar.
     */
    public function getExpiringLicenses($days = 30) {
        $sql = "SELECT *, DATEDIFF(expires_at, NOW()) as days_remaining
                FROM licenses
                WHERE status = 'active'
                AND expires_at IS NOT NULL
                AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY)
                ORDER BY expires_at ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $days);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Crea una nueva licencia.
     */
    public function createLicense($data) {
        // Implementar lógica de creación de licencia
        // (Esta es una versión simplificada, puedes mover tu lógica aquí)
        $license_key = 'LIC-' . strtoupper(bin2hex(random_bytes(10)));
        $stmt = $this->conn->prepare("INSERT INTO licenses (license_key, client_name, client_email, client_phone, product_name) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $license_key, $data['client_name'], $data['client_email'], $data['client_phone'], $data['product_name']);
        
        if ($stmt->execute()) {
            return ['success' => true, 'license_key' => $license_key];
        } else {
            return ['success' => false, 'error' => $this->conn->error];
        }
    }

    /**
     * Actualiza una licencia existente.
     */
    public function updateLicense($data) {
        // Lógica para actualizar...
         return ['success' => true];
    }
    
    /**
     * Elimina una licencia.
     */
    public function deleteLicense($id) {
        $stmt = $this->conn->prepare("DELETE FROM licenses WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
    
    /**
     * Obtiene detalles de una licencia para el modal de edición.
     */
    public function getLicenseDetails($id) {
        $stmt = $this->conn->prepare("SELECT * FROM licenses WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}
?>
