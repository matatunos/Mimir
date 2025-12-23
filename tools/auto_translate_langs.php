<?php
/**
 * Auto-translate language files using the unofficial Google Translate endpoint.
 *
 * Behavior:
 * - Back up each file to `lang/<code>.php.bak` (if not already present)
 * - Preserve placeholders like `%s`, `%d`, `%1$s` and `{name}`
 * - Skip `en.php` (source)
 * - Only translate keys that are missing, empty, or identical to English fallback
 *
 * Usage: php tools/auto_translate_langs.php
 */

set_time_limit(0);

function load_lang_file(string $path): ?array {
    if (!file_exists($path)) return null;
    $data = include $path;
    return is_array($data) ? $data : null;
}

function backup_if_missing(string $path): bool {
    $bak = $path . '.bak';
    if (file_exists($bak)) return true;
    return copy($path, $bak);
}

function protect_placeholders(string $text, array &$map): string {
    $map = [];
    $i = 0;
    // printf-style placeholders
    $text = preg_replace_callback('/%\d*\$?[sd]/', function($m) use (&$map, &$i){
        $tok = '__PH' . $i++ . '__';
        $map[$tok] = $m[0];
        return $tok;
    }, $text);
    // {name} style
    $text = preg_replace_callback('/\{[^}]+\}/', function($m) use (&$map, &$i){
        $tok = '__PH' . $i++ . '__';
        $map[$tok] = $m[0];
        return $tok;
    }, $text);
    return $text;
}

function restore_placeholders(string $text, array $map): string {
    if (empty($map)) return $text;
    return strtr($text, $map);
}

function translate_text(string $text, string $target): ?string {
    $endpoint = 'https://translate.googleapis.com/translate_a/single';
    $params = http_build_query([
        'client' => 'gtx',
        'dt' => 't',
        'sl' => 'en',
        'tl' => $target,
        'q' => $text,
    ]);
    $url = $endpoint . '?' . $params;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mimir-i18n-agent/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($res === false || $code !== 200) return null;
    $json = json_decode($res, true);
    if (!is_array($json) || !isset($json[0][0][0])) return null;
    return $json[0][0][0];
}

$langDir = __DIR__ . '/../lang';
if (!is_dir($langDir)) {
    fwrite(STDERR, "lang directory not found: $langDir\n");
    exit(1);
}

$en = load_lang_file($langDir . '/en.php');
if (!$en) {
    fwrite(STDERR, "Failed to load en.php\n");
    exit(1);
}

$files = glob($langDir . '/*.php');
$updated = [];

foreach ($files as $file) {
    $base = basename($file);
    if ($base === 'en.php') continue;
    $code = substr($base, 0, -4);
    echo "Processing $base (code=$code)\n";

    $orig = load_lang_file($file);
    if (!is_array($orig)) $orig = [];

    $changed = false;
    $out = $orig;

    foreach ($en as $k => $v) {
        if (array_key_exists($k, $orig) && $orig[$k] !== '' && $orig[$k] !== $v) continue;

        $map = [];
        $protected = protect_placeholders($v, $map);
        $translated = translate_text($protected, $code);
        if ($translated === null) {
            echo " - warn: failed to translate key $k\n";
            continue;
        }
        $restored = restore_placeholders($translated, $map);
        $out[$k] = trim($restored);
        $changed = true;
        usleep(250000);
    }

    if ($changed) {
        if (!backup_if_missing($file)) {
            echo " - warning: could not backup $file\n";
        }
        // write using var_export for safety
        $export = var_export($out, true);
        $content = "<?php\nreturn " . $export . ";\n";
        if (file_put_contents($file, $content) === false) {
            echo " - error: failed to write $file\n";
            continue;
        }
        echo " - updated $base\n";
        $updated[] = $base;
        // lint
        $lintOut = [];
        $rc = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $lintOut, $rc);
        if ($rc !== 0) {
            echo "   - php lint failed: " . implode("\n", $lintOut) . "\n";
        }
    } else {
        echo " - no changes for $base\n";
    }
}

echo "\nDone. Updated files:\n";
foreach ($updated as $u) echo " - $u\n";
echo "Backups: files with .bak suffix in lang/\n";

exit(0);

