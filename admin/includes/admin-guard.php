<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['admin_logged_in'], $_SESSION['user_role']) && $_SESSION['admin_logged_in'] === true && $_SESSION['user_role'] === 'Admin') {
    return;
}

if (isset($_SESSION['admin_id']) && empty($_SESSION['user_role'] ?? null)) {
    require_once __DIR__ . '/../../db.php';

    try {
        $stmt = $pdo->prepare('SELECT user_role FROM Users WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => (int) $_SESSION['admin_id']]);
        $adminUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($adminUser && isset($adminUser['user_role']) && $adminUser['user_role'] === 'Admin') {
            $_SESSION['user_role'] = $adminUser['user_role'];
            return;
        }
    } catch (PDOException $e) {
        // Fall through to the secure redirect below.
    }
}

$_SESSION['admin_error'] = 'You must be signed in as an administrator to access this page.';
$_SESSION = [];

if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}

header('Location: login.php');
exit;
