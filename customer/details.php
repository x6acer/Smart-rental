<?php
session_start();
require_once '../db.php';
require_once __DIR__ . '/../includes/asset-helper.php';
require_once __DIR__ . '/../includes/security.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'customer-details')) {
        // Invalid CSRF token for details page forms - ignore
    }
}

$vehicleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$vehicleId) {
    header('Location: browse.php');
    exit();
}

$vehicleStmt = $pdo->prepare('SELECT * FROM Vehicles WHERE vehicle_id = :vehicle_id AND status = :status LIMIT 1');
$vehicleStmt->execute(['vehicle_id' => $vehicleId, 'status' => 'Active']);
$vehicle = $vehicleStmt->fetch();
if (!$vehicle) {
    header('Location: browse.php');
    exit();
}

$ownerStmt = $pdo->prepare(
    'SELECT p.full_name
     FROM User_Profiles p
     WHERE p.user_id = :owner_id
     LIMIT 1'
);
$ownerStmt->execute(['owner_id' => $vehicle['owner_id']]);
$ownerProfile = $ownerStmt->fetch();
$ownerName = $ownerProfile['full_name'] ?? 'Vehicle Owner';

$reviewStmt = $pdo->prepare(
    'SELECT r.review_id, r.rating_score, r.comment, p.full_name AS reviewer_name
     FROM Reviews r
     JOIN Bookings b ON r.booking_id = b.booking_id
     LEFT JOIN User_Profiles p ON p.user_id = r.reviewer_id
     WHERE b.vehicle_id = :vehicle_id
     ORDER BY r.review_id DESC
     LIMIT 2'
);
$reviewStmt->execute(['vehicle_id' => $vehicleId]);
$vehicleReviews = $reviewStmt->fetchAll();

