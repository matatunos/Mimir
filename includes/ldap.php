<?php
/**
 * Mimir File Management System
 * LDAP/Active Directory Authentication
 */

class LdapAuth {
    private $config;
    private $conn;
    
    public function __construct() {
        $this->loadConfig();
    }
    
    /**
     * Load LDAP configuration from database
     */
    private function loadConfig() {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT config_key, config_value 
            FROM config 
            WHERE config_key LIKE 'ldap_%'
        ");
        $stmt->execute();
        
        $this->config = [];
        while ($row = $stmt->fetch()) {
            $key = str_replace('ldap_', '', $row['config_key']);
            $this->config[$key] = $row['config_value'];
        }
    }
    
    /**
     * Authenticate user against LDAP
     */
    public function authenticate($username, $password) {
        if (empty($this->config['host'])) {
            return false;
        }
        
        try {
            // Connect to LDAP server
            $ldapUri = 'ldap://' . $this->config['host'] . ':' . ($this->config['port'] ?? 389);
            $this->conn = ldap_connect($ldapUri);
            
            if (!$this->conn) {
                error_log("LDAP: Could not connect to server");
                return false;
            }
            
            // Set LDAP options
            ldap_set_option($this->conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($this->conn, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($this->conn, LDAP_OPT_NETWORK_TIMEOUT, 10);
            
            // Build user DN
            $userDn = $this->buildUserDn($username);
            
            // Attempt to bind with user credentials
            $bind = @ldap_bind($this->conn, $userDn, $password);
            
            if ($bind) {
                ldap_close($this->conn);
                return true;
            } else {
                error_log("LDAP: Authentication failed for user: $username");
                ldap_close($this->conn);
                return false;
            }
            
        } catch (Exception $e) {
            error_log("LDAP error: " . $e->getMessage());
            if ($this->conn) {
                ldap_close($this->conn);
            }
            return false;
        }
    }
    
    /**
     * Get user information from LDAP
     */
    public function getUserInfo($username) {
        if (empty($this->config['host'])) {
            return null;
        }
        
        try {
            $ldapUri = 'ldap://' . $this->config['host'] . ':' . ($this->config['port'] ?? 389);
            $this->conn = ldap_connect($ldapUri);
            
            if (!$this->conn) {
                return null;
            }
            
            ldap_set_option($this->conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($this->conn, LDAP_OPT_REFERRALS, 0);
            
            // Bind with service account if configured
            if (!empty($this->config['bind_dn']) && !empty($this->config['bind_password'])) {
                $bind = @ldap_bind($this->conn, $this->config['bind_dn'], $this->config['bind_password']);
            } else {
                $bind = @ldap_bind($this->conn);
            }
            
            if (!$bind) {
                ldap_close($this->conn);
                return null;
            }
            
            // Search for user
            $baseDn = $this->config['base_dn'] ?? '';
            $filter = "(sAMAccountName=$username)"; // Active Directory
            
            // Try alternative filter for standard LDAP
            if (strpos($this->config['user_dn'] ?? '', 'uid=') !== false) {
                $filter = "(uid=$username)";
            }
            
            $search = @ldap_search($this->conn, $baseDn, $filter, ['mail', 'cn', 'displayName', 'givenName', 'sn']);
            
            if (!$search) {
                ldap_close($this->conn);
                return null;
            }
            
            $entries = ldap_get_entries($this->conn, $search);
            ldap_close($this->conn);
            
            if ($entries['count'] > 0) {
                $entry = $entries[0];
                return [
                    'email' => $entry['mail'][0] ?? null,
                    'name' => $entry['displayName'][0] ?? $entry['cn'][0] ?? $username,
                    'first_name' => $entry['givenName'][0] ?? null,
                    'last_name' => $entry['sn'][0] ?? null
                ];
            }
            
            return null;
            
        } catch (Exception $e) {
            error_log("LDAP getUserInfo error: " . $e->getMessage());
            if ($this->conn) {
                ldap_close($this->conn);
            }
            return null;
        }
    }
    
    /**
     * Build user DN from username
     */
    private function buildUserDn($username) {
        $userDnPattern = $this->config['user_dn'] ?? '';
        
        if (empty($userDnPattern)) {
            // Default Active Directory format
            return $username . '@' . ($this->config['host'] ?? '');
        }
        
        // Replace {username} placeholder
        return str_replace('{username}', $username, $userDnPattern);
    }
    
    /**
     * Test LDAP connection
     */
    public function testConnection() {
        try {
            $ldapUri = 'ldap://' . $this->config['host'] . ':' . ($this->config['port'] ?? 389);
            $conn = ldap_connect($ldapUri);
            
            if (!$conn) {
                return ['success' => false, 'message' => 'Could not connect to LDAP server'];
            }
            
            ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 10);
            
            // Try to bind
            if (!empty($this->config['bind_dn']) && !empty($this->config['bind_password'])) {
                $bind = @ldap_bind($conn, $this->config['bind_dn'], $this->config['bind_password']);
            } else {
                $bind = @ldap_bind($conn);
            }
            
            ldap_close($conn);
            
            if ($bind) {
                return ['success' => true, 'message' => 'LDAP connection successful'];
            } else {
                return ['success' => false, 'message' => 'LDAP bind failed: ' . ldap_error($conn)];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'LDAP error: ' . $e->getMessage()];
        }
    }
}
