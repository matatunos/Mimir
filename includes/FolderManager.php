<?php
/**
 * Folder Management Class
 */
class FolderManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Create a folder
     */
    public function create($userId, $name, $parentId = null) {
        try {
            // Build path
            $path = '/';
            if ($parentId !== null) {
                $parent = $this->getFolder($parentId, $userId);
                if (!$parent) {
                    throw new Exception("Parent folder not found");
                }
                $path = $parent['path'] . $name . '/';
            } else {
                $path = '/' . $name . '/';
            }

            // Insert folder
            $stmt = $this->db->prepare("INSERT INTO folders (user_id, parent_id, name, path) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $parentId, $name, $path]);

            $folderId = $this->db->lastInsertId();

            // Log action
            AuditLog::log($userId, 'folder_created', 'folder', $folderId, "Created folder: $name");

            return $folderId;
        } catch (PDOException $e) {
            error_log("Folder creation failed: " . $e->getMessage());
            throw new Exception("Failed to create folder");
        }
    }

    /**
     * Get folder by ID
     */
    public function getFolder($folderId, $userId = null) {
        $query = "SELECT * FROM folders WHERE id = ?";
        $params = [$folderId];

        if ($userId !== null) {
            $query .= " AND user_id = ?";
            $params[] = $userId;
        }

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get user folders
     */
    public function getUserFolders($userId, $parentId = null) {
        if ($parentId === null) {
            $stmt = $this->db->prepare("SELECT * FROM folders WHERE user_id = ? AND parent_id IS NULL ORDER BY name");
            $stmt->execute([$userId]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM folders WHERE user_id = ? AND parent_id = ? ORDER BY name");
            $stmt->execute([$userId, $parentId]);
        }
        return $stmt->fetchAll();
    }

    /**
     * Delete folder
     */
    public function delete($folderId, $userId) {
        $folder = $this->getFolder($folderId, $userId);
        
        if (!$folder) {
            throw new Exception("Folder not found");
        }

        // Delete folder (will cascade delete subfolders and files)
        $stmt = $this->db->prepare("DELETE FROM folders WHERE id = ?");
        $stmt->execute([$folderId]);

        // Log action
        AuditLog::log($userId, 'folder_deleted', 'folder', $folderId, "Deleted folder: {$folder['name']}");

        return true;
    }

    /**
     * Rename folder
     */
    public function rename($folderId, $userId, $newName) {
        $folder = $this->getFolder($folderId, $userId);
        
        if (!$folder) {
            throw new Exception("Folder not found");
        }

        // Update name and path
        $oldPath = $folder['path'];
        $newPath = dirname($oldPath) . '/' . $newName . '/';

        $stmt = $this->db->prepare("UPDATE folders SET name = ?, path = ? WHERE id = ?");
        $stmt->execute([$newName, $newPath, $folderId]);

        // Log action
        AuditLog::log($userId, 'folder_renamed', 'folder', $folderId, "Renamed folder from {$folder['name']} to $newName");

        return true;
    }
}
