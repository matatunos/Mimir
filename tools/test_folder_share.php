<?php
// CLI test: create folder, upload files (CLI mode), create folder share, build ZIP via Share::createZipFromFolder and verify
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../classes/File.php';
require_once __DIR__ . '/../classes/Share.php';

putenv('ALLOW_CLI_UPLOAD=1');

$db = Database::getInstance()->getConnection();

try {
    // pick a user (first user)
    $u = $db->query("SELECT id, username FROM users ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$u) throw new Exception('No users found in DB');
    $userId = (int)$u['id'];
    echo "Using user id: $userId (" . ($u['username'] ?? '') . ")\n";

    $fileClass = new File();
    $shareClass = new Share();

    $folderName = 'test_folder_share_' . time();
    $folderId = $fileClass->createFolder($userId, $folderName);
    if (!$folderId) throw new Exception('Failed to create folder');
    echo "Created folder id: $folderId\n";

    $createdFiles = [];
    for ($i=1;$i<=3;$i++) {
        $tmp = tempnam(sys_get_temp_dir(), 'mimir_test_');
        file_put_contents($tmp, "This is test file #$i\nSample content for testing ZIP creation.\n");
        $fileData = [
            'tmp_name' => $tmp,
            'name' => "test_file_$i.txt",
            'size' => filesize($tmp)
        ];
        $fid = $fileClass->upload($fileData, $userId, 'test upload', $folderId, true);
        if (!$fid) throw new Exception('Upload failed for file ' . $tmp);
        echo "Uploaded file id: $fid\n";
        $createdFiles[] = ['id' => $fid, 'tmp' => $tmp];
    }

    // Create share for the folder
    $shareRes = $shareClass->create($folderId, $userId, ['max_days' => 1, 'max_downloads' => null]);
    if (empty($shareRes['token'])) throw new Exception('Failed to create share');
    $token = $shareRes['token'];
    echo "Created share token: $token\n";

    // Use reflection to call private createZipFromFolder
    $rm = new ReflectionMethod(Share::class, 'createZipFromFolder');
    $rm->setAccessible(true);
    $zipPath = $rm->invoke($shareClass, $folderId, $userId);
    if (!$zipPath || !file_exists($zipPath)) throw new Exception('ZIP not created');
    echo "ZIP created at: $zipPath\n";

    $za = new ZipArchive();
    if ($za->open($zipPath) !== true) throw new Exception('Unable to open ZIP');
    $entries = [];
    for ($i=0;$i<$za->numFiles;$i++) {
        $entries[] = $za->getNameIndex($i);
    }
    $za->close();
    echo "ZIP contains:\n";
    foreach ($entries as $e) echo " - $e\n";

    // basic assertions: expect 3 files present
    $found = 0;
    foreach ($entries as $e) {
        if (strpos($e, 'test_file_') !== false) $found++;
    }
    if ($found < 3) throw new Exception('Expected 3 test files inside ZIP, found: ' . $found);

    echo "Test passed: found $found files inside ZIP\n";

    // cleanup: delete share, delete uploaded files and folder
    // find share id
    $s = $db->prepare('SELECT id FROM shares WHERE share_token = ? LIMIT 1');
    $s->execute([$token]);
    $sid = $s->fetchColumn();
    if ($sid) {
        $shareClass->delete($sid, $userId);
        echo "Deleted share id: $sid\n";
    }

    // delete uploaded files via delete (this will remove DB records and update storage)
    foreach ($createdFiles as $c) {
        $fileClass->delete($c['id'], $userId);
        echo "Deleted file id: " . $c['id'] . "\n";
        if (file_exists($c['tmp'])) @unlink($c['tmp']);
    }
    // delete folder
    $fileClass->deleteFolder($folderId, $userId);
    echo "Deleted folder id: $folderId\n";

    // remove zip
    @unlink($zipPath);

    exit(0);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(2);
}
