<?php
/**
 * System Configuration Class
 */
class SystemConfig {
    private static $cache = [];

    /**
     * Get configuration value
     */
    public static function get($key, $default = null) {
        // Check cache first
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT config_value, config_type FROM system_config WHERE config_key = ?");
            $stmt->execute([$key]);
            $config = $stmt->fetch();

            if (!$config) {
                return $default;
            }

            // Convert value based on type
            $value = $config['config_value'];
            switch ($config['config_type']) {
                case 'integer':
                    $value = (int)$value;
                    break;
                case 'boolean':
                    $value = $value === 'true' || $value === '1';
                    break;
                case 'json':
                    $value = json_decode($value, true);
                    break;
            }

            // Cache the value
            self::$cache[$key] = $value;

            return $value;
        } catch (Exception $e) {
            error_log("Failed to get config $key: " . $e->getMessage());
            return $default;
        }
    }

    /**
     * Set configuration value
     */
    public static function set($key, $value, $type = 'string') {
        try {
            $db = Database::getInstance()->getConnection();

            // Convert value based on type
            switch ($type) {
                case 'boolean':
                    $value = $value ? 'true' : 'false';
                    break;
                case 'json':
                    $value = json_encode($value);
                    break;
                default:
                    $value = (string)$value;
            }

            $stmt = $db->prepare("INSERT INTO system_config (config_key, config_value, config_type) 
                                 VALUES (?, ?, ?) 
                                 ON DUPLICATE KEY UPDATE config_value = ?, config_type = ?");
            $stmt->execute([$key, $value, $type, $value, $type]);

            // Clear cache
            unset(self::$cache[$key]);

            return true;
        } catch (Exception $e) {
            error_log("Failed to set config $key: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all configuration
     */
    public static function getAll() {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("SELECT * FROM system_config ORDER BY config_key");
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Failed to get all config: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clear cache
     */
    public static function clearCache() {
        self::$cache = [];
    }
}
