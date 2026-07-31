<?php
ob_start();

require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/security.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle view user details AJAX request
if (isset($_GET['view_user'])) {
    $viewUserId = filter_input(INPUT_GET, 'view_user', FILTER_VALIDATE_INT);
    if (!$viewUserId) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
        exit;
    }

    try {
        $userDetailStmt = $pdo->prepare(
            'SELECT u.user_id, u.email, u.user_role, u.account_status, u.registration_date, u.last_login_at,
                    p.full_name, p.phone_number, p.business_settings, p.profile_photo_url,
                    COALESCE(iv.verification_status, "Pending") AS verification_status, iv.id_type, iv.id_document_url, iv.biometric_match_status, iv.age_validated
             FROM Users u
             LEFT JOIN User_Profiles p ON p.user_id = u.user_id
             LEFT JOIN Identity_Verifications iv ON iv.user_id = u.user_id
             WHERE u.user_id = :user_id'
        );
        $userDetailStmt->execute(['user_id' => $viewUserId]);
        $userDetail = $userDetailStmt->fetch(PDO::FETCH_ASSOC);

        if (!$userDetail) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }

        $businessSettings = [];
        if (!empty($userDetail['business_settings'])) {
            $businessSettings = json_decode($userDetail['business_settings'], true) ?: [];
        }

        ob_start();
        ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <p class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4">Account Information</p>
                <div class="space-y-3">
                    <div><p class="text-xs text-slate-500 font-semibold uppercase">User ID</p><p class="text-sm font-semibold text-slate-800 mt-1">#<?= (int) $userDetail['user_id']; ?></p></div>
                    <div><p class="text-xs text-slate-500 font-semibold uppercase">Full Name</p><p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) ($userDetail['full_name'] ?: 'Not provided'), ENT_QUOTES, 'UTF-8'); ?></p></div>
                    <div><p class="text-xs text-slate-500 font-semibold uppercase">Email</p><p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) $userDetail['email'], ENT_QUOTES, 'UTF-8'); ?></p></div>
                    <div><p class="text-xs text-slate-500 font-semibold uppercase">Phone</p><p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) ($userDetail['phone_number'] ?: 'Not provided'), ENT_QUOTES, 'UTF-8'); ?></p></div>
                    <div><p class="text-xs text-slate-500 font-semibold uppercase">Role</p><p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) $userDetail['user_role'], ENT_QUOTES, 'UTF-8'); ?></p></div>
                    <div><p class="text-xs text-slate-500 font-semibold uppercase">Account Status</p><p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) $userDetail['account_status'], ENT_QUOTES, 'UTF-8'); ?></p></div>
                    <div><p class="text-xs text-slate-500 font-semibold uppercase">Registration Date</p><p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) $userDetail['registration_date'], ENT_QUOTES, 'UTF-8'); ?></p></div>
                    <div><p class="text-xs text-slate-500 font-semibold uppercase">Last Login</p><p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) ($userDetail['last_login_at'] ?: 'Never'), ENT_QUOTES, 'UTF-8'); ?></p></div>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <p class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4">Verification Details</p>
                <div class="space-y-3">
                    <div><p class="text-xs text-slate-500 font-semibold uppercase">Verification Status</p><p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) $userDetail['verification_status'], ENT_QUOTES, 'UTF-8'); ?></p></div>
                    <div><p class="text-xs text-slate-500 font-semibold uppercase">ID Type</p><p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) ($userDetail['id_type'] ?: 'Not provided'), ENT_QUOTES, 'UTF-8'); ?></p></div>
                    <div><p class="text-xs text-slate-500 font-semibold uppercase">Biometric Match</p><p class="text-sm font-semibold text-slate-800 mt-1"><?= $userDetail['biometric_match_status'] ? '✓ Matched' : '— Not matched'; ?></p></div>
                    <div><p class="text-xs text-slate-500 font-semibold uppercase">Age Validated</p><p class="text-sm font-semibold text-slate-800 mt-1"><?= $userDetail['age_validated'] ? '✓ Confirmed' : '— Not confirmed'; ?></p></div>
                </div>
            </div>

            <?php if (!empty($businessSettings)): ?>
                <div class="rounded-2xl border border-slate-200 bg-white p-6">
                    <p class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4">Business Settings</p>
                    <pre class="text-xs text-slate-600 bg-slate-50 p-4 rounded-xl overflow-auto"><?= htmlspecialchars(json_encode($businessSettings, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8'); ?></pre>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $html = ob_get_clean();

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'html' => $html]);
        exit;

    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database error']);
        exit;
    }
}

