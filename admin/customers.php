<?php
require_once 'includes/auth.php';
require_once '../db_connect.php';
$db = db_get_conn();

// Check for required columns
$colCheckSql = "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND (
        (TABLE_NAME = 'orders' AND COLUMN_NAME IN ('created_at', 'customer_id', 'status'))
        OR (TABLE_NAME = 'customers' AND COLUMN_NAME = 'created_at')
    )";
$colCheckResult = $db->query($colCheckSql);
$hasColumn = [];
while ($row = $colCheckResult->fetch_assoc()) {
    $hasColumn[$row['TABLE_NAME']][$row['COLUMN_NAME']] = true;
}

// Determine best way to join orders to customers based on existing schema
$joins = "";
$orderJoinCondition = null;

// Check if users table exists and whether it has customer_id
$tblRes = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
$hasUsersTable = false;
if ($tblRes) {
    $r = $tblRes->fetch_assoc();
    $hasUsersTable = !empty($r['cnt']);
}

$hasUsersCustomerId = false;
if ($hasUsersTable) {
    $uColRes = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'customer_id'");
    if ($uColRes) {
        $ur = $uColRes->fetch_assoc();
        $hasUsersCustomerId = !empty($ur['cnt']);
    }
}

// Prefer joining through users->customer_id->orders.user_id when available
if ($hasUsersTable && $hasUsersCustomerId) {
    $joins = "LEFT JOIN users u ON c.id = u.customer_id\n    LEFT JOIN orders o ON u.id = o.user_id";
    $orderJoinCondition = "o.user_id IS NOT NULL";
} elseif (!empty($hasColumn['orders']['customer_id'])) {
    // orders has customer_id directly
    $joins = "LEFT JOIN orders o ON c.id = o.customer_id";
    $orderJoinCondition = "o.customer_id IS NOT NULL";
} else {
    // Fallback: join orders.user_id to customers.id (common in older schemas)
    $joins = "LEFT JOIN orders o ON c.id = o.user_id";
    $orderJoinCondition = "o.user_id IS NOT NULL";
}

// Build select
$orderDateRes = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'order_date'");
$hasOrderDateCol = false;
if ($orderDateRes) {
    $ordr = $orderDateRes->fetch_assoc();
    $hasOrderDateCol = !empty($ordr['cnt']);
}

$lastDateSelect = $hasOrderDateCol ? "MAX(o.order_date) as last_order_date" : (isset($hasColumn['orders']['created_at']) ? "MAX(o.created_at) as last_order_date" : "NULL as last_order_date");

$query = "SELECT c.*,\n       COUNT(DISTINCT o.id) as total_orders,\n       COALESCE(SUM(o.total_amount), 0) as total_spent,\n       " . $lastDateSelect . "\n    FROM customers c\n    " . $joins;

// If status column exists, only count non-cancelled orders
if (isset($hasColumn['orders']['status'])) {
    $query .= " WHERE (o.id IS NULL OR o.status != 'cancelled')";
}

$query .= " GROUP BY c.id";

// Order by created_at if it exists, otherwise by id
if (isset($hasColumn['customers']['created_at'])) {
    $query .= " ORDER BY c.created_at DESC";
} else {
    $query .= " ORDER BY c.id DESC";
}

$result = $db->query($query);
$customers = [];
while ($row = $result->fetch_assoc()) {
    $customers[] = $row;
}

$page_title = "Customers";
require_once 'includes/header.php';
?>

