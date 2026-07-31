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

if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'mark-notification-read')) {
    http_response_code(403);
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Security check failed.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$notificationId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);
$markAll = filter_input(INPUT_POST, 'mark_all', FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);

ensureOperationalTables($pdo);

if (!$notificationId && !$markAll) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Notification ID or mark_all flag is required.']);
    exit;
}

try {
    if ($markAll) {
        $stmt = $pdo->prepare(
            'UPDATE Notifications
             SET is_read = 1
             WHERE recipient_user_id = :user_id AND is_read = 0'
        );
        $stmt->execute(['user_id' => $userId]);
        $affectedRows = $stmt->rowCount();

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'All notifications marked as read.',
            'affected_count' => $affectedRows,
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT notification_id, recipient_user_id
         FROM Notifications
         WHERE notification_id = :notification_id
         LIMIT 1'
    );
    $stmt->execute(['notification_id' => $notificationId]);
    $notification = $stmt->fetch();

    if (!$notification) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Notification not found.']);
        exit;
    }

    if ((int) $notification['recipient_user_id'] !== $userId) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }

    $updateStmt = $pdo->prepare(
        'UPDATE Notifications
         SET is_read = 1
         WHERE notification_id = :notification_id'
    );
    $updateStmt->execute(['notification_id' => $notificationId]);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Notification marked as read.',
        'notification_id' => (int) $notificationId,
    ]);
    exit;

} catch (PDOException $e) {
    error_log('Mark notification failed: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}
