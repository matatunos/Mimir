<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/DuoAuth.php';
require_once __DIR__ . '/../classes/TwoFactor.php';

// Initialize auth to start session with SameSite=None
$auth = new Auth();



// Check if 2FA is pending
if (empty($_SESSION['2fa_pending']) || empty($_SESSION['2fa_user_id'])) {
    header('Location: ' . BASE_URL . '/login.php?error=' . urlencode('Sesión inválida'));
    exit;
}

$error = null;

// Get parameters from Duo callback
$duoCode = $_GET['duo_code'] ?? '';
$state = $_GET['state'] ?? '';

if (empty($duoCode) || empty($state)) {
    error_log("Duo callback - Missing parameters");
    $error = 'Parámetros de autenticación inválidos';
} else {
    $duoAuth = new DuoAuth();
    error_log("Duo callback - Verifying callback...");
    
    if ($duoAuth->verifyCallback($duoCode, $state)) {
        error_log("Duo callback - Verification successful");
        // 2FA successful
        $userId = $_SESSION['2fa_user_id'];
        
        // Get user from database
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            error_log("Duo callback - User found: " . $user['username']);
            // Complete login
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            
            // Update last login
            $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            error_log("Duo callback - Login completed, redirecting to index");
            
            // Check if "trust this device" was requested
            if (isset($_POST['trust_device']) && $_POST['trust_device'] === '1') {
                $twoFactor = new TwoFactor();
                $twoFactor->trustDevice($user['id']);
            }
            
            // Clean up 2FA session
            unset($_SESSION['2fa_pending']);
            unset($_SESSION['2fa_user_id']);
            unset($_SESSION['duo_username']);
            
            // Redirect to main page
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        } else {
            error_log("Duo callback - User not found");
            $error = 'Usuario no encontrado';
        }
    } else {
        error_log("Duo callback - Verification failed");
        $error = 'Verificación Duo fallida. Por favor, intenta de nuevo.';
    }
}

// If error, redirect back to login
if ($error) {
    unset($_SESSION['2fa_pending']);
    unset($_SESSION['2fa_user_id']);
    unset($_SESSION['duo_state']);
    unset($_SESSION['duo_username']);
    header('Location: ' . BASE_URL . '/login.php?error=' . urlencode($error));
    exit;
}
