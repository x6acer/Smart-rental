<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../../db.php';
    require_once __DIR__ . '/../../includes/security.php';
    require_once __DIR__ . '/../../includes/operational-logic.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred.'], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
    http_response_code(403);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Please sign in to continue.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$bookingId = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT);
$bookingId = $bookingId ? (int) $bookingId : 0;
if ($bookingId <= 0) {
    http_response_code(400);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int) $_SESSION['user_id'];
try {
    $ownershipStmt = $pdo->prepare('SELECT booking_id FROM Bookings WHERE booking_id = :booking_id AND customer_id = :customer_id LIMIT 1');
    $ownershipStmt->execute(['booking_id' => $bookingId, 'customer_id' => $userId]);
    if ($ownershipStmt->fetch() === false) {
        http_response_code(403);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'You do not have access to this conversation.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $bookingStmt = $pdo->prepare('SELECT vehicle_id, booking_status FROM Bookings WHERE booking_id = :booking_id LIMIT 1');
    $bookingStmt->execute(['booking_id' => $bookingId]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
    $vehicleId = isset($booking['vehicle_id']) ? (int) $booking['vehicle_id'] : 0;

    $ownerStmt = $pdo->prepare('SELECT owner_id FROM Vehicles WHERE vehicle_id = :vehicle_id LIMIT 1');
    $ownerStmt->execute(['vehicle_id' => $vehicleId]);
    $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
    $ownerId = isset($owner['owner_id']) ? (int) $owner['owner_id'] : 0;

    $messages = [];
    if ($ownerId > 0) {
        $messages = getConversationMessages($pdo, $bookingId, $userId, $ownerId, 'Owner');
    }

    markConversationMessagesRead($pdo, $bookingId, $userId, $ownerId);

    ob_end_clean();
    echo json_encode(['success' => true, 'messages' => $messages], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Customer message fetch failed: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load messages.'], JSON_UNESCAPED_SLASHES);
}
