#!/usr/bin/env php
<?php
// Autodiscover and SMTP discovery helper
// Usage: php tools/autodiscover_lookup.php admin@example.com [--username=user] [--password=pass] [--verbose]

if (php_sapi_name() !== 'cli') {
    echo "This script is intended to be run from the command line.\n";
    exit(1);
}

$args = $argv;
array_shift($args);

$opts = [];
$email = '';
$username = null;
$password = null;
$verbose = false;

foreach ($args as $a) {
    if (strpos($a, '--username=') === 0) {
        $username = substr($a, strlen('--username='));
    } elseif (strpos($a, '--password=') === 0) {
        $password = substr($a, strlen('--password='));
    } elseif ($a === '--verbose' || $a === '-v') {
        $verbose = true;
    } elseif (!$email) {
        $email = $a;
    }
}

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Usage: php tools/autodiscover_lookup.php user@domain.tld [--username=USER] [--password=PASS] [--verbose]\n";
    exit(1);
}

list(, $domain) = explode('@', $email, 2);

function logv($msg) {
    global $verbose;
    if ($verbose) echo $msg . PHP_EOL;
}

function dns_srv_lookup($domain) {
    $srv = '_autodiscover._tcp.' . $domain;
    $records = dns_get_record($srv, DNS_SRV);
    return $records ?: [];
}

function dns_mx_lookup($domain) {
    $records = dns_get_record($domain, DNS_MX);
    if (!$records) return [];
    usort($records, function($a,$b){ return ($a['priority'] ?? 0) - ($b['priority'] ?? 0); });
    return $records;
}

function http_get_follow($url, $username=null, $password=null, $verbose=false) {
    if (!function_exists('curl_version')) {
        // fallback to file_get_contents
        $opts = ['http' => ['method' => 'GET', 'ignore_errors' => true]];
        if ($username && $password) {
            $opts['http']['header'] = 'Authorization: Basic ' . base64_encode($username . ':' . $password) . "\r\n";
        }
        $context = stream_context_create($opts);
        $body = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        return ['url'=>$url,'code'=>null,'headers'=>$headers,'body'=>$body];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    if ($username && $password) {
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        // allow NTLM if available
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC | CURLAUTH_NTLM);
    }
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['url'=>$url,'error'=>$err];
    }
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $headers = substr($resp, 0, $header_size);
    $body = substr($resp, $header_size);
    curl_close($ch);
    return ['url'=>$final_url,'code'=>$status,'headers'=>explode("\r\n", trim($headers)),'body'=>$body];
}

function parse_autodiscover_xml_for_urls($xml) {
    $result = [];
    libxml_use_internal_errors(true);
    $s = simplexml_load_string($xml);
    if ($s === false) return $result;

    $ns = $s->getNamespaces(true);
    // Look for <Protocol> entries with <ASUrl>, <EwsUrl>, <Server>
    foreach ($s->xpath('//Protocol') as $p) {
        $p = (array)$p;
        foreach ($p as $k=>$v) {
            if (is_string($v) && preg_match('#https?://#', $v)) $result[] = $v;
            if (is_array($v)) {
                foreach ($v as $sub) if (is_string($sub) && preg_match('#https?://#',$sub)) $result[] = $sub;
            }
        }
    }
    // Fallback: look for common tags
    if (preg_match_all('#https?://[^"\'\s<]+#i', $xml, $m)) {
        foreach ($m[0] as $u) $result[] = $u;
    }
    return array_values(array_unique($result));
}

function probe_tcp_port($host, $port, $timeout=3) {
    $errno = 0; $errstr = '';
    $ctx = stream_context_create(['ssl'=>['capture_peer_cert'=>false]]);
    $fp = @stream_socket_client(sprintf('%s:%d', $host, $port), $errno, $errstr, $timeout);
    if (!$fp) return ['ok'=>false,'error'=>$errstr];
    fclose($fp);
    return ['ok'=>true];
}

