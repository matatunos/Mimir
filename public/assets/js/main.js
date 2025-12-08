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
            return response.json();
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
function toggleUserMenu() {
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
