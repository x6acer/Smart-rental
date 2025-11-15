<?php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/includes/rental_status.php';
$db = db_get_conn();

$page_title = "Rental Cart - Smart Rental";
include __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold mb-6">Rental Cart</h1>
    
    <div id="cart-items" class="space-y-4">
        <!-- Selected vehicles will be loaded here via JavaScript -->
    </div>
    
    <div id="cart-summary" class="mt-8 bg-white rounded-xl p-6 shadow-md">
        <div class="flex justify-between text-lg font-bold mb-4">
            <span>Total</span>
            <span id="cart-total">$0.00</span>
        </div>
        
        <button onclick="proceedToCheckout()" 
                class="w-full bg-[#1b4b4b] text-white py-3 rounded-lg font-bold hover:bg-[#228383] transition">
            Proceed to Checkout
        </button>
    </div>
</div>

<template id="cart-item-template">
    <div class="cart-item bg-white rounded-xl p-4 shadow-md flex items-center gap-4">
        <img src="" alt="" class="w-24 h-24 object-cover rounded">
        <div class="flex-1">
            <div class="flex items-center gap-2 mb-2">
                <h3 class="font-bold text-lg"></h3>
                <span class="status"></span>
            </div>
            <p class="text-gray-600">Vehicle Type: <span class="category"></span></p>
            <p class="text-[#1b4b4b] font-bold">$<span class="price"></span>/day × <span class="days"></span> days</p>
        </div>
        <div class="flex flex-col items-end gap-2">
            <p class="font-bold">$<span class="total"></span></p>
            <button onclick="removeFromCart(this)" class="text-red-600 hover:text-red-800">Remove</button>
        </div>
    </div>
</template>

<script>
async function loadCart() {
    const cart = JSON.parse(localStorage.getItem('cart') || '[]');
    const cartItems = document.getElementById('cart-items');
    const template = document.getElementById('cart-item-template');
    let grandTotal = 0;
    
    cartItems.innerHTML = '';
    
    if (cart.length === 0) {
        cartItems.innerHTML = '<p class="text-center py-8">Your rental cart is empty</p>';
        return;
    }
    
    for (const item of cart) {
        const response = await fetch(`/smart_rental/api/rental.php?id=${item.id}`);
        const rental = await response.json();
        
        const clone = template.content.cloneNode(true);
        
        const img = clone.querySelector('img');
        img.src = `/smart_rental/admin/uploads/${rental.image}`;
        img.alt = rental.title;

        // Set title and basic info
        clone.querySelector('h3').textContent = rental.title;
        clone.querySelector('.category').textContent = rental.category_name;
        clone.querySelector('.price').textContent = rental.price_per_day;
        clone.querySelector('.days').textContent = item.days;
        
        // Handle status display
        const statusElem = clone.querySelector('.status');
        if (rental.status) {
            const response = await fetch(`/smart_rental/customer/includes/rental_status.php?action=renderStatus&status=${encodeURIComponent(rental.status)}`);
            const statusHtml = await response.text();
            statusElem.innerHTML = statusHtml;
            
            // If not available, show warning
            if (rental.status.toLowerCase() !== 'available') {
                const warningDiv = document.createElement('div');
                warningDiv.className = 'mt-2 text-red-600 text-sm';
                warningDiv.textContent = `Warning: This vehicle is ${rental.status.toLowerCase()}. It may not be available for rent.`;
                clone.querySelector('.cart-item > div').appendChild(warningDiv);
            }
        }
        
        const total = (rental.price_per_day * item.days).toFixed(2);
        clone.querySelector('.total').textContent = total;
        grandTotal += parseFloat(total);
        
        const div = clone.querySelector('.cart-item');
        div.dataset.rentalId = item.id;
        
        cartItems.appendChild(clone);
    }
    
    document.getElementById('cart-total').textContent = `$${grandTotal.toFixed(2)}`;
}

function removeFromCart(button) {
    const item = button.closest('.cart-item');
    const rentalId = parseInt(item.dataset.rentalId);
    
    let cart = JSON.parse(localStorage.getItem('cart') || '[]');
    cart = cart.filter(item => item.id !== rentalId);
    localStorage.setItem('cart', JSON.stringify(cart));
    
    loadCart();
}

function proceedToCheckout() {
    const cart = JSON.parse(localStorage.getItem('cart') || '[]');
    if (cart.length === 0) {
        alert('Your rental cart is empty');
        return;
    }
    window.location.href = 'checkout.php';
}

// Load cart when page loads
document.addEventListener('DOMContentLoaded', loadCart);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
