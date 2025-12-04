<?php
require_once __DIR__ . '/../includes/init.php';

$error = '';
$success = '';

if (Auth::isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = 'Please enter username and password';
        } else {
            $auth = new Auth();
            if ($auth->login($username, $password)) {
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Invalid username or password';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'register') {
        if (!SystemConfig::get('allow_registration', true)) {
            $error = 'Registration is currently disabled';
        } else {
            $username = $_POST['username'] ?? '';
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($username) || empty($email) || empty($password)) {
                $error = 'All fields are required';
            } elseif ($password !== $confirmPassword) {
                $error = 'Passwords do not match';
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters';
            } else {
                $auth = new Auth();
                try {
                    $userId = $auth->register($username, $email, $password);
                    if ($userId) {
                        $success = 'Registration successful! Please log in.';
                    } else {
                        $error = 'Registration failed. Username or email may already exist.';
                    }
                } catch (Exception $e) {
                    $error = 'Registration failed. Username or email may already exist.';
                }
            }
        }
    }
}

$siteName = SystemConfig::get('site_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml($siteName); ?> - Login</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <div class="login-container">
            <h1><?php echo escapeHtml($siteName); ?></h1>
            <p class="subtitle">Personal Cloud Storage</p>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo escapeHtml($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo escapeHtml($success); ?></div>
            <?php endif; ?>
            
            <div class="tabs">
                <button class="tab-button active" onclick="showTab('login')">Login</button>
                <?php if (SystemConfig::get('allow_registration', true)): ?>
                <button class="tab-button" onclick="showTab('register')">Register</button>
                <?php endif; ?>
            </div>
            
            <div id="login-tab" class="tab-content active">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="login">
                    
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Login</button>
                </form>
            </div>
            
            <?php if (SystemConfig::get('allow_registration', true)): ?>
            <div id="register-tab" class="tab-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="register">
                    
                    <div class="form-group">
                        <label for="reg_username">Username</label>
                        <input type="text" id="reg_username" name="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="reg_email">Email</label>
                        <input type="email" id="reg_email" name="email" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="reg_password">Password</label>
                        <input type="password" id="reg_password" name="password" required minlength="6">
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Register</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="js/script.js"></script>
</body>
</html>