$adminNotice = $_SESSION['admin_notice'] ?? '';
$adminNoticeType = $_SESSION['admin_notice_type'] ?? 'info';
unset($_SESSION['admin_notice'], $_SESSION['admin_notice_type']);

$ownerReviewQueue = [];
$selectedOwnerForReview = null;
$selectedOwnerId = filter_input(INPUT_GET, 'review_owner', FILTER_VALIDATE_INT);

$search = trim((string) ($_GET['q'] ?? ''));
$roleFilter = isset($_GET['role']) ? strtolower(trim((string) $_GET['role'])) : 'all';
$statusFilter = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : 'all';
$roleFilter = in_array($roleFilter, ['customer', 'owner', 'all'], true) ? $roleFilter : 'all';
$statusFilter = in_array($statusFilter, ['all', 'pending', 'verified', 'suspended'], true) ? $statusFilter : 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-users')) {
        if ($isAjax) {
            header('Content-Type: application/json');
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Security check failed. Please try again.']);
            exit;
        }
        $_SESSION['admin_notice'] = 'Security check failed. Please try again.';
        $_SESSION['admin_notice_type'] = 'error';
        header('Location: users.php');
        exit;
    }

    $action = strtolower(trim((string) ($_POST['admin_action'] ?? '')));
    $targetUserId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

    if ($action === 'resync_verification') {
        try {
            $pdo->beginTransaction();
            $verificationRows = $pdo->query(
                'SELECT u.user_id, u.account_status, p.business_settings, iv.verification_id, iv.verification_status
                 FROM Users u
                 LEFT JOIN User_Profiles p ON p.user_id = u.user_id
                 LEFT JOIN Identity_Verifications iv ON iv.user_id = u.user_id'
            )->fetchAll(PDO::FETCH_ASSOC);

            $insertStmt = $pdo->prepare('INSERT INTO Identity_Verifications (user_id, verification_status) VALUES (:user_id, :status)');
            $updateStmt = $pdo->prepare('UPDATE Identity_Verifications SET verification_status = :status WHERE user_id = :user_id');
            $userUpdateStmt = $pdo->prepare('UPDATE Users SET account_status = :status WHERE user_id = :user_id');

            $updated = 0;
            $inserted = 0;
            foreach ($verificationRows as $row) {
                $businessSettings = [];
                if (!empty($row['business_settings'])) {
                    $businessSettings = json_decode($row['business_settings'], true) ?: [];
                }

                $diditStatus = strtolower(trim((string) ($businessSettings['didit_verification_status'] ?? '')));
                $diditVerifiedAt = trim((string) ($businessSettings['didit_verified_at'] ?? ''));

                if (empty($row['verification_id']) && empty($row['business_settings'])) {
                    continue;
                }

                if (in_array($diditStatus, ['approved', 'verified', 'approve'], true) || $diditVerifiedAt !== '') {
                    $targetVerificationStatus = 'Verified';
                } elseif (in_array($diditStatus, ['rejected', 'suspended'], true)) {
                    $targetVerificationStatus = 'Rejected';
                } else {
                    $targetVerificationStatus = 'Pending';
                }

                if ($row['verification_id']) {
                    if ($row['verification_status'] !== $targetVerificationStatus) {
                        $updateStmt->execute(['status' => $targetVerificationStatus, 'user_id' => $row['user_id']]);
                        $updated++;
                    }
                } else {
                    $insertStmt->execute(['user_id' => $row['user_id'], 'status' => $targetVerificationStatus]);
                    $inserted++;
                }

                if ($targetVerificationStatus === 'Verified' && $row['account_status'] !== 'Active') {
                    $userUpdateStmt->execute(['status' => 'Active', 'user_id' => $row['user_id']]);
                } elseif ($targetVerificationStatus === 'Rejected' && $row['account_status'] !== 'Suspended') {
                    $userUpdateStmt->execute(['status' => 'Suspended', 'user_id' => $row['user_id']]);
                }
            }

            $pdo->commit();
            $adminNotice = sprintf('Verification resync completed: %d row(s) updated, %d row(s) inserted.', $updated, $inserted);
            $adminNoticeType = 'success';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $adminNotice = 'Unable to resync verification data at this time.';
            $adminNoticeType = 'error';
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            ob_end_clean();
            echo json_encode(['success' => $adminNoticeType === 'success', 'message' => $adminNotice]);
            exit;
        }

        $_SESSION['admin_notice'] = $adminNotice;
        $_SESSION['admin_notice_type'] = $adminNoticeType;
        header('Location: users.php');
        exit;
    } elseif ($action === 'add_user') {
        try {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $role = in_array(strtolower(trim((string) ($_POST['role'] ?? ''))), ['customer', 'owner'], true) ? ucfirst(strtolower(trim((string) ($_POST['role'] ?? '')))) : 'Customer';
            $password = (string) ($_POST['password'] ?? '');

            if ($fullName === '' || $email === '' || $password === '') {
                throw new InvalidArgumentException('Full name, email, and password are required.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Please enter a valid email address.');
            }

            if (strlen($password) < 6) {
                throw new InvalidArgumentException('Password must be at least 6 characters long.');
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $checkStmt = $pdo->prepare('SELECT user_id FROM Users WHERE email = :email LIMIT 1');
            $checkStmt->execute(['email' => $email]);
            if ($checkStmt->fetch()) {
                throw new InvalidArgumentException('A user with this email already exists.');
            }

            $pdo->beginTransaction();

            $insertUser = $pdo->prepare('INSERT INTO Users (email, password_hash, user_role, account_status) VALUES (:email, :password, :role, :status)');
            $insertUser->execute([
                'email' => $email,
                'password' => $hashedPassword,
                'role' => $role,
                'status' => 'Pending',
            ]);
            $newUserId = (int) $pdo->lastInsertId();

            $insertProfile = $pdo->prepare('INSERT INTO User_Profiles (user_id, full_name, phone_number) VALUES (:user_id, :full_name, :phone)');
            $insertProfile->execute([
                'user_id' => $newUserId,
                'full_name' => $fullName,
                'phone' => $phone,
            ]);

            $insertVerification = $pdo->prepare('INSERT INTO Identity_Verifications (user_id, verification_status) VALUES (:user_id, :status)');
            $insertVerification->execute([
                'user_id' => $newUserId,
                'status' => 'Pending',
            ]);

            $pdo->commit();

            $adminNotice = 'User created successfully. User ID: ' . $newUserId;
            $adminNoticeType = 'success';
            logAdminAction((int) ($_SESSION['admin_id'] ?? 0), 'user_created', 'Users', $newUserId, 'Admin created new ' . $role . ' user: ' . $email);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $adminNotice = $e->getMessage();
            $adminNoticeType = 'error';
        }

        $_SESSION['admin_notice'] = $adminNotice;
        $_SESSION['admin_notice_type'] = $adminNoticeType;
        header('Location: users.php');
        exit;
    } elseif ($targetUserId && in_array($action, ['approve', 'verify', 'suspend', 'reject_owner'], true)) {
        try {
            $pdo->beginTransaction();

            if ($action === 'approve' || $action === 'verify') {
                $pdo->prepare('UPDATE users SET account_status = :status WHERE user_id = :user_id')->execute([
                    'status' => 'Active',
                    'user_id' => $targetUserId,
                ]);

                $verificationCheck = $pdo->prepare('SELECT verification_id FROM Identity_Verifications WHERE user_id = :user_id LIMIT 1');
                $verificationCheck->execute(['user_id' => $targetUserId]);
                $verificationRow = $verificationCheck->fetch(PDO::FETCH_ASSOC);

                if ($verificationRow) {
                    $verificationUpdate = $pdo->prepare('UPDATE Identity_Verifications SET verification_status = :status WHERE user_id = :user_id');
                    $verificationUpdate->execute([
                        'status' => 'Verified',
                        'user_id' => $targetUserId,
                    ]);
                } else {
                    $verificationInsert = $pdo->prepare('INSERT INTO Identity_Verifications (user_id, verification_status) VALUES (:user_id, :status)');
                    $verificationInsert->execute([
                        'user_id' => $targetUserId,
                        'status' => 'Verified',
                    ]);
                }

                $adminNotice = $action === 'approve' ? 'User approved successfully.' : 'User verification marked as verified.';
                $adminNoticeType = 'success';
                logAdminAction((int) ($_SESSION['admin_id'] ?? 0), $action === 'approve' ? 'user_approved' : 'user_verified', 'Users', $targetUserId, 'Admin updated account status for user ' . $targetUserId);
                createAdminNotification('User account updated', 'User account #' . $targetUserId . ' was approved or verified by an administrator.', 'User Management');
            } elseif ($action === 'reject_owner') {
                $pdo->prepare('UPDATE users SET account_status = :status WHERE user_id = :user_id')->execute([
                    'status' => 'Suspended',
                    'user_id' => $targetUserId,
                ]);

                $pdo->prepare('UPDATE Identity_Verifications SET verification_status = :status WHERE user_id = :user_id')->execute([
                    'status' => 'Rejected',
                    'user_id' => $targetUserId,
                ]);

                $adminNotice = 'Owner verification rejected.';
                $adminNoticeType = 'error';
                logAdminAction((int) ($_SESSION['admin_id'] ?? 0), 'owner_verification_rejected', 'Users', $targetUserId, 'Admin rejected owner verification for user ' . $targetUserId);
                createAdminNotification('Owner verification rejected', 'Owner account #' . $targetUserId . ' was rejected by an administrator.', 'User Management');
            } else {
                $pdo->prepare('UPDATE users SET account_status = :status WHERE user_id = :user_id')->execute([
                    'status' => 'Suspended',
                    'user_id' => $targetUserId,
                ]);

                $suspendVerification = $pdo->prepare('UPDATE Identity_Verifications SET verification_status = :status WHERE user_id = :user_id');
                $suspendVerification->execute([
                    'status' => 'Rejected',
                    'user_id' => $targetUserId,
                ]);

                $adminNotice = 'User suspended successfully.';
                $adminNoticeType = 'error';
                logAdminAction((int) ($_SESSION['admin_id'] ?? 0), 'user_suspended', 'Users', $targetUserId, 'Admin suspended user account ' . $targetUserId);
                createAdminNotification('User account suspended', 'User account #' . $targetUserId . ' was suspended by an administrator.', 'User Management');
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $adminNotice = 'Unable to process the selected action right now.';
            $adminNoticeType = 'error';
        }

        $redirectParams = [];
        if ($search !== '') {
            $redirectParams['q'] = $search;
        }
        if ($roleFilter !== 'all') {
            $redirectParams['role'] = $roleFilter;
        }
        if ($statusFilter !== 'all') {
            $redirectParams['status'] = $statusFilter;
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => $adminNotice]);
            exit;
        }

        $_SESSION['admin_notice'] = $adminNotice;
        $_SESSION['admin_notice_type'] = $adminNoticeType;

        $redirectUrl = 'users.php';
        if (!empty($redirectParams)) {
            $redirectUrl .= '?' . http_build_query($redirectParams);
        }

        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $_SESSION['admin_notice'] = 'Invalid request.';
    $_SESSION['admin_notice_type'] = 'error';
    header('Location: users.php');
    exit;
}

