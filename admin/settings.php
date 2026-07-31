<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';

$currentPage = 'settings.php';

$settingsNotice = '';
$settingsNoticeType = 'info';
$settingsProfile = [
    'platform_commission' => 15,
    'momo_fee' => 45.00,
    'escrow_holding_hours' => 24,
    'insurance_premium' => 2.5,
    'two_factor_enabled' => true,
    'verification_bypass' => false,
    'maintenance_mode' => false,
];

function loadAdminSettings(PDO $pdo, int $adminId): array
{
    $settingsProfile = [
        'platform_commission' => 15,
        'momo_fee' => 45.00,
        'escrow_holding_hours' => 24,
        'insurance_premium' => 2.5,
        'two_factor_enabled' => true,
        'verification_bypass' => false,
        'maintenance_mode' => false,
    ];

    try {
        $profileStmt = $pdo->prepare('SELECT business_settings FROM User_Profiles WHERE user_id = :user_id LIMIT 1');
        $profileStmt->execute(['user_id' => $adminId]);
        $profileRow = $profileStmt->fetch(PDO::FETCH_ASSOC);
        if ($profileRow && !empty($profileRow['business_settings'])) {
            $decodedSettings = json_decode((string) $profileRow['business_settings'], true);
            if (is_array($decodedSettings)) {
                $settingsProfile = array_replace($settingsProfile, $decodedSettings);
            }
        }
    } catch (PDOException $e) {
        error_log('Failed to load admin settings: ' . $e->getMessage());
    }

    return $settingsProfile;
}

