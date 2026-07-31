<?php
session_start();
require_once '../db.php';
require_once __DIR__ . '/../includes/asset-helper.php';
require_once __DIR__ . '/../includes/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$statusStmt = $pdo->prepare('SELECT account_status FROM Users WHERE user_id = :user_id LIMIT 1');
$statusStmt->execute(['user_id' => $_SESSION['user_id']]);
$accountStatus = $statusStmt->fetchColumn();
if ($accountStatus !== 'Active') {
    header('Location: verify-email.php');
    exit();
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

$dailyRate = number_format((float) $vehicle['base_rate'], 2);
$vehicleName = htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model']);
$vehicleYear = htmlspecialchars((string) $vehicle['year']);
$vehicleClass = htmlspecialchars($vehicle['transmission'] . ' • ' . $vehicle['fuel_type']);
$vehicleImage = srVehiclePhotoFromDatabase($pdo, (int) $vehicle['vehicle_id'], (string) $vehicle['make'], (string) $vehicle['model']);
$customerName = $_SESSION['user_name'] ?? 'Customer';
$customerInitials = strtoupper(substr(trim($customerName), 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Booking | Smart Rental</title>
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
        .step-active { background-color: var(--brand-primary); color: white; }
        .step-pending { background-color: #e2e8f0; color: #64748b; }
    </style>
</head>
<body class="font-['Segoe_UI',Tahoma,Geneva,Verdana,sans-serif] bg-[#f9f9f8] text-[#1b4b4b] antialiased">

    <?php require_once 'includes/header.php'; ?>

    </header>
    <div class="bg-white border-b border-gray-100">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="logo">
                <h1 class="sr-only">Secure Checkout</h1>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-[10px] font-black uppercase text-gray-400">Secure Checkout</span>
                <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 4.908-3.333 9.277-8 10.125a11.954 11.954 0 01-8-10.124c0-.681.056-1.35.166-2.002zM10 11.75a1.5 1.5 0 100-3 1.5 1.5 0 000 3z" clip-rule="evenodd"></path></svg>
            </div>
        </div>
    </div>

    <main class="max-w-5xl mx-auto px-6 py-12">
        
        <div class="flex items-center justify-center mb-12">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full flex items-center justify-center font-black step-active">1</div>
                <div class="h-1 w-12 bg-[#1b4b4b]"></div>
                <div class="w-10 h-10 rounded-full flex items-center justify-center font-black step-pending">2</div>
                <div class="h-1 w-12 bg-gray-200"></div>
                <div class="w-10 h-10 rounded-full flex items-center justify-center font-black step-pending">3</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-12">
            
            <div class="lg:col-span-2 space-y-8">
                <div class="bg-white rounded-3xl p-8 shadow-sm border border-gray-100">
                    <h2 class="text-2xl font-black uppercase tracking-tight mb-6">Reservation Details</h2>
                    
                    <form action="cart.php" method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken('customer-cart'), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="vehicle_id" value="<?php echo htmlspecialchars($vehicleId); ?>">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="flex flex-col gap-2">
                                <label class="text-[10px] font-black uppercase text-gray-400 ml-1">Pickup Date</label>
                                <input type="date" name="start_date" class="w-full p-4 bg-gray-50 border border-gray-100 rounded-2xl outline-none focus:border-[#1b4b4b] transition" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="flex flex-col gap-2">
                                <label class="text-[10px] font-black uppercase text-gray-400 ml-1">Return Date</label>
                                <input type="date" name="end_date" class="w-full p-4 bg-gray-50 border border-gray-100 rounded-2xl outline-none focus:border-[#1b4b4b] transition" required>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <label class="text-[10px] font-black uppercase text-gray-400 ml-1">Pickup Location</label>
                            <select name="pickup_location" class="w-full p-4 bg-gray-50 border border-gray-100 rounded-2xl outline-none focus:border-[#1b4b4b] transition">
                                <option>Accra Mall Parking Lot B</option>
                                <option>Kotoka International Airport (Terminal 3)</option>
                                <option>Kumasi City Mall</option>
                                <option>West Hills Mall</option>
                            </select>
                            <p class="text-[10px] text-gray-400 italic ml-1">Handover instructions will be sent via Messages after payment.</p>
                        </div>

                        <hr class="border-gray-50 my-8">

                        <h3 class="text-sm font-black uppercase tracking-widest mb-4">Enhance Your Experience</h3>
                        <div class="space-y-4">
                            <label class="flex items-center justify-between p-4 rounded-2xl border border-gray-100 hover:border-[#1b4b4b] cursor-pointer transition group">
                                <div class="flex items-center gap-4">
                                    <input type="checkbox" name="chauffeur_toggle" class="w-5 h-5 accent-[#1b4b4b]">
                                    <div>
                                        <p class="text-sm font-bold">Add Chauffeur</p>
                                        <p class="text-[10px] text-gray-500">GHS 150.00 / day</p>
                                    </div>
                                </div>
                                <span class="text-[#1b4b4b] opacity-0 group-hover:opacity-100 transition text-[10px] font-black uppercase">Add Service</span>
                            </label>

                            <label class="flex items-center justify-between p-4 rounded-2xl border border-gray-100 hover:border-[#1b4b4b] cursor-pointer transition group">
                                <div class="flex items-center gap-4">
                                    <input type="checkbox" name="gps" class="w-5 h-5 accent-[#1b4b4b]">
                                    <div>
                                        <p class="text-sm font-bold">GPS Navigation Unit</p>
                                        <p class="text-[10px] text-gray-500">GHS 20.00 / day</p>
                                    </div>
                                </div>
                                <span class="text-[#1b4b4b] opacity-0 group-hover:opacity-100 transition text-[10px] font-black uppercase">Add Item</span>
                            </label>
                        </div>

                        <div class="pt-8">
                            <button type="submit" class="w-full bg-[#1b4b4b] text-white py-5 rounded-2xl font-black text-lg uppercase tracking-widest hover:bg-gray-800 shadow-xl transform active:scale-95 transition">
                                Review & Calculate Total
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="space-y-6">
                <div class="bg-white rounded-3xl overflow-hidden shadow-sm border border-gray-100">
                    <div class="h-40 bg-gray-200">
                        <img src="<?= htmlspecialchars($vehicleImage, ENT_QUOTES, 'UTF-8'); ?>" class="w-full h-full object-cover" alt="Selected vehicle">
                    </div>
                    <div class="p-6">
                        <h3 class="font-black uppercase text-brand"><?php echo $vehicleName; ?></h3>
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest"><?php echo htmlspecialchars($vehicleClass); ?></p>
                        <div class="mt-4 pt-4 border-t border-gray-50">
                            <p class="text-xl font-black">GHS <?php echo htmlspecialchars($dailyRate); ?><span class="text-xs font-normal text-gray-400 tracking-tighter uppercase">/day</span></p>
                        </div>
                    </div>
                </div>

                <div class="bg-yellow-50 rounded-3xl p-6 border border-yellow-100">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-8 h-8 bg-yellow-400 rounded-full flex items-center justify-center text-brand">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"></path><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"></path></svg>
                        </div>
                        <h4 class="text-xs font-black uppercase tracking-tight text-yellow-800">Your Trust Score</h4>
                    </div>
                    <p class="text-xs text-yellow-700 font-medium leading-relaxed">
                        Your profile is <span class="font-black"><?php echo htmlspecialchars($accountStatus === 'Active' ? 'Verified' : 'Pending'); ?></span>. You are eligible for booking and escrow protection on this vehicle.
                    </p>
                </div>

                <div class="p-4 text-center">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Safe Handover Policy</p>
                    <p class="text-[10px] text-gray-400 mt-2">Always inspect the vehicle and document its condition in the app during pickup to ensure your security deposit is protected.</p>
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
                        <li><a href="privacy.php" class="hover:text-yellow-600 transition">Privacy Policy</a></li>
                        <li><a href="terms.php" class="hover:text-yellow-600 transition">Terms of Service</a></li>
                    </ul>
                </div>

                <div class="lg:col-span-4 md:col-span-4">
                    <h4 class="text-sm font-black uppercase text-gray-900 tracking-widest mb-6">Newsletter</h4>
                    <p class="text-gray-500 text-sm mb-6">Get notified about the latest additions to our fleet and exclusive discounts.</p>
                    <form action="#" method="POST" class="flex gap-2" data-newsletter-form="true">
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

    <script>
        const pickupDate = document.querySelector('input[name="start_date"]');
        const returnDate = document.querySelector('input[name="end_date"]');
        function validateDates() {
            if (!pickupDate || !returnDate) return true;
            const today = new Date();
            today.setHours(0,0,0,0);

            const pickup = new Date(pickupDate.value);
            const returning = new Date(returnDate.value);

            if (pickupDate.value && pickup < today) {
                if (window.CustUI && typeof window.CustUI.showToast === 'function') {
                    window.CustUI.showToast('error', 'Pickup date cannot be in the past');
                }
                return false;
            }

            if (pickupDate.value && returnDate.value && returning <= pickup) {
                if (window.CustUI && typeof window.CustUI.showToast === 'function') {
                    window.CustUI.showToast('error', 'Return date must be after pickup date');
                }
                return false;
            }

            return true;
        }

        if (pickupDate) {
            pickupDate.addEventListener('change', function() {
                if (returnDate) {
                    returnDate.min = this.value;
                    if (returnDate.value && returnDate.value <= this.value) {
                        returnDate.value = '';
                    }
                }
                validateDates();
            });
        }

        if (returnDate) {
            returnDate.addEventListener('change', validateDates);
        }
    </script>
    <script src="./js/cust-ui.js"></script>
</body>
</html>


