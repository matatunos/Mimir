<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../classes/Invitation.php';
require_once __DIR__ . '/../../classes/Logger.php';

$auth = new Auth();
$auth->requireAdmin();
$user = $auth->getUser();
$inv = new Invitation();
$logger = new Logger();
// Database connection used during POST handling
$db = Database::getInstance()->getConnection();

$error = '';
$success = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = t('error_invalid_csrf');
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create') {
                $email = trim($_POST['email'] ?? '');
                $role = $_POST['role'] ?? 'user';
                $message = trim($_POST['message'] ?? null);
                $expiresHours = isset($_POST['expires_hours']) && $_POST['expires_hours'] !== '' ? intval($_POST['expires_hours']) : null;
                $minExpires = 1;
                $maxExpires = 168; // maximum 7 days (in hours)
                if ($expiresHours !== null) {
                    // Clamp to allowed range: values > max become max, values < min become min
                    if ($expiresHours > $maxExpires) {
                        $expiresHours = $maxExpires;
                    } elseif ($expiresHours < $minExpires) {
                        $expiresHours = $minExpires;
                    }
                }
                $sendEmail = isset($_POST['send_email']);

                    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $error = t('error_invalid_email');
                } else {
                    $forcedUsername = trim($_POST['forced_username'] ?? '');

                    // forced username is mandatory
                    if ($forcedUsername === '') {
                        $error = t('error_forced_username_required');
                    }
                    $force2fa = $_POST['force_2fa'] ?? 'none';

                    // If forced username provided, ensure it does not already exist
                    if (!$error && $forcedUsername) {
                        $stmtChk = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
                        $stmtChk->execute([$forcedUsername]);
                        if ($stmtChk->fetch()) {
                            $error = t('error_forced_username_exists');
                        }
                    }

                    if (empty($error)) {
                        $token = $inv->create($email, $user['id'], ['role' => $role, 'message' => $message, 'expires_hours' => $expiresHours, 'send_email' => $sendEmail, 'forced_username' => $forcedUsername, 'force_2fa' => $force2fa]);
                    } else {
                        $token = false;
                    }
                    if ($token) {
                        $success = $sendEmail ? t('invitation_created_and_sent') : t('invitation_created_and_saved');
                        $logger->log($user['id'], 'invitation_admin_create', 'invitation', null, "Invitación creada para {$email}");
                    } else {
                        $error = t('error_create_invitation');
                    }
                }
            } elseif ($action === 'resend') {
                $id = intval($_POST['invitation_id'] ?? 0);
                if ($inv->resend($id)) {
                    $success = t('invitation_resent_success');
                } else {
                    $error = t('error_resend_invitation');
                }
            } elseif ($action === 'revoke') {
                $id = intval($_POST['invitation_id'] ?? 0);
                if ($inv->revoke($id, $user['id'])) {
                    $success = t('invitation_revoked');
                } else {
                    $error = t('error_revoke_invitation');
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Fetch recent invitations
$db = Database::getInstance()->getConnection();
try {
    // Use LEFT JOIN with explicit COLLATE to avoid illegal mix of collations between users.email and invitations.email
    $sql = "SELECT i.id, i.email, i.token, i.role, i.message, i.forced_username, i.force_2fa, i.created_at, i.expires_at, i.used_at, i.used_by, i.is_revoked, u.username AS existing_username
            FROM invitations i
            LEFT JOIN users u ON u.email COLLATE utf8mb4_unicode_ci = i.email COLLATE utf8mb4_unicode_ci
            ORDER BY i.created_at DESC
            LIMIT 50";
    $stmt = $db->query($sql);
    $invites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $invites = [];
    @file_put_contents('/tmp/mimir_invitations_error.log', date('c') . " - " . $e->getMessage() . "\n", FILE_APPEND);
}

renderPageStart('Invitaciones', 'invitations', true);
renderHeader('Invitaciones', $user);
?>

<div class="content">
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div style="max-width: 800px; margin: 0 auto 1.5rem;">
        <div class="card">
            <div class="card-header"><h2 class="card-title"><i class="fas fa-envelope-open-text"></i> <?php echo t('create'); ?> <?php echo t('invitation'); ?></h2></div>
            <div class="card-body">
                <form method="POST" id="invite-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Rol</label>
                        <select name="role" class="form-control">
                            <option value="user" <?php echo (($_POST['role'] ?? '') === 'user') ? 'selected' : ''; ?>>Usuario</option>
                            <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Administrador</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Mensaje (opcional)</label>
                        <textarea name="message" class="form-control" rows="3"><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Forzar nombre de usuario (obligatorio)</label>
                        <input type="text" id="forced_username" name="forced_username" class="form-control" maxlength="64" required value="<?php echo htmlspecialchars($_POST['forced_username'] ?? ''); ?>">
                        <small id="username-help" class="form-text text-muted">Este nombre será reservado para el invitado. Compruebo disponibilidad al teclear.</small>
                        <div id="username-status" style="margin-top:0.5rem;"></div>
                    </div>

                    <div class="form-group">
                        <label>Forzar 2FA</label>
                        <select name="force_2fa" class="form-control">
                            <option value="none" <?php echo (($_POST['force_2fa'] ?? '') === 'none') ? 'selected' : ''; ?>>No forzar</option>
                            <option value="totp" <?php echo (($_POST['force_2fa'] ?? '') === 'totp') ? 'selected' : ''; ?>>Forzar TOTP (aplicación autenticadora)</option>
                            <option value="duo" <?php echo (($_POST['force_2fa'] ?? '') === 'duo') ? 'selected' : ''; ?>>Forzar Duo</option>
                        </select>
                        <small class="form-text text-muted">Si se selecciona un método, el usuario será forzado a configurar 2FA al aceptar la invitación.</small>
                    </div>

                    <div class="form-group">
                        <label>Caduca en (horas, vacío = valor por defecto)</label>
                        <input type="number" id="expires_hours" name="expires_hours" class="form-control" min="1" max="720" value="<?php echo htmlspecialchars($_POST['expires_hours'] ?? ''); ?>">
                        <small id="expires_help" class="form-text text-muted">Introduce un número entre 1 y 720 (30 días). Déjalo vacío para usar el valor por defecto.</small>
                        <div id="expires_error" style="color:red; margin-top:0.5rem;"></div>
                    </div>

                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                            <input type="checkbox" name="send_email" value="1" checked>
                            <span>Enviar invitación por correo</span>
                        </label>
                    </div>

                    <div style="display:flex;gap:0.75rem;margin-top:1rem;">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> <?php echo t('create'); ?> <?php echo t('invitation'); ?></button>
                        <a href="<?php echo BASE_URL; ?>/admin" class="btn btn-outline"><?php echo htmlspecialchars(t('cancel')); ?></a>
                    </div>
                </form>
            </div>
        </div>
    </div>

                    <script>
                    (function(){
                        const input = document.getElementById('forced_username');
                        const status = document.getElementById('username-status');
                        const submitBtn = document.querySelector('#invite-form button[type="submit"]');
                        let last = '';
                        let timer = null;

                        function setStatus(text, ok) {
                            status.innerHTML = text ? ('<small style="color:' + (ok ? 'green' : 'red') + '">' + text + '</small>') : '';
                            if (ok) {
                                submitBtn.disabled = false;
                            } else {
                                submitBtn.disabled = true;
                            }
                        }

                        function checkUsername(u) {
                            if (!u) { setStatus('El nombre es obligatorio', false); return; }
                            fetch('<?php echo BASE_URL; ?>/admin/check_username.php?username=' + encodeURIComponent(u))
                                .then(Mimir.parseJsonResponse)
                                .then(j => {
                                    if (j.exists) {
                                        setStatus('Nombre de usuario no disponible (' + (j.where || 'registrado') + ')', false);
                                    } else {
                                        setStatus('Nombre disponible', true);
                                    }
                                }).catch(e => {
                                    setStatus('Error comprobando nombre', false);
                                });
                        }

                        if (input) {
                            input.addEventListener('input', function(e){
                                const v = input.value.trim();
                                if (v === last) return;
                                last = v;
                                if (timer) clearTimeout(timer);
                                timer = setTimeout(() => checkUsername(v), 300);
                            });

                            // On page load, trigger check if there's a value
                            if (input.value.trim()) {
                                checkUsername(input.value.trim());
                            }
                        }
                        // Expires hours validation
                        (function(){
                            const form = document.getElementById('invite-form');
                            const expiresInput = document.getElementById('expires_hours');
                            const expiresError = document.getElementById('expires_error');
                            const MIN = 1;
                            const MAX = 168;

                            if (!form || !expiresInput) return;

                            function validateExpires() {
                                expiresError.textContent = '';
                                const v = expiresInput.value.trim();
                                if (v === '') { expiresError.textContent = ''; return true; }
                                if (!/^[0-9]+$/.test(v)) {
                                    expiresError.textContent = 'Introduce solo números enteros.';
                                    return false;
                                }
                                let n = parseInt(v, 10);
                                if (n > MAX) {
                                    expiresError.textContent = `El valor excede ${MAX}. Se ajustará a ${MAX}.`;
                                    expiresInput.value = MAX;
                                    n = MAX;
                                    return true;
                                }
                                if (n < MIN) {
                                    expiresError.textContent = `El valor mínimo es ${MIN}. Se ajustará a ${MIN}.`;
                                    expiresInput.value = MIN;
                                    return true;
                                }
                                expiresError.textContent = '';
                                return true;
                            }

                            expiresInput.addEventListener('input', validateExpires);
                            form.addEventListener('submit', function(e){
                                if (!validateExpires()) {
                                    e.preventDefault();
                                    expiresInput.focus();
                                }
                            });
                        })();
                    })();
                    </script>

    <div style="max-width: 1200px; margin: 0 auto;">
        <div class="card">
            <div class="card-header"><h2 class="card-title"><i class="fas fa-list"></i> Últimas Invitaciones</h2></div>
            <div class="card-body">
                <table class="table table-striped" style="width:100%;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Email</th>
                            <th>Cuenta</th>
                            <th>Rol</th>
                            <th>Creada</th>
                            <th>Expira</th>
                            <th>Usada</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invites as $r): ?>
                            <tr>
                                <td><?php echo (int)$r['id']; ?></td>
                                <td><?php echo htmlspecialchars($r['email']); ?></td>
                                <td><?php echo htmlspecialchars($r['forced_username'] ?? $r['existing_username'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($r['role']); ?></td>
                                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($r['expires_at'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($r['used_at'] ?? ''); ?></td>
                                <td><?php echo $r['is_revoked'] ? '<span style="color:#c00;">Revocada</span>' : ($r['used_at'] ? '<span style="color:green;">Usada</span>' : '<span>Activa</span>'); ?></td>
                                <td>
                                    <?php if (!$r['is_revoked'] && empty($r['used_at'])): ?>
                                        <form method="POST" style="display:inline-block;margin-right:0.5rem;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="resend">
                                            <input type="hidden" name="invitation_id" value="<?php echo (int)$r['id']; ?>">
                                            <button class="btn btn-sm btn-secondary" type="submit"><i class="fas fa-redo"></i> Reenviar</button>
                                        </form>
                                        <form method="POST" style="display:inline-block;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCsrfToken(); ?>">
                                            <input type="hidden" name="action" value="revoke">
                                            <input type="hidden" name="invitation_id" value="<?php echo (int)$r['id']; ?>">
                                            <button class="btn btn-sm btn-danger" type="submit"><i class="fas fa-ban"></i> Revocar</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#666;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php renderPageEnd(); ?>
