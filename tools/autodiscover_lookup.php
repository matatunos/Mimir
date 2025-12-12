#!/usr/bin/env php
<?php
// tools/autodiscover_lookup.php
// Minimal Autodiscover + SMTP probe helper
// Usage: php tools/autodiscover_lookup.php user@domain.tld [--username=USER] [--password=PASS] [--verbose] [--timeout=SECONDS]

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "Run from CLI only.\n");
	exit(1);
}

$argv_copy = $argv; array_shift($argv_copy);
$email = $argv_copy[0] ?? null;
$username = null; $password = null; $verbose = false; $timeout = 6;
foreach ($argv_copy as $a) {
	if (strpos($a, '--username=') === 0) $username = substr($a, 11);
	if (strpos($a, '--password=') === 0) $password = substr($a, 11);
	if ($a === '--verbose' || $a === '-v') $verbose = true;
	if (strpos($a, '--timeout=') === 0) $timeout = (int) substr($a, 10);
}

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
	echo "Usage: php tools/autodiscover_lookup.php user@domain.tld [--username=USER] [--password=PASS] [--verbose] [--timeout=SECONDS]\n";
	exit(1);
}

list(, $domain) = explode('@', $email, 2);

function dns_mx($d) { $r = @dns_get_record($d, DNS_MX); return $r ?: []; }
function dns_srv($d) { $r = @dns_get_record('_autodiscover._tcp.' . $d, DNS_SRV); return $r ?: []; }

function http_get($url, $u = null, $p = null, $timeout = 6) {
	if (function_exists('curl_version')) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(4, $timeout));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		if ($u !== null && $p !== null) {
			curl_setopt($ch, CURLOPT_USERPWD, $u . ':' . $p);
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC | CURLAUTH_NTLM);
		}
		$r = curl_exec($ch);
		if ($r === false) { $e = curl_error($ch); curl_close($ch); return ['error' => $e]; }
		$hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$urlf = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		$h = substr($r, 0, $hs);
		$b = substr($r, $hs);
		curl_close($ch);
		return ['code' => $code, 'url' => $urlf, 'headers' => $h, 'body' => $b];
	}
	$opts = ['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => $timeout, 'header' => "User-Agent: autodiscover-lookup/1.0\r\n"]];
	if ($u !== null && $p !== null) $opts['http']['header'] .= 'Authorization: Basic ' . base64_encode($u . ':' . $p) . "\r\n";
	$ctx = stream_context_create($opts);
	$body = @file_get_contents($url, false, $ctx);
	return ['code' => null, 'url' => $url, 'headers' => $http_response_header ?? [], 'body' => $body];
}

function probe_port($host, $port, $timeout = 3) {
	$errno = 0; $errstr = '';
	$fp = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errno, $errstr, $timeout);
	if (!$fp) return false;
	fclose($fp);
	return true;
}

function probe_ssl_port($host, $port, $timeout = 3) {
	$errno = 0; $errstr = '';
	$fp = @stream_socket_client(sprintf('ssl://%s:%d', $host, $port), $errno, $errstr, $timeout);
	if (!$fp) return false;
	fclose($fp);
	return true;
}

function probe_starttls($host, $port = 587, $timeout = 4) {
	$errno = 0; $errstr = '';
	$fp = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errno, $errstr, $timeout);
	if (!$fp) return false;
	stream_set_timeout($fp, $timeout);
	$banner = @fgets($fp, 512);
	@fwrite($fp, "EHLO example.local\r\n");
	$eh = '';
	$start = microtime(true);
	while (!feof($fp)) {
		$l = @fgets($fp, 512);
		if ($l === false) break;
		$eh .= $l;
		if (preg_match('/^[0-9]{3} /', $l)) break;
		if ((microtime(true) - $start) > $timeout) break;
	}
	@fwrite($fp, "QUIT\r\n");
	fclose($fp);
	return stripos($eh, 'STARTTLS') !== false;
}

echo "Autodiscover probe for: $email (domain: $domain)\n\n";

$srv = dns_srv($domain);
if ($srv) {
	echo "SRV records:\n";
	foreach ($srv as $s) {
		$t = $s['target'] ?? ($s['host'] ?? '(unknown)');
		$p = $s['port'] ?? '';
		echo " - $t:$p\n";
	}
	echo "\n";
}

