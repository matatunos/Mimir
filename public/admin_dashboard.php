<?php
require_once __DIR__ . '/../includes/init.php';

Auth::requireAdmin();

$db = Database::getInstance()->getConnection();

// Get comprehensive statistics
$stats = [];

// Total users
$stmt = $db->query("SELECT COUNT(*) as total FROM users");
$stats['total_users'] = $stmt->fetch()['total'];

// Active users (logged in last 30 days)
$stmt = $db->query("SELECT COUNT(*) as total FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$stats['active_users_30d'] = $stmt->fetch()['total'];

// Total files
$stmt = $db->query("SELECT COUNT(*) as total FROM files");
$stats['total_files'] = $stmt->fetch()['total'];

// Total storage used
$stmt = $db->query("SELECT COALESCE(SUM(file_size), 0) as total FROM files");
$stats['total_storage'] = $stmt->fetch()['total'];

// Active shares
$stmt = $db->query("SELECT COUNT(*) as total FROM public_shares WHERE is_active = 1");
$stats['active_shares'] = $stmt->fetch()['total'];

// Files uploaded today
$stmt = $db->query("SELECT COUNT(*) as total FROM files WHERE DATE(created_at) = CURDATE()");
$stats['files_today'] = $stmt->fetch()['total'];

// Storage quota utilization
$stmt = $db->query("SELECT SUM(storage_quota) as total_quota, SUM(storage_used) as total_used FROM users");
$quotaData = $stmt->fetch();
$stats['total_quota'] = $quotaData['total_quota'];
$stats['total_used'] = $quotaData['total_used'];
$stats['quota_percentage'] = $stats['total_quota'] > 0 ? round(($stats['total_used'] / $stats['total_quota']) * 100, 1) : 0;

// Get activity for last 7 days (for chart)
$stmt = $db->query("
    SELECT DATE(created_at) as date, COUNT(*) as count 
    FROM files 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
");
$activityData = $stmt->fetchAll();

// Get recent audit logs
$recentLogs = AuditLog::getLogs([], 10, 0);

// Top 10 users by storage
$stmt = $db->query("
    SELECT u.id, u.username, u.role, u.storage_used, u.storage_quota
    FROM users u
    ORDER BY u.storage_used DESC
    LIMIT 10
");
$topStorageUsers = $stmt->fetchAll();

// Top 10 users by file count
$stmt = $db->query("
    SELECT u.id, u.username, u.role, COUNT(f.id) as file_count
    FROM users u
    LEFT JOIN files f ON u.id = f.user_id
    GROUP BY u.id
    ORDER BY file_count DESC
    LIMIT 10
");
$topFileUsers = $stmt->fetchAll();

// Inactive users (no login in last 30 days)
$stmt = $db->query("
    SELECT u.id, u.username, u.email, u.role, u.last_login, u.created_at
    FROM users u
    WHERE (u.last_login IS NULL OR u.last_login < DATE_SUB(NOW(), INTERVAL 30 DAY))
    AND u.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY u.last_login ASC
    LIMIT 20
");
$inactiveUsers30d = $stmt->fetchAll();

// Inactive users (no login in last year)
$stmt = $db->query("
    SELECT COUNT(*) as total
    FROM users u
    WHERE (u.last_login IS NULL OR u.last_login < DATE_SUB(NOW(), INTERVAL 1 YEAR))
    AND u.created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)
");
$inactiveUsers1y = $stmt->fetch()['total'];

// Most active users (by recent activity)
$stmt = $db->query("
    SELECT u.id, u.username, u.role, COUNT(a.id) as action_count, MAX(a.created_at) as last_action
    FROM users u
    LEFT JOIN audit_logs a ON u.id = a.user_id AND a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY u.id
    ORDER BY action_count DESC
    LIMIT 10
");
$mostActiveUsers = $stmt->fetchAll();

$siteName = SystemConfig::get('site_name', APP_NAME);
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel de Administración - Mimir</title>
    <link rel="stylesheet" href="/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body>
        <!-- Modal de edición de usuario -->
        <div id="editUserModal" class="modal" style="display:none;">
            <div class="modal-content" style="max-width: 420px;">
                <div class="modal-header">
                    <h3><i class="fas fa-user-edit"></i> Editar Usuario</h3>
                    <button class="modal-close" onclick="closeEditUserModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div id="editUserModalBody" style="padding: 1.5rem 0.5rem 0.5rem 0.5rem; text-align: left;">
                    <form id="editUserForm">
                        <input type="hidden" name="user_id" id="editUserId">
                        <div style="margin-bottom:1em;">
                            <label>Usuario:</label>
                            <input type="text" name="username" id="editUsername" required>
                        </div>
                        <div style="margin-bottom:1em;">
                            <label>Email:</label>
                            <input type="email" name="email" id="editEmail" required>
                        </div>
                        <div style="margin-bottom:1em;">
                            <label>Rol:</label>
                            <select name="role" id="editRole">
                                <option value="user">Usuario</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>
                        <div style="margin-bottom:1em;">
                            <label>Método de Doble Factor</label>
                            <select name="twofa_method" id="edit2FAMethod">
                                <option value="none">Ninguno</option>
                                <option value="totp">TOTP (App Authenticator)</option>
                                <option value="duo">Duo Security</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </form>
                    <div id="editUserMsg" style="margin-top:1em;"></div>
                </div>
            </div>
        </div>
    <div id="menu">
        <div class="logo">Mimir Admin</div>
        <div class="nav">
            <a href="#stats" class="active" onclick="showSection('stats')">Estadísticas</a>
            <a href="#users" onclick="showSection('users')">Usuarios</a>
            <a href="#files" onclick="showSection('files')">Archivos</a>
            <a href="#shares" onclick="showSection('shares')">Compartidos</a>
            <a href="#config" onclick="showSection('config')">Configuración</a>
            <a href="logout.php">Salir</a>
        </div>
        <div class="user">Administrador</div>
    </div>
    <div id="content">
        <div id="section-stats" class="section">
            <!-- Estadísticas generales -->
            <div class="stats-grid">
                <div class="stat-card info">
                    <h3>Total Usuarios</h3>
                    <div class="stat-value"><?php echo number_format($stats['total_users']); ?></div>
                    <div class="stat-subtitle"><?php echo $stats['active_users_30d']; ?> activos (30d)</div>
                </div>
                <div class="stat-card success">
                    <h3>Total Archivos</h3>
                    <div class="stat-value"><?php echo number_format($stats['total_files']); ?></div>
                    <div class="stat-subtitle"><?php echo $stats['files_today']; ?> subidos hoy</div>
                </div>
                <div class="stat-card <?php echo $stats['quota_percentage'] > 80 ? 'warning' : ''; ?>">
                    <h3>Almacenamiento</h3>
                    <div class="stat-value"><?php echo formatBytes($stats['total_storage']); ?></div>
                    <div class="stat-subtitle"><?php echo $stats['quota_percentage']; ?>% de la cuota</div>
                </div>
                <div class="stat-card">
                    <h3>Enlaces Compartidos</h3>
                    <div class="stat-value"><?php echo number_format($stats['active_shares']); ?></div>
                    <div class="stat-subtitle">Enlaces activos</div>
                </div>
            </div>
            <!-- Visualización de ocupación por usuario -->
            <h2 style="margin-top:2em;">Ocupación por Usuario</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <?php
                        ?>
                        <tr>
                        <th>Usuario</th>
                        <th>Rol</th>
                        <th>Archivos</th>
                        <th>Espacio Ocupado</th>
                        <th>Cuota</th>
                        <th>% Ocupado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topStorageUsers as $user): ?>
                    <tr data-user-id="<?php echo $user['id']; ?>" data-twofa-method="<?php echo !empty($user['duo_enabled']) ? 'duo' : (!empty($user['twofa_enabled']) ? 'totp' : 'none'); ?>">
                        <td><?php echo escapeHtml($user['username']); ?></td>
                        <td><?php echo strtoupper($user['role']); ?></td>
                        <td><?php echo number_format($user['file_count'] ?? 0); ?></td>
                        <td><?php echo formatBytes($user['storage_used']); ?></td>
                        <td><?php echo formatBytes($user['storage_quota']); ?></td>
                        <td><?php echo round(($user['storage_used'] / max(1,$user['storage_quota']))*100,1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <!-- Ranking de usuarios por archivos subidos -->
            <h2 style="margin-top:2em;">Top Usuarios por Archivos Subidos</h2>
            <ul class="top-users-list">
                <?php foreach ($topFileUsers as $index => $user): ?>
                <li class="top-user-item">
                    <div class="rank-badge <?php echo $index < 3 ? 'rank-'.($index+1) : 'rank-default'; ?>">
                        <?php echo $index + 1; ?>
                    </div>
                    <div class="top-user-info">
                        <span class="top-user-name">
                            <?php echo escapeHtml($user['username']); ?>
                            <?php if ($user['role'] === 'admin'): ?>
                                <span class="badge badge-admin">ADMIN</span>
                            <?php endif; ?>
                        </span>
                        <span class="top-user-value"><?php echo number_format($user['file_count']); ?> archivos</span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <!-- Listado de enlaces compartidos -->
            <h2 style="margin-top:2em;">Enlaces Compartidos</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Archivo</th>
                        <th>Usuario</th>
                        <th>Tipo</th>
                        <th>Token</th>
                        <th>Expira</th>
                        <th>Descargas</th>
                        <th>Protegido</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $stmt = $db->query("SELECT s.*, f.original_filename, u.username FROM public_shares s LEFT JOIN files f ON s.file_id = f.id LEFT JOIN users u ON s.user_id = u.id WHERE s.is_active = 1 ORDER BY s.created_at DESC LIMIT 50");
                    $shares = $stmt->fetchAll();
                    foreach ($shares as $share): ?>
                    <tr>
                        <td><?php echo escapeHtml($share['original_filename']); ?></td>
                        <td><?php echo escapeHtml($share['username']); ?></td>
                        <td><?php echo escapeHtml($share['share_type']); ?></td>
                        <td><?php echo escapeHtml($share['share_token']); ?></td>
                        <td><?php echo $share['expires_at'] ? date('d M Y H:i', strtotime($share['expires_at'])) : 'Sin límite'; ?></td>
                        <td><?php echo $share['current_downloads']; ?> / <?php echo $share['max_downloads'] ?? '-'; ?></td>
                        <td><?php echo $share['requires_password'] ? 'Sí' : 'No'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <!-- Logs de auditoría -->
            <h2 style="margin-top:2em;">Logs de Auditoría Recientes</h2>
            <input type="text" id="auditLogFilter" placeholder="Filtrar logs..." style="margin-bottom:1em;">
            <table class="data-table" id="auditLogTable">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Usuario</th>
                        <th>Acción</th>
                        <th>Entidad</th>
                        <th>ID</th>
                        <th>Detalles</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody id="auditLogTableBody">
                    <!-- Logs AJAX -->
                </tbody>
            </table>
            <div id="auditLogPagination" style="margin:1em 0;"></div>
            <script>
            function block2FA(userId) {
                if (!confirm('¿Seguro que quieres desactivar el doble factor para este usuario?')) return;
                fetch('./admin_user_2fa.php?action=block&user_id=' + encodeURIComponent(userId), {
                    credentials: 'same-origin'
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Doble factor desactivado');
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'No se pudo desactivar 2FA'));
                    }
                })
                .catch(() => {
                    alert('Error de red o backend');
                });
            }
            // Modal edición usuario
            function editUser(userId) {
                // Buscar datos del usuario en la tabla
                var row = document.querySelector('tr[data-user-id="'+userId+'"]');
                if (!row) {
                    // fallback: buscar por id en la tabla
                    var rows = document.querySelectorAll('#userTable tbody tr');
                    rows.forEach(function(r){
                        if (r.cells[0].textContent == userId) row = r;
                    });
                }
                // Si tienes los datos en JS, puedes hacer fetch a backend aquí
                // Para demo, los tomamos de la tabla
                document.getElementById('editUserId').value = userId;
                document.getElementById('editUsername').value = row ? row.cells[0].textContent : '';
                document.getElementById('editEmail').value = row ? row.cells[1].textContent : '';
                document.getElementById('editRole').value = row ? row.cells[2].textContent.toLowerCase() : 'user';
                    // Precargar método 2FA de atributo data-twofa-method
                    let twofa = row ? row.getAttribute('data-twofa-method') : 'none';
                    document.getElementById('edit2FAMethod').value = twofa;
                document.getElementById('editUserModal').style.display = 'block';
            }
            function closeEditUserModal() {
                document.getElementById('editUserModal').style.display = 'none';
                document.getElementById('editUserForm').reset();
                document.getElementById('editUserMsg').innerHTML = '';
            }
            document.getElementById('editUserForm').addEventListener('submit', function(e) {
                e.preventDefault();
                var form = e.target;
                var data = new FormData(form);
                fetch('admin_user_crud.php', {
                    method: 'POST',
                    body: data
                })
                .then(res => res.json())
                .then(json => {
                    if(json.success) {
                        document.getElementById('editUserMsg').innerHTML = '<span style="color:green;">Usuario actualizado correctamente</span>';
                        setTimeout(function(){
                            closeEditUserModal();
                            location.reload();
                        }, 1000);
                    } else {
                        document.getElementById('editUserMsg').innerHTML = '<span style="color:red;">'+(json.error||'Error al actualizar usuario')+'</span>';
                    }
                });
            });
            let auditLogPage = 0;
            function loadAuditLogs(page=0, filter='') {
                fetch('admin_auditlog_ajax.php?page='+page+'&filter='+encodeURIComponent(filter))
                .then(res=>res.json())
                .then(json=>{
                    let rows = '';
                    for(const log of json.logs) {
                        rows += `<tr><td>${log.created_at}</td><td>${log.username||''}</td><td>${log.action}</td><td>${log.entity_type}</td><td>${log.entity_id}</td><td>${log.details||''}</td><td>${log.ip_address||''}</td></tr>`;
                    }
                    document.getElementById('auditLogTableBody').innerHTML = rows;
                    let pag = '';
                    for(let i=0;i<json.pages;i++) {
                        pag += `<button onclick="loadAuditLogs(${i}, document.getElementById('auditLogFilter').value)" ${i===json.page?'style=\'font-weight:bold\'' : ''}>${i+1}</button> `;
                    }
                    document.getElementById('auditLogPagination').innerHTML = pag;
                });
            }
            document.getElementById('auditLogFilter').addEventListener('input', function(){
                auditLogPage = 0;
                loadAuditLogs(0, this.value);
            });
            document.addEventListener('DOMContentLoaded', function(){
                loadAuditLogs();
            });
            </script>
            <!-- Sugerencias adicionales -->
            <h2 style="margin-top:2em;">Sugerencias y Métricas Extra</h2>
            <ul>
                <li>Usuarios nunca conectados: <?php echo $stats['total_users'] - count($mostActiveUsers); ?></li>
                <li>Usuarios inactivos (30+ días): <?php echo count($inactiveUsers30d); ?></li>
                <li>Usuarios inactivos (1+ año): <?php echo $inactiveUsers1y; ?></li>
                <li>Enlaces compartidos protegidos: <?php echo array_sum(array_map(function($s){return $s['requires_password']?1:0;}, $shares)); ?></li>
                <li>Archivos subidos hoy: <?php echo $stats['files_today']; ?></li>
            </ul>
        </div>
        <div id="section-users" class="section" style="display:none">
            <h2>Usuarios</h2>
            <button class="btn-save" onclick="showUserForm()">Añadir Usuario</button>
            <div id="userForm" style="display:none; margin:2em 0;">
                <form id="addUserForm">
                    <div class="config-item"><label>Usuario</label><input type="text" name="username" required></div>
                    <div class="config-item"><label>Email</label><input type="email" name="email" required></div>
                    <div class="config-item"><label>Contraseña</label><input type="password" name="password" required></div>
                    <div class="config-item"><label>Rol</label>
                        <select name="role"><option value="user">Usuario</option><option value="admin">Administrador</option></select>
                    </div>
                    <div class="config-item"><label>Método de Doble Factor</label>
                        <select name="twofa_method" id="create2FAMethod">
                            <option value="none">Ninguno</option>
                            <option value="totp">TOTP (App Authenticator)</option>
                            <option value="duo">Duo Security</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-save">Guardar</button>
                    <button type="button" class="btn-save" style="background:#64748b;" onclick="hideUserForm()">Cancelar</button>
                </form>
                <div id="userFormMsg" style="margin-top:1em;"></div>
            </div>
            <input type="text" id="userFilter" placeholder="Filtrar usuarios..." onkeyup="filterTable('userFilter','userTable')" style="margin-bottom:1em;">
            <table class="data-table" id="userTable">
                <thead>
                    <tr data-user-id="<?php echo $user['id']; ?>">
                        <th>Usuario</th>
                        <th>Email</th>
                        <th>Rol</th>
                        <th>Archivos</th>
                        <th>Espacio Ocupado</th>
                        <th>Último Login</th>
                        <th>2FA</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $stmt = $db->query("SELECT u.*, COUNT(f.id) as file_count, COALESCE(SUM(f.file_size),0) as used FROM users u LEFT JOIN files f ON u.id = f.user_id GROUP BY u.id ORDER BY u.created_at DESC LIMIT 100");
                    $users = $stmt->fetchAll();
                    foreach ($users as $user): ?>
                    <?php
                    $twofa_method = 'none';
                    if (!empty($user['duo_enabled'])) {
                        $twofa_method = 'duo';
                    } else if (!empty($user['twofa_enabled'])) {
                        $twofa_method = 'totp';
                    }
                    ?>
                    <tr data-user-id="<?php echo $user['id']; ?>" data-twofa-method="<?php echo $twofa_method; ?>">
                        <td><?php echo escapeHtml($user['username']); ?></td>
                        <td><?php echo escapeHtml($user['email']); ?></td>
                        <td><span class="badge badge-<?php echo $user['role']; ?>"><?php echo strtoupper($user['role']); ?></span></td>
                        <td><?php echo $user['file_count']; ?></td>
                        <td><?php echo formatBytes($user['used']); ?></td>
                        <td><?php echo $user['last_login'] ? date('d M Y H:i', strtotime($user['last_login'])) : 'Nunca'; ?></td>
                        <td>
                            <div class="actions">
                            <?php if (!empty($user['duo_enabled'])): ?>
                                <span class="badge badge-info"><i class="fas fa-user-shield"></i> Duo</span>
                                <button type="button" class="btn-icon" title="Desactivar Duo" onclick="block2FA(<?php echo $user['id']; ?>)"><i class="fas fa-ban"></i></button>
                            <?php elseif (!empty($user['twofa_enabled'])): ?>
                                <span class="badge badge-success"><i class="fas fa-qrcode"></i> TOTP</span>
                                <button type="button" class="btn-icon" title="Regenerar QR" onclick="show2FAModal(<?php echo $user['id']; ?>, '<?php echo escapeHtml($user['username']); ?>', true)"><i class="fas fa-sync-alt"></i></button>
                                <button type="button" class="btn-icon" title="Desactivar 2FA" onclick="block2FA(<?php echo $user['id']; ?>)"><i class="fas fa-ban"></i></button>
                            <?php else: ?>
                                <span class="badge badge-secondary"><i class="fas fa-times-circle"></i> Ninguno</span>
                                <button type="button" class="btn-icon" title="Activar TOTP" onclick="show2FAModal(<?php echo $user['id']; ?>, '<?php echo escapeHtml($user['username']); ?>', false)"><i class="fas fa-qrcode"></i></button>
                            <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="actions">
                                <button class="btn btn-sm" onclick="editUser('<?php echo $user['id']; ?>')"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-sm btn-danger" onclick="deleteUser('<?php echo $user['id']; ?>')"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <!-- 2FA Modal -->
                <div id="twofaModal" class="modal" style="display:none;">
                    <div class="modal-content" style="max-width: 420px;">
                        <div class="modal-header">
                            <h3><i class="fas fa-qrcode"></i> Doble Factor (TOTP)</h3>
                            <button class="modal-close" onclick="close2FAModal()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div id="twofaModalBody" style="padding: 1.5rem 0.5rem 0.5rem 0.5rem; text-align: center;">
                            <div id="twofaLoading">Cargando...</div>
                            <div id="twofaContent" style="display:none;">
                                <img id="twofaQr" src="" alt="QR TOTP" style="margin-bottom: 1rem; max-width: 220px;">
                                <div style="margin-bottom: 0.5rem; font-size: 1.1em; max-width: 220px; margin:auto; overflow-x:auto; white-space:nowrap;">
                                    <strong>Secreto:</strong> <span id="twofaSecret" style="font-family:monospace; word-break:break-all; max-width:160px; display:inline-block; overflow-x:auto; white-space:nowrap;"></span>
                                </div>
                                <div style="color:#64748b; font-size:0.95em; margin-bottom:1rem;">Escanea el QR con Google Authenticator, Authy, etc.</div>
                                <button class="btn btn-secondary" onclick="close2FAModal()">Cerrar</button>
                            </div>
                            <div id="twofaError" style="display:none; color:#dc2626;">Error al cargar el QR</div>
                        </div>
                    </div>
                </div>
                <script>
                function show2FAModal(userId, username, regenerate) {
                    document.getElementById('twofaModal').style.display = 'flex';
                    document.getElementById('twofaLoading').style.display = '';
                    document.getElementById('twofaContent').style.display = 'none';
                    document.getElementById('twofaError').style.display = 'none';
                    // AJAX para obtener QR y secreto
                    fetch('./admin_user_2fa.php?action=generate&user_id=' + encodeURIComponent(userId) + (regenerate ? '&regenerate=1' : ''), {
                        credentials: 'same-origin'
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById('twofaQr').src = data.qr_url;
                                document.getElementById('twofaSecret').textContent = data.secret;
                                document.getElementById('twofaLoading').style.display = 'none';
                                document.getElementById('twofaContent').style.display = '';
                                setTimeout(function(){ location.reload(); }, 1200);
                            } else {
                                document.getElementById('twofaLoading').style.display = 'none';
                                document.getElementById('twofaError').textContent = data.error || 'Error al cargar el QR';
                                document.getElementById('twofaError').style.display = '';
                            }
                        })
                        .catch((err) => {
                            document.getElementById('twofaLoading').style.display = 'none';
                            document.getElementById('twofaError').textContent = 'Error de red o backend';
                            document.getElementById('twofaError').style.display = '';
                        });
                }
                function close2FAModal() {
                    document.getElementById('twofaModal').style.display = 'none';
                }
                </script>
            </table>
            <div id="userTable-controls" style="margin-bottom:1em;">
                <button id="userTable-prev">Anterior</button>
                <span id="userTable-pagination"></span>
                <button id="userTable-next">Siguiente</button>
            </div>
        </div>
        <div id="section-files" class="section" style="display:none">
            <h2>Archivos</h2>
            <input type="text" id="fileFilter" placeholder="Filtrar archivos..." onkeyup="filterTable('fileFilter','fileTable')" style="margin-bottom:1em;">
            <table class="data-table" id="fileTable">
                <thead>
                    <tr>
                        <th onclick="sortTable('fileTable',0)">Archivo</th>
                        <th onclick="sortTable('fileTable',1)">Usuario</th>
                        <th onclick="sortTable('fileTable',2)">Tamaño</th>
                        <th onclick="sortTable('fileTable',3)">Fecha</th>
                        <th onclick="sortTable('fileTable',4)">Compartido</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $stmt = $db->query("SELECT f.*, u.username, f.is_shared FROM files f LEFT JOIN users u ON f.user_id = u.id ORDER BY f.created_at DESC LIMIT 100");
                    $files = $stmt->fetchAll();
                    foreach ($files as $file): ?>
                    <tr>
                        <td><?php echo escapeHtml($file['original_filename']); ?></td>
                        <td><?php echo escapeHtml($file['username']); ?></td>
                        <td><?php echo formatBytes($file['file_size']); ?></td>
                        <td><?php echo date('d M Y H:i', strtotime($file['created_at'])); ?></td>
                        <td><?php echo $file['is_shared'] ? 'Sí' : 'No'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="section-shares" class="section" style="display:none">
            <h2>Enlaces Compartidos</h2>
            <input type="text" id="shareFilter" placeholder="Filtrar compartidos..." onkeyup="filterTable('shareFilter','shareTable')" style="margin-bottom:1em;">
            <table class="data-table" id="shareTable">
                <thead>
                    <tr>
                        <th onclick="sortTable('shareTable',0)">Archivo</th>
                        <th onclick="sortTable('shareTable',1)">Usuario</th>
                        <th onclick="sortTable('shareTable',2)">Tipo</th>
                        <th onclick="sortTable('shareTable',3)">Token</th>
                        <th onclick="sortTable('shareTable',4)">Expira</th>
                        <th onclick="sortTable('shareTable',5)">Descargas</th>
                        <th onclick="sortTable('shareTable',6)">Protegido</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $stmt = $db->query("SELECT s.*, f.original_filename, u.username FROM public_shares s LEFT JOIN files f ON s.file_id = f.id LEFT JOIN users u ON s.user_id = u.id WHERE s.is_active = 1 ORDER BY s.created_at DESC LIMIT 100");
                    $shares = $stmt->fetchAll();
                    foreach ($shares as $share): ?>
                    <tr>
                        <td><?php echo escapeHtml($share['original_filename']); ?></td>
                        <td><?php echo escapeHtml($share['username']); ?></td>
                        <td><?php echo escapeHtml($share['share_type']); ?></td>
                        <td><?php echo escapeHtml($share['share_token']); ?></td>
                        <td><?php echo $share['expires_at'] ? date('d M Y H:i', strtotime($share['expires_at'])) : 'Sin límite'; ?></td>
                        <td><?php echo $share['current_downloads']; ?> / <?php echo $share['max_downloads'] ?? '-'; ?></td>
                        <td><?php echo $share['requires_password'] ? 'Sí' : 'No'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Configuración -->
        <div id="section-config" class="section" style="display:none">
            <h2>Configuración del Sistema</h2>
            <div class="config-tabs">
                <button class="tab-btn active" onclick="showConfigTab('general')">General</button>
                <button class="tab-btn" onclick="showConfigTab('correo')">Correo</button>
                <button class="tab-btn" onclick="showConfigTab('ldap')">LDAP</button>
                <button class="tab-btn" onclick="showConfigTab('duo')">DUO</button>
                <button class="tab-btn" onclick="showConfigTab('seguridad')">Seguridad</button>
            </div>
            <?php $configs = SystemConfig::getAll(); ?>
            <form method="post" action="save_config.php" class="config-form" id="configForm">               
                <div id="tab-general" class="config-tab">
                    <h3>General</h3>
                    <?php foreach ($configs as $conf): if (!in_array($conf['config_key'], ['smtp_host','smtp_port','smtp_username','smtp_password','smtp_from_email','smtp_from_name','ldap_enabled','ldap_host','ldap_port','ldap_base_dn','ldap_admin_dn','ldap_admin_password','ldap_user_filter','duo_enabled','duo_ikey','duo_skey','duo_host','duo_app_key','twofa_enabled'])): ?>
                    <div class="config-item">
                        <label for="conf_<?php echo escapeHtml($conf['config_key']); ?>">
                            <?php echo escapeHtml($conf['description']); ?>
                            <span style="color:#64748b;font-size:0.9em;">(<?php echo escapeHtml($conf['config_key']); ?>)</span>
                        </label>
                        <?php if ($conf['config_type'] === 'boolean'): ?>
                            <select name="<?php echo escapeHtml($conf['config_key']); ?>" id="conf_<?php echo escapeHtml($conf['config_key']); ?>">
                                <option value="true" <?php if($conf['config_value']=='true')echo 'selected';?>>Sí</option>
                                <option value="false" <?php if($conf['config_value']=='false')echo 'selected';?>>No</option>
                            </select>
                        <?php elseif ($conf['config_type'] === 'integer'): ?>
                            <input type="number" name="<?php echo escapeHtml($conf['config_key']); ?>" id="conf_<?php echo escapeHtml($conf['config_key']); ?>" value="<?php echo escapeHtml($conf['config_value']); ?>">
                        <?php elseif ($conf['config_type'] === 'json'): ?>
                            <textarea name="<?php echo escapeHtml($conf['config_key']); ?>" id="conf_<?php echo escapeHtml($conf['config_key']); ?>" rows="2"><?php echo escapeHtml($conf['config_value']); ?></textarea>
                        <?php else: ?>
                            <input type="text" name="<?php echo escapeHtml($conf['config_key']); ?>" id="conf_<?php echo escapeHtml($conf['config_key']); ?>" value="<?php echo escapeHtml($conf['config_value']); ?>">
                        <?php endif; ?>
                    </div>
                    <?php endif; endforeach; ?>
                </div>
                <div id="tab-correo" class="config-tab" style="display:none">
                    <h3>Correo</h3>
                    <?php foreach ($configs as $conf): if (in_array($conf['config_key'], ['smtp_host','smtp_port','smtp_username','smtp_password','smtp_from_email','smtp_from_name'])): ?>
                    <div class="config-item">
                        <label for="conf_<?php echo escapeHtml($conf['config_key']); ?>">
                            <?php echo escapeHtml($conf['description']); ?>
                            <span style="color:#64748b;font-size:0.9em;">(<?php echo escapeHtml($conf['config_key']); ?>)</span>
                        </label>
                        <input type="text" name="<?php echo escapeHtml($conf['config_key']); ?>" id="conf_<?php echo escapeHtml($conf['config_key']); ?>" value="<?php echo escapeHtml($conf['config_value']); ?>">
                    </div>
                    <?php endif; endforeach; ?>
                    <button type="button" class="btn-save" onclick="testMailConfig()">Probar Correo</button>
                    <div id="mailTestMsg" style="margin-top:1em;"></div>
                </div>
                <div id="tab-ldap" class="config-tab" style="display:none">
                    <h3>LDAP</h3>
                    <?php foreach ($configs as $conf): if (in_array($conf['config_key'], ['ldap_enabled','ldap_host','ldap_port','ldap_base_dn','ldap_admin_dn','ldap_admin_password','ldap_user_filter'])): ?>
                    <div class="config-item">
                        <label for="conf_<?php echo escapeHtml($conf['config_key']); ?>">
                            <?php echo escapeHtml($conf['description']); ?>
                            <span style="color:#64748b;font-size:0.9em;">(<?php echo escapeHtml($conf['config_key']); ?>)</span>
                        </label>
                        <input type="text" name="<?php echo escapeHtml($conf['config_key']); ?>" id="conf_<?php echo escapeHtml($conf['config_key']); ?>" value="<?php echo escapeHtml($conf['config_value']); ?>">
                    </div>
                    <?php endif; endforeach; ?>
                    <button type="button" class="btn-save" onclick="testLdapConfig()">Probar LDAP</button>
                    <div id="ldapTestMsg" style="margin-top:1em;"></div>
                </div>
                <div id="tab-duo" class="config-tab" style="display:none">
                    <h3>DUO</h3>
                    <?php foreach ($configs as $conf): if (in_array($conf['config_key'], ['duo_enabled','duo_ikey','duo_skey','duo_host','duo_app_key'])): ?>
                    <div class="config-item">
                        <label for="conf_<?php echo escapeHtml($conf['config_key']); ?>">
                            <?php echo escapeHtml($conf['description']); ?>
                            <span style="color:#64748b;font-size:0.9em;">(<?php echo escapeHtml($conf['config_key']); ?>)</span>
                        </label>
                        <input type="text" name="<?php echo escapeHtml($conf['config_key']); ?>" id="conf_<?php echo escapeHtml($conf['config_key']); ?>" value="<?php echo escapeHtml($conf['config_value']); ?>">
                    </div>
                    <?php endif; endforeach; ?>
                    <button type="button" class="btn-save" onclick="testDuoConfig()">Probar DUO</button>
                    <div id="duoTestMsg" style="margin-top:1em;"></div>
                </div>
                <div id="tab-seguridad" class="config-tab" style="display:none">
                    <h3>Seguridad</h3>
                    <?php foreach ($configs as $conf): if (in_array($conf['config_key'], ['twofa_enabled'])): ?>
                    <div class="config-item">
                        <label for="conf_<?php echo escapeHtml($conf['config_key']); ?>">
                            <?php echo escapeHtml($conf['description']); ?>
                            <span style="color:#64748b;font-size:0.9em;">(<?php echo escapeHtml($conf['config_key']); ?>)</span>
                        </label>
                        <select name="<?php echo escapeHtml($conf['config_key']); ?>" id="conf_<?php echo escapeHtml($conf['config_key']); ?>">
                            <option value="true" <?php if($conf['config_value']=='true')echo 'selected';?>>Sí</option>
                            <option value="false" <?php if($conf['config_value']=='false')echo 'selected';?>>No</option>
                        </select>
                    </div>
                    <?php endif; endforeach; ?>
                </div>
                <button type="submit" class="btn-save">Guardar Cambios</button>
            </form>
            <div id="configMsg" style="margin-top:1em;"></div>
        </div>

        <script>
        // Mostrar formulario de usuario
        function showUserForm() {
            document.getElementById('userForm').style.display = 'block';
        }
        function hideUserForm() {
            document.getElementById('userForm').style.display = 'none';
            document.getElementById('addUserForm').reset();
            document.getElementById('userFormMsg').innerHTML = '';
        }
        // Enviar formulario de usuario por AJAX
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('addUserForm').addEventListener('submit', function(e) {
                e.preventDefault();
                var form = e.target;
                var data = new FormData(form);
                fetch('admin_user_crud.php', {
                    method: 'POST',
                    body: data
                })
                .then(res => res.json())
                .then(json => {
                    if(json.success) {
                        document.getElementById('userFormMsg').innerHTML = '<span style="color:green;">Usuario creado correctamente</span>';
                        setTimeout(function(){
                            hideUserForm();
                            location.reload();
                        }, 1000);
                    } else {
                        document.getElementById('userFormMsg').innerHTML = '<span style="color:red;">'+(json.error||'Error al crear usuario')+'</span>';
                    }
                });
            });
        });
        function showConfigTab(tab) {
            document.querySelectorAll('.config-tab').forEach(function(el){el.style.display='none';});
            document.querySelectorAll('.tab-btn').forEach(function(btn){btn.classList.remove('active');});
            document.getElementById('tab-'+tab).style.display = 'block';
            document.querySelector('.tab-btn[onclick*="'+tab+'"]').classList.add('active');
        }
            function showSection(section) {
                document.querySelectorAll('.nav a').forEach(a => a.classList.remove('active'));
                document.querySelector('.nav a[href="#'+section+'"]').classList.add('active');
                document.querySelectorAll('.section').forEach(s => s.style.display = 'none');
                document.getElementById('section-' + section).style.display = 'block';
            }
        </script>
        <script>
            const ctx = document.getElementById('activityChart').getContext('2d');
            const activityData = <?php echo json_encode($activityData); ?>;
            
            const labels = [];
            const data = [];
            for (let i = 6; i >= 0; i--) {
                const date = new Date();
                date.setDate(date.getDate() - i);
                const dateStr = date.toISOString().split('T')[0];
                labels.push(date.toLocaleDateString('es-ES', { month: 'short', day: 'numeric' }));
                
                const found = activityData.find(d => d.date === dateStr);
                data.push(found ? parseInt(found.count) : 0);
            }
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Archivos Subidos',
                        data: data,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#667eea',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: { size: 14, weight: 'bold' },
                            bodyFont: { size: 13 }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                color: '#64748b'
                           