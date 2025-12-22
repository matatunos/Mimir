<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/Config.php';

function decryptConfigValue($encValue) {
    $parts = explode(':', $encValue, 3);
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

$config = new Config();
$host = $config->get('smtp_host', '');
$port = intval($config->get('smtp_port', 587));
$enc = $config->get('smtp_encryption', 'tls');
$user = $config->get('smtp_username', '');
$pass = $config->get('smtp_password', '');

echo "SMTP host: {$host}\n";
echo "port: {$port}, enc: {$enc}\n";
if (!empty($user)) echo "username: {$user}\n";
if (is_string($pass) && strpos($pass, 'ENC:') === 0) {
    echo "password: stored encrypted (ENC)\n";
    $dec = decryptConfigValue($pass);
    if ($dec === false) {
        echo "password decryption: FAILED (check .secrets/smtp_key)\n";
        $pass_plain = '';
    } else {
        echo "password decryption: OK\n";
        $pass_plain = $dec;
    }
} else {
    if ($pass === '') echo "password: (empty)\n";
    else echo "password: (plain text configured)\n";
    $pass_plain = $pass;
}

$timeout = 8;
$fp = null;
$remote = ($enc === 'ssl' || $port === 465) ? 'ssl://' . $host . ':' . $port : 'tcp://' . $host . ':' . $port;
$fp = @stream_socket_client($remote, $errno, $errstr, $timeout);
if (!$fp) {
    echo "connect failed: " . ($errstr ?? "") . "\n";
    exit(1);
}
stream_set_timeout($fp, $timeout);
$banner = rtrim(fgets($fp, 512));
echo "banner: {$banner}\n";
// send EHLO
fwrite($fp, "EHLO probe.local\r\n");
$out = '';
$start = microtime(true);
while (!feof($fp)) {
    $line = fgets($fp, 512);
    if ($line === false) break;
    $out .= $line;
    echo 'S: ' . rtrim($line) . "\n";
    if (preg_match('/^[0-9]{3} /', $line)) break;
    if ((microtime(true) - $start) > 5) break;
}

$has_starttls = stripos($out, 'STARTTLS') !== false;
$has_auth = stripos($out, 'AUTH') !== false;
echo "STARTTLS: " . ($has_starttls ? 'yes' : 'no') . "\n";
echo "AUTH advertised: " . ($has_auth ? 'yes' : 'no') . "\n";

if ($has_starttls && $enc === 'tls') {
    echo "Attempting STARTTLS...\n";
    fwrite($fp, "STARTTLS\r\n");
    $line = rtrim(fgets($fp,512));
    echo "S: {$line}\n";
    if (strpos($line, '220') === 0) {
        $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        echo "enable_crypto: " . ($ok ? 'ok' : 'failed') . "\n";
        if ($ok) {
            // re-EHLO
            fwrite($fp, "EHLO probe.local\r\n");
            $start = microtime(true); $out2='';
            while (!feof($fp)) {
                $line = fgets($fp, 512);
                if ($line === false) break;
                $out2 .= $line;
                echo 'S: ' . rtrim($line) . "\n";
                if (preg_match('/^[0-9]{3} /', $line)) break;
                if ((microtime(true) - $start) > 5) break;
            }
            $has_auth = stripos($out2, 'AUTH') !== false;
            echo "AUTH after STARTTLS: " . ($has_auth ? 'yes' : 'no') . "\n";
        }
    }
}

// polite QUIT
fwrite($fp, "QUIT\r\n");
@fclose($fp);

exit(0);