$mx = dns_mx($domain);
if ($mx) {
	echo "MX records:\n";
	foreach ($mx as $m) {
		$t = $m['target'] ?? ($m['exchange'] ?? '(unknown)');
		echo " - $t\n";
	}
	echo "\n";
}

$candidates = [
	"https://autodiscover.$domain/autodiscover/autodiscover.xml",
	"https://$domain/autodiscover/autodiscover.xml",
];

echo "Probing common Autodiscover endpoints (HTTPS):\n";
foreach ($candidates as $u) {
	echo " - $u ... ";
	$res = http_get($u, $username, $password, $timeout);
	if (isset($res['error'])) { echo "error: " . $res['error'] . "\n"; continue; }
	$code = $res['code'] ?? 'N/A';
	echo "HTTP: $code\n";
	if (!empty($res['body'])) {
		// very small heuristic: look for URLs inside body
		if (preg_match_all('#https?://[^"' . "\s<>]+#i", $res['body'], $m)) {
			$urls = array_values(array_unique($m[0]));
			echo "   Found URLs: \n";
			foreach ($urls as $uu) echo "    - $uu\n";
		} else {
			if ($verbose) echo "   Body length: " . strlen($res['body']) . " bytes\n";
		}
	}
}

$smtpCandidates = [];
if ($mx) foreach ($mx as $m) $smtpCandidates[] = $m['target'] ?? ($m['exchange'] ?? null);
$smtpCandidates = array_merge($smtpCandidates, ["smtp.$domain", "mail.$domain", "exchange.$domain", $domain]);
$smtpCandidates = array_values(array_unique(array_filter($smtpCandidates)));

echo "\nSMTP host candidates (probing ports 25, 587, 465):\n";
$results = [];
foreach ($smtpCandidates as $h) {
	$r25 = probe_port($h, 25, (int)$timeout);
	$r587 = probe_port($h, 587, (int)$timeout);
	$r465 = probe_ssl_port($h, 465, (int)$timeout);
	$start = $r587 ? probe_starttls($h, 587, (int)$timeout) : false;
	$results[$h] = ['25' => $r25, '587' => $r587, '465' => $r465, 'starttls' => $start];
	echo sprintf(" - %s : ports:%s%s%s starttls:%s\n",
		$h,
		$r25 ? '25 ' : '',
		$r587 ? '587 ' : '',
		$r465 ? '465 ' : '',
		$start ? 'yes' : 'no'
	);
}

$selected = null;
// prefer 587 + STARTTLS
foreach ($results as $h => $info) { if (!empty($info['587']) && !empty($info['starttls'])) { $selected = ['host' => $h, 'port' => 587, 'encryption' => 'tls']; break; } }
// then 465 SSL
if (!$selected) { foreach ($results as $h => $info) { if (!empty($info['465'])) { $selected = ['host' => $h, 'port' => 465, 'encryption' => 'ssl']; break; } } }
// then 587 if present
if (!$selected) { foreach ($results as $h => $info) { if (!empty($info['587'])) { $selected = ['host' => $h, 'port' => 587, 'encryption' => 'tls']; break; } } }
// then 25
if (!$selected) { foreach ($results as $h => $info) { if (!empty($info['25'])) { $selected = ['host' => $h, 'port' => 25, 'encryption' => '']; break; } } }

if ($selected) {
	$fromName = ucfirst(str_replace(['-', '_', '.'], ' ', $domain));
	echo "\nSuggested SMTP config:\n";
	echo " smtp_host: {$selected['host']}\n";
	echo " smtp_port: {$selected['port']}\n";
	echo " smtp_encryption: " . ($selected['encryption'] ?: 'none') . "\n";
	echo " email_from_address: $email\n";
	echo " email_from_name: $fromName\n\n";

	echo "SQL to apply (escape values as necessary):\n";
	$kv = ['smtp_host' => $selected['host'], 'smtp_port' => (string)$selected['port'], 'smtp_encryption' => $selected['encryption'], 'email_from_address' => $email, 'email_from_name' => $fromName];
	foreach ($kv as $k => $v) {
		$ve = addslashes($v);
		echo "INSERT INTO config (config_key, config_value) VALUES ('$k', '$ve') ON DUPLICATE KEY UPDATE config_value = '$ve';\n";
	}
} else {
	echo "\nNo SMTP candidate selected; ensure you're running this from a network that can reach the mail servers.\n";
}

exit(0);
