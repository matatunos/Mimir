<?php
// Generate SQL file with config descriptions but do not execute it.
$s = file_get_contents(__DIR__ . '/../public/admin/config.php');
if ($s === false) { echo "Failed to read file\n"; exit(1); }
$cnt = preg_match_all('/\\$descs\s*\[\s*[\'\"]([a-zA-Z0-9_\-]+)[\'\"]\s*\]\s*=\s*([\'\"])(.*?)\\2\s*;/s', $s, $m, PREG_SET_ORDER);
if (!$cnt) { echo "No desc entries found\n"; exit(0); }
$sql = "SET NAMES utf8mb4;\n";
foreach ($m as $row) {
    $k = $row[1];
    $v = preg_replace('/\s+/', ' ', trim($row[3]));
    $v = str_replace("'", "''", $v);
    $sql .= "UPDATE config SET description = '" . $v . "' WHERE config_key = '" . $k . "' AND (description IS NULL OR description = '');\n";
}
$out = __DIR__ . '/../tmp/update_config_desc.sql';
file_put_contents($out, $sql);
echo "Wrote $cnt statements to $out\n";
?>