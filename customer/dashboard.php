<?php
session_start();
require_once '../db.php';
require_once __DIR__ . '/../includes/security.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'customer-dashboard')) {
        // Invalid CSRF token for dashboard newsletter form - ignore
    }
}

// Security Route Guard: Kick unauthenticated guests back to login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = (int) $_SESSION['user_id'];
$customerName = $_SESSION['user_name'] ?? '';

$profileStmt = $pdo->prepare('SELECT full_name FROM User_Profiles WHERE user_id = :user_id LIMIT 1');
$profileStmt->execute(['user_id' => $userId]);
$profile = $profileStmt->fetch();
if (!$customerName && $profile && !empty($profile['full_name'])) {
    $customerName = $profile['full_name'];
}

$customerName = $customerName ?: 'Customer';
$customerInitials = strtoupper(substr(trim($customerName), 0, 2));

$bookingSummaryStmt = $pdo->prepare(
    'SELECT COUNT(*) AS booking_count
     FROM Bookings
     WHERE customer_id = :user_id'
);
$bookingSummaryStmt->execute(['user_id' => $userId]);
$bookingSummary = $bookingSummaryStmt->fetch();
$totalBookings = (int) ($bookingSummary['booking_count'] ?? 0);

$activeBookingStmt = $pdo->prepare(
    'SELECT b.booking_id, b.start_date, b.end_date, b.booking_status, v.make, v.model, v.vehicle_id
     FROM Bookings b
     JOIN Vehicles v ON b.vehicle_id = v.vehicle_id
     WHERE b.customer_id = :user_id AND b.booking_status IN ("Pending", "Confirmed", "Active", "Picked Up")
     ORDER BY b.start_date ASC
     LIMIT 1'
);
$activeBookingStmt->execute(['user_id' => $userId]);
$activeBooking = $activeBookingStmt->fetch();

$completedBookingStmt = $pdo->prepare(
    'SELECT COUNT(*) AS completed_count
     FROM Bookings
     WHERE customer_id = :user_id AND booking_status = "Completed"'
);
$completedBookingStmt->execute(['user_id' => $userId]);
$completedBookingSummary = $completedBookingStmt->fetch();
$completedBookings = (int) ($completedBookingSummary['completed_count'] ?? 0);

$cartCountStmt = $pdo->prepare('SELECT COUNT(*) AS cart_count FROM Cart_Items WHERE customer_id = :user_id');
$cartCountStmt->execute(['user_id' => $userId]);
$cartCountSummary = $cartCountStmt->fetch();
$savedCars = (int) ($cartCountSummary['cart_count'] ?? 0);

$reviewAvgStmt = $pdo->prepare('SELECT ROUND(AVG(rating_score), 1) AS avg_score FROM Reviews WHERE reviewer_id = :user_id');
$reviewAvgStmt->execute(['user_id' => $userId]);
$reviewAvgSummary = $reviewAvgStmt->fetch();
$reviewAverage = $reviewAvgSummary['avg_score'] ?? '0.0';

$trustStmt = $pdo->prepare('SELECT account_status FROM Users WHERE user_id = :user_id LIMIT 1');
$trustStmt->execute(['user_id' => $userId]);
$trustAccount = $trustStmt->fetch();
$trustStatus = $trustAccount['account_status'] ?? 'Pending';

$authError = $_SESSION['auth_error'] ?? '';
$authSuccess = $_SESSION['auth_success'] ?? '';
unset($_SESSION['auth_error'], $_SESSION['auth_success']);

$csrfToken = csrfToken('customer-dashboard');
$returnCsrfToken = csrfToken('initiate-return');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <title>Customer Dashboard | Smart Rental</title>
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
        .status-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 300ms ease-in-out, visibility 300ms ease-in-out;
            backdrop-filter: blur(4px);
        }
        .modal-backdrop.active {
            opacity: 1;
            visibility: visible;
        }
        .modal-backdrop .modal-content {
            transform: translateY(20px) scale(0.95);
            transition: transform 300ms ease-out;
        }
        .modal-backdrop.active .modal-content {
            transform: translateY(0) scale(1);
        }
    </style>
