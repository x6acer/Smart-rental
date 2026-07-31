<?php
declare(strict_types=1);
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../includes/security.php';
    require_once __DIR__ . '/../includes/asset-helper.php';
    require_once __DIR__ . '/../includes/render-vehicle-card.php';
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? null;
if (!verifyCsrfToken($csrf, 'public-browse')) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Security validation failed']);
    exit;
}

$type = trim((string)($_POST['type'] ?? 'Any Type'));
$price = trim((string)($_POST['price'] ?? 'Any Price'));
$gear = trim((string)($_POST['gear'] ?? ''));
$region = trim((string)($_POST['region'] ?? 'All Regions'));

$where = ['status = "Active"'];
$params = [];

if ($gear !== '') {
    // gear options likely 'Automatic' or 'Manual' — map to transmission
    $where[] = 'LOWER(transmission) = :transmission';
    $params['transmission'] = strtolower($gear);
}

if ($type !== '' && strtolower($type) !== 'any type') {
    // vehicle_type column was added by migration; match case-insensitive
    $where[] = 'LOWER(COALESCE(vehicle_type, "")) = :vehicle_type';
    $params['vehicle_type'] = strtolower($type);
}

if ($price !== '' && $price !== 'Any Price') {
    if ($price === 'Below 1,000') {
        $where[] = 'base_rate < :pmax';
        $params['pmax'] = 1000;
    } elseif ($price === '1,000 - 2,500') {
        $where[] = 'base_rate BETWEEN :pmin AND :pmax';
        $params['pmin'] = 1000;
        $params['pmax'] = 2500;
    } elseif ($price === 'Above 2,500') {
        $where[] = 'base_rate > :pmin';
        $params['pmin'] = 2500;
    }
}

if ($region !== '' && strtolower($region) !== 'all regions') {
    $where[] = 'LOWER(COALESCE(region, "")) = :region';
    $params['region'] = strtolower($region);
}
$sql = 'SELECT vehicle_id, make, model, year, transmission, fuel_type, base_rate, instant_book_enabled, vehicle_type, region FROM Vehicles WHERE ' . implode(' AND ', $where) . ' ORDER BY vehicle_id DESC LIMIT 60';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll();

ob_end_clean();

$html = '';
if (!$vehicles) {
    $html = '';
} else {
    foreach ($vehicles as $vehicle) {
        $html .= renderVehicleCard($pdo, $vehicle, $region);
    }
}

echo json_encode(['success' => true, 'count' => count($vehicles), 'html' => $html]);
exit;
exit;
