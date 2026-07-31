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
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'gps-tracking')) {
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
        'SELECT b.booking_id, b.customer_id, b.vehicle_id, b.booking_status
         FROM Bookings b
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

    if ($booking['booking_status'] !== 'Active' && $booking['booking_status'] !== 'Confirmed') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Booking is not active.']);
        exit;
    }

    $vehicleId = (int) $booking['vehicle_id'];

    $telemetryStmt = $pdo->prepare(
        'SELECT telemetry_id, vehicle_id, current_latitude, current_longitude, route_history, geofence_violation
         FROM GPS_Telemetry
         WHERE vehicle_id = :vehicle_id
         ORDER BY telemetry_id DESC
         LIMIT 1'
    );
    $telemetryStmt->execute(['vehicle_id' => $vehicleId]);
    $telemetry = $telemetryStmt->fetch(PDO::FETCH_ASSOC);

    if (!$telemetry) {
        ob_end_clean();
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'has_tracking' => false,
                'latitude' => null,
                'longitude' => null,
                'speed' => null,
                'route_history' => [],
                'geofence_violation' => false,
                'message' => 'No GPS data available yet.',
            ],
        ]);
        exit;
    }

    $routeHistory = [];
    if (!empty($telemetry['route_history'])) {
        $decoded = json_decode($telemetry['route_history'], true);
        if (is_array($decoded)) {
            $routeHistory = array_slice($decoded, -50);
        }
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'data' => [
            'has_tracking' => true,
            'latitude' => isset($telemetry['current_latitude']) ? (float) $telemetry['current_latitude'] : null,
            'longitude' => isset($telemetry['current_longitude']) ? (float) $telemetry['current_longitude'] : null,
            'speed' => 0.0,
            'route_history' => array_map(function ($point) {
                return [
                    'latitude' => (float) $point['latitude'] ?? 0,
                    'longitude' => (float) $point['longitude'] ?? 0,
                    'timestamp' => (string) $point['timestamp'] ?? '',
                ];
            }, $routeHistory),
            'geofence_violation' => (bool) $telemetry['geofence_violation'],
            'message' => 'GPS tracking data retrieved successfully.',
        ],
    ]);
    exit;

} catch (PDOException $e) {
    error_log('GPS tracking fetch failed: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}
