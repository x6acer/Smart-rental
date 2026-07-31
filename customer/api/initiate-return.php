<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    require_once __DIR__ . '/../../db.php';
    require_once __DIR__ . '/../../includes/security.php';
    require_once __DIR__ . '/../../includes/notification-dispatch.php';
    require_once __DIR__ . '/../../includes/operational-logic.php';
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['user_id']) || !is_int((int) $_SESSION['user_id'])) {
    http_response_code(401);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'initiate-return')) {
    http_response_code(403);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Security check failed.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
$returnLocation = trim((string) ($_POST['return_location'] ?? ''));
$returnNotes = trim((string) ($_POST['return_notes'] ?? ''));

if (!$bookingId) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Booking ID is required.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT b.booking_id, b.customer_id, b.vehicle_id, b.booking_status, b.end_date, v.owner_id, v.make, v.model, t.total_price
         FROM Bookings b
         JOIN Vehicles v ON b.vehicle_id = v.vehicle_id
         LEFT JOIN Transactions t ON t.booking_id = b.booking_id
         WHERE b.booking_id = :booking_id AND b.customer_id = :customer_id
         LIMIT 1'
    );
    $stmt->execute([
        'booking_id' => $bookingId,
        'customer_id' => $userId
    ]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $pdo->rollBack();
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit;
    }

    if ($booking['booking_status'] !== 'Active' && $booking['booking_status'] !== 'Picked Up') {
        $pdo->rollBack();
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Only active rentals can be returned.']);
        exit;
    }

    $endDate = new DateTime($booking['end_date']);
    $now = new DateTime('now');
    $returnStatus = $now > $endDate ? 'Overdue' : 'OnTime';

    if ($returnStatus === 'Overdue') {
        $interval = $now->diff($endDate);
        $overtimeHours = $interval->h + ($interval->d * 24);
        $overtimeCharge = $overtimeHours * 15.00;
    } else {
        $overtimeCharge = 0;
    }

    $updateStmt = $pdo->prepare(
        'UPDATE Bookings
         SET booking_status = "Pending_Return", return_initiated_at = NOW()
         WHERE booking_id = :booking_id'
    );
    $updateStmt->execute(['booking_id' => $bookingId]);

    $insertNoteStmt = $pdo->prepare(
        'INSERT INTO Booking_Return_Notes (booking_id, customer_id, return_location, return_notes, overtime_charge, return_status, created_at)
         VALUES (:booking_id, :customer_id, :return_location, :return_notes, :overtime_charge, :return_status, NOW())'
    );
    $insertNoteStmt->execute([
        'booking_id' => $bookingId,
        'customer_id' => $userId,
        'return_location' => $returnLocation ?: null,
        'return_notes' => $returnNotes ?: null,
        'overtime_charge' => $overtimeCharge,
        'return_status' => $returnStatus,
    ]);

    $ownerEmail = null;
    $ownerStmt = $pdo->prepare('SELECT email FROM Users WHERE user_id = :user_id LIMIT 1');
    $ownerStmt->execute(['user_id' => (int) $booking['owner_id']]);
    $ownerData = $ownerStmt->fetch();
    if ($ownerData) {
        $ownerEmail = $ownerData['email'];
    }

    $notificationService = new NotificationDispatchService($pdo, []);
    $vehicleDisplay = trim($booking['make'] . ' ' . $booking['model']);

    $notificationService->dispatch([
        'channels' => ['in_app', 'email'],
        'recipient_user_id' => (int) $booking['owner_id'],
        'recipient_role' => 'Owner',
        'title' => 'Vehicle Return Ready for Inspection',
        'message' => 'Booking #' . $bookingId . ' - Customer has initiated return for ' . $vehicleDisplay . '. Return Status: ' . $returnStatus . '. Overtime Charge: GHS ' . number_format($overtimeCharge, 2) . '. Location: ' . ($returnLocation ?: 'Not specified'),
        'notification_type' => 'Booking',
        'related_entity_type' => 'Booking',
        'related_entity_id' => $bookingId,
        'recipient_email' => $ownerEmail,
        'subject' => 'Vehicle Return Ready - Booking #' . $bookingId,
        'body' => '<p>Hello,</p><p>A customer has initiated return for their ' . htmlspecialchars($vehicleDisplay) . ' rental (Booking #' . htmlspecialchars((string) $bookingId) . ').</p><p><strong>Return Status:</strong> ' . htmlspecialchars($returnStatus) . '</p><p><strong>Overtime Charge:</strong> GHS ' . htmlspecialchars(number_format($overtimeCharge, 2)) . '</p><p><strong>Return Location:</strong> ' . htmlspecialchars($returnLocation ?: 'Not specified') . '</p><p>Please log in to your dashboard to complete the vehicle inspection and finalize the return.</p>',
    ]);

    $notificationService->dispatch([
        'channels' => ['in_app'],
        'recipient_user_id' => $userId,
        'recipient_role' => 'Customer',
        'title' => 'Return Initiated',
        'message' => 'Your return for ' . $vehicleDisplay . ' has been initiated. Status: ' . $returnStatus . '. Awaiting owner inspection.',
        'notification_type' => 'Booking',
        'related_entity_type' => 'Booking',
        'related_entity_id' => $bookingId,
    ]);

    $pdo->commit();

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Return initiated successfully. Owner has been notified.',
        'data' => [
            'booking_id' => (int) $bookingId,
            'status' => 'Pending_Return',
            'return_status' => $returnStatus,
            'overtime_charge' => (float) $overtimeCharge,
            'return_location' => $returnLocation ?: null,
        ],
    ]);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Return initiation failed: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to initiate return.']);
    exit;
}
