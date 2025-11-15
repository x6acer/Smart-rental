<?php
require_once __DIR__ . '/../db_connect.php';
$db = db_get_conn();
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: items_details.php');
    exit;
}

if (!isset($_SESSION['customer_id'])) {
    // Redirect to login, preserve return URL
    $_SESSION['after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit;
}

$vehicle_id = isset($_POST['vehicle_id']) ? intval($_POST['vehicle_id']) : 0;
$rental_start = $_POST['rental_start'] ?? null;
$rental_end = $_POST['rental_end'] ?? null;

if (!$vehicle_id || !$rental_start || !$rental_end) {
    die('Missing booking information.');
}

// Validate date format and ordering
$start_ts = strtotime($rental_start);
$end_ts = strtotime($rental_end);
if ($start_ts === false || $end_ts === false) {
    die('Invalid dates provided.');
}
if ($end_ts <= $start_ts) {
    die('End date must be after start date.');
}

// Calculate duration days
$duration_days = max(1, intval(($end_ts - $start_ts) / 86400));

// Prepare to insert order. Be schema-aware like process_payment.php
$colRes = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'");
$orderCols = [];
if ($colRes) {
    while ($r = $colRes->fetch_assoc()) {
        $orderCols[$r['COLUMN_NAME']] = true;
    }
}

$fields = [];
$placeholders = [];
$types = '';
$values = [];

// Prefer customer_id or user_id
if (!empty($orderCols['customer_id'])) {
    $fields[] = 'customer_id'; $placeholders[] = '?'; $types .= 'i'; $values[] = intval($_SESSION['customer_id']);
} elseif (!empty($orderCols['user_id'])) {
    $fields[] = 'user_id'; $placeholders[] = '?'; $types .= 'i'; $values[] = intval($_SESSION['customer_id']);
} else {
    // fall back to customer_email if present
    $cust_email = $_SESSION['customer_email'] ?? null;
    if (!$cust_email) {
        $cstmt = $db->prepare("SELECT email FROM customers WHERE id = ? LIMIT 1");
        if ($cstmt) {
            $cstmt->bind_param('i', $_SESSION['customer_id']);
            $cstmt->execute();
            $cres = $cstmt->get_result()->fetch_assoc();
            if ($cres) $cust_email = $cres['email'];
        }
    }
    if (!empty($orderCols['customer_email']) && $cust_email) {
        $fields[] = 'customer_email'; $placeholders[] = '?'; $types .= 's'; $values[] = $cust_email;
    }
}

// rental_start / rental_end if available
if (!empty($orderCols['rental_start'])) {
    $fields[] = 'rental_start'; $placeholders[] = '?'; $types .= 's'; $values[] = $rental_start;
}
if (!empty($orderCols['rental_end'])) {
    $fields[] = 'rental_end'; $placeholders[] = '?'; $types .= 's'; $values[] = $rental_end;
}

// total_amount if exists: compute from rentals table daily rate if available
$daily_rate = 0.0;
$rr = $db->prepare("SELECT COALESCE(r.daily_rate, r.price_per_day, r.price, r.rate, 0) as rate FROM rentals r WHERE r.id = ? LIMIT 1");
if ($rr) {
    $rr->bind_param('i', $vehicle_id);
    $rr->execute();
    $rres = $rr->get_result()->fetch_assoc();
    if ($rres && is_numeric($rres['rate'])) $daily_rate = floatval($rres['rate']);
}
$total_amount = $daily_rate * $duration_days;
if (!empty($orderCols['total_amount'])) {
    $fields[] = 'total_amount'; $placeholders[] = '?'; $types .= 'd'; $values[] = $total_amount;
}

if (!empty($orderCols['status'])) {
    $fields[] = 'status'; $placeholders[] = '?'; $types .= 's'; $values[] = 'pending';
}

if (empty($fields)) {
    die('Orders table does not have writable columns to create an order.');
}

$sql = "INSERT INTO orders (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
$stmt = $db->prepare($sql);
if (!$stmt) die('Prepare failed: ' . $db->error);

// bind params
$bind_names = [];
$bind_names[] = $types;
for ($i=0;$i<count($values);$i++) {
    $bind_names[] = &$values[$i];
}
call_user_func_array([$stmt, 'bind_param'], $bind_names);
$stmt->execute();
if ($stmt->errno) die('Insert order failed: ' . $stmt->error);
$order_id = $db->insert_id;

// Insert order_items if table exists
$oi_stmt = $db->prepare("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'");
$oi_stmt->execute();
$oi_cnt = $oi_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
if ($oi_cnt) {
    // Best-effort insert similar to process_payment
    $ins = $db->prepare("INSERT INTO order_items (order_id, rental_id, days, daily_rate, total_amount) VALUES (?, ?, ?, ?, ?)");
    if ($ins) {
        $ins->bind_param('iiidd', $order_id, $vehicle_id, $duration_days, $daily_rate, $total_amount);
        $ins->execute();
    }
}

// Optionally update rental status
$db->query("UPDATE rentals SET status = 'maintenance' WHERE id = " . intval($vehicle_id));

// Redirect to order summary or orders page
header('Location: order_summary.php?id=' . intval($order_id));
exit;
