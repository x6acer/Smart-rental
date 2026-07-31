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

ensureOperationalTables($pdo);

$limit = 50;
$offset = 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = (int) filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT, ['options' => ['min' => 1, 'max' => 100, 'default' => 50]]);
    $offset = (int) filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT, ['options' => ['min' => 0, 'default' => 0]]);
    $unreadOnly = (bool) filter_input(INPUT_GET, 'unread_only', FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
} else {
    $limit = (int) filter_input(INPUT_POST, 'limit', FILTER_VALIDATE_INT, ['options' => ['min' => 1, 'max' => 100, 'default' => 50]]);
    $offset = (int) filter_input(INPUT_POST, 'offset', FILTER_VALIDATE_INT, ['options' => ['min' => 0, 'default' => 0]]);
    $unreadOnly = (bool) filter_input(INPUT_POST, 'unread_only', FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
}

try {
    $query = 'SELECT notification_id, title, message, notification_type, is_read, created_at, related_entity_type, related_entity_id
             FROM Notifications
             WHERE recipient_user_id = :user_id';
    $params = ['user_id' => $userId];

    if ($unreadOnly) {
        $query .= ' AND is_read = 0';
    }

    $countStmt = $pdo->prepare(str_replace('SELECT notification_id, title, message, notification_type, is_read, created_at, related_entity_type, related_entity_id', 'SELECT COUNT(*) AS cnt', $query));
    $countStmt->execute($params);
    $countResult = $countStmt->fetch();
    $totalCount = (int) $countResult['cnt'];

    $query .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';
    $params['limit'] = $limit;
    $params['offset'] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedNotifications = array_map(function ($notif) {
        $createdAt = new DateTime($notif['created_at']);
        $now = new DateTime('now');
        $interval = $now->diff($createdAt);

        if ($interval->d > 0) {
            $timeDisplay = $interval->d . 'd ago';
        } elseif ($interval->h > 0) {
            $timeDisplay = $interval->h . 'h ago';
        } else {
            $timeDisplay = $interval->i . 'm ago';
        }

        return [
            'notification_id' => (int) $notif['notification_id'],
            'title' => (string) $notif['title'],
            'message' => (string) $notif['message'],
            'type' => (string) $notif['notification_type'],
            'is_read' => (bool) $notif['is_read'],
            'created_at' => $createdAt->format('c'),
            'time_display' => $timeDisplay,
            'related_entity_type' => $notif['related_entity_type'] ? (string) $notif['related_entity_type'] : null,
            'related_entity_id' => $notif['related_entity_id'] ? (int) $notif['related_entity_id'] : null,
        ];
    }, $notifications);

    $unreadCount = 0;
    if (!$unreadOnly) {
        $unreadStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM Notifications WHERE recipient_user_id = :user_id AND is_read = 0');
        $unreadStmt->execute(['user_id' => $userId]);
        $unreadResult = $unreadStmt->fetch();
        $unreadCount = (int) $unreadResult['cnt'];
    }

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'data' => [
            'notifications' => $formattedNotifications,
            'pagination' => [
                'total' => $totalCount,
                'count' => count($formattedNotifications),
                'limit' => $limit,
                'offset' => $offset,
            ],
            'unread_count' => $unreadCount,
        ],
    ]);
    exit;

} catch (PDOException $e) {
    error_log('Notification fetch failed: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
    exit;
}