<div class="container px-6 mx-auto grid">
        <h2 class="my-6 text-2xl font-semibold text-gray-700">
        Customer Management
    </h2>

    <?php
    // If an admin requested to view orders for a specific customer, show them here.
    if (isset($_GET['view_orders']) && is_numeric($_GET['view_orders'])) {
        $view_id = intval($_GET['view_orders']);

        // Fetch customer name safely
        $cstmt = $db->prepare("SELECT name FROM customers WHERE id = ? LIMIT 1");
        $cstmt->bind_param('i', $view_id);
        $cstmt->execute();
        $cres = $cstmt->get_result();
        $crow = $cres ? $cres->fetch_assoc() : null;
        $customer_name = $crow['name'] ?? 'Customer ' . $view_id;

        echo '<div class="mb-6">';
        echo '<h4 class="text-xl font-semibold mb-3">Orders for: ' . htmlspecialchars($customer_name) . '</h4>';

        // Determine how to query orders for this customer depending on schema
        $colCheck = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME IN ('customer_id','user_id','order_date','created_at')");
        $orderCols = [];
        if ($colCheck) {
            while ($r = $colCheck->fetch_assoc()) $orderCols[$r['COLUMN_NAME']] = true;
        }

        $orders = null;

        if (!empty($orderCols['customer_id'])) {
            // orders has customer_id directly
            $stmt = $db->prepare("SELECT o.* FROM orders o WHERE o.customer_id = ? ORDER BY " . (!empty($orderCols['order_date']) ? 'o.order_date' : ( !empty($orderCols['created_at']) ? 'o.created_at' : 'o.id')) . " DESC");
            $stmt->bind_param('i', $view_id);
            $stmt->execute();
            $orders = $stmt->get_result();
        } else {
            // Try to resolve user_id from users table (users.customer_id = customers.id)
            // Only attempt this when we previously detected the users table has customer_id
            if (!empty($hasUsersTable) && !empty($hasUsersCustomerId)) {
                $uStmt = $db->prepare("SELECT id FROM users WHERE customer_id = ? LIMIT 1");
                $uStmt->bind_param('i', $view_id);
                $uStmt->execute();
                $ures = $uStmt->get_result();
                $urow = $ures ? $ures->fetch_assoc() : null;
                if ($urow && !empty($urow['id'])) {
                    $uid = intval($urow['id']);
                    $stmt = $db->prepare("SELECT o.* FROM orders o WHERE o.user_id = ? ORDER BY " . (!empty($orderCols['order_date']) ? 'o.order_date' : ( !empty($orderCols['created_at']) ? 'o.created_at' : 'o.id')) . " DESC");
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $orders = $stmt->get_result();
                }
            }

            // Fallback: try matching orders.user_id = customer id (some schemas store customer id in user_id)
            if ($orders === null) {
                $stmt = $db->prepare("SELECT o.* FROM orders o WHERE o.user_id = ? ORDER BY " . (!empty($orderCols['order_date']) ? 'o.order_date' : ( !empty($orderCols['created_at']) ? 'o.created_at' : 'o.id')) . " DESC");
                $stmt->bind_param('i', $view_id);
                $stmt->execute();
                $orders = $stmt->get_result();
            }
        }

        // Render orders table or empty message
        if ($orders && $orders->num_rows > 0) {
            echo '<div class="w-full overflow-hidden rounded-lg shadow-xs mb-6">';
            echo '<div class="w-full overflow-x-auto">';
            echo '<table class="w-full whitespace-no-wrap">';
            echo '<thead><tr class="text-xs font-semibold tracking-wide text-left text-gray-500 uppercase border-b bg-gray-50">';
            echo '<th class="px-4 py-3">Order #</th>';
            echo '<th class="px-4 py-3">Date</th>';
            echo '<th class="px-4 py-3">Status</th>';
            echo '<th class="px-4 py-3">Total</th>';
            echo '<th class="px-4 py-3">Actions</th>';
            echo '</tr></thead><tbody class="bg-white divide-y">';
            while ($o = $orders->fetch_assoc()) {
                $od = !empty($o['order_date']) ? $o['order_date'] : (!empty($o['created_at']) ? $o['created_at'] : '');
                echo '<tr class="text-gray-700">';
                echo '<td class="px-4 py-3 text-sm">#' . htmlspecialchars($o['id']) . '</td>';
                echo '<td class="px-4 py-3 text-sm">' . ($od ? date('M j, Y g:i A', strtotime($od)) : 'N/A') . '</td>';
                echo '<td class="px-4 py-3 text-sm">' . htmlspecialchars($o['status'] ?? 'N/A') . '</td>';
                echo '<td class="px-4 py-3 text-sm">' . format_money($o['total_amount'] ?? 0) . '</td>';
                echo '<td class="px-4 py-3 text-sm"><a href="orders.php?view=' . $o['id'] . '&customer_id=' . $view_id . '" class="text-purple-600 hover:underline">View</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div></div>';
            

        } else {
            echo '<div class="mb-6 p-4 bg-white rounded">No orders yet for this customer.</div>';
        }

        echo '</div>';
    }
    ?>

    <!-- Customer Stats -->
    <div class="grid gap-6 mb-8 md:grid-cols-3">
        <!-- Total Customers -->
        <div class="flex items-center p-4 bg-white rounded-lg shadow-xs">
            <div class="p-3 mr-4 text-orange-500 bg-orange-100 rounded-full">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"></path>
                </svg>
            </div>
            <div>
                <p class="mb-2 text-sm font-medium text-gray-600">
                    Total Customers
                </p>
                <p class="text-lg font-semibold text-gray-700">
                    <?php echo count($customers); ?>
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
                    <?php 
                    if (isset($hasColumn['orders']['customer_id'])) {
                        $res = $db->query("SELECT COUNT(*) as count FROM orders WHERE status = 'active'");
                        $row = $res ? $res->fetch_assoc() : null;
                        echo $row['count'] ?? 0;
                    } else {
                        echo '0';
                    }
                    ?>
                </p>
            </div>
        </div>

        <!-- Total Revenue -->
        <div class="flex items-center p-4 bg-white rounded-lg shadow-xs">
            <div class="p-3 mr-4 text-blue-500 bg-blue-100 rounded-full">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M3 1a1 1 0 000 2h1.22l.305 1.222a.997.997 0 00.01.042l1.358 5.43-.893.892C3.74 11.846 4.632 14 6.414 14H15a1 1 0 000-2H6.414l1-1H14a1 1 0 00.894-.553l3-6A1 1 0 0017 3H6.28l-.31-1.243A1 1 0 005 1H3zM16 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM6.5 18a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"></path>
                </svg>
            </div>
            <div>
                <p class="mb-2 text-sm font-medium text-gray-600">
                    Total Revenue
                </p>
                <p class="text-lg font-semibold text-gray-700">
                    <?php 
                    if (isset($hasColumn['orders']['customer_id'])) {
                        $res2 = $db->query("SELECT SUM(total_amount) as total FROM orders WHERE status != 'cancelled'");
                        $row2 = $res2 ? $res2->fetch_assoc() : null;
                        $total = $row2['total'] ?? 0;
                        echo format_money($total);
                    } else {
                        echo format_money(0);
                    }
                    ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Customers Table -->
    <div class="w-full overflow-hidden rounded-lg shadow-xs">
        <div class="w-full overflow-x-auto">
            <table class="w-full whitespace-no-wrap">
                <thead>
                    <tr class="text-xs font-semibold tracking-wide text-left text-gray-500 uppercase border-b bg-gray-50">
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Orders</th>
                        <th class="px-4 py-3">Total Spent</th>
                        <th class="px-4 py-3">Last Order</th>
                        <th class="px-4 py-3">Joined</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y">
                    <?php foreach ($customers as $customer): ?>
                    <tr class="text-gray-700">
                        <td class="px-4 py-3">
                            <div class="flex items-center text-sm">
                                <div>
                                    <p class="font-semibold"><?php echo htmlspecialchars($customer['name']); ?></p>
                                    <?php if ($customer['phone']): ?>
                                    <p class="text-xs text-gray-600"><?php echo htmlspecialchars($customer['phone']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php echo htmlspecialchars($customer['email']); ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php echo intval($customer['total_orders']); ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php echo format_money($customer['total_spent'] ?? 0); ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php 
                            if (!isset($hasColumn['orders']['customer_id'])) {
                                echo 'N/A';
                            } else {
                                echo $customer['last_order_date'] ? date('M j, Y', strtotime($customer['last_order_date'])) : 'Never';
                            }
                            ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php 
                            echo isset($customer['created_at']) && $customer['created_at'] 
                                ? date('M j, Y', strtotime($customer['created_at'])) 
                                : 'N/A'; 
                            ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <div class="flex items-center space-x-4">
                                <a href="customer_orders.php?user_id=<?php echo $customer['id']; ?>" 
                                   class="px-3 py-1 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 border border-transparent rounded-md hover:bg-purple-700">
                                    View Orders
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
