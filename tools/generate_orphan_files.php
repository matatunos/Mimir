<?php
// generate_orphan_files.php
// Creates N orphan files under storage/uploads with random names, sizes and mtimes

$targetDir = __DIR__ . '/../storage/uploads';
if (!is_dir($targetDir)) {
    if (!mkdir($targetDir, 0755, true)) {
        fwrite(STDERR, "Failed to create target directory: $targetDir\n");
        exit(1);
    }
}

$argc = $_SERVER['argc'];
$argv = $_SERVER['argv'];
$count = isset($argv[1]) ? (int)$argv[1] : 500;
if ($count < 1) $count = 500;

$extensions = ['pdf','docx','txt','jpg','png','zip','rar','7z','xlsx','pptx'];
$now = time();
$tenYears = 10 * 365 * 24 * 60 * 60;

$created = 0;
for ($i=0; $i<$count; $i++) {
    // random filename
    $name = 'orphan_' . bin2hex(random_bytes(8)) . '.' . $extensions[array_rand($extensions)];
    $path = $targetDir . '/' . $name;
    // random size between 1KB and 200KB
    $size = random_int(1024, 200 * 1024);
    // write random bytes
    $fh = fopen($path, 'w');
    if (!$fh) continue;
    $remaining = $size;
    while ($remaining > 0) {
        $chunk = random_bytes(min(8192, $remaining));
        fwrite($fh, $chunk);
        $remaining -= strlen($chunk);
    }
    fclose($fh);
    // random mtime within last 10 years
    $randTime = $now - random_int(0, $tenYears);
    @touch($path, $randTime);
    $created++;
}

echo "Created $created orphan files in $targetDir\n";
exit(0);
