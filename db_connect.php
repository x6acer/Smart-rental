<?php
// Database connection for SmartRental
// Adjust these constants if your MySQL credentials differ
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'smart_rental');

// Create mysqli connection (used by customer-facing code)
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($mysqli->connect_errno) {
    die('MySQL connect error: ' . $mysqli->connect_error);
}


$mysqli->query("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

$mysqli->select_db(DB_NAME);
$mysqli->set_charset('utf8mb4');

$pdo = null;
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {

    die('PDO connection error: ' . $e->getMessage());
}

$conn = $pdo;

function db_get_conn() {
    global $mysqli;
    return $mysqli;
}

function pdo_get_conn() {
    global $conn;
    return $conn;
}