function probe_smtp_starttls($host, $port=587, $timeout=5) {
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$fp) return ['ok'=>false,'error'=>$errstr];
    stream_set_timeout($fp, $timeout);
    $banner = fgets($fp, 512);
    fwrite($fp, "EHLO example.com\r\n");
    $ehlo = '';
    while (!feof($fp)) {
        $line = fgets($fp, 512);
        $ehlo .= $line;
        if (preg_match('/^[0-9]{3} /', $line)) break;
    }
    $supportsStart = stripos($ehlo, 'STARTTLS') !== false;
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return ['ok'=>true,'banner'=>trim($banner),'ehlo'=>$ehlo,'starttls'=>$supportsStart];
}

// Start
echo "Autodiscover & SMTP probe for: " . $email . PHP_EOL;
echo "Domain: " . $domain . PHP_EOL . PHP_EOL;

// SRV
echo "[DNS SRV] _autodiscover._tcp.$domain\n";
$srv = dns_srv_lookup($domain);
if ($srv) {
    foreach ($srv as $r) {
        echo " - target: " . ($r['target'] ?? '') . " port:" . ($r['port'] ?? '') . " priority:" . ($r['pri'] ?? ($r['priority'] ?? '')) . PHP_EOL;
    }
} else {
    echo " - no SRV records found" . PHP_EOL;
}

// MX
echo PHP_EOL . "[DNS MX]" . PHP_EOL;
$mx = dns_mx_lookup($domain);
if ($mx) {
    foreach ($mx as $r) echo " - " . ($r['target'] ?? $r['exchange'] ?? '') . " (priority:" . ($r['pri'] ?? $r['priority'] ?? '') . ")\n";
} else {
    echo " - no MX records found\n";
}

// Try autodiscover HTTP endpoints
echo PHP_EOL . "[Autodiscover HTTP probes]" . PHP_EOL;
$candidates = [
    "https://autodiscover.$domain/autodiscover/autodiscover.xml",
    "https://$domain/autodiscover/autodiscover.xml",
    "https://autodiscover.$domain/.well-known/autodiscover/autodiscover.xml",
    "https://autodiscover.$domain/Autodiscover/Autodiscover.xml",
];

foreach ($candidates as $url) {
    echo " - probing $url ... ";
    $res = http_get_follow($url, $username, $password, $verbose);
    if (isset($res['error'])) {
        echo "error: " . $res['error'] . PHP_EOL;
        continue;
    }
    echo "HTTP:" . ($res['code'] ?? 'n/a') . " -> " . ($res['url'] ?? $url) . PHP_EOL;
    if (!empty($res['body'])) {
        $found = parse_autodiscover_xml_for_urls($res['body']);
        if ($found) {
            echo "   Extracted URLs:\n";
            foreach ($found as $u) echo "    - $u\n";
        } else {
            echo "   Body received but no obvious service URLs found (or auth required)\n";
        }
    }
}

// Candidate SMTP hosts heuristics
echo PHP_EOL . "[SMTP host probes] Trying common hosts and MX targets\n";
$smtpCandidates = [];
foreach ($mx as $r) $smtpCandidates[] = $r['target'] ?? $r['exchange'] ?? '';
array_push($smtpCandidates, "smtp.$domain", "mail.$domain", "exchange.$domain", $domain);
$smtpCandidates = array_values(array_unique(array_filter($smtpCandidates)));

foreach ($smtpCandidates as $host) {
    echo " - testing $host ports 25/587/465 ... ";
    $r25 = probe_tcp_port($host, 25, 3);
    $r587 = probe_tcp_port($host, 587, 3);
    $r465 = probe_tcp_port($host, 465, 3);
    $details = [];
    if ($r25['ok']) $details[] = '25';
    if ($r587['ok']) $details[] = '587';
    if ($r465['ok']) $details[] = '465';
    echo count($details) ? 'open ports: ' . implode(',', $details) . PHP_EOL : 'no reachable smtp ports' . PHP_EOL;

    // If port 587 open, probe STARTTLS
    if ($r587['ok']) {
        $start = probe_smtp_starttls($host, 587, 5);
        echo "    STARTTLS support: " . (($start['starttls']) ? 'yes' : 'no') . PHP_EOL;
    }
}

echo PHP_EOL . "Done. Review the extracted info and try these SMTP settings in Mimir config (smtp_host, smtp_port, smtp_encryption).\n";

exit(0);
