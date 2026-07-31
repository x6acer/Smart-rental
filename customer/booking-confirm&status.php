<?php
session_start();
require_once '../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRGdImagePNG;

function generateBookingReceiptReference(int $bookingId): string
{
    return 'SR-' . date('Ymd') . '-' . str_pad((string) $bookingId, 6, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function confirmBookingAndPreparePickup(PDO $pdo, int $bookingId, int $customerId): array
{
    $receiptReference = generateBookingReceiptReference($bookingId);

    $pdo->beginTransaction();
    try {
        $bookingStmt = $pdo->prepare(
            'UPDATE Bookings
             SET booking_status = :status
             WHERE booking_id = :booking_id AND customer_id = :customer_id'
        );
        $bookingStmt->execute([
            'status' => 'Confirmed',
            'booking_id' => $bookingId,
            'customer_id' => $customerId,
        ]);

        $transactionStmt = $pdo->prepare(
            'UPDATE Transactions
             SET momo_transaction_ref = :receipt_reference
             WHERE booking_id = :booking_id'
        );
        $transactionStmt->execute([
            'receipt_reference' => $receiptReference,
            'booking_id' => $bookingId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    return ['success' => true, 'receipt_reference' => $receiptReference];
}

// Security Route Guard: Kick unauthenticated guests back to login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'customer-booking-confirm')) {
        $_SESSION['booking_error'] = 'Security check failed. Please try again.';
        header('Location: booking-confirm&status.php?booking_id=' . urlencode((string) (filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT) ?: '')));
        exit();
    }

    $userId = (int) $_SESSION['user_id'];
    $bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
    if ($bookingId !== null && $bookingId > 0) {
        try {
            $result = confirmBookingAndPreparePickup($pdo, $bookingId, $userId);
            $_SESSION['booking_reference'] = $result['receipt_reference'];
            $_SESSION['booking_success'] = 'Your booking is confirmed and ready for pickup.';
            header('Location: booking-confirm&status.php?booking_id=' . urlencode((string) $bookingId));
            exit();
        } catch (Throwable $e) {
            error_log('Booking confirmation failed: ' . $e->getMessage());
            $_SESSION['booking_error'] = 'We could not confirm your booking right now.';
            header('Location: booking-confirm&status.php?booking_id=' . urlencode((string) $bookingId));
            exit();
        }
    }
}

$userId = (int) $_SESSION['user_id'];
$bookingId = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT) ?: (int) ($_SESSION['last_booking_id'] ?? 0);

if ($bookingId > 0) {
    $bookingStmt = $pdo->prepare(
        'SELECT b.booking_id, b.start_date, b.end_date, b.booking_status, b.chauffeur_toggle,
                v.make, v.model, v.owner_id,
                o.full_name AS owner_name,
                t.total_price, t.payment_status, t.momo_transaction_ref
         FROM Bookings b
         JOIN Vehicles v ON b.vehicle_id = v.vehicle_id
         LEFT JOIN User_Profiles o ON o.user_id = v.owner_id
         LEFT JOIN Transactions t ON t.booking_id = b.booking_id
         WHERE b.booking_id = :booking_id AND b.customer_id = :customer_id
         LIMIT 1'
    );
    $bookingStmt->execute([
        'booking_id' => $bookingId,
        'customer_id' => $userId,
    ]);
    $booking = $bookingStmt->fetch();
} else {
    $bookingStmt = $pdo->prepare(
        'SELECT b.booking_id, b.start_date, b.end_date, b.booking_status, b.chauffeur_toggle,
                v.make, v.model, v.owner_id,
                o.full_name AS owner_name,
                t.total_price, t.payment_status, t.momo_transaction_ref
         FROM Bookings b
         JOIN Vehicles v ON b.vehicle_id = v.vehicle_id
         LEFT JOIN User_Profiles o ON o.user_id = v.owner_id
         LEFT JOIN Transactions t ON t.booking_id = b.booking_id
         WHERE b.customer_id = :customer_id
         ORDER BY b.booking_id DESC
         LIMIT 1'
    );
    $bookingStmt->execute(['customer_id' => $userId]);
    $booking = $bookingStmt->fetch();
}

if (!$booking) {
    header('Location: rentals.php');
    exit();
}

$customerName = $_SESSION['user_name'] ?? 'Customer';
$customerInitials = strtoupper(substr(trim($customerName ?: 'CU'), 0, 2));
$bookingNumber = '#SR-' . str_pad((string) $booking['booking_id'], 7, '0', STR_PAD_LEFT);
$ownerName = $booking['owner_name'] ?: 'Vehicle Owner';
$vehicleName = trim($booking['make'] . ' ' . $booking['model']);
$pickupDate = (new DateTime($booking['start_date']))->format('M d, Y, h:i A');
$returnDate = (new DateTime($booking['end_date']))->format('M d, Y, h:i A');
$bookingStatus = $booking['booking_status'] ?: 'Confirmed';
if ($bookingStatus !== 'Confirmed' && ($booking['payment_status'] ?? '') === 'Escrow') {
    try {
        $confirmationResult = confirmBookingAndPreparePickup($pdo, (int) $booking['booking_id'], $userId);
        $bookingStatus = 'Confirmed';
        $_SESSION['booking_reference'] = $confirmationResult['receipt_reference'];
        $booking['booking_status'] = 'Confirmed';
        $booking['momo_transaction_ref'] = $confirmationResult['receipt_reference'];
    } catch (Throwable $e) {
        error_log('Booking confirmation bootstrap failed: ' . $e->getMessage());
    }
}

$bookingHeadline = 'Booking Confirmed';
$bookingSubtext = 'Your reservation is now in motion.';

if ($bookingStatus === 'Pending') {
    $bookingHeadline = 'Booking Request Submitted';
    $bookingSubtext = 'Awaiting owner approval and pickup coordination.';
} elseif ($bookingStatus === 'Confirmed') {
    $bookingHeadline = 'Booking Confirmed';
    $bookingSubtext = 'Your reservation is ready for pickup.';
} elseif ($bookingStatus === 'Active') {
    $bookingHeadline = 'Vehicle In Rental';
    $bookingSubtext = 'Your trip is currently in progress.';
} elseif ($bookingStatus === 'Completed') {
    $bookingHeadline = 'Booking Completed';
    $bookingSubtext = 'Your rental has been completed successfully.';
} elseif ($bookingStatus === 'Cancelled') {
    $bookingHeadline = 'Booking Cancelled';
    $bookingSubtext = 'This reservation has been cancelled.';
}

$totalPrice = isset($booking['total_price']) ? number_format((float) $booking['total_price'], 2) : '0.00';
$paymentStatus = $booking['payment_status'] ?: 'Escrow';
$transactionRef = $booking['momo_transaction_ref'] ?: ($_SESSION['booking_reference'] ?? 'Pending');
$canCancel = in_array($bookingStatus, ['Pending', 'Confirmed'], true);
$canExtend = in_array($bookingStatus, ['Confirmed', 'Active'], true);

// Generate unique QR code data for this booking
$qrData = json_encode([
    'booking_id' => $booking['booking_id'],
    'customer_id' => $userId,
    'vehicle' => $vehicleName,
    'owner_id' => $booking['owner_id'],
    'start_date' => $booking['start_date'],
    'end_date' => $booking['end_date'],
    'transaction_ref' => $transactionRef,
    'timestamp' => time(),
    'hash' => hash('sha256', $booking['booking_id'] . $userId . $transactionRef . time())
]);

// Generate QR code with error handling
$qrImage = '';
try {
    $qrOptions = new QROptions([
        'version' => 5,
        'eccLevel' => 'M',
        'outputType' => QRCode::OUTPUT_IMAGE_PNG,
        'imageBase64' => true
    ]);
    $qrCode = new QRCode($qrOptions);
    $qrImage = $qrCode->render($qrData);
} catch (Throwable $e) {
    error_log('QR Code generation failed: ' . $e->getMessage());
    $qrImage = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Status | Smart Rental</title>
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
        .status-dot-active { background-color: #10b981; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.2); }
        .timeline-line { background: repeating-linear-gradient(to bottom, #e2e8f0, #e2e8f0 5px, transparent 5px, transparent 10px); }
    </style>
</head>
<body class="font-['Segoe_UI',Tahoma,Geneva,Verdana,sans-serif] bg-[#f9f9f8] text-[#1b4b4b] antialiased">

    <?php require_once 'includes/header.php'; ?>

    </header>

    <main class="max-w-4xl mx-auto px-6 py-10">
        
        <div class="text-center mb-12">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mb-4">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <h2 class="text-3xl font-black uppercase tracking-tight"><?php echo htmlspecialchars($bookingHeadline); ?></h2>
            <p class="text-gray-500 font-bold text-sm uppercase mt-1">Order ID: <?php echo htmlspecialchars($bookingNumber); ?></p>
            <p class="text-gray-500 font-bold text-sm uppercase mt-1">Receipt: <?php echo htmlspecialchars($transactionRef); ?></p>
            <p class="text-gray-500 text-sm mt-2"><?php echo htmlspecialchars($bookingSubtext); ?></p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            
            <div class="md:col-span-1 space-y-6">
                <div class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100 relative overflow-hidden">
                    <h3 class="text-xs font-black uppercase tracking-widest text-gray-400 mb-8">Journey Status</h3>
                    
                    <div class="relative pl-8 space-y-10">
                        <div class="absolute left-[11px] top-2 bottom-2 w-0.5 timeline-line"></div>
                        
                        <div class="relative">
                            <div class="absolute -left-8 w-6 h-6 rounded-full bg-green-500 flex items-center justify-center text-white text-[10px] z-10">✓</div>
                            <p class="text-xs font-black uppercase">Payment Verified</p>
                            <p class="text-[10px] text-gray-400"><?php echo htmlspecialchars($paymentStatus === 'Escrow' ? 'Funds held in Escrow' : 'Payment ' . $paymentStatus); ?></p>
                        </div>

                        <div class="relative">
                            <div class="absolute -left-8 w-6 h-6 rounded-full status-dot-active border-4 border-white z-10"></div>
                            <p class="text-xs font-black uppercase text-[#1b4b4b]">Awaiting Pickup</p>
                            <p class="text-[10px] text-gray-500 font-bold"><?php echo htmlspecialchars($pickupDate); ?></p>
                        </div>

                        <div class="relative">
                            <div class="absolute -left-8 w-6 h-6 rounded-full bg-gray-200 border-4 border-white z-10"></div>
                            <p class="text-xs font-black uppercase text-gray-300">Scheduled Return</p>
                            <p class="text-[10px] text-gray-300"><?php echo htmlspecialchars($returnDate); ?></p>
                        </div>
                    </div>
                </div>

                    <a href="notifications.php" class="flex items-center justify-center gap-3 w-full bg-white border border-gray-200 py-4 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-gray-50 transition shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.994 1.994 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z"></path></svg>
                        Contact Owner
                    </a>
                </div>

            <div class="md:col-span-2 space-y-6">
                
                <div class="bg-[#1b4b4b] rounded-[2rem] p-8 text-white shadow-xl relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-white/5 rounded-full -mr-16 -mt-16"></div>
                    
                    <div class="flex flex-col md:flex-row items-center gap-8">
                        <div class="bg-white p-4 rounded-3xl shrink-0 shadow-inner">
                            <?php if ($qrImage): ?>
                                <img src="data:image/png;base64,<?php echo htmlspecialchars($qrImage); ?>" alt="Handover QR Code" class="w-32 h-32">
                            <?php else: ?>
                                <div class="w-32 h-32 bg-gray-100 flex items-center justify-center border-2 border-dashed border-gray-200">
                                    <span class="text-[8px] text-gray-400 font-black uppercase text-center px-4">QR Code Unavailable</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h4 class="text-xl font-black uppercase mb-2">Pickup Protocol</h4>
                            <p class="text-xs text-white/70 leading-relaxed mb-4">Present this QR code to the vehicle owner at the pickup location. Do not share this code until you have inspected the vehicle.</p>
                            <div class="flex gap-2">
                                <span class="text-[10px] font-black bg-white/10 px-3 py-1 rounded-full uppercase tracking-tighter"><?php echo htmlspecialchars($bookingStatus); ?></span>
                                <span class="text-[10px] font-black bg-[#facd05] text-[#1b4b4b] px-3 py-1 rounded-full uppercase tracking-tighter">Security Verified</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-[2rem] border border-gray-100 shadow-sm overflow-hidden">
                    <div class="flex flex-col sm:flex-row">
                        <div class="w-full sm:w-48 h-32 bg-gray-200">
                             <img src="./assets/images/Cars/SUVs/ford-explorer.jpg" class="w-full h-full object-cover">
                        </div>
                        <div class="p-6 flex-grow">
                            <div class="flex justify-between">
                                <h4 class="text-sm font-black uppercase"><?php echo htmlspecialchars($vehicleName); ?></h4>
                                <span class="text-xs font-bold text-[#1b4b4b]">GHS <?php echo htmlspecialchars($totalPrice); ?> Total</span>
                            </div>
                            <p class="text-[10px] text-gray-400 font-bold uppercase mt-1"><?php echo htmlspecialchars($ownerName); ?></p>
                            
                            <div class="mt-4 flex gap-4">
                                <a href="rentals.php?download_receipt=<?= (int) $booking['booking_id']; ?>" class="text-[10px] font-black text-[#1b4b4b] uppercase underline decoration-[#facd05] decoration-2 underline-offset-4">View Receipt</a>
                                <?php if ($canCancel): ?>
                                    <a href="rentals.php?cancel_booking=<?= (int) $booking['booking_id']; ?>" class="text-[10px] font-black text-red-400 uppercase">Cancel Booking</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-yellow-50 border border-yellow-100 p-6 rounded-3xl flex gap-4">
                    <div class="w-8 h-8 bg-yellow-400 rounded-full shrink-0 flex items-center justify-center font-bold text-[#1b4b4b]">!</div>
                    <div>
                        <p class="text-xs font-black text-yellow-800 uppercase">Handover Reminder</p>
                        <p class="text-[10px] text-yellow-700 mt-1">To ensure your insurance is active, you MUST take at least 4 photos of the vehicle (front, back, sides) within the Smart Rental app during pickup.</p>
                    </div>
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
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('public-landing'), ENT_QUOTES, 'UTF-8'); ?>">
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