function saveAdminSettings(PDO $pdo, int $adminId, array $submittedSettings): array
{
    $defaults = [
        'platform_commission' => 15,
        'momo_fee' => 45.00,
        'escrow_holding_hours' => 24,
        'insurance_premium' => 2.5,
        'two_factor_enabled' => true,
        'verification_bypass' => false,
        'maintenance_mode' => false,
    ];

    $mergedSettings = array_replace($defaults, $submittedSettings);
    $settingsJson = json_encode($mergedSettings, JSON_UNESCAPED_SLASHES);

    $profileStmt = $pdo->prepare('SELECT profile_id, business_settings FROM User_Profiles WHERE user_id = :user_id LIMIT 1');
    $profileStmt->execute(['user_id' => $adminId]);
    $profileRow = $profileStmt->fetch(PDO::FETCH_ASSOC);

    if ($profileRow) {
        $updateStmt = $pdo->prepare('UPDATE User_Profiles SET business_settings = :settings WHERE profile_id = :profile_id');
        $updateStmt->execute(['settings' => $settingsJson, 'profile_id' => (int) $profileRow['profile_id']]);
    } else {
        $insertStmt = $pdo->prepare('INSERT INTO User_Profiles (user_id, business_settings) VALUES (:user_id, :settings)');
        $insertStmt->execute(['user_id' => $adminId, 'settings' => $settingsJson]);
    }

    return $mergedSettings;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-settings')) {
        $settingsNotice = 'Security check failed. Please try again.';
        $settingsNoticeType = 'error';
    } else {
        $submittedSettings = [
            'platform_commission' => (float) ($_POST['platform_commission'] ?? 15),
            'momo_fee' => (float) ($_POST['momo_fee'] ?? 45.00),
            'escrow_holding_hours' => (int) ($_POST['escrow_holding_hours'] ?? 24),
            'insurance_premium' => (float) ($_POST['insurance_premium'] ?? 2.5),
            'two_factor_enabled' => isset($_POST['two_factor_enabled']),
            'verification_bypass' => isset($_POST['verification_bypass']),
            'maintenance_mode' => isset($_POST['maintenance_mode']),
        ];

        try {
            $settingsProfile = saveAdminSettings($pdo, (int) ($_SESSION['admin_id'] ?? 0), $submittedSettings);
            $settingsNotice = 'Global compliance settings were updated.';
            $settingsNoticeType = 'success';
        } catch (PDOException $e) {
            $settingsNotice = 'Unable to save the compliance settings right now.';
            $settingsNoticeType = 'error';
        }
    }
} else {
    $settingsProfile = loadAdminSettings($pdo, (int) ($_SESSION['admin_id'] ?? 0));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Global System Settings | Smart Rental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --brand-primary: #1b4b4b;
            --brand-accent: #facd05;
        }
        .toggle-switch:checked + .toggle-bg { background-color: #10b981; }
        .toggle-switch:checked + .toggle-bg .toggle-dot { transform: translateX(100%); }
    </style>
</head>

<body class="bg-[#f8fafc] text-slate-900 antialiased">
    <div class="min-h-screen flex">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="flex-grow lg:ml-[280px] min-h-screen flex flex-col">
            <?php
            $pageTitle = 'System Configuration';
            $pageSubtitle = 'Platform economics and security controls';
            require_once __DIR__ . '/includes/header.php';
            ?>

            <main class="p-8">
                <?php if ($settingsNotice !== ''): ?>
                    <div class="mb-6 rounded-2xl border px-4 py-3 text-sm font-semibold <?= $settingsNoticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'; ?>">
                        <?= htmlspecialchars($settingsNotice, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                
                <form method="post" class="space-y-8" data-validate="true">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-settings'), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="save_settings" value="1">

                    <div class="grid grid-cols-1 xl:grid-cols-12 gap-8">
                        <div class="xl:col-span-8 space-y-8">
                            <section class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-gray-100">
                                <h3 class="text-sm font-black uppercase tracking-widest mb-8 flex items-center gap-3 text-brand">
                                    🌐 Platform Economics
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                    <div>
                                        <label class="text-[10px] font-black uppercase text-gray-400 block mb-2">Platform Commission (%)</label>
                                        <input type="number" name="platform_commission" value="<?= htmlspecialchars((string) ($settingsProfile['platform_commission'] ?? 15), ENT_QUOTES, 'UTF-8'); ?>" min="0" max="100" required class="w-full p-4 bg-gray-50 border border-gray-100 rounded-2xl outline-none focus:border-brand font-bold">
                                        <p class="text-[9px] text-gray-400 mt-2">Applies to all new vehicle bookings globally.</p>
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-black uppercase text-gray-400 block mb-2">MOMO Processing Fee (GHS)</label>
                                        <input type="number" step="0.01" name="momo_fee" value="<?= htmlspecialchars((string) ($settingsProfile['momo_fee'] ?? 45.00), ENT_QUOTES, 'UTF-8'); ?>" min="0" max="1000" required class="w-full p-4 bg-gray-50 border border-gray-100 rounded-2xl outline-none focus:border-brand font-bold">
                                        <p class="text-[9px] text-gray-400 mt-2">Flat fee added to the transaction summary (Flow 1.3).</p>
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-black uppercase text-gray-400 block mb-2">Escrow Holding Period (Hours)</label>
                                        <input type="number" name="escrow_holding_hours" value="<?= htmlspecialchars((string) ($settingsProfile['escrow_holding_hours'] ?? 24), ENT_QUOTES, 'UTF-8'); ?>" min="1" max="720" required class="w-full p-4 bg-gray-50 border border-gray-100 rounded-2xl outline-none focus:border-brand font-bold">
                                        <p class="text-[9px] text-gray-400 mt-2">Delay between rental end and payout release (Flow 3.4).</p>
                                    </div>
                                    <div>
                                        <label class="text-[10px] font-black uppercase text-gray-400 block mb-2">Base Insurance Premium (%)</label>
                                        <input type="number" step="0.1" name="insurance_premium" value="<?= htmlspecialchars((string) ($settingsProfile['insurance_premium'] ?? 2.5), ENT_QUOTES, 'UTF-8'); ?>" min="0" max="100" required class="w-full p-4 bg-gray-50 border border-gray-100 rounded-2xl outline-none focus:border-brand font-bold">
                                        <p class="text-[9px] text-gray-400 mt-2">Calculated from the total rental value (Flow 3.6).</p>
                                    </div>
                                </div>
                            </section>

                            <section class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-gray-100">
                                <h3 class="text-sm font-black uppercase tracking-widest mb-8 flex items-center gap-3 text-brand">
                                    🔐 Access Control & Security
                                </h3>
                                <div class="space-y-6">
                                    <div class="flex items-center justify-between p-6 bg-gray-50 rounded-3xl">
                                        <div>
                                            <p class="text-xs font-black uppercase">Two-Factor Authentication (2FA)</p>
                                            <p class="text-[10px] text-gray-400 font-medium">Require email OTP for all Administrative logins.</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="two_factor_enabled" class="sr-only toggle-switch" <?= !empty($settingsProfile['two_factor_enabled']) ? 'checked' : ''; ?>>
                                            <div class="w-12 h-6 bg-gray-200 rounded-full toggle-bg transition duration-300">
                                                <div class="w-5 h-5 bg-white rounded-full m-0.5 toggle-dot shadow-sm transition duration-300"></div>
                                            </div>
                                        </label>
                                    </div>
                                    <div class="flex items-center justify-between p-6 bg-gray-50 rounded-3xl">
                                        <div>
                                            <p class="text-xs font-black uppercase">Institutional Verification Bypass</p>
                                            <p class="text-[10px] text-gray-400 font-medium">Permit system-level override of identity validation checks.</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="verification_bypass" class="sr-only toggle-switch" <?= !empty($settingsProfile['verification_bypass']) ? 'checked' : ''; ?>>
                                            <div class="w-12 h-6 bg-gray-200 rounded-full toggle-bg transition duration-300">
                                                <div class="w-5 h-5 bg-white rounded-full m-0.5 toggle-dot shadow-sm transition duration-300"></div>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <div class="xl:col-span-4 space-y-8">
                            <div class="bg-red-50 p-8 rounded-[2.5rem] border border-red-100">
                                <h4 class="text-xs font-black uppercase text-red-600 tracking-widest mb-4">Maintenance Protocol</h4>
                                <p class="text-[10px] text-red-800 font-medium mb-6">Activating Maintenance Mode will prevent Renters and Owners from accessing the web application. Scheduled tasks will continue.</p>
                                <label class="flex items-center justify-between p-6 rounded-3xl bg-white border border-red-200 cursor-pointer">
                                    <div>
                                        <p class="text-xs font-black uppercase">Maintenance Mode</p>
                                        <p class="text-[10px] text-red-600 mt-2">Block customer and owner access until maintenance is complete.</p>
                                    </div>
                                    <input type="checkbox" name="maintenance_mode" class="w-5 h-5 accent-red-600" <?= !empty($settingsProfile['maintenance_mode']) ? 'checked' : ''; ?>>
                                </label>
                            </div>

                            <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-gray-100">
                                <h4 class="text-xs font-black uppercase text-gray-400 tracking-widest mb-6">Cloud Infrastructure</h4>
                                <div class="space-y-4">
                                    <div class="flex justify-between items-center text-[10px] font-bold">
                                        <span class="uppercase">Database Status</span>
                                        <span class="text-green-500 font-black">HEALTHY</span>
                                    </div>
                                    <div class="flex justify-between items-center text-[10px] font-bold">
                                        <span class="uppercase">Storage (S3)</span>
                                        <span class="text-brand">84% FULL</span>
                                    </div>
                                    <div class="flex justify-between items-center text-[10px] font-bold">
                                        <span class="uppercase">Last Backup</span>
                                        <span class="text-gray-400">TODAY, 04:00 AM</span>
                                    </div>
                                </div>
                                <button type="button" class="w-full mt-8 py-3 border-2 border-brand text-brand rounded-xl font-black text-[10px] uppercase tracking-widest hover:bg-brand hover:text-white transition">Trigger Manual Backup</button>
                            </div>

                            <div class="pt-10">
                                <button type="submit" class="w-full py-5 bg-brand text-white rounded-[2rem] font-black text-xs uppercase tracking-widest shadow-2xl hover:bg-gray-800 transition">Save Global Changes</button>
                                <p class="text-[9px] text-gray-400 font-bold uppercase text-center mt-4">Audit ID: #SYS-CONF-2026-04-27</p>
                            </div>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>
    <script src="js/admin-app.js" defer></script>
</body>
</html>
