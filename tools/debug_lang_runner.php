<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$_GET['lang'] = $argv[1] ?? 'fr';
try {
    include __DIR__ . '/../public/lang_ajax.php';
} catch (Throwable $e) {
    echo "Caught throwable: ", $e->getMessage(), "\n";
    echo $e->getTraceAsString();
}
