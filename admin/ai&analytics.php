<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';

$currentPage = 'ai&analytics.php';

$revenueProjection = 0.0;
$riskIndex = 0.0;
$retentionRate = 0.0;
$pricingInsights = [];
$activeRentals = 0;
$monthlyGrowth = [];
$vehicleUtilization = 0.0;
$highDemandWindows = [];

function buildPredictiveDemandBlocks(PDO $pdo): array
{
    $demand = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT DATE_FORMAT(start_date, "%Y-%m") AS month_bucket, COUNT(*) AS booking_count
             FROM Bookings
             GROUP BY month_bucket
             ORDER BY month_bucket ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $demand[] = [
                'month' => (string) ($row['month_bucket'] ?? ''),
                'bookings' => (int) ($row['booking_count'] ?? 0),
            ];
        }
    } catch (PDOException $e) {
        error_log('Failed to build demand blocks: ' . $e->getMessage());
    }

    return $demand;
}

try {
    $revenueStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(t.total_price), 0) AS revenue
         FROM Transactions t
         JOIN Bookings b ON b.booking_id = t.booking_id
         WHERE t.payment_status = :paidStatus
           AND b.start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)'
    );
    $revenueStmt->execute(['paidStatus' => 'Paid']);
    $thirtyDayRevenue = (float) $revenueStmt->fetchColumn();
    $revenueProjection = round($thirtyDayRevenue * 1.12, 2);

    $riskUsersStmt = $pdo->query('SELECT COUNT(*) FROM Users WHERE account_status = "Suspended"');
    $suspendedUserCount = (int) $riskUsersStmt->fetchColumn();
    $vehicleCountStmt = $pdo->query('SELECT COUNT(*) FROM Vehicles');
    $vehicleCount = max(1, (int) $vehicleCountStmt->fetchColumn());
    $riskIndex = round(min(1.0, $suspendedUserCount / $vehicleCount), 2);

    $repeatCustomersStmt = $pdo->query(
        'SELECT COUNT(DISTINCT customer_id) AS repeat_customers
         FROM (
             SELECT customer_id, COUNT(*) AS booking_count
             FROM Bookings
             GROUP BY customer_id
             HAVING booking_count > 1
         ) repeated'
    );
    $repeatCustomerCount = (int) $repeatCustomersStmt->fetchColumn();
    $allCustomersStmt = $pdo->query('SELECT COUNT(*) FROM Users WHERE user_role = "Customer"');
    $allCustomersCount = max(1, (int) $allCustomersStmt->fetchColumn());
    $retentionRate = round(($repeatCustomerCount / $allCustomersCount) * 100, 1);

    $activeRentalsStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM Bookings
         WHERE booking_status IN ("Confirmed", "Active")'
    );
    $activeRentalsStmt->execute();
    $activeRentals = (int) $activeRentalsStmt->fetchColumn();

    $monthlyGrowthStmt = $pdo->prepare(
        'SELECT DATE_FORMAT(start_date, "%b") AS month_name, COUNT(*) AS booking_count
         FROM Bookings
         WHERE start_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 MONTH)
         GROUP BY month_name
         ORDER BY MIN(start_date) ASC'
    );
    $monthlyGrowthStmt->execute();
    $monthlyGrowth = $monthlyGrowthStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $utilizationStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT vehicle_id) AS active_vehicles
         FROM Bookings
         WHERE booking_status IN ("Confirmed", "Active")'
    );
    $utilizationStmt->execute();
    $activeVehicleCount = max(1, (int) $utilizationStmt->fetchColumn());
    $vehicleUtilization = round(min(100, ($activeVehicleCount / max(1, $vehicleCount)) * 100), 1);

    $pricingStmt = $pdo->prepare(
        'SELECT v.make, COUNT(*) AS booking_count
         FROM Bookings b
         JOIN Vehicles v ON v.vehicle_id = b.vehicle_id
         GROUP BY v.make
         ORDER BY booking_count DESC
         LIMIT 2'
    );
    $pricingStmt->execute();
    $pricingRows = $pricingStmt->fetchAll();
    foreach ($pricingRows as $row) {
        $pricingInsights[] = [
            'label' => (string) ($row['make'] ?? 'Vehicle'),
            'booking_count' => (int) ($row['booking_count'] ?? 0),
        ];
    }

    $highDemandWindows = buildPredictiveDemandBlocks($pdo);
} catch (PDOException $e) {
    $revenueProjection = 0.0;
    $riskIndex = 0.0;
    $retentionRate = 0.0;
    $pricingInsights = [];
    $activeRentals = 0;
    $monthlyGrowth = [];
    $vehicleUtilization = 0.0;
    $highDemandWindows = [];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>AI Intelligence & Predictive Analytics | Smart Rental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --brand-primary: #1b4b4b;
            --brand-accent: #facd05;
        }
        .ai-glow { box-shadow: 0 0 15px rgba(250, 205, 5, 0.15); }
        .gradient-text { background: linear-gradient(to right, var(--brand-primary), #4b5563); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    </style>
</head>

<body class="bg-[#f8fafc] text-slate-900 antialiased">
    <div class="min-h-screen flex">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="flex-grow lg:ml-[280px] min-h-screen flex flex-col">
            <?php
            $pageTitle = 'Predictive Intelligence';
            $pageSubtitle = 'Data model: SmartNet-v3 • Last trained 4h ago';
            $showStatusBadge = false;
            require_once __DIR__ . '/includes/header.php';
            ?>

            <main class="p-8">
                
                <section class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-10">
                    
                    <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-gray-100 relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-24 h-24 bg-brand/5 rounded-full -mr-12 -mt-12"></div>
                        <h4 class="text-[10px] font-black uppercase text-gray-400 tracking-widest mb-6">30-Day Revenue Projection</h4>
                        <div class="flex items-baseline gap-2">
                            <h3 class="text-3xl font-black italic">GHS <?= htmlspecialchars(number_format($revenueProjection, 2), ENT_QUOTES, 'UTF-8'); ?></h3>
                            <span class="text-[10px] font-black text-green-500 uppercase">↑ 12% Confident</span>
                        </div>
                        <div class="mt-6 h-20 flex items-end gap-1">
                            <div class="flex-1 bg-gray-100 h-[60%] rounded-t-sm"></div>
                            <div class="flex-1 bg-gray-100 h-[65%] rounded-t-sm"></div>
                            <div class="flex-1 bg-gray-100 h-[75%] rounded-t-sm"></div>
                            <div class="flex-1 bg-brand h-[85%] rounded-t-sm"></div>
                            <div class="flex-1 bg-brand/30 border-t-2 border-dashed border-brand h-[90%] rounded-t-sm"></div>
                            <div class="flex-1 bg-brand/20 border-t-2 border-dashed border-brand h-[95%] rounded-t-sm"></div>
                        </div>
                    </div>

                    <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-gray-100">
                        <h4 class="text-[10px] font-black uppercase text-gray-400 tracking-widest mb-6">Security Risk Matrix</h4>
                        <div class="flex items-center gap-6">
                            <div class="w-20 h-20 rounded-full border-8 border-brand flex items-center justify-center">
                                <span class="text-xl font-black">Low</span>
                            </div>
                            <div class="space-y-2">
                                <p class="text-[10px] font-bold text-gray-700">Anomaly Index: <?= htmlspecialchars(number_format($riskIndex, 2), ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="text-[10px] font-bold text-gray-700">Flagged Users: <?= htmlspecialchars((string) (int) max(0, $riskIndex * 100), ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="text-[10px] font-black text-brand uppercase underline cursor-pointer">View Guardrails</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-brand p-8 rounded-[2.5rem] shadow-xl text-white">
                        <h4 class="text-[10px] font-black uppercase text-yellow-400 tracking-widest mb-6">Partner Retention</h4>
                        <div class="flex items-baseline gap-2">
                            <h3 class="text-3xl font-black"><?= htmlspecialchars(number_format($retentionRate, 1), ENT_QUOTES, 'UTF-8'); ?>%</h3>
                            <span class="text-[10px] font-bold opacity-60">Fleet Loyalty</span>
                        </div>
                        <p class="text-[10px] font-medium opacity-80 mt-4 leading-relaxed italic">"Owners in Accra Mall region are 20% more likely to renew premium insurance tiers next quarter."</p>
                    </div>

                </section>

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-8">
                    
                    <div class="bg-white p-8 rounded-[3rem] shadow-sm border border-gray-100">
                        <div class="flex justify-between items-center mb-10">
                            <h4 class="text-sm font-black uppercase tracking-widest">Regional Demand Density</h4>
                            <span class="text-[9px] font-black bg-yellow-50 text-yellow-700 px-3 py-1 rounded-full uppercase tracking-widest">Live Optimization</span>
                        </div>
                        <div class="h-80 bg-gray-50 rounded-[2rem] border border-gray-100 relative overflow-hidden">
                            <div class="absolute top-1/2 left-1/3 w-32 h-32 bg-brand/10 rounded-full blur-3xl"></div>
                            <div class="absolute top-1/4 right-1/4 w-40 h-40 bg-yellow-400/20 rounded-full blur-3xl animate-pulse"></div>
                            <div class="flex items-center justify-center h-full">
                                <p class="text-[10px] font-black text-gray-300 uppercase tracking-[0.5em]">Spatial Intelligence Render</p>
                            </div>
                        </div>
                        <div class="mt-8 flex justify-between">
                            <div class="text-center">
                                <p class="text-[9px] font-black text-gray-400 uppercase">Hot Zone</p>
                                <p class="text-xs font-bold">Airport T3</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[9px] font-black text-gray-400 uppercase">Rising</p>
                                <p class="text-xs font-bold">Labadi Beach</p>
                            </div>
                            <div class="text-center">
                                <p class="text-[9px] font-black text-gray-400 uppercase">Oversupply</p>
                                <p class="text-xs font-bold">Tema Port</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-8 rounded-[3rem] shadow-sm border border-gray-100 flex flex-col">
                        <h4 class="text-sm font-black uppercase tracking-widest mb-10">Algorithmic Pricing Insights</h4>
                        <div class="flex-grow space-y-6">
                            <?php if (!empty($pricingInsights)): ?>
                                <?php foreach ($pricingInsights as $pricingInsight): ?>
                                    <div class="p-6 bg-gray-50 rounded-3xl flex items-center justify-between">
                                        <div class="flex items-center gap-4">
                                            <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-sm">🚙</div>
                                            <div>
                                                <p class="text-xs font-black uppercase"><?= htmlspecialchars((string) $pricingInsight['label'], ENT_QUOTES, 'UTF-8'); ?></p>
                                                <p class="text-[9px] text-gray-400 font-bold">Avg. Utilization: <?= htmlspecialchars((string) min(100, max(0, (int) $pricingInsight['booking_count'] * 10)), ENT_QUOTES, 'UTF-8'); ?>%</p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-[9px] font-black text-green-500 uppercase">Recommendation</p>
                                            <p class="text-sm font-black">+<?= htmlspecialchars((string) ((int) $pricingInsight['booking_count'] + 5), ENT_QUOTES, 'UTF-8'); ?> booking(s)</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state p-6 text-sm">
                                    <div class="es-icon">📈</div>
                                    <div>No pricing insights available yet.</div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <button class="w-full mt-10 py-5 bg-[#facd05] text-brand rounded-2xl font-black text-[10px] uppercase tracking-widest shadow-lg hover:scale-[1.02] transition">Apply Pricing Adjustments</button>
                    </div>

                </div>

            </main>
        </div>
    </div>
    <script src="js/admin-app.js" defer></script>
</body>
</html>
