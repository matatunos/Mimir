// Mimir File Management System - Main JavaScript
const Mimir = {
    apiUrl: window.location.origin,
    
    showAlert: function(message, type = 'info') {
        const alert = document.createElement('div');
        alert.className = `alert alert-${type}`;
        alert.textContent = message;
        alert.style.position = 'fixed';
        alert.style.top = '20px';
        alert.style.right = '20px';
        alert.style.zIndex = '9999';
        alert.style.minWidth = '300px';
        document.body.appendChild(alert);
        setTimeout(() => alert.remove(), 5000);
    },

    showAuthBanner: function(message) {
        // If already present, update message and return
        let banner = document.getElementById('mimir-auth-banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'mimir-auth-banner';
            banner.style.position = 'fixed';
            banner.style.top = '0';
            banner.style.left = '0';
            banner.style.right = '0';
            banner.style.zIndex = '2147483647';
            banner.style.background = '#fff3cd';
            banner.style.borderBottom = '1px solid #ffeeba';
            banner.style.color = '#856404';
            banner.style.padding = '0.75rem 1rem';
            banner.style.display = 'flex';
            banner.style.alignItems = 'center';
            banner.style.justifyContent = 'space-between';

            const msg = document.createElement('div');
            msg.id = 'mimir-auth-banner-msg';
            msg.style.flex = '1';
            msg.style.marginRight = '1rem';
            banner.appendChild(msg);

            const actions = document.createElement('div');
            actions.style.display = 'flex';
            actions.style.gap = '0.5rem';

            const relogin = document.createElement('button');
            relogin.className = 'btn';
            relogin.textContent = 'Reautenticar';
            relogin.style.background = '#007bff';
            relogin.style.color = 'white';
            relogin.style.border = 'none';
            relogin.style.padding = '0.4rem 0.8rem';
            relogin.style.borderRadius = '4px';
            relogin.onclick = function() { window.open(window.location.origin + '/login.php', '_blank'); };

            const reload = document.createElement('button');
            reload.className = 'btn';
            reload.textContent = 'Recargar';
            reload.style.background = '#28a745';
            reload.style.color = 'white';
            reload.style.border = 'none';
            reload.style.padding = '0.4rem 0.8rem';
            reload.style.borderRadius = '4px';
            reload.onclick = function() { window.location.reload(); };

            actions.appendChild(relogin);
            actions.appendChild(reload);
            banner.appendChild(actions);

            document.body.appendChild(banner);
        }
        const msgEl = document.getElementById('mimir-auth-banner-msg');
        if (msgEl) msgEl.textContent = message || 'Sesión expirada o no autorizada. Por favor reautentica.';
    },

    hideAuthBanner: function() {
        const banner = document.getElementById('mimir-auth-banner');
        if (banner) banner.remove();
    },

    processListIdsInPages: async function(listUrl, action, pageSize = 500, chunkSize = 100, callbacks = {}) {
        // callbacks: onProgress(processed, total), onLog(text), onError(err), onComplete()
        const onProgress = callbacks.onProgress || function(){};
        const onLog = callbacks.onLog || function(){};
        const onError = callbacks.onError || function(e){ console.error(e); };
        const onComplete = callbacks.onComplete || function(){};

        let offset = 0;
        let total = null;
        let processed = 0;

        try {
            while (true) {
                const url = listUrl + (listUrl.includes('?') ? '&' : '?') + 'limit=' + encodeURIComponent(pageSize) + '&offset=' + encodeURIComponent(offset);
                onLog(`Obteniendo IDs ${offset}..${offset+pageSize}`);
                const resp = await fetch(url, { credentials: 'same-origin' });
                const data = await this.parseJsonResponse(resp);
                if (!data.success) throw new Error(data.message || 'Error obteniendo IDs');
                const ids = data.ids || [];
                if (total === null) total = parseInt(data.total) || null;
                if (!ids.length) break;

                // Process ids in chunks of chunkSize
                // Determine POST URL: use the base of listUrl (before '?') so we post to the API endpoint
                const postUrl = listUrl.split('?')[0];
                for (let i = 0; i < ids.length; i += chunkSize) {
                    const chunk = ids.slice(i, i + chunkSize);
                    const fd = new FormData();
                    fd.append('action', action);
                    fd.append('file_ids', JSON.stringify(chunk));

                    onLog(`Enviando lote de ${chunk.length} elementos`);
                    const postResp = await fetch(postUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const postData = await this.parseJsonResponse(postResp);
                    if (!postData.success) {
                        onLog(`Lote fallido: ${postData.message || 'sin detalle'}`);
                    } else {
                        onLog(`Lote procesado: ${chunk.length}`);
                    }

                    processed += chunk.length;
                    onProgress(processed, total);
                    // small pause to avoid hammering the server
                    await new Promise(r => setTimeout(r, 120));
                }

                // advance
                if (ids.length < pageSize) break;
                offset += pageSize;
            }

            onComplete();
        } catch (err) {
            onError(err);
        }
    },
    
    ajax: function(url, options = {}) {
        const defaults = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        const config = { ...defaults, ...options };
        
        if (config.method === 'POST' && config.body && typeof config.body === 'object') {
            config.body = JSON.stringify(config.body);
        }
        
        return fetch(url, config).then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return this.parseJsonResponse(response);
        });
    },

    parseJsonResponse: function(response) {
        // Read raw text first so we can provide a helpful sample on parse failure,
        // even if the Content-Type header is present but body is empty or malformed.
        return response.text().then(text => {
            const trimmed = (text || '').trim();
            if (!trimmed) {
                const err = new Error('Empty response from server');
                err.serverResponse = '';
                err.fullResponse = text;
                throw err;
            }

            // Detect common signs of an HTML login page or redirect (session expired)
            const lowered = trimmed.toLowerCase();
            const looksLikeHtml = lowered.includes('<html') || lowered.includes('<form') || lowered.includes('name="username"') || lowered.includes('login.php') || lowered.includes('iniciar sesi') || lowered.includes('sign in');
            if (looksLikeHtml || response.status === 401 || response.status === 403) {
                const sample = trimmed.slice(0, 2000);
                const err = new Error('Autenticación requerida (sesión expirada o no autorizado)');
                err.code = 'AUTH_REQUIRED';
                err.serverResponse = sample;
                err.fullResponse = text;
                throw err;
            }

            // Try to parse JSON regardless of content-type header
            try {
                const parsed = JSON.parse(trimmed);
                return parsed;
            } catch (e) {
                const sample = trimmed.slice(0, 2000);
                const err = new Error('Invalid JSON response from server');
                err.serverResponse = sample;
                err.fullResponse = text;
                throw err;
            }
        });
    },
    
    formatFileSize: function(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    },
    
    formatDate: function(date) {
        return new Date(date).toLocaleString('es-ES', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    },
    
    showModal: function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) modal.classList.add('active');
    },
    
    hideModal: function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) modal.classList.remove('active');
    },
    
    toggleMobileMenu: function() {
        const sidebar = document.querySelector('.sidebar');
        if (sidebar) sidebar.classList.toggle('mobile-open');
    },
    
    copyToClipboard: function(text) {
        navigator.clipboard.writeText(text).then(() => {
            this.showAlert('Copiado al portapapeles', 'success');
        }).catch(() => {
            this.showAlert('Error al copiar', 'danger');
        });
    },
    
    selectAll: function(className, checked) {
        document.querySelectorAll('.' + className).forEach(cb => {
            cb.checked = checked;
        });
    },
    
    getSelectedIds: function(className) {
        return Array.from(document.querySelectorAll('.' + className + ':checked'))
            .map(cb => cb.value);
    },
    
    uploadFile: function(file, url, progressCallback) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            const formData = new FormData();
            formData.append('file', file);
            
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable && progressCallback) {
                    const percent = (e.loaded / e.total) * 100;
                    progressCallback(percent);
                }
            });
            
            xhr.addEventListener('load', () => {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        resolve(response);
                    } catch (e) {
                        reject(new Error('Invalid JSON response'));
                    }
                } else {
                    reject(new Error('Upload failed'));
                }
            });
            
            xhr.addEventListener('error', () => {
                reject(new Error('Upload failed'));
            });
            
            xhr.open('POST', url);
            xhr.send(formData);
        });
    }
};

