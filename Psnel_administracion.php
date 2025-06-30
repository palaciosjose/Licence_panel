<?php
/**
 * Panel de Administración del Servidor de Licencias
 * Version: 1.1 - Con teléfono y sistema de períodos
 */

session_start();

// Configuración de la base de datos del servidor de licencias
require_once 'config.php'; 

$license_db_config = [
    'host'     => DB_HOST,
    'username' => DB_USER,
    'password' => DB_PASS,
    'database' => DB_NAME
];

require_once 'whatsapp_config.php';
require_once 'LicenseManager.class.php'; // Asegúrate de incluir la clase LicenseManager externa.

// Inicializar el gestor de licencias
// Se le pasa la configuración de WhatsApp para que LicenseManager pueda usarla en sus métodos.
$licenseManager = new LicenseManager($license_db_config, $whatsapp_config);

// Manejar logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Manejar login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($licenseManager->authenticate($username, $password)) {
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $login_error = "Credenciales inválidas";
    }
}

// Verificar autenticación
if (!$licenseManager->isLoggedIn()) {
    // Mostrar formulario de login
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Servidor de Licencias - Login</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/flatly/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="assets/css/custom.css">
        <style>
            body { min-height: 100vh; }
            .login-card { border-radius: 0.75rem; }
        </style>
    </head>
    <body class="bg-gradient d-flex align-items-center justify-content-center">
        <div class="login-card p-4" style="width: 100%; max-width: 400px;">
            <div class="text-center mb-4">
                <i class="fas fa-key fa-3x text-primary mb-3"></i>
                <h2>Servidor de Licencias</h2>
                <p class="text-muted">Acceso al Panel de Administración v1.1</p>
            </div>
            
            <?php if (isset($login_error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?= htmlspecialchars($login_error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="mb-3">
                    <label for="username" class="form-label">Usuario</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control" name="username" required>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                </div>
                
                <button type="submit" name="login" class="btn btn-primary w-100">
                    <i class="fas fa-sign-in-alt me-2"></i>Iniciar Sesión
                </button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Manejar acciones del panel
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_license'])) {
        $result = $licenseManager->createLicense($_POST);
        if ($result['success']) {
            $success_message = "Licencia creada exitosamente. Clave: " . $result['license_key'];
            // Comprobamos si la clave 'expires_at' existe y no está vacía
            if (!empty($result['expires_at'])) { 
                $success_message .= "<br>Válida desde: " . date('d/m/Y', strtotime($result['start_date']));
                $success_message .= "<br>Expira: " . date('d/m/Y', strtotime($result['expires_at']));
            }
        } else {
            $error_message = "Error al crear licencia: " . $result['error'];
        }
    }
    
    if (isset($_POST['update_license'])) {
        $license_id = (int)$_POST['edit_license_id'];
        // Modificación: Llamar a updateLicense desde LicenseManager para aprovechar la lógica de WhatsApp
        // Se necesita pasar un array con todos los datos necesarios para la actualización.
        $update_data = [
            'id' => $license_id,
            'client_name' => $_POST['client_name'],
            'client_email' => $_POST['client_email'],
            'client_phone' => $_POST['client_phone'],
            'product_name' => $_POST['product_name'],
            'version' => $_POST['version'],
            'license_type' => $_POST['license_type'],
            'max_domains' => $_POST['max_domains'],
            'start_date' => $_POST['start_date'],
            'duration_days' => $_POST['duration_days'],
            'custom_duration' => $_POST['custom_duration'] ?? null, // Asegúrate de pasar esto si es 'custom'
            'status' => $_POST['status'],
            'notes' => $_POST['notes']
        ];
        $result = $licenseManager->updateLicense($update_data); // Pasamos un solo array $update_data
        if ($result['success']) {
            $success_message = "Licencia actualizada exitosamente";
            if ($result['expires_at']) {
                $success_message .= "<br>Nueva fecha de expiración: " . date('d/m/Y H:i', strtotime($result['expires_at']));
            }
        } else {
            $error_message = "Error al actualizar licencia: " . ($result['error'] ?? 'Desconocido');
        }
    }
    
    if (isset($_POST['update_status'])) {
        $license_id = (int)$_POST['license_id'];
        $status = $_POST['status'];
        if ($licenseManager->updateLicenseStatus($license_id, $status)) {
            $success_message = "Estado de licencia actualizado";
        } else {
            $error_message = "Error al actualizar estado";
        }
    }
    
    if (isset($_POST['update_period'])) {
        $license_id = (int)$_POST['license_id'];
        $start_date = $_POST['start_date'];
        $duration_days = (int)$_POST['duration_days'];
        if ($licenseManager->updateLicensePeriod($license_id, $start_date, $duration_days)) {
            $success_message = "Período de licencia actualizado";
        } else {
            $error_message = "Error al actualizar período";
        }
    }
    
    if (isset($_POST['delete_license'])) {
        $license_id = (int)$_POST['license_id'];
        if ($licenseManager->deleteLicense($license_id)) {
            $success_message = "Licencia eliminada";
        } else {
            $error_message = "Error al eliminar licencia";
        }
    }

    // Manejar el bloqueo de activación desde el panel
    if (isset($_POST['block_activation'])) {
        $activation_id = (int)($_POST['activation_id'] ?? 0);
        if ($activation_id > 0) {
            // Asumiendo que LicenseManager tiene acceso a la conexión para esto o un método para ello
            $conn = $licenseManager->getDbConnection(); // Obtener la conexión
            $stmt = $conn->prepare("UPDATE license_activations SET status = 'blocked' WHERE id = ?");
            $stmt->bind_param("i", $activation_id);
            if ($stmt->execute()) {
                $success_message = "Activación bloqueada exitosamente.";
            } else {
                $error_message = "Error al bloquear la activación: " . $conn->error;
            }
        } else {
            $error_message = "ID de activación inválido para bloquear.";
        }
    }
    
    // Manejar limpieza de logs antiguos desde el panel
    if (isset($_POST['clear_old_logs'])) {
        $days_old = 90; // Define cuántos días atrás limpiar
        $conn = $licenseManager->getDbConnection(); // Obtener la conexión
        $stmt = $conn->prepare("DELETE FROM license_logs WHERE created_at < NOW() - INTERVAL ? DAY");
        $stmt->bind_param("i", $days_old);
        if ($stmt->execute()) {
            $success_message = "Logs antiguos (más de 90 días) eliminados exitosamente.";
        } else {
            $error_message = "Error al eliminar logs antiguos: " . $conn->error;
        }
    }
}

// Obtener datos para el dashboard
$stats = $licenseManager->getLicenseStats();
$recent_licenses = $licenseManager->getLicenses(10);
$recent_logs = $licenseManager->getRecentLogs(20);
$recent_activations = $licenseManager->getActivations();
$expiring_licenses = $licenseManager->getExpiringLicenses(30);

$current_tab = $_GET['tab'] ?? 'dashboard';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Servidor de Licencias - Panel de Administración v1.1</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/flatly/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <style>
        .sidebar { background: #0d6efd; min-height: 100vh; }
        .nav-link { color: rgba(255,255,255,0.8) !important; }
        .nav-link:hover, .nav-link.active { color: white !important; background: rgba(255,255,255,0.1); }
        .stat-card { border-left: 4px solid #0d6efd; }
        .table-actions { white-space: nowrap; }
        .license-status-active { background-color: #d4edda; }
        .license-status-expired { background-color: #f8d7da; }
        .license-status-expiring { background-color: #fff3cd; }
    </style>
</head>
<body class="bg-gradient">
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-3 col-lg-2 px-0">
                <div class="sidebar p-3">
                    <div class="text-center mb-4">
                        <i class="fas fa-key fa-2x text-white mb-2"></i>
                        <h5 class="text-white">Servidor de Licencias</h5>
                        <small class="text-light">v1.1</small>
                    </div>
                    
                    <ul class="nav nav-pills flex-column">
                        <li class="nav-item">
                            <a class="nav-link <?= $current_tab === 'dashboard' ? 'active' : '' ?>" href="?tab=dashboard">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_tab === 'licenses' ? 'active' : '' ?>" href="?tab=licenses">
                                <i class="fas fa-certificate me-2"></i>Licencias
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_tab === 'expiring' ? 'active' : '' ?>" href="?tab=expiring">
                                <i class="fas fa-exclamation-triangle me-2"></i>Por Expirar
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_tab === 'activations' ? 'active' : '' ?>" href="?tab=activations">
                                <i class="fas fa-plug me-2"></i>Activaciones
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_tab === 'logs' ? 'active' : '' ?>" href="?tab=logs">
                                <i class="fas fa-list-alt me-2"></i>Logs
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_tab === 'verifications' ? 'active' : '' ?>" href="?tab=verifications">
                                <i class="fas fa-shield-check me-2"></i>Dashboard Verificaciones
                            </a>
                        </li>
                        <hr class="text-light">
                        <li class="nav-item">
                            <a class="nav-link" href="?logout=1">
                                <i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión
                            </a>
                        </li>
                    </ul>
                    
                    <div class="mt-4 text-center">
                        <small class="text-light">
                            Usuario: <?= htmlspecialchars($_SESSION['license_admin']['username']) ?>
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-9 col-lg-10">
                <div class="p-4">
                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i>
                            <?= $success_message ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?= htmlspecialchars($error_message) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($current_tab === 'dashboard'): ?>
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h2>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-3 mb-3">
                                <div class="card stat-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <p class="card-title text-muted mb-1">Total Licencias</p>
                                                <h3 class="mb-0"><?= $stats['total_licenses'] ?? 0 ?></h3>
                                            </div>
                                            <div class="text-primary">
                                                <i class="fas fa-certificate fa-2x"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <div class="card stat-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <p class="card-title text-muted mb-1">Licencias Activas</p>
                                                <h3 class="mb-0 text-success"><?= $stats['active_licenses'] ?? 0 ?></h3>
                                            </div>
                                            <div class="text-success">
                                                <i class="fas fa-check-circle fa-2x"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <div class="card stat-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <p class="card-title text-muted mb-1">Por Expirar (30d)</p>
                                                <h3 class="mb-0 text-warning"><?= $stats['expiring_soon'] ?? 0 ?></h3>
                                            </div>
                                            <div class="text-warning">
                                                <i class="fas fa-exclamation-triangle fa-2x"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <div class="card stat-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <p class="card-title text-muted mb-1">Activaciones</p>
                                                <h3 class="mb-0"><?= $stats['total_activations'] ?? 0 ?></h3>
                                            </div>
                                            <div class="text-info">
                                                <i class="fas fa-plug fa-2x"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-lg-8 mb-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-certificate me-2"></i>Licencias Recientes</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Cliente</th>
                                                        <th>Teléfono</th>
                                                        <th>Clave</th>
                                                        <th>Estado</th>
                                                        <th>Expira</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recent_licenses as $license): ?>
                                                        <tr class="<?= $license['calculated_status'] === 'expired' ? 'license-status-expired' : ($license['days_remaining'] <= 7 && $license['days_remaining'] > 0 ? 'license-status-expiring' : '') ?>">
                                                            <td>
                                                                <strong><?= htmlspecialchars($license['client_name']) ?></strong><br>
                                                                <small class="text-muted"><?= htmlspecialchars($license['client_email']) ?></small>
                                                            </td>
                                                            <td>
                                                                <?php if ($license['client_phone']): ?>
                                                                    <i class="fas fa-phone me-1"></i>
                                                                    <?= htmlspecialchars($license['client_phone']) ?>
                                                                <?php else: ?>
                                                                    <small class="text-muted">N/A</small>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><code><?= htmlspecialchars(substr($license['license_key'], 0, 20)) ?>...</code></td>
                                                            <td>
                                                                <?php
                                                                $status_colors = [
                                                                    'active' => 'success',
                                                                    'suspended' => 'warning', 
                                                                    'expired' => 'danger',
                                                                    'revoked' => 'dark'
                                                                ];
                                                                $color = $status_colors[$license['status']] ?? 'secondary';
                                                                ?>
                                                                <span class="badge bg-<?= $color ?>"><?= ucfirst($license['status']) ?></span>
                                                            </td>
                                                            <td>
                                                                <?php if ($license['expires_at']): ?>
                                                                    <?= date('d/m/Y', strtotime($license['expires_at'])) ?>
                                                                    <?php if ($license['days_remaining'] > 0): ?>
                                                                        <br><small class="text-muted">(<?= $license['days_remaining'] ?> días)</small>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="badge bg-info">Permanente</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-lg-4 mb-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="fas fa-list-alt me-2"></i>Actividad Reciente</h5>
                                    </div>
                                    <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                                        <?php foreach (array_slice($recent_logs, 0, 10) as $log): ?>
                                            <div class="border-bottom py-2">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <small class="text-muted"><?= date('H:i', strtotime($log['created_at'])) ?></small>
                                                        <p class="mb-1 small">
                                                            <?php if ($log['client_name']): ?>
                                                                <strong><?= htmlspecialchars($log['client_name']) ?></strong>
                                                                <?php if ($log['client_phone']): ?>
                                                                    <br><small class="text-muted"><?= htmlspecialchars($log['client_phone']) ?></small>
                                                                <?php endif; ?>
                                                                <br>
                                                            <?php endif; ?>
                                                            <?= htmlspecialchars($log['action']) ?>: <?= htmlspecialchars($log['message']) ?>
                                                        </p>
                                                    </div>
                                                    <span class="badge bg-<?= $log['status'] === 'success' ? 'success' : 'danger' ?> ms-2">
                                                        <?= $log['status'] ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($current_tab === 'licenses'): ?>
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2><i class="fas fa-certificate me-2"></i>Gestión de Licencias</h2>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createLicenseModal">
                                <i class="fas fa-plus me-2"></i>Nueva Licencia
                            </button>
                        </div>
                        
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Cliente</th>
                                                <th>Contacto</th>
                                                <th>Clave de Licencia</th>
                                                <th>Tipo</th>
                                                <th>Período</th>
                                                <th>Estado</th>
                                                <th>Activaciones</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $all_licenses = $licenseManager->getLicenses(100);
                                            foreach ($all_licenses as $license): 
                                            ?>
                                                <tr class="<?= $license['calculated_status'] === 'expired' ? 'license-status-expired' : ($license['days_remaining'] <= 7 && $license['days_remaining'] > 0 ? 'license-status-expiring' : '') ?>">
                                                    <td>
                                                        <strong><?= htmlspecialchars($license['client_name']) ?></strong><br>
                                                        <small class="text-muted"><?= htmlspecialchars($license['client_email']) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if ($license['client_phone']): ?>
                                                            <i class="fas fa-phone me-1"></i>
                                                            <?= htmlspecialchars($license['client_phone']) ?>
                                                        <?php else: ?>
                                                            <small class="text-muted">N/A</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><code><?= htmlspecialchars($license['license_key']) ?></code></td>
                                                    <td><?= ucfirst($license['license_type']) ?></td>
                                                    <td>
                                                        <?php if ($license['start_date']): ?>
                                                            <small>
                                                                <strong>Inicio:</strong> <?= date('d/m/Y', strtotime($license['start_date'])) ?><br>
                                                                <?php if ($license['duration_days']): ?>
                                                                    <strong>Duración:</strong> <?= $license['duration_days'] ?> días<br>
                                                                    <strong>Expira:</strong> <?= date('d/m/Y', strtotime($license['expires_at'])) ?>
                                                                    <?php if ($license['days_remaining'] > 0): ?>
                                                                        <br><span class="text-warning">(<?= $license['days_remaining'] ?> días restantes)</span>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="badge bg-info">Permanente</span>
                                                                <?php endif; ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <span class="badge bg-info">Permanente</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $status_colors = [
                                                            'active' => 'success',
                                                            'suspended' => 'warning', 
                                                            'expired' => 'danger',
                                                            'revoked' => 'dark'
                                                        ];
                                                        $color = $status_colors[$license['status']] ?? 'secondary';
                                                        ?>
                                                        <span class="badge bg-<?= $color ?>"><?= ucfirst($license['status']) ?></span>
                                                        <?php if ($license['calculated_status'] === 'expired'): ?>
                                                            <br><small class="text-danger">Expirada</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-info"><?= $license['active_activations'] ?? '0' ?></span>
                                                        /
                                                        <span class="text-muted"><?= $license['max_domains'] ?></span>
                                                    </td>
                                                    <td class="table-actions">
                                                        <div class="btn-group" role="group">
                                                            <button class="btn btn-sm btn-outline-primary" onclick="editLicense(<?= $license['id'] ?>)">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button class="btn btn-sm btn-outline-warning" onclick="editPeriod(<?= $license['id'] ?>, '<?= $license['start_date'] ?>', <?= $license['duration_days'] ?: 0 ?>)">
                                                                <i class="fas fa-calendar-alt"></i>
                                                            </button>
                                                            <button class="btn btn-sm btn-outline-info" onclick="viewActivations(<?= $license['id'] ?>)">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteLicense(<?= $license['id'] ?>, '<?= htmlspecialchars($license['client_name']) ?>')">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($current_tab === 'expiring'): ?>
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2><i class="fas fa-exclamation-triangle me-2"></i>Licencias por Expirar</h2>
                        </div>
                        
                        <div class="card">
                            <div class="card-body">
                                <?php if (!empty($expiring_licenses)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Cliente</th>
                                                    <th>Contacto</th>
                                                    <th>Clave de Licencia</th>
                                                    <th>Expira</th>
                                                    <th>Días Restantes</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($expiring_licenses as $license): ?>
                                                    <tr class="<?= $license['days_remaining'] <= 7 ? 'license-status-expiring' : '' ?>">
                                                        <td>
                                                            <strong><?= htmlspecialchars($license['client_name']) ?></strong><br>
                                                            <small class="text-muted"><?= htmlspecialchars($license['client_email']) ?></small>
                                                        </td>
                                                        <td>
                                                            <?php if ($license['client_phone']): ?>
                                                                <i class="fas fa-phone me-1"></i>
                                                                <a href="tel:<?= htmlspecialchars($license['client_phone']) ?>"><?= htmlspecialchars($license['client_phone']) ?></a>
                                                            <?php else: ?>
                                                                <small class="text-muted">N/A</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><code><?= htmlspecialchars($license['license_key']) ?></code></td>
                                                        <td><?= date('d/m/Y H:i', strtotime($license['expires_at'])) ?></td>
                                                        <td>
                                                            <?php
                                                            $days = $license['days_remaining'];
                                                            $class = $days <= 3 ? 'danger' : ($days <= 7 ? 'warning' : 'info');
                                                            ?>
                                                            <span class="badge bg-<?= $class ?>"><?= $days ?> días</span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <button class="btn btn-sm btn-outline-success" onclick="extendLicense(<?= $license['id'] ?>)">
                                                                    <i class="fas fa-calendar-plus"></i> Extender
                                                                </button>
                                                                <button class="btn btn-sm btn-outline-primary" onclick="contactClient('<?= htmlspecialchars($license['client_phone']) ?>', '<?= htmlspecialchars($license['client_name']) ?>')">
                                                                    <i class="fas fa-phone"></i> Contactar
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                        <h5>¡Excelente!</h5>
                                        <p class="text-muted">No hay licencias próximas a expirar en los próximos 30 días.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($current_tab === 'activations'): ?>
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2><i class="fas fa-plug me-2"></i>Activaciones de Licencias</h2>
                            <?php if (isset($_GET['license'])): ?>
                                <a href="?tab=activations" class="btn btn-secondary">
                                    <i class="fas fa-list me-2"></i>Ver Todas
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <?php
                        $license_filter = isset($_GET['license']) ? (int)$_GET['license'] : null;
                        $activations = $licenseManager->getActivations($license_filter);
                        ?>
                        
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Cliente</th>
                                                <th>Contacto</th>
                                                <th>Clave de Licencia</th>
                                                <th>Dominio</th>
                                                <th>IP</th>
                                                <th>Estado</th>
                                                <th>Activada</th>
                                                <th>Última Verificación</th>
                                                <th>Verificaciones</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (!empty($activations)): ?>
                                                <?php foreach ($activations as $activation): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($activation['client_name']) ?></strong>
                                                        </td>
                                                        <td>
                                                            <?php if ($activation['client_phone']): ?>
                                                                <i class="fas fa-phone me-1"></i>
                                                                <?= htmlspecialchars($activation['client_phone']) ?>
                                                            <?php else: ?>
                                                                <small class="text-muted">N/A</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <code><?= htmlspecialchars(substr($activation['license_key'], 0, 20)) ?>...</code>
                                                        </td>
                                                        <td>
                                                            <i class="fas fa-globe me-2"></i>
                                                            <?= htmlspecialchars($activation['domain']) ?>
                                                        </td>
                                                        <td>
                                                            <small class="text-muted"><?= htmlspecialchars($activation['ip_address']) ?></small>
                                                        </td>
                                                        <td>
                                                            <?php
                                                            $status_colors = [
                                                                'active' => 'success',
                                                                'inactive' => 'warning',
                                                                'blocked' => 'danger'
                                                            ];
                                                            $color = $status_colors[$activation['status']] ?? 'secondary';
                                                            ?>
                                                            <span class="badge bg-<?= $color ?>"><?= ucfirst($activation['status']) ?></span>
                                                        </td>
                                                        <td>
                                                            <small><?= date('d/m/Y H:i', strtotime($activation['activated_at'])) ?></small>
                                                        </td>
                                                        <td>
                                                            <small><?= date('d/m/Y H:i', strtotime($activation['last_check'])) ?></small>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?= $activation['check_count'] ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group" role="group">
                                                                <button class="btn btn-sm btn-outline-info" onclick="viewActivationDetails(<?= $activation['id'] ?>)">
                                                                    <i class="fas fa-eye"></i>
                                                                </button>
                                                                <?php if ($activation['status'] === 'active'): ?>
                                                                    <button class="btn btn-sm btn-outline-warning" onclick="blockActivationConfirm(<?= $activation['id'] ?>)">
                                                                        <i class="fas fa-ban"></i>
                                                                    </button>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="10" class="text-center py-4">
                                                        <i class="fas fa-plug fa-2x text-muted mb-2"></i>
                                                        <p class="text-muted mb-0">No hay activaciones registradas</p>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($current_tab === 'logs'): ?>
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2><i class="fas fa-list-alt me-2"></i>Logs del Sistema</h2>
                            <div class="btn-group" role="group">
                                <button class="btn btn-outline-secondary" onclick="refreshLogs()">
                                    <i class="fas fa-sync me-2"></i>Actualizar
                                </button>
                                <button class="btn btn-outline-danger" onclick="clearOldLogsConfirm()">
                                    <i class="fas fa-trash me-2"></i>Limpiar Antiguos
                                </button>
                            </div>
                        </div>
                        
                        <div class="card mb-4">
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <input type="hidden" name="tab" value="logs">
                                    <div class="col-md-3">
                                        <select class="form-select" name="action_filter">
                                            <option value="">Todas las acciones</option>
                                            <option value="activation" <?= ($_GET['action_filter'] ?? '') === 'activation' ? 'selected' : '' ?>>Activaciones</option>
                                            <option value="verification" <?= ($_GET['action_filter'] ?? '') === 'verification' ? 'selected' : '' ?>>Verificaciones</option>
                                            <option value="deactivation" <?= ($_GET['action_filter'] ?? '') === 'deactivation' ? 'selected' : '' ?>>Desactivaciones</option>
                                            <option value="error" <?= ($_GET['action_filter'] ?? '') === 'error' ? 'selected' : '' ?>>Errores</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select" name="status_filter">
                                            <option value="">Todos los estados</option>
                                            <option value="success" <?= ($_GET['status_filter'] ?? '') === 'success' ? 'selected' : '' ?>>Éxito</option>
                                            <option value="failure" <?= ($_GET['status_filter'] ?? '') === 'failure' ? 'selected' : '' ?>>Fallo</option>
                                            <option value="warning" <?= ($_GET['status_filter'] ?? '') === 'warning' ? 'selected' : '' ?>>Advertencia</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <input type="text" class="form-control" name="search" placeholder="Buscar en logs..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="fas fa-search"></i> Filtrar
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm">
                                        <thead>
                                            <tr>
                                                <th>Fecha/Hora</th>
                                                <th>Cliente</th>
                                                <th>Contacto</th>
                                                <th>Acción</th>
                                                <th>Estado</th>
                                                <th>Mensaje</th>
                                                <th>IP</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_logs as $log): ?>
                                                <tr>
                                                    <td>
                                                        <small><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if ($log['client_name']): ?>
                                                            <small><?= htmlspecialchars($log['client_name']) ?></small>
                                                        <?php else: ?>
                                                            <small class="text-muted">-</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($log['client_phone']): ?>
                                                            <small><?= htmlspecialchars($log['client_phone']) ?></small>
                                                        <?php else: ?>
                                                            <small class="text-muted">-</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $action_icons = [
                                                            'activation' => 'fas fa-plug text-success',
                                                            'verification' => 'fas fa-check-circle text-info',
                                                            'deactivation' => 'fas fa-unlink text-warning',
                                                            'error' => 'fas fa-exclamation-triangle text-danger'
                                                        ];
                                                        $icon = $action_icons[$log['action']] ?? 'fas fa-info';
                                                        ?>
                                                        <i class="<?= $icon ?> me-1"></i>
                                                        <small><?= ucfirst($log['action']) ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?= $log['status'] === 'success' ? 'success' : ($log['status'] === 'warning' ? 'warning' : 'danger') ?> badge-sm">
                                                            <?= ucfirst($log['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <small><?= htmlspecialchars(substr($log['message'], 0, 60)) ?><?= strlen($log['message']) > 60 ? '...' : '' ?></small>
                                                    </td>
                                                    <td>
                                                        <small class="text-muted"><?= htmlspecialchars($log['ip_address']) ?></small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($current_tab === 'verifications'): ?>
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h2><i class="fas fa-shield-check me-2"></i>Verificaciones de Licencias</h2>
                            <div class="btn-group" role="group">
                                <button class="btn btn-outline-primary" onclick="refreshVerifications()" id="refreshBtn">
                                    <i class="fas fa-sync me-2"></i>Actualizar
                                </button>
                                <button class="btn btn-outline-info" onclick="toggleAutoRefresh()" id="autoRefreshBtn">
                                    <i class="fas fa-play me-2"></i>Auto-actualizar
                                </button>
                            </div>
                        </div>
                        
                        <div class="row mb-4" id="verificationStats">
                            <div class="col-md-3 mb-3">
                                <div class="card border-primary">
                                    <div class="card-body text-center">
                                        <i class="fas fa-check-circle fa-2x text-primary mb-2"></i>
                                        <h4 class="text-primary" id="totalVerifications">-</h4>
                                        <p class="mb-0">Verificaciones Totales</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <div class="card border-success">
                                    <div class="card-body text-center">
                                        <i class="fas fa-clock fa-2x text-success mb-2"></i>
                                        <h4 class="text-success" id="verifications24h">-</h4>
                                        <p class="mb-0">Últimas 24 horas</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <div class="card border-info">
                                    <div class="card-body text-center">
                                        <i class="fas fa-history fa-2x text-info mb-2"></i>
                                        <h4 class="text-info" id="verifications1h">-</h4>
                                        <p class="mb-0">Última hora</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <div class="card border-warning">
                                    <div class="card-body text-center">
                                        <i class="fas fa-calendar-week fa-2x text-warning mb-2"></i>
                                        <h4 class="text-warning" id="verifications7d">-</h4>
                                        <p class="mb-0">Última semana</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">
                                            <i class="fas fa-circle-notch fa-spin me-2"></i>Actividad en Tiempo Real
                                            <span class="badge bg-success ms-2" id="liveIndicator">EN VIVO</span>
                                        </h5>
                                        <small class="text-muted">Últimos 5 minutos</small>
                                    </div>
                                    <div class="card-body" style="max-height: 400px; overflow-y: auto;" id="liveActivity">
                                        <div class="text-center text-muted py-4">
                                            <i class="fas fa-spinner fa-spin fa-2x mb-3"></i>
                                            <p>Cargando actividad...</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0">
                                            <i class="fas fa-chart-line me-2"></i>Estado de Activaciones
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="table-responsive">
                                            <table class="table table-sm" id="activationStatusTable">
                                                <thead>
                                                    <tr>
                                                        <th>Dominio</th>
                                                        <th>Cliente</th>
                                                        <th>Última Verificación</th>
                                                        <th>Estado</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td colspan="4" class="text-center text-muted">Cargando...</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-history me-2"></i>Historial de Verificaciones
                                </h5>
                                <div class="btn-group btn-group-sm" role="group">
                                    <input type="checkbox" class="btn-check" id="successOnly" autocomplete="off">
                                    <label class="btn btn-outline-success" for="successOnly">Solo Exitosas</label>
                                    
                                    <input type="checkbox" class="btn-check" id="failuresOnly" autocomplete="off">
                                    <label class="btn btn-outline-danger" for="failuresOnly">Solo Fallos</label>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover table-sm" id="verificationsTable">
                                        <thead>
                                            <tr>
                                                <th>Fecha/Hora</th>
                                                <th>Cliente</th>
                                                <th>Dominio</th>
                                                <th>IP</th>
                                                <th>Estado</th>
                                                <th>Mensaje</th>
                                                <th>Software</th>
                                                <th>Verificaciones</th>
                                            </tr>
                                        </thead>
                                        <tbody id="verificationsTableBody">
                                            <tr>
                                                <td colspan="8" class="text-center text-muted py-4">
                                                    <i class="fas fa-spinner fa-spin me-2"></i>Cargando verificaciones...
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <script>
                            let autoRefreshInterval = null;
                            let isAutoRefreshActive = false;
                            
                            // Cargar datos iniciales al entrar a la pestaña
                            document.addEventListener('DOMContentLoaded', function() {
                                // Solo cargar si la pestaña actual es 'verifications'
                                const urlParams = new URLSearchParams(window.location.search);
                                const currentTab = urlParams.get('tab') || 'dashboard'; // Valor por defecto
                                
                                if (currentTab === 'verifications') {
                                    loadVerificationStats();
                                    loadRecentVerifications();
                                    loadLiveActivity();
                                    loadActivationStatus();
                                }
                            });
                            
                            // Cargar estadísticas de verificaciones
                            function loadVerificationStats() {
                                fetch('api_admin.php?action=get_verification_stats')
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            const stats = data.stats;
                                            document.getElementById('totalVerifications').textContent = stats.total_verifications;
                                            document.getElementById('verifications24h').textContent = stats.verifications_24h;
                                            document.getElementById('verifications1h').textContent = stats.verifications_1h;
                                            document.getElementById('verifications7d').textContent = stats.verifications_7d;
                                        }
                                    })
                                    .catch(error => console.error('Error loading verification stats:', error));
                            }
                            
                            // Cargar verificaciones recientes
                            function loadRecentVerifications() {
                                const successOnly = document.getElementById('successOnly').checked;
                                const failuresOnly = document.getElementById('failuresOnly').checked;
                                let statusFilter = null;
                                if (successOnly) {
                                    statusFilter = 'success';
                                } else if (failuresOnly) {
                                    statusFilter = 'failure';
                                }

                                fetch(`api_admin.php?action=get_recent_verifications&limit=50` + (statusFilter ? `&status_filter=${statusFilter}` : ''))
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            updateVerificationsTable(data.verifications);
                                        }
                                    })
                                    .catch(error => console.error('Error loading verifications:', error));
                            }
                            
                            // Actualizar tabla de verificaciones
                            function updateVerificationsTable(verifications) {
                                const tbody = document.getElementById('verificationsTableBody');
                                
                                if (verifications.length === 0) {
                                    tbody.innerHTML = `
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">
                                                <i class="fas fa-search me-2"></i>No hay verificaciones que mostrar
                                            </td>
                                        </tr>
                                    `;
                                    return;
                                }
                                
                                tbody.innerHTML = verifications.map(verification => {
                                    const statusBadge = verification.status === 'success' ? 
                                        '<span class="badge bg-success">Éxito</span>' : 
                                        '<span class="badge bg-danger">Fallo</span>';
                                    
                                    const timeAgo = getTimeAgo(verification.created_at);
                                    
                                    // Extraer información del software y versión desde request_data (si existe)
                                    let software = 'N/A';
                                    let version = '';
                                    if (verification.request_data) {
                                        try {
                                            const reqData = JSON.parse(verification.request_data);
                                            // Asumiendo que 'software' y 'version' están en el objeto 'params' dentro de 'request_data'
                                            software = reqData.params?.software || 'N/A';
                                            version = reqData.params?.version || '';
                                        } catch (e) {
                                            console.warn("Error parsing request_data:", e);
                                        }
                                    }

                                    return `
                                        <tr class="${verification.status === 'failure' ? 'table-warning' : ''}">
                                            <td>
                                                <small>${new Date(verification.created_at).toLocaleString('es-ES')}</small>
                                                <br><span class="text-muted">${timeAgo}</span>
                                            </td>
                                            <td>
                                                ${verification.client_name ? 
                                                    `<strong>${verification.client_name}</strong>` : 
                                                    '<span class="text-muted">-</span>'
                                                }
                                                ${verification.client_phone ? 
                                                    `<br><small><i class="fas fa-phone me-1"></i>${verification.client_phone}</small>` : 
                                                    ''
                                                }
                                            </td>
                                            <td>
                                                ${verification.domain ? 
                                                    `<i class="fas fa-globe me-1"></i>${verification.domain}` : 
                                                    '<span class="text-muted">-</span>'
                                                }
                                            </td>
                                            <td><small>${verification.ip_address}</small></td>
                                            <td>${statusBadge}</td>
                                            <td>
                                                <small>${verification.message}</small>
                                            </td>
                                            <td>
                                                <small>${software} ${version}</small>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-info" 
                                                        onclick="showVerificationDetails('${verification.license_key}', '${verification.domain}')">
                                                    <i class="fas fa-info-circle"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    `;
                                }).join('');
                            }
                            
                            // Cargar actividad en tiempo real (últimos 5 minutos)
                            function loadLiveActivity() {
                                fetch('api_admin.php?action=get_live_activity&minutes=5')
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            updateLiveActivity(data.activity);
                                        }
                                    })
                                    .catch(error => console.error('Error loading live activity:', error));
                            }
                            
                            // Actualizar actividad en tiempo real
                            function updateLiveActivity(activity) {
                                const container = document.getElementById('liveActivity');
                                
                                if (activity.length === 0) {
                                    container.innerHTML = `
                                        <div class="text-center text-muted py-4">
                                            <i class="fas fa-clock fa-2x mb-2"></i>
                                            <p class="mb-0">Sin actividad reciente</p>
                                            <small>Últimos 5 minutos</small>
                                        </div>
                                    `;
                                    return;
                                }
                                
                                container.innerHTML = activity.map(item => {
                                    const timeAgo = getTimeAgo(item.created_at);
                                    const icon = getActionIcon(item.action);
                                    const badgeColor = item.status === 'success' ? 'success' : 'danger';
                                    
                                    return `
                                        <div class="border-bottom py-2">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <div class="d-flex align-items-center mb-1">
                                                        <i class="${icon} me-2 text-${badgeColor}"></i>
                                                        <strong>${item.action}</strong>
                                                        <span class="badge bg-${badgeColor} ms-2">${item.status}</span>
                                                    </div>
                                                    ${item.client_name ? 
                                                        `<p class="mb-1"><strong>${item.client_name}</strong>` + 
                                                        (item.client_phone ? ` <small><i class="fas fa-phone me-1"></i>${item.client_phone}</small>` : '') + 
                                                        `</p>` : ''
                                                    }
                                                    <p class="mb-0 small text-muted">${item.message}</p>
                                                </div>
                                                <small class="text-muted">${timeAgo}</small>
                                            </div>
                                        </div>
                                    `;
                                }).join('');
                            }

                            // Nueva función para cargar estado de activaciones (últimas 20 activas)
                            function loadActivationStatus() {
                                fetch('api_admin.php?action=get_activations') // Reutiliza el endpoint de activaciones, filtrando por activas
                                    .then(response => response.json())
                                    .then(data => {
                                        if (data.success) {
                                            // Filtra las activas y toma las 20 más recientes.
                                            // El ordenamiento ya lo hace el backend por 'activated_at DESC'
                                            updateActivationStatusTable(data.activations.filter(a => a.status === 'active').slice(0, 20));
                                        }
                                    })
                                    .catch(error => console.error('Error loading activation status:', error));
                            }

                            // Nueva función para actualizar la tabla de estado de activaciones
                            function updateActivationStatusTable(activations) {
                                const tbody = document.querySelector('#activationStatusTable tbody');
                                if (activations.length === 0) {
                                    tbody.innerHTML = `
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">
                                                <i class="fas fa-plug fa-2x mb-2"></i>
                                                <p class="mb-0">No hay activaciones activas recientes</p>
                                            </td>
                                        </tr>
                                    `;
                                    return;
                                }

                                tbody.innerHTML = activations.map(activation => {
                                    const lastCheckTimeAgo = getTimeAgo(activation.last_check);
                                    const statusClass = activation.status === 'active' ? 'success' : 'danger'; // Asumiendo que solo mostramos activas o bloqueadas si es el caso
                                    return `
                                        <tr>
                                            <td><small><i class="fas fa-globe me-1"></i>${activation.domain}</small></td>
                                            <td><strong>${activation.client_name}</strong></td>
                                            <td><small>${lastCheckTimeAgo}</small></td>
                                            <td><span class="badge bg-${statusClass}">${activation.status}</span></td>
                                        </tr>
                                    `;
                                }).join('');
                            }
                            
                            // Función auxiliar para obtener íconos por acción
                            function getActionIcon(action) {
                                const icons = {
                                    'verification': 'fas fa-shield-check',
                                    'activation': 'fas fa-plug',
                                    'deactivation': 'fas fa-unlink',
                                    'validation': 'fas fa-check-circle',
                                    'error': 'fas fa-exclamation-triangle'
                                };
                                return icons[action] || 'fas fa-info';
                            }
                            
                            // Función auxiliar para calcular tiempo relativo
                            function getTimeAgo(dateString) {
                                const now = new Date();
                                const date = new Date(dateString);
                                const diffInSeconds = Math.floor((now - date) / 1000);
                                
                                if (diffInSeconds < 60) {
                                    return 'Ahora mismo';
                                } else if (diffInSeconds < 3600) {
                                    const minutes = Math.floor(diffInSeconds / 60);
                                    return `Hace ${minutes} minuto${minutes > 1 ? 's' : ''}`;
                                } else if (diffInSeconds < 86400) {
                                    const hours = Math.floor(diffInSeconds / 3600);
                                    return `Hace ${hours} hora${hours > 1 ? 's' : ''}`;
                                } else {
                                    const days = Math.floor(diffInSeconds / 86400);
                                    return `Hace ${days} día${days > 1 ? 's' : ''}`;
                                }
                            }
                            
                            // Refrescar todos los datos del dashboard de verificaciones
                            function refreshVerifications() {
                                const btn = document.getElementById('refreshBtn');
                                const originalContent = btn.innerHTML;
                                
                                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Actualizando...';
                                btn.disabled = true;
                                
                                Promise.all([
                                    loadVerificationStats(),
                                    loadRecentVerifications(),
                                    loadLiveActivity(),
                                    loadActivationStatus()
                                ]).finally(() => {
                                    btn.innerHTML = originalContent;
                                    btn.disabled = false;
                                });
                            }
                            
                            // Auto-refresh
                            function toggleAutoRefresh() {
                                const btn = document.getElementById('autoRefreshBtn');
                                const indicator = document.getElementById('liveIndicator');
                                
                                if (isAutoRefreshActive) {
                                    clearInterval(autoRefreshInterval);
                                    isAutoRefreshActive = false;
                                    btn.innerHTML = '<i class="fas fa-play me-2"></i>Auto-actualizar';
                                    btn.className = 'btn btn-outline-info';
                                    indicator.className = 'badge bg-secondary ms-2';
                                    indicator.textContent = 'PAUSADO';
                                } else {
                                    // Ejecutar una vez al activar y luego en el intervalo
                                    refreshVerifications(); 
                                    autoRefreshInterval = setInterval(() => {
                                        refreshVerifications();
                                    }, 30000); // Cada 30 segundos
                                    
                                    isAutoRefreshActive = true;
                                    btn.innerHTML = '<i class="fas fa-pause me-2"></i>Pausar';
                                    btn.className = 'btn btn-outline-warning';
                                    indicator.className = 'badge bg-success ms-2';
                                    indicator.textContent = 'EN VIVO';
                                }
                            }
                            
                            // Mostrar detalles de verificación (placeholder)
                            function showVerificationDetails(licenseKey, domain) {
                                alert(`Detalles de verificación:\nLicencia: ${licenseKey}\nDominio: ${domain}`);
                            }
                            
                            // Filtros para la tabla de historial
                            document.getElementById('successOnly').addEventListener('change', function() {
                                if (this.checked) {
                                    document.getElementById('failuresOnly').checked = false;
                                }
                                loadRecentVerifications();
                            });
                            
                            document.getElementById('failuresOnly').addEventListener('change', function() {
                                if (this.checked) {
                                    document.getElementById('successOnly').checked = false;
                                }
                                loadRecentVerifications();
                            });
                        </script>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="createLicenseModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Crear Nueva Licencia
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3 text-primary">
                                    <i class="fas fa-user me-2"></i>Información del Cliente
                                </h6>
                                
                                <div class="mb-3">
                                    <label for="client_name" class="form-label">Nombre del Cliente</label>
                                    <input type="text" class="form-control" name="client_name" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="client_email" class="form-label">Email del Cliente</label>
                                    <input type="email" class="form-control" name="client_email" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="client_phone" class="form-label">Teléfono del Cliente</label>
                                    <input type="tel" class="form-control" name="client_phone" placeholder="+57 300 123 4567">
                                    <div class="form-text">Incluye código de país para mejor contacto</div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h6 class="mb-3 text-info">
                                    <i class="fas fa-cog me-2"></i>Configuración de Licencia
                                </h6>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="product_name" class="form-label">Producto</label>
                                            <input type="text" class="form-control" name="product_name" value="Sistema de Códigos">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="version" class="form-label">Versión</label>
                                            <input type="text" class="form-control" name="version" value="1.0">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="license_type" class="form-label">Tipo de Licencia</label>
                                            <select class="form-select" name="license_type">
                                                <option value="single">Single Domain</option>
                                                <option value="multiple">Multiple Domains</option>
                                                <option value="unlimited">Unlimited Domains</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="max_domains" class="form-label">Máximo Dominios</label>
                                            <input type="number" class="form-control" name="max_domains" value="1" min="1">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-12">
                                <h6 class="mb-3 text-warning">
                                    <i class="fas fa-calendar-alt me-2"></i>Configuración de Período
                                </h6>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="start_date" class="form-label">Fecha de Inicio</label>
                                    <input type="datetime-local" class="form-control" name="start_date" 
                                           value="<?= date('Y-m-d\TH:i') ?>">
                                    <div class="form-text">Cuándo comienza la validez de la licencia</div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="duration_days" class="form-label">Duración (días)</label>
                                    <select class="form-select" name="duration_days" id="duration_days">
                                        <option value="">Permanente</option>
                                        <option value="7">7 días (Prueba)</option>
                                        <option value="30">30 días (1 mes)</option>
                                        <option value="90">90 días (3 meses)</option>
                                        <option value="180">180 días (6 meses)</option>
                                        <option value="365">365 días (1 año)</option>
                                        <option value="730">730 días (2 años)</option>
                                        <option value="custom">Personalizado...</option>
                                    </select>
                                    <div class="form-text">Vacío = licencia permanente</div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="custom_duration" class="form-label">Días personalizados</label>
                                    <input type="number" class="form-control" name="custom_duration" 
                                           id="custom_duration" min="1" max="3650" style="display: none;">
                                    <div class="form-text" id="expiry_preview"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notas</label>
                            <textarea class="form-control" name="notes" rows="3" 
                                      placeholder="Notas adicionales sobre esta licencia..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="create_license" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Crear Licencia
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="editLicenseModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Editar Licencia
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editLicenseForm">
                    <input type="hidden" name="edit_license_id" id="edit_license_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3 text-primary">
                                    <i class="fas fa-user me-2"></i>Información del Cliente
                                </h6>
                                
                                <div class="mb-3">
                                    <label for="edit_client_name" class="form-label">Nombre del Cliente</label>
                                    <input type="text" class="form-control" name="client_name" id="edit_client_name" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="edit_client_email" class="form-label">Email del Cliente</label>
                                    <input type="email" class="form-control" name="client_email" id="edit_client_email" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="edit_client_phone" class="form-label">Teléfono del Cliente</label>
                                    <input type="tel" class="form-control" name="client_phone" id="edit_client_phone">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h6 class="mb-3 text-info">
                                    <i class="fas fa-cog me-2"></i>Configuración de Licencia
                                </h6>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="edit_product_name" class="form-label">Producto</label>
                                            <input type="text" class="form-control" name="product_name" id="edit_product_name">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="edit_version" class="form-label">Versión</label>
                                            <input type="text" class="form-control" name="version" id="edit_version">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="edit_license_type" class="form-label">Tipo de Licencia</label>
                                            <select class="form-select" name="license_type" id="edit_license_type">
                                                <option value="single">Single Domain</option>
                                                <option value="multiple">Multiple Domains</option>
                                                <option value="unlimited">Unlimited Domains</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="edit_max_domains" class="form-label">Máximo Dominios</label>
                                            <input type="number" class="form-control" name="max_domains" id="edit_max_domains" min="1">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="edit_status" class="form-label">Estado</label>
                                    <select class="form-select" name="status" id="edit_status">
                                        <option value="active">Activa</option>
                                        <option value="suspended">Suspendida</option>
                                        <option value="expired">Expirada</option>
                                        <option value="revoked">Revocada</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-12">
                                <h6 class="mb-3 text-warning">
                                    <i class="fas fa-calendar-alt me-2"></i>Configuración de Período
                                </h6>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="edit_start_date" class="form-label">Fecha de Inicio</label>
                                    <input type="datetime-local" class="form-control" name="start_date" id="edit_start_date">
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="edit_duration_days" class="form-label">Duración (días)</label>
                                    <select class="form-select" name="duration_days" id="edit_duration_days">
                                        <option value="">Permanente</option>
                                        <option value="7">7 días (Prueba)</option>
                                        <option value="30">30 días (1 mes)</option>
                                        <option value="90">90 días (3 meses)</option>
                                        <option value="180">180 días (6 meses)</option>
                                        <option value="365">365 días (1 año)</option>
                                        <option value="730">730 días (2 años)</option>
                                        <option value="custom">Personalizado...</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="edit_custom_duration" class="form-label">Días personalizados</label>
                                    <input type="number" class="form-control" name="custom_duration" 
                                           id="edit_custom_duration" min="1" max="3650" style="display: none;">
                                    <div class="form-text" id="edit_expiry_preview"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_notes" class="form-label">Notas</label>
                            <textarea class="form-control" name="notes" id="edit_notes" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="update_license" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Actualizar Licencia
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editPeriodModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-alt me-2"></i>Editar Período de Licencia
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editPeriodForm">
                    <input type="hidden" name="license_id" id="period_license_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="period_start_date" class="form-label">Fecha de Inicio</label>
                            <input type="datetime-local" class="form-control" name="start_date" id="period_start_date" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="period_duration_days" class="form-label">Duración (días)</label>
                            <select class="form-select" name="duration_days" id="period_duration_days">
                                <option value="">Permanente</option>
                                <option value="7">7 días (Prueba)</option>
                                <option value="30">30 días (1 mes)</option>
                                <option value="90">90 días (3 meses)</option>
                                <option value="180">180 días (6 meses)</option>
                                <option value="365">365 días (1 año)</option>
                                <option value="730">730 días (2 años)</option>
                                <option value="custom">Personalizado...</option>
                            </select>
                            <div class="form-text" id="period_expiry_preview"></div> </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="update_period" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Actualizar Período
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Manejar duración personalizada en el modal de creación
        document.getElementById('duration_days').addEventListener('change', function() {
            const customField = document.getElementById('custom_duration');
            if (this.value === 'custom') {
                customField.style.display = 'block';
                customField.required = true;
            } else {
                customField.style.display = 'none';
                customField.required = false;
            }
            updateExpiryPreview('create');
        });
        
        // Manejar duración personalizada en el modal de edición de licencia
        document.getElementById('edit_duration_days').addEventListener('change', function() {
            const customField = document.getElementById('edit_custom_duration');
            if (this.value === 'custom') {
                customField.style.display = 'block';
                customField.required = true;
            } else {
                customField.style.display = 'none';
                customField.required = false;
            }
            updateExpiryPreview('edit');
        });

        // Manejar duración personalizada en el modal de edición de período
        document.getElementById('period_duration_days').addEventListener('change', function() {
            updateExpiryPreview('period');
        });

        // Función unificada para actualizar la vista previa de expiración
        function updateExpiryPreview(context) {
            let startDateField, durationSelect, customDurationField, previewElement;

            if (context === 'create') {
                startDateField = document.querySelector('#createLicenseModal [name="start_date"]');
                durationSelect = document.getElementById('duration_days');
                customDurationField = document.getElementById('custom_duration');
                previewElement = document.getElementById('expiry_preview');
            } else if (context === 'edit') {
                startDateField = document.getElementById('edit_start_date');
                durationSelect = document.getElementById('edit_duration_days');
                customDurationField = document.getElementById('edit_custom_duration');
                previewElement = document.getElementById('edit_expiry_preview');
            } else if (context === 'period') {
                startDateField = document.getElementById('period_start_date');
                durationSelect = document.getElementById('period_duration_days');
                customDurationField = null; // No hay campo custom_duration en este modal, se usa solo el select
                previewElement = document.getElementById('period_expiry_preview');
            } else {
                return; // Contexto no válido
            }

            const startDate = startDateField.value;
            if (!startDate) {
                previewElement.innerHTML = '';
                return;
            }
            
            let duration = durationSelect.value === 'custom' ? (customDurationField ? customDurationField.value : '') : durationSelect.value;
            
            if (duration && duration > 0) {
                const start = new Date(startDate);
                const expiry = new Date(start.getTime() + (duration * 24 * 60 * 60 * 1000));
                previewElement.innerHTML = `<i class="fas fa-clock me-1"></i>Expira: ${expiry.toLocaleDateString('es-ES')}`;
                previewElement.className = 'form-text text-info';
            } else {
                previewElement.innerHTML = '<i class="fas fa-infinity me-1"></i>Licencia permanente';
                previewElement.className = 'form-text text-success';
            }
        }
        
        // Event listeners para actualizar preview en creación
        document.addEventListener('DOMContentLoaded', function() {
            const createStartDateField = document.querySelector('#createLicenseModal [name="start_date"]');
            const createDurationField = document.getElementById('duration_days');
            const createCustomField = document.getElementById('custom_duration');
            
            if (createStartDateField) createStartDateField.addEventListener('change', () => updateExpiryPreview('create'));
            if (createDurationField) createDurationField.addEventListener('change', () => updateExpiryPreview('create'));
            if (createCustomField) createCustomField.addEventListener('input', () => updateExpiryPreview('create'));
            
            updateExpiryPreview('create');
        });
        
        // Event listeners para actualizar preview en edición (modal completo)
        const editStartDateField = document.getElementById('edit_start_date');
        const editDurationField = document.getElementById('edit_duration_days');
        const editCustomField = document.getElementById('edit_custom_duration');
        
        if (editStartDateField) editStartDateField.addEventListener('change', () => updateExpiryPreview('edit'));
        if (editDurationField) editDurationField.addEventListener('change', () => updateExpiryPreview('edit'));
        if (editCustomField) editCustomField.addEventListener('input', () => updateExpiryPreview('edit'));
        
        // Event listeners para actualizar preview en edición de período
        const periodStartDateField = document.getElementById('period_start_date');
        const periodDurationField = document.getElementById('period_duration_days');
        
        if (periodStartDateField) periodStartDateField.addEventListener('change', () => updateExpiryPreview('period'));
        if (periodDurationField) periodDurationField.addEventListener('change', () => updateExpiryPreview('period'));

        function editLicense(id) {
            // Obtener datos de la licencia desde api_admin.php
            fetch('api_admin.php?action=get_license_details&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const license = data.license;
                        
                        // Llenar el formulario
                        document.getElementById('edit_license_id').value = license.id;
                        document.getElementById('edit_client_name').value = license.client_name || '';
                        document.getElementById('edit_client_email').value = license.client_email || '';
                        document.getElementById('edit_client_phone').value = license.client_phone || '';
                        document.getElementById('edit_product_name').value = license.product_name || '';
                        document.getElementById('edit_version').value = license.version || '';
                        document.getElementById('edit_license_type').value = license.license_type || '';
                        document.getElementById('edit_max_domains').value = license.max_domains || '';
                        document.getElementById('edit_status').value = license.status || '';
                        document.getElementById('edit_notes').value = license.notes || '';
                        
                        // Configurar fecha de inicio
                        if (license.start_date && license.start_date !== '0000-00-00 00:00:00') {
                            const startDate = new Date(license.start_date);
                            const isoString = startDate.toISOString().slice(0, 16);
                            document.getElementById('edit_start_date').value = isoString;
                        } else {
                             document.getElementById('edit_start_date').value = ''; // Limpiar si no hay fecha
                        }
                        
                        // Configurar duración
                        if (license.duration_days !== null && license.duration_days !== '' && license.duration_days !== 0) {
                            const durationSelect = document.getElementById('edit_duration_days');
                            const standardValues = ['7', '30', '90', '180', '365', '730'];
                            
                            if (standardValues.includes(license.duration_days.toString())) {
                                durationSelect.value = license.duration_days;
                                document.getElementById('edit_custom_duration').style.display = 'none';
                                document.getElementById('edit_custom_duration').value = '';
                            } else {
                                durationSelect.value = 'custom';
                                document.getElementById('edit_custom_duration').style.display = 'block';
                                document.getElementById('edit_custom_duration').value = license.duration_days;
                            }
                        } else {
                            document.getElementById('edit_duration_days').value = ''; // Permanente
                            document.getElementById('edit_custom_duration').style.display = 'none';
                            document.getElementById('edit_custom_duration').value = '';
                        }
                        
                        updateExpiryPreview('edit'); // Actualizar la vista previa del modal de edición
                        
                        // Mostrar modal
                        const modal = new bootstrap.Modal(document.getElementById('editLicenseModal'));
                        modal.show();
                    } else {
                        alert('Error al cargar datos de la licencia: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error de conexión: ' + error.message);
                });
        }
        
        // Manejar envío del formulario de edición con AJAX
        document.getElementById('editLicenseForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            // formData.append('action', 'update_license'); // Ya se maneja por el 'name="update_license"' en el botón submit
            
            // Convertir FormData a un objeto JSON si la API espera JSON
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });
            
            // Mostrar loading
            const submitBtn = this.querySelector('button[name="update_license"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Actualizando...';
            submitBtn.disabled = true;
            
            fetch('api_admin.php?action=update_license', { // Apunta al nuevo endpoint de la API Admin
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json' // Indicar que el cuerpo es JSON
                },
                body: JSON.stringify(data) // Enviar los datos como JSON
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Cerrar modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('editLicenseModal'));
                    modal.hide();
                    
                    // Mostrar mensaje de éxito y recargar página
                    alert('Licencia actualizada exitosamente');
                    window.location.reload();
                } else {
                    alert('Error al actualizar licencia: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error de conexión: ' + error.message);
            })
            .finally(() => {
                // Restaurar botón
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
        
        // Función mejorada para eliminar licencia
        function deleteLicense(id, clientName) {
            if (confirm('¿Estás seguro de eliminar la licencia de "' + clientName + '"?\n\nEsta acción no se puede deshacer.')) {
                const formData = new FormData();
                formData.append('action', 'delete_license');
                formData.append('license_id', id);
                
                fetch('license_ajax.php', { // Mantener license_ajax.php para delete
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Licencia eliminada exitosamente');
                        window.location.reload();
                    } else {
                        alert('Error al eliminar licencia: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error de conexión: ' + error.message);
                });
            }
        }
        
        function editPeriod(id, startDate, durationDays) {
            document.getElementById('period_license_id').value = id;
            document.getElementById('period_start_date').value = startDate ? startDate.replace(' ', 'T').substring(0, 16) : '';
            
            // Convertir 0 a cadena vacía para que el select muestre "Permanente"
            document.getElementById('period_duration_days').value = (durationDays === 0 || durationDays === '0') ? '' : durationDays;
            
            // Actualizar la vista previa de expiración del modal de período
            updateExpiryPreview('period');

            const modal = new bootstrap.Modal(document.getElementById('editPeriodModal'));
            modal.show();
        }
        
        // Manejar envío del formulario de edición de período
        document.getElementById('editPeriodForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            // La acción 'update_period' ya está en el 'name' del botón submit, no es necesario agregarla
            
            // Mostrar loading
            const submitBtn = this.querySelector('button[name="update_period"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Actualizando...';
            submitBtn.disabled = true;

            fetch('Psnel_administracion.php', { // Envía al mismo archivo PHP para procesar
                method: 'POST',
                body: formData
            })
            .then(response => response.text()) // Usar text() porque la respuesta puede no ser JSON
            .then(text => {
                // Puedes inspeccionar 'text' si hay problemas
                // console.log(text);
                // Si la actualización es exitosa, el PHP simplemente recargará la página
                window.location.reload(); 
            })
            .catch(error => {
                alert('Error de conexión: ' + error.message);
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });

        function viewActivations(id) {
            window.location.href = '?tab=activations&license=' + id;
        }
        
        function extendLicense(id) {
            const extension = prompt('¿Cuántos días adicionales desea agregar?', '30');
            if (extension && parseInt(extension) > 0) {
                // Implementar extensión de licencia
                alert('Función de extensión - Agregar ' + extension + ' días a licencia ID: ' + id);
            }
        }
        
        function contactClient(phone, clientName) {
            if (phone) {
                window.open('tel:' + phone);
            } else {
                alert('No hay número de teléfono registrado para ' + clientName);
            }
        }
        
        function viewActivationDetails(activationId) {
            // Obtener detalles de activación de la API
            fetch(`api_admin.php?action=get_activation_details&id=${activationId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const activation = data.activation;
                        let details = `ID: ${activation.id}\n`;
                        details += `Dominio: ${activation.domain}\n`;
                        details += `IP: ${activation.ip_address}\n`;
                        details += `Estado: ${activation.status}\n`;
                        details += `Activada: ${new Date(activation.activated_at).toLocaleString('es-ES')}\n`;
                        details += `Última Verificación: ${new Date(activation.last_check).toLocaleString('es-ES')}\n`;
                        details += `Conteo de Verificaciones: ${activation.check_count}\n`;
                        details += `\nLicencia:\n`;
                        details += `  Clave: ${activation.license_key}\n`;
                        details += `  Cliente: ${activation.client_name}\n`;
                        details += `  Producto: ${activation.product_name}\n`;
                        details += `  Versión: ${activation.version}\n`;
                        details += `  Expira: ${activation.expires_at ? new Date(activation.expires_at).toLocaleDateString('es-ES') : 'Permanente'}\n`;
                        
                        // Si hay server_info, intentar parsearlo
                        if (activation.server_info) {
                            try {
                                const serverInfo = JSON.parse(activation.server_info);
                                details += '\nInformación del Servidor:\n';
                                for (const key in serverInfo) {
                                    details += `  ${key}: ${serverInfo[key]}\n`;
                                }
                            } catch (e) {
                                details += `\nInformación del Servidor (raw): ${activation.server_info}\n`;
                            }
                        }

                        // Mostrar historial de verificaciones de la activación
                        if (activation.verification_history && activation.verification_history.length > 0) {
                            details += `\nHistorial de Verificaciones (Últimos ${activation.verification_history.length}):\n`;
                            activation.verification_history.forEach(log => {
                                const logDate = new Date(log.created_at).toLocaleString('es-ES');
                                details += `  - ${logDate} | ${log.status.toUpperCase()} | ${log.message}\n`;
                            });
                        } else {
                            details += `\nNo hay historial de verificaciones para esta activación.\n`;
                        }

                        alert(details);
                    } else {
                        alert('Error al cargar detalles de activación: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error de conexión: ' + error.message);
                });
        }
        
        function blockActivationConfirm(activationId) {
            if (confirm('¿Está seguro de bloquear esta activación? Esto impedirá futuras verificaciones para este dominio.')) {
                const formData = new FormData();
                formData.append('action', 'block_activation');
                formData.append('activation_id', activationId);

                fetch('api_admin.php', { // Envía la solicitud a api_admin.php
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Activación bloqueada exitosamente.');
                        window.location.reload();
                    } else {
                        alert('Error al bloquear activación: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error de conexión: ' + error.message);
                });
            }
        }
        
        function refreshLogs() {
            window.location.reload();
        }
        
        function clearOldLogsConfirm() {
            if (confirm('¿Está seguro de eliminar logs antiguos (más de 90 días)? Esta acción es irreversible.')) {
                fetch('api_admin.php?action=clear_old_logs')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Logs antiguos eliminados exitosamente.');
                        window.location.reload();
                    } else {
                        alert('Error al eliminar logs: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Error de conexión: ' + error.message);
                });
            }
        }
    </script>
</body>
</html>