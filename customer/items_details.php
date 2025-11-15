<?php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/includes/rental_status.php';
$db = db_get_conn();

// Detect rental column names for compatibility across schema variants
$colNameRes = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME = 'name'");
$hasName = false;
if ($colNameRes) {
    $rr = $colNameRes->fetch_assoc();
    $hasName = !empty($rr['cnt']);
}

$colDailyRes = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME = 'daily_rate'");
$hasDaily = false;
if ($colDailyRes) {
    $rr2 = $colDailyRes->fetch_assoc();
    $hasDaily = !empty($rr2['cnt']);
}

// Detect whether categories use 'slug' (used by header links)
$colCatSlugRes = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'slug'");
$hasCategorySlug = false;
if ($colCatSlugRes) {
    $rcs = $colCatSlugRes->fetch_assoc();
    $hasCategorySlug = !empty($rcs['cnt']);
}

// Get single rental if ID is provided
if (isset($_GET['id'])) {

    // Build SELECT depending on available columns
    // Check if is_deleted column exists
    $delColRes = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME = 'is_deleted'");
    $hasIsDeleted = false;
    if ($delColRes) {
        $r3 = $delColRes->fetch_assoc();
        $hasIsDeleted = !empty($r3['cnt']);
    }

    // Build where conditions for non-deleted items
    $where = ['r.id = ?'];
    if ($hasIsDeleted) {
        $where[] = "(r.is_deleted = 0 OR r.is_deleted IS NULL)";
    }
    $where[] = "(r.status IS NULL OR r.status = '' OR LOWER(r.status) != 'deleted')";

    // Build base query with correct schema columns
    if ($hasName && $hasDaily) {
        $sql = "SELECT r.*, r.name AS title, r.daily_rate AS price_per_day, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
    } elseif ($hasName && !$hasDaily) {
        $sql = "SELECT r.*, r.name AS title, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
    } elseif (!$hasName && $hasDaily) {
        $sql = "SELECT r.*, r.daily_rate AS price_per_day, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
    } else {
        $sql = "SELECT r.*, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
    }

    // Add WHERE clause to exclude deleted items
    $sql .= " WHERE " . implode(' AND ', $where);
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $_GET['id']);
    $stmt->execute();
    $rental = $stmt->get_result()->fetch_assoc();
    
    if (!$rental) {
        header('Location: index.php');
        exit;
    }
    
    // Normalize fields for display
    $displayTitle = $rental['title'] ?? $rental['name'] ?? ($rental['model'] ?? 'Untitled');
    $displayPrice = $rental['price_per_day'] ?? $rental['daily_rate'] ?? 0.00;
    $displayCategory = $rental['category_name'] ?? ($rental['category'] ?? '');
    $displayImage = $rental['image'] ?? '';
    // Ensure status is always available for rendering and checks
    $displayStatus = $rental['status'] ?? '';

    $page_title = $displayTitle . " - Smart Rental";
} else {
    // Get all rentals with optional filtering (show all statuses)
    $where = [];
    $params = [];
    $types = "";
    
    // Category filter: support either slug (if DB has it) or numeric id via category_id
    if (!empty($_GET['category']) && $hasCategorySlug) {
        $where[] = "c.slug = ?";
        $params[] = $_GET['category'];
        $types .= "s";
    } elseif (!empty($_GET['category_id'])) {
        $where[] = "c.id = ?";
        $params[] = intval($_GET['category_id']);
        $types .= "i";
    }
    
    if (!empty($_GET['search'])) {
        $search = "%{$_GET['search']}%";
        // Use name or title depending on schema
        $titleCol = $hasName ? 'r.name' : 'r.title';
        $where[] = "($titleCol LIKE ? OR r.description LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $types .= "ss";
    }
    
    // Build SELECT depending on available columns (reuse from above)
    if ($hasName && $hasDaily) {
    $selectBase = "SELECT r.*, r.name AS title, r.daily_rate AS price_per_day, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
    } elseif ($hasName && !$hasDaily) {
    $selectBase = "SELECT r.*, r.name AS title, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
    } elseif (!$hasName && $hasDaily) {
    $selectBase = "SELECT r.*, r.daily_rate AS price_per_day, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
    } else {
    $selectBase = "SELECT r.*, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
    }

    $sql = $selectBase . (!empty($where) ? " WHERE " . implode(" AND ", $where) : "") . " ORDER BY r.created_at DESC";
    
    $stmt = $db->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rentals = $stmt->get_result();
    
    $page_title = "Browse Vehicles - Smart Rental";
}

