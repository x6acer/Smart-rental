<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/security.php';

$currentPage = 'booking.php';

$bookingNotice = '';
$bookingNoticeType = 'info';
$bookings = [];
$bookingStatuses = [];
$bookingStatusOptions = ['Pending', 'Confirmed', 'Active', 'Completed', 'Cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_action'], $_POST['booking_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-booking')) {
        $bookingNotice = 'Security check failed. Please try again.';
        $bookingNoticeType = 'error';
    } else {
        $action = trim((string) ($_POST['booking_action'] ?? ''));
        $targetBookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);

        if ($targetBookingId && in_array($action, $bookingStatusOptions, true)) {
            try {
                $pdo->prepare('UPDATE Bookings SET booking_status = :status WHERE booking_id = :booking_id')->execute([
                    'status' => $action,
                    'booking_id' => $targetBookingId,
                ]);
                logAdminActivity('booking_status_updated', 'Admin updated booking ' . $targetBookingId . ' to ' . $action, (int) ($_SESSION['admin_id'] ?? 0), 'Booking', $targetBookingId);
                $bookingNotice = 'Booking status updated successfully.';
                $bookingNoticeType = 'success';
            } catch (PDOException $e) {
                $bookingNotice = 'Unable to update this booking right now.';
                $bookingNoticeType = 'error';
            }
        } else {
            $bookingNotice = 'Invalid booking action.';
            $bookingNoticeType = 'error';
        }
    }
}

try {
    $stmt = $pdo->prepare(
        'SELECT b.booking_id, b.start_date, b.end_date, b.booking_status, b.chauffeur_toggle, c.email AS customer_email, cp.full_name AS customer_name, v.vehicle_id, v.make, v.model, v.year, v.vin, o.email AS owner_email, op.full_name AS owner_name
         FROM Bookings b
         INNER JOIN Users c ON c.user_id = b.customer_id
         LEFT JOIN User_Profiles cp ON cp.user_id = c.user_id
         INNER JOIN Vehicles v ON v.vehicle_id = b.vehicle_id
         LEFT JOIN Users o ON o.user_id = v.owner_id
         LEFT JOIN User_Profiles op ON op.user_id = o.user_id
         ORDER BY b.start_date DESC'
    );
    $stmt->execute();
    $bookings = $stmt->fetchAll();

    $statusStmt = $pdo->query('SELECT booking_status, COUNT(*) AS total FROM Bookings GROUP BY booking_status');
    $bookingStatuses = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bookings = [];
    $bookingStatuses = [];
    $bookingNotice = 'Unable to load bookings at this time.';
    $bookingNoticeType = 'error';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Global Booking Control | Smart Rental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --brand-primary: #1b4b4b;
            --brand-accent: #facd05;
        }
    </style>
</head>

