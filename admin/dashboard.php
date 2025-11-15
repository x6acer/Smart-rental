<?php
require_once 'includes/auth.php';
require_once '../db_connect.php';

// Get total rentals. Some installations may not have the `is_deleted` column
// (older schema). Check INFORMATION_SCHEMA for the column and adapt the
// query so the dashboard doesn't fatal when the column is absent.
$colCheck = $conn->prepare(
    "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME = 'is_deleted'"
);
$colCheck->execute();
$colRow = $colCheck->fetch(PDO::FETCH_ASSOC);
$hasIsDeleted = !empty($colRow['cnt']);

if ($hasIsDeleted) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM rentals WHERE is_deleted = 0");
} else {
    // Fall back to counting all rentals if the soft-delete column doesn't exist
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM rentals");
}
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$total_rentals = $row ? $row['total'] : 0;

// Get total active orders
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM orders WHERE status = 'active'");
$stmt->execute();
$active_orders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get total customers
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM customers");
$stmt->execute();
$total_customers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get recent orders with customer details. Some schemas may not have
// orders.customer_id (older or customized). Detect the column and
// adapt the query to avoid SQL errors.
// Detect whether orders link to customers (customer_id) or users (user_id)
$colCheckOrders = $conn->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME IN ('customer_id','user_id')"
);
$colCheckOrders->execute();
$orderCols = [];
while ($r = $colCheckOrders->fetch(PDO::FETCH_ASSOC)) $orderCols[$r['COLUMN_NAME']] = true;

