<?php
require_once __DIR__ . '/../db_connect.php';
$db = db_get_conn();

// Redirect if not logged in
session_start();
if (!isset($_SESSION['customer_id'])) {
    $_SESSION['redirect_after_login'] = '/smart_rental/customer/checkout.php';
    header('Location: login.php');
    exit;
}

$page_title = "Checkout - Smart Rental";
include __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold mb-6">Checkout</h1>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <!-- Rental Summary -->
        <div class="bg-white rounded-xl p-6 shadow-md">
            <h2 class="text-xl font-bold mb-4">Rental Summary</h2>
            <div id="order-items" class="space-y-4 mb-4">
                <!-- Selected vehicles will be loaded here -->
            </div>
            
            <div class="border-t pt-4">
                <div class="flex justify-between font-bold">
                    <span>Total</span>
                    <span id="order-total">$0.00</span>
                </div>
            </div>
        </div>
        
        <!-- Checkout Form -->
        <div class="bg-white rounded-xl p-6 shadow-md">
            <h2 class="text-xl font-bold mb-4">Payment Details</h2>
            
            <form id="checkout-form" action="process_payment.php" method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Card Number</label>
                    <input type="text" name="card_number" required
                           class="w-full border rounded px-3 py-2"
                           placeholder="4111 1111 1111 1111">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Expiry Date</label>
                        <input type="text" name="expiry" required
                               class="w-full border rounded px-3 py-2"
                               placeholder="MM/YY">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">CVV</label>
                        <input type="text" name="cvv" required
                               class="w-full border rounded px-3 py-2"
                               placeholder="123">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1">Name on Card</label>
                    <input type="text" name="card_name" required
                           class="w-full border rounded px-3 py-2">
                </div>
                
                <input type="hidden" name="order_data" id="order-data">
                
                <button type="submit" 
                        class="w-full bg-[#1b4b4b] text-white py-3 rounded-lg font-bold hover:bg-[#228383] transition">
                    Complete Booking
                </button>
            </form>
        </div>
    </div>
</div>

<template id="order-item-template">
    <div class="flex justify-between items-center">
        <div>
            <h3 class="font-medium"></h3>
            <p class="text-sm text-gray-600"><span class="days"></span> days @ $<span class="price"></span>/day</p>
        </div>
        <span class="font-bold">$<span class="total"></span></span>
    </div>
</template>

<script>
async function loadOrderSummary() {
    const cart = JSON.parse(localStorage.getItem('cart') || '[]');
    const orderItems = document.getElementById('order-items');
    const template = document.getElementById('order-item-template');
    let grandTotal = 0;
    const orderData = [];
    
    orderItems.innerHTML = '';
    
    for (const item of cart) {
        const response = await fetch(`/smart_rental/api/rental.php?id=${item.id}`);
        const rental = await response.json();
        
        const clone = template.content.cloneNode(true);
        
        clone.querySelector('h3').textContent = rental.title;
        clone.querySelector('.days').textContent = item.days;
        clone.querySelector('.price').textContent = rental.price_per_day;
        
        const total = (rental.price_per_day * item.days).toFixed(2);
        clone.querySelector('.total').textContent = total;
        grandTotal += parseFloat(total);
        
        orderItems.appendChild(clone);
        
        orderData.push({
            rental_id: rental.id,
            days: item.days,
            price_per_day: rental.price_per_day,
            total: parseFloat(total)
        });
    }
    
    document.getElementById('order-total').textContent = `$${grandTotal.toFixed(2)}`;
    document.getElementById('order-data').value = JSON.stringify({
        items: orderData,
        total: grandTotal
    });
}

document.getElementById('checkout-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // In a real app, validate card details here
    
    this.submit();
});

// Load order summary when page loads
document.addEventListener('DOMContentLoaded', loadOrderSummary);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
