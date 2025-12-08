<?php
/**
 * Mimir File Management System
 * Configuration Management Class
 */

class Config {
    private $db;
    private $cache = [];
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->loadConfig();
    }
    
    /**
     * Load all configuration into cache
     */
    private function loadConfig() {
        try {
            $stmt = $this->db->query("SELECT config_key, config_value, config_type FROM config");
            while ($row = $stmt->fetch()) {
                $this->cache[$row['config_key']] = $this->castValue($row['config_value'], $row['config_type']);
            }
        } catch (Exception $e) {
            error_log("Config load error: " . $e->getMessage());
        }
    }
    
    /**
     * Get configuration value
     */
    public function get($key, $default = null) {
        return $this->cache[$key] ?? $default;
    }
    
    /**
     * Set configuration value
     */
    public function set($key, $value, $type = 'string') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO config (config_key, config_value, config_type) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    config_value = VALUES(config_value),
                    config_type = VALUES(config_type)
            ");
            
            $stmt->execute([$key, $value, $type]);
            
            // Update cache
            $this->cache[$key] = $this->castValue($value, $type);
            
            return true;
        } catch (Exception $e) {
            error_log("Config set error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all configuration
     */
    public function getAll() {
        return $this->cache;
    }
    
    /**
     * Get configuration by pattern
     */
    public function getByPattern($pattern) {
        $result = [];
        foreach ($this->cache as $key => $value) {
            if (strpos($key, $pattern) === 0) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
    
    /**
     * Get configuration details from database
     */
    public function getDetails($key) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM config WHERE config_key = ?");
            $stmt->execute([$key]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Config get details error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all configuration details
     */
    public function getAllDetails($systemOnly = false) {
        try {
            $where = $systemOnly ? "WHERE is_system = 1" : "";
            $stmt = $this->db->query("SELECT * FROM config $where ORDER BY config_key");
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Config get all details error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update multiple configurations
     */
    public function updateMultiple($configs) {
        try {
            $this->db->beginTransaction();
            
            foreach ($configs as $key => $data) {
                $this->set($key, $data['value'], $data['type'] ?? 'string');
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Config update multiple error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete configuration
     */
    public function delete($key) {
        try {
            // Check if system config
            $details = $this->getDetails($key);
            if ($details && $details['is_system']) {
                return false; // Cannot delete system configs
            }
            
            $stmt = $this->db->prepare("DELETE FROM config WHERE config_key = ? AND is_system = 0");
            $stmt->execute([$key]);
            
            // Remove from cache
            unset($this->cache[$key]);
            
            return true;
        } catch (Exception $e) {
            error_log("Config delete error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Cast value to appropriate type
     */
    private function castValue($value, $type) {
        switch ($type) {
            case 'number':
                return is_numeric($value) ? (int)$value : 0;
            case 'boolean':
                return (bool)$value;
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }
    
    /**
     * Reload configuration from database
     */
    public function reload() {
        $this->cache = [];
        $this->loadConfig();
    }
}
