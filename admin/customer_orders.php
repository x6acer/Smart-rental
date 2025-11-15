<?php
require_once 'includes/auth.php';
require_once '../db_connect.php';

// Validate and sanitize user_id
$user_id = isset($_GET['user_id']) ? filter_var($_GET['user_id'], FILTER_VALIDATE_INT) : null;
if (!$user_id) {
    header('Location: customers.php');
    exit;
}

// Check which columns exist in users table
$userColRes = $conn->prepare("
    SELECT COLUMN_NAME 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'users' 
    AND COLUMN_NAME IN ('name', 'fullname', 'full_name', 'username', 'email')
");
$userColRes->execute();
$userCols = [];
while ($col = $userColRes->fetch(PDO::FETCH_ASSOC)) {
    $userCols[] = 'u.' . $col['COLUMN_NAME'];
}

// Get customer name using available columns
$customerName = 'Unknown Customer';
if (!empty($userCols)) {
    $coalesceFields = implode(', ', $userCols);
    $stmt = $conn->prepare("
        SELECT COALESCE({$coalesceFields}) as customer_name 
        FROM users u 
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $customerData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($customerData) {
        $customerName = $customerData['customer_name'];
    }
}

// Check which date column exists in orders table
$dateColCheck = $conn->prepare("
    SELECT COLUMN_NAME 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'orders' 
    AND COLUMN_NAME IN ('created_at', 'order_date')
");
$dateColCheck->execute();
$dateCols = [];
while ($col = $dateColCheck->fetch(PDO::FETCH_ASSOC)) {
    $dateCols[$col['COLUMN_NAME']] = true;
}

// Determine sort column
$orderBy = 'o.id DESC';
if (isset($dateCols['order_date'])) {
    $orderBy = 'o.order_date DESC';
} elseif (isset($dateCols['created_at'])) {
    $orderBy = 'o.created_at DESC';
}

// Build the date column selection based on what exists
$dateSelect = [];
foreach (['order_date', 'created_at'] as $dateCol) {
    if (isset($dateCols[$dateCol])) {
        $dateSelect[] = "o.$dateCol";
    }
}
$dateSelectStr = $dateSelect ? implode(', ', $dateSelect) : 'NULL as created_at';

// Build query based on schema
$query = "
    SELECT o.id, o.status, o.total_amount, 
           $dateSelectStr,
           COUNT(oi.id) as total_items
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE o.user_id = ?
    GROUP BY o.id" . ($dateSelect ? ", " . implode(', ', $dateSelect) : "") . "
    ORDER BY " . $orderBy;

$stmt = $conn->prepare($query);
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Orders for: " . htmlspecialchars($customerName);
require_once 'includes/header.php';
?>

<div class="container px-6 mx-auto grid">
    <div class="flex justify-between items-center my-6">
        <h2 class="text-2xl font-semibold text-gray-700">
            Orders for: <?php echo htmlspecialchars($customerName); ?>
        </h2>
        <a href="customers.php" class="px-4 py-2 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200">
            ← Back to Customers
        </a>
    </div>

    <?php if (empty($orders)): ?>
        <div class="bg-white shadow-md rounded my-6 p-6 text-center">
            <p class="text-gray-600">This customer has no orders yet.</p>
        </div>
    <?php else: ?>
        <div class="w-full overflow-hidden rounded-lg shadow-xs">
            <div class="w-full overflow-x-auto">
                <table class="w-full whitespace-no-wrap">
                    <thead>
                        <tr class="text-xs font-semibold tracking-wide text-left text-gray-500 uppercase border-b bg-gray-50">
                            <th class="px-4 py-3">Order #</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Total</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y">
                        <?php foreach ($orders as $order): ?>
                            <tr class="text-gray-700">
                                <td class="px-4 py-3">
                                    #<?php echo htmlspecialchars($order['id']); ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <?php 
                                    $display_date = '';
                                    // Try order_date first if it exists
                                    if (isset($dateCols['order_date']) && !empty($order['order_date'])) {
                                        $display_date = date('M j, Y g:i A', strtotime($order['order_date']));
                                    }
                                    // Fall back to created_at if order_date is not available
                                    elseif (isset($dateCols['created_at']) && !empty($order['created_at'])) {
                                        $display_date = date('M j, Y g:i A', strtotime($order['created_at']));
                                    }
                                    echo $display_date ?: 'N/A';
                                    ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <span class="px-2 py-1 font-semibold leading-tight rounded-full 
                                        <?php
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
                                        <?php echo htmlspecialchars(ucfirst($order['status'])); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    ₵<?php echo number_format($order['total_amount'] ?? 0, 2); ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <a href="order_details.php?id=<?php echo $order['id']; ?>" 
                                       class="px-3 py-1 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 border border-transparent rounded-md hover:bg-purple-700">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>