</head>
<body class="font-['Segoe_UI',Tahoma,Geneva,Verdana,sans-serif] bg-[#f9f9f8] text-[#1b4b4b] antialiased">

    <?php require_once 'includes/header.php'; ?>

    <main class="max-w-7xl mx-auto px-6 py-8">

        <?php if ($authError || $authSuccess): ?>
            <div class="mb-8 rounded-2xl border px-4 py-4 shadow-sm <?php echo $authError ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700'; ?>">
                <p class="text-sm font-medium"><?php echo htmlspecialchars($authError ?: $authSuccess); ?></p>
            </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-10">
            <div class="lg:col-span-2 bg-white p-8 rounded-[2rem] shadow-sm border border-gray-100 flex flex-col justify-center">
                <h2 class="text-3xl font-black uppercase tracking-tight mb-2">Welcome back, <?php echo htmlspecialchars(explode(' ', $customerName)[0]); ?>!</h2>
                <p class="text-gray-500 font-medium">You have <span class="text-[#1b4b4b] font-bold"><?php echo htmlspecialchars((string) $totalBookings); ?> total booking<?php echo $totalBookings === 1 ? '' : 's'; ?></span> and <span class="text-[#1b4b4b] font-bold"><?php echo htmlspecialchars((string) max(0, $totalBookings - $completedBookings)); ?> active or upcoming trip<?php echo max(0, $totalBookings - $completedBookings) === 1 ? '' : 's'; ?></span>.</p>
                <div class="mt-6 flex gap-3">
                    <a href="browse.php" class="bg-[#1b4b4b] text-white px-6 py-3 rounded-xl font-bold text-xs uppercase tracking-widest hover:bg-gray-800 transition shadow-lg">Book New Vehicle</a>
                </div>
            </div>

            <div class="bg-[#1b4b4b] p-8 rounded-[2rem] text-white shadow-xl relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -mr-16 -mt-16"></div>
                <h3 class="text-[10px] font-black uppercase tracking-[0.2em] text-[#facd05] mb-4">Trust Level</h3>
                <div class="flex items-center gap-4 mb-4">
                    <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center">
                        <svg class="w-6 h-6 text-[#facd05]" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0110 1.944 11.954 11.954 0 0117.834 5c.11.65.166 1.32.166 2.001 0 4.908-3.333 9.277-8 10.125a11.954 11.954 0 01-8-10.124c0-.681.056-1.35.166-2.002zM10 11.75a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" clip-rule="evenodd"></path></svg>
                    </div>
                    <div>
                        <p class="text-xl font-black"><?php echo htmlspecialchars($trustStatus); ?></p>
                        <p class="text-[10px] opacity-70 font-bold uppercase">Identity & Payment Status</p>
                    </div>
                </div>
                <p class="text-xs opacity-80 leading-relaxed">Your portal status is synchronized with your account record.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            
            <div class="lg:col-span-8 space-y-8">
                
                <div data-booking-tracker="true" <?php echo $activeBooking ? 'data-booking-id="' . htmlspecialchars((string) $activeBooking['booking_id']) . '"' : ''; ?>>
                    <h3 class="text-sm font-black uppercase tracking-widest text-gray-400 mb-6 flex items-center gap-3">
                        <span class="w-2 h-2 bg-green-500 rounded-full <?php echo $activeBooking ? 'animate-pulse' : ''; ?>"></span> Active Rental
                    </h3>
                    
                    <div class="bg-white rounded-[2rem] border border-gray-100 shadow-sm overflow-hidden flex flex-col md:flex-row">
                        <div class="w-full md:w-64 h-48 bg-gray-100">
                            <img src="./assets/images/Cars/SUVs/ford-explorer.jpg" alt="Active rental preview" class="w-full h-full object-cover">
                        </div>
                        <div class="p-8 flex-grow flex flex-col justify-between">
                            <div class="flex justify-between items-start">
                                <div>
                                    <h4 class="text-xl font-black uppercase"><?php echo htmlspecialchars($activeBooking ? trim($activeBooking['make'] . ' ' . $activeBooking['model']) : 'No active booking'); ?></h4>
                                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest"><?php echo $activeBooking ? htmlspecialchars('Return: ' . (new DateTime($activeBooking['end_date']))->format('M d, Y, h:i A')) : 'No scheduled return'; ?></p>
                                </div>
                                <span data-status-badge="true" class="<?php echo $activeBooking ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?> text-[10px] font-black px-3 py-1 rounded-full uppercase" data-booking-status="<?php echo htmlspecialchars($activeBooking['booking_status'] ?? 'No Active Booking'); ?>"><?php echo htmlspecialchars($activeBooking['booking_status'] ?? 'No Active Booking'); ?></span>
                            </div>
                            
                            <?php if ($activeBooking): ?>
                                <div class="mt-6 flex flex-wrap gap-3">
                                    <a href="booking-confirm&status.php?booking_id=<?php echo urlencode((string) $activeBooking['booking_id']); ?>" class="bg-[#1b4b4b] text-white px-5 py-2.5 rounded-xl font-bold text-[10px] uppercase tracking-widest shadow-md hover:bg-gray-800 transition">View Handover QR</a>
                                    <button data-initiate-return-btn="true" data-booking-id="<?php echo htmlspecialchars((string) $activeBooking['booking_id']); ?>" class="bg-yellow-100 text-yellow-700 px-5 py-2.5 rounded-xl font-bold text-[10px] uppercase tracking-widest shadow-md hover:bg-yellow-200 transition">Initiate Return</button>
                                    <a href="notifications.php" class="bg-white border border-gray-200 text-gray-500 px-5 py-2.5 rounded-xl font-bold text-[10px] uppercase tracking-widest hover:bg-gray-50 transition">Message Owner</a>
                                </div>
                            <?php else: ?>
                                <div class="mt-6">
                                    <p class="text-sm text-gray-500">You do not have an active rental right now.</p>
                                    <a href="browse.php" class="inline-flex mt-4 bg-[#1b4b4b] text-white px-5 py-2.5 rounded-xl font-bold text-[10px] uppercase tracking-widest shadow-md hover:bg-gray-800 transition">Browse Vehicles</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($activeBooking): ?>
                <div data-gps-tracker="true" data-booking-id="<?php echo htmlspecialchars((string) $activeBooking['booking_id']); ?>" class="bg-white rounded-[2rem] border border-gray-100 shadow-sm p-8">
                    <h3 class="text-sm font-black uppercase tracking-widest text-gray-400 mb-6 flex items-center gap-3">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg> Live GPS Tracking
                    </h3>

                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4 mb-6">
                            <div class="bg-gray-50 p-4 rounded-xl">
                                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Current Latitude</p>
                                <p class="text-lg font-black text-[#1b4b4b]" data-current-latitude="--">--</p>
                            </div>
                            <div class="bg-gray-50 p-4 rounded-xl">
                                <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Current Longitude</p>
                                <p class="text-lg font-black text-[#1b4b4b]" data-current-longitude="--">--</p>
                            </div>
                        </div>

                        <div class="bg-gray-50 p-4 rounded-xl">
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Route History</p>
                            <p class="text-sm text-gray-600" data-route-points="No GPS data available yet">No GPS data available yet</p>
                        </div>

                        <div class="rounded-xl overflow-hidden border border-gray-100 bg-white" data-map-embed="true" style="height: 320px;">
                            <div class="w-full h-full flex items-center justify-center bg-gray-100">
                                <p class="text-gray-400 text-sm">Loading map...</p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-white p-6 rounded-3xl border border-gray-50 text-center">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Total Trips</p>
                        <p class="text-2xl font-black text-[#1b4b4b]"><?php echo htmlspecialchars((string) $totalBookings); ?></p>
                    </div>
                    <div class="bg-white p-6 rounded-3xl border border-gray-50 text-center">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Reliability</p>
                        <p class="text-2xl font-black text-green-500"><?php echo htmlspecialchars((string) ($reviewAverage ? $reviewAverage . '/5' : '0.0')); ?></p>
                    </div>
                    <div class="bg-white p-6 rounded-3xl border border-gray-50 text-center">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Saved Cars</p>
                        <p class="text-2xl font-black text-[#1b4b4b]"><?php echo htmlspecialchars((string) $savedCars); ?></p>
                    </div>
                    <div class="bg-white p-6 rounded-3xl border border-gray-50 text-center">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Reviews</p>
                        <p class="text-2xl font-black text-[#facd05]"><?php echo htmlspecialchars((string) $reviewAverage); ?></p>
                    </div>
                </div>

            </div>

            <div class="lg:col-span-4 space-y-8">
                
                <div data-notifications-container="true" class="bg-white rounded-[2rem] p-8 shadow-sm border border-gray-100">
                    <h3 class="text-sm font-black uppercase tracking-widest text-gray-400 mb-6 flex items-center justify-between">
                        <span>Live Notifications</span>
                        <span data-unread-count class="bg-red-500 text-white text-[9px] font-black px-2 py-0.5 rounded-full"></span>
                    </h3>
                    
                    <div class="space-y-4 max-h-96 overflow-y-auto">
                        <div class="flex gap-4">
                            <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center shrink-0 text-blue-600 animate-pulse">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            </div>
                            <div>
                                <p class="text-xs font-black text-gray-800">Loading notifications...</p>
                                <p class="text-[10px] text-gray-500 mt-1">Fetching your latest alerts and messages.</p>
                                <p class="text-[9px] font-bold text-gray-400 mt-2 uppercase tracking-widest">Just now</p>
                            </div>
                        </div>
                    </div>

                    <button class="w-full mt-6 pt-4 border-t border-gray-50 text-[10px] font-black text-gray-400 uppercase hover:text-[#1b4b4b] transition">View All Activity</button>
                </div>

                <div class="bg-gray-900 rounded-[2rem] p-8 text-white">
                    <h4 class="text-xs font-black uppercase tracking-widest text-[#facd05] mb-4">24/7 Roadside Support</h4>
                    <p class="text-[10px] opacity-70 mb-6">Need emergency assistance with your active rental?</p>
                    <a href="tel:+233200853940" class="block w-full text-center bg-white/10 hover:bg-white/20 py-3 rounded-xl font-black text-xs uppercase tracking-widest transition">Call Support</a>
                </div>

            </div>
        </div>
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
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
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

    <!-- Return Vehicle Modal -->
    <div data-return-modal="true" class="modal-backdrop">
        <div class="modal-content bg-white rounded-[2rem] shadow-2xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="p-8 border-b border-gray-100 flex items-center justify-between">
                <h2 class="text-2xl font-black uppercase">Initiate Vehicle Return</h2>
                <button data-close-modal="true" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
            </div>

            <div class="p-8 space-y-6">
                <div>
                    <label class="block text-sm font-black uppercase text-gray-700 mb-2">Return Location *</label>
                    <input type="text" data-return-location="true" placeholder="e.g., Airport Terminal 2, Downtown Office" class="w-full p-4 border border-gray-200 rounded-xl outline-none focus:border-[#1b4b4b] focus:ring-2 focus:ring-[#1b4b4b]/10">
                    <p class="text-[10px] text-gray-500 mt-1">Where will you be returning the vehicle?</p>
                </div>

                <div>
                    <label class="block text-sm font-black uppercase text-gray-700 mb-2">Additional Notes</label>
                    <textarea data-return-notes="true" placeholder="Any special instructions or damage observations..." rows="4" class="w-full p-4 border border-gray-200 rounded-xl outline-none focus:border-[#1b4b4b] focus:ring-2 focus:ring-[#1b4b4b]/10 resize-none"></textarea>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
                    <p class="text-sm text-blue-900"><strong>Note:</strong> Once you initiate return, the vehicle owner will be notified immediately. They will conduct a final inspection and process the return.</p>
                </div>
            </div>

            <div class="p-8 border-t border-gray-100 flex gap-4">
                <button data-close-modal="true" class="flex-1 px-6 py-3 bg-gray-100 text-gray-700 rounded-xl font-bold text-sm uppercase hover:bg-gray-200 transition">Cancel</button>
                <button data-submit-return="true" class="flex-1 px-6 py-3 bg-[#1b4b4b] text-white rounded-xl font-bold text-sm uppercase hover:bg-gray-800 transition">Confirm Return</button>
            </div>
        </div>
    </div>

    <script src="./js/cust-ui.js"></script>

</body>
</html>
