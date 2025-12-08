<?php
/**
 * Mimir File Management System
 * Logger Class - Activity and Access Logging
 */

class Logger {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Log an activity
     */
    public function log($userId, $action, $entityType = null, $entityId = null, $description = null, $metadata = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO activity_log 
                (user_id, action, entity_type, entity_id, description, ip_address, user_agent, metadata) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $action,
                $entityType,
                $entityId,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $metadata ? json_encode($metadata) : null
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Logger error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log share access
     */
    public function logShareAccess($shareId, $action = 'view') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO share_access_log 
                (share_id, ip_address, user_agent, action) 
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $shareId,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $action
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Share access log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get activity logs with filters
     */
    public function getActivityLogs($filters = [], $limit = 100, $offset = 0) {
        try {
            $where = [];
            $params = [];
            
            if (!empty($filters['user_id'])) {
                $where[] = "user_id = ?";
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['action'])) {
                $where[] = "action = ?";
                $params[] = $filters['action'];
            }
            
            if (!empty($filters['entity_type'])) {
                $where[] = "entity_type = ?";
                $params[] = $filters['entity_type'];
            }
            
            if (!empty($filters['date_from'])) {
                $where[] = "created_at >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $where[] = "created_at <= ?";
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['search'])) {
                $where[] = "(description LIKE ? OR action LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $sql = "
                SELECT 
                    al.*,
                    u.username,
                    u.full_name
                FROM activity_log al
                LEFT JOIN users u ON al.user_id = u.id
                $whereClause
                ORDER BY al.created_at DESC
                LIMIT ? OFFSET ?
            ";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Get activity logs error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get total count of activity logs
     */
    public function getActivityLogsCount($filters = []) {
        try {
            $where = [];
            $params = [];
            
            if (!empty($filters['user_id'])) {
                $where[] = "user_id = ?";
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['action'])) {
                $where[] = "action = ?";
                $params[] = $filters['action'];
            }
            
            if (!empty($filters['entity_type'])) {
                $where[] = "entity_type = ?";
                $params[] = $filters['entity_type'];
            }
            
            if (!empty($filters['date_from'])) {
                $where[] = "created_at >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $where[] = "created_at <= ?";
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['search'])) {
                $where[] = "(description LIKE ? OR action LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM activity_log $whereClause");
            $stmt->execute($params);
            
            $result = $stmt->fetch();
            return $result['total'];
        } catch (Exception $e) {
            error_log("Get activity logs count error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Delete old logs
     */
    public function cleanOldLogs($days = 90) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM activity_log 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);
            
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log("Clean old logs error: " . $e->getMessage());
            return 0;
        }
    }
}
