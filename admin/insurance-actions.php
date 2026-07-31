<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/audit.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
    exit;
}

$csrfContext = 'admin-insurance';
if (!verifyCsrfToken((string) ($payload['csrf_token'] ?? ''), $csrfContext)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security check failed.']);
    exit;
}

$claimId = (int) ($payload['claim_id'] ?? 0);
$action = trim((string) ($payload['action'] ?? ''));
$note = trim((string) ($payload['claim_note'] ?? ''));
$payoutAmount = (float) ($payload['payout_amount'] ?? 0);

$normalizedStatus = match ($action) {
    'approve' => 'Approved',
    'reject' => 'Rejected',
    default => 'Under_Review',
};

if ($claimId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The claim workflow request is invalid.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $selectStmt = $pdo->prepare('SELECT claim_id, claim_status FROM Claims WHERE claim_id = :claim_id LIMIT 1');
    $selectStmt->execute(['claim_id' => $claimId]);
    $claim = $selectStmt->fetch(PDO::FETCH_ASSOC);

    if (!$claim) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Claim not found.']);
        exit;
    }

    $updateStmt = $pdo->prepare(
        'UPDATE Claims SET claim_status = :claim_status, resolved_at = :resolved_at WHERE claim_id = :claim_id'
    );
    $updateStmt->execute([
        'claim_status' => $normalizedStatus,
        'resolved_at' => in_array($normalizedStatus, ['Approved', 'Rejected'], true) ? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') : null,
        'claim_id' => $claimId,
    ]);

    $logStmt = $pdo->prepare(
        'INSERT INTO Claim_Payout_Logs (claim_id, admin_id, action, payout_amount, note)
         VALUES (:claim_id, :admin_id, :action, :payout_amount, :note)'
    );
    $logStmt->execute([
        'claim_id' => $claimId,
        'admin_id' => (int) ($_SESSION['admin_id'] ?? 0),
        'action' => $normalizedStatus,
        'payout_amount' => $payoutAmount,
        'note' => $note !== '' ? $note : 'Claim adjudicated asynchronously by admin.',
    ]);

    $pdo->commit();
    logAdminActivity('claim_status_updated', 'Claim ' . $claimId . ' was updated to ' . $normalizedStatus . ' via async workflow.', (int) ($_SESSION['admin_id'] ?? 0), 'Claims', $claimId);

    echo json_encode([
        'success' => true,
        'message' => 'Claim marked as ' . $normalizedStatus . '.',
        'status' => $normalizedStatus,
    ]);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Failed to process async claim update: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The claim update could not be completed.']);
}
