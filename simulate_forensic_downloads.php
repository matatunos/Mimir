#!/usr/bin/env php
<?php
/**
 * Script para simular descargas forenses con datos variados
 * Uso: php simulate_forensic_downloads.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

$db = Database::getInstance()->getConnection();

echo "🔬 Simulando descargas forenses...\n\n";

// Get some files
$files = $db->query("SELECT id, file_size FROM files ORDER BY RAND() LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

if (empty($files)) {
    echo "❌ No hay archivos en la base de datos\n";
    exit(1);
}

// Get some shares
$shares = $db->query("SELECT id, file_id FROM shares WHERE is_active = 1 ORDER BY RAND() LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

// User agents simulados (reales)
$userAgents = [
    // Desktop browsers
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
    
    // Mobile browsers
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.43 Mobile Safari/537.36',
    'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Linux; Android 14; SM-A546B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
    
    // Tablets
    'Mozilla/5.0 (iPad; CPU OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Linux; Android 13; SM-X900) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    
    // Bots
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
    'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
    'curl/7.81.0',
    'python-requests/2.31.0',
    'Wget/1.21.3'
];

// IPs simuladas
$ips = [
    '192.168.1.10',
    '192.168.1.15',
    '192.168.1.20',
    '10.0.0.5',
    '172.16.0.100',
    '203.0.113.45', // Public IP example
    '198.51.100.78',
    '192.0.2.33',
    '2001:db8::1', // IPv6
    '151.101.1.69',
    '8.8.8.8',
    '1.1.1.1'
];

// Referers
$referers = [
    'https://www.google.com/search?q=file+sharing',
    'https://www.bing.com/search?q=download',
    'https://twitter.com/',
    'https://www.linkedin.com/feed/',
    'https://slack.com/channels/',
    'https://mail.google.com/',
    null, // Direct access
    null,
    null
];

// Languages
$languages = [
    'es-ES,es;q=0.9',
    'en-US,en;q=0.9',
    'fr-FR,fr;q=0.9',
    'de-DE,de;q=0.9',
    'pt-BR,pt;q=0.9',
    'it-IT,it;q=0.9',
    'es-MX,es;q=0.9,en;q=0.8'
];

// HTTP status codes (mayoría exitosos)
$statusCodes = array_merge(
    array_fill(0, 85, 200), // 85% exitosos
    array_fill(0, 10, 206), // 10% partial content
    array_fill(0, 3, 404),  // 3% no encontrados
    array_fill(0, 2, 500)   // 2% errores de servidor
);

$downloadsCreated = 0;

try {
    $db->beginTransaction();
    
    // Simular descargas en los últimos 90 días
    for ($i = 0; $i < 500; $i++) {
        $file = $files[array_rand($files)];
        $share = rand(0, 100) < 40 && !empty($shares) ? $shares[array_rand($shares)] : null;
        
        // Fecha aleatoria en los últimos 90 días
        $timestamp = time() - rand(0, 90 * 86400);
        $startedAt = date('Y-m-d H:i:s', $timestamp);
        
        // User agent aleatorio
        $userAgent = $userAgents[array_rand($userAgents)];
        
        // Detectar si es bot
        $isBot = (stripos($userAgent, 'bot') !== false || 
                  stripos($userAgent, 'curl') !== false || 
                  stripos($userAgent, 'wget') !== false ||
                  stripos($userAgent, 'python') !== false);
        
        // Detectar tipo de dispositivo
        $deviceType = 'desktop';
        if ($isBot) {
            $deviceType = 'bot';
        } elseif (stripos($userAgent, 'Mobile') !== false && stripos($userAgent, 'iPad') === false) {
            $deviceType = 'mobile';
        } elseif (stripos($userAgent, 'iPad') !== false || stripos($userAgent, 'Tablet') !== false) {
            $deviceType = 'tablet';
        }
        
        // Detectar navegador
        $browser = 'Unknown';
        $browserVersion = null;
        if (preg_match('/Chrome\/([0-9.]+)/', $userAgent, $matches)) {
            $browser = 'Chrome';
            $browserVersion = $matches[1];
        } elseif (preg_match('/Firefox\/([0-9.]+)/', $userAgent, $matches)) {
            $browser = 'Firefox';
            $browserVersion = $matches[1];
        } elseif (preg_match('/Safari\/([0-9.]+)/', $userAgent, $matches) && stripos($userAgent, 'Chrome') === false) {
            $browser = 'Safari';
            $browserVersion = $matches[1];
        } elseif (preg_match('/Edg\/([0-9.]+)/', $userAgent, $matches)) {
            $browser = 'Edge';
            $browserVersion = $matches[1];
        } elseif ($isBot) {
            if (stripos($userAgent, 'Googlebot') !== false) $browser = 'Googlebot';
            elseif (stripos($userAgent, 'bingbot') !== false) $browser = 'Bingbot';
            elseif (stripos($userAgent, 'YandexBot') !== false) $browser = 'YandexBot';
            elseif (stripos($userAgent, 'curl') !== false) $browser = 'cURL';
            elseif (stripos($userAgent, 'python') !== false) $browser = 'Python';
            elseif (stripos($userAgent, 'Wget') !== false) $browser = 'Wget';
        }
        
        // Detectar OS
        $os = 'Unknown';
        $osVersion = null;
        if (preg_match('/Windows NT ([0-9.]+)/', $userAgent, $matches)) {
            $os = 'Windows';
            $osVersion = $matches[1] === '10.0' ? '10/11' : $matches[1];
        } elseif (preg_match('/Mac OS X ([0-9_]+)/', $userAgent, $matches)) {
            $os = 'macOS';
            $osVersion = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/Android ([0-9.]+)/', $userAgent, $matches)) {
            $os = 'Android';
            $osVersion = $matches[1];
        } elseif (preg_match('/iPhone OS ([0-9_]+)/', $userAgent, $matches)) {
            $os = 'iOS';
            $osVersion = str_replace('_', '.', $matches[1]);
        } elseif (stripos($userAgent, 'Linux') !== false) {
            $os = 'Linux';
        }
        
        // Bot name
        $botName = null;
        if ($isBot) {
            if (stripos($userAgent, 'Googlebot') !== false) $botName = 'Googlebot';
            elseif (stripos($userAgent, 'bingbot') !== false) $botName = 'Bing Bot';
            elseif (stripos($userAgent, 'YandexBot') !== false) $botName = 'Yandex Bot';
            elseif (stripos($userAgent, 'curl') !== false) $botName = 'cURL';
            elseif (stripos($userAgent, 'python') !== false) $botName = 'Python Requests';
            elseif (stripos($userAgent, 'Wget') !== false) $botName = 'Wget';
        }
        
        // Duración de descarga (segundos)
        $duration = null;
        $completedAt = null;
        $statusCode = $statusCodes[array_rand($statusCodes)];
        $bytesTransferred = $file['file_size'];
        
        if ($statusCode === 200) {
            // Descarga completa
            $duration = rand(1, 120); // 1 segundo a 2 minutos
            $completedAt = date('Y-m-d H:i:s', $timestamp + $duration);
        } elseif ($statusCode === 206) {
            // Descarga parcial
            $bytesTransferred = rand($file['file_size'] * 0.5, $file['file_size']);
            $duration = rand(1, 60);
            $completedAt = date('Y-m-d H:i:s', $timestamp + $duration);
        }
        
        $ip = $ips[array_rand($ips)];
        $referer = $referers[array_rand($referers)];
        $language = $languages[array_rand($languages)];
        
        $stmt = $db->prepare("
            INSERT INTO download_log (
                file_id, share_id, user_id, ip_address, user_agent, referer,
                accept_language, request_method, browser, browser_version,
                os, os_version, device_type, is_bot, bot_name,
                file_size, bytes_transferred, download_started_at, 
                download_completed_at, download_duration, http_status_code,
                session_id, metadata
            ) VALUES (
                ?, ?, NULL, ?, ?, ?,
                ?, 'GET', ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?
            )
        ");
        
        $metadata = json_encode([
            'simulated' => true,
            'https' => rand(0, 1) === 1
        ]);
        
        $stmt->execute([
            $file['id'],
            $share ? $share['id'] : null,
            $ip,
            $userAgent,
            $referer,
            $language,
            $browser,
            $browserVersion,
            $os,
            $osVersion,
            $deviceType,
            $isBot ? 1 : 0,
            $botName,
            $file['file_size'],
            $bytesTransferred,
            $startedAt,
            $completedAt,
            $duration,
            $statusCode,
            bin2hex(random_bytes(16)),
            $metadata
        ]);
        
        $downloadsCreated++;
        
        if ($downloadsCreated % 50 === 0) {
            echo "✓ $downloadsCreated descargas simuladas...\n";
        }
    }
    
    $db->commit();
    
    echo "\n✅ Simulación completada!\n";
    echo "  - Total descargas: $downloadsCreated\n";
    echo "  - Período: últimos 90 días\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
