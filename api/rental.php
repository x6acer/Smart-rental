<?php
require_once __DIR__ . '/../db_connect.php';
$db = db_get_conn();

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Rental ID is required']);
    exit;
}

// Detect which columns exist so we can query safely across schema variants
$colRes = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME IN ('name','title','daily_rate','price_per_day','image','category_id')");
$has = [];
if ($colRes) {
    while ($r = $colRes->fetch_assoc()) {
        $has[$r['COLUMN_NAME']] = true;
    }
}

// Build select dynamically
$select = "SELECT r.*";
$select .= (isset($has['name'])) ? ", r.name AS title" : "";
$select .= (!isset($has['name']) && isset($has['title'])) ? ", r.title AS title" : "";
$select .= (isset($has['daily_rate'])) ? ", r.daily_rate AS price_per_day" : "";
$select .= (!isset($has['daily_rate']) && isset($has['price_per_day'])) ? ", r.price_per_day AS price_per_day" : "";

// Join categories only if category_id exists
$joinCategory = isset($has['category_id']);
if ($joinCategory) {
    $select .= ", c.name as category_name";
}

$sql = $select . " FROM rentals r";
if ($joinCategory) {
    $sql .= " LEFT JOIN categories c ON r.category_id = c.id";
}
 $sql .= " WHERE r.id = ?";

$stmt = $db->prepare($sql);
$id = intval($_GET['id']);
$stmt->bind_param('i', $id);
$stmt->execute();
$rental = $stmt->get_result()->fetch_assoc();

if (!$rental) {
    http_response_code(404);
    echo json_encode(['error' => 'Rental not found']);
    exit;
}

// Normalize expected fields for the frontend
$rental['title'] = $rental['title'] ?? $rental['name'] ?? $rental['model'] ?? '';
$rental['price_per_day'] = $rental['price_per_day'] ?? $rental['daily_rate'] ?? $rental['price_per_day'] ?? 0;
$rental['image'] = $rental['image'] ?? '';
$rental['category_name'] = $rental['category_name'] ?? '';

echo json_encode($rental);