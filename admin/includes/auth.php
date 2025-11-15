<?php
require_once __DIR__ . '/config.php';
if (!is_admin_logged_in()) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: /smart_rental/admin/login.php');
    exit;
}
