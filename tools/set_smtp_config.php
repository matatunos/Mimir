#!/usr/bin/env php
<?php
// tools/set_smtp_config.php
// CLI helper to set SMTP config using the app's Config class.
// Usage: php tools/set_smtp_config.php --host=... --port=... --encryption=ssl|tls|none --username=... --from=... --fromname="..."

$opts = getopt('', ['host:', 'port:', 'encryption::', 'username::', 'from::', 'fromname::', 'timeout::']);

$host = $opts['host'] ?? null;
$port = $opts['port'] ?? null;
$enc = $opts['encryption'] ?? null;
$username = $opts['username'] ?? null;
$from = $opts['from'] ?? null;
$fromname = $opts['fromname'] ?? null;
$timeout = isset($opts['timeout']) ? (int)$opts['timeout'] : 6;

if (!$host || !$port) {
    echo "Usage: php tools/set_smtp_config.php --host=HOST --port=PORT [--encryption=ssl|tls|none] --username=USER --from=addr --fromname=Name\n";
    exit(1);
}

// Prompt for password securely
function prompt_silent($prompt = 'Password: ') {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows: fallback to normal prompt (no silent)
        echo $prompt;
        $pw = rtrim(fgets(STDIN), "\r\n");
        return $pw;
    }
    echo $prompt;
    system('stty -echo');
    $pw = rtrim(fgets(STDIN), "\n");
    system('stty echo');
    echo "\n";
    return $pw;
}

$pass = prompt_silent('SMTP password (input hidden): ');

// Load app environment
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';

$config = new Config();

// Set keys
$setmap = [
    'smtp_host' => $host,
    'smtp_port' => (string)$port,
    'smtp_encryption' => $enc ?: '',
];
if ($username !== null) $setmap['smtp_username'] = $username;
if ($pass !== null && $pass !== '') $setmap['smtp_password'] = $pass;
if ($from !== null) $setmap['email_from_address'] = $from;
if ($fromname !== null) $setmap['email_from_name'] = $fromname;

foreach ($setmap as $k => $v) {
    $type = 'string';
    if ($k === 'smtp_port') $type = 'number';
    $ok = $config->set($k, $v, $type);
    if ($ok) echo "Set $k -> $v\n";
    else echo "Failed to set $k\n";
}

echo "Done. You can now test from the admin UI (Config page) using TEST correo.\n";
