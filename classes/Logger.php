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

    /**
     * Return distinct actions present in activity_log and security_events
     * Used to populate dynamic filters in the admin UI.
     */
    public function getDistinctActions() {
        try {
            $sql = "(
                SELECT DISTINCT action as a FROM activity_log
            ) UNION (
                SELECT DISTINCT event_type as a FROM security_events
            ) ORDER BY a ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $actions = [];
            foreach ($rows as $r) {
                if (!empty($r['a'])) $actions[] = $r['a'];
            }
            return $actions;
        } catch (Exception $e) {
            error_log("Get distinct actions error: " . $e->getMessage());
            return [];
        }
    }
    public function log($userId, $action, $entityType = null, $entityId = null, $description = null, $metadata = null) {
        try {
            // Normalize empty values: ensure integer columns receive NULL rather than empty string
            // Treat any empty-like scalar (empty string, false, whitespace-only) as NULL
            if (!is_null($entityId) && trim((string)$entityId) === '') {
                $entityId = null;
            }
            if (!is_null($entityType) && trim((string)$entityType) === '') {
                $entityType = null;
            }
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
            // Build filters for both activity_log and security_events, then UNION
            $whereActivity = [];
            $paramsActivity = [];

            $whereSecurity = [];
            $paramsSecurity = [];

            if (!empty($filters['user_id'])) {
                $whereActivity[] = "al.user_id = ?";
                $paramsActivity[] = $filters['user_id'];

                $whereSecurity[] = "se.user_id = ?";
                $paramsSecurity[] = $filters['user_id'];
            }

            if (!empty($filters['action'])) {
                $whereActivity[] = "al.action = ?";
                $paramsActivity[] = $filters['action'];

                $whereSecurity[] = "se.event_type = ?";
                $paramsSecurity[] = $filters['action'];
            }

            if (!empty($filters['entity_type'])) {
                $whereActivity[] = "al.entity_type = ?";
                $paramsActivity[] = $filters['entity_type'];
            }

            if (!empty($filters['date_from'])) {
                $whereActivity[] = "al.created_at >= ?";
                $paramsActivity[] = $filters['date_from'];

                $whereSecurity[] = "se.created_at >= ?";
                $paramsSecurity[] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $whereActivity[] = "al.created_at <= ?";
                $paramsActivity[] = $filters['date_to'];

                $whereSecurity[] = "se.created_at <= ?";
                $paramsSecurity[] = $filters['date_to'];
            }

            if (!empty($filters['search'])) {
                $searchTerm = '%' . $filters['search'] . '%';
                $whereActivity[] = "(al.description LIKE ? OR al.action LIKE ?)";
                $paramsActivity[] = $searchTerm;
                $paramsActivity[] = $searchTerm;

                $whereSecurity[] = "(se.description LIKE ? OR se.event_type LIKE ?)";
                $paramsSecurity[] = $searchTerm;
                $paramsSecurity[] = $searchTerm;
            }

            $whereActivityClause = !empty($whereActivity) ? 'WHERE ' . implode(' AND ', $whereActivity) : '';
            $whereSecurityClause = !empty($whereSecurity) ? 'WHERE ' . implode(' AND ', $whereSecurity) : '';

            $sql = "
                SELECT * FROM (
                    SELECT al.id, al.action, al.entity_type, al.entity_id, al.description, al.ip_address, al.user_agent, al.created_at, u.username, u.full_name
                    FROM activity_log al
                    LEFT JOIN users u ON al.user_id = u.id
                    $whereActivityClause
                    UNION ALL
                    SELECT se.id, se.event_type as action, NULL as entity_type, NULL as entity_id, se.description, se.ip_address, se.user_agent, se.created_at, COALESCE(se.username, u2.username) as username, u2.full_name
                    FROM security_events se
                    LEFT JOIN users u2 ON se.user_id = u2.id
                    $whereSecurityClause
                ) combined
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ";

            $params = array_merge($paramsActivity, $paramsSecurity);
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
            // Count both activity_log and security_events matching filters and sum
            $whereActivity = [];
            $paramsActivity = [];

            $whereSecurity = [];
            $paramsSecurity = [];

            if (!empty($filters['user_id'])) {
                $whereActivity[] = "user_id = ?";
                $paramsActivity[] = $filters['user_id'];

                $whereSecurity[] = "user_id = ?";
                $paramsSecurity[] = $filters['user_id'];
            }

            if (!empty($filters['action'])) {
                $whereActivity[] = "action = ?";
                $paramsActivity[] = $filters['action'];

                $whereSecurity[] = "event_type = ?";
                $paramsSecurity[] = $filters['action'];
            }

            if (!empty($filters['entity_type'])) {
                $whereActivity[] = "entity_type = ?";
                $paramsActivity[] = $filters['entity_type'];
            }

            if (!empty($filters['date_from'])) {
                $whereActivity[] = "created_at >= ?";
                $paramsActivity[] = $filters['date_from'];

                $whereSecurity[] = "created_at >= ?";
                $paramsSecurity[] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $whereActivity[] = "created_at <= ?";
                $paramsActivity[] = $filters['date_to'];

                $whereSecurity[] = "created_at <= ?";
                $paramsSecurity[] = $filters['date_to'];
            }

            if (!empty($filters['search'])) {
                $searchTerm = '%' . $filters['search'] . '%';
                $whereActivity[] = "(description LIKE ? OR action LIKE ?)";
                $paramsActivity[] = $searchTerm;
                $paramsActivity[] = $searchTerm;

                $whereSecurity[] = "(description LIKE ? OR event_type LIKE ?)";
                $paramsSecurity[] = $searchTerm;
                $paramsSecurity[] = $searchTerm;
            }

            $whereActivityClause = !empty($whereActivity) ? 'WHERE ' . implode(' AND ', $whereActivity) : '';
            $whereSecurityClause = !empty($whereSecurity) ? 'WHERE ' . implode(' AND ', $whereSecurity) : '';

            $stmtA = $this->db->prepare("SELECT COUNT(*) as total FROM activity_log $whereActivityClause");
            $stmtA->execute($paramsActivity);
            $resA = $stmtA->fetch();

            $stmtS = $this->db->prepare("SELECT COUNT(*) as total FROM security_events $whereSecurityClause");
            $stmtS->execute($paramsSecurity);
            $resS = $stmtS->fetch();

            return intval($resA['total']) + intval($resS['total']);
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
