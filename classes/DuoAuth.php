<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Duo\DuoUniversal\Client;
use Duo\DuoUniversal\DuoException;

class DuoAuth {
    private $db;
    private $client;
    private $config;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->config = $this->loadConfig();
        
        if ($this->isConfigured()) {
            try {
                $this->client = new Client(
                    $this->config['client_id'],
                    $this->config['client_secret'],
                    $this->config['api_hostname'],
                    $this->config['redirect_uri']
                );
            } catch (DuoException $e) {
                error_log("Duo client initialization error: " . $e->getMessage());
                $this->client = null;
            }
        }
    }
    
    /**
     * Load Duo configuration from database
     * @return array
     */
    private function loadConfig() {
        $stmt = $this->db->query("
            SELECT config_key, config_value 
            FROM config 
            WHERE config_key IN ('duo_client_id', 'duo_client_secret', 'duo_api_hostname', 'duo_redirect_uri')
        ");
        
        $config = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = str_replace('duo_', '', $row['config_key']);
            $config[$key] = $row['config_value'];
        }
        
        // Set default redirect URI if not configured
        if (!isset($config['redirect_uri'])) {
            $config['redirect_uri'] = BASE_URL . '/public/login_2fa_duo_callback.php';
        }
        
        return $config;
    }
    
    /**
     * Check if Duo is properly configured
     * @return bool
     */
    public function isConfigured() {
        return !empty($this->config['client_id']) 
            && !empty($this->config['client_secret']) 
            && !empty($this->config['api_hostname']);
    }
    
    /**
     * Get Duo client instance
     * @return Client|null
     */
    public function getClient() {
        return $this->client;
    }
    
    /**
     * Generate Duo authentication URL
     * @param string $username
     * @return string|null Authentication URL or null on error
     */
    public function generateAuthUrl($username) {
        if (!$this->client) {
            return null;
        }
        
        try {
            // Generate a unique state token
            $state = bin2hex(random_bytes(32));
            
            // Store state in session for verification
            $_SESSION['duo_state'] = $state;
            $_SESSION['duo_username'] = $username;
            
            // Generate and return Duo auth URL
            return $this->client->createAuthUrl($username, $state);
        } catch (DuoException $e) {
            error_log("Duo auth URL generation error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Verify Duo callback
     * @param string $duoCode The authorization code from Duo
     * @param string $state The state token for CSRF protection
     * @return bool True if verification successful
     */
    public function verifyCallback($duoCode, $state) {
        if (!$this->client) {
            return false;
        }
        
        // Verify state matches
        if (!isset($_SESSION['duo_state']) || $_SESSION['duo_state'] !== $state) {
            error_log("Duo state mismatch");
            return false;
        }
        
        try {
            // Exchange the authorization code for verification
            $username = $this->client->exchangeAuthorizationCodeFor2FAResult($duoCode, $_SESSION['duo_username']);
            
            // Clean up session
            unset($_SESSION['duo_state']);
            
            return $username === $_SESSION['duo_username'];
        } catch (DuoException $e) {
            error_log("Duo verification error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Perform Duo health check
     * @return array Health check results
     */
    public function healthCheck() {
        if (!$this->client) {
            return [
                'success' => false,
                'message' => 'Duo client not configured'
            ];
        }
        
        try {
            $this->client->healthCheck();
            return [
                'success' => true,
                'message' => 'Duo service is reachable and configured correctly'
            ];
        } catch (DuoException $e) {
            return [
                'success' => false,
                'message' => 'Health check failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Register a user for Duo
     * @param int $userId
     * @param string $username Duo username (usually email)
     * @return bool
     */
    public function registerUser($userId, $username) {
        $twoFactor = new TwoFactor();
        return $twoFactor->enable($userId, 'duo', [
            'duo_username' => $username
        ]);
    }
    
    /**
     * Get Duo username for a user
     * @param int $userId
     * @return string|null
     */
    public function getDuoUsername($userId) {
        $stmt = $this->db->prepare("
            SELECT duo_username 
            FROM user_2fa 
            WHERE user_id = ? AND method = 'duo' AND is_enabled = 1
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['duo_username'] : null;
    }
    
    /**
     * Get configuration for admin panel
     * @return array
     */
    public function getConfig() {
        return [
            'client_id' => $this->config['client_id'] ?? '',
            'client_secret' => !empty($this->config['client_secret']) ? '***' : '',
            'api_hostname' => $this->config['api_hostname'] ?? '',
            'redirect_uri' => $this->config['redirect_uri'] ?? '',
            'is_configured' => $this->isConfigured()
        ];
    }
    
    /**
     * Update Duo configuration (admin function)
     * @param array $newConfig
     * @return bool
     */
    public function updateConfig($newConfig) {
        $keys = ['client_id', 'client_secret', 'api_hostname', 'redirect_uri'];
        
        foreach ($keys as $key) {
            if (isset($newConfig[$key])) {
                $configKey = 'duo_' . $key;
                $stmt = $this->db->prepare("
                    INSERT INTO config (config_key, config_value) 
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE config_value = ?
                ");
                $stmt->execute([$configKey, $newConfig[$key], $newConfig[$key]]);
            }
        }
        
        // Reload configuration
        $this->config = $this->loadConfig();
        
        // Reinitialize client
        if ($this->isConfigured()) {
            try {
                $this->client = new Client(
                    $this->config['client_id'],
                    $this->config['client_secret'],
                    $this->config['api_hostname'],
                    $this->config['redirect_uri']
                );
                return true;
            } catch (DuoException $e) {
                error_log("Duo client initialization error: " . $e->getMessage());
                return false;
            }
        }
        
        return false;
    }
    
    /**
     * Test Duo configuration
     * @param array $testConfig
     * @return array Test results
     */
    public function testConfiguration($testConfig) {
        try {
            $testClient = new Client(
                $testConfig['client_id'],
                $testConfig['client_secret'],
                $testConfig['api_hostname'],
                $testConfig['redirect_uri']
            );
            
            $testClient->healthCheck();
            
            return [
                'success' => true,
                'message' => 'Configuration is valid and Duo service is reachable'
            ];
        } catch (DuoException $e) {
            return [
                'success' => false,
                'message' => 'Configuration test failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get Duo usage statistics for a user
     * @param int $userId
     * @return array
     */
    public function getUserStats($userId) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_attempts,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed,
                MAX(attempted_at) as last_attempt
            FROM 2fa_attempts
            WHERE user_id = ? AND method = 'duo'
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
