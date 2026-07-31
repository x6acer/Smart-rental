<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';

function isAdminSession(): bool
{
    if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        return true;
    }

    $userRole = $_SESSION['user_role'] ?? '';
    return $userRole === 'Admin';
}

function isOwnerSession(): bool
{
    return !empty($_SESSION['owner_logged_in']) && $_SESSION['owner_logged_in'] === true && !empty($_SESSION['owner_id']);
}

function isCustomerSession(): bool
{
    return !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && !empty($_SESSION['user_id']) && (!empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'Customer');
}

function resolveRequestedDocumentPath(string $requestedFile): ?string
{
    $candidate = trim(str_replace('\\', '/', $requestedFile));
    if ($candidate === '') {
        return null;
    }

    if (preg_match('#(^|/)(\.\.|/|$)#', $candidate) === 1) {
        return null;
    }

    $allowedPrefixes = ['uploads/kyc/', 'uploads/documents/', 'uploads/vehicles/', 'uploads/cars/', 'uploads/inspections/'];
    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($candidate, $prefix)) {
            $relativePath = $candidate;
            break;
        }
    }

    if (!isset($relativePath)) {
        $relativePath = 'uploads/documents/' . basename($candidate);
    }

    $relativePath = ltrim($relativePath, '/');
    if ($relativePath === '' || $relativePath === 'uploads/documents/') {
        return null;
    }

    return $relativePath;
}

function resolveAllowedRootForPath(string $relativePath): string
{
    $prefixes = [
        'uploads/kyc/' => 'uploads/kyc',
        'uploads/documents/' => 'uploads/documents',
        'uploads/vehicles/' => 'uploads/vehicles',
        'uploads/cars/' => 'uploads/cars',
        'uploads/inspections/' => 'uploads/inspections',
    ];

    foreach ($prefixes as $prefix => $root) {
        if (str_starts_with($relativePath, $prefix)) {
            return $root;
        }
    }

    return 'uploads/documents';
}

function resolveStoredDocumentAbsolutePath(string $relativePath): ?string
{
    $projectRoot = dirname(__DIR__);
    $allowedRootName = resolveAllowedRootForPath($relativePath);
    $storageRoot = $projectRoot . DIRECTORY_SEPARATOR . $allowedRootName;
    $candidatePath = $storageRoot . DIRECTORY_SEPARATOR . basename($relativePath);
    $realStorageRoot = realpath($storageRoot);
    $realCandidatePath = realpath($candidatePath);

    if ($realStorageRoot === false || $realCandidatePath === false || !is_file($realCandidatePath)) {
        return null;
    }

    $normalizedStorageRoot = str_replace('\\', '/', $realStorageRoot);
    $normalizedCandidatePath = str_replace('\\', '/', $realCandidatePath);

    if (!str_starts_with($normalizedCandidatePath, $normalizedStorageRoot . '/')) {
        return null;
    }

    return $realCandidatePath;
}

function detectDocumentMimeType(string $absolutePath): string
{
    $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    $mimeMap = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    if (isset($mimeMap[$extension])) {
        return $mimeMap[$extension];
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = finfo_file($finfo, $absolutePath);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }
    }

    return 'application/octet-stream';
}

function isDocumentOwnedByCurrentUser(PDO $pdo, string $relativePath, string $documentName): bool
{
    global $pdo;

    $currentOwnerId = (int) ($_SESSION['owner_id'] ?? 0);
    $currentCustomerId = (int) ($_SESSION['user_id'] ?? 0);

    if ($currentOwnerId > 0) {
        $stmt = $pdo->prepare('SELECT 1 FROM Owner_Verification_Documents WHERE owner_id = :owner_id AND (document_url = :document_url OR document_url = :document_name) LIMIT 1');
        $stmt->execute([
            'owner_id' => $currentOwnerId,
            'document_url' => $relativePath,
            'document_name' => 'uploads/kyc/' . $documentName,
        ]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
    }

    if ($currentCustomerId > 0 && !empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'Customer') {
        $tables = $pdo->query("SHOW TABLES LIKE 'Customer_Verification_Documents'");
        if ($tables !== false && $tables->fetch() !== false) {
            $stmt = $pdo->prepare('SELECT 1 FROM Customer_Verification_Documents WHERE customer_id = :customer_id AND (document_url = :document_url OR document_url = :document_name) LIMIT 1');
            $stmt->execute([
                'customer_id' => $currentCustomerId,
                'document_url' => $relativePath,
                'document_name' => 'uploads/kyc/' . $documentName,
            ]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }
        }
    }

    return false;
}

$requestedFile = (string) ($_GET['file'] ?? '');
$relativePath = resolveRequestedDocumentPath($requestedFile);
if ($relativePath === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

$documentName = basename($relativePath);
$absolutePath = resolveStoredDocumentAbsolutePath($relativePath);
if ($absolutePath === null || !is_file($absolutePath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

$authorized = isAdminSession() || (isOwnerSession() && isDocumentOwnedByCurrentUser($pdo, $relativePath, $documentName)) || (isCustomerSession() && isDocumentOwnedByCurrentUser($pdo, $relativePath, $documentName));
if (!$authorized) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$mimeType = detectDocumentMimeType($absolutePath);
$size = filesize($absolutePath);
if ($size === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Internal Server Error';
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . basename($absolutePath) . '"');
header('Content-Length: ' . $size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

$bytesSent = readfile($absolutePath);
if ($bytesSent === false) {
    http_response_code(500);
    exit;
}

exit;
