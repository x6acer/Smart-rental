<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/maintenance-check.php';
require_once __DIR__ . '/../../includes/security.php';
enforceMaintenanceMode($pdo);

$customerName = $_SESSION['user_name'] ?? '';
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

$profilePhotoUrl = '';
$verifiedBadgeVisible = false;
$accountStatus = 'Pending';

if ($userId) {
    if (!isset($pdo)) {
        require_once __DIR__ . '/../../db.php';
    }

    $profileStmt = $pdo->prepare('SELECT full_name, profile_photo_url FROM User_Profiles WHERE user_id = :user_id LIMIT 1');
    $profileStmt->execute(['user_id' => $userId]);
    $profile = $profileStmt->fetch();
    if ($profile && !empty($profile['full_name'])) {
        $customerName = $profile['full_name'];
    }
    if ($profile && !empty($profile['profile_photo_url'])) {
        $profilePhotoUrl = $profile['profile_photo_url'];
    }

    $verificationStmt = $pdo->prepare('SELECT account_status, email_verified_at FROM Users WHERE user_id = :user_id LIMIT 1');
    $verificationStmt->execute(['user_id' => $userId]);
    $verification = $verificationStmt->fetch();
    $accountStatus = $verification['account_status'] ?? 'Pending';
    $emailVerifiedAt = $verification['email_verified_at'] ?? null;
    $verifiedBadgeVisible = !empty($emailVerifiedAt) || $accountStatus === 'Active';

    $identityVerified = isCustomerIdentityVerified($pdo, $userId);
}

$customerName = $customerName ?: 'Customer';
$customerInitials = strtoupper(substr(trim($customerName), 0, 2)) ?: 'CU';
$currentPage = basename($_SERVER['PHP_SELF']);
$csrfTokenValue = csrfToken('customer-header');

if ($userId && !in_array($currentPage, ['complete-profile.php', 'verify-email.php', 'login.php', 'register.php', 'password-reset.php'], true)) {
    enforceCustomerProfileCompletion($pdo, $userId, 'complete-profile.php');
}

function headerLinkClass(string $page, string $currentPage): string
{
    return $page === $currentPage ? 'text-[#1b4b4b] border-b-2 border-[#facd05] pb-1' : 'hover:text-yellow-600 transition';
}
?>
<style>
    :root {
        --brand-primary: #1b4b4b;
        --brand-accent: #facd05;
    }

    .logo h1 {
        font-size: 24px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: -1px;
        color: var(--brand-primary);
        margin: 0;
    }

    .logo span {
        color: var(--brand-accent);
    }

    /* Reusable empty-state card used across customer pages */
    .empty-state {
        border: 1px dashed rgba(148,163,184,0.24);
        background: rgba(249,250,251,0.6);
        padding: 2rem;
        border-radius: 1.5rem;
        text-align: center;
        color: #6b7280;
    }
    .empty-state .es-icon { font-size: 2.25rem; opacity: 0.85; margin-bottom: 0.5rem; }
    .empty-state .es-cta { margin-top: 1rem; }
</style>

<?php if ($userId && $verifiedBadgeVisible): ?>
<div class="border-b border-emerald-100 bg-emerald-50/80">
    <div class="max-w-7xl mx-auto flex items-center justify-center px-6 py-2 text-sm font-semibold text-emerald-700">
        <svg class="mr-2 h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M10 1.5a.75.75 0 0 1 .67.41l1.62 3.29 3.63.53a.75.75 0 0 1 .42 1.28l-2.63 2.57.62 3.62a.75.75 0 0 1-1.09.79L10 11.9l-3.25 1.71a.75.75 0 0 1-1.09-.79l.62-3.62L3.66 7.01a.75.75 0 0 1 .42-1.28l3.63-.53 1.62-3.29A.75.75 0 0 1 10 1.5Z" clip-rule="evenodd" />
        </svg>
        <span>Verified profile • Secure bookings and faster support</span>
    </div>
