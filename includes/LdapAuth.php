<?php
/**
 * LDAP Authentication Class
 * Supports Active Directory and standard LDAP servers
 */
class LdapAuth {
    private $enabled;
    private $server;
    private $port;
    private $baseDn;
    private $userDn;
    private $useSsl;
    private $useStartTls;
    private $adminDn;
    private $adminPassword;
    private $userFilter;
    private $usernameAttribute;
    private $emailAttribute;
    private $displayNameAttribute;

    public function __construct() {
        // Load LDAP configuration from system_config
        $this->enabled = SystemConfig::get('ldap_enabled', false);
        $this->server = SystemConfig::get('ldap_server', '');
        $this->port = SystemConfig::get('ldap_port', 389);
        $this->baseDn = SystemConfig::get('ldap_base_dn', '');
        $this->userDn = SystemConfig::get('ldap_user_dn', '');
        $this->useSsl = SystemConfig::get('ldap_use_ssl', false);
        $this->useStartTls = SystemConfig::get('ldap_use_starttls', false);
        $this->adminDn = SystemConfig::get('ldap_admin_dn', '');
        $this->adminPassword = SystemConfig::get('ldap_admin_password', '');
        $this->userFilter = SystemConfig::get('ldap_user_filter', '(sAMAccountName={username})'); // AD default
        $this->usernameAttribute = SystemConfig::get('ldap_username_attr', 'sAMAccountName');
        $this->emailAttribute = SystemConfig::get('ldap_email_attr', 'mail');
        $this->displayNameAttribute = SystemConfig::get('ldap_displayname_attr', 'displayName');
    }

    /**
     * Check if LDAP is enabled and configured
     */
    public function isEnabled() {
        return $this->enabled && !empty($this->server) && !empty($this->baseDn);
    }

    /**
     * Authenticate user against LDAP server
     */
    public function authenticate($username, $password) {
        if (!$this->isEnabled()) {
            return false;
        }

        if (!function_exists('ldap_connect')) {
            error_log("LDAP extension not available");
            return false;
        }

        try {
            // Build connection URI
            $protocol = $this->useSsl ? 'ldaps' : 'ldap';
            $uri = "$protocol://{$this->server}:{$this->port}";
            
            $ldap = @ldap_connect($uri);
            
            if (!$ldap) {
                error_log("LDAP: Could not connect to $uri");
                return false;
            }

            // Set LDAP options
            ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 10);

            // Start TLS if configured
            if ($this->useStartTls && !$this->useSsl) {
                if (!@ldap_start_tls($ldap)) {
                    error_log("LDAP: Could not start TLS");
                    ldap_close($ldap);
                    return false;
                }
            }

            // Try to find user DN
            $userDn = $this->findUserDn($ldap, $username);
            
            if (!$userDn) {
                ldap_close($ldap);
                return false;
            }

            // Try to bind with user credentials
            if (@ldap_bind($ldap, $userDn, $password)) {
                // Get user information
                $userInfo = $this->getUserInfo($ldap, $userDn);
                ldap_close($ldap);
                
                return $userInfo;
            } else {
                error_log("LDAP: Authentication failed for user: $username");
                ldap_close($ldap);
                return false;
            }

        } catch (Exception $e) {
            error_log("LDAP authentication error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Find user DN by username
     */
    private function findUserDn($ldap, $username) {
        // If userDn pattern is specified, use it directly
        if (!empty($this->userDn)) {
            return str_replace('{username}', $username, $this->userDn);
        }

        // Otherwise search for user
        try {
            // Bind with admin credentials if provided
            if (!empty($this->adminDn) && !empty($this->adminPassword)) {
                if (!@ldap_bind($ldap, $this->adminDn, $this->adminPassword)) {
                    error_log("LDAP: Admin bind failed");
                    return false;
                }
            } else {
                // Try anonymous bind
                if (!@ldap_bind($ldap)) {
                    error_log("LDAP: Anonymous bind failed");
                    return false;
                }
            }

            // Search for user
            $filter = str_replace('{username}', ldap_escape($username, '', LDAP_ESCAPE_FILTER), $this->userFilter);
            $search = @ldap_search($ldap, $this->baseDn, $filter, [$this->usernameAttribute]);
            
            if (!$search) {
                error_log("LDAP: Search failed for user: $username");
                return false;
            }

            $entries = ldap_get_entries($ldap, $search);
            
            if ($entries['count'] === 0) {
                error_log("LDAP: User not found: $username");
                return false;
            }

            if ($entries['count'] > 1) {
                error_log("LDAP: Multiple users found for: $username");
                return false;
            }

            return $entries[0]['dn'];

        } catch (Exception $e) {
            error_log("LDAP findUserDn error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user information from LDAP
     */
    private function getUserInfo($ldap, $userDn) {
        try {
            $search = @ldap_read($ldap, $userDn, '(objectClass=*)', [
                $this->usernameAttribute,
                $this->emailAttribute,
                $this->displayNameAttribute,
                'cn',
                'givenName',
                'sn'
            ]);

            if (!$search) {
                return false;
            }

            $entries = ldap_get_entries($ldap, $search);
            
            if ($entries['count'] === 0) {
                return false;
            }

            $entry = $entries[0];

            // Extract user information
            $username = $this->getLdapValue($entry, $this->usernameAttribute);
            $email = $this->getLdapValue($entry, $this->emailAttribute);
            $displayName = $this->getLdapValue($entry, $this->displayNameAttribute);
            
            // Fallback for display name
            if (empty($displayName)) {
                $displayName = $this->getLdapValue($entry, 'cn');
            }
            
            // Fallback for email
            if (empty($email)) {
                $email = $username . '@' . $this->server;
            }

            return [
                'username' => $username,
                'email' => $email,
                'display_name' => $displayName,
                'dn' => $userDn,
                'is_ldap' => true
            ];

        } catch (Exception $e) {
            error_log("LDAP getUserInfo error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get value from LDAP entry
     */
    private function getLdapValue($entry, $attribute) {
        $attr = strtolower($attribute);
        if (isset($entry[$attr][0])) {
            return $entry[$attr][0];
        }
        return '';
    }

    /**
     * Test LDAP connection
     */
    public function testConnection() {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'LDAP is not enabled or configured'];
        }

        if (!function_exists('ldap_connect')) {
            return ['success' => false, 'message' => 'LDAP extension is not installed'];
        }

        try {
            $protocol = $this->useSsl ? 'ldaps' : 'ldap';
            $uri = "$protocol://{$this->server}:{$this->port}";
            
            $ldap = @ldap_connect($uri);
            
            if (!$ldap) {
                return ['success' => false, 'message' => "Could not connect to $uri"];
            }

            ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);

            if ($this->useStartTls && !$this->useSsl) {
                if (!@ldap_start_tls($ldap)) {
                    ldap_close($ldap);
                    return ['success' => false, 'message' => 'Could not start TLS'];
                }
            }

            // Try to bind
            if (!empty($this->adminDn) && !empty($this->adminPassword)) {
                if (@ldap_bind($ldap, $this->adminDn, $this->adminPassword)) {
                    ldap_close($ldap);
                    return ['success' => true, 'message' => 'Connection successful (authenticated bind)'];
                } else {
                    ldap_close($ldap);
                    return ['success' => false, 'message' => 'Authentication failed with admin credentials'];
                }
            } else {
                if (@ldap_bind($ldap)) {
                    ldap_close($ldap);
                    return ['success' => true, 'message' => 'Connection successful (anonymous bind)'];
                } else {
                    ldap_close($ldap);
                    return ['success' => false, 'message' => 'Anonymous bind failed'];
                }
            }

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}
