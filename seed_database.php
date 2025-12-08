#!/usr/bin/env php
<?php
/**
 * Script para poblar la base de datos con datos de prueba
 * Uso: php seed_database.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

$db = Database::getInstance()->getConnection();

echo "🌱 Iniciando seed de base de datos...\n\n";

// Nombres y apellidos para generar usuarios random
$nombres = ['Juan', 'María', 'Carlos', 'Ana', 'Luis', 'Elena', 'Pedro', 'Laura', 'Diego', 'Carmen', 'Miguel', 'Isabel', 'José', 'Rosa', 'Antonio', 'Lucía', 'Manuel', 'Marta', 'Francisco', 'Sara'];
$apellidos = ['García', 'Rodríguez', 'González', 'Fernández', 'López', 'Martínez', 'Sánchez', 'Pérez', 'Gómez', 'Martín', 'Jiménez', 'Ruiz', 'Hernández', 'Díaz', 'Moreno', 'Muñoz', 'Álvarez', 'Romero', 'Alonso', 'Gutiérrez'];

// Tipos de archivos para simular
$tiposArchivos = [
    ['extension' => 'pdf', 'mime' => 'application/pdf', 'size_min' => 100000, 'size_max' => 5000000],
    ['extension' => 'jpg', 'mime' => 'image/jpeg', 'size_min' => 50000, 'size_max' => 3000000],
    ['extension' => 'png', 'mime' => 'image/png', 'size_min' => 100000, 'size_max' => 5000000],
    ['extension' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size_min' => 50000, 'size_max' => 2000000],
    ['extension' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size_min' => 30000, 'size_max' => 1000000],
    ['extension' => 'mp4', 'mime' => 'video/mp4', 'size_min' => 5000000, 'size_max' => 50000000],
    ['extension' => 'zip', 'mime' => 'application/zip', 'size_min' => 1000000, 'size_max' => 20000000],
    ['extension' => 'txt', 'mime' => 'text/plain', 'size_min' => 1000, 'size_max' => 50000],
];

$nombresArchivos = [
    'Informe', 'Presentación', 'Documento', 'Proyecto', 'Análisis', 'Reporte', 'Manual', 'Guía',
    'Tutorial', 'Memoria', 'Propuesta', 'Contrato', 'Factura', 'Presupuesto', 'Plan',
    'Imagen', 'Foto', 'Captura', 'Video', 'Grabación', 'Audio', 'Backup', 'Copia'
];

$descripciones = [
    'Documento importante para el proyecto',
    'Material de trabajo del equipo',
    'Archivo de referencia',
    'Backup de seguridad',
    'Presentación para clientes',
    'Informe mensual',
    'Documentación técnica',
    'Recursos del proyecto',
    null, null, null // Algunos sin descripción
];

try {
    $db->beginTransaction();
    
    // Contraseña hasheada para "test"
    $passwordHash = password_hash('test', PASSWORD_DEFAULT);
    
    // 1. Crear usuarios (20 usuarios)
    echo "👥 Creando usuarios...\n";
    $userIds = [];
    for ($i = 0; $i < 20; $i++) {
        $nombre = $nombres[array_rand($nombres)];
        $apellido = $apellidos[array_rand($apellidos)];
        $username = strtolower($nombre . $apellido . rand(1, 999));
        $email = $username . '@example.com';
        $fullName = $nombre . ' ' . $apellido;
        
        $stmt = $db->prepare("
            INSERT INTO users (username, password, email, full_name, role, storage_quota, is_active, is_ldap)
            VALUES (?, ?, ?, ?, 'user', ?, 1, 0)
        ");
        
        $quota = rand(1, 10) * 1024 * 1024 * 1024; // Entre 1GB y 10GB
        $stmt->execute([$username, $passwordHash, $email, $fullName, $quota]);
        $userIds[] = $db->lastInsertId();
        
        echo "  ✓ Usuario creado: $username (contraseña: test)\n";
    }
    
    // 2. Crear archivos (100-200 archivos)
    echo "\n📁 Creando archivos...\n";
    $fileIds = [];
    $numArchivos = rand(100, 200);
    
    for ($i = 0; $i < $numArchivos; $i++) {
        $userId = $userIds[array_rand($userIds)];
        $tipo = $tiposArchivos[array_rand($tiposArchivos)];
        $nombreBase = $nombresArchivos[array_rand($nombresArchivos)];
        $originalName = $nombreBase . '_' . rand(1, 9999) . '.' . $tipo['extension'];
        $storedName = bin2hex(random_bytes(16)) . '.' . $tipo['extension'];
        $fileSize = rand($tipo['size_min'], $tipo['size_max']);
        $description = $descripciones[array_rand($descripciones)];
        
        // Fecha aleatoria en los últimos 90 días
        $daysAgo = rand(0, 90);
        $hoursAgo = rand(0, 23);
        $createdAt = date('Y-m-d H:i:s', strtotime("-$daysAgo days -$hoursAgo hours"));
        
        // Simular ruta de archivo
        $filePath = 'uploads/' . date('Y/m/', strtotime($createdAt)) . $storedName;
        
        $stmt = $db->prepare("
            INSERT INTO files (user_id, original_name, stored_name, file_path, file_size, mime_type, description, is_shared, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)
        ");
        
        $stmt->execute([$userId, $originalName, $storedName, $filePath, $fileSize, $tipo['mime'], $description, $createdAt]);
        $fileIds[] = $db->lastInsertId();
    }
    
    echo "  ✓ $numArchivos archivos creados\n";
    
    // 3. Crear comparticiones (30-50% de los archivos)
    echo "\n🔗 Creando comparticiones...\n";
    $numShares = rand(count($fileIds) * 0.3, count($fileIds) * 0.5);
    $sharedFileIds = array_rand(array_flip($fileIds), (int)$numShares);
    if (!is_array($sharedFileIds)) {
        $sharedFileIds = [$sharedFileIds];
    }
    
    foreach ($sharedFileIds as $fileId) {
        // Obtener el user_id del archivo
        $stmt = $db->prepare("SELECT user_id FROM files WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        
        $shareToken = bin2hex(random_bytes(16));
        $isActive = rand(0, 100) > 20 ? 1 : 0; // 80% activos
        $downloadCount = rand(0, 50);
        
        // Fecha de creación después del archivo
        $stmt = $db->prepare("SELECT created_at FROM files WHERE id = ?");
        $stmt->execute([$fileId]);
        $fileCreated = $stmt->fetchColumn();
        $daysAfter = rand(0, 30);
        $shareCreated = date('Y-m-d H:i:s', strtotime($fileCreated . " +$daysAfter days"));
        
        $stmt = $db->prepare("
            INSERT INTO shares (file_id, share_token, created_by, is_active, download_count, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$fileId, $shareToken, $file['user_id'], $isActive, $downloadCount, $shareCreated]);
        
        // Actualizar el archivo como compartido
        $stmt = $db->prepare("UPDATE files SET is_shared = 1 WHERE id = ?");
        $stmt->execute([$fileId]);
    }
    
    echo "  ✓ " . count($sharedFileIds) . " comparticiones creadas\n";
    
    // 4. Crear logs de actividad (200-300 entradas)
    echo "\n📊 Creando logs de actividad...\n";
    $actions = ['file_upload', 'file_download', 'file_delete', 'share_create', 'share_deactivate', 'login', 'logout'];
    $entityTypes = ['file', 'share', 'user'];
    
    $numLogs = rand(200, 300);
    for ($i = 0; $i < $numLogs; $i++) {
        $userId = $userIds[array_rand($userIds)];
        $action = $actions[array_rand($actions)];
        $entityType = $entityTypes[array_rand($entityTypes)];
        $entityId = rand(1, 100);
        
        $description = match($action) {
            'file_upload' => 'Usuario subió archivo',
            'file_download' => 'Usuario descargó archivo',
            'file_delete' => 'Usuario eliminó archivo',
            'share_create' => 'Usuario creó enlace compartido',
            'share_deactivate' => 'Usuario desactivó enlace compartido',
            'login' => 'Usuario inició sesión',
            'logout' => 'Usuario cerró sesión',
            default => 'Acción realizada'
        };
        
        $daysAgo = rand(0, 90);
        $hoursAgo = rand(0, 23);
        $minutesAgo = rand(0, 59);
        $createdAt = date('Y-m-d H:i:s', strtotime("-$daysAgo days -$hoursAgo hours -$minutesAgo minutes"));
        
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, action, entity_type, entity_id, description, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$userId, $action, $entityType, $entityId, $description, $createdAt]);
    }
    
    echo "  ✓ $numLogs logs de actividad creados\n";
    
    $db->commit();
    
    echo "\n✅ Seed completado exitosamente!\n";
    echo "\n📝 Resumen:\n";
    echo "  - Usuarios: " . count($userIds) . " (todos con contraseña: test)\n";
    echo "  - Archivos: $numArchivos\n";
    echo "  - Comparticiones: " . count($sharedFileIds) . "\n";
    echo "  - Logs de actividad: $numLogs\n";
    echo "\n🔐 Puedes iniciar sesión con cualquier usuario usando la contraseña: test\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