// check existence of customers and users tables
$tblRes = $conn->prepare("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers'");
$tblRes->execute();
$hasCustomers = ($tblRes->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
$tblRes2 = $conn->prepare("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
$tblRes2->execute();
$hasUsers = ($tblRes2->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;

// Determine which join to use for customer display
$ordersHasCustomerId = !empty($orderCols['customer_id']) && $hasCustomers;
$ordersHasUserId = !empty($orderCols['user_id']) && $hasUsers;

// Check if orders.created_at exists; if not, fall back to ordering by id
$colCheckOrdersCreated = $conn->prepare(
    "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'created_at'"
);
$colCheckOrdersCreated->execute();
$colRowOrdersCreated = $colCheckOrdersCreated->fetch(PDO::FETCH_ASSOC);
$ordersHasCreatedAt = !empty($colRowOrdersCreated['cnt']);
$orderBy = $ordersHasCreatedAt ? 'o.created_at DESC' : 'o.id DESC';

$selectCust = "NULL as customer_name";
$join = '';
if ($ordersHasCustomerId) {
    // try to find a name-like column in customers
    $custColRes = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME IN ('name','fullname','full_name','username')");
    $custColRes->execute();
    $custCols = [];
    while ($rr = $custColRes->fetch(PDO::FETCH_ASSOC)) $custCols[$rr['COLUMN_NAME']] = true;
    $custNameCol = isset($custCols['name']) ? 'name' : (isset($custCols['fullname']) ? 'fullname' : (isset($custCols['full_name']) ? 'full_name' : (isset($custCols['username']) ? 'username' : null)));
    $selectCust = $custNameCol ? "c.$custNameCol as customer_name" : "NULL as customer_name";
    $join = "LEFT JOIN customers c ON o.customer_id = c.id";
} elseif ($ordersHasUserId) {
    $userColRes = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('name','fullname','full_name','username')");
    $userColRes->execute();
    $userCols = [];
    while ($rr = $userColRes->fetch(PDO::FETCH_ASSOC)) $userCols[$rr['COLUMN_NAME']] = true;
    $userNameCol = isset($userCols['name']) ? 'name' : (isset($userCols['fullname']) ? 'fullname' : (isset($userCols['full_name']) ? 'full_name' : (isset($userCols['username']) ? 'username' : null)));
    $selectCust = $userNameCol ? "u.$userNameCol as customer_name" : "NULL as customer_name";
    $join = "LEFT JOIN users u ON o.user_id = u.id";
} else {
    $selectCust = "'Unknown' as customer_name";
}

$sql = "SELECT o.*, " . $selectCust . " FROM orders o " . $join . " ORDER BY $orderBy LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->execute();
$recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ensure every order has a customer_name key to avoid undefined index in the template
foreach ($recent_orders as &$ord) {
    if (!isset($ord['customer_name'])) {
        $ord['customer_name'] = 'Unknown';
    }
}
unset($ord);

$page_title = "Dashboard";
require_once 'includes/header.php';
?>

<div class="container px-6 mx-auto grid">
    <h2 class="my-6 text-2xl font-semibold text-gray-700">
        Dashboard
    </h2>

    <!-- Cards -->
    <div class="grid gap-6 mb-8 md:grid-cols-3">
        <!-- Total Rentals -->
        <div class="flex items-center p-4 bg-white rounded-lg shadow-xs">
            <div class="p-3 mr-4 text-orange-500 bg-orange-100 rounded-full">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"></path>
                </svg>
            </div>
            <div>
                <p class="mb-2 text-sm font-medium text-gray-600">
                    Total Rentals
                </p>
                <p class="text-lg font-semibold text-gray-700">
                    <?php echo $total_rentals; ?>
                </p>
            </div>
        </div>

        <!-- Active Orders -->
        <div class="flex items-center p-4 bg-white rounded-lg shadow-xs">
            <div class="p-3 mr-4 text-green-500 bg-green-100 rounded-full">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4 4a2 2 0 00-2 2v4a2 2 0 002 2V6h10a2 2 0 00-2-2H4zm2 6a2 2 0 012-2h8a2 2 0 012 2v4a2 2 0 01-2 2H8a2 2 0 01-2-2v-4zm6 4a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"></path>
                </svg>
            </div>
            <div>
                <p class="mb-2 text-sm font-medium text-gray-600">
                    Active Orders
                </p>
                <p class="text-lg font-semibold text-gray-700">
                    <?php echo $active_orders; ?>
                </p>
            </div>
        </div>

        <!-- Total Customers -->
        <div class="flex items-center p-4 bg-white rounded-lg shadow-xs">
            <div class="p-3 mr-4 text-blue-500 bg-blue-100 rounded-full">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M3 1a1 1 0 000 2h1.22l.305 1.222a.997.997 0 00.01.042l1.358 5.43-.893.892C3.74 11.846 4.632 14 6.414 14H15a1 1 0 000-2H6.414l1-1H14a1 1 0 00.894-.553l3-6A1 1 0 0017 3H6.28l-.31-1.243A1 1 0 005 1H3zM16 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM6.5 18a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"></path>
                </svg>
            </div>
            <div>
                <p class="mb-2 text-sm font-medium text-gray-600">
                    Total Customers
                </p>
                <p class="text-lg font-semibold text-gray-700">
                    <?php echo $total_customers; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Recent Orders -->
    <div class="w-full overflow-hidden rounded-lg shadow-xs">
        <div class="w-full overflow-x-auto">
            <h4 class="mb-4 text-lg font-semibold text-gray-600">
                Recent Orders
            </h4>
            <table class="w-full whitespace-no-wrap">
                <thead>
                    <tr class="text-xs font-semibold tracking-wide text-left text-gray-500 uppercase border-b bg-gray-50">
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Amount</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y">
                    <?php foreach ($recent_orders as $order): ?>
                    <tr class="text-gray-700">
                        <td class="px-4 py-3">
                            <div class="flex items-center text-sm">
                                <div>
                                        <p class="font-semibold"><?php echo htmlspecialchars($order['customer_name']); ?></p>
                                        <?php
                                        // Debug: show linked IDs so we can tell whether the order points to a customer or a user (helps diagnose 'ADMIN' showing)
                                        $debugParts = [];
                                        if (isset($order['customer_id'])) $debugParts[] = 'cust_id: '.htmlspecialchars($order['customer_id']);
                                        if (isset($order['user_id'])) $debugParts[] = 'user_id: '.htmlspecialchars($order['user_id']);
                                        if (!empty($debugParts)) {
                                            echo '<p class="text-xs text-gray-500">(' . implode(' • ', $debugParts) . ')</p>';
                                        }
                                        ?>
                                    </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php echo format_money($order['total_amount']); ?>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <span class="px-2 py-1 font-semibold leading-tight rounded-full <?php 
                                switch(strtolower($order['status'])) {
                                    case 'active':
                                        echo 'text-green-700 bg-green-100';
                                        break;
                                    case 'completed':
                                        echo 'text-blue-700 bg-blue-100';
                                        break;
                                    case 'pending':
                                        echo 'text-orange-700 bg-orange-100';
                                        break;
                                    case 'cancelled':
                                        echo 'text-red-700 bg-red-100';
                                        break;
                                    default:
                                        echo 'text-gray-700 bg-gray-100';
                                }
                                ?>">
                                <?php echo ucfirst($order['status']); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php
                            // created_at may not exist in some schemas; fall back to 'N/A'
                            if (!empty($order['created_at'])) {
                                echo date('M j, Y', strtotime($order['created_at']));
                            } else {
                                echo 'N/A';
                            }
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
</div>

<?php require_once 'includes/footer.php'; ?>
