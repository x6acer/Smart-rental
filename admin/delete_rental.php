<?php
session_start();
require_once 'includes/auth.php';
require_once '../db_connect.php';

// Set JSON response header
header('Content-Type: application/json');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

// === AUTHENTICATION & AUTHORIZATION ===
// Ensure admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized - Admin access required']));
}

// === READ AND VALIDATE JSON INPUT ===
$raw_input = file_get_contents('php://input');
error_log("Delete rental raw input: " . $raw_input);

$data = json_decode($raw_input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("JSON decode error: " . json_last_error_msg());
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Invalid JSON payload: ' . json_last_error_msg()]));
}

$rental_id = $data['id'] ?? null;

if (!is_numeric($rental_id) || ($rental_id = (int)$rental_id) <= 0) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Valid rental ID is required']));
}

try {
    // For admin area, we don't check ownership since admins can delete any rental
    // Just verify the rental exists
    $rentalCheck = $conn->prepare("SELECT 1 FROM rentals WHERE id = ?");
    $rentalCheck->execute([$rental_id]);
    if (!$rentalCheck->fetch()) {
        http_response_code(404);
        exit(json_encode(['success' => false, 'message' => 'Rental not found']));
    }

    // === DETECT SOFT-DELETE COLUMNS ===
    $colStmt = $conn->prepare("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'rentals' 
          AND COLUMN_NAME IN ('status', 'is_deleted')
    ");
    $colStmt->execute();
    $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);

    $hasStatus = in_array('status', $columns);
    $hasIsDeleted = in_array('is_deleted', $columns);

    $success = false;
    $alreadyDeleted = false;

    if ($hasIsDeleted) {
        // Soft delete via is_deleted
        $stmt = $conn->prepare("
            UPDATE rentals 
            SET is_deleted = 1 
            WHERE id = ? AND (is_deleted = 0 OR is_deleted IS NULL)
        ");
        $stmt->execute([$rental_id]);
        $affected = $stmt->rowCount();
        $success = true;

        if ($affected === 0) {
            // Check if it exists but already deleted
            $check = $conn->prepare("SELECT 1 FROM rentals WHERE id = ? AND is_deleted = 1");
            $check->execute([$rental_id]);
            $alreadyDeleted = $check->fetch() !== false;
        }
    } 
    elseif ($hasStatus) {
        // Soft delete via status
        $stmt = $conn->prepare("
            UPDATE rentals 
            SET status = 'deleted' 
            WHERE id = ? AND (status != 'deleted' OR status IS NULL)
        ");
        $stmt->execute([$rental_id]);
        $affected = $stmt->rowCount();
        $success = true;

        if ($affected === 0) {
            $check = $conn->prepare("SELECT 1 FROM rentals WHERE id = ? AND status = 'deleted'");
            $check->execute([$rental_id]);
            $alreadyDeleted = $check->fetch() !== false;
        }
    } 
    else {
        // === NO SOFT DELETE AVAILABLE → FAIL SAFE ===
        http_response_code(500);
        exit(json_encode([
            'success' => false,
            'message' => 'Delete failed: No soft-delete mechanism (status or is_deleted) found in table'
        ]));
    }

    // === FINAL RESPONSE ===
    if ($success) {
        if ($affected > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Rental deleted successfully'
            ]);
        } elseif ($alreadyDeleted) {
            echo json_encode([
                'success' => true,
                'message' => 'Rental already deleted'
            ]);
        } else {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Rental not found'
            ]);
        }
    }

} catch (PDOException $e) {
    // Log error in production with detailed information
    error_log("Delete rental error (ID: $rental_id): " . $e->getMessage());
    error_log("SQL State: " . $e->getCode());
    error_log("Stack trace: " . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred: ' . $e->getMessage()
    ]);
}
?>