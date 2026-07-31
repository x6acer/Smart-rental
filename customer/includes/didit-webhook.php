<?php
/**
 * Didit webhook receiver for identity results.
 */

declare(strict_types=1);

$config = require __DIR__ . '/../../config/app.php';
$diditConfig = $config['didit'];
$diditWebhookSecret = $diditConfig['secret_key'];

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Empty request body.']);
    exit;
}

$signature = (string) ($_SERVER['HTTP_X_SIGNATURE_V2'] ?? '');
$timestamp = (string) ($_SERVER['HTTP_X_TIMESTAMP'] ?? '');

if ($signature === '' || $timestamp === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing Didit signature headers.']);
    exit;
}

$expectedSignature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $diditWebhookSecret);
if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid webhook signature.']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload.']);
    exit;
}

$statusText = is_string($payload['status'] ?? null) ? trim($payload['status']) : '';
$verificationData = [];
if (is_array($payload['id_verification'] ?? null)) {
    $verificationData = $payload['id_verification'];
} elseif (is_array($payload['extracted_data'] ?? null)) {
    $verificationData = $payload['extracted_data'];
}

if ($statusText === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing status in payload.']);
    exit;
}

if (strtoupper($statusText) !== 'APPROVED') {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Didit webhook processed without profile updates.',
        'status' => $statusText,
    ]);
    exit;
}

$documentType = (string) ($verificationData['document_type'] ?? '');
$docNumber = (string) ($verificationData['document_number'] ?? '');
$firstName = (string) ($verificationData['first_name'] ?? '');
$lastName = (string) ($verificationData['last_name'] ?? '');
$fullName = trim($firstName . ' ' . $lastName);
$userId = isset($payload['vendor_data']) ? (int) $payload['vendor_data'] : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing user reference.']);
    exit;
}

if ($documentType === 'Driving Licence') {
    $drivingLicenseVerified = 1;
} else {
    $drivingLicenseVerified = 0;
}

$accountStatus = 'Active';
$licenseNumber = $docNumber;

require_once '../../db.php';

try {
    $usersStmt = $pdo->prepare('UPDATE Users SET account_status = :account_status WHERE user_id = :user_id');
    $usersStmt->execute([
        ':account_status' => $accountStatus,
        ':user_id' => $userId,
    ]);

    $profileRowStmt = $pdo->prepare('SELECT business_settings FROM User_Profiles WHERE user_id = :user_id LIMIT 1');
    $profileRowStmt->execute([':user_id' => $userId]);
    $profileRow = $profileRowStmt->fetch();

    $profileSettings = [];
    if (is_string($profileRow['business_settings'] ?? null) && $profileRow['business_settings'] !== '') {
        $decodedSettings = json_decode($profileRow['business_settings'], true);
        if (is_array($decodedSettings)) {
            $profileSettings = $decodedSettings;
        }
    }

    $profileSettings['kyc_document_type'] = $documentType;
    $profileSettings['license_number'] = $licenseNumber;
    $profileSettings['driving_license_verified'] = $drivingLicenseVerified;
    $profileSettings['didit_verification_status'] = $statusText;
    $profileSettings['didit_verified_at'] = date('c');

    $existingProfileStmt = $pdo->prepare('SELECT profile_id FROM User_Profiles WHERE user_id = :user_id LIMIT 1');
    $existingProfileStmt->execute([':user_id' => $userId]);
    $existingProfile = $existingProfileStmt->fetch();

    if ($existingProfile) {
        $profileStmt = $pdo->prepare(
            'UPDATE User_Profiles SET full_name = :full_name, business_settings = :business_settings WHERE user_id = :user_id'
        );
        $profileStmt->execute([
            ':full_name' => $fullName,
            ':business_settings' => json_encode($profileSettings),
            ':user_id' => $userId,
        ]);
    } else {
        $profileStmt = $pdo->prepare(
            'INSERT INTO User_Profiles (user_id, full_name, business_settings) VALUES (:user_id, :full_name, :business_settings)'
        );
        $profileStmt->execute([
            ':user_id' => $userId,
            ':full_name' => $fullName,
            ':business_settings' => json_encode($profileSettings),
        ]);
    }

    $verificationStmt = $pdo->prepare('SELECT verification_id FROM Identity_Verifications WHERE user_id = :user_id LIMIT 1');
    $verificationStmt->execute([':user_id' => $userId]);
    $existingVerification = $verificationStmt->fetch();

    $verificationStatusValue = 'Verified';
    if ($existingVerification) {
        $updateVerification = $pdo->prepare(
            'UPDATE Identity_Verifications SET id_type = :id_type, verification_status = :verification_status WHERE user_id = :user_id'
        );
        $updateVerification->execute([
            ':id_type' => $documentType,
            ':verification_status' => $verificationStatusValue,
            ':user_id' => $userId,
        ]);
    } else {
        $insertVerification = $pdo->prepare(
            'INSERT INTO Identity_Verifications (user_id, id_type, verification_status) VALUES (:user_id, :id_type, :verification_status)'
        );
        $insertVerification->execute([
            ':user_id' => $userId,
            ':id_type' => $documentType,
            ':verification_status' => $verificationStatusValue,
        ]);
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Didit webhook processed successfully.',
        'status' => $statusText,
        'user_id' => $userId,
        'document_type' => $documentType,
        'driving_license_verified' => $drivingLicenseVerified,
        'account_status' => $accountStatus,
    ]);
} catch (Throwable $e) {
    error_log('Didit webhook update failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Webhook update failed.']);
}