include __DIR__ . '/includes/header.php';
?>

<?php if (isset($rental)): ?>
<!-- Single Rental View -->
<div class="bg-white rounded-xl p-6 shadow-md">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <div>
          <img src="/smart_rental/admin/uploads/<?php echo htmlspecialchars($displayImage); ?>" 
              alt="<?php echo htmlspecialchars($displayTitle); ?>"
                 class="w-full rounded-lg shadow-md">
        </div>
        <div>
            <div class="flex items-center gap-2 mb-4">
                <span class="inline-block px-3 py-1 bg-[#1b4b4b] text-white text-sm rounded-full">
                    <?php echo htmlspecialchars($displayCategory); ?>
                </span>
                <?php renderRentalStatusBadge($displayStatus); ?>
            </div>
            <h1 class="text-3xl font-bold mt-2 mb-4"><?php echo htmlspecialchars($displayTitle); ?></h1>
            <p class="text-xl font-bold text-[#1b4b4b] mb-4">
                <?php echo format_money($displayPrice); ?> per day
            </p>
            <div class="prose max-w-none mb-6">
                <?php echo nl2br(htmlspecialchars($rental['description'])); ?>
            </div>
            
                <div class="space-y-4">
                <?php if (isVehicleAvailable($displayStatus)): ?>
                    <div class="flex gap-4">
                        <input type="number" id="days" min="1" value="1" 
                               class="border rounded px-3 py-2 w-24"
                               onchange="updateTotal(<?php echo $displayPrice; ?>)">
                        <div class="text-lg">
                            Total: <span id="total"><?php echo format_money($displayPrice); ?></span>
                        </div>
                    </div>
                    <button onclick="addToCart(<?php echo $rental['id']; ?>)"
                            class="w-full bg-[#1b4b4b] text-white py-3 rounded-lg font-bold hover:bg-[#228383] transition">
                        Add to Rental Cart
                    </button>
                <?php else: ?>
                    <button disabled 
                            title="<?php echo htmlspecialchars(getRentalStatusMessage($displayStatus)); ?>" 
                            class="w-full bg-gray-300 text-gray-700 py-3 rounded-lg font-bold cursor-not-allowed">
                        <?php echo htmlspecialchars(getRentalStatusMessage($displayStatus)); ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function updateTotal(pricePerDay) {
    const days = document.getElementById('days').value;
    const total = (days * pricePerDay).toFixed(2);
    document.getElementById('total').textContent = '₵' + total;
}

function addToCart(rentalId) {
    const days = document.getElementById('days').value;
    const cart = JSON.parse(localStorage.getItem('cart') || '[]');
    
    // Check if already in cart
    const existingItem = cart.find(item => item.id === rentalId);
    if (existingItem) {
        existingItem.days = parseInt(days);
    } else {
        cart.push({
            id: rentalId,
            days: parseInt(days)
        });
    }
    
    localStorage.setItem('cart', JSON.stringify(cart));
    alert('Added to rental cart successfully!');
}
</script>

<?php else: ?>
<!-- Rentals List View -->
<section class="mb-6">
    <form method="GET" class="flex flex-wrap gap-4 items-center bg-white px-6 py-4 rounded-xl shadow">
        <input type="text" name="search" placeholder="Search model or brand" 
               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
               class="border px-3 py-2 rounded w-full max-w-xs outline-none" />
        
        <select name="<?php echo $hasCategorySlug ? 'category' : 'category_id'; ?>" class="border px-3 py-2 rounded outline-none">
            <option value="">Any Category</option>
            <?php
            if ($hasCategorySlug) {
                $categories = $db->query("SELECT name, slug FROM categories ORDER BY name");
                while($cat = $categories->fetch_assoc()): 
                    $selected = ($cat['slug'] === ($_GET['category'] ?? '')) ? 'selected' : '';
            ?>
                <option value="<?php echo htmlspecialchars($cat['slug']); ?>" <?php echo $selected; ?>>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </option>
            <?php endwhile; } else {
                $categories = $db->query("SELECT id, name FROM categories ORDER BY name");
                while($cat = $categories->fetch_assoc()): 
                    $selected = (isset($_GET['category_id']) && intval($_GET['category_id']) === intval($cat['id'])) ? 'selected' : '';
            ?>
                <option value="<?php echo intval($cat['id']); ?>" <?php echo $selected; ?>>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </option>
            <?php endwhile; } ?>
        </select>
        
        <button type="submit" class="px-6 py-2 bg-[#1b4b4b] text-white rounded font-bold">
            Apply Filters
        </button>
    </form>
