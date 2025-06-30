<?php
/**
 * Script de Migración Segura
 * Reemplaza archivos originales con versiones mejoradas
 */

echo "🔄 INICIANDO MIGRACIÓN SEGURA...\n\n";

$migrations = [
    "LicenseManager.class.php" => "LicenseManager.improved.php",
    "api_admin.php" => "api_admin_improved.php"
];

foreach ($migrations as $original => $improved) {
    if (file_exists($improved)) {
        echo "📁 Migrando $original...\n";
        
        // Crear backup del original
        $backup_name = $original . ".backup." . date("Y-m-d_H-i-s");
        if (copy($original, $backup_name)) {
            echo "  ✅ Backup creado: $backup_name\n";
            
            // Reemplazar con la versión mejorada
            if (copy($improved, $original)) {
                echo "  ✅ Archivo actualizado: $original\n";
                
                // Opcional: eliminar archivo temporal mejorado
                unlink($improved);
                echo "  🗑️ Archivo temporal eliminado: $improved\n";
            } else {
                echo "  ❌ Error actualizando $original\n";
            }
        } else {
            echo "  ❌ Error creando backup de $original\n";
        }
    } else {
        echo "⚠️ Archivo mejorado no encontrado: $improved\n";
    }
    echo "\n";
}

echo "🎉 MIGRACIÓN COMPLETADA\n";
echo "📋 ARCHIVOS DE BACKUP CREADOS - Puedes restaurarlos si hay problemas\n";
