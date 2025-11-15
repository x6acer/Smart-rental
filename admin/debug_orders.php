<?php
require_once 'includes/auth.php';
require_once '../db_connect.php';

// Get current customer orders for admin debugging
$db = db_get_conn();
echo "<pre>";
echo "=== Customer Order Debug ===\n\n";

// Check orders table columns
$colRes = $db->query("SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'");
echo "Orders table columns:\n";
while ($col = $colRes->fetch_assoc()) {
    echo "- {$col['COLUMN_NAME']} ({$col['DATA_TYPE']})\n";
}
echo "\n";

// Get a sample of recent orders
$sql = "SELECT o.*, oi.rental_id, oi.days, oi.daily_rate, oi.total_amount as item_total, r.name as rental_name, r.title as rental_title
        FROM orders o 
        LEFT JOIN order_items oi ON o.id = oi.order_id
        LEFT JOIN rentals r ON oi.rental_id = r.id
        ORDER BY o.id DESC LIMIT 10";
$result = $db->query($sql);

echo "Last 10 orders:\n";
while ($row = $result->fetch_assoc()) {
    echo "\nOrder #{$row['id']}:\n";
    echo "- user_id: " . ($row['user_id'] ?? 'NULL') . "\n";
    echo "- customer_id: " . ($row['customer_id'] ?? 'NULL') . "\n";
    echo "- customer_email: " . ($row['customer_email'] ?? 'NULL') . "\n";
    echo "- created_at: " . ($row['created_at'] ?? 'NULL') . "\n";
    echo "- status: " . ($row['status'] ?? 'NULL') . "\n";
    echo "- total_amount: " . ($row['total_amount'] ?? 'NULL') . "\n";
    if ($row['rental_id']) {
        echo "  Vehicle: " . ($row['rental_title'] ?? $row['rental_name'] ?? 'Unknown') . "\n";
        echo "  Days: " . $row['days'] . " @ " . $row['daily_rate'] . " = " . $row['item_total'] . "\n";
    }
}

// Get debug logs if they exist
if (file_exists(__DIR__ . '/../customer/order_debug.log')) {
    echo "\nRecent debug log:\n";
    echo file_get_contents(__DIR__ . '/../customer/order_debug.log');
}
?>