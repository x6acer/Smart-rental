<?php
require_once 'includes/auth.php';
require_once '../db_connect.php';

// Get filter parameters
$status = $_GET['status'] ?? null;
$customer_id = $_GET['customer_id'] ?? null;
$user_id = $_GET['user_id'] ?? null;
$date_from = $_GET['date_from'] ?? null;
$date_to = $_GET['date_to'] ?? null;

// Sanitize user_id
$user_id = $user_id ? filter_var($user_id, FILTER_VALIDATE_INT) : null;

// Detect whether orders link to customers (customer_id) or users (user_id)
$colCheck = $conn->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME IN ('customer_id','user_id')"
);
$colCheck->execute();
$orderCols = [];
while ($r = $colCheck->fetch(PDO::FETCH_ASSOC)) $orderCols[$r['COLUMN_NAME']] = true;

// Check for customers/users tables
$tblRes = $conn->prepare("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers'");
$tblRes->execute();
$hasCustomers = ($tblRes->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
$tblRes2 = $conn->prepare("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
$tblRes2->execute();
$hasUsers = ($tblRes2->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;

$hasCustomerId = !empty($orderCols['customer_id']) && $hasCustomers;
$hasUserId = !empty($orderCols['user_id']) && $hasUsers;

// Check if orders.created_at or orders.order_date exists
$colDateCheck = $conn->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'orders' 
    AND COLUMN_NAME IN ('order_date','created_at')"
);
$colDateCheck->execute();
$dateCols = [];
while ($dr = $colDateCheck->fetch(PDO::FETCH_ASSOC)) $dateCols[$dr['COLUMN_NAME']] = true;

// Prefer order_date when present, otherwise created_at
$hasOrderDate = !empty($dateCols['order_date']);
$hasCreatedAt = !empty($dateCols['created_at']);
$dateCol = $hasOrderDate ? 'order_date' : ($hasCreatedAt ? 'created_at' : null);

// Build base query depending on schema
if ($hasCustomers && (!empty($orderCols['customer_id']) || !empty($orderCols['user_id']))) {
    // prefer joining to customers table using whichever key exists on orders
    $joinKey = !empty($orderCols['customer_id']) ? 'customer_id' : 'user_id';
    $custColRes = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME IN ('name','fullname','full_name','username','email')");
    $custColRes->execute();
    $custCols = [];
    while ($rr = $custColRes->fetch(PDO::FETCH_ASSOC)) $custCols[$rr['COLUMN_NAME']] = true;
    $custNameCol = isset($custCols['name']) ? 'name' : (isset($custCols['fullname']) ? 'fullname' : (isset($custCols['full_name']) ? 'full_name' : (isset($custCols['username']) ? 'username' : (isset($custCols['email']) ? 'email' : null))));
    $selectCust = $custNameCol ? "c.$custNameCol as customer_name, c.email as customer_email" : "NULL as customer_name, NULL as customer_email";
    $query = "
        SELECT o.*, $selectCust, COUNT(oi.id) as total_items
        FROM orders o
        LEFT JOIN customers c ON o.$joinKey = c.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE 1=1
    ";
} elseif ($hasUsers && !empty($orderCols['user_id'])) {
    // Fall back to users table when customers table isn't available
    $userColRes = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('name','fullname','full_name','username','email')");
    $userColRes->execute();
    $userCols = [];
    while ($rr = $userColRes->fetch(PDO::FETCH_ASSOC)) $userCols[$rr['COLUMN_NAME']] = true;
    $userNameCol = isset($userCols['name']) ? 'name' : (isset($userCols['fullname']) ? 'fullname' : (isset($userCols['full_name']) ? 'full_name' : (isset($userCols['username']) ? 'username' : (isset($userCols['email']) ? 'email' : null))));
    $selectUser = $userNameCol ? "u.$userNameCol as customer_name, u.email as customer_email" : "NULL as customer_name, NULL as customer_email";
    $query = "
        SELECT o.*, $selectUser, COUNT(oi.id) as total_items
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE 1=1
    ";
} else {
    // Fall back to orders-only when no linking column exists
    $query = "
        SELECT o.*, 'Unknown' as customer_name, '' as customer_email,
               COUNT(oi.id) as total_items
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE 1=1
    ";
}
$params = [];

if ($status) {
    $query .= " AND o.status = ?";
    $params[] = $status;
}
if ($customer_id && $hasCustomerId) {
    $query .= " AND o.customer_id = ?";
    $params[] = $customer_id;
} elseif ($user_id && $hasUserId) {
    $query .= " AND o.user_id = ?";
    $params[] = $user_id;
}
if ($date_from && $dateCol) {
    $query .= " AND DATE(o." . $dateCol . ") >= ?";
    $params[] = $date_from;
}
if ($date_to && $dateCol) {
    $query .= " AND DATE(o." . $dateCol . ") <= ?";
    $params[] = $date_to;
}

// Add ORDER BY clause based on available columns
$query .= " GROUP BY o.id ORDER BY " . ($dateCol ? "o." . $dateCol . " DESC" : "o.id DESC");

// Execute query
$stmt = $conn->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle no orders found
$hasOrders = !empty($orders);

// Get order statistics
$stats = [
    'total' => 0,
    'pending' => 0,
    'active' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'revenue' => 0
];

foreach ($orders as $order) {
    $stats['total']++;
    // Normalize status value and guard against empty/missing statuses
    $statusKey = isset($order['status']) && $order['status'] !== '' ? $order['status'] : 'unknown';
    if (!isset($stats[$statusKey])) $stats[$statusKey] = 0;
    $stats[$statusKey]++;

    // Safely add revenue only when total_amount is numeric and order not cancelled
    $amount = isset($order['total_amount']) && is_numeric($order['total_amount']) ? (float)$order['total_amount'] : 0.0;
    if ($statusKey !== 'cancelled') {
        $stats['revenue'] += $amount;
    }
}

// Get customer name if user_id is present
$customerName = null;
if ($user_id) {
    if ($hasUserId) {
        $nameQuery = $conn->prepare("
            SELECT COALESCE(u.name, u.fullname, u.full_name, u.username, u.email) as customer_name 
            FROM users u 
            WHERE u.id = ?
        ");
        $nameQuery->execute([$user_id]);
        $customerData = $nameQuery->fetch(PDO::FETCH_ASSOC);
        $customerName = $customerData ? $customerData['customer_name'] : 'Unknown Customer';
    }
}

$page_title = $user_id ? "Orders for: $customerName" : "Orders";
require_once 'includes/header.php';
?>

<div class="container px-6 mx-auto grid">
    <div class="flex justify-between items-center my-6">
        <h2 class="text-2xl font-semibold text-gray-700">
            <?php if ($user_id): ?>
                Orders for: <?php echo htmlspecialchars($customerName); ?>
                <a href="customers.php" class="ml-4 px-4 py-2 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200">
                    ← Back to Customers
                </a>
            <?php else: ?>
                Order Management
            <?php endif; ?>
        </h2>
    </div>

    <!-- Order Stats -->
    <?php if (!$user_id): // Only show stats for main orders page ?>
    <div class="grid gap-6 mb-8 md:grid-cols-4">
        <!-- Total Orders -->
        <div class="flex items-center p-4 bg-white rounded-lg shadow-xs">
            <div class="p-3 mr-4 text-blue-500 bg-blue-100 rounded-full">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M3 1a1 1 0 000 2h1.22l.305 1.222a.997.997 0 00.01.042l1.358 5.43-.893.892C3.74 11.846 4.632 14 6.414 14H15a1 1 0 000-2H6.414l1-1H14a1 1 0 00.894-.553l3-6A1 1 0 0017 3H6.28l-.31-1.243A1 1 0 005 1H3zM16 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM6.5 18a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"></path>
                </svg>
            </div>
            <div>
                <p class="mb-2 text-sm font-medium text-gray-600">
                    Total Orders
                </p>
                <p class="text-lg font-semibold text-gray-700">
                    <?php echo $stats['total']; ?>
                </p>
            </div>
        </div>

        <!-- Active Orders -->
        <div class="flex items-center p-4 bg-white rounded-lg shadow-xs">
            <div class="p-3 mr-4 text-green-500 bg-green-100 rounded-full">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div>
                <p class="mb-2 text-sm font-medium text-gray-600">
                    Active Orders
                </p>
                <p class="text-lg font-semibold text-gray-700">
                    <?php echo $stats['active']; ?>
                </p>
            </div>
        </div>

        <!-- Pending Orders -->
        <div class="flex items-center p-4 bg-white rounded-lg shadow-xs">
            <div class="p-3 mr-4 text-orange-500 bg-orange-100 rounded-full">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div>
                <p class="mb-2 text-sm font-medium text-gray-600">
                    Pending Orders
                </p>
                <p class="text-lg font-semibold text-gray-700">
                    <?php echo $stats['pending']; ?>
                </p>
            </div>
        </div>

        <!-- Total Revenue -->
        <div class="flex items-center p-4 bg-white rounded-lg shadow-xs">
            <div class="p-3 mr-4 text-teal-500 bg-teal-100 rounded-full">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div>
                <p class="mb-2 text-sm font-medium text-gray-600">
                    Total Revenue
                </p>
                <p class="text-lg font-semibold text-gray-700">
                    <?php echo format_money($stats['revenue']); ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="mb-8 bg-white rounded-lg shadow-xs">
        <div class="p-4">
            <form method="GET" class="grid gap-6 md:grid-cols-4">
                <?php if ($customer_id): ?>
                    <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($customer_id); ?>">
                <?php endif; ?>
                
                <label class="block text-sm">
                    <span class="text-gray-700">Status</span>
                    <select name="status" class="block w-full mt-1 text-sm rounded-lg border-gray-300">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </label>

                <label class="block text-sm">
                    <span class="text-gray-700">From Date</span>
                    <input type="date" name="date_from" 
                           value="<?php echo $date_from; ?>"
                           class="block w-full mt-1 text-sm rounded-lg border-gray-300">
                </label>

                <label class="block text-sm">
                    <span class="text-gray-700">To Date</span>
                    <input type="date" name="date_to" 
                           value="<?php echo $date_to; ?>"
                           class="block w-full mt-1 text-sm rounded-lg border-gray-300">
                </label>

                <div class="flex items-end">
                    <button type="submit" 
                            class="px-4 py-2 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 border border-transparent rounded-lg hover:bg-purple-700">
                        Apply Filters
                    </button>
                    <?php if ($status || $date_from || $date_to): ?>
                    <a href="?<?php echo $customer_id ? 'customer_id=' . htmlspecialchars($customer_id) : ''; ?>" 
                       class="px-4 py-2 ml-2 text-sm font-medium leading-5 text-gray-600 transition-colors duration-150 bg-white border border-gray-300 rounded-lg hover:border-gray-500">
                        Clear
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php endif; // End of stats section ?>

    <!-- Orders Table -->
    <?php if (!$hasOrders): ?>
        <div class="bg-white shadow-md rounded my-6 p-6 text-center">
            <p class="text-gray-600">
                <?php if ($user_id): ?>
                    This customer has no orders yet.
                <?php else: ?>
                    No orders found.
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
    <div class="w-full overflow-hidden rounded-lg shadow-xs">
        <div class="w-full overflow-x-auto">
            <table class="w-full whitespace-no-wrap">
                <thead>
                    <tr class="text-xs font-semibold tracking-wide text-left text-gray-500 uppercase border-b bg-gray-50">
                        <th class="px-4 py-3">Order ID</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Vehicles</th>
                        <th class="px-4 py-3">Total</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y">
                    <?php foreach ($orders as $order): ?>
                    <tr class="text-gray-700">
                        <td class="px-4 py-3">
                            <div class="flex items-center text-sm">
                                <div>
                                    <p class="font-semibold">#<?php echo $order['id']; ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <p class="font-semibold"><?php echo htmlspecialchars($order['customer_name']); ?></p>
                            <p class="text-xs text-gray-600"><?php echo htmlspecialchars($order['customer_email']); ?></p>
                            <?php
                            // Debug helper: show linked IDs to diagnose mismatches (visible only to admins)
                            $cid = $order['customer_id'] ?? null;
                            $uid = $order['user_id'] ?? null;
                            if ($cid || $uid) {
                                echo '<div class="text-xs text-gray-500 mt-1">(';
                                if ($cid) echo 'cust_id: ' . intval($cid);
                                if ($cid && $uid) echo ' • ';
                                if ($uid) echo 'user_id: ' . intval($uid);
                                echo ')</div>';
                            }
                            ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php echo $order['total_items']; ?> vehicles
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php echo format_money($order['total_amount']); ?>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <span class="px-2 py-1 font-semibold leading-tight rounded-full 
                                <?php
                                switch ($order['status']) {
                                    case 'pending':
                                        echo 'text-orange-700 bg-orange-100';
                                        break;
                                    case 'active':
                                        echo 'text-green-700 bg-green-100';
                                        break;
                                    case 'completed':
                                        echo 'text-blue-700 bg-blue-100';
                                        break;
                                    case 'cancelled':
                                        echo 'text-red-700 bg-red-100';
                                        break;
                                }
                                ?>">
                                <?php echo ucfirst($order['status']); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php
                                // Prefer order_date if present, otherwise fallback to created_at
                                $display_date = 'N/A';
                                if (!empty(
                                    (isset(
                                        $order['order_date']
                                    ) ? $order['order_date'] : null
                                ))) {
                                    $display_date = date('M j, Y g:i A', strtotime($order['order_date']));
                                } elseif (!empty($order['created_at'])) {
                                    $display_date = date('M j, Y g:i A', strtotime($order['created_at']));
                                }
                                echo $display_date;
                            ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <a href="order_details.php?id=<?php echo $order['id']; ?>" 
                               class="px-3 py-1 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 border border-transparent rounded-md hover:bg-purple-700">
                                View Details
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; // End of orders table condition ?>
</div>

<?php require_once 'includes/footer.php'; ?>
