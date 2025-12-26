<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$id = intval($argv[1] ?? 0);
if (!$id) { echo "Usage: php delete_test_share.php SHARE_ID\n"; exit(1); }

require_once __DIR__ . '/../classes/Share.php';
$shareClass = new Share();
if ($shareClass->purge($id)) {
	echo "Purged share id: $id\n";
	exit(0);
} else {
	echo "Failed to purge share id: $id\n";
	exit(2);
}
exit(0);

?>