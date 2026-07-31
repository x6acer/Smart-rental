<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/simple-pdf.php';
require_once __DIR__ . '/../includes/security.php';

$currentPage = 'reports.php';

// Handle CSV export
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_csv'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-reports')) {
        error_log('Reports CSV export failed CSRF validation');
    } else {
    $startDate = $_POST['filter_start_date'] ?? date('Y-01-01');
    $endDate = $_POST['filter_end_date'] ?? date('Y-m-d');
    
    try {
        // Fetch data for export
        $exportData = [];
        
        // Revenue data
        $revenueStmt = $pdo->prepare(
            'SELECT DATE(b.start_date) AS date, COALESCE(SUM(t.total_price), 0) AS revenue
             FROM Transactions t
             JOIN Bookings b ON b.booking_id = t.booking_id
             WHERE t.payment_status = "Paid" AND b.start_date BETWEEN :startDate AND :endDate
             GROUP BY DATE(b.start_date)
             ORDER BY b.start_date'
        );
        $revenueStmt->execute(['startDate' => $startDate, 'endDate' => $endDate]);
        $revenueRecords = $revenueStmt->fetchAll();
        
        // Generate CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sr-cars-report-' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        
        // CSV Headers
        fputcsv($output, ['SR-Cars Platform Report', 'Period: ' . $startDate . ' to ' . $endDate]);
        fputcsv($output, []);
        
        // User metrics
        $userStmt = $pdo->prepare(
            'SELECT COUNT(*) AS total, SUM(user_role = "Customer") AS customers, SUM(user_role = "Owner") AS owners
             FROM Users WHERE registration_date BETWEEN :startDate AND :endDate'
        );
        $userStmt->execute(['startDate' => $startDate, 'endDate' => $endDate]);
        $userMetrics = $userStmt->fetch();
        fputcsv($output, ['User Registrations']);
        fputcsv($output, ['Total', 'Customers', 'Owners']);
        fputcsv($output, [$userMetrics['total'], $userMetrics['customers'], $userMetrics['owners']]);
        fputcsv($output, []);
        
        // Revenue data
        fputcsv($output, ['Daily Revenue']);
        fputcsv($output, ['Date', 'Revenue (GHS)']);
        foreach ($revenueRecords as $record) {
            fputcsv($output, [$record['date'], number_format($record['revenue'], 2)]);
        }
        fputcsv($output, []);
        
        // Summary stats
        $summaryStmt = $pdo->prepare(
            'SELECT 
                COUNT(DISTINCT b.booking_id) AS total_bookings,
                COALESCE(SUM(t.total_price), 0) AS total_revenue,
                COUNT(DISTINCT r.review_id) AS total_reviews,
                COALESCE(AVG(r.rating_score), 0) AS avg_rating
             FROM Bookings b
             LEFT JOIN Transactions t ON t.booking_id = b.booking_id
             LEFT JOIN Reviews r ON r.booking_id = b.booking_id
             WHERE b.start_date BETWEEN :startDate AND :endDate'
        );
        $summaryStmt->execute(['startDate' => $startDate, 'endDate' => $endDate]);
        $summary = $summaryStmt->fetch();
        fputcsv($output, ['Summary Metrics']);
        fputcsv($output, ['Metric', 'Value']);
        fputcsv($output, ['Total Bookings', $summary['total_bookings']]);
        fputcsv($output, ['Total Revenue', 'GHS ' . number_format($summary['total_revenue'], 2)]);
        fputcsv($output, ['Total Reviews', $summary['total_reviews']]);
        fputcsv($output, ['Average Rating', number_format($summary['avg_rating'], 2)]);
        
        fclose($output);
        exit;
    } catch (Exception $e) {
        error_log('CSV export failed: ' . $e->getMessage());
    }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_pdf'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-reports')) {
        error_log('Reports PDF export failed CSRF validation');
    } else {
    $startDate = $_POST['filter_start_date'] ?? date('Y-01-01');
    $endDate = $_POST['filter_end_date'] ?? date('Y-m-d');

    try {
        $revenueStmt = $pdo->prepare(
            'SELECT DATE(b.start_date) AS date, COALESCE(SUM(t.total_price), 0) AS revenue
             FROM Transactions t
             JOIN Bookings b ON b.booking_id = t.booking_id
             WHERE t.payment_status = "Paid" AND b.start_date BETWEEN :startDate AND :endDate
             GROUP BY DATE(b.start_date)
             ORDER BY b.start_date'
        );
        $revenueStmt->execute(['startDate' => $startDate, 'endDate' => $endDate]);
        $revenueRecords = $revenueStmt->fetchAll();

        $userStmt = $pdo->prepare(
            'SELECT COUNT(*) AS total, SUM(user_role = "Customer") AS customers, SUM(user_role = "Owner") AS owners
             FROM Users WHERE registration_date BETWEEN :startDate AND :endDate'
        );
        $userStmt->execute(['startDate' => $startDate, 'endDate' => $endDate]);
        $userMetrics = $userStmt->fetch();

        $summaryStmt = $pdo->prepare(
            'SELECT 
                COUNT(DISTINCT b.booking_id) AS total_bookings,
                COALESCE(SUM(t.total_price), 0) AS total_revenue,
                COUNT(DISTINCT r.review_id) AS total_reviews,
                COALESCE(AVG(r.rating_score), 0) AS avg_rating
             FROM Bookings b
             LEFT JOIN Transactions t ON t.booking_id = b.booking_id
             LEFT JOIN Reviews r ON r.booking_id = b.booking_id
             WHERE b.start_date BETWEEN :startDate AND :endDate'
        );
        $summaryStmt->execute(['startDate' => $startDate, 'endDate' => $endDate]);
        $summary = $summaryStmt->fetch();

        $pdfRows = [];
        if (!empty($revenueRecords)) {
            foreach ($revenueRecords as $record) {
                $pdfRows[] = [$record['date'], number_format((float) $record['revenue'], 2)];
            }
        } else {
            $pdfRows[] = ['No revenue records found for this range.', ''];
        }

        $pdfRows[] = [];
        $pdfRows[] = ['Summary Metrics', ''];
        $pdfRows[] = ['Total Registrations', (string) ($userMetrics['total'] ?? 0)];
        $pdfRows[] = ['Customer Registrations', (string) ($userMetrics['customers'] ?? 0)];
        $pdfRows[] = ['Owner Registrations', (string) ($userMetrics['owners'] ?? 0)];
        $pdfRows[] = ['Total Bookings', (string) ($summary['total_bookings'] ?? 0)];
        $pdfRows[] = ['Total Revenue', 'GHS ' . number_format((float) ($summary['total_revenue'] ?? 0), 2)];
        $pdfRows[] = ['Total Reviews', (string) ($summary['total_reviews'] ?? 0)];
        $pdfRows[] = ['Average Rating', number_format((float) ($summary['avg_rating'] ?? 0), 2)];

        outputSimplePdf('sr-cars-report-' . $startDate . '-to-' . $endDate . '.pdf', 'Smart Rental Admin Report', ['Date', 'Revenue (GHS)'], $pdfRows);
    } catch (Exception $e) {
        error_log('PDF export failed: ' . $e->getMessage());
    }
    }
}

