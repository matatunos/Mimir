<?php
// Merge missing keys from en.php into other lang files, writing them back.
error_reporting(E_ALL);
ini_set('display_errors', 1);

$dir = __DIR__ . '/../lang';
$enFile = $dir . '/en.php';
if (!file_exists($enFile)) {
    echo "en.php not found\n";
    exit(1);
}

// load en
$en = include $enFile;
if (!is_array($en)) {
    echo "en.php did not return array\n";
    exit(1);
}

$files = glob($dir . '/*.php');
foreach ($files as $f) {
    $base = basename($f);
    if ($base === 'en.php') continue;
    // include file and capture returned array or $LANG
    $lang = [];
    $ret = include $f;
    if (is_array($ret)) $lang = $ret;
    else {
        // try to capture $LANG var
        $contents = file_get_contents($f);
        // naive eval in isolated scope
        $LANG = null;
        try {
            // execute in function scope
            $lang = (function() use ($f) {
                $LANG = null;
                include $f;
                if (isset($LANG) && is_array($LANG)) return $LANG;
                return [];
            })();
        } catch (Throwable $e) {
            echo "Error including $f: " . $e->getMessage() . "\n";
            continue;
        }
    }

    $merged = array_merge($en, $lang); // prefer existing lang values over en? we want lang override en so reverse
    // Actually keep existing translations where present: so take en then overwrite with lang
    $merged = $en;
    foreach ($lang as $k => $v) $merged[$k] = $v;

    // write back file with $LANG = [ ... ]; return $LANG;
    $out = "<?php\n";
    $out .= "return [\n";
    foreach ($merged as $k => $v) {
        // export string preserving single quotes and newlines
        $escaped = str_replace("'", "\\'", $v);
        $escaped = str_replace("\n", "\\n", $escaped);
        $out .= "    '" . $k . "' => '" . $escaped . "',\n";
    }
    $out .= "];\n";

    file_put_contents($f, $out);
    echo "Updated $base\n";
}

echo "Done.\n";
