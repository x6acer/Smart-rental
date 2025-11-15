<?php
require_once __DIR__ . '/../db_connect.php';
$db = db_get_conn();

session_start();

// Debug session state
error_log("Session state in order_summary.php: " . print_r($_SESSION, true));

if (!isset($_SESSION['customer_id'])) {
    error_log("No customer_id in session, redirecting to login");
    header('Location: login.php');
    exit;
}

$customerId = $_SESSION['customer_id'];
error_log("Found customer_id in session: " . $customerId);

// Get or create user_id mapping for this customer
$userId = null;
$userCheck = $db->prepare("SELECT id FROM users WHERE customer_id = ? LIMIT 1");
if ($userCheck) {
    $userCheck->bind_param('i', $customerId);
    $userCheck->execute();
    $userRes = $userCheck->get_result();
    if ($userRes && ($user = $userRes->fetch_assoc())) {
        $userId = $user['id'];
    }
}

if (!$userId) {
    // Get customer data to create user
    $custCheck = $db->prepare("SELECT email FROM customers WHERE id = ? LIMIT 1");
    $custCheck->bind_param('i', $customerId);
    $custCheck->execute();
    $custData = $custCheck->get_result()->fetch_assoc();
    
    if ($custData) {
        // First check if user with this email already exists
        $emailCheck = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $emailCheck->bind_param('s', $custData['email']);
        $emailCheck->execute();
        $emailCheck->store_result();
        
        if ($emailCheck->num_rows > 0) {
            // User exists - get their ID
            $emailCheck->bind_result($userId);
            $emailCheck->fetch();
            
            // Update customer_id mapping if different
            $updateUser = $db->prepare("UPDATE users SET customer_id = ? WHERE id = ?");
            $updateUser->bind_param('ii', $customerId, $userId);
            $updateUser->execute();
        } else {
            // Create new user only if email doesn't exist
            $createUser = $db->prepare("INSERT INTO users (customer_id, email, type) VALUES (?, ?, 'customer')");
            $createUser->bind_param('is', $customerId, $custData['email']);
            $createUser->execute();
            $userId = $db->insert_id;
        }
    }
}

