<?php
// CLI tool to export/import SMTP/email configuration
// Usage:
//  php email_config_transfer.php export /path/to/file.json [--decrypt]
//  php email_config_transfer.php import /path/to/file.json [--encrypt] [--force]

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$allowedKeys = ['smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password','email_from_address','email_from_name','enable_email'];

$argv0 = array_shift($argv);
$cmd = array_shift($argv) ?? '';
$path = array_shift($argv) ?? '';
$flags = $argv;

function usage($prog) {
    echo "Usage:\n";
    echo "  php $prog export /path/to/file.json [--decrypt]\n";
    echo "  php $prog import /path/to/file.json [--encrypt] [--force]\n";
    exit(1);
}

if (!in_array($cmd, ['export','import'])) usage($argv0);
if (empty($path)) usage($argv0);

$pdo = null;
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(2);
}

function decrypt_value($enc) {
    // Expect ENC:<b64_iv>:<b64_cipher>
    $parts = explode(':', $enc, 3);
    if (count($parts) !== 3) return false;
    list($_tag, $b64iv, $b64cipher) = $parts;
    $iv = base64_decode($b64iv);
    $cipher = base64_decode($b64cipher);
    $keyFile = rtrim(dirname(__DIR__), '/') . '/.secrets/smtp_key';
    if (!file_exists($keyFile)) return false;
    $key = trim(@file_get_contents($keyFile));
    if ($key === '') return false;
    $keyRaw = base64_decode($key);
    if ($keyRaw === false) return false;
    $plain = @openssl_decrypt($cipher, 'AES-256-CBC', $keyRaw, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? false : $plain;
}

function encrypt_value($plain) {
    $keyFile = rtrim(dirname(__DIR__), '/') . '/.secrets/smtp_key';
    if (!file_exists($keyFile)) return false;
    $key = trim(@file_get_contents($keyFile));
    if ($key === '') return false;
    $keyRaw = base64_decode($key);
    if ($keyRaw === false) return false;
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $keyRaw, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) return false;
    return 'ENC:' . base64_encode($iv) . ':' . base64_encode($cipher);
}

if ($cmd === 'export') {
    $doDecrypt = in_array('--decrypt', $flags);
    $out = [];
    $stmt = $pdo->prepare('SELECT config_key, config_value FROM config WHERE config_key IN (' . implode(',', array_fill(0, count($allowedKeys), '?')) . ')');
    $stmt->execute($allowedKeys);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $k = $r['config_key'];
        $v = $r['config_value'];
        if ($k === 'smtp_password' && is_string($v) && strpos($v, 'ENC:') === 0) {
            if ($doDecrypt) {
                $dec = decrypt_value($v);
                if ($dec === false) {
                    $out[$k] = null;
                    $out['_meta'][] = "smtp_password:ENCRYPTED_FAILED_TO_DECRYPT";
                } else {
                    $out[$k] = $dec;
                    $out['_meta'][] = "smtp_password:DECRYPTED";
                }
            } else {
                $out[$k] = '[ENCRYPTED]';
            }
        } else {
            $out[$k] = $v;
        }
    }
    // Ensure keys present even if not in DB
    foreach ($allowedKeys as $k) if (!array_key_exists($k, $out)) $out[$k] = null;

    $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($path, $json) === false) {
        fwrite(STDERR, "Failed to write to {$path}\n");
        exit(3);
    }
    chmod($path, 0600);
    echo "Exported SMTP config to {$path}\n";
    exit(0);
}

if ($cmd === 'import') {
    $doEncrypt = in_array('--encrypt', $flags);
    $force = in_array('--force', $flags);
    if (!file_exists($path)) { fwrite(STDERR, "File not found: {$path}\n"); exit(4); }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) { fwrite(STDERR, "Invalid JSON in {$path}\n"); exit(5); }

    // Merge allowed keys
    $toWrite = [];
    foreach ($allowedKeys as $k) {
        if (!array_key_exists($k, $data)) continue;
        $val = $data[$k];
        if ($k === 'smtp_password') {
            if ($val === '[ENCRYPTED]') {
                // Keep as-is by reading original from file if present and reusing
                // but since we don't have original, skip unless force
                if (!$force) {
                    fwrite(STDOUT, "smtp_password is marked [ENCRYPTED]; use --force to preserve literal marker or provide a plaintext password. Skipping.\n");
                    continue;
                }
            }
            if ($doEncrypt && !empty($val)) {
                $enc = encrypt_value($val);
                if ($enc === false) { fwrite(STDERR, "Encryption failed: missing or invalid key at .secrets/smtp_key\n"); exit(6); }
                $val = $enc;
            }
        }
        $toWrite[$k] = $val;
    }

    if (empty($toWrite)) { fwrite(STDOUT, "No configuration keys to import.\n"); exit(0); }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO config (config_key, config_value, config_type, is_system) VALUES (?, ?, 'string', 0) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
        foreach ($toWrite as $k => $v) {
            $stmt->execute([$k, $v]);
            echo "Wrote {$k}\n";
        }
        $pdo->commit();
        echo "Import complete.\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Import failed: " . $e->getMessage() . "\n");
        exit(7);
    }
    exit(0);
}

?>