try {
    $whereClauses = ["u.user_role IN ('Customer', 'Owner')"];
    $params = [];

    if ($search !== '') {
        $whereClauses[] = '(u.email LIKE :searchEmail OR p.full_name LIKE :searchFullName OR CAST(u.user_id AS CHAR) LIKE :searchUserId)';
        $params['searchEmail'] = '%' . $search . '%';
        $params['searchFullName'] = '%' . $search . '%';
        $params['searchUserId'] = '%' . $search . '%';
    }

    if ($roleFilter !== 'all') {
        $whereClauses[] = 'u.user_role = :role';
        $params['role'] = ucfirst($roleFilter);
    }

    if ($statusFilter === 'pending') {
        $whereClauses[] = "(u.account_status = 'Pending' OR COALESCE(iv.verification_status, 'Pending') = 'Pending')";
    } elseif ($statusFilter === 'verified') {
        $whereClauses[] = "(u.account_status = 'Active' AND COALESCE(iv.verification_status, 'Pending') = 'Verified')";
    } elseif ($statusFilter === 'suspended') {
        $whereClauses[] = "u.account_status = 'Suspended'";
    }

    $whereSql = ' WHERE ' . implode(' AND ', $whereClauses);

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM Users u
         LEFT JOIN User_Profiles p ON p.user_id = u.user_id
         LEFT JOIN Identity_Verifications iv ON iv.user_id = u.user_id' . $whereSql
    );
    $countStmt->execute($params);
    $totalUsers = (int) $countStmt->fetchColumn();

    $userStmt = $pdo->prepare(
        "SELECT u.user_id, u.email, u.user_role, u.account_status, u.registration_date, p.full_name, p.profile_photo_url, COALESCE(iv.verification_status, 'Pending') AS verification_status
         FROM Users u
         LEFT JOIN User_Profiles p ON p.user_id = u.user_id
         LEFT JOIN Identity_Verifications iv ON iv.user_id = u.user_id" . $whereSql . '
         ORDER BY u.registration_date DESC
         LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $paramKey => $paramValue) {
        $userStmt->bindValue(':' . $paramKey, $paramValue, is_int($paramValue) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $userStmt->bindValue(':limit', (int) $perPage, PDO::PARAM_INT);
    $userStmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
    $userStmt->execute();
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

    $ownerReviewStmt = $pdo->prepare(
        "SELECT u.user_id, u.email, p.full_name, COALESCE(iv.verification_status, 'Pending') AS verification_status
         FROM Users u
         LEFT JOIN User_Profiles p ON p.user_id = u.user_id
         LEFT JOIN Identity_Verifications iv ON iv.user_id = u.user_id
         WHERE u.user_role = 'Owner'
           AND (u.account_status = 'Pending' OR COALESCE(iv.verification_status, 'Pending') = 'Pending')
         ORDER BY u.registration_date DESC
         LIMIT 5"
    );
    $ownerReviewStmt->execute();
    $ownerReviewQueue = $ownerReviewStmt->fetchAll();

    // Load selected owner KYC details if review_owner param is set
    if ($selectedOwnerId) {
        $kycStmt = $pdo->prepare(
            'SELECT u.user_id, u.email, p.full_name, u.phone_number AS phone, iv.verification_id, iv.id_type, iv.id_document_url, iv.biometric_match_status, iv.age_validated, iv.verification_status
             FROM Users u
             LEFT JOIN User_Profiles p ON p.user_id = u.user_id
             LEFT JOIN Identity_Verifications iv ON iv.user_id = u.user_id
             WHERE u.user_id = :user_id AND u.user_role = :ownerRole'
        );
        $kycStmt->execute(['user_id' => $selectedOwnerId, 'ownerRole' => 'Owner']);
        $selectedOwnerForReview = $kycStmt->fetch();
    }

    $totalPages = max(1, (int) ceil($totalUsers / $perPage));
    $page = min($page, $totalPages);
} catch (PDOException $e) {
    // Ensure any open transaction is rolled back
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Exception $ex) {
        // ignore rollback failures
    }

    $users = [];
    $totalUsers = 0;
    $totalPages = 1;
    $adminNotice = 'Unable to load user records at this time.';
    $adminNoticeType = 'error';

    error_log('[admin/users.php] PDOException: ' . $e->getMessage());
}

