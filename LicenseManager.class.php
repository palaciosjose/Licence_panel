<?php
/**
 * LicenseManager - Versión Final y Estable v4.0
 * Contiene toda la lógica centralizada y un manejo de fechas "blindado".
 */

class LicenseManager {
    private $conn;
    private $whatsapp_config;

    public function __construct($db_config, $whatsapp_config = null) {
        $this->conn = new mysqli(
            $db_config['host'],
            $db_config['username'],
            $db_config['password'],
            $db_config['database']
        );
        if ($this->conn->connect_error) {
            error_log("FATAL: Error de conexión a la base de datos: " . $this->conn->connect_error);
            die("Error crítico del sistema. Contacta al administrador.");
        }
        $this->conn->set_charset("utf8mb4");
        $this->whatsapp_config = $whatsapp_config;
    }

    /**
     * Función privada y "blindada" para obtener siempre una fecha válida.
     * Si la fecha de entrada es inválida o vacía, SIEMPRE usará la fecha actual.
     * Esta es la clave de la solución final.
     */
    private function _getValidDate($date_string) {
        if (!empty($date_string)) {
            $timestamp = strtotime($date_string);
            // strtotime devuelve false si el formato es irreconocible.
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }
        // Si la fecha está vacía o el formato es inválido, usa la fecha y hora actual como respaldo seguro.
        return date('Y-m-d H:i:s');
    }

    public function isLoggedIn() {
        return isset($_SESSION['license_admin']) && !empty($_SESSION['license_admin']['id']);
    }

