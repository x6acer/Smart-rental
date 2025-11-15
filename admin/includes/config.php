<?php

require_once __DIR__ . '/../../db_connect.php';
require_once __DIR__ . '/../../config.php';

session_start();

function is_admin_logged_in() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function admin_logout() {
    unset($_SESSION['admin_logged_in']);
    session_destroy();
}

function set_flash_message($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function get_flash_message() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
