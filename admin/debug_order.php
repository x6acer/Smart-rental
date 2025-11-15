<?php
require_once 'includes/auth.php';
require_once '../db_connect.php';

$order_id = isset($_GET['id']) ? intval($_GET['id']) : null;
if (!$order_id) {
    echo "Provide an order id via ?id=123\n";
    exit;
}

try {
    // List orders table columns
    $colStmt = $conn->prepare(
        "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' ORDER BY ORDINAL_POSITION"
    );
    $colStmt->execute();
    $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch the order row
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch order items
    $itemsStmt = $conn->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $itemsStmt->execute([$order_id]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // If user/customer ids exist in order row, fetch them
    $linked = [];
    if (!empty($order['user_id'])) {
        $us = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $us->execute([$order['user_id']]);
        $linked['user'] = $us->fetch(PDO::FETCH_ASSOC);
    }
    if (!empty($order['customer_id'])) {
        $cs = $conn->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
        $cs->execute([$order['customer_id']]);
        $linked['customer'] = $cs->fetch(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
    exit;
}

?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Debug Order <?php echo htmlspecialchars($order_id); ?></title>
    <style>body{font-family:monospace;padding:20px} pre{background:#f7f7f7;padding:12px;border-radius:6px;overflow:auto}</style>
</head>
<body>
    <h1>Debug Order <?php echo htmlspecialchars($order_id); ?></h1>

    <h2>Orders table columns</h2>
    <pre><?php echo htmlspecialchars(print_r($cols, true)); ?></pre>

    <h2>Order row</h2>
    <pre><?php echo htmlspecialchars(print_r($order, true)); ?></pre>

    <h2>Detected date-like columns (from orders table)</h2>
    <?php
    // Find columns that look like dates/timestamps and print their values for this order
    $date_like = [];
    foreach ($cols as $c) {
        $cn = strtolower($c['COLUMN_NAME']);
        if (preg_match('/created|date|start|end|time|timestamp/', $cn)) {
            $date_like[] = $c['COLUMN_NAME'];
        }
    }
    if (!empty($date_like)) {
        echo '<pre>';
        foreach ($date_like as $dcol) {
            $val = array_key_exists($dcol, $order) ? $order[$dcol] : null;
            $show = $val === null ? '<NULL>' : $val;
            echo htmlspecialchars($dcol . ' => ' . $show) . "\n";
        }
        echo '</pre>';
    } else {
        echo '<p>No date-like columns detected in orders table.</p>';
    }
    ?>

    <h2>Order vehicles</h2>
    <pre><?php echo htmlspecialchars(print_r($items, true)); ?></pre>

    <h2>Linked rows (users/customers)</h2>
    <pre><?php echo htmlspecialchars(print_r($linked, true)); ?></pre>

    <p>Copy the above output and paste here so I can inspect whether <strong>created_at</strong>, <strong>start_date</strong> or <strong>end_date</strong> contain values.</p>
</body>
</html>
