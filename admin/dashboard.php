<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';

$currentPage = 'dashboard.php';

$pendingUserCount = 0;
$pendingVehicleCount = 0;
$activeDisputeCount = 0;
$escrowTotal = '0.00';
$activeBookingCount = 0;
$monthlyRevenue = '0.00';
$totalUserCount = 0;
$totalOwnerCount = 0;
$totalVehicleCount = 0;
$revenueTrend = [];
$recentUserVerification = null;
$recentVehicleListing = null;
$recentDisputeTicket = null;

try {
    $totalUsersStmt = $pdo->query(
        'SELECT
            SUM(user_role = "Customer") AS total_customers,
            SUM(user_role = "Owner") AS total_owners,
            COUNT(*) AS total_users
         FROM Users'
    );
    $userTotals = $totalUsersStmt->fetch();
    if ($userTotals !== false) {
        $totalUserCount = (int) $userTotals['total_users'];
        $totalOwnerCount = (int) $userTotals['total_owners'];
    }

    $totalVehiclesStmt = $pdo->query('SELECT COUNT(*) AS total_vehicles FROM Vehicles');
    $totalVehicleCount = (int) $totalVehiclesStmt->fetchColumn();

    $revenueTrendStmt = $pdo->prepare(
        'SELECT DATE_FORMAT(b.start_date, "%b %Y") AS month_label,
                YEAR(b.start_date) AS revenue_year,
                MONTH(b.start_date) AS revenue_month,
                COALESCE(SUM(t.total_price), 0) AS revenue
         FROM Transactions t
         JOIN Bookings b ON b.booking_id = t.booking_id
         WHERE t.payment_status = :paid
           AND b.start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 5 MONTH)
         GROUP BY revenue_year, revenue_month
         ORDER BY revenue_year, revenue_month'
    );
    $revenueTrendStmt->execute(['paid' => 'Paid']);
    $revenueRows = $revenueTrendStmt->fetchAll();
    $revenueMap = [];
    foreach ($revenueRows as $row) {
        $revenueMap[$row['month_label']] = (float) $row['revenue'];
    }

    $monthCursor = new DateTime('first day of -5 months');
    for ($i = 0; $i < 6; $i++) {
        $label = $monthCursor->format('M Y');
        $revenueTrend[] = [
            'label' => $label,
            'value' => $revenueMap[$label] ?? 0.0,
        ];
        $monthCursor->modify('+1 month');
    }

    $maxRevenue = 0.0;
    foreach ($revenueTrend as $trend) {
        if ($trend['value'] > $maxRevenue) {
            $maxRevenue = $trend['value'];
        }
    }
    if ($maxRevenue <= 0.0) {
        $maxRevenue = 1.0;
    }

    $pendingUsersStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT u.user_id) AS total
         FROM Users u
         LEFT JOIN Identity_Verifications iv ON iv.user_id = u.user_id
         WHERE u.user_role IN ("Customer", "Owner")
           AND (u.account_status = :pendingStatus OR COALESCE(iv.verification_status, :pendingStatus) = :pendingStatus)'
    );
    $pendingUsersStmt->execute(['pendingStatus' => 'Pending']);
    $pendingUserCount = (int) $pendingUsersStmt->fetchColumn();

    $pendingVehiclesStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT v.vehicle_id) AS total
         FROM Vehicles v
         LEFT JOIN Vehicle_Verifications vv ON vv.vehicle_id = v.vehicle_id
         WHERE v.status = :idleStatus
            OR v.status = :serviceStatus
            OR COALESCE(vv.verification_status, :pendingStatus) = :pendingStatus'
    );
    $pendingVehiclesStmt->execute([
        'idleStatus' => 'Idle',
        'serviceStatus' => 'Service',
        'pendingStatus' => 'Pending',
    ]);
    $pendingVehicleCount = (int) $pendingVehiclesStmt->fetchColumn();

    $activeDisputesStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM Support_Tickets WHERE status IN (:open, :inProgress)'
    );
    $activeDisputesStmt->execute(['open' => 'Open', 'inProgress' => 'In_Progress']);
    $activeDisputeCount = (int) $activeDisputesStmt->fetchColumn();

    $escrowStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(total_price), 0) FROM Transactions WHERE payment_status = :escrow'
    );
    $escrowStmt->execute(['escrow' => 'Escrow']);
    $escrowTotal = number_format((float) $escrowStmt->fetchColumn(), 2);

    $activeBookingsStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM Bookings WHERE booking_status IN (:confirmed, :active)'
    );
    $activeBookingsStmt->execute(['confirmed' => 'Confirmed', 'active' => 'Active']);
    $activeBookingCount = (int) $activeBookingsStmt->fetchColumn();

    $monthlyRevenueStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(t.total_price), 0)
         FROM Transactions t
         JOIN Bookings b ON b.booking_id = t.booking_id
         WHERE t.payment_status = :paid
           AND MONTH(b.start_date) = MONTH(CURRENT_DATE())
           AND YEAR(b.start_date) = YEAR(CURRENT_DATE())'
    );
    $monthlyRevenueStmt->execute(['paid' => 'Paid']);
    $monthlyRevenue = number_format((float) $monthlyRevenueStmt->fetchColumn(), 2);

    $recentUserStmt = $pdo->prepare(
        'SELECT u.email, u.user_role, p.full_name
         FROM Users u
         LEFT JOIN User_Profiles p ON p.user_id = u.user_id
         LEFT JOIN Identity_Verifications iv ON iv.user_id = u.user_id
         WHERE u.user_role IN ("Customer", "Owner")
           AND (u.account_status = :pendingStatus OR COALESCE(iv.verification_status, :pendingStatus) = :pendingStatus)
         ORDER BY u.registration_date DESC
         LIMIT 1'
    );
    $recentUserStmt->execute(['pendingStatus' => 'Pending']);
    $recentUserVerification = $recentUserStmt->fetch();

    $recentVehicleStmt = $pdo->prepare(
        'SELECT v.make, v.model, v.year, u.email AS owner_email, COALESCE(vv.verification_status, :pendingStatus) AS verification_status
         FROM Vehicles v
         LEFT JOIN Vehicle_Verifications vv ON vv.vehicle_id = v.vehicle_id
         LEFT JOIN Users u ON u.user_id = v.owner_id
         WHERE v.status = :idleStatus
            OR v.status = :serviceStatus
            OR COALESCE(vv.verification_status, :pendingStatus) = :pendingStatus
         ORDER BY v.vehicle_id DESC
         LIMIT 1'
    );
    $recentVehicleStmt->execute([
        'idleStatus' => 'Idle',
        'serviceStatus' => 'Service',
        'pendingStatus' => 'Pending',
    ]);
    $recentVehicleListing = $recentVehicleStmt->fetch();

    $recentDisputeStmt = $pdo->prepare(
        'SELECT st.ticket_id, st.subject, st.status, u.email AS user_email
         FROM Support_Tickets st
         JOIN Users u ON u.user_id = st.user_id
         WHERE st.status IN (:open, :inProgress)
         ORDER BY st.ticket_id DESC
         LIMIT 1'
    );
    $recentDisputeStmt->execute(['open' => 'Open', 'inProgress' => 'In_Progress']);
    $recentDisputeTicket = $recentDisputeStmt->fetch();
} catch (PDOException $e) {
    error_log('Dashboard metrics could not be loaded: ' . $e->getMessage());
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard | Smart Rental Control Tower</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>

<body class="bg-[#f8fafc] text-slate-900 antialiased">
    <div class="min-h-screen flex">

        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="flex-grow lg:ml-[280px] min-h-screen flex flex-col justify-between">
            
            <div>
                <?php
                $pageTitle = 'Control Hub Dashboard';
                $pageSubtitle = 'Real-time mobility data stream';
                $showSearch = false;
                require_once __DIR__ . '/includes/header.php';
                ?>

                <main class="p-8">
                    
                    <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                        
                        <div class="bg-white p-6 rounded-2xl border border-slate-200/70 shadow-sm relative overflow-hidden group">
                            <div class="absolute top-0 left-0 w-full h-1 bg-amber-400"></div>
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Identity Verification</p>
                                    <h3 class="text-3xl font-extrabold text-slate-900 mt-2"><?= htmlspecialchars((string) $pendingUserCount, ENT_QUOTES, 'UTF-8'); ?></h3>
                                </div>
                                <div class="p-2.5 rounded-xl bg-amber-50 border border-amber-100 text-amber-600">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 009 11.571V11a4 4 0 118 0v.571c0 1.925.354 3.768.992 5.461l.054.088m-5.44 2.04A13.948 13.948 0 0112 18.7M12 18.7a13.948 13.948 0 002.753-2.629M12 18.7a13.947 13.947 0 003.442-2.04M15 11V5a3 3 0 00-6 0v6m6 0a3 3 0 01-6 0"/></svg>
                                </div>
                            </div>
                            <div class="flex justify-between items-center mt-4 pt-4 border-t border-slate-100">
                                <span class="text-[10px] text-slate-400 font-semibold uppercase">Pending Screening</span>
                                <a href="users.php?status=pending" class="text-xs font-bold text-[#1b4b4b] hover:text-[#facd05] transition flex items-center gap-1">Review &rarr;</a>
                            </div>
                        </div>

                        <div class="bg-white p-6 rounded-2xl border border-slate-200/70 shadow-sm relative overflow-hidden group">
                            <div class="absolute top-0 left-0 w-full h-1 bg-[#1b4b4b]"></div>
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Fleet Access Queue</p>
                                    <h3 class="text-3xl font-extrabold text-slate-900 mt-2"><?= htmlspecialchars((string) $pendingVehicleCount, ENT_QUOTES, 'UTF-8'); ?></h3>
                                </div>
                                <div class="p-2.5 rounded-xl bg-teal-50 border border-teal-100 text-[#1b4b4b]">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                </div>
                            </div>
                            <div class="flex justify-between items-center mt-4 pt-4 border-t border-slate-100">
                                <span class="text-[10px] text-slate-400 font-semibold uppercase">Awaiting Inspection</span>
                                <a href="vehicles.php" class="text-xs font-bold text-[#1b4b4b] hover:text-[#facd05] transition flex items-center gap-1">Inspect &rarr;</a>
                            </div>
                        </div>

                        <div class="bg-white p-6 rounded-2xl border border-slate-200/70 shadow-sm relative overflow-hidden group">
                            <div class="absolute top-0 left-0 w-full h-1 bg-red-500"></div>
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Active Disputes</p>
                                    <h3 class="text-3xl font-extrabold text-red-600 mt-2"><?= htmlspecialchars((string) $activeDisputeCount, ENT_QUOTES, 'UTF-8'); ?></h3>
                                </div>
                                <div class="p-2.5 rounded-xl bg-red-50 border border-red-100 text-red-600">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                </div>
                            </div>
                            <div class="flex justify-between items-center mt-4 pt-4 border-t border-slate-100">
                                <span class="text-[10px] text-slate-400 font-semibold uppercase">High Priority Tickets</span>
                                <a href="support.php" class="text-xs font-bold text-red-600 hover:underline transition flex items-center gap-1">Mediate &rarr;</a>
                            </div>
                        </div>

                        <div class="bg-white p-6 rounded-2xl border border-slate-200/70 shadow-sm relative overflow-hidden group">
                            <div class="absolute top-0 left-0 w-full h-1 bg-emerald-500"></div>
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Escrow Balance</p>
                                    <h3 class="text-2xl font-extrabold text-slate-900 mt-2.5">GHS <?= htmlspecialchars($escrowTotal, ENT_QUOTES, 'UTF-8'); ?></h3>
                                </div>
                                <div class="p-2.5 rounded-xl bg-emerald-50 border border-emerald-100 text-emerald-600">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </div>
                            </div>
                            <div class="flex justify-between items-center mt-4 pt-4 border-t border-slate-100">
                                <span class="text-[10px] text-slate-400 font-semibold uppercase">Pending Dispatch</span>
                                <a href="payrev.php" class="text-xs font-bold text-emerald-600 hover:underline transition flex items-center gap-1">Ledger &rarr;</a>
                            </div>
                        </div>
                    </section>

                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
                        
                        <div class="xl:col-span-2 bg-white p-8 rounded-2xl border border-slate-200/70 shadow-sm">
                            <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100">
                                <div>
                                    <h3 class="text-sm font-bold uppercase tracking-wider text-slate-800">Revenue & Platform Utilization</h3>
                                    <p class="text-xs text-slate-400 mt-0.5">Performance tracking for completed bookings</p>
                                </div>
                                <select class="text-xs font-bold text-slate-600 bg-slate-50 border border-slate-200 p-2 rounded-lg outline-none focus:border-[#1b4b4b]">
                                    <option>Current Fiscal Period</option>
                                    <option>Previous Quarter</option>
                                </select>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
                                <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-5">
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Net Settled Revenue (Current Month)</p>
                                    <p class="mt-2 text-3xl font-extrabold text-[#1b4b4b]">GHS <?= htmlspecialchars($monthlyRevenue, ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-5">
                                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Active Fleet Allocations</p>
                                    <p class="mt-2 text-3xl font-extrabold text-[#1b4b4b]"><?= htmlspecialchars((string) $activeBookingCount, ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-4 mb-8 border-b border-slate-100 pb-6">
                                <div class="text-center py-2 border-r border-slate-100">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Registered Users</p>
                                    <p class="text-xl font-extrabold text-slate-800 mt-1"><?= htmlspecialchars((string) $totalUserCount, ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <div class="text-center py-2 border-r border-slate-100">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Verified Hosts</p>
                                    <p class="text-xl font-extrabold text-slate-800 mt-1"><?= htmlspecialchars((string) $totalOwnerCount, ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <div class="text-center py-2">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Assets</p>
                                    <p class="text-xl font-extrabold text-slate-800 mt-1"><?= htmlspecialchars((string) $totalVehicleCount, ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                            </div>

                            <h4 class="text-xs font-bold uppercase text-slate-400 tracking-wider mb-4">6-Month Trend Curve</h4>
                            <?php if (!empty($revenueTrend)): ?>
                                <div class="space-y-4">
                                    <?php foreach ($revenueTrend as $trend): ?>
                                        <?php $barWidth = max(4, min(100, ($trend['value'] / $maxRevenue) * 100)); ?>
                                        <div>
                                            <div class="flex justify-between text-xs font-medium text-slate-600 mb-1.5">
                                                <span class="font-semibold text-slate-700"><?= htmlspecialchars($trend['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="font-mono text-slate-500">GHS <?= htmlspecialchars(number_format($trend['value'], 2), ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                            <div class="h-2.5 bg-slate-100 rounded-full overflow-hidden">
                                                <div class="h-full bg-[#1b4b4b] rounded-full transition-all duration-500" style="width: <?= htmlspecialchars((string) $barWidth, ENT_QUOTES, 'UTF-8'); ?>%;"></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="h-48 border border-dashed border-slate-200 rounded-xl flex items-center justify-center text-xs font-medium text-slate-400 tracking-wide bg-slate-50/50">
                                    No transaction records found for the past 6 months.
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="bg-white p-8 rounded-2xl border border-slate-200/70 shadow-sm flex flex-col justify-between">
                            <div>
                                <div class="mb-6 pb-4 border-b border-slate-100">
                                    <h3 class="text-sm font-bold uppercase tracking-wider text-slate-800">Security & Onboarding Audit</h3>
                                    <p class="text-xs text-slate-400 mt-0.5">Latest priority gateway requests</p>
                                </div>

                                <div class="space-y-5">
                                    <div class="flex gap-4 p-3.5 rounded-xl hover:bg-slate-50 transition border border-transparent hover:border-slate-100">
                                        <div class="w-9 h-9 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center text-blue-600 text-xs shrink-0 font-bold">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        </div>
                                        <div class="overflow-hidden">
                                            <p class="text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($recentUserVerification['full_name'] ?? $recentUserVerification['email'] ?? 'No user screening records found', ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[10px] text-slate-400 font-medium mt-0.5 uppercase tracking-wider">
                                                <?= htmlspecialchars($recentUserVerification ? $recentUserVerification['user_role'] . ' Identity KYC File' : 'System Clear', ENT_QUOTES, 'UTF-8'); ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex gap-4 p-3.5 rounded-xl hover:bg-slate-50 transition border border-transparent hover:border-slate-100">
                                        <div class="w-9 h-9 rounded-xl bg-amber-50 border border-amber-100 flex items-center justify-center text-amber-600 text-xs shrink-0 font-bold">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                                        </div>
                                        <div class="overflow-hidden">
                                            <p class="text-xs font-bold text-slate-800 truncate">
                                                <?= htmlspecialchars($recentVehicleListing ? ($recentVehicleListing['make'] . ' ' . $recentVehicleListing['model'] . ' (' . $recentVehicleListing['year'] . ')') : 'No vehicle validation records found', ENT_QUOTES, 'UTF-8'); ?>
                                            </p>
                                            <p class="text-[10px] text-slate-400 font-medium mt-0.5 uppercase tracking-wider truncate">
                                                <?= htmlspecialchars($recentVehicleListing ? 'Owner: ' . $recentVehicleListing['owner_email'] : 'System Fleet Idle', ENT_QUOTES, 'UTF-8'); ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex gap-4 p-3.5 rounded-xl hover:bg-slate-50 transition border border-transparent hover:border-slate-100">
                                        <div class="w-9 h-9 rounded-xl bg-rose-50 border border-rose-100 flex items-center justify-center text-rose-600 text-xs shrink-0 font-bold">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        </div>
                                        <div class="overflow-hidden">
                                            <p class="text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($recentDisputeTicket['subject'] ?? 'No active disputes flagged', ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[10px] text-slate-400 font-medium mt-0.5 uppercase tracking-wider truncate">
                                                <?= htmlspecialchars($recentDisputeTicket ? 'User: ' . $recentDisputeTicket['user_email'] : 'No Open Conflict Signals', ENT_QUOTES, 'UTF-8'); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <button class="w-full mt-6 py-3 border border-slate-200 hover:border-[#1b4b4b] rounded-xl text-xs font-bold text-slate-500 hover:text-[#1b4b4b] uppercase tracking-wider transition bg-white shadow-sm">
                                View System Audit Trail
                            </button>
                        </div>

                    </div>
                </main>
            </div>

            <footer class="bg-white border-t border-slate-200/80 h-16 flex items-center justify-center px-8 text-center">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Smart Rental Core Trust Architecture v4.2.0 • Restricted Institutional Access</p>
            </footer>

        </div>
    </div>
    <script src="js/admin-app.js" defer></script>
</body>
</html>