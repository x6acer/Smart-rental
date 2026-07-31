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
    require_once __DIR__ . '/../../includes/notification-dispatch.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred.'], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'customer-messages')) {
    http_response_code(403);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Security check failed.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
$bookingId = $bookingId ? (int) $bookingId : 0;
$messageText = trim((string) ($_POST['message_text'] ?? ''));

if ($bookingId <= 0 || $messageText === '' || mb_strlen($messageText) > 5000) {
    http_response_code(400);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Message must be between 1 and 5000 characters.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int) $_SESSION['user_id'];
try {
    $ownershipStmt = $pdo->prepare('SELECT b.booking_id, b.vehicle_id FROM Bookings b WHERE b.booking_id = :booking_id AND b.customer_id = :customer_id LIMIT 1');
    $ownershipStmt->execute(['booking_id' => $bookingId, 'customer_id' => $userId]);
    $booking = $ownershipStmt->fetch(PDO::FETCH_ASSOC);
    if ($booking === false) {
        http_response_code(403);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'You do not have access to this conversation.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $vehicleId = isset($booking['vehicle_id']) ? (int) $booking['vehicle_id'] : 0;
    $ownerStmt = $pdo->prepare('SELECT owner_id FROM Vehicles WHERE vehicle_id = :vehicle_id LIMIT 1');
    $ownerStmt->execute(['vehicle_id' => $vehicleId]);
    $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
    $ownerId = isset($owner['owner_id']) ? (int) $owner['owner_id'] : 0;
    if ($ownerId <= 0) {
        http_response_code(404);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'No owner was found for this booking.'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $messageId = insertConversationMessage($pdo, $bookingId, $userId, 'Customer', $ownerId, 'Owner', $messageText);

    $service = new NotificationDispatchService($pdo, []);
    $service->dispatch([
        'recipient_user_id' => $ownerId,
        'title' => 'New customer message',
        'message' => 'A customer sent a new message about booking #' . $bookingId . '.',
        'notification_type' => 'BookingMessage',
        'channels' => ['in_app'],
        'related_entity_type' => 'Bookings',
        'related_entity_id' => $bookingId,
    ]);

    ob_end_clean();
    echo json_encode(['success' => true, 'message_id' => $messageId], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Customer message send failed: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to send message.'], JSON_UNESCAPED_SLASHES);
}