// Toggle user menu dropdown
function toggleUserMenu(event) {
    if (event) {
        event.stopPropagation();
    }
    const menu = document.getElementById('userMenuDropdown');
    if (menu) {
        menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
    }
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    const menu = document.getElementById('userMenuDropdown');
    if (menu && !e.target.closest('.user-menu') && !e.target.closest('#userMenuDropdown')) {
        menu.style.display = 'none';
    }
});

// Close modals when clicking on background
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('active');
    }
});

// Close modals when clicking close button
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-close')) {
        const modal = e.target.closest('.modal');
        if (modal) modal.classList.remove('active');
    }
});

// Toggle maintenance mode
function toggleMaintenance(event, enable, csrfToken) {
    event.preventDefault();
    
    const action = enable ? 'activar' : 'desactivar';
    const message = enable 
        ? '¿Activar el modo mantenimiento? Los usuarios no administradores no podrán acceder al sistema.'
        : '¿Desactivar el modo mantenimiento? Los usuarios podrán volver a acceder al sistema.';
    
    if (!confirm(message)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('enabled', enable ? 'true' : 'false');
    
    fetch(Mimir.apiUrl + '/admin/toggle_maintenance.php', {
        method: 'POST',
        body: formData
    })
    .then(response => this.parseJsonResponse(response))
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al cambiar el modo mantenimiento');
    });
}

