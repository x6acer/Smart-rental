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
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'booking-status')) {
        http_response_code(403);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Security check failed.']);
        exit;
    }
}

$bookingId = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT) ?? filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);

if (!$bookingId) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Booking ID is required.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT b.booking_id, b.customer_id, b.vehicle_id, b.booking_status, b.start_date, b.end_date, b.chauffeur_toggle,
                v.make, v.model, v.year, v.vin,
                u.email AS owner_email,
                p.full_name AS owner_name,
                t.transaction_id, t.total_price, t.payment_status,
                bi.inspection_id, bi.mileage AS pre_rental_mileage
         FROM Bookings b
         JOIN Vehicles v ON b.vehicle_id = v.vehicle_id
         JOIN Users u ON v.owner_id = u.user_id
         LEFT JOIN User_Profiles p ON p.user_id = v.owner_id
         LEFT JOIN Transactions t ON t.booking_id = b.booking_id
         LEFT JOIN Booking_Inspections bi ON bi.booking_id = b.booking_id AND bi.inspection_type = "pre_rental"
         WHERE b.booking_id = :booking_id AND b.customer_id = :customer_id
         LIMIT 1'
    );
    $stmt->execute([
        'booking_id' => $bookingId,
        'customer_id' => $userId
    ]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found.']);
        exit;
    }

    $startDate = new DateTime($booking['start_date']);
    $endDate = new DateTime($booking['end_date']);
    $now = new DateTime('now');

    $isActive = $booking['booking_status'] === 'Active' || ($now >= $startDate && $now <= $endDate);
    $isOverdue = $now > $endDate && $booking['booking_status'] !== 'Completed' && $booking['booking_status'] !== 'Cancelled';

    $vehicleDisplay = trim($booking['make'] . ' ' . $booking['model']);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'data' => [
            'booking_id' => (int) $booking['booking_id'],
            'status' => (string) $booking['booking_status'],
            'vehicle' => [
                'display' => $vehicleDisplay,
                'make' => (string) $booking['make'],
                'model' => (string) $booking['model'],
                'year' => (int) $booking['year'],
                'vin' => (string) $booking['vin'],
            ],
            'owner' => [
                'name' => (string) $booking['owner_name'],
                'email' => (string) $booking['owner_email'],
            ],
            'dates' => [
                'start' => $startDate->format('c'),
                'end' => $endDate->format('c'),
                'start_display' => $startDate->format('M d, Y, h:i A'),
                'end_display' => $endDate->format('M d, Y, h:i A'),
            ],
            'pricing' => [
                'total' => isset($booking['total_price']) ? (float) $booking['total_price'] : null,
                'payment_status' => (string) ($booking['payment_status'] ?? 'Pending'),
                'transaction_id' => isset($booking['transaction_id']) ? (int) $booking['transaction_id'] : null,
            ],
            'flags' => [
                'is_active' => (bool) $isActive,
                'is_overdue' => (bool) $isOverdue,
                'has_chauffeur' => (bool) $booking['chauffeur_toggle'],
                'pre_rental_mileage' => isset($booking['pre_rental_mileage']) ? (int) $booking['pre_rental_mileage'] : null,
            ],
        ],
    ]);
    exit;

} catch (PDOException $e) {
    error_log('Booking status fetch failed: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}
