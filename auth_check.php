<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['customer_id'])) {
    // Store the current URL to redirect back after login
    $_SESSION['after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: /smart_rental/customer/login.php');
    exit;
}

// Optionally fetch customer details if needed
function get_customer_details($db) {
    if (!isset($_SESSION['customer_id'])) return null;
    
    $stmt = $db->prepare("SELECT id, name, email FROM customers WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['customer_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}
?>