// Handle post-payment order persistence (when redirected with clear_cart=1)
if (isset($_GET['clear_cart']) && $_GET['clear_cart'] == '1' && isset($_GET['id'])) {
    $orderId = intval($_GET['id']);
    
    // Verify this order belongs to the current user and needs processing
    $orderCheck = $db->prepare("SELECT id, status FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
    $orderCheck->bind_param('ii', $orderId, $userId);
    $orderCheck->execute();
    $orderData = $orderCheck->get_result()->fetch_assoc();
    
    if ($orderData && $orderData['status'] == 'pending') {
        // Update order status to paid. Only include paid_at if the column exists.
        $paidColRes = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'paid_at'");
        $hasPaidAt = false;
        if ($paidColRes) {
            $pr = $paidColRes->fetch_assoc();
            $hasPaidAt = !empty($pr['cnt']);
        }

        if ($hasPaidAt) {
            $updateOrder = $db->prepare("UPDATE orders SET status = 'paid', paid_at = NOW() WHERE id = ?");
        } else {
            $updateOrder = $db->prepare("UPDATE orders SET status = 'paid' WHERE id = ?");
        }
        if ($updateOrder) {
            $updateOrder->bind_param('i', $orderId);
            $updateOrder->execute();
        }

        // Clear the cart in session since order is now paid
        unset($_SESSION['cart']);
    }
}

// Detect which columns exist on orders so we can filter safely
$colRes = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME IN ('user_id','customer_id','customer_email','created_at','order_date','start_date','end_date','paid_at','status')");
$orderCols = [];
if ($colRes) {
    while ($r = $colRes->fetch_assoc()) {
        $orderCols[$r['COLUMN_NAME']] = true;
    }
}

// Detect presence of date columns and prepare NULL aliases when absent
$colDatesRes = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME IN ('created_at','order_date','start_date','end_date','paid_at')");
$hasDateCols = [];
if ($colDatesRes) {
    while ($r = $colDatesRes->fetch_assoc()) {
        $hasDateCols[$r['COLUMN_NAME']] = true;
    }
}
$selectDateParts = [];
$selectDateParts[] = !empty($hasDateCols['order_date']) ? "o.order_date as order_date" : "NULL as order_date";
$selectDateParts[] = !empty($hasDateCols['created_at']) ? "o.created_at as created_at" : "NULL as created_at";
$selectDateParts[] = !empty($hasDateCols['start_date']) ? "o.start_date as start_date" : "NULL as start_date";
$selectDateParts[] = !empty($hasDateCols['end_date']) ? "o.end_date as end_date" : "NULL as end_date";
$selectDateParts[] = !empty($hasDateCols['paid_at']) ? "o.paid_at as paid_at" : "NULL as paid_at";
$datesSelectSql = implode(', ', $selectDateParts);

// Get orders for this customer (temporary direct user_id match)
// Build where clause based on view mode
$whereClause = [];
$whereParams = [];
$whereTypes = '';

// Always filter by user_id
$whereClause[] = "o.user_id = ?";
$whereParams[] = $userId;
$whereTypes .= 'i';

// Check if status column exists
$statusColRes = $db->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
                           WHERE TABLE_SCHEMA = DATABASE() 
                           AND TABLE_NAME = 'orders' 
                           AND COLUMN_NAME = 'status'");
if ($statusColRes && $statusColRes->num_rows > 0) {
    $whereClause[] = "o.status = ?";
    $whereParams[] = 'paid';
    $whereTypes .= 's';
}

// Check rentals table schema
$rentalColRes = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                           WHERE TABLE_SCHEMA = DATABASE() 
                           AND TABLE_NAME = 'rentals' 
                           AND COLUMN_NAME IN ('name', 'title', 'vehicle_name', 'description', 'daily_rate', 'price_per_day')");
$rentalCols = [];
if ($rentalColRes) {
    while ($r = $rentalColRes->fetch_assoc()) {
        $rentalCols[$r['COLUMN_NAME']] = true;
    }
}

// Build rental name and rate fields based on available columns
$rentalNameCol = !empty($rentalCols['name']) ? 'r.name' : 
                (!empty($rentalCols['title']) ? 'r.title' : 
                (!empty($rentalCols['vehicle_name']) ? 'r.vehicle_name' : 
                (!empty($rentalCols['description']) ? 'r.description' : "'' ")));

$rentalRateCol = !empty($rentalCols['daily_rate']) ? 'r.daily_rate' : 
                (!empty($rentalCols['price_per_day']) ? 'r.price_per_day' : 'NULL');

// For single order view (after payment), show pending/paid
if (isset($_GET['id'])) {
    $whereClause[] = "o.id = ?";
    $whereParams[] = intval($_GET['id']);
    $whereTypes .= 'i';
} else {
    // For list view, only show paid orders if status column exists
    if (isset($statusColRes) && $statusColRes->num_rows > 0) {
        $whereClause[] = "o.status = ?";
        $whereParams[] = 'paid';
        $whereTypes .= 's';
    }
}

// Build ORDER BY clause based on available date columns
$orderByCol = !empty($hasDateCols['order_date']) ? 'o.order_date' :
             (!empty($hasDateCols['created_at']) ? 'o.created_at' :
             (!empty($hasDateCols['paid_at']) ? 'o.paid_at' : 'o.id'));

$orderQuery = "SELECT o.*, oi.*, $datesSelectSql,
               $rentalNameCol as rental_name, 
               $rentalRateCol as daily_rate,
               (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
               FROM orders o
               LEFT JOIN order_items oi ON o.id = oi.order_id
               LEFT JOIN rentals r ON oi.rental_id = r.id
               WHERE " . implode(' AND ', $whereClause) . "
               ORDER BY $orderByCol DESC";

$stmt = $db->prepare($orderQuery);
if ($stmt && !empty($whereParams)) {
    error_log("Binding parameters - Types: $whereTypes, Params: " . print_r($whereParams, true));
    $stmt->bind_param($whereTypes, ...$whereParams);
    
    if ($stmt->execute()) {
        $orders = $stmt->get_result();
        error_log("Query executed successfully, found " . $orders->num_rows . " orders");
    } else {
        error_log("Query execution failed: " . $stmt->error);
        die("Failed to fetch orders: " . $stmt->error);
    }
} else {
    error_log("Failed to prepare statement or no parameters to bind");
    die("Failed to prepare order query");
}

// Helper to fetch order items (schema-aware: support rentals.title or rentals.name)
function fetch_order_items($db, $order_id) {
    // Detect which columns exist in rentals
    $colRes = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME IN ('title','name','image')");
    $rentalCols = [];
    if ($colRes) {
        while ($r = $colRes->fetch_assoc()) {
            $rentalCols[$r['COLUMN_NAME']] = true;
        }
    }
    $titleCol = !empty($rentalCols['title']) ? 'title' : (!empty($rentalCols['name']) ? 'name' : null);
    $imageCol = !empty($rentalCols['image']) ? 'image' : null;
    $select = 'oi.*';
    if ($titleCol) $select .= ", r.$titleCol as title";
    else $select .= ", NULL as title";
    if ($imageCol) $select .= ", r.$imageCol as image";
    else $select .= ", NULL as image";
    $sql = "SELECT $select FROM order_items oi LEFT JOIN rentals r ON oi.rental_id = r.id WHERE oi.order_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Helper: pick the best available date value from an order row.
function find_order_date_val($order) {
    if (!is_array($order)) return null;
    // Preferred keys in order
    $preferred = ['created_at','created_on','created','date_created','order_date','start_date','end_date','timestamp','createdAt'];
    foreach ($preferred as $k) {
        if (array_key_exists($k, $order) && !empty($order[$k])) return ['key'=>$k,'value'=>$order[$k]];
    }
    // Fallback: any key containing date/time/created/timestamp
    foreach ($order as $k => $v) {
        if (preg_match('/created|date|time|timestamp/i', $k) && !empty($v)) return ['key'=>$k,'value'=>$v];
    }
    return null;
}

// Execute the prepared statement
if (!$stmt->execute()) {
    die("Error fetching orders: " . $db->error);
}

$result = $stmt->get_result();
$orders = [];
$currentOrder = null;

// Group order items by order
while ($row = $result->fetch_assoc()) {
    $orderId = $row['order_id'];
    
    if (!isset($orders[$orderId])) {
        $orders[$orderId] = [
            'id' => $orderId,
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'paid_at' => $row['paid_at'],
            'total_amount' => $row['total_amount'],
            'items' => []
        ];
    }
    
    if (!empty($row['rental_id'])) {
        $orders[$orderId]['items'][] = [
            'rental_id' => $row['rental_id'] ?? null,
            'rental_name' => $row['rental_name'] ?? null,
            'quantity' => $row['quantity'] ?? 0,
            'daily_rate' => $row['daily_rate'] ?? null,
            'days' => $row['days'] ?? null,
            'total' => $row['total_amount'] ?? ($row['total'] ?? null)
        ];
    }
    
    // Keep track of current order for single-order view
    if (isset($_GET['id']) && $row['id'] == $_GET['id']) {
        $currentOrder = &$orders[$orderId];
    }
}

// For single order view
if (isset($_GET['id'])) {
    $order = $currentOrder;

        // Try to resolve customer name from customers table if possible
        if ($order && empty($order['customer_name']) && isset($order['customer_email'])) {
            $cstmt = $db->prepare("SELECT name FROM customers WHERE email = ? LIMIT 1");
            if ($cstmt) {
                $cstmt->bind_param('s', $order['customer_email']);
                $cstmt->execute();
                $cres = $cstmt->get_result()->fetch_assoc();
                if ($cres) $order['customer_name'] = $cres['name'];
            }
        }

        if (!$order) {
            header('Location: index.php');
            exit;
        }

        // Get order items if we have an order ID
        $items = fetch_order_items($db, $_GET['id']);
} else {
    // Get all orders for current customer - check both user_id and customer_id fields
    // Debug log
    $debug_log = fopen(__DIR__ . '/order_debug.log', 'a');
    fwrite($debug_log, "\n=== " . date('Y-m-d H:i:s') . " ===\n");
    fwrite($debug_log, "Session customer_id: " . $_SESSION['customer_id'] . "\n");
    
    // Prefer order_date when present, otherwise created_at
    $dateOrderCol = !empty($hasDateCols['order_date']) ? 'o.order_date' : (!empty($hasDateCols['created_at']) ? 'o.created_at' : null);

    $sql = "SELECT o.*, $datesSelectSql, 
        (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
    FROM orders o 
    WHERE (o.user_id = ? " . (!empty($orderCols['customer_id']) ? " OR o.customer_id = ? " : "") . ") 
    ORDER BY " . ($dateOrderCol ? $dateOrderCol . ' DESC' : 'o.id DESC');
    
    fwrite($debug_log, "SQL Query: " . $sql . "\n");
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        fwrite($debug_log, "Prepare failed: " . $db->error . "\n");
        error_log("Prepare failed: " . $db->error);
        die("Database error: " . $db->error);
    }

    // Bind parameters: always bind user_id; bind customer_id too if the column exists
    $bindParams = [];
    $bindTypes = '';
    $bindParams[] = $userId;
    $bindTypes .= 'i';
    if (!empty($orderCols['customer_id'])) {
        $bindParams[] = $customerId;
        $bindTypes .= 'i';
    }

    if (!empty($bindParams)) {
        // bind_param requires references in an array
        $bind_names = [];
        $bind_names[] = $bindTypes;
        for ($i = 0; $i < count($bindParams); $i++) {
            $bind_names[] = & $bindParams[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_names);
        fwrite($debug_log, "Binding params: types=" . $bindTypes . " params=" . print_r($bindParams, true) . "\n");
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $orders = [];
        
        // Group orders and their items
        while ($row = $result->fetch_assoc()) {
            $orderId = $row['id'];
            if (!isset($orders[$orderId])) {
                $orders[$orderId] = [
                    'id' => $orderId,
                    'status' => $row['status'],
                    'created_at' => $row['created_at'],
                    'paid_at' => $row['paid_at'],
                    'total_amount' => $row['total_amount'],
                    'items' => []
                ];
            }
            
            if (!empty($row['rental_id'])) {
                $orders[$orderId]['items'][] = [
                    'rental_id' => $row['rental_id'] ?? null,
                    'rental_name' => $row['rental_name'] ?? null,
                    'quantity' => $row['quantity'] ?? 0,
                    'daily_rate' => $row['daily_rate'] ?? null,
                    'days' => $row['days'] ?? null,
                    'total' => $row['total_amount'] ?? ($row['total'] ?? null)
                ];
            }
        }
        
        // Convert to indexed array for display
        $orders = array_values($orders);
    } else {
        $orders = [];
    }
}

$page_title = isset($order) ? "Order #" . $order['id'] : "My Orders";
include __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <?php if (isset($_GET['debug']) && $_GET['debug'] == '1'): ?>
        <div class="mb-4 p-3 bg-yellow-50 border border-yellow-200 text-sm rounded">
            <strong>DEBUG:</strong>
            Session customer_id: <?php echo htmlspecialchars($_SESSION['customer_id'] ?? 'NONE'); ?> •
            Orders found: <?php echo isset($orders) ? count($orders) : 'N/A'; ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['debug']) && $_GET['debug'] == '1'): ?>
        <pre style="background:#fff;padding:10px;border:1px solid #eee;overflow:auto;max-height:300px;">
<?php echo htmlspecialchars(var_export($orders, true)); ?>
        </pre>
    <?php endif; ?>
    <?php if (isset($order)): ?>
        <!-- Single Order View -->
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">Order #<?php echo $order['id']; ?></h1>
            <a href="order_summary.php" class="text-[#1b4b4b] hover:underline">← Back to Orders</a>
        </div>
        
        <div class="bg-white rounded-xl p-6 shadow-md mb-6">
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <h3 class="font-bold text-sm text-gray-600">Order Date</h3>
                    <?php $od = find_order_date_val($order); ?>
                    <p><?php echo $od ? date('F j, Y', strtotime($od['value'])) : 'N/A'; ?></p>
                </div>
                <div>
                    <h3 class="font-bold text-sm text-gray-600">Status</h3>
                    <p><?php echo $order['status']; ?></p>
                </div>
                <div>
                    <h3 class="font-bold text-sm text-gray-600">Customer</h3>
                    <p><?php echo htmlspecialchars($order['customer_name']); ?></p>
                </div>
                <div>
                    <h3 class="font-bold text-sm text-gray-600">Total Amount</h3>
                    <p><?php echo format_money($order['total_amount']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl p-6 shadow-md">
            <h2 class="font-bold text-xl mb-4">Vehicles Rented</h2>
            <div class="space-y-4">
                <?php foreach ($items as $item): ?>
                    <div class="flex items-center gap-4 p-4 border rounded-lg">
                        <img src="/smart_rental/admin/uploads/<?php echo htmlspecialchars($item['image']); ?>"
                             alt="<?php echo htmlspecialchars($item['title']); ?>"
                             class="w-20 h-20 object-cover rounded">
                        <div class="flex-1">
                            <h3 class="font-bold"><?php echo htmlspecialchars($item['title']); ?></h3>
                                        <p class="text-sm text-gray-600">
                                            <?php echo $item['days']; ?> days @ <?php echo format_money($item['daily_rate'] ?? $item['price_per_day'] ?? 0); ?>/day
                                        </p>
                        </div>
                        <div class="text-right">
                                        <p class="font-bold"><?php echo format_money($item['total_amount'] ?? $item['total_price'] ?? 0); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
    <?php else: ?>
        <!-- Orders List View -->
        <h1 class="text-2xl font-bold mb-6">My Orders</h1>
        
        <?php if (empty($orders)): ?>
            <div class="text-center py-8">
                <p class="text-gray-600">You haven't placed any orders yet.</p>
                <a href="items_details.php" class="text-[#1b4b4b] hover:underline">Browse Vehicles</a>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($orders as $order): ?>
                    <?php $items_preview = fetch_order_items($db, $order['id']); $first = $items_preview[0] ?? null; ?>
                    <a href="?id=<?php echo $order['id']; ?>" 
                       class="block bg-white rounded-xl p-6 shadow-md hover:shadow-lg transition">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center gap-4">
                                <?php if ($first && !empty($first['image'])): ?>
                                    <img src="/smart_rental/admin/uploads/<?php echo htmlspecialchars($first['image']); ?>" alt="" class="w-16 h-16 object-cover rounded">
                                <?php else: ?>
                                    <div class="w-16 h-16 bg-gray-100 rounded"></div>
                                <?php endif; ?>
                                <div>
                                    <h3 class="font-bold">Order #<?php echo $order['id']; ?></h3>
                                    <p class="text-sm text-gray-600">
                                        <?php
                                            // prefer order_date, then created_at
                                            $od = null;
                                            if (!empty($order['order_date'])) $od = $order['order_date'];
                                            elseif (!empty($order['created_at'])) $od = $order['created_at'];
                                            echo $od ? date('M j, Y g:i A', strtotime($od)) : 'N/A';
                                        ?> • <?php
                                            $item_count = $order['item_count'] ?? (is_array($items_preview) ? count($items_preview) : 0);
                                            echo $item_count;
                                        ?> vehicles
                                    </p>
                                    <?php if ($first): ?>
                                        <p class="text-sm text-gray-700 font-medium"><?php echo htmlspecialchars($first['title'] ?? $first['name'] ?? 'Vehicle'); ?></p>
                                        <?php
                                            $rstart = $first['start_date'] ?? $order['start_date'] ?? null;
                                            $rend = $first['end_date'] ?? $order['end_date'] ?? null;
                                            if ($rstart || $rend) {
                                                echo '<p class="text-xs text-gray-500">';
                                                if ($rstart) echo date('M j, Y', strtotime($rstart));
                                                echo ' - ';
                                                if ($rend) echo date('M j, Y', strtotime($rend));
                                                echo '</p>';
                                            }
                                        ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-bold"><?php echo format_money($order['total_amount']); ?></p>
                                <p class="text-sm text-gray-600">
                                    <span class="px-2 py-1 font-semibold leading-tight rounded-full <?php
                                        switch ($order['status'] ?? '') {
                                            case 'pending': echo 'text-orange-700 bg-orange-100'; break;
                                            case 'active': echo 'text-green-700 bg-green-100'; break;
                                            case 'completed': echo 'text-blue-700 bg-blue-100'; break;
                                            case 'cancelled': echo 'text-red-700 bg-red-100'; break;
                                            default: echo 'text-gray-700 bg-gray-100';
                                        }
                                    ?>">
                                        <?php echo ucfirst($order['status'] ?? 'unknown'); ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
// If we were redirected after checkout, clear client-side cart stored in localStorage
if (isset($_GET['clear_cart']) && $_GET['clear_cart'] == '1') {
    echo "<script>try{ localStorage.removeItem('cart'); }catch(e){};</script>\n";
}
if (isset($_SESSION['clear_cart'])) {
    // Output JS once and then clear the session flag
    echo "<script>try{ localStorage.removeItem('cart'); }catch(e){};</script>\n";
    unset($_SESSION['clear_cart']);
}

include __DIR__ . '/includes/footer.php'; ?>