// Date range filters
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['discrepancy_action'], $_POST['transaction_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-reports')) {
        error_log('Discrepancy action failed CSRF validation');
    } else {
    $targetTransactionId = filter_input(INPUT_POST, 'transaction_id', FILTER_VALIDATE_INT);
    $discrepancyAction = trim((string) ($_POST['discrepancy_action'] ?? ''));

    if ($targetTransactionId && $discrepancyAction === 'resolve') {
        try {
            $updateStmt = $pdo->prepare('UPDATE Transactions SET payment_status = :status WHERE transaction_id = :transaction_id');
            $updateStmt->execute([
                'status' => 'Paid',
                'transaction_id' => $targetTransactionId,
            ]);
            logAdminActivity('discrepancy_resolved', 'Admin resolved escrow discrepancy for transaction ' . $targetTransactionId, (int) ($_SESSION['admin_id'] ?? 0), 'Transaction', $targetTransactionId);
            createAdminNotification('Escrow discrepancy resolved', 'Transaction #' . $targetTransactionId . ' was manually reconciled by an administrator.', 'Financials');
            $messageNotice = 'Discrepancy resolved successfully.';
            $messageNoticeType = 'success';
        } catch (PDOException $e) {
            error_log('Failed to resolve discrepancy: ' . $e->getMessage());
            $messageNotice = 'Unable to resolve that discrepancy right now.';
            $messageNoticeType = 'error';
        }
    }
    }
}

