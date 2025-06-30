<?php
/**
 * Clase LicenseManager Unificada y Corregida
 * Version: 1.2 - Con soporte completo para Whaticket y Dashboard de Verificaciones
 */

class LicenseManager {
    private $conn;
    private $whatsapp_config = null;

    public function __construct($db_config, $whatsapp_config = null) {
        $this->conn = new mysqli(
            $db_config['host'],
            $db_config['username'],
            $db_config['password'],
            $db_config['database']
        );

        if ($this->conn->connect_error) {
            // Se lanza una excepción si la conexión falla
            throw new Exception("Error de conexión a la base de datos: " . $this->conn->connect_error);
        }

        $this->conn->set_charset("utf8mb4");
        $this->whatsapp_config = $whatsapp_config;
    }

    public function getDbConnection() {
        return $this->conn;
    }

    public function authenticate($username, $password) {
        $stmt = $this->conn->prepare("SELECT id, username, password, role FROM license_admins WHERE username = ? AND status = 1");
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
                
                // Actualizar último login
                $update_stmt = $this->conn->prepare("UPDATE license_admins SET last_login = NOW() WHERE id = ?");
                $update_stmt->bind_param("i", $user['id']);
                $update_stmt->execute();
                
                return true;
            }
        }
        return false;
    }

    public function isLoggedIn() {
        return isset($_SESSION['license_admin']);
    }

    public function generateLicenseKey() {
        $prefix = 'LC';
        $timestamp = base_convert(time(), 10, 36);
        $random = bin2hex(random_bytes(12));
        $key = strtoupper($prefix . $timestamp . $random);
        
        return rtrim(chunk_split($key, 4, '-'), '-');
    }

    public function createLicense($data) {
        $license_key = $this->generateLicenseKey();
        
        // Calcular fecha de vencimiento automáticamente
        $start_date = !empty($data['start_date']) ? $data['start_date'] : date('Y-m-d H:i:s');
        $duration_days = !empty($data['duration_days']) ? (int)$data['duration_days'] : null;
        
        // Si se seleccionó "custom", usar el valor personalizado
        if (isset($data['duration_days']) && $data['duration_days'] === 'custom' && !empty($data['custom_duration'])) {
            $duration_days = (int)$data['custom_duration'];
        }
        
        $expires_at = null;
        if ($duration_days && $duration_days > 0) {
            $start_timestamp = strtotime($start_date);
            $expires_at = date('Y-m-d H:i:s', $start_timestamp + ($duration_days * 24 * 3600));
        }
        
        $stmt = $this->conn->prepare("
            INSERT INTO licenses (license_key, client_name, client_email, client_phone, product_name, version, 
                                license_type, max_domains, start_date, duration_days, expires_at, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("sssssssissss", 
            $license_key,
            $data['client_name'],
            $data['client_email'],
            $data['client_phone'],
            $data['product_name'],
            $data['version'],
            $data['license_type'],
            $data['max_domains'],
            $start_date,
            $duration_days,
            $expires_at,
            $data['notes']
        );
        
        if ($stmt->execute()) {
            $license_id = $this->conn->insert_id;
            
            // Enviar mensaje de WhatsApp de activación si está configurado y el teléfono existe
            $this->sendWhatsAppNotification('license_created', [
                'client_name' => $data['client_name'],
                'client_phone' => $data['client_phone'],
                'license_key' => $license_key,
                'expires_at' => $expires_at,
                'product_name' => $data['product_name'] ?? 'Sistema de Códigos'
            ]);
            
            return [
                'success' => true,
                'license_id' => $license_id,
                'license_key' => $license_key,
                'start_date' => $start_date,
                'expires_at' => $expires_at
            ];
        }
        
        return ['success' => false, 'error' => $this->conn->error];
    }

    public function getLicenseDetails($license_id) {
        $stmt = $this->conn->prepare("
            SELECT *, 
                   CASE 
                       WHEN expires_at IS NULL THEN 'permanent'
                       WHEN expires_at > NOW() THEN 'valid'
                       ELSE 'expired'
                   END as period_status,
                   CASE 
                       WHEN expires_at IS NOT NULL AND expires_at > NOW() 
                       THEN DATEDIFF(expires_at, NOW()) 
                       ELSE NULL 
                   END as days_remaining
            FROM licenses WHERE id = ?
        ");
        $stmt->bind_param("i", $license_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function updateLicense($data) {
        // Calcular fecha de vencimiento automáticamente
        $start_date = !empty($data['start_date']) ? $data['start_date'] : date('Y-m-d H:i:s');
        $duration_days = !empty($data['duration_days']) ? (int)$data['duration_days'] : null;
        
        // Si se seleccionó "custom", usar el valor personalizado
        if (isset($data['duration_days']) && $data['duration_days'] === 'custom' && !empty($data['custom_duration'])) {
            $duration_days = (int)$data['custom_duration'];
        }
        
        $expires_at = null;
        if ($duration_days && $duration_days > 0) {
            $start_timestamp = strtotime($start_date);
            $expires_at = date('Y-m-d H:i:s', $start_timestamp + ($duration_days * 24 * 3600));
        }

        // Obtener datos anteriores para comparar cambios antes de actualizar
        $old_license = $this->getLicenseDetails($data['id']);
        
        $stmt = $this->conn->prepare("
            UPDATE licenses SET 
                client_name = ?, client_email = ?, client_phone = ?, 
                product_name = ?, version = ?, license_type = ?, max_domains = ?, 
                start_date = ?, duration_days = ?, expires_at = ?, 
                status = ?, notes = ?
            WHERE id = ?
        ");
        
        $stmt->bind_param("ssssssisssssi", 
            $data['client_name'],
            $data['client_email'],
            $data['client_phone'],
            $data['product_name'],
            $data['version'],
            $data['license_type'],
            $data['max_domains'],
            $start_date,
            $duration_days,
            $expires_at,
            $data['status'],
            $data['notes'],
            $data['id']
        );
        
        if ($stmt->execute()) {
            // Enviar WhatsApp si cambió el estado y el teléfono existe
            if ($old_license && $old_license['status'] !== $data['status'] && !empty($data['client_phone'])) {
                $this->sendWhatsAppNotification('status_changed', [
                    'client_name' => $data['client_name'],
                    'client_phone' => $data['client_phone'],
                    'old_status' => $old_license['status'],
                    'new_status' => $data['status'],
                    'license_key' => $old_license['license_key'],
                    'product_name' => $data['product_name'] ?? 'Sistema de Códigos'
                ]);
            }
            
            return [
                'success' => true,
                'start_date' => $start_date,
                'expires_at' => $expires_at
            ];
        }
        
        return ['success' => false, 'error' => $this->conn->error];
    }

    public function getLicenses($limit = 50, $offset = 0, $search = '') {
        $where_clause = '';
        $params = [];
        $types = '';
        
        if (!empty($search)) {
            $where_clause = "WHERE l.client_name LIKE ? OR l.client_email LIKE ? OR l.client_phone LIKE ? OR l.license_key LIKE ?";
            $search_param = "%{$search}%";
            $params = [$search_param, $search_param, $search_param, $search_param];
            $types = 'ssss';
        }
        
        $sql = "
            SELECT l.*, 
                   COUNT(la.id) as activations_count,
                   COUNT(CASE WHEN la.status = 'active' THEN 1 END) as active_activations,
                   CASE 
                       WHEN l.expires_at IS NULL THEN 'permanent'
                       WHEN l.expires_at > NOW() THEN 'active'
                       ELSE 'expired'
                   END as calculated_status,
                   CASE 
                       WHEN l.expires_at IS NOT NULL AND l.expires_at > NOW() 
                       THEN DATEDIFF(l.expires_at, NOW()) 
                       ELSE 0 
                   END as days_remaining
            FROM licenses l
            LEFT JOIN license_activations la ON l.id = la.license_id
            {$where_clause}
            GROUP BY l.id
            ORDER BY l.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
        
        $stmt = $this->conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getLicenseStats() {
        // Esta vista se actualiza en el script de migración, asumimos que existe.
        $result = $this->conn->query("SELECT * FROM license_stats");
        return $result->fetch_assoc();
    }

    public function getActivations($license_id = null) {
        $where_clause = $license_id ? "WHERE la.license_id = ?" : "";
        
        $sql = "
            SELECT la.*, l.client_name, l.client_phone, l.license_key
            FROM license_activations la
            JOIN licenses l ON la.license_id = l.id
            {$where_clause}
            ORDER BY la.activated_at DESC
            LIMIT 100
        ";
        
        $stmt = $this->conn->prepare($sql);
        if ($license_id) {
            $stmt->bind_param("i", $license_id);
        }
        
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function updateLicenseStatus($license_id, $status) {
        // Obtener datos antes del cambio para notificación
        $old_license = $this->getLicenseDetails($license_id);
        
        $stmt = $this->conn->prepare("UPDATE licenses SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $license_id);
        
        if ($stmt->execute() && $old_license) {
            // Enviar notificación si cambió el estado y el teléfono existe
            if ($old_license['status'] !== $status && !empty($old_license['client_phone'])) {
                $this->sendWhatsAppNotification('status_changed', [
                    'client_name' => $old_license['client_name'],
                    'client_phone' => $old_license['client_phone'],
                    'old_status' => $old_license['status'],
                    'new_status' => $status,
                    'license_key' => $old_license['license_key'],
                    'product_name' => $old_license['product_name'] ?? 'Sistema de Códigos'
                ]);
            }
            return true;
        }
        return false;
    }

    public function getLicenseById($license_id) {
        return $this->getLicenseDetails($license_id);
    }

    public function updateLicensePeriod($license_id, $start_date, $duration_days) {
        $expires_at = null;
        if ($duration_days && $duration_days > 0) {
            $start_timestamp = strtotime($start_date);
            $expires_at = date('Y-m-d H:i:s', $start_timestamp + ($duration_days * 24 * 3600));
        }
        
        $stmt = $this->conn->prepare("UPDATE licenses SET start_date = ?, duration_days = ?, expires_at = ? WHERE id = ?");
        $stmt->bind_param("sisi", $start_date, $duration_days, $expires_at, $license_id);
        return $stmt->execute();
    }

    public function deleteLicense($license_id) {
        // Primero, eliminar las activaciones asociadas para mantener la integridad referencial
        $stmt_activations = $this->conn->prepare("DELETE FROM license_activations WHERE license_id = ?");
        $stmt_activations->bind_param("i", $license_id);
        $stmt_activations->execute();

        // Luego, eliminar los logs asociados
        $stmt_logs = $this->conn->prepare("DELETE FROM license_logs WHERE license_id = ?");
        $stmt_logs->bind_param("i", $license_id);
        $stmt_logs->execute();

        // Finalmente, eliminar la licencia
        $stmt_license = $this->conn->prepare("DELETE FROM licenses WHERE id = ?");
        $stmt_license->bind_param("i", $license_id);
        
        return $stmt_license->execute();
    }

    public function getRecentLogs($limit = 50, $action_filter = '', $status_filter = '', $search = '') {
        $where_clauses = [];
        $params = [];
        $types = '';

        if (!empty($action_filter)) {
            $where_clauses[] = "ll.action = ?";
            $params[] = $action_filter;
            $types .= 's';
        }
        if (!empty($status_filter)) {
            $where_clauses[] = "ll.status = ?";
            $params[] = $status_filter;
            $types .= 's';
        }
        if (!empty($search)) {
            $where_clauses[] = "(ll.message LIKE ? OR l.client_name LIKE ? OR l.license_key LIKE ?)";
            $search_param = "%{$search}%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $types .= 'sss';
        }

        $where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

        $sql = "
            SELECT ll.*, l.client_name, l.client_phone, l.license_key 
            FROM license_logs ll
            LEFT JOIN licenses l ON ll.license_id = l.id
            {$where_sql}
            ORDER BY ll.created_at DESC
            LIMIT ?
        ";

        $params[] = $limit;
        $types .= 'i';

        $stmt = $this->conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getExpiringLicenses($days = 30) {
        $sql = "
            SELECT *, DATEDIFF(expires_at, NOW()) as days_remaining
            FROM licenses 
            WHERE expires_at IS NOT NULL 
            AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY)
            AND status = 'active'
            ORDER BY expires_at ASC
        ";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $days);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
            WHERE action = 'verification'
        ";
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
            WHERE ll.action = 'verification'
        ";
        $params = [];
        $types = "";

        if ($status_filter) {
            $sql .= " AND ll.status = ?";
            $params[] = $status_filter;
            $types .= "s";
        }

        $sql .= " ORDER BY ll.created_at DESC LIMIT ?";
        $params[] = $limit;
        $types .= "i";
        
        $stmt = $this->conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Obtiene la actividad de logs en un período de tiempo reciente (ej. últimos 5 minutos).
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
            LIMIT 20
        ";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $minutes);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }


    // =============================
    // MÉTODOS PARA WHATSAPP WHATICKET
    // =============================

    public function sendWhatsAppNotification($type, $data) {
        // Asegúrate de que whatsapp_config esté inicializado y habilitado
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

        // Preparar payload específico para Whaticket
        $payload = [
            'number' => $clean_phone,
            'body' => $message,
            'userId' => $this->whatsapp_config['userId'] ?? '',
            'queueId' => $this->whatsapp_config['queueId'] ?? '',
            'sendSignature' => $this->whatsapp_config['sendSignature'] ?? false,
            'closeTicket' => $this->whatsapp_config['closeTicket'] ?? false
        ];

        // Headers específicos para Whaticket
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
            CURLOPT_VERBOSE => false // Cambiar a true para depuración en desarrollo
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // Log del envío
        $log_response_detail = json_decode($response, true) ?? $response; // Intentar decodificar JSON
        $this->logWhatsAppSend($clean_phone, $message, $type, $http_code, print_r($log_response_detail, true)); // Usar print_r para arrays/objetos

        // Whaticket generalmente retorna 200-299 para envíos exitosos
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
        
        // Remover cualquier caracter no numérico
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Si el número ya comienza con '57', no agregar nada.
        // Si tiene 10 dígitos y NO comienza con '57', asumir código de país '57' para Colombia.
        if (strlen($phone) === 10 && !str_starts_with($phone, '57')) {
            $phone = '57' . $phone;
        }
        // Si tiene 12 dígitos y empieza con '57', es el formato esperado (57XXXXXXXXXX)
        // Si tiene 13 dígitos y empieza con '57' (ej. 5703001234567), remover el '0' después del 57
        if (strlen($phone) === 13 && str_starts_with($phone, '57')) {
            // Esto es si el formato es 570xxxxxxxx
            if (substr($phone, 2, 1) === '0') {
                $phone = '57' . substr($phone, 3);
            }
        }
        
        // Validar que el número resultante tenga 12 dígitos y comience con '57'
        if (strlen($phone) === 12 && str_starts_with($phone, '57')) {
            return $phone;
        }
        
        // Si no cumple con el formato final, se considera inválido.
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
                ($support ? "\n\n📞 Soporte: $support" : ""),
                
            'expiring_soon' => 
                "⚠️ *¡Atención! Licencia por Expirar*\n\n" .
                "Hola *{client_name}*,\n\n" .
                "Tu licencia de {company} expirará en *{days_remaining} días*:\n\n" .
                "🔑 *Clave:* `{license_key}`\n" .
                "📅 *Expira:* {expires_date}\n" .
                "🏢 *Producto:* {product_name}\n\n" .
                "🔄 *¡Renueva ahora para evitar interrupciones!*\n\n" .
                "Contáctanos para procesar tu renovación." .
                ($support ? "\n\n📞 Soporte: $support" : ""),
                
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
                ($support ? "\n\n📞 Soporte: $support" : ""),
                
            'license_expired' => 
                "🚫 *Licencia Expirada*\n\n" .
                "Hola *{client_name}*,\n\n" .
                "Tu licencia de {company} ha expirado:\n\n" .
                "🔑 *Clave:* `{license_key}`\n" .
                "📅 *Expiró:* {expires_date}\n" .
                "🏢 *Producto:* {product_name}\n\n" .
                "⛔ *El acceso ha sido suspendido.*\n\n" .
                "🔄 Contáctanos inmediatamente para renovar y recuperar el acceso." .
                ($support ? "\n\n📞 Soporte: $support" : ""),
                
            'license_activated' =>
                "✅ *¡Licencia Reactivada!*\n\n" .
                "Hola *{client_name}*,\n\n" .
                "Tu licencia ha sido reactivada en el dominio:\n\n" .
                "🔑 *Clave:* `{license_key}`\n" .
                "🌐 *Dominio:* {domain}\n" .
                "📅 *Válida hasta:* {expires_date}\n\n" .
                "✅ El sistema ya está funcionando normalmente.\n\n" .
                "_Gracias por usar {company}_" .
                ($support ? "\n\n📞 Soporte: $support" : "")
        ];

        if (!isset($templates[$type])) {
            return null;
        }

        $message = $templates[$type];
        
        // Mensajes dinámicos según estado
        $status_messages = [
            'active' => '✅ Tu licencia está ahora *ACTIVA* y funcionando.',
            'suspended' => '⏸️ Tu licencia ha sido *SUSPENDIDA* temporalmente.',
            'expired' => '⛔ Tu licencia ha *EXPIRADO*. Contacta para renovar.',
            'revoked' => '🚫 Tu licencia ha sido *REVOCADA* permanentemente.'
        ];
        
        // Preparar reemplazos
        $replacements = [
            '{client_name}' => $data['client_name'] ?? 'Cliente',
            '{license_key}' => $data['license_key'] ?? '',
            '{expires_date}' => isset($data['expires_at']) && $data['expires_at'] 
                ? date('d/m/Y H:i', strtotime($data['expires_at'])) 
                : '*Permanente*',
            '{days_remaining}' => $data['days_remaining'] ?? '0',
            '{old_status}' => ucfirst($data['old_status'] ?? ''),
            '{new_status}' => ucfirst($data['new_status'] ?? ''),
            '{product_name}' => $data['product_name'] ?? 'Sistema de Códigos',
            '{domain}' => $data['domain'] ?? '',
            '{company}' => $company,
            '{status_message}' => isset($data['new_status']) 
                ? ($status_messages[$data['new_status']] ?? '') 
                : ''
        ];

        // Aplicar reemplazos
        foreach ($replacements as $placeholder => $value) {
            $message = str_replace($placeholder, $value, $message);
        }

        return $message;
    }

    public function checkExpiringLicensesAndNotify() {
        $alert_days = $this->whatsapp_config['expiry_alert_days'] ?? 3;
        
        // Buscar licencias que expiran exactamente en X días
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
        $stmt->bind_param("i", $alert_days);
        $stmt->execute();
        $expiring = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $sent_count = 0;
        
        foreach ($expiring as $license) {
            $success = $this->sendWhatsAppNotification('expiring_soon', [
                'client_name' => $license['client_name'],
                'client_phone' => $license['client_phone'],
                'license_key' => $license['license_key'],
                'expires_at' => $license['expires_at'],
                'days_remaining' => $license['days_remaining'],
                'product_name' => $license['product_name']
            ]);
            
            if ($success) {
                $sent_count++;
            }
        }
        
        // También verificar licencias que expiraron hoy
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
                // Cambiar estado a expirado
                $this->updateLicenseStatus($license['id'], 'expired');
                
                // Enviar notificación
                $this->sendWhatsAppNotification('license_expired', [
                    'client_name' => $license['client_name'],
                    'client_phone' => $license['client_phone'],
                    'license_key' => $license['license_key'],
                    'expires_at' => $license['expires_at'],
                    'product_name' => $license['product_name']
                ]);
                
                $sent_count++;
            }
        }
        
        return $sent_count;
    }

    private function logWhatsAppSend($phone, $message, $type, $http_code, $response_detail) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO whatsapp_logs (phone, message, type, http_code, response, sent_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            // Limitar la longitud de la respuesta para evitar errores en la base de datos si es muy larga
            $response_limited = substr($response_detail, 0, 65535); // TEXT column max length
            $stmt->bind_param("sssis", $phone, $message, $type, $http_code, $response_limited);
            $stmt->execute();
        } catch (Exception $e) {
            error_log("Error logging WhatsApp: " . $e->getMessage());
        }
    }
}
?>