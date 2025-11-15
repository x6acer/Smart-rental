window.addEventListener('click', function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            closeModal(modal);
        }
    });
});


function closeModal(modal) {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function showSuccess(message, duration = 3000) {
    const alert = document.createElement('div');
    alert.className = 'fixed top-4 right-4 px-4 py-2 bg-green-100 border border-green-400 text-green-700 rounded';
    alert.textContent = message;
    document.body.appendChild(alert);
    
    setTimeout(() => {
        alert.remove();
    }, duration);
}

function showError(message, duration = 3000) {
    const alert = document.createElement('div');
    alert.className = 'fixed top-4 right-4 px-4 py-2 bg-red-100 border border-red-400 text-red-700 rounded';
    alert.textContent = message;
    document.body.appendChild(alert);
    
    setTimeout(() => {
        alert.remove();
    }, duration);
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'GHS'
    }).format(amount);
}

// Function to format date
function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}


function toggleMobileMenu() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('-translate-x-full');
}

function handleFileInput(input) {
    const file = input.files[0];
    const preview = document.getElementById('imagePreview');
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            if (preview) {
                preview.src = e.target.result;
                preview.classList.remove('hidden');
            }
        };
        reader.readAsDataURL(file);
    }
}

// Confirm delete action
function confirmDelete(message = 'Are you sure you want to delete this item?') {
    return confirm(message);
}

// Toggle password visibility
function togglePassword(inputId, buttonId) {
    const input = document.getElementById(inputId);
    const button = document.getElementById(buttonId);
    
    if (input.type === 'password') {
        input.type = 'text';
        button.innerHTML = '<i class="fas fa-eye-slash"></i>';
    } else {
        input.type = 'password';
        button.innerHTML = '<i class="fas fa-eye"></i>';
    }
}

// Handle form submission with AJAX
function submitFormAjax(form, successCallback, errorCallback) {
    const formData = new FormData(form);
    
    fetch(form.action, {
        method: form.method,
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (successCallback) successCallback(data);
        } else {
            if (errorCallback) errorCallback(data.error);
        }
    })
    .catch(error => {
        if (errorCallback) errorCallback('An error occurred. Please try again.');
    });
    
    return false;
}

// Initialize tooltips
function initTooltips() {
    const tooltips = document.querySelectorAll('[data-tooltip]');
    tooltips.forEach(element => {
        const tooltip = element.getAttribute('data-tooltip');
        element.setAttribute('title', tooltip);
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initTooltips();
});