</section>

<?php 
$hasResults = false;
$results = [];
while($rental = $rentals->fetch_assoc()) {
    $hasResults = true;
    $results[] = $rental;
}

if (!$hasResults): 
    if (!empty($_GET['search'])): ?>
    <div class="no-results text-center py-5">
        <h3 class="text-xl font-bold mb-4">No results found</h3>
        <p class="mb-3">Try these tips:</p>
        <ul class="list-none text-start mx-auto mb-4 inline-block">
            <li class="mb-2">• Check your spelling for typing errors</li>
            <li class="mb-2">• Try searching with short and simple keywords</li>
            <li class="mb-2">• Try searching more general terms - you can then filter the search results</li>
        </ul>
        <div>
            <a href="items_details.php" class="inline-block px-6 py-2 bg-[#1b4b4b] text-white rounded font-bold hover:bg-[#228383] transition">Go to Browse</a>
        </div>
    </div>
    <?php else: ?>
    <div class="no-items text-center py-5">
        <h3 class="text-xl font-bold mb-4">No items available</h3>
        <p class="mb-3">There's no item available yet. Try other categories.</p>
        <div>
            <a href="items_details.php" class="inline-block px-6 py-2 bg-[#1b4b4b] text-white rounded font-bold hover:bg-[#228383] transition mt-3">Back to Browse Page</a>
        </div>
    </div>
    <?php endif; ?>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php foreach($results as $rental): ?>
        <div class="bg-white rounded-xl p-4 shadow-md hover:shadow-lg transition-shadow">
            <img src="/smart_rental/admin/uploads/<?php echo htmlspecialchars($rental['image']); ?>"
                 alt="<?php echo htmlspecialchars($rental['title']); ?>"
                 class="w-full h-48 object-cover rounded-lg mb-4">
            
            <span class="inline-block px-2 py-1 bg-[#1b4b4b] text-white text-sm rounded-full">
                <?php echo htmlspecialchars($rental['category_name']); ?>
            </span>
            <?php $rstat = $rental['status'] ?? null; ?>
            <?php if (!empty($rstat)): ?>
                <?php 
                    $statusColors = [
                        'available' => 'bg-green-100 text-green-700',
                        'rented' => 'bg-blue-100 text-blue-700',
                        'maintenance' => 'bg-yellow-100 text-yellow-700'
                    ];
                    $statusLabels = [
                        'available' => 'Available',
                        'rented' => 'Rented',
                        'maintenance' => 'Maintenance'
                    ];
                    $normalizedStatus = strtolower($rstat);
                    $colorClass = $statusColors[$normalizedStatus] ?? 'bg-gray-200 text-gray-800';
                    $label = $statusLabels[$normalizedStatus] ?? ucfirst($rstat);
                ?>
                <span class="ml-2 inline-block px-3 py-1 rounded-full text-sm <?php echo $colorClass; ?>">
                    <?php echo htmlspecialchars($label); ?>
                </span>
            <?php endif; ?>
            
            <h3 class="text-xl font-bold mt-2 mb-1">
                <?php echo htmlspecialchars($rental['title']); ?>
            </h3>
            
            <p class="text-lg font-bold text-[#1b4b4b] mb-3">
                <?php echo format_money($rental['price_per_day']); ?>/day
            </p>
            
            <?php $is_rented = in_array(($rental['status'] ?? ''), ['rented','on_rent','maintenance','in_use']); ?>
            <a href="?id=<?php echo $rental['id']; ?>"
               class="block w-full text-center <?php echo $is_rented ? 'bg-gray-300 text-gray-700 cursor-not-allowed' : 'bg-[#1b4b4b] text-white hover:bg-[#228383]'; ?> py-2 rounded font-bold transition"
               <?php echo $is_rented ? 'title="This vehicle is currently rented" aria-disabled="true"' : ''; ?>
               <?php if ($is_rented): ?> onclick="event.preventDefault(); alert('This vehicle is currently on rent and unavailable');" <?php endif; ?> >
                View Details
            </a>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
