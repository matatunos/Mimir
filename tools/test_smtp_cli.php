#!/usr/bin/env php
<?php
// tools/test_smtp_cli.php
// CLI helper to test SMTP connectivity with STARTTLS and AUTH attempts.
// Usage: php tools/test_smtp_cli.php --host=... --port=... [--enc=ssl|tls|none] [--user=...] [--pass=...]

$opts = getopt('', ['host:', 'port:', 'enc::', 'user::', 'pass::', 'timeout::']);
$host = $opts['host'] ?? null;
$port = isset($opts['port']) ? (int)$opts['port'] : null;
$enc = $opts['enc'] ?? '';
$user = $opts['user'] ?? null;
$pass = $opts['pass'] ?? null;
$timeout = isset($opts['timeout']) ? (int)$opts['timeout'] : 6;

if (!$host || !$port) {
    echo "Usage: php tools/test_smtp_cli.php --host=HOST --port=PORT [--enc=ssl|tls|none] [--user=USER] [--pass=PASS]\n";
    exit(1);
}

$debug = [];
try {
    $debug[] = "Connecting to $host:$port (enc=$enc timeout=$timeout)";
    $connected = false;
    $fp = null; $eh = '';
    if ($enc === 'ssl' || $port === 465) {
        $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $timeout);
        if ($fp) { $connected = true; stream_set_timeout($fp, $timeout); $banner = rtrim(fgets($fp,512)); $debug[] = "Banner: $banner"; }
        else { $debug[] = "SSL connect error: $errstr ($errno)"; }
    } else {
        $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout);
        if ($fp) { $connected = true; stream_set_timeout($fp, $timeout); $banner = rtrim(fgets($fp,512)); $debug[] = "Banner: $banner"; fwrite($fp, "EHLO cli.test\r\n"); $start = microtime(true); while(!feof($fp)) { $line = fgets($fp,512); if ($line===false) break; $eh .= $line; if (preg_match('/^[0-9]{3} /',$line)) break; if ((microtime(true)-$start)>$timeout) break; } $debug[] = "EHLO: " . trim($eh); }
        else { $debug[] = "TCP connect error: $errstr ($errno)"; }
    }

    $starttls_ok = false;
    if (!empty($fp) && $connected && $enc === 'tls') {
        if (stripos($eh ?? '', 'STARTTLS') !== false) {
            $debug[] = 'STARTTLS supported; sending STARTTLS';
            fwrite($fp, "STARTTLS\r\n");
            $resp = rtrim(fgets($fp,512));
            $debug[] = "STARTTLS resp: $resp";
            if (strpos($resp, '220') === 0) {
                $okCrypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($okCrypto) {
                    $starttls_ok = true; $debug[] = 'STARTTLS negotiation OK';
                    fwrite($fp, "EHLO cli.test\r\n"); $eh = ''; $start = microtime(true); while(!feof($fp)) { $line = fgets($fp,512); if ($line===false) break; $eh .= $line; if (preg_match('/^[0-9]{3} /',$line)) break; if ((microtime(true)-$start)>$timeout) break; } $debug[] = "EHLO after TLS: " . trim($eh);
                } else { $debug[] = 'stream_socket_enable_crypto failed'; }
            }
        } else { $debug[] = 'STARTTLS not advertised'; }
    }

    $auth_ok = null;
    if (!empty($user) && !empty($pass) && !empty($fp) && $connected) {
        if (stripos($eh ?? '', 'AUTH') !== false || $enc === 'ssl' || $starttls_ok) {
            $plain = base64_encode("\0" . $user . "\0" . $pass);
            fwrite($fp, "AUTH PLAIN $plain\r\n");
            $resp = rtrim(fgets($fp,512)); $debug[] = "AUTH PLAIN resp: $resp";
            if (strpos($resp,'235')===0) { $auth_ok = true; $debug[] = 'AUTH PLAIN OK'; }
            else {
                fwrite($fp, "AUTH LOGIN\r\n"); $step = rtrim(fgets($fp,512)); $debug[] = "AUTH LOGIN start: $step";
                if (strpos($step,'334')===0) { fwrite($fp, base64_encode($user) . "\r\n"); $resp2 = rtrim(fgets($fp,512)); if (strpos($resp2,'334')===0) { fwrite($fp, base64_encode($pass) . "\r\n"); $final = rtrim(fgets($fp,512)); $debug[] = "AUTH LOGIN final: $final"; if (strpos($final,'235')===0) { $auth_ok = true; $debug[] = 'AUTH LOGIN OK'; } else { $auth_ok = false; $debug[] = 'AUTH LOGIN failed'; } } else { $auth_ok = false; $debug[] = 'Unexpected resp after user'; } } else { $auth_ok = false; $debug[] = 'AUTH LOGIN not started'; }
            }
        } else { $debug[] = 'Server does not advertise AUTH; skipping auth test'; $auth_ok = false; }
    }

    if (!empty($fp) && is_resource($fp)) { @fwrite($fp, "QUIT\r\n"); @fclose($fp); }

    $ok = $connected && ($enc !== 'tls' || $starttls_ok || $enc === 'ssl' || $port === 465);
    $msg = $ok ? 'Connection OK' : 'Connection failed';
    if ($auth_ok === true) $msg .= ' and auth OK';
    if ($auth_ok === false) $msg .= ' (auth failed or not tested)';

    echo json_encode(['success' => (bool)$ok, 'message' => $msg, 'debug' => $debug, 'starttls' => $starttls_ok, 'auth_ok' => $auth_ok], JSON_PRETTY_PRINT) . "\n";

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage(), 'debug' => $debug]) . "\n";
}
