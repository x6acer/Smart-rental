<?php
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/includes/rental_status.php';
$db = db_get_conn();

// Get customer details
$customer = get_customer_details($db);

// Get featured rentals. Detect column names to remain compatible with different schemas.
$colNameRes = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME = 'name'");
$hasName = false;
if ($colNameRes) {
    $r = $colNameRes->fetch_assoc();
    $hasName = !empty($r['cnt']);
}

$colDailyRes = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME = 'daily_rate'");
$hasDaily = false;
if ($colDailyRes) {
    $r2 = $colDailyRes->fetch_assoc();
    $hasDaily = !empty($r2['cnt']);
}

// Check if is_deleted column exists
$delColRes = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME = 'is_deleted'");
$hasIsDeleted = false;

// Build featured rentals query (schema-aware)
$where = [];
if ($hasIsDeleted) {
    $where[] = "(r.is_deleted = 0 OR r.is_deleted IS NULL)";
}
$where[] = "(r.status IS NULL OR r.status = '' OR LOWER(r.status) != 'deleted')";

if ($hasName && $hasDaily) {
    $sql = "SELECT r.*, r.name AS title, r.daily_rate AS price_per_day, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
} elseif ($hasName && !$hasDaily) {
    $sql = "SELECT r.*, r.name AS title, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
} elseif (!$hasName && $hasDaily) {
    $sql = "SELECT r.*, r.daily_rate AS price_per_day, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
} else {
    $sql = "SELECT r.*, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
}
if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY r.created_at DESC LIMIT 4";
$featured_rentals = $db->query($sql);

$page_title = "Home - Smart Rental";
include __DIR__ . '/includes/header.php';
?>


    <section id="hero" class="relative h-[78vh] md:h-screen overflow-hidden" style="margin-left: calc(-1 * clamp(1rem,6vw,100px)); margin-right: calc(-1 * clamp(1rem,6vw,100px));">
            <!-- full-bleed background image (spans the full browser width) -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('/smart_rental/assets/images/dextar-vision-4JztXiioPHI-unsplash.jpg');"></div>
            <div class="absolute inset-0 bg-gradient-to-b from-black/70 via-black/30 to-black/10"></div>
            <div class="container-hero relative z-10 max-w-6xl mx-auto px-6 h-full flex items-center">
                <div class="text-white max-w-2xl">
                    <h1 class="text-5xl md:text-6xl lg:text-7xl font-extrabold uppercase text-yellow-400 leading-tight">SMART RENTAL</h1>
                    <p id="phrase" class="mt-4 text-lg md:text-xl text-gray-200">- Comfortable driving everywhere</p>
                    <p class="mt-4 text-gray-200 hidden md:block">Reliable vehicles, exceptional service, and transparent pricing to keep you moving.</p>
                    <div class="mt-8">
                        <a href="items_details.php" class="inline-block bg-yellow-400 text-[#1b4b4b] px-6 py-3 rounded-full font-semibold hover:scale-105 transition">START RENTING TODAY</a>
                    </div>
                </div>
            </div>
        </section>
<?php
// Prepare dynamic data for additional landing sections - Cars of the month
$where_best = [];
if ($hasIsDeleted) {
    $where_best[] = "(r.is_deleted = 0 OR r.is_deleted IS NULL)";
}
$where_best[] = "(r.status IS NULL OR r.status = '' OR LOWER(r.status) != 'deleted')";

$best_sql = "SELECT r.*, c.name as category_name FROM rentals r LEFT JOIN categories c ON r.category_id = c.id";
if (!empty($where_best)) {
    $best_sql .= " WHERE " . implode(' AND ', $where_best);
}
$best_sql .= " ORDER BY r.created_at DESC LIMIT 3";

$best_offer_stmt = $db->prepare($best_sql);
$best_offer_stmt->execute();
$best_offers = $best_offer_stmt->get_result();

$faqs = [
    ['q' => 'What documents do I need to rent a car?', 'a' => 'A valid driver\'s license, a credit card in the renter\'s name, and proof of insurance if you opt out of our coverage.'],
    ['q' => 'Can I return the car to a different location?', 'a' => 'Yes — some vehicles allow one-way rentals. Additional fees may apply depending on drop-off location.'],
    ['q' => 'Do you offer roadside assistance?', 'a' => 'Yes, roadside assistance is available and included on most plans or available as an add-on.']
];

$why_points = [
    'Personal accident insurance',
    'Roadside assistance protection',
    'Different options for financial responsibility'
];

$testimonials = [
    ['text' => 'Smooth booking, clean car and great support — highly recommended!', 'author' => 'Ama Boateng', 'date' => 'Jan 2025'],
    ['text' => 'Reliable cars and friendly staff. Perfect for business trips.', 'author' => 'Kofi Mensah', 'date' => 'Mar 2025'],
    ['text' => 'Great value and flexible options. The roadside assistance saved us once!', 'author' => 'Efua Owusu', 'date' => 'May 2025']
];
?>


