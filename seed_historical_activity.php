#!/usr/bin/env php
<?php
/**
 * Script para generar actividad histórica de los últimos 3 años
 * Uso: php seed_historical_activity.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

$db = Database::getInstance()->getConnection();

echo "📅 Generando actividad histórica (últimos 3 años)...\n\n";

// Obtener usuarios existentes
$users = $db->query("SELECT id FROM users WHERE role = 'user'")->fetchAll(PDO::FETCH_COLUMN);

if (empty($users)) {
    echo "❌ No hay usuarios en la base de datos\n";
    exit(1);
}

echo "👥 Encontrados " . count($users) . " usuarios\n";

// Tipos de archivos
$tiposArchivos = [
    ['extension' => 'pdf', 'mime' => 'application/pdf', 'size_min' => 100000, 'size_max' => 5000000],
    ['extension' => 'jpg', 'mime' => 'image/jpeg', 'size_min' => 50000, 'size_max' => 3000000],
    ['extension' => 'png', 'mime' => 'image/png', 'size_min' => 100000, 'size_max' => 5000000],
    ['extension' => 'docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size_min' => 50000, 'size_max' => 2000000],
    ['extension' => 'xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'size_min' => 30000, 'size_max' => 1000000],
    ['extension' => 'mp4', 'mime' => 'video/mp4', 'size_min' => 5000000, 'size_max' => 50000000],
    ['extension' => 'zip', 'mime' => 'application/zip', 'size_min' => 1000000, 'size_max' => 20000000],
    ['extension' => 'txt', 'mime' => 'text/plain', 'size_min' => 1000, 'size_max' => 50000],
    ['extension' => 'mp3', 'mime' => 'audio/mpeg', 'size_min' => 2000000, 'size_max' => 10000000],
    ['extension' => 'pptx', 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'size_min' => 500000, 'size_max' => 15000000],
];

$nombresArchivos = [
    'Informe', 'Presentación', 'Documento', 'Proyecto', 'Análisis', 'Reporte', 'Manual', 'Guía',
    'Tutorial', 'Memoria', 'Propuesta', 'Contrato', 'Factura', 'Presupuesto', 'Plan', 'Estudio',
    'Imagen', 'Foto', 'Captura', 'Video', 'Grabación', 'Audio', 'Backup', 'Copia', 'Plantilla',
    'Formulario', 'Certificado', 'Acta', 'Minuta', 'Resumen', 'Anexo', 'Apéndice'
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
    'Material educativo',
    'Datos históricos',
    'Análisis de resultados',
    null, null, null // Algunos sin descripción
];

try {
    $db->beginTransaction();
    
    $startDate = strtotime('-3 years');
    $endDate = time();
    
    // Actividad media: ~5-10 archivos por usuario por mes durante 3 años
    $totalMonths = 36;
    $filesPerUserPerMonth = rand(5, 10);
    $totalFilesExpected = count($users) * $totalMonths * $filesPerUserPerMonth;
    
    echo "\n📊 Objetivo: ~" . number_format($totalFilesExpected) . " archivos en 3 años\n";
    echo "   (" . $filesPerUserPerMonth . " archivos/usuario/mes × " . count($users) . " usuarios × 36 meses)\n\n";
    
    $filesCreated = 0;
    $sharesCreated = 0;
    $logsCreated = 0;
    
    // Generar por meses para distribución realista
    for ($month = 0; $month < $totalMonths; $month++) {
        $monthStart = strtotime("-" . ($totalMonths - $month) . " months");
        $monthEnd = strtotime("-" . ($totalMonths - $month - 1) . " months");
        
        $monthName = date('Y-m', $monthStart);
        echo "📅 Procesando $monthName... ";
        
        $monthFiles = 0;
        $monthShares = 0;
        $monthLogs = 0;
        
        // Cada usuario sube archivos ese mes (con variación)
        foreach ($users as $userId) {
            // Algunos usuarios más activos que otros
            $userActivity = rand(0, 100);
            if ($userActivity < 20) continue; // 20% usuarios inactivos ese mes
            
            $filesThisMonth = rand(3, 12); // Variación por usuario
            
            for ($i = 0; $i < $filesThisMonth; $i++) {
                // Fecha aleatoria dentro del mes
                $fileTimestamp = rand($monthStart, $monthEnd);
                $createdAt = date('Y-m-d H:i:s', $fileTimestamp);
                
                // Generar archivo
                $tipo = $tiposArchivos[array_rand($tiposArchivos)];
                $nombreBase = $nombresArchivos[array_rand($nombresArchivos)];
                $originalName = $nombreBase . '_' . date('Ymd', $fileTimestamp) . '_' . rand(1, 999) . '.' . $tipo['extension'];
                $storedName = bin2hex(random_bytes(16)) . '.' . $tipo['extension'];
                $fileSize = rand($tipo['size_min'], $tipo['size_max']);
                $description = $descripciones[array_rand($descripciones)];
                $filePath = 'uploads/' . date('Y/m/', $fileTimestamp) . $storedName;
                
                $stmt = $db->prepare("
                    INSERT INTO files (user_id, original_name, stored_name, file_path, file_size, mime_type, description, is_shared, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)
                ");
                $stmt->execute([$userId, $originalName, $storedName, $filePath, $fileSize, $tipo['mime'], $description, $createdAt]);
                $fileId = $db->lastInsertId();
                $filesCreated++;
                $monthFiles++;
                
                // Log de upload
                $stmt = $db->prepare("
                    INSERT INTO activity_log (user_id, action, entity_type, entity_id, description, created_at)
                    VALUES (?, 'file_upload', 'file', ?, 'Usuario subió archivo', ?)
                ");
                $stmt->execute([$userId, $fileId, $createdAt]);
                $logsCreated++;
                $monthLogs++;
                
                // 40% de probabilidad de compartir
                if (rand(0, 100) < 40) {
                    $shareTimestamp = $fileTimestamp + rand(3600, 86400 * 7); // Entre 1 hora y 7 días después
                    if ($shareTimestamp > $endDate) $shareTimestamp = $endDate;
                    $shareCreated = date('Y-m-d H:i:s', $shareTimestamp);
                    
                    $shareToken = bin2hex(random_bytes(16));
                    $isActive = rand(0, 100) < 85 ? 1 : 0; // 85% activos
                    $downloadCount = $isActive ? rand(0, 30) : 0;
                    
                    $stmt = $db->prepare("
                        INSERT INTO shares (file_id, share_token, created_by, is_active, download_count, created_at)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$fileId, $shareToken, $userId, $isActive, $downloadCount, $shareCreated]);
                    $sharesCreated++;
                    $monthShares++;
                    
                    // Actualizar archivo como compartido
                    $stmt = $db->prepare("UPDATE files SET is_shared = 1 WHERE id = ?");
                    $stmt->execute([$fileId]);
                    
                    // Log de share
                    $stmt = $db->prepare("
                        INSERT INTO activity_log (user_id, action, entity_type, entity_id, description, created_at)
                        VALUES (?, 'share_create', 'share', ?, 'Usuario creó enlace compartido', ?)
                    ");
                    $stmt->execute([$userId, $db->lastInsertId(), $shareCreated]);
                    $logsCreated++;
                    $monthLogs++;
                    
                    // Simular descargas
                    if ($downloadCount > 0) {
                        for ($d = 0; $d < $downloadCount; $d++) {
                            $downloadTimestamp = $shareTimestamp + rand(3600, ($endDate - $shareTimestamp));
                            if ($downloadTimestamp > $endDate) break;
                            $downloadDate = date('Y-m-d H:i:s', $downloadTimestamp);
                            
                            $stmt = $db->prepare("
                                INSERT INTO activity_log (user_id, action, entity_type, entity_id, description, created_at)
                                VALUES (?, 'file_download', 'file', ?, 'Archivo descargado vía enlace compartido', ?)
                            ");
                            $stmt->execute([null, $fileId, $downloadDate]);
                            $logsCreated++;
                            $monthLogs++;
                        }
                    }
                }
            }
            
            // Logs adicionales de login (2-4 por mes por usuario activo)
            $logins = rand(2, 4);
            for ($l = 0; $l < $logins; $l++) {
                $loginTimestamp = rand($monthStart, $monthEnd);
                $loginDate = date('Y-m-d H:i:s', $loginTimestamp);
                
                $stmt = $db->prepare("
                    INSERT INTO activity_log (user_id, action, entity_type, entity_id, description, created_at)
                    VALUES (?, 'login', 'user', ?, 'Usuario inició sesión', ?)
                ");
                $stmt->execute([$userId, $userId, $loginDate]);
                $logsCreated++;
                $monthLogs++;
            }
        }
        
        echo "✓ Files: $monthFiles | Shares: $monthShares | Logs: $monthLogs\n";
    }
    
    $db->commit();
    
    echo "\n✅ Actividad histórica generada exitosamente!\n\n";
    echo "📊 Resumen total:\n";
    echo "  - Archivos creados: " . number_format($filesCreated) . "\n";
    echo "  - Comparticiones: " . number_format($sharesCreated) . " (" . round($sharesCreated / $filesCreated * 100, 1) . "%)\n";
    echo "  - Logs de actividad: " . number_format($logsCreated) . "\n";
    echo "  - Período: " . date('Y-m-d', $startDate) . " a " . date('Y-m-d', $endDate) . "\n";
    
    // Calcular tamaño total
    $totalSize = $db->query("SELECT SUM(file_size) FROM files")->fetchColumn();
    echo "  - Almacenamiento total: " . number_format($totalSize / 1024 / 1024 / 1024, 2) . " GB\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
