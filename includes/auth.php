<?php
/**
 * Mimir File Management System
 * Authentication and Session Management
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/ldap.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/TwoFactor.php';

class Auth {
    private $db;
    private $logger;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->initSession();
    }
    
    /**
     * Initialize session
     */
    private function initSession() {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
            // Allow session cookies to work with external redirects (Duo)
            ini_set('session.cookie_samesite', 'None');
            
            session_name(SESSION_NAME);
            session_start();
            
            // Regenerate session ID periodically (but not during 2FA flow)
            if (!isset($_SESSION['created'])) {
                $_SESSION['created'] = time();
            } else if (time() - $_SESSION['created'] > 1800) {
                // Don't regenerate if 2FA is pending to avoid losing variables
                if (empty($_SESSION['2fa_pending'])) {
                    session_regenerate_id(true);
                    $_SESSION['created'] = time();
                }
            }
        }
    }
    
    /**
     * Login user with username and password
     */
    public function login($username, $password, $remember = false) {
        try {
            // Check if LDAP is enabled
            $ldapEnabled = $this->getLdapStatus();
            
            $user = null;
            $isLdapUser = false;
            
            // Try AD/LDAP authentication first if enabled
            if ($ldapEnabled) {
                // Prefer AD settings when enabled
                $useAd = $this->isAdEnabled();
                $ldapAuth = $useAd ? new LdapAuth('ad') : new LdapAuth('ldap');
                if ($ldapAuth->authenticate($username, $password)) {
                    // LDAP authentication successful
                    $isLdapUser = true;
                    
                    // Check if user exists in database
                    $user = $this->getUserByUsername($username);
                    
                    // If not, create user from LDAP
                    if (!$user) {
                        $ldapUser = $ldapAuth->getUserInfo($username);
                        $userId = $this->createLdapUser($username, $ldapUser);
                        $user = $this->getUserById($userId);
                    }
                    
                    // If AD is enabled, check admin group membership and set role accordingly
                    if ($useAd) {
                        try {
                            $stmt = $this->db->prepare("SELECT config_value FROM config WHERE config_key = 'ad_admin_group_dn' LIMIT 1");
                            $stmt->execute();
                            $r = $stmt->fetch();
                            $adAdminGroupDn = $r ? trim($r['config_value']) : '';
                            if (!empty($adAdminGroupDn)) {
                                $isMember = false;
                                try {
                                    $isMember = $ldapAuth->isMemberOf($username, $adAdminGroupDn);
                                } catch (Exception $e) {
                                    // ignore membership check failures but log
                                    $this->logger->log($user['id'] ?? null, 'ldap_group_check_failed', 'auth', $user['id'] ?? null, 'AD group membership check failed: ' . $e->getMessage());
                                }

                                if ($isMember && $user && $user['role'] !== 'admin') {
                                    $uStmt = $this->db->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
                                    $uStmt->execute([$user['id']]);
                                    $this->logger->log($user['id'], 'role_granted_via_ad', 'user', $user['id'], "Granted admin via AD group: $adAdminGroupDn");
                                    // refresh user
                                    $user = $this->getUserById($user['id']);
                                } elseif (!$isMember && $user && $user['role'] === 'admin') {
                                    // If user previously had admin and no longer in AD group, revoke
                                    $uStmt = $this->db->prepare("UPDATE users SET role = 'user' WHERE id = ?");
                                    $uStmt->execute([$user['id']]);
                                    $this->logger->log($user['id'], 'role_revoked_via_ad', 'user', $user['id'], "Revoked admin via AD group absence: $adAdminGroupDn");
                                    $user = $this->getUserById($user['id']);
                                }
                            }
                        } catch (Exception $e) {
                            // don't block login on config read failure
                            error_log('AD admin group check error: ' . $e->getMessage());
                        }
                    }
                }
            }
            
            // If not LDAP or LDAP failed, try local authentication
            if (!$user) {
                $user = $this->getUserByUsername($username);
                
                if (!$user || $user['is_ldap']) {
                    $this->logger->log(null, 'login_failed', 'user', null, "Failed login attempt for username: $username");
                    return false;
                }
                
                // Verify password
                if (!password_verify($password, $user['password'])) {
                    $this->logger->log($user['id'], 'login_failed', 'user', $user['id'], "Incorrect password");
                    return false;
                }
            }
            
            // Check if user is active
            if (!$user['is_active']) {
                $this->logger->log($user['id'], 'login_failed', 'user', $user['id'], "Inactive user attempted login");
                return false;
            }
            
            // If user has 2FA enabled, set pending state instead of creating the session
            try {
                $twoFactor = new TwoFactor();
                if ($twoFactor->isEnabled($user['id'])) {
                    $deviceHash = $twoFactor->getDeviceHash();
                    if (!$twoFactor->isDeviceTrusted($user['id'], $deviceHash)) {
                        // Set pending 2FA and return; login page will handle redirect to Duo/TOTP
                        $_SESSION['2fa_user_id'] = $user['id'];
                        $_SESSION['2fa_pending'] = true;
                        return true;
                    }
                    // If device is trusted, fall through and create normal session
                }
            } catch (Exception $e) {
                // If any 2FA check fails, proceed with normal session creation to avoid blocking login
                error_log('TwoFactor check failed during login: ' . $e->getMessage());
            }

            // Create session
            $this->createSession($user);
            
            // Update last login
            $this->updateLastLogin($user['id']);
            
            // Log successful login
            $this->logger->log($user['id'], 'login_success', 'user', $user['id'], "Successful login");
            
            return true;
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Logout current user
     */
    public function logout() {
        if ($this->isLoggedIn()) {
            $userId = $_SESSION['user_id'];
            
            // Delete session from database
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = ?");
            $stmt->execute([session_id()]);
            
            // Log logout
            $this->logger->log($userId, 'logout', 'user', $userId, "User logged out");
            
            // Destroy session
            $_SESSION = [];
            session_destroy();
        }
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['username']);
    }
    
    /**
     * Check if current user is admin
     */
    public function isAdmin() {
        return $this->isLoggedIn() && $_SESSION['role'] === 'admin';
    }
    
    /**
     * Get current user ID
     */
    public function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Get current user data
     */
    public function getUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        return $this->getUserById($_SESSION['user_id']);
    }
    
    /**
     * Check if system is in maintenance mode
     */
    public function checkMaintenanceMode() {
        // Skip check for admins
        if ($this->isAdmin()) {
            return;
        }
        
        require_once __DIR__ . '/../classes/Config.php';
        $config = new Config();
        $maintenanceMode = $config->get('maintenance_mode', '0');
        
        if ($maintenanceMode === '1') {
            // Get current script to avoid redirect loop
            $currentScript = basename($_SERVER['PHP_SELF']);
            if ($currentScript !== 'maintenance.php' && $currentScript !== 'logout.php') {
                header('Location: ' . BASE_URL . '/maintenance.php');
                exit;
            }
        }
    }
    
    /**
     * Require login
     */
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
        
        // Check maintenance mode after login
        $this->checkMaintenanceMode();
    }
    
    /**
     * Require admin role
     */
    public function requireAdmin() {
        $this->requireLogin();
        if (!$this->isAdmin()) {
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
    }
    
    /**
     * Create user session
     */
    private function createSession($user) {
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['is_ldap'] = $user['is_ldap'];
        $_SESSION['created'] = time();

        // Set session language preference from user profile if available,
        // otherwise fall back to global default.
        try {
            require_once __DIR__ . '/../classes/Config.php';
            $config = new Config();
            if (!empty($user['language'])) {
                $_SESSION['lang'] = $user['language'];
            } else {
                $_SESSION['lang'] = $config->get('default_language', 'es');
            }
        } catch (Exception $e) {
            // ignore language setting failures
        }
        
        // Store session in database
        $stmt = $this->db->prepare("
            INSERT INTO sessions (id, user_id, ip_address, user_agent, data) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                user_id = VALUES(user_id),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                data = VALUES(data),
                last_activity = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([
            session_id(),
            $user['id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            json_encode($_SESSION)
        ]);
    }
    
    /**
     * Get user by username
     */
    private function getUserByUsername($username) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        return $stmt->fetch();
    }
    
    /**
     * Get user by ID
     */
    private function getUserById($id) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Create LDAP user in database
     */
    private function createLdapUser($username, $ldapData) {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, email, full_name, role, is_active, is_ldap) 
            VALUES (?, ?, ?, 'user', 1, 1)
        ");
        
        $email = $ldapData['email'] ?? $username . '@ldap.local';
        $fullName = $ldapData['name'] ?? $username;
        
        $stmt->execute([$username, $email, $fullName]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Update last login time
     */
    private function updateLastLogin($userId) {
        $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
    }
    
    /**
     * Get LDAP enabled status from config
     */
    private function getLdapStatus() {
        // Consider either LDAP or AD enabling flags
        $stmt = $this->db->prepare("SELECT config_key, config_value FROM config WHERE config_key IN ('enable_ldap','enable_ad')");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $enabled = false;
        foreach ($rows as $r) {
            if ($r['config_key'] === 'enable_ldap' && $r['config_value']) $enabled = $enabled || (bool)$r['config_value'];
            if ($r['config_key'] === 'enable_ad' && $r['config_value']) $enabled = $enabled || (bool)$r['config_value'];
        }
        return $enabled;
    }

    private function isAdEnabled() {
        $stmt = $this->db->prepare("SELECT config_value FROM config WHERE config_key = 'enable_ad'");
        $stmt->execute();
        $r = $stmt->fetch();
        return $r ? (bool)$r['config_value'] : false;
    }
    
    /**
     * Validate CSRF token
     */
    public function validateCsrfToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Generate CSRF token
     */
    public function generateCsrfToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