<!-- Featured Rentals (dynamic, moved up immediately after hero) -->
<section id="featured-rentals">
    <div class="text-center mt-6">
        <h2 class="font-bold text-[#1b4b4b] mb-1 tracking-wide text-[clamp(1.7rem,4vw,2.2rem)] relative after:content-[''] after:block after:mx-auto after:mt-3 after:w-16 after:border-b-4 after:border-[#1b4b4b] after:rounded">
            Featured Vehicles
        </h2>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 p-6 mt-8">
        <?php while($rental = $featured_rentals->fetch_assoc()): ?>
            <?php
                $displayTitle = $rental['title'] ?? $rental['name'] ?? ($rental['model'] ?? 'Untitled');
                $displayPrice = $rental['price_per_day'] ?? $rental['daily_rate'] ?? 0.00;
                $displayImage = $rental['image'] ?? '';
                $displayCategory = $rental['category_name'] ?? ($rental['category'] ?? '');
            ?>
            <?php $fstatus = $rental['status'] ?? null; ?>
            <div class="bg-[#f9f9f8] rounded-xl flex h-full flex-col p-4 transition-shadow relative hover:shadow-[0_8px_32px_rgba(0,0,0,0.15)]">
                <div class="relative">
                    <?php if (!empty($fstatus)): ?>
                        <div class="absolute top-2 left-2 z-10">
                            <?php renderRentalStatusBadge($fstatus); ?>
                        </div>
                    <?php endif; ?>

                    <img src="/smart_rental/admin/uploads/<?php echo htmlspecialchars($displayImage); ?>" 
                         alt="<?php echo htmlspecialchars($displayTitle); ?>" 
                         class="w-full h-[200px] object-cover mb-4 rounded-lg" />

                    <div class="px-3 py-1 bg-[#1b4b4b] text-white text-sm rounded-full absolute top-2 right-2">
                        <?php echo htmlspecialchars($displayCategory); ?>
                    </div>
                </div>
                <div class="flex flex-col flex-grow">
                    <h3 class="text-lg mt-2 mb-1 text-[#1b4b4b] font-bold">
                        <?php echo htmlspecialchars($displayTitle); ?>
                    </h3>
                    <p class="text-base text-[#4b4b4b] mb-4">
                        <?php echo format_money($displayPrice); ?>/day
                    </p>
                    <a href="items_details.php?id=<?php echo $rental['id']; ?>" 
                       class="bg-black/50 text-white border-none px-6 py-2 rounded mt-auto transition hover:bg-[#222] text-center">
                        View Details
                    </a>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</section>


<!-- Best Offer / Car of the Month -->
<section id="best-offer" class="py-12 bg-white">
    <div class="max-w-7xl mx-auto px-6 text-center mb-6">
        <p class="text-yellow-500 font-semibold">- BEST OFFER</p>
        <h2 class="text-3xl font-extrabold text-gray-800">Cars of the Month</h2>
        <p class="text-gray-600 mt-2">Specially selected vehicles with exclusive monthly offers. Save on your next trip.</p>
    </div>
    <div class="max-w-6xl mx-auto px-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php while ($offer = $best_offers->fetch_assoc()):
            $title = $offer['name'] ?? $offer['title'] ?? 'Vehicle';
            $img = $offer['image'] ?? '';
            $price = $offer['daily_rate'] ?? $offer['price_per_day'] ?? 0;
        ?>
    <?php $ostatus = $offer['status'] ?? null; ?>
    <div class="bg-white rounded-xl overflow-hidden shadow hover:shadow-xl relative">
            <img src="/smart_rental/admin/uploads/<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($title); ?>" class="w-full h-48 object-cover">
            <div class="p-4">
                <?php if (!empty($ostatus)): ?>
                    <div class="absolute top-4 left-4">
                        <?php renderRentalStatusBadge($ostatus); ?>
                    </div>
                <?php endif; ?>
                <h4 class="font-bold"><?php echo htmlspecialchars($title); ?></h4>
                <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($offer['category_name'] ?? ''); ?></p>
                <div class="mt-4 flex justify-between items-center">
                    <span class="text-xl font-bold"><?php echo format_money($price); ?>/day</span>
                    <?php $offer_rented = in_array(($offer['status'] ?? ''), ['rented','on_rent','maintenance','in_use']); ?>
                    <a href="items_details.php?id=<?php echo $offer['id']; ?>" class="text-yellow-500 font-semibold <?php echo $offer_rented ? 'pointer-events-none opacity-60' : ''; ?>" <?php echo $offer_rented ? 'title="This vehicle is currently rented"' : ''; ?>>Reserve</a>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
</section>

