<?php
session_start();
require_once '../db.php';
require_once __DIR__ . '/../includes/asset-helper.php';
require_once __DIR__ . '/../includes/security.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'public-browse')) {
        // Invalid CSRF token for public browse forms - ignore
    }
}

$isLoggedIn = isset($_SESSION['user_id']);
$customerName = $_SESSION['user_name'] ?? 'Customer';
$customerInitials = strtoupper(substr(trim($customerName), 0, 2));

if ($isLoggedIn && empty($_SESSION['user_name'])) {
    $profileStmt = $pdo->prepare('SELECT full_name FROM User_Profiles WHERE user_id = :user_id LIMIT 1');
    $profileStmt->execute(['user_id' => (int) $_SESSION['user_id']]);
    $profile = $profileStmt->fetch();

    if ($profile && !empty($profile['full_name'])) {
        $customerName = $profile['full_name'];
        $customerInitials = strtoupper(substr(trim($customerName), 0, 2));
    }
}

$vehiclesStmt = $pdo->query(
    'SELECT vehicle_id, make, model, year, transmission, fuel_type, base_rate, instant_book_enabled, status
     FROM Vehicles
     WHERE status = "Active"
     ORDER BY vehicle_id DESC'
);
$availableVehicles = $vehiclesStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Browse Vehicles | Smart Rental</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&family=Segoe+UI:wght@400;700&display=swap');
        :root {
            --brand-primary: #1b4b4b;
            --brand-accent: #facd05;
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
        .bg-brand { background-color: var(--brand-primary); }
        .text-brand { color: var(--brand-primary); }
        .border-brand { border-color: var(--brand-primary); }
        .tab-active { border-bottom: 3px solid var(--brand-primary); color: var(--brand-primary); font-weight: bold; }
    </style>
</head>
<body class="font-['Segoe_UI',Tahoma,Geneva,Verdana,sans-serif] bg-[#f9f9f8] text-[#1b4b4b] antialiased">

    <?php require_once 'includes/header.php'; ?>

    <main class="max-w-7xl mx-auto px-6 py-10">

        <section class="rounded-3xl bg-brand p-10 mb-12 text-center relative overflow-hidden">
            <div class="absolute inset-0 opacity-30 bg-[url('https://www.transparenttextures.com/patterns/carbon-fibre.png')]"></div>
            <h2 class="text-4xl font-black text-white mb-4 relative z-10">Find Your Perfect Ride</h2>
            <p class="text-yellow-400 text-lg mb-8 relative z-10 font-bold tracking-wide">Trust-Verified Owners & 24/7 Roadside Assistance</p>
            <?php if (!$isLoggedIn): ?>
                <p class="relative z-10 mx-auto mb-8 max-w-2xl rounded-2xl border border-white/20 bg-white/10 px-5 py-4 text-sm font-medium text-white/90 backdrop-blur-sm">Browse the fleet but log in to reserve, save cars, and message owners.</p>
            <?php endif; ?>
            <div class="flex justify-center gap-4 relative z-10">
                <a href="#Featured-v" class="px-8 py-3 bg-white text-brand rounded-xl font-bold hover:bg-yellow-400 transition">Browse Fleet</a>
                <a href="#quick-book" class="px-8 py-3 bg-brand border-2 border-white text-white rounded-xl font-bold hover:bg-white hover:text-brand hover:text-gray-700 transition"><?php echo $isLoggedIn ? 'Quick Quote' : 'How Booking Works'; ?></a>
            </div>
        </section>

        <section class="mb-12 bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <form class="grid grid-cols-1 md:grid-cols-5 gap-6 items-end" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('public-browse'), ENT_QUOTES, 'UTF-8'); ?>">
                <div>
                    <label class="text-xs font-black uppercase text-gray-400 mb-2 block">Vehicle Type</label>
                    <select name="type" class="w-full p-3 bg-gray-50 border border-gray-100 rounded-lg outline-none focus:border-brand">
                        <option>Any Type</option>
                        <option>SUV</option>
                        <option>Sedan</option>
                        <option>Luxury</option>
                        <option>Truck</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-black uppercase text-gray-400 mb-2 block">Price Range (GHS)</label>
                    <select name="price" class="w-full p-3 bg-gray-50 border border-gray-100 rounded-lg outline-none focus:border-brand">
                        <option>Any Price</option>
                        <option>Below 1,000</option>
                        <option>1,000 - 2,500</option>
                        <option>Above 2,500</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-black uppercase text-gray-400 mb-2 block">Transmission</label>
                    <select name="gear" class="w-full p-3 bg-gray-50 border border-gray-100 rounded-lg outline-none focus:border-brand">
                        <option>Automatic</option>
                        <option>Manual</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-black uppercase text-gray-400 mb-2 block">Region / Location</label>
                    <select name="region" class="w-full p-3 bg-gray-50 border border-gray-100 rounded-lg outline-none focus:border-brand">
                        <option selected>All Regions</option>
                        <option>Greater Accra</option>
                        <option>Ashanti</option>
                        <option>Central</option>
                        <option>Western</option>
                        <option>Eastern</option>
                        <option>Northern</option>
                        <option>Volta</option>
                        <option>Ahafo</option>
                        <option>Bono</option>
                        <option>Bono East</option>
                        <option>North East</option>
                        <option>Oti</option>
                        <option>Savannah</option>
                        <option>Upper East</option>
                        <option>Upper West</option>
                        <option>Western North</option>
                    </select>
                </div>
                <button type="submit" class="w-full p-3 bg-brand text-white font-bold rounded-lg hover:bg-gray-800 shadow-md transition">Apply Filters</button>
            </form>
        </section>

        <section id="Featured-v">
            <div class="flex items-center justify-between mb-8 border-b border-gray-200">
                <h2 class="text-3xl font-black pb-4 border-b-4 border-brand">Available Fleet</h2>
                <div class="hidden md:flex gap-6 text-sm font-bold text-gray-500 mb-4">
                    <button class="tab-active pb-4">All</button>
                    <button class="hover:text-brand transition pb-4">SUVs</button>
                    <button class="hover:text-brand transition pb-4">Luxury</button>
                    <button class="hover:text-brand transition pb-4">Trucks</button>
                </div>
            </div>

            <?php if (empty($availableVehicles)): ?>
                <div class="rounded-3xl border border-dashed border-gray-200 bg-white p-12 text-center shadow-sm">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-4">No vehicles available</p>
                    <h2 class="text-2xl font-black mb-4">We could not find any active vehicles.</h2>
                    <p class="text-sm text-gray-500">Check back later for verified listings.</p>
                </div>
            <?php else: ?>
                <div id="browseEmptyState" class="hidden rounded-3xl border border-dashed border-gray-200 bg-white p-12 text-center shadow-sm">
                    <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-4">No matches found</p>
                    <h2 class="text-2xl font-black mb-4">Try adjusting your filters.</h2>
                    <p class="text-sm text-gray-500">Change vehicle type, price range, or transmission to see more listings.</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3 gap-8">
                    <?php foreach ($availableVehicles as $vehicle): ?>
                        <?php
                        $vehiclePhoto = srVehiclePhotoFromDatabase(
                            $pdo,
                            (int) $vehicle['vehicle_id'],
                            (string) ($vehicle['make'] ?? ''),
                            (string) ($vehicle['model'] ?? '')
                        );
                        $vehicleLabel = strtolower(trim(($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')));
                        ?>
                        <div
                            class="bg-white rounded-3xl overflow-hidden shadow-sm hover:shadow-2xl transition-all duration-300 group border border-gray-100"
                            data-vehicle-card="true"
                            data-make-model="<?= htmlspecialchars($vehicleLabel, ENT_QUOTES, 'UTF-8'); ?>"
                            data-rate="<?= htmlspecialchars((string) ($vehicle['base_rate'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?>"
                            data-transmission="<?= htmlspecialchars(strtolower((string) ($vehicle['transmission'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                            data-region="Greater Accra"
                        >
                            <div class="h-60 relative overflow-hidden">
                                <img src="<?= htmlspecialchars($vehiclePhoto, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?>" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
                                <div class="absolute top-4 left-4 <?php echo $vehicle['instant_book_enabled'] ? 'bg-brand text-yellow-400' : 'bg-green-500 text-white'; ?> text-[10px] font-black px-3 py-1 rounded-full uppercase">
                                    <?php echo $vehicle['instant_book_enabled'] ? 'Instant Booking' : 'Verified'; ?>
                                </div>
                            </div>
                            <div class="p-6">
                                <div class="flex justify-between items-start mb-2">
                                    <h3 class="text-xl font-black text-gray-900 uppercase"><?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']); ?></h3>
                                    <span class="bg-gray-100 text-brand text-[10px] font-bold px-2 py-1 rounded"><?php echo htmlspecialchars((string) $vehicle['year']); ?></span>
                                </div>
                                <p class="text-gray-500 text-sm mb-6 flex items-center gap-2">
                                    <span class="font-bold"><?php echo htmlspecialchars($vehicle['transmission']); ?></span> • <span class="font-bold"><?php echo htmlspecialchars($vehicle['fuel_type']); ?></span>
                                </p>
                                <div class="flex items-center justify-between pt-6 border-t border-gray-50">
                                    <div>
                                        <p class="text-2xl font-black text-brand">GHS <?php echo htmlspecialchars(number_format((float) $vehicle['base_rate'], 2)); ?><span class="text-xs text-gray-400 font-bold uppercase tracking-tighter">/day</span></p>
                                    </div>
                                    <?php if ($isLoggedIn): ?>
                                        <a href="details.php?id=<?php echo urlencode((string) $vehicle['vehicle_id']); ?>" class="px-6 py-2.5 bg-brand text-white rounded-xl font-bold text-sm hover:bg-yellow-400 hover:text-brand transition">Rent Now</a>
                                    <?php else: ?>
                                        <a href="login.php?redirect=details.php?id=<?php echo urlencode((string) $vehicle['vehicle_id']); ?>" class="px-6 py-2.5 bg-brand text-white rounded-xl font-bold text-sm hover:bg-yellow-400 hover:text-brand transition">Log in to book</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div id="noResultsState" class="hidden mt-12 rounded-[2rem] border border-dashed border-gray-200 bg-white p-12 text-center shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 mb-4">No vehicles available</p>
            <h2 class="text-2xl font-black mb-4">We could not find any vehicles matching your filter.</h2>
            <p class="text-sm text-gray-500 mb-8">Try widening your date range, selecting a different region, or checking back later for more verified listings.</p>
            <a href="browse.php" class="inline-flex px-8 py-4 bg-[#1b4b4b] text-white rounded-3xl uppercase tracking-widest text-xs font-black hover:bg-gray-800 transition">Clear Filters</a>
        </div>
        <div id="vehicleUnavailableState" class="hidden mt-12 rounded-[2rem] border border-yellow-100 bg-yellow-50 p-12 text-center shadow-sm">
            <p class="text-[10px] font-black uppercase tracking-widest text-yellow-700 mb-4">Availability Update</p>
            <h2 class="text-2xl font-black mb-4 text-yellow-800">Selected dates are unavailable for this vehicle.</h2>
            <p class="text-sm text-yellow-700 mb-8">Choose alternative dates or select another vehicle to continue.</p>
            <a href="details.php?id=3" class="inline-flex px-8 py-4 bg-[#1b4b4b] text-white rounded-3xl uppercase tracking-widest text-xs font-black hover:bg-gray-800 transition">View Similar Options</a>
        </div>

        <section id="quick-book" class="mt-24 bg-brand rounded-3xl p-8 md:p-12 text-white">
            <?php if ($isLoggedIn): ?>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                    <div>
                        <h3 class="text-3xl font-black mb-4">Ready to Drive?</h3>
                        <p class="text-gray-300 mb-8 max-w-md">Complete this form and our team will contact you within 15 minutes to confirm your reservation and payment details.</p>
                        <div class="space-y-4">
                            <div class="flex items-center gap-4">
                                <span class="bg-yellow-400 text-brand w-8 h-8 rounded-full flex items-center justify-center font-bold">1</span>
                                <p class="font-bold">Select your dates & vehicle</p>
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="bg-yellow-400 text-brand w-8 h-8 rounded-full flex items-center justify-center font-bold">2</span>
                                <p class="font-bold">Receive instant confirmation</p>
                            </div>
                            <div class="flex items-center gap-4">
                                <span class="bg-yellow-400 text-brand w-8 h-8 rounded-full flex items-center justify-center font-bold">3</span>
                                <p class="font-bold">Pay via MOMO & get your keys</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-8 rounded-2xl shadow-xl text-gray-900">
                        <form action="#" method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('public-browse'), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="text" placeholder="Full Name" class="w-full p-3 bg-gray-50 border border-gray-100 rounded-lg focus:border-brand outline-none" required />
                            <input type="email" placeholder="Email Address" class="w-full p-3 bg-gray-50 border border-gray-100 rounded-lg focus:border-brand outline-none" required />
                            <select class="w-full p-3 bg-gray-50 border border-gray-100 rounded-lg focus:border-brand outline-none">
                                <option>Select Vehicle</option>
                                <?php foreach ($availableVehicles as $vehicleOption): ?>
                                    <option><?php echo htmlspecialchars($vehicleOption['make'] . ' ' . $vehicleOption['model'] . ' (GHS ' . number_format((float) $vehicleOption['base_rate'], 2) . '/day)'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-[10px] font-black uppercase text-gray-400 ml-1">Pickup Date</label>
                                    <input id="quick_pickup" type="date" class="w-full p-3 bg-gray-50 border border-gray-100 rounded-lg outline-none" required />
                                </div>
                                <div>
                                    <label class="text-[10px] font-black uppercase text-gray-400 ml-1">Return Date</label>
                                    <input id="quick_return" type="date" class="w-full p-3 bg-gray-50 border border-gray-100 rounded-lg outline-none" required />
                                </div>
                            </div>
                            <button type="submit" class="w-full p-4 bg-brand text-white font-bold rounded-xl hover:bg-gray-800 shadow-lg transition">Confirm Reservation</button>
                        </form>
                        <script>
                            (function() {
                                var pickupInput = document.getElementById('quick_pickup');
                                var returnInput = document.getElementById('quick_return');

                                function formatLocalDate(date) {
                                    var year = date.getFullYear();
                                    var month = String(date.getMonth() + 1).padStart(2, '0');
                                    var day = String(date.getDate()).padStart(2, '0');
                                    return year + '-' + month + '-' + day;
                                }

                                var today = new Date();
                                var todayValue = formatLocalDate(today);

                                pickupInput.setAttribute('min', todayValue);
                                returnInput.setAttribute('min', todayValue);

                                pickupInput.addEventListener('change', function() {
                                    var selectedPickup = pickupInput.value;
                                    if (!selectedPickup) {
                                        returnInput.setAttribute('min', todayValue);
                                        return;
                                    }

                                    returnInput.setAttribute('min', selectedPickup);

                                    if (returnInput.value && returnInput.value < selectedPickup) {
                                        returnInput.value = '';
                                    }
                                });
                            })();
                        </script>
                    </div>
                </div>
            <?php else: ?>
                <div class="max-w-3xl mx-auto text-center">
                    <p class="text-[10px] font-black uppercase tracking-[0.3em] text-yellow-400 mb-4">Booking locked for guests</p>
                    <h3 class="text-3xl font-black mb-4">Sign in to reserve vehicles, save listings, and message owners.</h3>
                    <p class="text-white/75 mb-8">You can still browse the fleet and compare prices, but booking and checkout require an account.</p>
                    <div class="flex flex-col sm:flex-row justify-center gap-4">
                        <a href="login.php?redirect=browse.php#quick-book" class="px-8 py-3 bg-white text-brand rounded-xl font-bold hover:bg-yellow-400 transition">Log In</a>
                        <a href="register.php" class="px-8 py-3 bg-brand border-2 border-white text-white rounded-xl font-bold hover:bg-white hover:text-brand hover:text-gray-500 transition">Create Account</a>
                    </div>
                </div>
            <?php endif; ?>
        </section>

    </main>

    <script src="../assets/js/site-ui.js"></script>

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
                        <li><a href="privacy.php" class="hover:text-yellow-600 transition">Privacy Policy</a></li>
                        <li><a href="terms.php" class="hover:text-yellow-600 transition">Terms of Service</a></li>
                    </ul>
                </div>

                <div class="lg:col-span-4 md:col-span-4">
                    <h4 class="text-sm font-black uppercase text-gray-900 tracking-widest mb-6">Newsletter</h4>
                    <p class="text-gray-500 text-sm mb-6">Get notified about the latest additions to our fleet and exclusive discounts.</p>
                    <form action="#" method="POST" class="flex gap-2" data-newsletter-form="true">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('public-browse'), ENT_QUOTES, 'UTF-8'); ?>">
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