</div>
<?php elseif ($userId): ?>
<div class="border-b border-amber-100 bg-amber-50/80">
    <div class="max-w-7xl mx-auto flex items-center justify-center px-6 py-2 text-sm font-semibold text-amber-900">
        <svg class="mr-2 h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M8.257 3.099c.366-.446.957-.546 1.403-.18l.094.083 6 5.5a1 1 0 01.224 1.325l-.083.094-6 7a1 1 0 01-1.51.082l-.094-.083-4-4.5a1 1 0 01.083-1.41l.083-.094 4-3.5z" clip-rule="evenodd" />
        </svg>
        <span>Your identity verification is still pending. Complete verification to enable secure payment and booking.</span>
        <a href="complete-profile.php" class="ml-2 underline font-semibold text-[#1b4b4b]">Verify now</a>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!document.head.querySelector('meta[name="csrf-token"]')) {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = <?php echo json_encode($csrfTokenValue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        document.head.appendChild(meta);
    }
});
</script>

<header class="bg-white shadow-sm sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
        <div class="logo">
            <h1>Smart<span>Rental</span></h1>
        </div>
        <nav class="hidden md:flex items-center gap-8 font-bold text-sm">
            <a href="browse.php" class="<?php echo headerLinkClass('browse.php', $currentPage); ?>">Browse</a>
            <?php if ($userId): ?>
                <a href="dashboard.php" class="<?php echo headerLinkClass('dashboard.php', $currentPage); ?>">Dashboard</a>
                <a href="rentals.php" class="<?php echo headerLinkClass('rentals.php', $currentPage); ?>">My Rentals</a>
                <a href="notifications.php" class="<?php echo headerLinkClass('notifications.php', $currentPage); ?>">Messages</a>
            <?php endif; ?>
            <a href="Support&FAQ.php" class="<?php echo headerLinkClass('Support&FAQ.php', $currentPage); ?>">Support</a>
        </nav>
        <?php if ($userId): ?>
            <div class="flex items-center gap-4 relative">
                <a href="cart.php" class="relative p-2.5 text-gray-400 hover:text-[#1b4b4b] transition duration-200" title="View Cart">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
                </a>
                <button data-dropdown-toggle="profileMenuDropdown" type="button" aria-expanded="false" class="group flex items-center gap-3 rounded-full border-2 border-[#1b4b4b]/20 bg-white px-2 py-1.5 pr-4 shadow-sm transition hover:border-[#facd05] hover:shadow-md">
                    <?php if (!empty($profilePhotoUrl)): ?>
                        <img src="<?php echo htmlspecialchars($profilePhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>" class="w-9 h-9 rounded-full border-2 border-white object-cover shadow-sm">
                    <?php else: ?>
                        <span class="w-9 h-9 rounded-full bg-[#1b4b4b] border-2 border-white flex items-center justify-center text-[11px] font-black text-white shadow-sm"><?php echo htmlspecialchars($customerInitials); ?></span>
                    <?php endif; ?>
                    <span class="hidden sm:block text-left">
                        <span class="block text-[14px] font-medium leading-none text-[#1b4b4b] group-hover:text-[#facd05]"><?php echo htmlspecialchars($customerName); ?></span>
                    </span>
                    <svg class="h-4 w-4 text-[#1b4b4b] transition group-hover:text-[#facd05]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 10.94l3.71-3.71a.75.75 0 1 1 1.06 1.06l-4.24 4.25a.75.75 0 0 1-1.06 0L5.21 8.29a.75.75 0 0 1 .02-1.08Z" clip-rule="evenodd" />
                    </svg>
                </button>

                <div id="profileMenuDropdown" class="hidden absolute right-0 top-[calc(100%+12px)] z-50 w-[320px] overflow-hidden rounded-3xl border border-[#e5e7eb] bg-white shadow-[0_24px_60px_rgba(27,75,75,0.12)]" data-dropdown-menu>
                    <div class="p-5 pb-4">
                        <div class="flex items-center gap-4">
                            <div class="h-12 w-12 shrink-0 overflow-hidden rounded-full bg-[#1b4b4b] flex items-center justify-center border-2 border-white shadow-sm text-sm font-black text-white">
                                <?php if (!empty($profilePhotoUrl)): ?>
                                    <img src="<?php echo htmlspecialchars($profilePhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?>" class="h-full w-full object-cover">
                                <?php else: ?>
                                    <?php echo htmlspecialchars($customerInitials); ?>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-lg font-extrabold leading-tight text-[#1b4b4b]"><?php echo htmlspecialchars($customerName); ?></p>
                                <a href="complete-profile.php" class="text-sm font-medium text-[#1b4b4b] underline decoration-[#facd05] decoration-2 underline-offset-4 hover:text-[#143a3a]">Edit your account</a>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-[#e5e7eb]">
                        <?php if (!$identityVerified): ?>
                        <a href="complete-profile.php" class="flex items-center justify-between px-5 py-4 text-[15px] text-[#4b5563] transition hover:bg-[#f8faf5] hover:text-[#1b4b4b]">
                            <span>Verify Your Profile</span>
                        </a>
                        <?php endif; ?>
                        <a href="rentals.php" class="flex items-center justify-between px-5 py-4 text-[15px] text-[#4b5563] transition hover:bg-[#f8faf5] hover:text-[#1b4b4b]">
                            <span>Rentals</span>
                        </a>
                        <a href="notifications.php" class="flex items-center justify-between px-5 py-4 text-[15px] text-[#4b5563] transition hover:bg-[#f8faf5] hover:text-[#1b4b4b]">
                            <span>Messages</span>
                        </a>
                        <a href="payment.php" class="flex items-center justify-between px-5 py-4 text-[15px] text-[#4b5563] transition hover:bg-[#f8faf5] hover:text-[#1b4b4b]">
                            <span>Payments</span>
                        </a>
                    </div>

                    <div class="bg-[#f5f7fa] px-5 py-4">
                        <a href="includes/logout.php" class="flex items-center justify-between rounded-2xl px-4 py-3 text-[15px] font-medium text-[#1b4b4b] transition hover:bg-white">
                            <span>Log out</span>
                            <svg class="h-5 w-5 text-[#4b5563]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M3 4.75A1.75 1.75 0 0 1 4.75 3h4a.75.75 0 0 1 0 1.5h-4a.25.25 0 0 0-.25.25v10.5c0 .138.112.25.25.25h4a.75.75 0 0 1 0 1.5h-4A1.75 1.75 0 0 1 3 15.25V4.75Zm10.22 1.72a.75.75 0 0 1 1.06 0l3 3a.75.75 0 0 1 0 1.06l-3 3a.75.75 0 1 1-1.06-1.06l1.72-1.72H7.75a.75.75 0 0 1 0-1.5h6.19l-1.72-1.72a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                            </svg>
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="flex items-center gap-3">
                <a href="login.php?redirect=<?php echo urlencode($currentPage); ?>" class="px-4 py-2 text-[#1b4b4b] border-2 border-[#1b4b4b] rounded-lg hover:bg-[#1b4b4b] hover:text-white transition">Log in</a>
                <a href="register.php" class="px-4 py-2 bg-[#1b4b4b] text-white rounded-lg hover:bg-gray-800 transition">Sign Up</a>
            </div>
        <?php endif; ?>
    </div>
</header>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.querySelector('[data-dropdown-toggle="profileMenuDropdown"]');
    const menu = document.getElementById('profileMenuDropdown');

    if (!toggle || !menu) {
        return;
    }

    const closeMenu = function () {
        menu.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
    };

    toggle.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();

        const shouldOpen = menu.classList.contains('hidden');
        if (shouldOpen) {
            menu.classList.remove('hidden');
            toggle.setAttribute('aria-expanded', 'true');
        } else {
            closeMenu();
        }
    });

    menu.addEventListener('click', function (event) {
        if (event.target.closest('a')) {
            closeMenu();
        }
    });

    document.addEventListener('click', function (event) {
        if (!menu.contains(event.target) && !toggle.contains(event.target)) {
            closeMenu();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeMenu();
        }
    });
});
</script>