    public function authenticate($username, $password) {
        $stmt = $this->conn->prepare("SELECT * FROM license_admins WHERE username = ? AND status = 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['license_admin'] = ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']];
                $this->conn->query("UPDATE license_admins SET last_login = NOW() WHERE id = " . (int)$user['id']);
                return true;
            }
        }
        return false;
    }
    
    public function getDbConnection() {
        return $this->conn;
    }

    public function getLicenseStats() {
        $result = $this->conn->query("SELECT * FROM license_stats");
        return $result ? $result->fetch_assoc() : [];
    }

    public function getLicenses($limit = 100) {
        $sql = "SELECT l.*, 
                (SELECT COUNT(*) FROM license_activations la WHERE la.license_id = l.id AND la.status = 'active') as active_activations,
                CASE WHEN l.status = 'expired' OR (l.expires_at IS NOT NULL AND l.expires_at < NOW()) THEN 'expired' ELSE l.status END as calculated_status,
                CASE WHEN l.expires_at > NOW() THEN DATEDIFF(l.expires_at, NOW()) ELSE 0 END as days_remaining
                FROM licenses l ORDER BY l.created_at DESC LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    public function getRecentLogs($limit = 20) {
        $sql = "SELECT ll.*, l.client_name, l.client_phone FROM license_logs ll LEFT JOIN licenses l ON ll.license_id = l.id ORDER BY ll.created_at DESC LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getActivations($license_id = null, $limit = 50) {
        $sql = "SELECT la.*, l.license_key, l.client_name, l.client_phone FROM license_activations la JOIN licenses l ON la.license_id = l.id ";
        if ($license_id) $sql .= "WHERE la.license_id = ? ";
        $sql .= "ORDER BY la.activated_at DESC LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        ($license_id) ? $stmt->bind_param("ii", $license_id, $limit) : $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getExpiringLicenses($days = 30) {
        $sql = "SELECT *, DATEDIFF(expires_at, NOW()) as days_remaining FROM licenses WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY) ORDER BY expires_at ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $days);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function createLicense($data) {
        $prefix = "LCSY";
        $groups = [];
        for ($i = 0; $i < 8; $i++) {
            $groups[] = strtoupper(bin2hex(random_bytes(2)));
        }
        $license_key = $prefix . '-' . implode('-', $groups);

        $client_name = $data['client_name'] ?? 'N/A';
        $client_email = $data['client_email'] ?? 'N/A';
        $client_phone = $data['client_phone'] ?? null;
        $product_name = $data['product_name'] ?? 'Sistema de Códigos';
        $version = $data['version'] ?? '1.0';
        $license_type = $data['license_type'] ?? 'single';
        $max_domains = (int)($data['max_domains'] ?? 1);
        $notes = $data['notes'] ?? '';

        // Usar la nueva función de fecha segura
        $start_date = $this->_getValidDate($data['start_date'] ?? null);

        $duration_days = null;
        if (isset($data['duration_days']) && $data['duration_days'] !== '' && $data['duration_days'] !== 'custom') {
            $duration_days = (int)$data['duration_days'];
        } elseif (($data['duration_days'] ?? '') === 'custom' && !empty($data['custom_duration'])) {
            $duration_days = (int)$data['custom_duration'];
        }
        
        $expires_at = $duration_days ? date('Y-m-d H:i:s', strtotime($start_date . " +$duration_days days")) : null;

        $sql = "INSERT INTO licenses (license_key, client_name, client_email, client_phone, product_name, version, license_type, max_domains, notes, start_date, duration_days, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        // Correct type binding: start_date is a string and duration_days is integer
        $stmt->bind_param("sssssssissis", $license_key, $client_name, $client_email, $client_phone, $product_name, $version, $license_type, $max_domains, $notes, $start_date, $duration_days, $expires_at);

        if ($stmt->execute()) {
            return ['success' => true, 'license_key' => $license_key, 'start_date' => $start_date, 'expires_at' => $expires_at];
        } else {
            return ['success' => false, 'error' => 'Error de base de datos: ' . $this->conn->error];
        }
    }

    public function updateLicense($data) {
        if (empty($data['id'])) return ['success' => false, 'error' => 'ID de licencia no proporcionado.'];
        $license_id = (int)$data['id'];

        $client_name = $data['client_name'] ?? 'N/A';
        $client_email = $data['client_email'] ?? 'N/A';
        $client_phone = $data['client_phone'] ?? null;
        $product_name = $data['product_name'] ?? 'Sistema de Códigos';
        $version = $data['version'] ?? '1.0';
        $license_type = $data['license_type'] ?? 'single';
        $max_domains = (int)($data['max_domains'] ?? 1);
        $status = $data['status'] ?? 'active';
        $notes = $data['notes'] ?? '';

        // Usar la nueva función de fecha segura
        $start_date = $this->_getValidDate($data['start_date'] ?? null);
        
        $duration_days = null;
        if (isset($data['duration_days']) && $data['duration_days'] !== '' && $data['duration_days'] !== 'custom') {
            $duration_days = (int)$data['duration_days'];
        } elseif (($data['duration_days'] ?? '') === 'custom' && !empty($data['custom_duration'])) {
            $duration_days = (int)$data['custom_duration'];
        }
        
        $expires_at = $duration_days ? date('Y-m-d H:i:s', strtotime($start_date . " +$duration_days days")) : null;

        $sql = "UPDATE licenses SET client_name = ?, client_email = ?, client_phone = ?, product_name = ?, version = ?, license_type = ?, max_domains = ?, status = ?, notes = ?, start_date = ?, duration_days = ?, expires_at = ? WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssssssisssisi", $client_name, $client_email, $client_phone, $product_name, $version, $license_type, $max_domains, $status, $notes, $start_date, $duration_days, $expires_at, $license_id);

        if ($stmt->execute()) {
            return ['success' => true, 'expires_at' => $expires_at];
        } else {
            return ['success' => false, 'error' => 'Error de base de datos: ' . $this->conn->error];
        }
    }

    public function deleteLicense($id) {
        if (empty($id) || !is_numeric($id)) return ['success' => false, 'error' => 'ID de licencia inválido.'];
        $stmt = $this->conn->prepare("DELETE FROM licenses WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            return ($stmt->affected_rows > 0) ? ['success' => true] : ['success' => false, 'error' => 'No se encontró la licencia para eliminar.'];
        }
        return ['success' => false, 'error' => $this->conn->error];
    }

    public function getLicenseDetails($id) {
        $stmt = $this->conn->prepare("SELECT * FROM licenses WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getActivationDetails($activation_id) {
        if (empty($activation_id) || !is_numeric($activation_id)) return null;
        $sql = "SELECT la.*, l.license_key, l.client_name, l.product_name, l.version, l.expires_at FROM license_activations la JOIN licenses l ON la.license_id = l.id WHERE la.id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $activation_id);
        $stmt->execute();
        $activation = $stmt->get_result()->fetch_assoc();
        if (!$activation) return null;
        $sql_logs = "SELECT created_at, status, message FROM license_logs WHERE activation_id = ? ORDER BY created_at DESC LIMIT 10";
        $stmt_logs = $this->conn->prepare($sql_logs);
        $stmt_logs->bind_param("i", $activation_id);
        $stmt_logs->execute();
        $activation['verification_history'] = $stmt_logs->get_result()->fetch_all(MYSQLI_ASSOC);
        return $activation;
    }

    // ===========================================
    // MÉTODOS PARA EL DASHBOARD DE VERIFICACIONES
    // ===========================================

    /**
     * Obtiene estadísticas agregadas de verificaciones.
     * @return array
     */
    public function getVerificationStats() {
        $sql = "
            SELECT
                COUNT(*) as total_verifications,
                COUNT(CASE WHEN created_at >= NOW() - INTERVAL 1 HOUR THEN 1 END) as verifications_1h,
                COUNT(CASE WHEN created_at >= NOW() - INTERVAL 24 HOUR THEN 1 END) as verifications_24h,
                COUNT(CASE WHEN created_at >= NOW() - INTERVAL 7 DAY THEN 1 END) as verifications_7d
            FROM license_logs
            WHERE action = 'verification'";
        $result = $this->conn->query($sql);
        return $result->fetch_assoc();
    }

    /**
     * Obtiene una lista de verificaciones recientes con detalles de licencia y activación.
     * @param int $limit Número máximo de registros a retornar.
     * @param string|null $status_filter Filtra por 'success' o 'failure'.
     * @return array
     */
    public function getRecentVerifications($limit = 50, $status_filter = null) {
        $sql = "
            SELECT ll.*, l.client_name, l.client_phone, la.domain
            FROM license_logs ll
            LEFT JOIN licenses l ON ll.license_id = l.id
            LEFT JOIN license_activations la ON ll.activation_id = la.id
            WHERE ll.action = 'verification'";

        $params = [];
        $types = '';
        if ($status_filter) {
            $sql .= " AND ll.status = ?";
            $params[] = $status_filter;
            $types .= 's';
        }

        $sql .= " ORDER BY ll.created_at DESC LIMIT ?";
        $params[] = $limit;
        $types .= 'i';

        $stmt = $this->conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Obtiene la actividad en un período reciente de tiempo (ej. últimos minutos).
     * @param int $minutes Duración en minutos hacia atrás.
     * @return array
     */
    public function getLiveActivity($minutes = 5) {
        $sql = "
            SELECT ll.*, l.client_name, l.client_phone, la.domain
            FROM license_logs ll
            LEFT JOIN licenses l ON ll.license_id = l.id
            LEFT JOIN license_activations la ON ll.activation_id = la.id
            WHERE ll.created_at >= NOW() - INTERVAL ? MINUTE
            ORDER BY ll.created_at DESC
            LIMIT 20";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $minutes);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function clearOldLogs() {
        $days_old = 90;
        $sql = "DELETE FROM license_logs WHERE created_at < NOW() - INTERVAL ? DAY";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $days_old);
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Logs antiguos eliminados exitosamente.'];
        }
        return ['success' => false, 'error' => 'Error al eliminar los logs: ' . $this->conn->error];
    }
}
?>