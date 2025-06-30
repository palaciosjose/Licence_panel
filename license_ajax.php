<?php
/**
 * Endpoint AJAX para operaciones de licencias
 * Maneja obtención y actualización de datos de licencias
 */

session_start();

// Verificar autenticación
if (!isset($_SESSION['license_admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// Configuración de la base de datos
$license_db_config = [
    'host' => 'localhost',
    'username' => 'warsup_sdcode',
    'password' => 'warsup_sdcode', 
    'database' => 'warsup_sdcode'
];

// Incluir la clase LicenseManager y la configuración de WhatsApp
require_once 'LicenseManager.class.php';
require_once 'whatsapp_config.php'; // Asegúrate de que este archivo exista y esté bien configurado

try {
    // Instancia de LicenseManager para manejar todas las operaciones de licencia
    $licenseManager = new LicenseManager($license_db_config, $whatsapp_config); 
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos: ' . $e->getMessage()]);
    exit;
}

// Manejar requests
header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'get_license':
            if (!isset($_GET['id'])) {
                throw new Exception('ID de licencia requerido');
            }
            
            $license_id = (int)$_GET['id'];
            $license = $licenseManager->getLicenseDetails($license_id); // Usar LicenseManager
            if ($license) {
                echo json_encode(['success' => true, 'license' => $license]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Licencia no encontrada']);
                http_response_code(404);
            }
            break;
            
        case 'update_license':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método no permitido');
            }
            
            // Los datos POST ya contienen todo lo necesario para updateLicense en LicenseManager
            $data = $_POST;
            // Asegurarse de que el 'id' de la licencia esté en los datos para LicenseManager::updateLicense
            $data['id'] = (int)($data['edit_license_id'] ?? 0); 
            
            if ($data['id'] <= 0) {
                throw new Exception('ID de licencia requerido para actualizar');
            }

            $result = $licenseManager->updateLicense($data); // Usar LicenseManager
            echo json_encode($result);
            break;
            
        case 'delete_license':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método no permitido');
            }
            
            $license_id = (int)($_POST['license_id'] ?? 0);
            if ($license_id <= 0) {
                throw new Exception('ID de licencia requerido');
            }
            
            $result = $licenseManager->deleteLicense($license_id); // Usar LicenseManager
            echo json_encode($result);
            break;
            
        case 'get_activations':
            if (!isset($_GET['license_id'])) {
                throw new Exception('ID de licencia requerido');
            }
            
            $license_id = (int)$_GET['license_id'];
            $activations = $licenseManager->getActivations($license_id); // Usar LicenseManager
            echo json_encode(['success' => true, 'activations' => $activations]);
            break;
            
        default:
            throw new Exception('Acción no válida');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>