<body class="bg-[#f8fafc] text-slate-900 antialiased">
    <div class="min-h-screen flex">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="flex-grow lg:ml-[280px] min-h-screen flex flex-col">
            <?php
            $pageTitle = 'Booking Management';
            $pageSubtitle = 'Live rental operations';
            $showSearch = true;
            $headerSearchPlaceholder = 'Search ID, Renter, or Plate...';
            require_once __DIR__ . '/includes/header.php';
            ?>

            <main class="p-8">
                
                <div class="flex flex-wrap items-center justify-between gap-6 mb-8" data-tab-group="booking-status">
                    <div class="flex gap-2">
                        <button data-tab="Active" class="px-6 py-2 bg-[#1b4b4b] text-white rounded-xl text-[10px] font-black uppercase shadow-lg">Live Rentals</button>
                        <button data-tab="Confirmed" class="px-6 py-2 bg-white text-gray-400 hover:text-[#1b4b4b] rounded-xl text-[10px] font-black uppercase border border-transparent hover:border-gray-200">Upcoming</button>
                        <button data-tab="Cancelled" class="px-6 py-2 bg-white text-red-500 hover:bg-red-50 rounded-xl text-[10px] font-black uppercase border border-red-100">Disputed (3)</button>
                    </div>

                    <div class="flex items-center gap-3">
                        <span class="text-[10px] font-black text-gray-400 uppercase">Region:</span>
                        <select class="bg-white border border-gray-200 text-[10px] font-black uppercase px-4 py-2 rounded-xl outline-none" data-region-filter="true">
                            <option value="all">All Regions</option>
                            <option>Greater Accra</option>
                            <option>Ashanti Region</option>
                            <option>Western Region</option>
                        </select>
                    </div>
                </div>

                <?php if ($bookingNotice !== ''): ?>
                    <div class="mb-6 rounded-2xl border px-4 py-3 text-sm font-semibold <?= $bookingNoticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'; ?>">
                        <?= htmlspecialchars($bookingNotice, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <?php foreach ($bookingStatusOptions as $statusOption): ?>
                        <?php $countForStatus = 0; foreach ($bookingStatuses as $statusRow) { if (($statusRow['booking_status'] ?? '') === $statusOption) { $countForStatus = (int) $statusRow['total']; break; } } ?>
                        <div class="rounded-[1.5rem] border border-slate-200 bg-white p-4 shadow-sm">
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400"><?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?></p>
                            <p class="mt-2 text-2xl font-black text-slate-800"><?= htmlspecialchars((string) $countForStatus, ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 text-[9px] font-black uppercase text-gray-400 tracking-widest">
                            <tr>
                                <th class="px-8 py-5">Booking ID</th>
                                <th class="px-4 py-5">Renter & Owner</th>
                                <th class="px-4 py-5">Vehicle</th>
                                <th class="px-4 py-5">Rental Period</th>
                                <th class="px-4 py-5">Status</th>
                                <th class="px-4 py-5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php if (!empty($bookings)): ?>
                                <?php foreach ($bookings as $booking): ?>
                                    <?php
                                    $statusBadgeClass = 'text-slate-600';
                                    $statusIcon = '•';
                                    if ($booking['booking_status'] === 'Active') {
                                        $statusBadgeClass = 'text-green-600';
                                        $statusIcon = '●';
                                    } elseif ($booking['booking_status'] === 'Cancelled') {
                                        $statusBadgeClass = 'text-red-600';
                                        $statusIcon = '⚠';
                                    } elseif ($booking['booking_status'] === 'Completed') {
                                        $statusBadgeClass = 'text-blue-600';
                                        $statusIcon = '✓';
                                    } elseif ($booking['booking_status'] === 'Confirmed') {
                                        $statusBadgeClass = 'text-amber-600';
                                        $statusIcon = '↻';
                                    }
                                    ?>
                                    <tr class="hover:bg-gray-50/50 transition">
                                        <td class="px-8 py-6">
                                            <code class="text-xs font-black">#SR-<?= (int) $booking['booking_id']; ?></code>
                                        </td>
                                        <td class="px-4 py-6">
                                            <div class="space-y-1">
                                                <p class="text-xs font-black uppercase"><?= htmlspecialchars((string) ($booking['customer_name'] ?: $booking['customer_email'] ?: 'Unknown customer'), ENT_QUOTES, 'UTF-8'); ?></p>
                                                <p class="text-xs font-black italic text-brand"><?= htmlspecialchars((string) ($booking['owner_name'] ?: $booking['owner_email'] ?: 'Unknown owner'), ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-6">
                                            <p class="text-[10px] font-black uppercase"><?= htmlspecialchars((string) (($booking['make'] ?? '') . ' ' . ($booking['model'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[9px] text-gray-400 font-bold"><?= htmlspecialchars((string) ($booking['vin'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p>
                                        </td>
                                        <td class="px-4 py-6">
                                            <p class="text-[10px] font-bold"><?= htmlspecialchars(date('M d', strtotime((string) $booking['start_date'])) . ' - ' . date('M d', strtotime((string) $booking['end_date'])), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[9px] text-gray-400 font-black uppercase mt-1"><?= htmlspecialchars($booking['chauffeur_toggle'] ? 'Chauffeur Added' : 'Self Drive', ENT_QUOTES, 'UTF-8'); ?></p>
                                        </td>
                                        <td class="px-4 py-6">
                                            <span class="inline-flex items-center gap-1.5 <?= $statusBadgeClass; ?> text-[9px] font-black uppercase">
                                                <span class="w-1.5 h-1.5 rounded-full <?= $booking['booking_status'] === 'Active' ? 'bg-green-500 animate-pulse' : 'bg-current'; ?>"></span> <?= htmlspecialchars((string) $booking['booking_status'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-6 text-right">
                                            <form method="post" class="flex justify-end gap-2">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-booking'), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="booking_id" value="<?= (int) $booking['booking_id']; ?>">
                                                <select name="booking_action" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-[9px] font-black uppercase">
                                                    <?php foreach ($bookingStatusOptions as $statusOption): ?>
                                                        <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?>" <?= $statusOption === (string) $booking['booking_status'] ? 'selected' : ''; ?>><?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="rounded-lg bg-[#1b4b4b] px-3 py-2 text-[9px] font-black uppercase text-white">Update</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-8 py-12 text-center text-sm text-slate-500">No bookings were found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-8 p-6 bg-[#1b4b4b] rounded-[2rem] text-white shadow-xl flex items-center justify-between">
                    <div class="flex items-center gap-6">
                        <div class="w-12 h-12 bg-white/10 rounded-2xl flex items-center justify-center text-2xl">🛡️</div>
                        <div>
                            <h4 class="text-sm font-black uppercase tracking-tight">Institutional Escrow Active</h4>
                            <p class="text-[10px] opacity-70 font-medium">Currently securing GHS 248,500.00 across all active rental sessions.</p>
                        </div>
                    </div>
                    <a href="payrev.php" class="bg-[#facd05] text-[#1b4b4b] px-6 py-3 rounded-xl font-black text-[10px] uppercase tracking-widest hover:scale-105 transition">Audit Payouts</a>
                </div>

            </main>
        </div>
    </div>
    <script src="js/admin-app.js" defer></script>
</body>
</html>
