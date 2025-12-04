<?php
/**
 * Authentication and Authorization Class
 */
class Auth {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Register a new user
     */
    public function register($username, $email, $password, $role = 'user') {
        try {
            $stmt = $this->db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt->execute([$username, $email, $passwordHash, $role]);
            
            $userId = $this->db->lastInsertId();
            AuditLog::log($userId, 'user_registered', 'user', $userId, "User registered: $username");
            
            return $userId;
        } catch (PDOException $e) {
            error_log("Registration failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Login user
     */
    public function login($username, $password) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Update last login
                $updateStmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$user['id']]);

                // Set session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['email'] = $user['email'];

                AuditLog::log($user['id'], 'user_login', 'user', $user['id'], "User logged in: $username");

                return true;
            }
            return false;
        } catch (PDOException $e) {
            error_log("Login failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Logout user
     */
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            AuditLog::log($_SESSION['user_id'], 'user_logout', 'user', $_SESSION['user_id'], "User logged out");
        }
        session_destroy();
    }

    /**
     * Check if user is logged in
     */
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    /**
     * Check if user is admin
     */
    public static function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }

    /**
     * Get current user ID
     */
    public static function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Require login
     */
    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            header('Location: /login.php');
            exit;
        }
    }

    /**
     * Require admin
     */
    public static function requireAdmin() {
        self::requireLogin();
        if (!self::isAdmin()) {
            header('Location: /dashboard.php');
            exit;
        }
    }

    /**
     * Get user by ID
     */
    public function getUserById($userId) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    /**
     * Update user storage used
     */
    public function updateStorageUsed($userId, $bytes) {
        $stmt = $this->db->prepare("UPDATE users SET storage_used = storage_used + ? WHERE id = ?");
        return $stmt->execute([$bytes, $userId]);
    }

    /**
     * Check if user has storage space
     */
    public function hasStorageSpace($userId, $requiredBytes) {
        $user = $this->getUserById($userId);
        if (!$user) return false;
        return ($user['storage_used'] + $requiredBytes) <= $user['storage_quota'];
    }
}