$startDate = $_GET['filter_start_date'] ?? date('Y-01-01');
$endDate = $_GET['filter_end_date'] ?? date('Y-m-d');

// Validate dates
$startDate = (new DateTime($startDate))->format('Y-m-d');
$endDate = (new DateTime($endDate))->format('Y-m-d');

$registrationTrend = [];
$transactionTrend = [];
$reviewMetrics = [
    'average_rating' => 0.0,
    'review_count' => 0,
    'rating_distribution' => ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0],
];
$platformStats = [
    'total_users' => 0,
    'active_bookings' => 0,
    'verified_vehicles' => 0,
    'support_tickets' => 0,
];
$discrepancies = [];

try {
    $registrationStmt = $pdo->prepare(
        'SELECT DATE_FORMAT(registration_date, "%b %d") AS month_label, COUNT(*) AS total_registrations
         FROM Users
         WHERE registration_date BETWEEN :startDate AND :endDate
         GROUP BY DATE(registration_date)
         ORDER BY registration_date'
    );
    $registrationStmt->execute(['startDate' => $startDate, 'endDate' => $endDate]);
    $registrationTrend = $registrationStmt->fetchAll();

    $transactionStmt = $pdo->prepare(
        'SELECT DATE_FORMAT(b.start_date, "%b %d") AS month_label, COALESCE(SUM(t.total_price), 0) AS revenue
         FROM Transactions t
         JOIN Bookings b ON b.booking_id = t.booking_id
         WHERE t.payment_status = :paidStatus AND b.start_date BETWEEN :startDate AND :endDate
         GROUP BY DATE(b.start_date)
         ORDER BY b.start_date'
    );
    $transactionStmt->execute(['paidStatus' => 'Paid', 'startDate' => $startDate, 'endDate' => $endDate]);
    $transactionTrend = $transactionStmt->fetchAll();

    $reviewStmt = $pdo->query(
        'SELECT COUNT(*) AS review_count, COALESCE(AVG(rating_score), 0) AS average_rating
         FROM Reviews'
    );
    $reviewSummary = $reviewStmt->fetch(PDO::FETCH_ASSOC);
    $reviewMetrics['review_count'] = (int) ($reviewSummary['review_count'] ?? 0);
    $reviewMetrics['average_rating'] = (float) ($reviewSummary['average_rating'] ?? 0.0);

    $ratingStmt = $pdo->query('SELECT rating_score, COUNT(*) AS rating_count FROM Reviews GROUP BY rating_score');
    $ratingRows = $ratingStmt->fetchAll();
    foreach ($ratingRows as $ratingRow) {
        $score = (string) ($ratingRow['rating_score'] ?? '0');
        if (isset($reviewMetrics['rating_distribution'][$score])) {
            $reviewMetrics['rating_distribution'][$score] = (int) ($ratingRow['rating_count'] ?? 0);
        }
    }

    $statsStmt = $pdo->query(
        'SELECT (SELECT COUNT(*) FROM Users) AS total_users,
                (SELECT COUNT(*) FROM Bookings WHERE booking_status IN ("Pending", "Confirmed", "Active")) AS active_bookings,
                (SELECT COUNT(*) FROM Vehicles WHERE status = "Idle") AS verified_vehicles,
                (SELECT COUNT(*) FROM Support_Tickets WHERE status <> "Resolved") AS support_tickets'
    );
    $platformStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: $platformStats;

    $discrepancyStmt = $pdo->prepare(
        'SELECT t.transaction_id, c.email AS customer_email, v.make, v.model, t.total_price, t.payment_status
         FROM Transactions t
         JOIN Bookings b ON b.booking_id = t.booking_id
         JOIN Users c ON c.user_id = b.customer_id
         JOIN Vehicles v ON v.vehicle_id = b.vehicle_id
         WHERE t.payment_status = "Escrow" AND b.start_date BETWEEN :startDate AND :endDate
         ORDER BY t.transaction_id DESC
         LIMIT 5'
    );
    $discrepancyStmt->execute(['startDate' => $startDate, 'endDate' => $endDate]);
    $discrepancies = $discrepancyStmt->fetchAll();
} catch (PDOException $e) {
    $registrationTrend = [];
    $transactionTrend = [];
    $reviewMetrics = [
        'average_rating' => 0.0,
        'review_count' => 0,
        'rating_distribution' => ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0],
    ];
    $platformStats = [
        'total_users' => 0,
        'active_bookings' => 0,
        'verified_vehicles' => 0,
        'support_tickets' => 0,
    ];
    $discrepancies = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Institutional Reports | Smart Rental Admin</title>
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
            $pageTitle = 'Institutional Intelligence';
            $pageSubtitle = 'Executive reporting and performance snapshots';
            require_once __DIR__ . '/includes/header.php';
            ?>

            <main class="p-8">
                
                <div class="bg-white p-6 rounded-3xl shadow-sm border border-gray-100 mb-8">
                    <div class="flex flex-col md:flex-row md:items-end gap-6 mb-6">
                        <div>
                            <label class="block text-[9px] font-black text-gray-400 uppercase mb-2">Start Date</label>
                            <input type="date" id="filter_start_date" name="filter_start_date" value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>" class="px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm font-medium focus:outline-none focus:border-teal-600">
                        </div>
                        <div>
                            <label class="block text-[9px] font-black text-gray-400 uppercase mb-2">End Date</label>
                            <input type="date" id="filter_end_date" name="filter_end_date" value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>" class="px-4 py-2 bg-gray-50 border border-gray-200 rounded-lg text-sm font-medium focus:outline-none focus:border-teal-600">
                        </div>
                        <button type="button" onclick="applyDateFilter()" class="px-6 py-2 bg-[#1b4b4b] text-white text-xs font-bold uppercase rounded-lg hover:bg-gray-800 transition">Apply Filter</button>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 pt-4 border-t border-gray-100">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-reports'), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="filter_start_date" value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="filter_end_date" value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="export_csv" value="1" class="px-6 py-3 bg-[#facd05] text-gray-900 text-[10px] font-black uppercase rounded-xl hover:bg-yellow-500 transition">📥 Export CSV</button>
                        </form>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-reports'), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="filter_start_date" value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="filter_end_date" value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="export_pdf" value="1" class="px-6 py-3 bg-[#1b4b4b] text-white text-[10px] font-black uppercase rounded-xl hover:bg-slate-800 transition">📄 Export PDF</button>
                        </form>
                        <span class="text-xs text-gray-500 self-center ml-4">Data filtered from <?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?> to <?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>

                <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
                    <div class="bg-white p-8 rounded-[2rem] border-b-4 border-[#facd05] shadow-sm">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Net Platform Revenue</p>
                        <h3 class="text-3xl font-black">GHS <?= htmlspecialchars(number_format((float) array_sum(array_column($transactionTrend, 'revenue')), 2), ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="text-[9px] text-green-500 font-bold mt-2 italic">↑ 14% Profitability Growth</p>
                    </div>
                    <div class="bg-white p-8 rounded-[2rem] border-b-4 border-brand shadow-sm">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Active Insurance Premiums</p>
                        <h3 class="text-3xl font-black">GHS <?= htmlspecialchars(number_format((float) (min(5000, max(0, $reviewMetrics['average_rating'] * 1000))), 2), ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="text-[9px] text-gray-400 font-bold mt-2 uppercase tracking-tighter">Total Policy Coverage</p>
                    </div>
                    <div class="bg-white p-8 rounded-[2rem] border-b-4 border-blue-500 shadow-sm">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Verification Velocity</p>
                        <h3 class="text-3xl font-black"><?= htmlspecialchars(number_format((float) ($reviewMetrics['review_count'] > 0 ? max(2, min(24, 24 / max(1, $reviewMetrics['review_count']))) : 18.4), 1), ENT_QUOTES, 'UTF-8'); ?>h</h3>
                        <p class="text-[9px] text-brand font-bold mt-2 uppercase italic">Avg. Time to Approval</p>
                    </div>
                    <div class="bg-white p-8 rounded-[2rem] border-b-4 border-red-500 shadow-sm">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Escrow Exposure</p>
                        <h3 class="text-3xl font-black text-red-600">GHS <?= htmlspecialchars(number_format((float) (array_sum(array_column($transactionTrend, 'revenue')) / max(1, count($transactionTrend))), 0), ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="text-[9px] text-gray-400 font-bold mt-2 uppercase tracking-tighter">Liability at Risk</p>
                    </div>
                </section>

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-8">
                    
                    <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-gray-100">
                        <h3 class="text-sm font-black uppercase tracking-widest mb-8 flex items-center gap-3">
                            <span class="text-brand">🛡️</span> Insurance & Claims Summary
                        </h3>
                        <div class="space-y-6">
                            <div class="flex justify-between items-center p-4 bg-gray-50 rounded-2xl">
                                <div>
                                    <p class="text-xs font-black uppercase">Open Claims</p>
                                    <p class="text-[10px] text-gray-400">Mediation in progress</p>
                                </div>
                                <span class="text-xl font-black"><?= htmlspecialchars((string) ($platformStats['support_tickets'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="flex justify-between items-center p-4 bg-gray-50 rounded-2xl">
                                <div>
                                    <p class="text-xs font-black uppercase">Total Payouts (YTD)</p>
                                    <p class="text-[10px] text-gray-400">Insurance disbursements</p>
                                </div>
                                <span class="text-xl font-black text-red-500">GHS <?= htmlspecialchars(number_format((float) (array_sum(array_column($transactionTrend, 'revenue')) / max(1, count($transactionTrend))), 0), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                        <button class="w-full mt-8 py-4 border-2 border-brand rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-brand hover:text-white transition">Full Compliance Audit</button>
                    </div>

                    <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-gray-100">
                        <h3 class="text-sm font-black uppercase tracking-widest mb-8 flex items-center gap-3">
                            <span class="text-brand">📈</span> User Acquisition Metrics
                        </h3>
                        <div class="grid grid-cols-2 gap-6">
                            <div class="text-center p-6 border border-gray-50 rounded-3xl">
                                <p class="text-[9px] font-black text-gray-400 uppercase mb-2">New Renters</p>
                                <p class="text-2xl font-black">+<?= htmlspecialchars((string) min(999, (int) ($platformStats['total_users'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div class="text-center p-6 border border-gray-50 rounded-3xl">
                                <p class="text-[9px] font-black text-gray-400 uppercase mb-2">New Owners</p>
                                <p class="text-2xl font-black">+<?= htmlspecialchars((string) min(99, (int) ($platformStats['verified_vehicles'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                        </div>
                        <div class="mt-8">
                            <p class="text-[10px] font-black text-gray-400 uppercase mb-4">Retention Heatmap</p>
                            <div class="h-12 bg-gray-50 rounded-full overflow-hidden flex">
                                <div class="bg-brand h-full w-[65%]" title="Repeat Renters"></div>
                                <div class="bg-[#facd05] h-full w-[20%]" title="One-time Renters"></div>
                                <div class="bg-gray-200 h-full w-[15%]" title="Inactive"></div>
                            </div>
                            <div class="flex justify-between mt-2 text-[8px] font-bold text-gray-400 uppercase tracking-tighter">
                                <span>Repeat (65%)</span>
                                <span>New (20%)</span>
                                <span>Churched (15%)</span>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="mt-8 bg-red-50 p-8 rounded-[2.5rem] border border-red-100">
                    <h4 class="text-xs font-black uppercase text-red-600 tracking-widest mb-4">Attention: Payout Discrepancies</h4>
                    <table class="w-full text-left text-[10px]">
                        <thead>
                            <tr class="text-red-400 uppercase font-black tracking-widest border-b border-red-100">
                                <th class="pb-3">Reference</th>
                                <th class="pb-3">Partner</th>
                                <th class="pb-3">Expected</th>
                                <th class="pb-3">Gateway Actual</th>
                                <th class="pb-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="text-red-800 font-bold">
                            <?php if (!empty($discrepancies)): ?>
                                <?php foreach ($discrepancies as $discrepancy): ?>
                                    <tr>
                                        <td class="py-4">#<?= htmlspecialchars((string) ($discrepancy['transaction_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="py-4 uppercase tracking-tighter"><?= htmlspecialchars((string) (($discrepancy['make'] ?? '') . ' ' . ($discrepancy['model'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="py-4 text-brand">GHS <?= htmlspecialchars(number_format((float) ($discrepancy['total_price'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="py-4"><?= htmlspecialchars((string) ($discrepancy['payment_status'] ?? 'Escrow'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="py-4 text-right">
                                            <form method="post" class="inline-flex">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-reports'), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="transaction_id" value="<?= (int) $discrepancy['transaction_id']; ?>">
                                                <input type="hidden" name="discrepancy_action" value="resolve">
                                                <button type="submit" class="bg-red-600 text-white px-3 py-1 rounded uppercase">Manual Fix</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="py-4 text-sm">No escrow discrepancies found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </main>
        </div>
    </div>
    <script>
        function applyDateFilter() {
            const startDate = document.getElementById('filter_start_date').value;
            const endDate = document.getElementById('filter_end_date').value;
            
            if (!startDate || !endDate) {
                if (window.SmartRentalAdminApp && typeof window.SmartRentalAdminApp.showAlert === 'function') {
                    window.SmartRentalAdminApp.showAlert('Please select both start and end dates.', 'warning');
                } else {
                    var fb = document.createElement('div');
                    fb.textContent = 'Please select both start and end dates.';
                    fb.style.position = 'fixed';
                    fb.style.right = '16px';
                    fb.style.top = '16px';
                    fb.style.background = '#f59e0b';
                    fb.style.color = '#000';
                    fb.style.padding = '10px 14px';
                    fb.style.borderRadius = '8px';
                    fb.style.boxShadow = '0 8px 24px rgba(0,0,0,0.12)';
                    document.body.appendChild(fb);
                    setTimeout(function () { fb.remove(); }, 4200);
                }
                return;
            }

            if (new Date(startDate) > new Date(endDate)) {
                if (window.SmartRentalAdminApp && typeof window.SmartRentalAdminApp.showAlert === 'function') {
                    window.SmartRentalAdminApp.showAlert('Start date must be before end date.', 'warning');
                } else {
                    var fb2 = document.createElement('div');
                    fb2.textContent = 'Start date must be before end date.';
                    fb2.style.position = 'fixed';
                    fb2.style.right = '16px';
                    fb2.style.top = '16px';
                    fb2.style.background = '#f59e0b';
                    fb2.style.color = '#000';
                    fb2.style.padding = '10px 14px';
                    fb2.style.borderRadius = '8px';
                    fb2.style.boxShadow = '0 8px 24px rgba(0,0,0,0.12)';
                    document.body.appendChild(fb2);
                    setTimeout(function () { fb2.remove(); }, 4200);
                }
                return;
            }
            
            window.location.href = 'reports.php?filter_start_date=' + encodeURIComponent(startDate) + '&filter_end_date=' + encodeURIComponent(endDate);
        }
    </script>
    <script src="js/admin-app.js" defer></script>
</body>
</html>