$queryStringBase = [];
if ($search !== '') {
    $queryStringBase['q'] = $search;
}
if ($roleFilter !== 'all') {
    $queryStringBase['role'] = $roleFilter;
}
if ($statusFilter !== 'all') {
    $queryStringBase['status'] = $statusFilter;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>User Management | Smart Rental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>

<body class="bg-[#f8fafc] text-slate-900 antialiased">
    <div class="min-h-screen flex">
        <?php $currentPage = 'users.php'; require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="flex-grow lg:ml-[280px] min-h-screen flex flex-col">
            <?php
            $pageTitle = 'User Registry';
            $pageSubtitle = 'Customers and hosts under secure review';
            $showSearch = false;
            require_once __DIR__ . '/includes/header.php';
            ?>

            <main class="p-8 flex-1">
                <?php if ($adminNotice !== ''): ?>
                    <div class="mb-6 rounded-2xl border px-4 py-3 text-sm font-semibold <?= $adminNoticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'; ?>">
                        <?= htmlspecialchars($adminNotice, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($selectedOwnerForReview): ?>
                    <section class="mb-8 rounded-[2rem] border border-blue-200/50 bg-blue-50/30 p-8 shadow-sm">
                        <div class="flex items-center justify-between gap-4 mb-6 pb-4 border-b border-blue-200">
                            <div>
                                <h3 class="text-lg font-bold text-slate-900">Owner KYC Review</h3>
                                <p class="text-sm text-slate-500 mt-1">Identity verification documents for <?= htmlspecialchars((string) ($selectedOwnerForReview['full_name'] ?: 'owner'), ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <a href="users.php" class="text-sm font-semibold text-slate-600 hover:text-slate-900 transition">← Back</a>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                                <p class="text-sm font-bold text-slate-400 uppercase tracking-wider">Personal Information</p>
                                <div class="mt-4 space-y-3">
                                    <div>
                                        <p class="text-xs text-slate-500 font-semibold uppercase">Full Name</p>
                                        <p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) ($selectedOwnerForReview['full_name'] ?: 'Not provided'), ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-500 font-semibold uppercase">Email</p>
                                        <p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) ($selectedOwnerForReview['email'] ?? 'Not provided'), ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-500 font-semibold uppercase">Phone</p>
                                        <p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) ($selectedOwnerForReview['phone'] ?: 'Not provided'), ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                                <p class="text-sm font-bold text-slate-400 uppercase tracking-wider">Verification Status</p>
                                <div class="mt-4 space-y-3">
                                    <div>
                                        <p class="text-xs text-slate-500 font-semibold uppercase">ID Type</p>
                                        <p class="text-sm font-semibold text-slate-800 mt-1"><?= htmlspecialchars((string) ($selectedOwnerForReview['id_type'] ?: 'Not provided'), ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-500 font-semibold uppercase">Biometric Match</p>
                                        <span class="inline-block mt-1 px-3 py-1 rounded-full text-[11px] font-semibold <?= $selectedOwnerForReview['biometric_match_status'] ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'; ?>">
                                            <?= $selectedOwnerForReview['biometric_match_status'] ? '✓ Matched' : '— Not matched'; ?>
                                        </span>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-500 font-semibold uppercase">Age Validated</p>
                                        <span class="inline-block mt-1 px-3 py-1 rounded-full text-[11px] font-semibold <?= $selectedOwnerForReview['age_validated'] ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'; ?>">
                                            <?= $selectedOwnerForReview['age_validated'] ? '✓ Confirmed' : '— Not confirmed'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($selectedOwnerForReview['id_document_url']): ?>
                            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
                                <p class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4">Identity Document</p>
                                <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-800">Document on file</p>
                                        <p class="text-xs text-slate-500 mt-1"><?= htmlspecialchars(basename((string) $selectedOwnerForReview['id_document_url']), ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                    <a href="<?= htmlspecialchars('../admin/serve-document.php?file=' . rawurlencode((string) $selectedOwnerForReview['id_document_url']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-[11px] font-semibold text-blue-700 hover:bg-blue-100 transition">View Document</a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="mt-6 flex gap-3 justify-end">
                            <a href="users.php" class="rounded-xl border border-slate-200 bg-white px-6 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">Cancel Review</a>
                            <form method="post" class="inline-flex">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-users'), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $selectedOwnerForReview['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="verify">
                                <button type="submit" class="rounded-xl border border-emerald-200 bg-emerald-50 px-6 py-2.5 text-sm font-semibold text-emerald-700 hover:bg-emerald-100 transition">Approve Owner</button>
                            </form>
                            <form method="post" class="inline-flex">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-users'), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $selectedOwnerForReview['user_id']; ?>">
                                <input type="hidden" name="admin_action" value="reject_owner">
                                <button type="submit" class="rounded-xl border border-red-200 bg-red-50 px-6 py-2.5 text-sm font-semibold text-red-700 hover:bg-red-100 transition">Reject Owner</button>
                            </form>
                        </div>
                    </section>
                <?php endif; ?>

                <section class="mb-8 rounded-[2rem] border border-slate-200/70 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-4">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900">Owner verification queue</h3>
                            <p class="text-sm text-slate-500 mt-1">Pending owner applications that need review.</p>
                        </div>
                        <div class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">
                            <?= count($ownerReviewQueue); ?> pending
                        </div>
                    </div>
                    <div class="mt-5 space-y-3">
                        <?php if (!empty($ownerReviewQueue)): ?>
                            <?php foreach ($ownerReviewQueue as $ownerReview): ?>
                                <div class="flex flex-col gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-4 md:flex-row md:items-center md:justify-between">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars((string) ($ownerReview['full_name'] ?: 'Unnamed owner'), ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p class="text-xs text-slate-500"><?= htmlspecialchars((string) ($ownerReview['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full bg-amber-50 px-3 py-1 text-[11px] font-semibold text-amber-700"><?= htmlspecialchars((string) ($ownerReview['verification_status'] ?? 'Pending'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <a href="users.php?review_owner=<?= (int) $ownerReview['user_id']; ?>" class="rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-[11px] font-semibold text-blue-700 hover:bg-blue-100 transition">View KYC</a>
                                        <form method="post" class="inline-flex">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-users'), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $ownerReview['user_id']; ?>">
                                            <input type="hidden" name="admin_action" value="verify">
                                            <button type="submit" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700">Approve</button>
                                        </form>
                                        <form method="post" class="inline-flex">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-users'), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="user_id" value="<?= (int) $ownerReview['user_id']; ?>">
                                            <input type="hidden" name="admin_action" value="reject_owner">
                                            <button type="submit" class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700">Reject</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state p-4 text-sm">
                                <div class="es-icon">📝</div>
                                <div>No owner verification requests are currently pending.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="bg-white rounded-[2rem] border border-slate-200/70 shadow-sm overflow-hidden">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 border-b border-slate-100 px-8 py-6">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900">Account management console</h3>
                            <p class="text-sm text-slate-500 mt-1">Premium review workspace for customers and hosts.</p>
                        </div>
                        <form method="get" action="users.php" class="flex flex-wrap items-center gap-3">
                            <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by name, email or ID" class="w-full sm:w-72 pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-[#1b4b4b]" />
                            <?php if ($roleFilter !== 'all'): ?>
                                <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php endif; ?>
                            <?php if ($statusFilter !== 'all'): ?>
                                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php endif; ?>
                            <select name="role" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600">
                                <option value="all" <?= $roleFilter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                                <option value="customer" <?= $roleFilter === 'customer' ? 'selected' : ''; ?>>Customer</option>
                                <option value="owner" <?= $roleFilter === 'owner' ? 'selected' : ''; ?>>Owner</option>
                            </select>
                            <select name="status" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-600">
                                <option value="all" <?= $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="verified" <?= $statusFilter === 'verified' ? 'selected' : ''; ?>>Verified</option>
                                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-[#1b4b4b] px-4 py-2.5 text-sm font-semibold text-white">Filter</button>
                        </form>
                        <div class="flex items-center gap-3">
                            <button onclick="document.getElementById('addUserModal').classList.remove('hidden')" class="inline-flex items-center justify-center rounded-xl bg-[#1b4b4b] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#143a3a] transition">
                                + Add User
                            </button>
                            <form method="post" action="users.php" class="inline-flex items-center gap-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-users'), ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="admin_action" value="resync_verification">
                                <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Resync verifications</button>
                            </form>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left">
                            <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-[0.22em] text-slate-400">
                                <tr>
                                    <th class="px-8 py-5">Account</th>
                                    <th class="px-4 py-5">Role</th>
                                    <th class="px-4 py-5">Identity</th>
                                    <th class="px-4 py-5">Registration</th>
                                    <th class="px-4 py-5">Status</th>
                                    <th class="px-4 py-5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (!empty($users)): ?>
                                    <?php foreach ($users as $user): ?>
                                        <?php
                                        $displayName = trim((string) ($user['full_name'] ?? '')) !== '' ? $user['full_name'] : 'Unnamed profile';
                                        $statusLabel = (string) ($user['account_status'] ?? 'Pending');
                                        $verificationStatus = (string) ($user['verification_status'] ?? 'Pending');
                                        $statusBadgeClass = $statusLabel === 'Suspended' ? 'bg-red-50 text-red-600' : ($statusLabel === 'Active' ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600');
                                        $canApprove = $verificationStatus === 'Pending' && $statusLabel !== 'Suspended';
                                        ?>
                                        <tr class="hover:bg-slate-50/70 transition">
                                            <td class="px-8 py-6">
                                                <div class="flex items-center gap-3">
                                                    <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#1b4b4b] text-sm font-bold text-[#facd05]">
                                                        <?= htmlspecialchars(strtoupper(substr($displayName, 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></p>
                                                        <p class="text-xs text-slate-500"><?= htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8'); ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-6 text-sm font-medium text-slate-600">
                                                <?= htmlspecialchars((string) $user['user_role'], ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td class="px-4 py-6">
                                                <p class="text-sm font-semibold text-slate-700"><?= htmlspecialchars($verificationStatus, ENT_QUOTES, 'UTF-8'); ?></p>
                                                <p class="text-xs text-slate-500">KYC / identity profile</p>
                                            </td>
                                            <td class="px-4 py-6 text-sm text-slate-600">
                                                <?= htmlspecialchars((string) $user['registration_date'], ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td class="px-4 py-6">
                                                <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold <?= $statusBadgeClass; ?>">
                                                    <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-6 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <button onclick="viewUserDetails(<?= (int) $user['user_id']; ?>)" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-[11px] font-semibold text-slate-700 hover:bg-slate-50 transition">
                                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                        View
                                                    </button>
                                                    <?php if ($canApprove): ?>
                                                        <form method="post" class="inline-flex">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-users'), ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="user_id" value="<?= (int) $user['user_id']; ?>">
                                                            <input type="hidden" name="admin_action" value="approve">
                                                            <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700 transition hover:bg-emerald-100">
                                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                                                Approve
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <?php if ($statusLabel !== 'Suspended'): ?>
                                                        <form method="post" class="inline-flex">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-users'), ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="user_id" value="<?= (int) $user['user_id']; ?>">
                                                            <input type="hidden" name="admin_action" value="suspend">
                                                            <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700 transition hover:bg-red-100">
                                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                Suspend
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-8 py-12 text-center text-sm text-slate-500"><div class="empty-state inline-block w-full"><div class="es-icon">🙍</div><div>No user records matched the selected filters.</div></div></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-col gap-4 border-t border-slate-100 px-8 py-5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-500">Showing <?= htmlspecialchars((string) min($perPage, count($users)), ENT_QUOTES, 'UTF-8'); ?> of <?= htmlspecialchars((string) $totalUsers, ENT_QUOTES, 'UTF-8'); ?> records.</p>
                        <div class="flex items-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="users.php?page=<?= max(1, $page - 1); ?><?= $search !== '' ? '&q=' . urlencode($search) : ''; ?><?= $roleFilter !== 'all' ? '&role=' . urlencode($roleFilter) : ''; ?><?= $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : ''; ?>" class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Prev</a>
                            <?php endif; ?>
                            <span class="rounded-xl bg-[#1b4b4b] px-3 py-2 text-sm font-semibold text-[#facd05]">Page <?= htmlspecialchars((string) $page, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if ($page < $totalPages): ?>
                                <a href="users.php?page=<?= min($totalPages, $page + 1); ?><?= $search !== '' ? '&q=' . urlencode($search) : ''; ?><?= $roleFilter !== 'all' ? '&role=' . urlencode($roleFilter) : ''; ?><?= $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : ''; ?>" class="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </main>

            <footer class="bg-white border-t border-slate-200/80 h-16 flex items-center justify-center px-8 text-center">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Smart Rental Core Trust Architecture v4.2.0 • Restricted Institutional Access</p>
            </footer>
    </div>

    <!-- Add User Modal -->
    <div id="addUserModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-8">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-bold text-slate-900">Add New User</h3>
                <button onclick="document.getElementById('addUserModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <form method="post" action="users.php" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-users'), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="admin_action" value="add_user">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Full Name</label>
                    <input type="text" name="full_name" required class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-sm outline-none focus:border-[#1b4b4b]" placeholder="Enter full name">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Email Address</label>
                    <input type="email" name="email" required class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-sm outline-none focus:border-[#1b4b4b]" placeholder="Enter email address">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Phone Number</label>
                    <input type="tel" name="phone" class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-sm outline-none focus:border-[#1b4b4b]" placeholder="Enter phone number">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Role</label>
                    <select name="role" required class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-sm outline-none focus:border-[#1b4b4b]">
                        <option value="Customer">Customer</option>
                        <option value="Owner">Owner</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Temporary Password</label>
                    <input type="text" name="password" required class="w-full px-4 py-2.5 border border-slate-200 rounded-xl text-sm outline-none focus:border-[#1b4b4b]" placeholder="Set temporary password">
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="button" onclick="document.getElementById('addUserModal').classList.add('hidden')" class="flex-1 px-4 py-2.5 border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="flex-1 px-4 py-2.5 bg-[#1b4b4b] text-white rounded-xl text-sm font-semibold hover:bg-[#143a3a]">Create User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View User Details Modal -->
    <div id="viewUserModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="p-8">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-bold text-slate-900">User Details</h3>
                    <button onclick="document.getElementById('viewUserModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div id="viewUserContent" class="space-y-4">
                    <!-- Content loaded via JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <script>
    function viewUserDetails(userId) {
        const modal = document.getElementById('viewUserModal');
        const content = document.getElementById('viewUserContent');
        
        fetch('users.php?view_user=' + userId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    content.innerHTML = data.html;
                    modal.classList.remove('hidden');
                } else {
                    alert('Failed to load user details');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to load user details');
            });
    }

    // Close modals on backdrop click
    document.getElementById('addUserModal').addEventListener('click', function(e) {
        if (e.target === this) this.classList.add('hidden');
    });
    document.getElementById('viewUserModal').addEventListener('click', function(e) {
        if (e.target === this) this.classList.add('hidden');
    });
    </script>
</body>
</html>
