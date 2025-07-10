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

    /**
     * Extiende la fecha de expiración de una licencia.
     *
     * @param int $id   ID de la licencia a modificar.
     * @param int $days Cantidad de días a agregar.
     * @return array Resultado de la operación con la nueva fecha.
     */
    public function extendLicense($id, $days) {
        $id = (int)$id;
        $days = (int)$days;
        if ($id <= 0 || $days <= 0) {
            return ['success' => false, 'error' => 'Parámetros inválidos'];
        }

        $stmt = $this->conn->prepare("SELECT expires_at FROM licenses WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if (!$result) {
            return ['success' => false, 'error' => 'Licencia no encontrada'];
        }

        $current = $result['expires_at'] ? $result['expires_at'] : date('Y-m-d H:i:s');
        $new_expiration = date('Y-m-d H:i:s', strtotime("$current +$days days"));

        $update = $this->conn->prepare("UPDATE licenses SET expires_at = ? WHERE id = ?");
        $update->bind_param('si', $new_expiration, $id);
        if ($update->execute()) {
            return ['success' => true, 'expires_at' => $new_expiration];
        }
        return ['success' => false, 'error' => $this->conn->error];
    }

    public function updateLicenseStatus($license_id, $status) {
        // Obtener datos antes del cambio para notificación
        $old_license = $this->getLicenseDetails($license_id);

        $stmt = $this->conn->prepare("UPDATE licenses SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $license_id);

        if ($stmt->execute() && $old_license) {
            if ($old_license['status'] !== $status && !empty($old_license['client_phone'])) {
                $this->sendWhatsAppNotification('status_changed', [
                    'client_name'   => $old_license['client_name'],
                    'client_phone'  => $old_license['client_phone'],
                    'old_status'    => $old_license['status'],
                    'new_status'    => $status,
                    'license_key'   => $old_license['license_key'],
                    'product_name'  => $old_license['product_name'] ?? 'Sistema de Códigos'
                ]);
            }
            return true;
        }
        return false;
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
            WHERE action IN ('verification','validation')";
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
            WHERE ll.action IN ('verification','validation')";

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

    // =============================
    // MÉTODOS PARA WHATSAPP WHATICKET
    // =============================

    public function sendWhatsAppNotification($type, $data) {
        if (!$this->whatsapp_config || !isset($this->whatsapp_config['enabled']) || !$this->whatsapp_config['enabled'] || empty($data['client_phone'])) {
            error_log("WhatsApp Notification Skipped: Config disabled or missing phone for type: $type");
            return false;
        }

        $phone = $this->cleanPhoneNumber($data['client_phone']);
        if (!$phone) {
            error_log("WhatsApp Notification Skipped: Invalid phone number for type: $type, Original: " . ($data['client_phone'] ?? 'N/A'));
            return false;
        }

        $message = $this->getWhatsAppMessage($type, $data);
        if (!$message) {
            error_log("WhatsApp Notification Skipped: Message template not found or data incomplete for type: $type");
            return false;
        }

        return $this->sendWhatsAppMessage($phone, $message, $type);
    }

    private function sendWhatsAppMessage($phone, $message, $type = 'notification') {
        if (!$this->whatsapp_config || !isset($this->whatsapp_config['enabled']) || !$this->whatsapp_config['enabled']) {
            return false;
        }

        $clean_phone = $this->cleanPhoneNumber($phone);
        if (!$clean_phone) {
            $this->logWhatsAppSend($phone, $message, $type, 0, 'Invalid phone number after cleaning');
            return false;
        }

        $payload = [
            'number'        => $clean_phone,
            'body'          => $message,
            'userId'        => $this->whatsapp_config['userId'] ?? '',
            'queueId'       => $this->whatsapp_config['queueId'] ?? '',
            'sendSignature' => $this->whatsapp_config['sendSignature'] ?? false,
            'closeTicket'   => $this->whatsapp_config['closeTicket'] ?? false
        ];

        $headers = [
            'Authorization: Bearer ' . $this->whatsapp_config['token'],
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->whatsapp_config['endpoint'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->whatsapp_config['timeout'] ?? 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_VERBOSE => false
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        $log_response_detail = json_decode($response, true) ?? $response;
        $this->logWhatsAppSend($clean_phone, $message, $type, $http_code, print_r($log_response_detail, true));

        $success = ($http_code >= 200 && $http_code < 300);

        if (!$success) {
            error_log("WhatsApp Error - Type: $type, Phone: $clean_phone, Code: $http_code, Response: " . print_r($log_response_detail, true));
        }

        return $success;
    }

    private function cleanPhoneNumber($phone) {
        if (empty($phone)) {
            return false;
        }

        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($phone) === 10 && !str_starts_with($phone, '57')) {
            $phone = '57' . $phone;
        }
        if (strlen($phone) === 13 && str_starts_with($phone, '57')) {
            if (substr($phone, 2, 1) === '0') {
                $phone = '57' . substr($phone, 3);
            }
        }

        if (strlen($phone) === 12 && str_starts_with($phone, '57')) {
            return $phone;
        }

        return false;
    }

    private function getWhatsAppMessage($type, $data) {
        $company = $this->whatsapp_config['company_name'] ?? 'Sistema de Códigos';
        $support = $this->whatsapp_config['support_phone'] ?? '';

        $templates = [
            'license_created' =>
                "🎉 *¡Licencia Activada!*\n\n" .
                "Hola *{client_name}*,\n\n" .
                "Tu licencia de {company} ha sido activada exitosamente:\n\n" .
                "🔑 *Clave de Licencia:*\n`{license_key}`\n\n" .
                "📅 *Válida hasta:* {expires_date}\n" .
                "🏢 *Producto:* {product_name}\n\n" .
                "✅ Ya puedes utilizar tu licencia.\n\n" .
                "_¡Gracias por confiar en nosotros!_" .
                ($support ? "\n\n📞 Soporte: $support" : ''),

            'expiring_soon' =>
                "⚠️ *¡Atención! Licencia por Expirar*\n\n" .
                "Hola *{client_name}*,\n\n" .
                "Tu licencia de {company} expirará en *{days_remaining} días*:\n\n" .
                "🔑 *Clave:* `{license_key}`\n" .
                "📅 *Expira:* {expires_date}\n" .
                "🏢 *Producto:* {product_name}\n\n" .
                "🔄 *¡Renueva ahora para evitar interrupciones!*\n\n" .
                "Contáctanos para procesar tu renovación." .
                ($support ? "\n\n📞 Soporte: $support" : ''),

            'status_changed' =>
                "🔄 *Estado de Licencia Actualizado*\n\n" .
                "Hola *{client_name}*,\n\n" .
                "El estado de tu licencia ha sido modificado:\n\n" .
                "🔑 *Clave:* `{license_key}`\n" .
                "📊 *Estado anterior:* {old_status}\n" .
                "📊 *Estado actual:* *{new_status}*\n" .
                "🏢 *Producto:* {product_name}\n\n" .
                "{status_message}\n\n" .
                "Si tienes dudas, no dudes en contactarnos." .
                ($support ? "\n\n📞 Soporte: $support" : ''),

            'license_expired' =>
                "🚫 *Licencia Expirada*\n\n" .
                "Hola *{client_name}*,\n\n" .
                "Tu licencia de {company} ha expirado:\n\n" .
                "🔑 *Clave:* `{license_key}`\n" .
                "📅 *Expiró:* {expires_date}\n" .
                "🏢 *Producto:* {product_name}\n\n" .
                "⛔ *El acceso ha sido suspendido.*\n\n" .
                "🔄 Contáctanos inmediatamente para renovar y recuperar el acceso." .
                ($support ? "\n\n📞 Soporte: $support" : ''),

            'license_activated' =>
                "✅ *¡Licencia Reactivada!*\n\n" .
                "Hola *{client_name}*,\n\n" .
                "Tu licencia ha sido reactivada en el dominio:\n\n" .
                "🔑 *Clave:* `{license_key}`\n" .
                "🌐 *Dominio:* {domain}\n" .
                "📅 *Válida hasta:* {expires_date}\n\n" .
                "✅ El sistema ya está funcionando normalmente.\n\n" .
                "_Gracias por usar {company}_" .
                ($support ? "\n\n📞 Soporte: $support" : '')
        ];

        if (!isset($templates[$type])) {
            return null;
        }

        $message = $templates[$type];

        $status_messages = [
            'active'    => '✅ Tu licencia está ahora *ACTIVA* y funcionando.',
            'suspended' => '⏸️ Tu licencia ha sido *SUSPENDIDA* temporalmente.',
            'expired'   => '⛔ Tu licencia ha *EXPIRADO*. Contacta para renovar.',
            'revoked'   => '🚫 Tu licencia ha sido *REVOCADA* permanentemente.'
        ];

        $replacements = [
            '{client_name}'   => $data['client_name'] ?? 'Cliente',
            '{license_key}'   => $data['license_key'] ?? '',
            '{expires_date}'  => isset($data['expires_at']) && $data['expires_at'] ? date('d/m/Y H:i', strtotime($data['expires_at'])) : '*Permanente*',
            '{days_remaining}' => $data['days_remaining'] ?? '0',
            '{old_status}'    => ucfirst($data['old_status'] ?? ''),
            '{new_status}'    => ucfirst($data['new_status'] ?? ''),
            '{product_name}'  => $data['product_name'] ?? 'Sistema de Códigos',
            '{domain}'        => $data['domain'] ?? '',
            '{company}'       => $company,
            '{status_message}' => isset($data['new_status']) ? ($status_messages[$data['new_status']] ?? '') : ''
        ];

        foreach ($replacements as $placeholder => $value) {
            $message = str_replace($placeholder, $value, $message);
        }

        return $message;
    }

    public function checkExpiringLicensesAndNotify() {
        $alert_days = $this->whatsapp_config['expiry_alert_days'] ?? 3;

        $sql = "
            SELECT *, DATEDIFF(expires_at, NOW()) as days_remaining
            FROM licenses
            WHERE expires_at IS NOT NULL
            AND DATEDIFF(expires_at, NOW()) = ?
            AND status = 'active'
            AND client_phone IS NOT NULL
            AND client_phone != ''
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $alert_days);
        $stmt->execute();
        $expiring = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $sent_count = 0;

        foreach ($expiring as $license) {
            $success = $this->sendWhatsAppNotification('expiring_soon', [
                'client_name'    => $license['client_name'],
                'client_phone'   => $license['client_phone'],
                'license_key'    => $license['license_key'],
                'expires_at'     => $license['expires_at'],
                'days_remaining' => $license['days_remaining'],
                'product_name'   => $license['product_name']
            ]);

            if ($success) {
                $sent_count++;
            }
        }

        $sql_expired = "
            SELECT *
            FROM licenses
            WHERE expires_at IS NOT NULL
            AND DATE(expires_at) = CURDATE()
            AND status = 'active'
            AND client_phone IS NOT NULL
            AND client_phone != ''
        ";

        $result_expired = $this->conn->query($sql_expired);
        if ($result_expired) {
            while ($license = $result_expired->fetch_assoc()) {
                $this->updateLicenseStatus($license['id'], 'expired');

                $this->sendWhatsAppNotification('license_expired', [
                    'client_name'  => $license['client_name'],
                    'client_phone' => $license['client_phone'],
                    'license_key'  => $license['license_key'],
                    'expires_at'   => $license['expires_at'],
                    'product_name' => $license['product_name']
                ]);

                $sent_count++;
            }
        }

        return $sent_count;
    }

    private function logWhatsAppSend($phone, $message, $type, $http_code, $response_detail) {
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO whatsapp_logs (phone, message, type, http_code, response, sent_at) VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $response_limited = substr($response_detail, 0, 65535);
            $stmt->bind_param('sssis', $phone, $message, $type, $http_code, $response_limited);
            $stmt->execute();
        } catch (Exception $e) {
            error_log('Error logging WhatsApp: ' . $e->getMessage());
        }
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