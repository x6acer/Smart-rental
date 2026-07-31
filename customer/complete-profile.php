<?php
session_start();
require_once __DIR__ . '/../includes/maintenance-check.php';
enforceMaintenanceMode($pdo);
require_once '../db.php';
require_once __DIR__ . '/../includes/security.php';

define('DIDIT_API_KEY', 't-r1CCKd8jTQHZmWaffZMroszv8d5xssV4PD8z4kbPc');
define('DIDIT_WORKFLOW_ID', '1dfcf358-5e3a-4939-b349-79fa1c1368eb');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];
$customerName = $_SESSION['user_name'] ?? '';
$profileError = $_SESSION['auth_error'] ?? '';
$profileSuccess = $_SESSION['auth_success'] ?? '';
unset($_SESSION['auth_error'], $_SESSION['auth_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['csrf_token'] ?? null, 'customer-profile')) {
    $_SESSION['auth_error'] = 'Security check failed. Please try again.';
    header('Location: complete-profile.php');
    exit();
}

$profileState = 'unverified';
$verificationStatus = 'Unverified';
$documentType = '';
$licenseNumber = '';
$phoneNumber = '';
$accountStatus = 'Pending';
$profileSettings = [];

function startDiditSession(int $userId): array
{
    $diditPayload = [
        'workflow_id' => DIDIT_WORKFLOW_ID,
        'vendor_data' => (string) $userId,
        'callback' => 'https://easter-cackle-oozy.ngrok-free.dev/SR-cars-new/customer/dashboard.php',
    ];

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL is not available on this server.'];
    }

    $diditCh = curl_init('https://verification.didit.me/v3/session/');
    curl_setopt($diditCh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($diditCh, CURLOPT_POST, true);
    curl_setopt($diditCh, CURLOPT_POSTFIELDS, json_encode($diditPayload));
    curl_setopt($diditCh, CURLOPT_HTTPHEADER, [
        'x-api-key: ' . DIDIT_API_KEY,
        'Content-Type: application/json',
    ]);
    curl_setopt($diditCh, CURLOPT_TIMEOUT, 20);
    curl_setopt($diditCh, CURLOPT_CONNECTTIMEOUT, 10);

    $diditResponse = curl_exec($diditCh);
    $diditHttpCode = curl_getinfo($diditCh, CURLINFO_HTTP_CODE);
    $diditCurlError = curl_error($diditCh);
    curl_close($diditCh);

    if ($diditResponse !== false && $diditHttpCode >= 200 && $diditHttpCode < 300) {
        $diditData = json_decode($diditResponse, true);
        $targetUrl = is_array($diditData) ? ($diditData['url'] ?? $diditData['session_url'] ?? '') : '';

        if (is_string($targetUrl) && $targetUrl !== '') {
            return ['success' => true, 'redirect_url' => $targetUrl];
        }

        return ['success' => false, 'error' => 'Didit verification session was created but no redirect URL was returned.'];
    }

    $message = $diditCurlError !== ''
        ? 'Unable to start verification right now: ' . $diditCurlError
        : 'Unable to start verification right now. Please try again.';

    return ['success' => false, 'error' => $message];
}

try {
    $profileStmt = $pdo->prepare('SELECT full_name, business_settings FROM User_Profiles WHERE user_id = :user_id LIMIT 1');
    $profileStmt->execute(['user_id' => $userId]);
    $existingProfile = $profileStmt->fetch();

    if ($existingProfile) {
        $customerName = $existingProfile['full_name'] ?: $customerName;
        $profileSettings = json_decode($existingProfile['business_settings'] ?? '{}', true) ?: [];
    }

    $userStmt = $pdo->prepare('SELECT phone_number, account_status FROM Users WHERE user_id = :user_id LIMIT 1');
    $userStmt->execute(['user_id' => $userId]);
    $userRecord = $userStmt->fetch();
    if ($userRecord) {
        $phoneNumber = $userRecord['phone_number'] ?? '';
        $accountStatus = $userRecord['account_status'] ?? $accountStatus;
    }

    $verificationStmt = $pdo->prepare('SELECT id_type, verification_status FROM Identity_Verifications WHERE user_id = :user_id LIMIT 1');
    $verificationStmt->execute(['user_id' => $userId]);
    $existingVerification = $verificationStmt->fetch();

    if ($existingVerification) {
        $verificationStatus = $existingVerification['verification_status'] ?? $verificationStatus;
        $documentType = $existingVerification['id_type'] ?? $documentType;
    }

    $documentType = $documentType ?: ($profileSettings['kyc_document_type'] ?? $profileSettings['document_type'] ?? '');
    $licenseNumber = $profileSettings['license_number'] ?? $profileSettings['drivers_license'] ?? '';

    if (strtolower((string) $verificationStatus) === 'verified') {
        $isDriverLicense = preg_match('/driver|licence|license/i', (string) $documentType) === 1;
        $profileState = $isDriverLicense ? 'fully_verified' : 'verified';
    }
} catch (Throwable $ignored) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_didit_session'])) {
    $diditResult = startDiditSession($userId);

    if ($diditResult['success'] ?? false) {
        header('Location: ' . $diditResult['redirect_url']);
        exit();
    }

    $profileError = $diditResult['error'] ?? 'Unable to start verification right now. Please try again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $phoneNumber = trim((string) ($_POST['phone_number'] ?? ''));
    $licenseNumber = trim((string) ($_POST['license_number'] ?? ''));

    if ($fullName === '' || $phoneNumber === '') {
        $_SESSION['auth_error'] = 'Please provide your full name and phone number.';
        header('Location: complete-profile.php');
        exit();
    }

    try {
        $pdo->beginTransaction();
        $profileStmt = $pdo->prepare('INSERT INTO User_Profiles (user_id, full_name, business_settings, updated_at) VALUES (:user_id, :full_name, :business_settings, NOW()) ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), business_settings = VALUES(business_settings), updated_at = NOW()');
        $profileStmt->execute([
            'user_id' => $userId,
            'full_name' => $fullName,
            'business_settings' => json_encode(['license_number' => $licenseNumber], JSON_UNESCAPED_SLASHES),
        ]);

        $userStmt = $pdo->prepare('UPDATE Users SET phone_number = :phone_number WHERE user_id = :user_id');
        $userStmt->execute(['phone_number' => $phoneNumber, 'user_id' => $userId]);

        $pdo->commit();
        $_SESSION['auth_success'] = 'Profile updated successfully.';
        header('Location: complete-profile.php');
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['auth_error'] = 'Unable to save profile right now. Please try again.';
        header('Location: complete-profile.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Identity Profile | Smart Rental</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-[#1b4b4b] antialiased">
    
    <?php require_once 'includes/header.php'; ?>

    <main class="max-w-xl mx-auto px-4 py-16">
        
        <!-- System Status Feedback Notifications -->
        <?php if ($profileError): ?>
            <div class="mb-5 rounded-2xl bg-red-50 border border-red-100 p-4 text-sm text-red-700 flex gap-3 items-center">
                <span class="text-lg">⚠️</span>
                <span><?php echo htmlspecialchars($profileError); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($profileSuccess): ?>
            <div class="mb-5 rounded-2xl bg-emerald-50 border border-emerald-100 p-4 text-sm text-emerald-700 flex gap-3 items-center">
                <span class="text-lg">✓</span>
                <span><?php echo htmlspecialchars($profileSuccess); ?></span>
            </div>
        <?php endif; ?>

        <!-- Main Adaptive Interface Panel -->
        <div class="bg-white rounded-[2rem] border border-gray-100 p-10 transition-all duration-300">
            
            <?php switch ($profileState): case 'fully_verified': ?>
                <!-- DESIGN STATE 1: COMPLETE FULL DEEP VERIFIED ACCESS -->
                <div class="text-center">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-50 text-emerald-600 mb-6">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900">Verification Complete</h1>
                    <p class="text-sm text-gray-500 mt-2">Your documentation is scanned, approved, and fully synced.</p>
                    
                    <div class="mt-8 rounded-2xl bg-gray-50/70 border border-gray-100 p-5 text-left space-y-3.5">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-400 font-medium">Verified Renter</span>
                            <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($customerName); ?></span>
                        </div>
                        <div class="w-full border-t border-dashed border-gray-200"></div>
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-400 font-medium">License Identity</span>
                            <span class="font-mono font-bold bg-white border border-gray-100 px-2.5 py-1 rounded-md text-xs text-gray-700"><?php echo htmlspecialchars($licenseNumber ?: 'AUTOMATED SCAN'); ?></span>
                        </div>
                        <div class="w-full border-t border-dashed border-gray-200"></div>
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-400 font-medium">Rental Privileges</span>
                            <span class="text-emerald-600 font-semibold bg-emerald-50 px-3 py-1 rounded-full text-xs">Self-Drive & Chauffeur</span>
                        </div>
                    </div>

                    <a href="dashboard.php" class="mt-8 block w-full text-center bg-[#1b4b4b] hover:bg-[#143939] text-white py-3.5 rounded-xl text-sm font-semibold tracking-wide transition duration-150 shadow-sm">
                        Explore Rental Fleet
                    </a>
                </div>

            <?php break; case 'verified': ?>
                <!-- DESIGN STATE 2: PARTIAL ACCESS WITH PASSPORT / NATIONAL ID -->
                <div>
                    <div class="text-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-blue-50 text-blue-600 mb-6">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 00-2 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 014 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
                            </svg>
                        </div>
                        <h1 class="text-2xl font-bold tracking-tight text-gray-900">Identity Confirmed</h1>
                        <p class="text-sm text-gray-500 mt-2">Your base user profile has been successfully activated.</p>
                    </div>

                    <div class="mt-6 bg-gradient-to-r from-blue-50/60 to-indigo-50/40 border border-blue-100 rounded-2xl p-5 text-sm leading-relaxed text-blue-900">
                        <p class="font-bold text-blue-950 mb-1">🚗 Chauffeur Access Unlocked</p> 
                        Your current identification limits you to chauffeured bookings. To take the wheel yourself and unlock our **Self-Drive** tiers, please add a valid driving license document.
                    </div>

                    <form method="POST" class="mt-8">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken('customer-profile'), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="start_didit_session" value="1">
                        <button type="submit" class="w-full bg-[#1b4b4b] hover:bg-[#143939] text-white py-3.5 rounded-xl text-sm font-semibold tracking-wide transition duration-150 shadow-sm">
                            Scan Driving License
                        </button>
                    </form>
                    
                    <a href="dashboard.php" class="block text-center text-sm font-semibold text-gray-400 hover:text-gray-600 mt-5 transition duration-150">
                        Continue with Chauffeur Bookings &rarr;
                    </a>
                </div>

            <?php break; default: ?>
                <!-- DESIGN STATE 3: UNVERIFIED CORE LAUNCHPAD -->
                <div>
                    <div class="mb-6 flex h-14 w-14 m-auto items-center justify-center rounded-2xl bg-amber-50 text-[#1b4b4b] border border-amber-100">
                        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 2L5 5V11C5 15.7 7.8 20.1 12 22C16.2 20.1 19 15.7 19 11V5L12 2Z" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M12 8v4m0 4h.01" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </div>

                    <h1 class="text-2xl font-bold tracking-tight text-center text-gray-900">Verify Identity</h1>
                    <p class="text-sm text-gray-500 mt-2">Access your vehicle rental capabilities with a secure, instant document scan.</p>

                    <div class="mt-8 space-y-4 border-t border-gray-100 pt-6">
                        <div class="flex gap-4 items-start">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-50 border border-gray-100 text-xs font-bold text-gray-500">1</span>
                            <p class="text-sm text-gray-600 leading-relaxed">No complex form inputs required. Our AI integration reads the credentials cleanly from your document capture.</p>
                        </div>
                        <div class="flex gap-4 items-start">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-50 border border-gray-100 text-xs font-bold text-gray-500">2</span>
                            <p class="text-sm text-gray-600 leading-relaxed">Using a valid **Driving License** instantly unlocks our standalone **Self-Drive** options. Passports clear you for premium chauffeured options.</p>
                        </div>
                    </div>

                    <form method="POST" class="mt-8 space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken('customer-profile'), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="save_profile" value="1">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2" for="full_name">Full name</label>
                            <input id="full_name" name="full_name" type="text" value="<?php echo htmlspecialchars($customerName); ?>" class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm focus:border-[#1b4b4b] focus:outline-none" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2" for="phone_number">Phone number</label>
                            <input id="phone_number" name="phone_number" type="tel" value="<?php echo htmlspecialchars($phoneNumber); ?>" class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm focus:border-[#1b4b4b] focus:outline-none" required>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2" for="license_number">Driving license number (optional)</label>
                            <input id="license_number" name="license_number" type="text" value="<?php echo htmlspecialchars($licenseNumber); ?>" class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm focus:border-[#1b4b4b] focus:outline-none">
                        </div>
                        <button type="submit" class="w-full bg-[#1b4b4b] hover:bg-[#143939] text-white py-3.5 rounded-xl text-sm font-semibold tracking-wide transition duration-150 shadow-sm">
                            Save Profile
                        </button>
                    </form>

                    <form method="POST" class="mt-6">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken('customer-profile'), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="start_didit_session" value="1">
                        <button type="submit" class="w-full bg-white border border-gray-200 hover:border-[#1b4b4b] text-[#1b4b4b] py-3.5 rounded-xl text-sm font-semibold tracking-wide transition duration-150 shadow-sm">
                            Start Identity Scan
                        </button>
                    </form>

                    <!-- NEW: FLEXIBLE SKIP ALTERNATIVE HOOK -->
                    <div class="mt-5 text-center">
                        <a href="dashboard.php" class="inline-flex items-center text-xs font-bold uppercase tracking-wider text-gray-400 hover:text-[#1b4b4b] transition duration-150 py-1">
                            Skip for now
                        </a>
                    </div>
                </div>
            <?php endswitch; ?>

        </div>
    </main>

</body>
</html>