$vehicleName = htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']);
$vehicleYear = htmlspecialchars((string) $vehicle['year']);
$vehiclePrice = number_format((float) $vehicle['base_rate'], 2);
$vehicleClass = htmlspecialchars($vehicle['transmission']);
$vehicleFuel = htmlspecialchars($vehicle['fuel_type']);
$vehicleStatus = htmlspecialchars($vehicle['status']);
$vehicleImage = srVehiclePhotoFromDatabase($pdo, (int) $vehicle['vehicle_id'], (string) $vehicle['make'], (string) $vehicle['model']);
$vehicleGallery = srVehicleGallery((string) $vehicle['make'], (string) $vehicle['model']);
$vehicleGallery[0] = $vehicleImage;
$vehicleCategory = strtoupper($vehicle['make']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Vehicle Details | Smart Rental</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&family=Segoe+UI:wght@400;700&display=swap');
        :root {
            --brand-primary: #1b4b4b;
            --brand-accent: #facd05;
            --brand-light: #e6f5f4;
        }
        .logo h1 {
            font-size: 24px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: -1px;
            color: #1b4b4b;
            margin: 0;
        }
        .logo span { color: #facd05; }
    </style>
</head>
<body class="font-['Segoe_UI',Tahoma,Geneva,Verdana,sans-serif] bg-[#f9f9f8] text-[#1b4b4b] antialiased">

    <?php require_once 'includes/header.php'; ?>

    <main class="max-w-7xl mx-auto px-6 py-12">
        <nav class="mb-8 text-xs font-bold uppercase tracking-widest text-gray-400">
            <a href="browse.php" class="hover:text-[#1b4b4b]">Garage</a> / 
            <a href="#" class="hover:text-[#1b4b4b]"><?php echo htmlspecialchars($vehicleCategory); ?></a> / 
            <span class="text-[#1b4b4b]"><?php echo $vehicleName; ?></span>
        </nav>

        <section class="grid grid-cols-1 lg:grid-cols-12 gap-12 bg-white rounded-[2rem] shadow-xl border border-gray-100 overflow-hidden">
            
            <div class="lg:col-span-5 p-8 bg-gray-50">
                <div class="sticky top-24">
                    <div class="relative group">
                        <img src="<?= htmlspecialchars($vehicleGallery[0], ENT_QUOTES, 'UTF-8'); ?>" alt="Vehicle view" class="w-full rounded-2xl shadow-lg group-hover:scale-[1.02] transition-transform duration-500">
                        <div class="absolute top-4 left-4 bg-green-500 text-white text-[10px] font-black px-3 py-1 rounded-full uppercase shadow-sm">Verified Unit</div>
                    </div>
                    
                    <div class="grid grid-cols-4 gap-4 mt-6">
                        <img src="<?= htmlspecialchars($vehicleGallery[1], ENT_QUOTES, 'UTF-8'); ?>" class="w-full aspect-square object-cover rounded-xl border-2 border-white shadow-sm cursor-pointer hover:border-[#1b4b4b] transition" alt="Vehicle gallery image 2">
                        <img src="<?= htmlspecialchars($vehicleGallery[2], ENT_QUOTES, 'UTF-8'); ?>" class="w-full aspect-square object-cover rounded-xl border-2 border-white shadow-sm cursor-pointer hover:border-[#1b4b4b] transition" alt="Vehicle gallery image 3">
                        <div class="relative rounded-xl overflow-hidden cursor-pointer group">
                             <img src="<?= htmlspecialchars($vehicleGallery[0], ENT_QUOTES, 'UTF-8'); ?>" class="w-full aspect-square object-cover opacity-50 group-hover:opacity-100 transition" alt="More vehicle photos">
                             <span class="absolute inset-0 flex items-center justify-center text-xs font-black text-[#1b4b4b]">+5 More</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-7 p-8 md:p-12 flex flex-col">
                <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
                    <div>
                        <h2 class="text-4xl font-black text-[#1b4b4b] uppercase tracking-tight"><?php echo $vehicleName; ?></h2>
                        <p class="text-gray-400 font-bold uppercase text-xs tracking-widest mt-1">Managed by: <span class="text-[#1b4b4b]"><?php echo htmlspecialchars($ownerName); ?></span></p>
                    </div>
                    <div class="bg-[#e6f5f4] text-[#1b4b4b] font-black px-4 py-2 rounded-xl text-sm italic"><?php echo htmlspecialchars($vehicle['transmission'] . ' • ' . $vehicle['fuel_type']); ?></div>
                </div>

                <div class="mb-8">
                    <p class="text-4xl font-black text-[#1b4b4b]">GHS <?php echo htmlspecialchars($vehiclePrice); ?><span class="text-sm font-bold text-gray-400 uppercase tracking-tighter">/day</span></p>
                    <p class="text-green-600 text-xs font-bold mt-1">● <?php echo $vehicle['instant_book_enabled'] ? 'Instant Booking Available' : 'Request Booking Only'; ?></p>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-6 mb-10">
                    <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100 text-center">
                        <p class="text-[10px] font-black text-gray-400 uppercase mb-1">Year</p>
                        <p class="text-sm font-bold"><?php echo $vehicleYear; ?></p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100 text-center">
                        <p class="text-[10px] font-black text-gray-400 uppercase mb-1">Transmission</p>
                        <p class="text-sm font-bold"><?php echo htmlspecialchars($vehicleClass); ?></p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-2xl border border-gray-100 text-center">
                        <p class="text-[10px] font-black text-gray-400 uppercase mb-1">Fuel</p>
                        <p class="text-sm font-bold"><?php echo htmlspecialchars($vehicleFuel); ?></p>
                    </div>
                </div>

                <div class="space-y-8 flex-grow">
                    <div>
                        <h4 class="text-xs font-black uppercase text-gray-400 tracking-widest mb-4">Included Protection</h4>
                        <div class="flex flex-wrap gap-2">
                            <span class="bg-[#f9f9f8] border border-gray-200 px-4 py-2 rounded-full text-xs font-bold">Comprehensive Insurance</span>
                            <span class="bg-[#f9f9f8] border border-gray-200 px-4 py-2 rounded-full text-xs font-bold">24/7 Roadside Assist</span>
                            <span class="bg-[#f9f9f8] border border-gray-200 px-4 py-2 rounded-full text-xs font-bold">Theft Protection</span>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-xs font-black uppercase text-gray-400 tracking-widest mb-4">Rental Terms</h4>
                        <ul class="grid grid-cols-1 md:grid-cols-2 gap-y-3 text-sm font-medium text-gray-600">
                            <li class="flex items-center gap-2"><span class="text-yellow-500">✔</span> Valid Driver's License Required</li>
                            <li class="flex items-center gap-2"><span class="text-yellow-500">✔</span> Base Rate: GHS <?php echo htmlspecialchars($vehiclePrice); ?>/day</li>
                            <li class="flex items-center gap-2"><span class="text-yellow-500">✔</span> Booking subject to vehicle availability</li>
                            <li class="flex items-center gap-2"><span class="text-yellow-500">✔</span> Payment processed through escrow</li>
                        </ul>
                    </div>
                </div>

                <div class="mt-12 pt-8 border-t border-gray-100">
                    <a href="booking.php?id=<?php echo urlencode((string) $vehicle['vehicle_id']); ?>" class="block w-full text-center bg-[#1b4b4b] text-white py-5 rounded-2xl font-black text-lg uppercase tracking-widest hover:bg-gray-800 shadow-xl transform active:scale-[0.98] transition">
                        Proceed to Booking
                    </a>
                    <p class="text-center text-[10px] font-bold text-gray-400 mt-4 uppercase">Secure Escrow Payment via MOMO or Bank</p>
                </div>
            </div>
        </section>

        <section class="mt-16">
            <div class="flex items-center justify-between mb-8">
                <h3 class="text-2xl font-black uppercase tracking-tight text-[#1b4b4b]">Renter Feedback</h3>
                <div class="flex items-center gap-2">
                    <span class="text-yellow-400 text-lg">★★★★★</span>
                    <span class="text-sm font-bold text-gray-500"><?php echo count($vehicleReviews); ?> recent review<?php echo count($vehicleReviews) === 1 ? '' : 's'; ?></span>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php if (empty($vehicleReviews)): ?>
                    <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm md:col-span-2">
                        <p class="text-sm text-gray-600">No reviews have been submitted for this vehicle yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($vehicleReviews as $review): ?>
                        <div class="bg-white p-6 rounded-3xl border border-gray-100 shadow-sm">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <p class="font-black text-[#1b4b4b]"><?php echo htmlspecialchars($review['reviewer_name'] ?: 'Verified Renter'); ?></p>
                                    <p class="text-[10px] text-gray-400 font-bold uppercase">Verified Renter</p>
                                </div>
                                <span class="text-yellow-400 font-bold italic text-sm"><?php echo htmlspecialchars((string) $review['rating_score']); ?> ★</span>
                            </div>
                            <p class="text-sm text-gray-600 leading-relaxed italic">"<?php echo htmlspecialchars($review['comment'] ?: 'No written comment provided.'); ?>"</p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <footer class="bg-white border-t border-gray-100">
        <div class="max-w-7xl mx-auto px-6 pt-20 pb-10">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-12">
                <div class="lg:col-span-4">
                    <div class="text-3xl font-black text-[#1b4b4b] leading-tight">
                        SMART<br/><span class="text-yellow-500">RENTAL</span>
                    </div>
                    <p class="mt-6 text-gray-500 leading-relaxed max-w-sm">
                        Building the future of shared mobility in Ghana through safety, transparency, and a commitment to quality.
                    </p>
                    <div class="mt-8 flex gap-4">
                        <a href="#" class="w-10 h-10 bg-gray-50 rounded-full flex items-center justify-center hover:bg-yellow-400 transition"><span class="text-sm font-bold">FB</span></a>
                        <a href="#" class="w-10 h-10 bg-gray-50 rounded-full flex items-center justify-center hover:bg-yellow-400 transition"><span class="text-sm font-bold">IG</span></a>
                        <a href="#" class="w-10 h-10 bg-gray-50 rounded-full flex items-center justify-center hover:bg-yellow-400 transition"><span class="text-sm font-bold">X</span></a>
                    </div>
                </div>

                <div class="lg:col-span-2 md:col-span-4">
                    <h4 class="text-sm font-black uppercase text-gray-900 tracking-widest mb-6">Quick Links</h4>
                    <ul class="space-y-4 text-gray-600 text-sm">
                        <li><a href="#hero" class="hover:text-yellow-600 transition">Home</a></li>
                        <li><a href="aboutus.php" class="hover:text-yellow-600 transition">Our Story</a></li>
                        <li><a href="#cotm" class="hover:text-yellow-600 transition">Special Offers</a></li>
                        <li><a href="#pricing" class="hover:text-yellow-600 transition">Pricing Plans</a></li>
                    </ul>
                </div>

                <div class="lg:col-span-2 md:col-span-4">
                    <h4 class="text-sm font-black uppercase text-gray-900 tracking-widest mb-6">Support</h4>
                    <ul class="space-y-4 text-gray-600 text-sm">
                        <li><a href="#faq" class="hover:text-yellow-600 transition">FAQs</a></li>
                        <li><a href="tel:+233200853940" class="hover:text-yellow-600 transition">Contact Us</a></li>
                        <li><a href="../privacy.php" class="hover:text-yellow-600 transition">Privacy Policy</a></li>
                        <li><a href="../terms.php" class="hover:text-yellow-600 transition">Terms of Service</a></li>
                    </ul>
                </div>

                <div class="lg:col-span-4 md:col-span-4">
                    <h4 class="text-sm font-black uppercase text-gray-900 tracking-widest mb-6">Newsletter</h4>
                    <p class="text-gray-500 text-sm mb-6">Get notified about the latest additions to our fleet and exclusive discounts.</p>
                    <form action="#" method="POST" class="flex gap-2" data-newsletter-form="true">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('customer-details'), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="email" placeholder="email@address.com" class="flex-grow p-3 bg-gray-50 border border-gray-100 rounded-xl outline-none focus:border-yellow-400 text-sm">
                        <button type="submit" class="bg-[#1b4b4b] text-white px-5 py-3 rounded-xl font-bold text-sm hover:bg-gray-800 transition">Join</button>
                    </form>
                </div>
            </div>

            <div class="mt-20 pt-8 border-t border-gray-100 flex flex-col md:flex-row justify-between items-center gap-4 text-xs font-bold text-gray-400 uppercase tracking-widest">
                <p>&copy; 2026 Smart Rental. All rights reserved.</p>
                <p>Designed with Trust in mind</p>
            </div>
        </div>
    </footer>

    <script src="./js/cust-ui.js"></script>

</body>
</html>


