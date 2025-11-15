document.addEventListener('DOMContentLoaded', function() {
    // Initialize cart functionality
    initializeCart();
    
    // Initialize form validation
    initializeFormValidation();

    // Initialize quantity controls
    initializeQuantityControls();
});

function initializeCart() {
    // Update cart badge when items are added/removed
    function updateCartBadge() {
        const cartBadge = document.querySelector('#cart-badge');
        if (!cartBadge) return;

        fetch('cart.php?action=count')
            .then(response => response.json())
            .then(data => {
                cartBadge.textContent = data.count;
                cartBadge.style.display = data.count > 0 ? 'inline-flex' : 'none';
            })
            .catch(error => console.error('Error updating cart badge:', error));
    }

    // Add to cart functionality
    const addToCartForms = document.querySelectorAll('.add-to-cart-form');
    addToCartForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(form);
            fetch('cart.php?action=add', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Vehicle added to rental cart successfully!', 'success');
                    updateCartBadge();
                } else {
                    showNotification(data.message || 'Failed to add vehicle to cart', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('An error occurred', 'error');
            });
        });
    });

    // Remove from cart functionality
    const removeCartButtons = document.querySelectorAll('.remove-from-cart');
    removeCartButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            const itemId = this.dataset.itemId;
            fetch(`cart.php?action=remove&item_id=${itemId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const cartItem = button.closest('.cart-item');
                        cartItem.remove();
                        updateCartBadge();
                        updateCartTotal();
                        showNotification('Vehicle removed from rental cart', 'success');
                    } else {
                        showNotification(data.message || 'Failed to remove vehicle', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred', 'error');
                });
        });
    });
}

function initializeFormValidation() {
    // Registration form validation
    const registerForm = document.querySelector('form[action="register.php"]');
    if (registerForm) {
        registerForm.addEventListener('submit', function(e) {
            const password = document.querySelector('#password');
            const confirmPassword = document.querySelector('#confirm_password');
            const email = document.querySelector('#email');

            if (password.value !== confirmPassword.value) {
                e.preventDefault();
                showNotification('Passwords do not match', 'error');
                return;
            }

            if (password.value.length < 6) {
                e.preventDefault();
                showNotification('Password must be at least 6 characters long', 'error');
                return;
            }

            if (!isValidEmail(email.value)) {
                e.preventDefault();
                showNotification('Please enter a valid email address', 'error');
                return;
            }
        });
    }

    // Checkout form validation
    const checkoutForm = document.querySelector('form[action="process_payment.php"]');
    if (checkoutForm) {
        checkoutForm.addEventListener('submit', function(e) {
            const required = checkoutForm.querySelectorAll('[required]');
            let valid = true;

            required.forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    field.classList.add('border-red-500');
                } else {
                    field.classList.remove('border-red-500');
                }
            });

            if (!valid) {
                e.preventDefault();
                showNotification('Please fill in all required fields', 'error');
            }
        });
    }
}

function initializeQuantityControls() {
    // Quantity adjustment buttons
    const quantityControls = document.querySelectorAll('.quantity-control');
    quantityControls.forEach(control => {
        const input = control.querySelector('input[type="number"]');
        const decrementBtn = control.querySelector('.decrement');
        const incrementBtn = control.querySelector('.increment');

        if (input && decrementBtn && incrementBtn) {
            decrementBtn.addEventListener('click', () => {
                if (input.value > 1) {
                    input.value = parseInt(input.value) - 1;
                    updateItemQuantity(input);
                }
            });

            incrementBtn.addEventListener('click', () => {
                input.value = parseInt(input.value) + 1;
                updateItemQuantity(input);
            });

            input.addEventListener('change', () => {
                if (input.value < 1) input.value = 1;
                updateItemQuantity(input);
            });
        }
    });
}

function updateItemQuantity(input) {
    const itemId = input.dataset.itemId;
    const quantity = input.value;

    fetch('cart.php?action=update', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `item_id=${itemId}&quantity=${quantity}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCartTotal();
        } else {
            showNotification(data.message || 'Failed to update quantity', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred', 'error');
    });
}

function updateCartTotal() {
    fetch('cart.php?action=total')
        .then(response => response.json())
        .then(data => {
            const totalElement = document.querySelector('#cart-total');
            if (totalElement) {
                totalElement.textContent = '₵' + data.total.toFixed(2);
            }
        })
        .catch(error => console.error('Error updating cart total:', error));
}

function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 p-4 rounded-md shadow-lg ${
        type === 'success' ? 'bg-green-500' : 'bg-red-500'
    } text-white`;
    notification.textContent = message;

    document.body.appendChild(notification);

    setTimeout(() => {
        notification.remove();
    }, 3000);
}

function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}
