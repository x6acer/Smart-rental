<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    session_start();
    require_once '../db.php';
    require_once __DIR__ . '/../includes/asset-helper.php';
    require_once __DIR__ . '/../includes/security.php';
} catch (Throwable $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error occurred.']);
    exit;
}

// Detect AJAX / JSON requests early
$isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

// Security Route Guard: Kick unauthenticated guests back to login
if (!isset($_SESSION['user_id'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(401);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Please sign in to continue.']);
        exit();
    }
    header("Location: login.php");
    exit();
}

$userStatusStmt = $pdo->prepare('SELECT account_status FROM Users WHERE user_id = :user_id LIMIT 1');
$userStatusStmt->execute(['user_id' => $_SESSION['user_id']]);
$userStatus = $userStatusStmt->fetchColumn();
if ($userStatus !== 'Active') {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(403);
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Account verification required.']);
        exit();
    }
    header('Location: verify-email.php');
    exit();
}

$userId = $_SESSION['user_id'];
$identityVerified = isCustomerIdentityVerified($pdo, $userId);
$customerName = $_SESSION['user_name'] ?? 'Customer';
$customerInitials = strtoupper(substr(trim($customerName), 0, 2));
$errors = [];
$success = '';
$selectedCart = null;
$cartCsrfToken = csrfToken('customer-cart');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // detect AJAX / JSON requests
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    // Support remove action via AJAX
    if (isset($_POST['action']) && $_POST['action'] === 'remove') {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'customer-cart')) {
            if ($isAjax) {
                header('Content-Type: application/json');
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Security check failed.']);
                exit;
            }
            $errors[] = 'Security check failed. Please try again.';
        } else {
            $cartId = filter_input(INPUT_POST, 'cart_id', FILTER_VALIDATE_INT);
            if ($cartId) {
                try {
                    $del = $pdo->prepare('DELETE FROM Cart_Items WHERE cart_id = :cart_id AND customer_id = :customer_id');
                    $del->execute(['cart_id' => $cartId, 'customer_id' => $userId]);
                } catch (PDOException $e) {
                    error_log('Cart removal failed: ' . $e->getMessage());
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        ob_end_clean();
                        echo json_encode(['success' => false, 'message' => 'Unable to remove item.']);
                        exit;
                    }
                    $errors[] = 'Unable to remove item.';
                }

                // Recalculate totals for response
                $totalsStmt = $pdo->prepare(
                    'SELECT ci.cart_id, ci.temp_start_date, ci.temp_end_date, ci.chauffeur_selected, v.base_rate
                     FROM Cart_Items ci
                     JOIN Vehicles v ON ci.vehicle_id = v.vehicle_id
                     WHERE ci.customer_id = :customer_id'
                );
                $totalsStmt->execute(['customer_id' => $userId]);
                $all = $totalsStmt->fetchAll();
                $subtotal = 0.0;
                foreach ($all as $it) {
                    $days = 1;
                    try {
                        $d1 = new DateTime($it['temp_start_date']);
                        $d2 = new DateTime($it['temp_end_date']);
                        $interval = $d1->diff($d2);
                        $days = max(1, (int) $interval->days);
                    } catch (Throwable $t) {
                        $days = 1;
                    }
                    $subtotal += ((float) $it['base_rate']) * $days;
                    if ($it['chauffeur_selected']) {
                        $subtotal += 150.00 * $days;
                    }
                }
                $service = count($all) > 0 ? 45 : 0;
                $total = $subtotal + $service;

                if ($isAjax) {
                    header('Content-Type: application/json');
                    ob_end_clean();
                    echo json_encode([
                        'success' => true,
                        'message' => 'Item removed from cart',
                        'subtotal' => number_format($subtotal, 2),
                        'total' => number_format($total, 2),
                        'count' => count($all),
                        'totalsHtml' => '<div class="mb-4"><div class="flex justify-between text-sm"><span class="text-gray-500">Subtotal</span><span class="font-bold">GHS ' . number_format($subtotal, 2) . '</span></div><div class="flex justify-between text-sm"><span class="text-gray-500">Service Fee</span><span class="font-bold">GHS ' . number_format($service, 2) . '</span></div><div class="pt-4 border-t border-gray-100 flex justify-between items-center"><span class="text-lg font-black uppercase">Total Cost</span><span class="text-2xl font-black text-[#1b4b4b]">GHS ' . number_format($total, 2) . '</span></div></div>'
                    ]);
                    exit;
                }
            }
        }
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'customer-cart')) {
        $errors[] = 'Security check failed. Please try again.';
        if (!empty($isAjax)) {
            header('Content-Type: application/json');
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Security check failed. Please try again.']);
            exit;
        }
    } else {
    $vehicleId = filter_input(INPUT_POST, 'vehicle_id', FILTER_VALIDATE_INT);
    $startDate = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
    $endDate = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
    $pickupLocation = trim($_POST['pickup_location'] ?? '');
    $chauffeurSelected = isset($_POST['chauffeur_toggle']) ? 1 : 0;

    if (!$vehicleId || !$startDate || !$endDate) {
        $errors[] = 'Please complete the full booking request before proceeding.';
    } else {
        try {
            $startDateTime = new DateTime($startDate);
            $endDateTime = new DateTime($endDate);

            if ($startDateTime >= $endDateTime) {
                $errors[] = 'Pickup date must be before return date.';
            } elseif ($startDateTime < new DateTime('today')) {
                $errors[] = 'Pickup date cannot be in the past.';
            } else {
                $vehicleCheck = $pdo->prepare('SELECT status FROM Vehicles WHERE vehicle_id = :vehicle_id LIMIT 1');
                $vehicleCheck->execute(['vehicle_id' => $vehicleId]);
                $vehicle = $vehicleCheck->fetch();

                if (! $vehicle || $vehicle['status'] !== 'Active') {
                    $errors[] = 'This vehicle is no longer available for booking.';
                } else {
                    $availabilityCheck = $pdo->prepare(
                        'SELECT COUNT(*) AS conflicting
                         FROM Bookings
                         WHERE vehicle_id = :vehicle_id
                           AND booking_status IN ("Pending", "Confirmed", "Active")
                           AND NOT (end_date <= :start_date OR start_date >= :end_date)'
                    );
                    $availabilityCheck->execute([
                        'vehicle_id' => $vehicleId,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ]);
                    $conflict = (int) ($availabilityCheck->fetchColumn() ?? 0);

                    if ($conflict > 0) {
                        $errors[] = 'The selected vehicle is unavailable for the chosen dates. Please select a different period.';
                    } else {
                        $insertCart = $pdo->prepare(
                            'INSERT INTO Cart_Items (customer_id, vehicle_id, chauffeur_selected, temp_start_date, temp_end_date, pickup_location) VALUES (:customer_id, :vehicle_id, :chauffeur_selected, :temp_start_date, :temp_end_date, :pickup_location)'
                        );
                        $insertCart->execute([
                            'customer_id' => $userId,
                            'vehicle_id' => $vehicleId,
                            'chauffeur_selected' => $chauffeurSelected,
                            'temp_start_date' => $startDate,
                            'temp_end_date' => $endDate,
                            'pickup_location' => $pickupLocation,
                        ]);
                        $success = 'Your booking request was added to the cart successfully.';

                        if (!empty($isAjax)) {
                            // return JSON summary for client
                            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM Cart_Items WHERE customer_id = :customer_id');
                            $countStmt->execute(['customer_id' => $userId]);
                            $cartCount = (int) $countStmt->fetchColumn();
                            header('Content-Type: application/json');
                            ob_end_clean();
                            echo json_encode(['success' => true, 'message' => $success, 'cart_count' => $cartCount]);
                            exit;
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('Cart insertion failed: ' . $e->getMessage());
            $errors[] = 'Unable to add booking to cart. Please try again later.';
        } catch (Throwable $e) {
            $errors[] = 'Invalid booking dates. Please verify and try again.';
            if (!empty($isAjax)) {
                header('Content-Type: application/json');
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Invalid booking dates. Please verify and try again.']);
                exit;
            }
        }
    }
    }
}

// Return JSON for AJAX requests with validation errors
if (!empty($isAjax) && !empty($errors)) {
    header('Content-Type: application/json');
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

$cartStmt = $pdo->prepare(
    'SELECT ci.cart_id, ci.vehicle_id, ci.chauffeur_selected, ci.temp_start_date, ci.temp_end_date, ci.pickup_location,
            v.make, v.model, v.base_rate, v.transmission, v.fuel_type
     FROM Cart_Items ci
     JOIN Vehicles v ON ci.vehicle_id = v.vehicle_id
     WHERE ci.customer_id = :customer_id
     ORDER BY ci.cart_id DESC'
);
$cartStmt->execute(['customer_id' => $userId]);
$cartItems = $cartStmt->fetchAll();
if (count($cartItems) > 0) {
    $selectedCart = $cartItems[0];
}

$selectedCartDays = 0;
$selectedCartBase = 0.0;
$selectedCartChauffeurFee = 0.0;
$selectedCartTotal = 0.0;
if ($selectedCart) {
    $selectedCartDays = calculateDays($selectedCart['temp_start_date'], $selectedCart['temp_end_date']);
    $selectedCartBase = (float) $selectedCart['base_rate'] * $selectedCartDays;
    $selectedCartChauffeurFee = $selectedCart['chauffeur_selected'] ? 150.00 * $selectedCartDays : 0.00;
    $selectedCartTotal = $selectedCartBase + $selectedCartChauffeurFee;
    $selectedCartImage = srVehiclePhotoFromDatabase(
        $pdo,
        (int) $selectedCart['vehicle_id'],
        (string) $selectedCart['make'],
        (string) $selectedCart['model']
    );
}

function calculateDays(string $start, string $end): int
{
    $startDate = new DateTime($start);
    $endDate = new DateTime($end);
    $interval = $startDate->diff($endDate);
    return max(1, (int) $interval->days);
}

function formatMoney(float $value): string
{
    return number_format($value, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Booking | Smart Rental</title>
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
    </style>
</head>
<body class="font-['Segoe_UI',Tahoma,Geneva,Verdana,sans-serif] bg-[#f9f9f8] text-[#1b4b4b] antialiased">
    <input type="hidden" name="csrf_token" id="customer-cart-csrf" value="<?php echo htmlspecialchars($cartCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">

    <?php require_once 'includes/header.php'; ?>
    <div class="bg-white border-b border-gray-100">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4 text-xs font-black uppercase tracking-widest text-gray-400">
                <span>1. Select</span>
                <span class="text-[#1b4b4b]">2. Review</span>
                <span>3. Payment</span>
            </div>
        </div>
    </div>

    <main class="max-w-7xl mx-auto px-6 py-12">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12">
            
            <div class="lg:col-span-8 space-y-8">
                <h2 class="text-3xl font-black uppercase tracking-tight">Review Your Selection</h2>
                <?php if (!$identityVerified): ?>
                    <div class="rounded-3xl border border-amber-200 bg-amber-50 p-5 mt-6 text-sm font-semibold text-amber-900">
                        Your identity verification is still pending. You can review booking details here, but you must complete verification before paying.
                        <a href="complete-profile.php" class="inline-block ml-1 text-[#1b4b4b] font-bold underline">Verify now</a>
                    </div>
                <?php endif; ?>
                
                <?php if ($selectedCart): ?>
                    <div data-cart-row="<?= htmlspecialchars((string) $selectedCart['cart_id'], ENT_QUOTES, 'UTF-8'); ?>" class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100 flex flex-col md:flex-row gap-8 items-center">
                        <div class="w-full md:w-64 h-40 rounded-2xl overflow-hidden bg-gray-50 shrink-0">
                            <img src="<?= htmlspecialchars($selectedCartImage ?? srAsset('images/Cars/SUVs/ford-explorer.jpg'), ENT_QUOTES, 'UTF-8'); ?>" alt="Selected Vehicle" class="w-full h-full object-cover">
                        </div>
                        <div class="flex-grow">
                            <div class="flex justify-between items-start">
                                <h3 class="text-xl font-black uppercase"><?php echo htmlspecialchars($selectedCart['make'] . ' ' . $selectedCart['model']); ?></h3>
                                <button data-cart-remove="<?= htmlspecialchars((string) $selectedCart['cart_id'], ENT_QUOTES, 'UTF-8'); ?>" class="text-red-500 text-[10px] font-black uppercase hover:underline" type="button">Remove</button>
                            </div>
                            <p class="text-xs font-bold text-gray-400 mt-1 uppercase tracking-widest"><?php echo htmlspecialchars($selectedCart['transmission'] . ' • ' . $selectedCart['fuel_type']); ?></p>
                            <div class="grid grid-cols-2 gap-4 mt-6">
                                <div>
                                    <p class="text-[10px] font-black text-gray-400 uppercase">Pickup</p>
                                    <p class="text-sm font-bold"><?php echo htmlspecialchars((new DateTime($selectedCart['temp_start_date']))->format('M d, Y')); ?></p>
                                </div>
                                <div>
                                    <p class="text-[10px] font-black text-gray-400 uppercase">Return</p>
                                    <p class="text-sm font-bold"><?php echo htmlspecialchars((new DateTime($selectedCart['temp_end_date']))->format('M d, Y')); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-3xl p-8 shadow-sm border border-gray-100">
                <?php else: ?>
                    <div class="bg-white rounded-3xl p-8 shadow-sm border border-gray-100 text-center">
                        <p class="text-sm font-black uppercase tracking-widest text-gray-400">Your cart is currently empty.</p>
                        <p class="mt-4 text-sm text-gray-600">Select a vehicle and fill in your booking details before proceeding to payment.</p>
                    </div>

                    <div class="bg-white rounded-3xl p-8 shadow-sm border border-gray-100">
                <?php endif; ?>
                    <h4 class="text-sm font-black uppercase tracking-widest mb-6">Additional Options</h4>
                    <form>
                        <div class="space-y-4">
                            <label class="flex items-center justify-between p-4 rounded-2xl border border-gray-100 hover:border-[#1b4b4b] cursor-pointer transition">
                                <div class="flex items-center gap-4">
                                    <input type="checkbox" name="chauffeur" class="w-5 h-5 accent-[#1b4b4b]" <?php echo $selectedCart && $selectedCart['chauffeur_selected'] ? 'checked' : ''; ?> disabled>
                                    <div>
                                        <p class="text-sm font-bold">Professional Chauffeur</p>
                                        <p class="text-[10px] text-gray-500 font-medium">Daily driver service within city limits</p>
                                    </div>
                                </div>
                                <span class="text-sm font-black">+ GHS 150/day</span>
                            </label>

                            <label class="flex items-center justify-between p-4 rounded-2xl border border-gray-100 hover:border-[#1b4b4b] cursor-pointer transition">
                                <div class="flex items-center gap-4">
                                    <input type="checkbox" name="full_insurance" class="w-5 h-5 accent-[#1b4b4b]" checked disabled>
                                    <div>
                                        <p class="text-sm font-bold">Premium Insurance Coverage</p>
                                        <p class="text-[10px] text-gray-500 font-medium">Zero-excess theft and damage protection</p>
                                    </div>
                                </div>
                                <span class="text-sm font-black">+ GHS 80/day</span>
                            </label>
                        </div>
                    </form>
                </div>

                <div class="bg-blue-50 p-6 rounded-2xl border border-blue-100 flex gap-4">
                    <span class="text-2xl">🛡️</span>
                    <div>
                        <p class="text-sm font-bold text-blue-900">Escrow Protected Payment</p>
                        <p class="text-xs text-blue-700 mt-1">Your payment is held securely in escrow. Funds are only released to the owner 24 hours after you successfully pick up the vehicle.</p>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-4">
                <div class="bg-white rounded-3xl p-8 shadow-xl border border-gray-100 sticky top-24">
                    <h3 class="text-lg font-black uppercase tracking-tight mb-6 border-b border-gray-50 pb-4">Booking Summary</h3>
                    
                    <?php if ($selectedCart): ?>
                        <div class="space-y-4 mb-8">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 font-medium"><?php echo htmlspecialchars($selectedCart['make'] . ' ' . $selectedCart['model'] . ' (' . $selectedCartDays . ' Day' . ($selectedCartDays === 1 ? '' : 's') . ')'); ?></span>
                                <span class="font-bold">GHS <?php echo formatMoney($selectedCartBase); ?></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 font-medium">Chauffeur Service</span>
                                <span class="font-bold">GHS <?php echo formatMoney($selectedCartChauffeurFee); ?></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 font-medium">Service Fee</span>
                                <span class="font-bold">GHS 45</span>
                            </div>
                            <div class="pt-4 border-t border-gray-100 flex justify-between items-center">
                                <span class="text-lg font-black uppercase">Total Cost</span>
                                <span class="text-2xl font-black text-[#1b4b4b]">GHS <?php echo formatMoney($selectedCartTotal + 45); ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4 mb-8 text-center text-gray-500">
                            <p class="font-bold">No booking available for review.</p>
                            <p class="text-sm">Please select a vehicle and complete a booking first.</p>
                        </div>
                    <?php endif; ?>

                    <div class="mb-8">
                        <p class="text-[10px] font-black uppercase text-gray-400 mb-3 tracking-widest text-center">Pay Securely With</p>
                        <div class="flex gap-2 justify-center mb-6">
                            <div class="px-4 py-2 border rounded-xl bg-gray-50 flex items-center gap-2 grayscale hover:grayscale-0 transition cursor-pointer">
                                <div class="w-8 h-4 bg-yellow-400 rounded-sm"></div>
                                <span class="text-[10px] font-black uppercase">MOMO</span>
                            </div>
                            <div class="px-4 py-2 border rounded-xl bg-gray-50 flex items-center gap-2 grayscale hover:grayscale-0 transition cursor-pointer">
                                <div class="w-8 h-4 bg-blue-600 rounded-sm"></div>
                                <span class="text-[10px] font-black uppercase">CARD</span>
                            </div>
                        </div>
                    </div>

                    <?php if ($selectedCart): ?>
                        <?php if ($identityVerified): ?>
                            <form action="payment.php" method="POST">
                                <input type="hidden" name="cart_item_id" value="<?php echo htmlspecialchars($selectedCart['cart_id']); ?>">
                                <button type="submit" class="w-full bg-[#1b4b4b] text-white py-5 rounded-2xl font-black text-lg uppercase tracking-widest hover:bg-gray-800 shadow-lg transform active:scale-95 transition">
                                    Proceed to Payment
                                </button>
                            </form>
                        <?php else: ?>
                            <a href="complete-profile.php" class="inline-flex w-full justify-center bg-amber-100 text-amber-900 text-center py-3 px-3 rounded-2xl font-black text-lg uppercase tracking-widest shadow-sm border border-amber-200 hover:bg-amber-200 transition">
                                Complete verification to pay
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <button type="button" disabled class="w-full bg-gray-300 text-gray-600 py-5 rounded-2xl font-black text-lg uppercase tracking-widest cursor-not-allowed">
                            Proceed to Payment
                        </button>
                    <?php endif; ?>
                    
                    <p class="text-[9px] text-center text-gray-400 font-bold uppercase mt-6 tracking-widest">
                        Locked price for 15:00 minutes
                    </p>
                </div>

                <div class="mt-8 p-6 text-center">
                    <p class="text-xs font-bold text-gray-400">Need help with your booking?</p>
                    <a href="tel:+233200853940" class="text-sm font-black text-[#1b4b4b] hover:underline">+233 20 085 3940</a>
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


