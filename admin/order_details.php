<?php
require_once 'includes/auth.php';
require_once '../db_connect.php';

// Get order ID
$order_id = $_GET['id'] ?? null;
if (!$order_id) {
    header('Location: orders.php');
    exit;
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $new_status = $_POST['status'];
        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $order_id]);
        
        // Redirect to prevent form resubmission
        header('Location: order_details.php?id=' . $order_id);
        exit;
    }
}

// Get order details with customer info (schema-aware: support orders.customer_id or orders.user_id)
$colRes = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME IN ('customer_id','user_id')");
$colRes->execute();
$orderCols = [];
while ($r = $colRes->fetch(PDO::FETCH_ASSOC)) {
    $orderCols[$r['COLUMN_NAME']] = true;
}

// Check if customers table exists
$tblRes = $conn->prepare("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers'");
$tblRes->execute();
$hasCustomers = ($tblRes->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;

// Check if users table exists
$tblRes2 = $conn->prepare("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
$tblRes2->execute();
$hasUsers = ($tblRes2->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;

$selectCust = "NULL as customer_name, NULL as customer_email, NULL as customer_phone, NULL as customer_address";
$join = '';
// Prefer joining to customers table if it exists and orders has either customer_id or user_id
if ($hasCustomers && (!empty($orderCols['customer_id']) || !empty($orderCols['user_id']))) {
    $joinKey = !empty($orderCols['customer_id']) ? 'customer_id' : 'user_id';
    // detect customer columns
    $custColRes = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME IN ('name','fullname','full_name','email','phone','address','username')");
    $custColRes->execute();
    $custCols = [];
    while ($rr = $custColRes->fetch(PDO::FETCH_ASSOC)) $custCols[$rr['COLUMN_NAME']] = true;
    $custNameCol = isset($custCols['name']) ? 'name' : (isset($custCols['fullname']) ? 'fullname' : (isset($custCols['full_name']) ? 'full_name' : (isset($custCols['username']) ? 'username' : null)));
    $custEmailCol = isset($custCols['email']) ? 'email' : null;
    $custPhoneCol = isset($custCols['phone']) ? 'phone' : null;
    $custAddrCol = isset($custCols['address']) ? 'address' : null;

    $parts = [];
    $parts[] = $custNameCol ? "c.$custNameCol as customer_name" : "NULL as customer_name";
    $parts[] = $custEmailCol ? "c.$custEmailCol as customer_email" : "NULL as customer_email";
    $parts[] = $custPhoneCol ? "c.$custPhoneCol as customer_phone" : "NULL as customer_phone";
    $parts[] = $custAddrCol ? "c.$custAddrCol as customer_address" : "NULL as customer_address";
    $selectCust = implode(', ', $parts);
    $join = "LEFT JOIN customers c ON o.$joinKey = c.id";
} elseif (!empty($orderCols['user_id']) && $hasUsers) {
    // detect customer columns
    $custColRes = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME IN ('name','fullname','full_name','email','phone','address')");
    $custColRes->execute();
    $custCols = [];
    while ($rr = $custColRes->fetch(PDO::FETCH_ASSOC)) $custCols[$rr['COLUMN_NAME']] = true;
    $custNameCol = isset($custCols['name']) ? 'name' : (isset($custCols['fullname']) ? 'fullname' : (isset($custCols['full_name']) ? 'full_name' : (isset($custCols['username']) ? 'username' : null)));
    $custEmailCol = isset($custCols['email']) ? 'email' : null;
    $custPhoneCol = isset($custCols['phone']) ? 'phone' : null;
    $custAddrCol = isset($custCols['address']) ? 'address' : null;

    $parts = [];
    $parts[] = $custNameCol ? "c.$custNameCol as customer_name" : "NULL as customer_name";
    $parts[] = $custEmailCol ? "c.$custEmailCol as customer_email" : "NULL as customer_email";
    $parts[] = $custPhoneCol ? "c.$custPhoneCol as customer_phone" : "NULL as customer_phone";
    $parts[] = $custAddrCol ? "c.$custAddrCol as customer_address" : "NULL as customer_address";
    $selectCust = implode(', ', $parts);
    $join = "LEFT JOIN customers c ON o.customer_id = c.id";
} elseif (!empty($orderCols['user_id']) && $hasUsers) {
    // detect user columns and map best-effort
    $userColRes = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('name','fullname','full_name','username','email','phone','address')");
    $userColRes->execute();
    $userCols = [];
    while ($rr = $userColRes->fetch(PDO::FETCH_ASSOC)) $userCols[$rr['COLUMN_NAME']] = true;
    $userNameCol = isset($userCols['name']) ? 'name' : (isset($userCols['fullname']) ? 'fullname' : (isset($userCols['full_name']) ? 'full_name' : (isset($userCols['username']) ? 'username' : null)));
    $userEmailCol = isset($userCols['email']) ? 'email' : null;
    $userPhoneCol = isset($userCols['phone']) ? 'phone' : null;
    $userAddrCol = isset($userCols['address']) ? 'address' : null;

    $parts = [];
    $parts[] = $userNameCol ? "u.$userNameCol as customer_name" : "NULL as customer_name";
    $parts[] = $userEmailCol ? "u.$userEmailCol as customer_email" : "NULL as customer_email";
    $parts[] = $userPhoneCol ? "u.$userPhoneCol as customer_phone" : "NULL as customer_phone";
    $parts[] = $userAddrCol ? "u.$userAddrCol as customer_address" : "NULL as customer_address";
    $selectCust = implode(', ', $parts);
    $join = "LEFT JOIN users u ON o.user_id = u.id";
}

$colDatesRes = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME IN ('created_at','start_date','end_date')");
$colDatesRes->execute();
$hasDateCols = [];
while ($rr = $colDatesRes->fetch(PDO::FETCH_ASSOC)) $hasDateCols[$rr['COLUMN_NAME']] = true;

$selectDateParts = [];
$selectDateParts[] = !empty($hasDateCols['created_at']) ? "o.created_at as created_at" : "NULL as created_at";
$selectDateParts[] = !empty($hasDateCols['start_date']) ? "o.start_date as start_date" : "NULL as start_date";
$selectDateParts[] = !empty($hasDateCols['end_date']) ? "o.end_date as end_date" : "NULL as end_date";

$sql = "SELECT o.*, " . implode(', ', $selectDateParts) . ", " . $selectCust . " FROM orders o " . $join . " WHERE o.id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Get order items with rental details (schema-aware)
$rentColRes = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME IN ('name','title','image','daily_rate','price_per_day')");
$rentColRes->execute();
$rentCols = [];
while ($rr = $rentColRes->fetch(PDO::FETCH_ASSOC)) $rentCols[$rr['COLUMN_NAME']] = true;
$rentalNameCol = isset($rentCols['name']) ? 'name' : (isset($rentCols['title']) ? 'title' : null);
$rentalImageCol = isset($rentCols['image']) ? 'image' : null;

$selectRentalParts = [];
$selectRentalParts[] = 'oi.*';
$selectRentalParts[] = $rentalNameCol ? "r.$rentalNameCol as rental_name" : "NULL as rental_name";
$selectRentalParts[] = $rentalImageCol ? "r.$rentalImageCol as rental_image" : "NULL as rental_image";

$sql = "SELECT " . implode(', ', $selectRentalParts) . " FROM order_items oi JOIN rentals r ON oi.rental_id = r.id WHERE oi.order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$order_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Order Details #" . $order_id;
require_once 'includes/header.php';
?>

<div class="container px-6 mx-auto grid">
    <div class="flex justify-between items-center my-6">
        <h2 class="text-2xl font-semibold text-gray-700">
            Order #<?php echo $order_id; ?>
        </h2>
        <a href="orders.php" class="flex items-center justify-between px-4 py-2 text-sm font-medium leading-5 text-white transition-colors duration-150 bg-purple-600 border border-transparent rounded-lg hover:bg-purple-700">
            <svg class="w-4 h-4 mr-2 -ml-1" fill="currentColor" aria-hidden="true" viewBox="0 0 20 20">
                <path d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" fill-rule="evenodd"></path>
            </svg>
            Back to Orders
        </a>
    </div>

    <!-- Order Summary -->
    <div class="grid gap-6 mb-8 md:grid-cols-2">
        <!-- Customer Information -->
        <div class="p-4 bg-white rounded-lg shadow-xs">
            <h4 class="mb-4 font-semibold text-gray-600">Customer Information</h4>
            <div class="grid gap-4">
                <div class="flex justify-between">
                    <span class="text-sm font-medium text-gray-600">Name:</span>
                    <span class="text-sm text-gray-500"><?php echo htmlspecialchars($order['customer_name']); ?></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm font-medium text-gray-600">Email:</span>
                    <span class="text-sm text-gray-500"><?php echo htmlspecialchars($order['customer_email']); ?></span>
                </div>
                <?php if ($order['customer_phone']): ?>
                <div class="flex justify-between">
                    <span class="text-sm font-medium text-gray-600">Phone:</span>
                    <span class="text-sm text-gray-500"><?php echo htmlspecialchars($order['customer_phone']); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($order['customer_address']): ?>
                <div class="flex justify-between">
                    <span class="text-sm font-medium text-gray-600">Address:</span>
                    <span class="text-sm text-gray-500"><?php echo htmlspecialchars($order['customer_address']); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Order Information -->
        <div class="p-4 bg-white rounded-lg shadow-xs">
            <h4 class="mb-4 font-semibold text-gray-600">Order Information</h4>
            <div class="grid gap-4">
                <div class="flex justify-between">
                    <span class="text-sm font-medium text-gray-600">Order Date:</span>
                    <span class="text-sm text-gray-500">
                        <?php
                        $createdAt = $order['created_at'] ?? null;
                        if ($createdAt) {
                            echo date('F j, Y g:i A', strtotime($createdAt));
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm font-medium text-gray-600">Rental Period:</span>
                    <span class="text-sm text-gray-500">
                        <?php
                        $startRaw = $order['start_date'] ?? null;
                        $endRaw = $order['end_date'] ?? null;
                        $start = $startRaw ? date('M j, Y', strtotime($startRaw)) : 'N/A';
                        $end = $endRaw ? date('M j, Y', strtotime($endRaw)) : 'N/A';
                        echo $start . ' - ' . $end;
                        ?>
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm font-medium text-gray-600">Status:</span>
                    <form method="POST" class="inline-flex">
                        <input type="hidden" name="action" value="update_status">
                        <select name="status" onchange="this.form.submit()"
                                class="text-sm rounded-lg border-gray-300 focus:border-purple-400 focus:outline-none focus:shadow-outline-purple">
                            <option value="pending" <?php echo $order['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="active" <?php echo $order['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="completed" <?php echo $order['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $order['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </form>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm font-medium text-gray-600">Total Amount:</span>
                    <span class="text-sm font-semibold text-gray-800">
                        <?php echo format_money($order['total_amount']); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Order Items -->
    <div class="w-full overflow-hidden rounded-lg shadow-xs">
        <div class="w-full overflow-x-auto">
            <table class="w-full whitespace-no-wrap">
                <thead>
                    <tr class="text-xs font-semibold tracking-wide text-left text-gray-500 uppercase border-b bg-gray-50">
                    <th class="px-4 py-3">Vehicle</th>
                    <th class="px-4 py-3">Rental Days</th>
                    <th class="px-4 py-3">Rate / Day</th>
                    <th class="px-4 py-3">Total</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y">
                    <?php foreach ($items as $item): ?>
                    <tr class="text-gray-700">
                        <td class="px-4 py-3">
                            <div class="flex items-center text-sm">
                                <?php if ($item['rental_image']): ?>
                                <div class="relative hidden w-8 h-8 mr-3 rounded-full md:block">
                                    <img class="object-cover w-full h-full rounded-full" 
                                         src="uploads/thumbs/<?php echo htmlspecialchars($item['rental_image']); ?>"
                                         alt="<?php echo htmlspecialchars($item['rental_name']); ?>">
                                </div>
                                <?php endif; ?>
                                <div>
                                    <p class="font-semibold"><?php echo htmlspecialchars($item['rental_name']); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php echo $item['days']; ?> days
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php echo format_money($item['daily_rate']); ?>
                        </td>
                        <td class="px-4 py-3 text-sm">
                            <?php echo format_money($item['total_amount']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <!-- Total Row -->
                    <tr class="text-gray-700 bg-gray-50">
                        <td colspan="3" class="px-4 py-3 text-sm font-semibold text-right">
                            Total Amount:
                        </td>
                        <td class="px-4 py-3 text-sm font-bold">
                            <?php echo format_money($order['total_amount']); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($order['notes'] ?? null)): ?>
    <!-- Order Notes -->
    <div class="mt-8 p-4 bg-white rounded-lg shadow-xs">
        <h4 class="mb-4 font-semibold text-gray-600">Notes</h4>
        <p class="text-sm text-gray-600"><?php echo nl2br(htmlspecialchars($order['notes'] ?? '')); ?></p>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
