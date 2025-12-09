<?php
/**
 * Mimir File Management System
 * LDAP/Active Directory Authentication
 */

class LdapAuth {
    private $config;
    private $conn;
    private $type;
    
    public function __construct($type = 'ldap') {
        $this->type = $type;
        $this->loadConfig($type);
    }
    
    /**
     * Load LDAP/AD configuration from database
     */
    private function loadConfig($type = 'ldap') {
        $db = Database::getInstance()->getConnection();
        $prefix = $type === 'ad' ? 'ad_' : 'ldap_';
        $stmt = $db->prepare("SELECT config_key, config_value FROM config WHERE config_key LIKE :prefix");
        $stmt->execute([':prefix' => $prefix . '%']);
        $this->config = [];
        while ($row = $stmt->fetch()) {
            $key = str_replace($prefix, '', $row['config_key']);
            $this->config[$key] = $row['config_value'];
        }
    }
    // ...existing code...
    public function authenticate($username, $password) {
        if (empty($this->config['host'])) {
            return false;
        }
        try {
            $port = $this->config['port'] ?? 389;
            $useSsl = !empty($this->config['use_ssl']);
            $useTls = !empty($this->config['use_tls']);
            $scheme = ($port == 636 || $useSsl) ? 'ldaps://' : 'ldap://';
            $ldapUri = $scheme . $this->config['host'] . ':' . $port;
            $this->conn = ldap_connect($ldapUri);
            if (!$this->conn) {
                error_log("LDAP: Could not connect to server");
                return false;
            }
            ldap_set_option($this->conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($this->conn, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($this->conn, LDAP_OPT_NETWORK_TIMEOUT, 10);
            // If configured to use STARTTLS (use_tls) attempt to upgrade the connection.
            if ($useTls && !$useSsl) {
                $startTls = @ldap_start_tls($this->conn);
                if (!$startTls) {
                    $errNo = @ldap_errno($this->conn);
                    $errMsg = @ldap_error($this->conn);
                    error_log("LDAP: STARTTLS failed (errno=$errNo, error=$errMsg)");
                    if (class_exists('Logger')) {
                        try {
                            $logger = new Logger();
                            $logger->log(null, 'ldap_starttls_failed', 'auth', null, 'LDAP STARTTLS failed', [
                                'username' => $username,
                                'ldap_errno' => $errNo,
                                'ldap_error' => $errMsg,
                                'uri' => $ldapUri
                            ]);
                        } catch (Exception $e) {
                            error_log('LDAP logger error: ' . $e->getMessage());
                        }
                    }
                    ldap_close($this->conn);
                    return false;
                }
            }
            $userDn = $this->buildUserDn($username);
            $bind = @ldap_bind($this->conn, $userDn, $password);
            if ($bind) {
                // Successful user bind. Keep connection open long enough for optional checks by caller.
                ldap_close($this->conn);
                return true;
            } else {
                // collect ldap error info
                $errNo = @ldap_errno($this->conn);
                $errMsg = @ldap_error($this->conn);
                error_log("LDAP: Authentication failed for user: $username (user_dn=$userDn, errno=$errNo, error=$errMsg)");
                // try to record a non-sensitive debug entry via Logger (no passwords)
                if (class_exists('Logger')) {
                    try {
                        $logger = new Logger();
                        $logger->log(null, 'ldap_bind_failed', 'auth', null, 'LDAP bind failed for user', [
                            'username' => $username,
                            'user_dn' => $userDn,
                            'ldap_errno' => $errNo,
                            'ldap_error' => $errMsg,
                            'uri' => $ldapUri
                        ]);
                    } catch (Exception $e) {
                        error_log('LDAP logger error: ' . $e->getMessage());
                    }
                }
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
     * Check if a user is member of a given group (DN). Uses AD matching rule for nested groups when available.
     * Returns boolean.
     */
    public function isMemberOf($username, $groupDn) {
        if (empty($groupDn) || empty($this->config['host'])) {
            return false;
        }

        try {
            $port = $this->config['port'] ?? 389;
            $useSsl = !empty($this->config['use_ssl']);
            $useTls = !empty($this->config['use_tls']);
            $scheme = ($port == 636 || $useSsl) ? 'ldaps://' : 'ldap://';
            $ldapUri = $scheme . $this->config['host'] . ':' . $port;
            $conn = @ldap_connect($ldapUri);
            if (!$conn) return false;
            ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 10);
            if ($useTls && !$useSsl) {
                @ldap_start_tls($conn);
            }

            // Bind with configured bind DN if available, otherwise anonymous
            if (!empty($this->config['bind_dn']) && !empty($this->config['bind_password'])) {
                $bind = @ldap_bind($conn, $this->config['bind_dn'], $this->config['bind_password']);
            } else {
                $bind = @ldap_bind($conn);
            }

            if (!$bind) {
                ldap_close($conn);
                return false;
            }

            $baseDn = $this->config['base_dn'] ?? '';

            // Prefer AD matching rule in chain for nested groups if this is AD
            $filter = "(sAMAccountName={$username})";
            if (strpos($this->config['user_dn'] ?? '', 'uid=') !== false) {
                $filter = "(uid={$username})";
            }

            // If using AD or explicit request, use matching rule in chain
            $matchingRule = ':1.2.840.113556.1.4.1941:';
            $searchFilter = "(&{$filter}(memberOf{$matchingRule}={$groupDn}))";
            $search = @ldap_search($conn, $baseDn, $searchFilter, ['dn']);

            if ($search) {
                $entries = ldap_get_entries($conn, $search);
                ldap_close($conn);
                return ($entries && $entries['count'] > 0);
            }

            // Fallback: fetch the user entry and inspect memberOf attribute
            $search2 = @ldap_search($conn, $baseDn, $filter, ['memberOf']);
            if ($search2) {
                $entries2 = ldap_get_entries($conn, $search2);
                if ($entries2 && $entries2['count'] > 0) {
                    $entry = $entries2[0];
                    if (!empty($entry['memberof'])) {
                        for ($i = 0; $i < $entry['memberof']['count']; $i++) {
                            if (strcasecmp($entry['memberof'][$i], $groupDn) === 0) {
                                ldap_close($conn);
                                return true;
                            }
                        }
                    }
                }
            }

            ldap_close($conn);
            return false;
        } catch (Exception $e) {
            error_log('LDAP isMemberOf error: ' . $e->getMessage());
            if (isset($conn) && $conn) ldap_close($conn);
            return false;
        }
    }
    // ...existing code...
    public function getUserInfo($username) {
        if (empty($this->config['host'])) {
            return null;
        }
        try {
            $port = $this->config['port'] ?? 389;
            $useSsl = !empty($this->config['use_ssl']);
            $useTls = !empty($this->config['use_tls']);
            $scheme = ($port == 636 || $useSsl) ? 'ldaps://' : 'ldap://';
            $ldapUri = $scheme . $this->config['host'] . ':' . $port;
            $this->conn = ldap_connect($ldapUri);
            if (!$this->conn) {
                return null;
            }
            ldap_set_option($this->conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($this->conn, LDAP_OPT_REFERRALS, 0);
            if ($useTls && !$useSsl) {
                $startTls = @ldap_start_tls($this->conn);
                if (!$startTls) {
                    ldap_close($this->conn);
                    return null;
                }
            }
            if (!empty($this->config['bind_dn']) && !empty($this->config['bind_password'])) {
                $bind = @ldap_bind($this->conn, $this->config['bind_dn'], $this->config['bind_password']);
            } else {
                $bind = @ldap_bind($this->conn);
            }
            if (!$bind) {
                ldap_close($this->conn);
                return null;
            }
            $baseDn = $this->config['base_dn'] ?? '';
            $filter = "(sAMAccountName=$username)";
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
    // ...existing code...
    private function buildUserDn($username) {
        $userDnPattern = $this->config['user_dn'] ?? '';
        if (empty($userDnPattern)) {
            // Prefer a UPN using a configured domain or derived from base_dn (eg. dc=example,dc=com -> example.com)
            if (!empty($this->config['domain'])) {
                return $username . '@' . $this->config['domain'];
            }
            if (!empty($this->config['base_dn'])) {
                $baseDn = $this->config['base_dn'];
                if (preg_match_all('/dc=([^,\s]+)/i', $baseDn, $matches) && !empty($matches[1])) {
                    $domain = implode('.', $matches[1]);
                    return $username . '@' . $domain;
                }
            }
            // fallback to host if no domain/base_dn available
            return $username . '@' . ($this->config['host'] ?? '');
        }
        return str_replace('{username}', $username, $userDnPattern);
    }
    // ...existing code...
    public function testConnection() {
        try {
            $port = $this->config['port'] ?? 389;
            $useSsl = !empty($this->config['use_ssl']);
            $useTls = !empty($this->config['use_tls']);
            $scheme = ($port == 636 || $useSsl) ? 'ldaps://' : 'ldap://';

            $ldapUri = $scheme . ($this->config['host'] ?? '') . ':' . $port;
            $conn = @ldap_connect($ldapUri);

            if (!$conn) {
                return ['success' => false, 'message' => 'Could not connect to LDAP server', 'debug' => ['uri' => $ldapUri]];
            }

            ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 10);

            if ($useTls && !$useSsl) {
                $startTls = @ldap_start_tls($conn);
                if (!$startTls) {
                    $errNo = @ldap_errno($conn);
                    $errMsg = @ldap_error($conn);
                    ldap_close($conn);
                    return [
                        'success' => false,
                        'message' => 'LDAP STARTTLS failed',
                        'debug' => [
                            'ldap_errno' => $errNo,
                            'ldap_error' => $errMsg,
                            'uri' => $ldapUri
                        ]
                    ];
                }
            }

            if (!empty($this->config['bind_dn']) && !empty($this->config['bind_password'])) {
                $bind = @ldap_bind($conn, $this->config['bind_dn'], $this->config['bind_password']);
            } else {
                $bind = @ldap_bind($conn);
            }

            $errNo = @ldap_errno($conn);
            $errMsg = @ldap_error($conn);

            ldap_close($conn);

            if ($bind) {
                return ['success' => true, 'message' => 'LDAP connection successful'];
            }

            return [
                'success' => false,
                'message' => 'LDAP bind failed',
                'debug' => [
                    'ldap_errno' => $errNo,
                    'ldap_error' => $errMsg,
                    'uri' => $ldapUri
                ]
            ];

        } catch (Exception $e) {
            return ['success' => false, 'message' => 'LDAP error: ' . $e->getMessage()];
        }

    }

}