<!-- Why Choose Us -->
<section id="whyus" class="py-20 bg-white">
    <div class="max-w-7xl mx-auto px-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">

            <div class="lg:col-span-4">
                <p class="text-yellow-500 font-semibold tracking-wider">— SAFE CAR RENTAL</p>
                <h2 class="mt-4 text-4xl md:text-5xl lg:text-6xl font-extrabold text-gray-900 leading-tight">Responsibility</h2>
                <p class="text-gray-600 mt-6 text-lg">We know how important the safety of our customers is. Therefore, we do everything possible to ensure our cars are reliably serviced and safe to drive.</p>

                <p class="text-gray-500 mt-4">All cars are equipped with active and passive safety systems and are regularly inspected and maintained by certified technicians.</p>

                <ul class="mt-6 space-y-4 text-gray-700">
                    <li class="flex items-start gap-3">
                        <img src="/smart_rental/assets/icons/settings.png" alt="icon" class="h-6 w-6 flex-shrink-0">
                        <span>Personal accident insurance</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <img src="/smart_rental/assets/icons/settings.png" alt="icon" class="h-6 w-6 flex-shrink-0">
                        <span>Roadside assistance protection</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <img src="/smart_rental/assets/icons/settings.png" alt="icon" class="h-6 w-6 flex-shrink-0">
                        <span>Different options for financial responsibility</span>
                    </li>
                </ul>

                <div class="mt-8">
                    <a href="#cotm" class="inline-block bg-blue-600 text-white px-6 py-3 rounded-full font-semibold shadow hover:bg-blue-700 transition">LEARN MORE</a>
                </div>
            </div>

            <!-- Center column: car image (col-span 4) -->
            <div class="lg:col-span-4 flex justify-center items-center">
                <div class="w-full max-w-[450px] lg:max-w-[550px]">
                    <img src="/smart_rental/assets/images/whyus.jpg" alt="whyus car" class="w-full h-[550px] object-contain mx-auto">
                </div>
            </div>

            <!-- Right column: stats (col-span 4) -->
            <div class="lg:col-span-4 flex flex-col justify-center items-start lg:items-end space-y-10">
                <div class="text-right lg:text-right">
                    <div class="text-5xl md:text-6xl lg:text-7xl font-extrabold text-blue-500 leading-none">10</div>
                    <div class="text-base text-gray-700 font-medium mt-2">Years of experience</div>
                    <p class="text-gray-500 mt-2 max-w-xs">We have the perfect reputation for premium car rental service.</p>
                </div>

                <div class="text-right lg:text-right">
                    <div class="text-5xl md:text-6xl lg:text-7xl font-extrabold text-blue-500 leading-none">450</div>
                    <div class="text-base text-gray-700 font-medium mt-2">Clients per month</div>
                    <p class="text-gray-500 mt-2 max-w-xs">We serve celebrities, entrepreneurs and travelers from around the world.</p>
                </div>

                <div class="text-right lg:text-right">
                    <div class="text-5xl md:text-6xl lg:text-7xl font-extrabold text-blue-500 leading-none">80</div>
                    <div class="text-base text-gray-700 font-medium mt-2">Cars available</div>
                    <p class="text-gray-500 mt-2 max-w-xs">You can pick up a car at any convenient location.</p>
                </div>
            </div>
        </div>
    </div>
</section>


<!-- What Our Customers Say -->
<section id="customer-testimonials" class="py-12 bg-white">
    <div class="max-w-7xl mx-auto px-6 text-center mb-8">
        <h2 class="text-3xl font-extrabold">What Our Customers Say</h2>
        <p class="text-gray-600 mt-2">Real experiences from drivers like you</p>
    </div>
    <div class="max-w-6xl mx-auto px-6 grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php foreach ($testimonials as $t): ?>
    <div class="bg-gray-50 p-6 rounded-xl shadow">
            <div class="flex items-center gap-4 mb-3">
                <img src="/smart_rental/assets/images/cotm1.jpg" alt="avatar" class="w-12 h-12 rounded-full object-cover">
                <div>
                    <p class="italic text-gray-700"><?php echo htmlspecialchars($t['text']); ?></p>
                    <p class="mt-2 font-semibold"><?php echo htmlspecialchars($t['author']); ?></p>
                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($t['date']); ?></p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>


<!-- FAQ -->
<section id="faq" class="py-12 bg-white">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-8">
            <h2 class="text-3xl font-extrabold text-gray-800">Frequently Asked Questions</h2>
            <p class="text-gray-600 mt-2">Answers to the questions customers ask most often.</p>
        </div>

        <div class="max-w-4xl mx-auto space-y-4">
            <?php foreach ($faqs as $f): ?>
            <details class="p-4 border rounded-lg">
                <summary class="font-semibold cursor-pointer"><?php echo htmlspecialchars($f['q']); ?></summary>
                <p class="mt-2 text-gray-600"><?php echo htmlspecialchars($f['a']); ?></p>
            </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>


<?php include __DIR__ . '/includes/footer.php'; ?>
