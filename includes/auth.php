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
            // Check if LDAP/AD is enabled
            $ldapEnabled = $this->getLdapStatus();

            $user = null;
            $isLdapUser = false;

            // Try Active Directory first if enabled, then fallback to LDAP
            $adEnabled = $this->getLdapStatus('ad');
            if ($adEnabled) {
                $ldapAuth = new LdapAuth('ad');
                if ($ldapAuth->authenticate($username, $password)) {
                    $isLdapUser = true;
                    $user = $this->getUserByUsername($username);

                    // Enforce AD required group if configured
                    require_once __DIR__ . '/../classes/Config.php';
                    $config = new Config();
                    $requiredGroup = $config->get('ad_required_group_dn', '');
                    $adminGroup = $config->get('ad_admin_group_dn', '');

                    if (!empty($requiredGroup) && !$ldapAuth->isMemberOf($username, $requiredGroup)) {
                        $this->logger->log(null, 'login_failed', 'user', null, "AD user not in required group: $username");
                        return false;
                    }

                    $isAdminFromAd = false;
                    if (!empty($adminGroup) && $ldapAuth->isMemberOf($username, $adminGroup)) {
                        $isAdminFromAd = true;
                    }

                    if (!$user) {
                        $ldapUser = $ldapAuth->getUserInfo($username);
                        $role = $isAdminFromAd ? 'admin' : 'user';
                        $userId = $this->createLdapUser($username, $ldapUser, $role);
                        $user = $this->getUserById($userId);
                    } else {
                        // Update role if changed according to AD admin group
                        $desiredRole = $isAdminFromAd ? 'admin' : 'user';
                        if ($user['role'] !== $desiredRole) {
                            $stmt = $this->db->prepare("UPDATE users SET role = ? WHERE id = ?");
                            $stmt->execute([$desiredRole, $user['id']]);
                            $this->logger->log($user['id'], 'role_updated', 'user', $user['id'], "Role updated via AD group sync to $desiredRole");
                            // Refresh user
                            $user = $this->getUserById($user['id']);
                        }
                    }
                }
            }

            if (!$isLdapUser && $ldapEnabled) {
                $ldapAuth = new LdapAuth('ldap');
                if ($ldapAuth->authenticate($username, $password)) {
                    $isLdapUser = true;
                    $user = $this->getUserByUsername($username);

                    // Enforce LDAP required group if configured (optional)
                    require_once __DIR__ . '/../classes/Config.php';
                    $config = new Config();
                    $requiredGroup = $config->get('ldap_required_group_dn', '');
                    $adminGroup = $config->get('ldap_admin_group_dn', '');

                    if (!empty($requiredGroup) && !$ldapAuth->isMemberOf($username, $requiredGroup)) {
                        $this->logger->log(null, 'login_failed', 'user', null, "LDAP user not in required group: $username");
                        return false;
                    }

                    $isAdminFromLdap = false;
                    if (!empty($adminGroup) && $ldapAuth->isMemberOf($username, $adminGroup)) {
                        $isAdminFromLdap = true;
                    }

                    if (!$user) {
                        $ldapUser = $ldapAuth->getUserInfo($username);
                        $role = $isAdminFromLdap ? 'admin' : 'user';
                        $userId = $this->createLdapUser($username, $ldapUser, $role);
                        $user = $this->getUserById($userId);
                    } else {
                        $desiredRole = $isAdminFromLdap ? 'admin' : 'user';
                        if ($user['role'] !== $desiredRole) {
                            $stmt = $this->db->prepare("UPDATE users SET role = ? WHERE id = ?");
                            $stmt->execute([$desiredRole, $user['id']]);
                            $this->logger->log($user['id'], 'role_updated', 'user', $user['id'], "Role updated via LDAP group sync to $desiredRole");
                            $user = $this->getUserById($user['id']);
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
                    // Log failure with non-sensitive hash metadata (length + prefix) to aid debugging
                    $hashInfo = null;
                    if (!empty($user['password'])) {
                        $hashInfo = [
                            'hash_len' => strlen($user['password']),
                            'hash_prefix' => substr($user['password'], 0, 4)
                        ];
                    }
                    $this->logger->log($user['id'], 'login_failed', 'user', $user['id'], "Incorrect password", $hashInfo);
                    return false;
                }
            }
            
            // Check if user is active
            if (!$user['is_active']) {
                $this->logger->log($user['id'], 'login_failed', 'user', $user['id'], "Inactive user attempted login");
                return false;
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
        $user = $this->getUserById($_SESSION['user_id']);
        if (!$user) {
            // User record missing (deleted). Clear session and redirect to login.
            $this->logout();
            header('Location: ' . BASE_URL . '/login.php?error=' . urlencode('Cuenta no encontrada o eliminada.'));
            exit;
        }
        return $user;
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
    private function createLdapUser($username, $ldapData, $role = 'user') {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, email, full_name, role, is_active, is_ldap) 
            VALUES (?, ?, ?, ?, 1, 1)
        ");
        
        $email = $ldapData['email'] ?? $username . '@ldap.local';
        $fullName = $ldapData['name'] ?? $username;
        
        $stmt->execute([$username, $email, $fullName, $role]);
        
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
     * Get LDAP/AD enabled status from config
     */
    private function getLdapStatus($type = 'ldap') {
        $key = $type === 'ad' ? 'enable_ad' : 'enable_ldap';
        $stmt = $this->db->prepare("SELECT config_value FROM config WHERE config_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? (bool)$result['config_value'] : false;
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
