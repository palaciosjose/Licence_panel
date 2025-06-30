<?php
/**
 * Script de Instalación para el Sistema de Licencias
 *
 * Este script guía al usuario a través de la configuración inicial del sistema,
 * incluyendo la conexión a la base de datos y la creación del usuario administrador.
 *
 * ADVERTENCIA: Por seguridad, se recomienda ELIMINAR o RENOMBRAR este archivo
 * inmediatamente después de una instalación exitosa.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1); // Desactivar en producción

$install_step = $_GET['step'] ?? '0';
$error_message = '';
$success_message = '';

// Ruta del archivo de configuración de WhatsApp (asumimos que está en la misma carpeta)
$whatsapp_config_path = __DIR__ . '/whatsapp_config.php';

// Contenido de whatsapp_config.php si no existe o para crear uno nuevo
$default_whatsapp_config_content = <<<'EOT'
<?php
// =====================================================================
// ARCHIVO 1: whatsapp_config.php
// =====================================================================

$whatsapp_config = [
    'enabled' => false, // Cambiar a true después de configurar la API
    'token' => 'TU_TOKEN_API_WHATICKET', // Reemplazar con tu token de Whaticket
    'endpoint' => 'https://apiwhaticket.streamdigi.co/api/messages/send', // Reemplazar si tu endpoint es diferente
    'timeout' => 30,
    'retry_attempts' => 3,
    'test_mode' => false,
    
    // Configuraciones específicas de Whaticket
    'userId' => '', // ID del usuario en Whaticket o vacío
    'queueId' => '', // ID de la fila en Whaticket o vacío  
    'sendSignature' => false, // Firmar mensajes
    'closeTicket' => false, // Cerrar ticket automáticamente
    
    // Configuraciones de notificaciones
    'expiry_alert_days' => 3, // Días antes de vencer para enviar alerta
    'daily_check_hour' => '09:00', // Hora de verificación diaria (formato HH:MM)
    'company_name' => 'Tu Empresa', // Nombre de tu empresa
    'support_phone' => '+573232405812' // Tu número de soporte para WhatsApp (con código de país sin +)
];
EOT;

// --- Funciones auxiliares ---
function check_php_extensions($extensions) {
    $missing = [];
    foreach ($extensions as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    return $missing;
}

function check_writable_dirs($dirs) {
    $unwritable = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true); // Intentar crear si no existe
        }
        if (!is_writable($dir)) {
            $unwritable[] = $dir;
        }
    }
    return $unwritable;
}

function db_connect($host, $username, $password, $database = null) {
    $conn = new mysqli($host, $username, $password, $database);
    if ($conn->connect_error) {
        throw new Exception("Error de conexión a MySQL: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

function create_db_if_not_exists($conn, $db_name) {
    $result = $conn->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$db_name'");
    if ($result && $result->num_rows == 0) {
        if (!$conn->query("CREATE DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            throw new Exception("No se pudo crear la base de datos '$db_name'. Asegúrate de que el usuario tenga permisos para crear bases de datos. Error: " . $conn->error);
        }
        return true; // DB creada
    }
    return false; // DB ya existía
}

// SQL para crear las tablas
function get_sql_schema() {
    return [
        "CREATE TABLE IF NOT EXISTS `licenses` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `license_key` VARCHAR(255) UNIQUE NOT NULL,
            `client_name` VARCHAR(255) NOT NULL,
            `client_email` VARCHAR(255) NOT NULL,
            `client_phone` VARCHAR(20) NULL,
            `product_name` VARCHAR(255) NOT NULL,
            `version` VARCHAR(50) DEFAULT '1.0',
            `license_type` ENUM('single', 'multiple', 'unlimited') DEFAULT 'single',
            `max_domains` INT DEFAULT 1,
            `status` ENUM('active', 'suspended', 'expired', 'revoked') DEFAULT 'active',
            `start_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `duration_days` INT NULL,
            `expires_at` DATETIME NULL,
            `notes` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `license_activations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `license_id` INT NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `ip_address` VARCHAR(45) NULL,
            `status` ENUM('active', 'inactive', 'blocked') DEFAULT 'active',
            `activated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `last_check` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `check_count` INT DEFAULT 0,
            `user_agent` TEXT NULL,
            `server_info` JSON NULL,
            UNIQUE KEY `unique_license_domain` (`license_id`, `domain`),
            FOREIGN KEY (`license_id`) REFERENCES `licenses`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `license_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `license_id` INT NULL,
            `activation_id` INT NULL,
            `action` VARCHAR(100) NOT NULL,
            `status` VARCHAR(50) NOT NULL,
            `message` TEXT NOT NULL,
            `ip_address` VARCHAR(45) NULL,
            `user_agent` TEXT NULL,
            `request_data` JSON NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`license_id`) REFERENCES `licenses`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
            FOREIGN KEY (`activation_id`) REFERENCES `license_activations`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `license_admins` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(255) UNIQUE NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            `role` ENUM('admin', 'editor') DEFAULT 'admin',
            `status` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `last_login` DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

        "CREATE TABLE IF NOT EXISTS `whatsapp_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `phone` VARCHAR(20) NOT NULL,
            `message` TEXT NOT NULL,
            `type` VARCHAR(100) NULL,
            `http_code` INT NULL,
            `response` TEXT NULL,
            `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        
        // Vistas para estadísticas (recrear si ya existen)
        "DROP VIEW IF EXISTS `license_stats`;", // Eliminar vista antigua si existe
        "CREATE VIEW `license_stats` AS
        SELECT 
            COUNT(*) as total_licenses,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_licenses,
            COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired_licenses,
            COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended_licenses,
            (SELECT COUNT(*) FROM license_activations) as total_activations,
            (SELECT COUNT(DISTINCT domain) FROM license_activations WHERE status = 'active') as unique_domains,
            COUNT(CASE WHEN expires_at IS NOT NULL AND expires_at < NOW() THEN 1 END) as expired_count,
            COUNT(CASE WHEN expires_at IS NOT NULL AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 END) as expiring_soon,
            COUNT(CASE WHEN client_phone IS NOT NULL AND client_phone != '' THEN 1 END) as licenses_with_phone,
            COUNT(CASE WHEN duration_days IS NOT NULL THEN 1 END) as time_limited_licenses,
            COUNT(CASE WHEN duration_days IS NULL THEN 1 END) as permanent_licenses
        FROM licenses;"
    ];
}

// --- Lógica del instalador ---

// Paso 0: Bienvenida y Chequeo de requisitos
if ($install_step === '0') {
    $required_extensions = ['mysqli', 'curl', 'json'];
    $missing_extensions = check_php_extensions($required_extensions);

    $required_dirs = [__DIR__ . '/logs']; // Directorio para logs de WhatsApp
    $unwritable_dirs = check_writable_dirs($required_dirs);

    if (phpversion() < 7.4) { // Recomendar PHP 7.4+
        $error_message .= "ADVERTENCIA: Tu versión de PHP (" . phpversion() . ") es inferior a 7.4. Se recomienda PHP 7.4 o superior para un mejor rendimiento y seguridad.<br>";
    }

    if (!empty($missing_extensions)) {
        $error_message .= "Faltan las siguientes extensiones de PHP: " . implode(', ', $missing_extensions) . ". Por favor, instálalas y habilítalas.<br>";
    }
    if (!empty($unwritable_dirs)) {
        $error_message .= "Los siguientes directorios no tienen permisos de escritura: " . implode(', ', $unwritable_dirs) . ". Por favor, asigna permisos de escritura (CHMOD 0755 o 0777 si es necesario).<br>";
    }

    if (!empty($error_message)) {
        $install_step = 'error'; // Forzar a paso de error si hay problemas críticos
    } else {
        $success_message = "Todos los requisitos de PHP parecen estar cubiertos. Podemos continuar.";
        if (file_exists($whatsapp_config_path)) {
            $success_message .= "<br>Se detectó un archivo `whatsapp_config.php` existente. Si quieres reconfigurarlo, lo haremos en el paso 3.";
        }
    }
}

// Paso 1: Configuración de la base de datos
if ($install_step === '1' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['db_config'])) {
    $db_host = trim($_POST['db_host'] ?? '');
    $db_username = trim($_POST['db_username'] ?? '');
    $db_password = $_POST['db_password'] ?? '';
    $db_name = trim($_POST['db_name'] ?? '');

    if (empty($db_host) || empty($db_username) || empty($db_name)) {
        $error_message = "Todos los campos de la base de datos son requeridos.";
    } else {
        try {
            $conn = db_connect($db_host, $db_username, $db_password);
            
            // Intentar crear la base de datos si no existe
            $db_created = create_db_if_not_exists($conn, $db_name);
            
            // Conectar a la base de datos recién creada o existente
            $conn->select_db($db_name);

            // Ejecutar el esquema SQL
            $sql_schema = get_sql_schema();
            foreach ($sql_schema as $sql) {
                if (!$conn->query($sql)) {
                    throw new Exception("Error al ejecutar SQL: " . $conn->error . " (Consulta: " . substr($sql, 0, 100) . "...)");
                }
            }

            // Guardar configuración de DB en un archivo para el sistema de licencias
            $config_content = "<?php\n\$license_db_config = [\n";
            $config_content .= "    'host' => '" . $conn->real_escape_string($db_host) . "',\n";
            $config_content .= "    'username' => '" . $conn->real_escape_string($db_username) . "',\n";
            $config_content .= "    'password' => '" . $conn->real_escape_string($db_password) . "',\n";
            $config_content .= "    'database' => '" . $conn->real_escape_string($db_name) . "'\n";
            $config_content .= "];\n";
            
            // La configuración de DB la inyectamos directamente en Psnel_administracion.php y LicenseManager.class.php
            // Esto es más directo que crear un archivo externo y tener que incluirlo en múltiples lugares.
            // ALTERNATIVA: Podrías escribirla a un archivo db_config.php y luego hacer require_once.
            // Por simplicidad y ya que el usuario tiene las configs en cada archivo, la dejaremos en las cabeceras
            // y explicaremos que deben modificarla manualmente si se cambia.
            // Para el instalador, asumimos que los archivos ya tienen la configuración y solo la usamos para la instalación.
            // No crearemos un archivo de configuración de DB separado aquí.

            $_SESSION['db_connection_success'] = true;
            $_SESSION['db_host'] = $db_host;
            $_SESSION['db_username'] = $db_username;
            $_SESSION['db_password'] = $db_password;
            $_SESSION['db_name'] = $db_name;

            $success_message = "Conexión a la base de datos exitosa. Base de datos y tablas creadas/actualizadas.";
            $install_step = '2'; // Ir al siguiente paso: Crear administrador
            
        } catch (Exception $e) {
            $error_message = "Error en la base de datos: " . $e->getMessage();
        }
    }
}

// Paso 2: Crear usuario administrador
if ($install_step === '2' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_config'])) {
    if (!isset($_SESSION['db_connection_success'])) {
        $error_message = "Error: La configuración de la base de datos no se completó correctamente. Por favor, reinicia el instalador.";
        $install_step = '0';
    } else {
        $admin_username = trim($_POST['admin_username'] ?? '');
        $admin_password = $_POST['admin_password'] ?? '';
        $admin_password_confirm = $_POST['admin_password_confirm'] ?? '';

        if (empty($admin_username) || empty($admin_password) || empty($admin_password_confirm)) {
            $error_message = "Todos los campos del administrador son requeridos.";
        } elseif ($admin_password !== $admin_password_confirm) {
            $error_message = "Las contraseñas no coinciden.";
        } elseif (strlen($admin_password) < 6) {
            $error_message = "La contraseña debe tener al menos 6 caracteres.";
        } else {
            try {
                $conn = db_connect($_SESSION['db_host'], $_SESSION['db_username'], $_SESSION['db_password'], $_SESSION['db_name']);
                
                $hashed_password = password_hash($admin_password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("INSERT INTO license_admins (username, password, role, status) VALUES (?, ?, 'admin', 1)");
                $stmt->bind_param("ss", $admin_username, $hashed_password);
                
                if ($stmt->execute()) {
                    $success_message = "Usuario administrador '$admin_username' creado exitosamente.";
                    $install_step = '3'; // Ir al siguiente paso: Configuración de WhatsApp
                } else {
                    $error_message = "Error al crear usuario administrador: " . $conn->error;
                }
                $conn->close();
            } catch (Exception $e) {
                $error_message = "Error de base de datos al crear administrador: " . $e->getMessage();
            }
        }
    }
}

// Paso 3: Configuración de WhatsApp
if ($install_step === '3' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['whatsapp_config'])) {
    $whatsapp_enabled = isset($_POST['whatsapp_enabled']) ? 'true' : 'false';
    $whatsapp_token = trim($_POST['whatsapp_token'] ?? 'TU_TOKEN_API_WHATICKET');
    $whatsapp_endpoint = trim($_POST['whatsapp_endpoint'] ?? 'https://apiwhaticket.streamdigi.co/api/messages/send');
    $whatsapp_userId = trim($_POST['whatsapp_userId'] ?? '');
    $whatsapp_queueId = trim($_POST['whatsapp_queueId'] ?? '');
    $whatsapp_sendSignature = isset($_POST['whatsapp_sendSignature']) ? 'true' : 'false';
    $whatsapp_closeTicket = isset($_POST['whatsapp_closeTicket']) ? 'true' : 'false';
    $whatsapp_expiry_alert_days = (int)($_POST['whatsapp_expiry_alert_days'] ?? 3);
    $whatsapp_daily_check_hour = trim($_POST['whatsapp_daily_check_hour'] ?? '09:00');
    $whatsapp_company_name = trim($_POST['whatsapp_company_name'] ?? 'Tu Empresa');
    $whatsapp_support_phone = trim($_POST['whatsapp_support_phone'] ?? '+573232405812');
    
    $config_content = "<?php\n// =====================================================================\n";
    $config_content .= "// ARCHIVO 1: whatsapp_config.php\n";
    $config_content .= "// =====================================================================\n\n";
    $config_content .= "\$whatsapp_config = [\n";
    $config_content .= "    'enabled' => $whatsapp_enabled,\n";
    $config_content .= "    'token' => '" . addslashes($whatsapp_token) . "',\n";
    $config_content .= "    'endpoint' => '" . addslashes($whatsapp_endpoint) . "',\n";
    $config_content .= "    'timeout' => 30,\n";
    $config_content .= "    'retry_attempts' => 3,\n";
    $config_content .= "    'test_mode' => false,\n"; // Mantener test_mode false por defecto en instalación
    $config_content .= "    \n";
    $config_content .= "    // Configuraciones específicas de Whaticket\n";
    $config_content .= "    'userId' => '" . addslashes($whatsapp_userId) . "',\n";
    $config_content .= "    'queueId' => '" . addslashes($whatsapp_queueId) . "',\n";
    $config_content .= "    'sendSignature' => $whatsapp_sendSignature,\n";
    $config_content .= "    'closeTicket' => $whatsapp_closeTicket,\n";
    $config_content .= "    \n";
    $config_content .= "    // Configuraciones de notificaciones\n";
    $config_content .= "    'expiry_alert_days' => $whatsapp_expiry_alert_days,\n";
    $config_content .= "    'daily_check_hour' => '" . addslashes($whatsapp_daily_check_hour) . "',\n";
    $config_content .= "    'company_name' => '" . addslashes($whatsapp_company_name) . "',\n";
    $config_content .= "    'support_phone' => '" . addslashes($whatsapp_support_phone) . "'\n";
    $config_content .= "];\n";

    if (file_put_contents($whatsapp_config_path, $config_content)) {
        $success_message = "Configuración de WhatsApp guardada exitosamente.";
        $install_step = 'complete'; // Ir al paso final
    } else {
        $error_message = "Error al escribir el archivo `whatsapp_config.php`. Verifica los permisos de escritura en el directorio del proyecto.";
    }
} else if ($install_step === '3') { // Si se llega al paso 3 sin POST, pero con DB configurada
     if (!isset($_SESSION['db_connection_success'])) {
        $error_message = "Error: La configuración de la base de datos no se completó correctamente. Por favor, reinicia el instalador.";
        $install_step = '0';
    }
}


// Limpiar variables de sesión después de usarlas o al final de la instalación
if ($install_step === 'complete' || $install_step === 'error') {
    unset($_SESSION['db_connection_success']);
    unset($_SESSION['db_host']);
    unset($_SESSION['db_username']);
    unset($_SESSION['db_password']);
    unset($_SESSION['db_name']);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador del Sistema de Licencias</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; justify-content: center; align-items: center; }
        .install-card { background: rgba(255, 255, 255, 0.95); border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); padding: 30px; max-width: 700px; width: 95%; }
        .step-indicator { margin-bottom: 20px; }
        .step-indicator .step { border: 2px solid #ccc; border-radius: 50%; width: 40px; height: 40px; line-height: 36px; text-align: center; display: inline-block; background: #f0f0f0; color: #555; }
        .step-indicator .step.active { border-color: #007bff; background: #007bff; color: white; }
        .step-indicator .step.completed { border-color: #28a745; background: #28a745; color: white; }
        .step-indicator .divider { height: 2px; background: #ccc; margin: 18px 10px; flex-grow: 1; }
        .step-indicator .divider.completed { background: #28a745; }
    </style>
</head>
<body>
    <div class="install-card">
        <h2 class="text-center mb-4">
            <i class="fas fa-screwdriver-wrench me-2"></i>Instalador del Sistema de Licencias
        </h2>

        <div class="d-flex justify-content-center align-items-center step-indicator">
            <div class="step <?= ($install_step >= 0) ? 'active' : '' ?> <?= ($install_step > 0) ? 'completed' : '' ?>">1</div>
            <div class="divider <?= ($install_step > 0) ? 'completed' : '' ?>"></div>
            <div class="step <?= ($install_step >= 1) ? 'active' : '' ?> <?= ($install_step > 1) ? 'completed' : '' ?>">2</div>
            <div class="divider <?= ($install_step > 1) ? 'completed' : '' ?>"></div>
            <div class="step <?= ($install_step >= 2) ? 'active' : '' ?> <?= ($install_step > 2) ? 'completed' : '' ?>">3</div>
            <div class="divider <?= ($install_step > 2) ? 'completed' : '' ?>"></div>
            <div class="step <?= ($install_step >= 3) ? 'active' : '' ?> <?= ($install_step > 3) ? 'completed' : '' ?>">4</div>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-danger mt-3">
                <i class="fas fa-exclamation-triangle me-2"></i><?= $error_message ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success mt-3">
                <i class="fas fa-check-circle me-2"></i><?= $success_message ?>
            </div>
        <?php endif; ?>

        <?php if ($install_step === '0'): // Paso 0: Bienvenida y Chequeo de requisitos ?>
            <h4>Bienvenido al Instalador</h4>
            <p>Este asistente te guiará a través de la instalación de tu Sistema de Licencias.</p>
            <p>Asegúrate de que tu servidor cumpla con los siguientes requisitos:</p>
            <ul>
                <li>Versión de PHP 7.4 o superior (actual: <?= phpversion() ?>)</li>
                <li>Extensión PHP `mysqli` habilitada.</li>
                <li>Extensión PHP `curl` habilitada.</li>
                <li>Extensión PHP `json` habilitada.</li>
                <li>Permisos de escritura en el directorio `logs/` dentro de la raíz de tu proyecto.</li>
            </ul>
            <p>Si hay advertencias o errores arriba, por favor corrígelos antes de continuar.</p>
            <form action="?step=1" method="post">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-arrow-right me-2"></i>Comenzar Instalación
                </button>
            </form>

        <?php elseif ($install_step === '1'): // Paso 1: Configuración de la base de datos ?>
            <h4>Paso 1: Configuración de la Base de Datos</h4>
            <p>Ingresa los detalles de tu base de datos MySQL.</p>
            <form action="?step=1" method="post">
                <input type="hidden" name="db_config" value="1">
                <div class="mb-3">
                    <label for="db_host" class="form-label">Host de la Base de Datos</label>
                    <input type="text" class="form-control" id="db_host" name="db_host" value="localhost" required>
                    <div class="form-text">Generalmente `localhost` o la IP/hostname de tu servidor de DB.</div>
                </div>
                <div class="mb-3">
                    <label for="db_username" class="form-label">Usuario de la Base de Datos</label>
                    <input type="text" class="form-control" id="db_username" name="db_username" required>
                    <div class="form-text">Usuario de MySQL con permisos para crear bases de datos y tablas.</div>
                </div>
                <div class="mb-3">
                    <label for="db_password" class="form-label">Contraseña de la Base de Datos</label>
                    <input type="password" class="form-control" id="db_password" name="db_password">
                </div>
                <div class="mb-3">
                    <label for="db_name" class="form-label">Nombre de la Base de Datos</label>
                    <input type="text" class="form-control" id="db_name" name="db_name" required>
                    <div class="form-text">La base de datos se creará si no existe.</div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-database me-2"></i>Conectar y Crear Tablas
                </button>
            </form>

        <?php elseif ($install_step === '2'): // Paso 2: Crear usuario administrador ?>
            <h4>Paso 2: Crear Usuario Administrador</h4>
            <p>Crea la cuenta de administrador para acceder al panel de licencias.</p>
            <form action="?step=2" method="post">
                <input type="hidden" name="admin_config" value="1">
                <div class="mb-3">
                    <label for="admin_username" class="form-label">Nombre de Usuario (Admin)</label>
                    <input type="text" class="form-control" id="admin_username" name="admin_username" required>
                </div>
                <div class="mb-3">
                    <label for="admin_password" class="form-label">Contraseña (Admin)</label>
                    <input type="password" class="form-control" id="admin_password" name="admin_password" required>
                    <div class="form-text">Mínimo 6 caracteres.</div>
                </div>
                <div class="mb-3">
                    <label for="admin_password_confirm" class="form-label">Confirmar Contraseña</label>
                    <input type="password" class="form-control" id="admin_password_confirm" name="admin_password_confirm" required>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i>Crear Administrador
                </button>
            </form>

        <?php elseif ($install_step === '3'): // Paso 3: Configuración de WhatsApp ?>
            <h4>Paso 3: Configuración de WhatsApp (Opcional)</h4>
            <p>Configura la integración con Whaticket para notificaciones de WhatsApp. Puedes dejar los valores por defecto y configurarlos más tarde en `whatsapp_config.php`.</p>
            <form action="?step=3" method="post">
                <input type="hidden" name="whatsapp_config" value="1">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="whatsapp_enabled" name="whatsapp_enabled" value="1" checked>
                    <label class="form-check-label" for="whatsapp_enabled">Habilitar Notificaciones WhatsApp</label>
                </div>
                <div class="mb-3">
                    <label for="whatsapp_token" class="form-label">Token de API (Whaticket)</label>
                    <input type="text" class="form-control" id="whatsapp_token" name="whatsapp_token" value="TU_TOKEN_API_WHATICKET">
                </div>
                <div class="mb-3">
                    <label for="whatsapp_endpoint" class="form-label">Endpoint de API (Whaticket)</label>
                    <input type="url" class="form-control" id="whatsapp_endpoint" name="whatsapp_endpoint" value="https://apiwhaticket.streamdigi.co/api/messages/send">
                    <div class="form-text">URL de tu instancia de Whaticket para enviar mensajes.</div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="whatsapp_userId" class="form-label">ID de Usuario (Whaticket)</label>
                        <input type="text" class="form-control" id="whatsapp_userId" name="whatsapp_userId" placeholder="Opcional">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="whatsapp_queueId" class="form-label">ID de Fila (Whaticket)</label>
                        <input type="text" class="form-control" id="whatsapp_queueId" name="whatsapp_queueId" placeholder="Opcional">
                    </div>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="whatsapp_sendSignature" name="whatsapp_sendSignature" value="1">
                    <label class="form-check-label" for="whatsapp_sendSignature">Enviar Firma en mensajes</label>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="whatsapp_closeTicket" name="whatsapp_closeTicket" value="1">
                    <label class="form-check-label" for="whatsapp_closeTicket">Cerrar Ticket automáticamente</label>
                </div>

                <hr>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="whatsapp_expiry_alert_days" class="form-label">Días para Alerta de Expiración</label>
                        <input type="number" class="form-control" id="whatsapp_expiry_alert_days" name="whatsapp_expiry_alert_days" value="3" min="1">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="whatsapp_daily_check_hour" class="form-label">Hora de Verificación Diaria (HH:MM)</label>
                        <input type="time" class="form-control" id="whatsapp_daily_check_hour" name="whatsapp_daily_check_hour" value="09:00">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="whatsapp_company_name" class="form-label">Nombre de tu Empresa</label>
                    <input type="text" class="form-control" id="whatsapp_company_name" name="whatsapp_company_name" value="Tu Empresa">
                </div>
                <div class="mb-3">
                    <label for="whatsapp_support_phone" class="form-label">Teléfono de Soporte (con código de país, sin +)</label>
                    <input type="text" class="form-control" id="whatsapp_support_phone" name="whatsapp_support_phone" value="573232405812" placeholder="Ej: 573232405812">
                    <div class="form-text">Número al que los clientes pueden contactar para soporte.</div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>Guardar Configuración de WhatsApp
                </button>
            </form>

        <?php elseif ($install_step === 'complete'): // Paso final: Instalación completada ?>
            <h4 class="text-success text-center"><i class="fas fa-check-circle me-2"></i>¡Instalación Completada!</h4>
            <p>Tu Sistema de Licencias ha sido instalado y configurado exitosamente.</p>
            <p>Ahora puedes acceder a tu panel de administración con las credenciales que creaste.</p>
            
            <div class="alert alert-warning mt-4">
                <h5><i class="fas fa-exclamation-triangle me-2"></i>Paso IMPORTANTE de Seguridad</h5>
                <p>Por favor, **elimina o renombra el archivo `install.php`** de tu servidor inmediatamente. Dejarlo expuesto permitiría a cualquier persona ejecutar el instalador nuevamente y resetear tu sistema.</p>
            </div>
            
            <div class="text-center mt-4">
                <a href="Psnel_administracion.php" class="btn btn-success btn-lg">
                    <i class="fas fa-sign-in-alt me-2"></i>Ir al Panel de Administración
                </a>
            </div>
            <div class="mt-3 text-center">
                 <small class="text-muted">No olvides configurar el cron job para `whatsapp_notifier.php` si deseas enviar notificaciones automáticas de WhatsApp por expiración de licencias.</small>
            </div>

        <?php elseif ($install_step === 'error'): // Error general ?>
            <h4><i class="fas fa-times-circle text-danger me-2"></i>Error de Instalación</h4>
            <p>Hubo un problema durante la instalación. Por favor, revisa los mensajes de error anteriores y corrige los problemas antes de intentar nuevamente.</p>
            <div class="text-center mt-4">
                <a href="?step=0" class="btn btn-secondary">
                    <i class="fas fa-redo me-2"></i>Reintentar Instalación
                </a>
            </div>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>