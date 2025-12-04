// Tab functionality
function showTab(tabName) {
    const tabs = document.querySelectorAll('.tab-content');
    const buttons = document.querySelectorAll('.tab-button');
    
    tabs.forEach(tab => {
        tab.classList.remove('active');
    });
    
    buttons.forEach(button => {
        button.classList.remove('active');
    });
    
    document.getElementById(tabName + '-tab').classList.add('active');
    event.target.classList.add('active');
}

// Modal functions
function showUploadModal() {
    document.getElementById('uploadModal').classList.add('active');
}

function closeUploadModal() {
    document.getElementById('uploadModal').classList.remove('active');
}

function showCreateFolderModal() {
    document.getElementById('createFolderModal').classList.add('active');
}

function closeCreateFolderModal() {
    document.getElementById('createFolderModal').classList.remove('active');
}

// Close modal when clicking outside
window.onclick = function(event) {
    const uploadModal = document.getElementById('uploadModal');
    const folderModal = document.getElementById('createFolderModal');
    
    if (event.target == uploadModal) {
        uploadModal.classList.remove('active');
    }
    
    if (event.target == folderModal) {
        folderModal.classList.remove('active');
    }
}

// File size validation
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('file');
    
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                // Client-side file size validation
                const maxSize = 104857600; // 100MB
                if (file.size > maxSize) {
                    alert('File size exceeds maximum allowed (100MB)');
                    this.value = '';
                }
            }
        });
    }
});
