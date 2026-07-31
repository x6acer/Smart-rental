<?php
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/security.php';

function adminRedirectWithMessage(string $location, string $message, string $messageType = 'error'): void
{
    if ($messageType === 'success') {
        $_SESSION['admin_success'] = $message;
    } else {
        $_SESSION['admin_error'] = $message;
    }

    header('Location: ' . $location);
    exit;
}

function adminGenerateOtp(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function adminSendOtp(string $recipientEmail, string $otp): bool
{
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $config = require __DIR__ . '/../config/app.php';
    $brevoConfig = $config['brevo'];

    try {
        $mail->isSMTP();
        $mail->Host = $brevoConfig['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $brevoConfig['username'];
        $mail->Password = $brevoConfig['password'];
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $brevoConfig['port'];

        $mail->setFrom('smarental.cv@gmail.com', 'Smart Rental');
        $mail->addAddress($recipientEmail, 'Admin');
        $mail->isHTML(true);
        $mail->Subject = 'Smart Rental Admin Verification Code';
        $mail->Body = '<p>Your Smart Rental admin verification code is <strong>' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</strong>.</p><p>This code expires in 10 minutes.</p>';
        $mail->AltBody = 'Your Smart Rental admin verification code is ' . $otp . '. This code expires in 10 minutes.';

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('Admin OTP email failed: ' . $e->getMessage());
        return false;
    }
}

function adminLoadPendingVerification(PDO $pdo): array
{
    if (empty($_SESSION['admin_2fa_pending']) || empty($_SESSION['admin_2fa_user_id'])) {
        adminRedirectWithMessage('login.php', 'Please sign in again to continue.');
    }

    $stmt = $pdo->prepare(
        'SELECT user_id, email, user_role, email_verification_code, email_verification_expires
         FROM Users
         WHERE user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute(['user_id' => (int) $_SESSION['admin_2fa_user_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (! $admin || ($admin['user_role'] ?? '') !== 'Admin') {
        adminRedirectWithMessage('login.php', 'Please sign in again to continue.');
    }

    return $admin;
}

function adminPersistVerificationCode(PDO $pdo, int $userId, string $otp): void
{
    $expiresAt = (new DateTime('+10 minutes'))->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare('UPDATE Users SET email_verification_code = :code, email_verification_expires = :expires WHERE user_id = :user_id');
    $stmt->execute([
        'code' => $otp,
        'expires' => $expiresAt,
        'user_id' => $userId,
    ]);

    $_SESSION['admin_2fa_code'] = $otp;
    $_SESSION['admin_2fa_expires'] = time() + 600;
    $_SESSION['admin_2fa_attempts'] = 0;
}

function adminClearPendingVerificationState(): void
{
    unset(
        $_SESSION['admin_2fa_pending'],
        $_SESSION['admin_2fa_code'],
        $_SESSION['admin_2fa_expires'],
        $_SESSION['admin_2fa_user_id'],
        $_SESSION['admin_2fa_email'],
        $_SESSION['admin_2fa_attempts'],
        $_SESSION['admin_2fa_backup_codes']
    );
}

function adminFinalizeVerification(array $admin): void
{
    session_regenerate_id(true);

    $_SESSION['admin_id'] = (int) $admin['user_id'];
    $_SESSION['admin_email'] = $admin['email'];
    $_SESSION['user_role'] = 'Admin';
    $_SESSION['admin_logged_in'] = true;

    adminClearPendingVerificationState();
    logAdminActivity('admin_login_success', 'Admin signed in successfully.', (int) $_SESSION['admin_id'], 'Admin', (int) $_SESSION['admin_id']);
    createAdminNotification('Admin sign-in verified', 'A successful admin sign-in was recorded for the portal.', 'Security');
    header('Location: dashboard.php');
    exit;
}

if (!empty($_SESSION['admin_logged_in']) && ($_SESSION['user_role'] ?? '') === 'Admin') {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['admin_2fa_pending']) || empty($_SESSION['admin_2fa_user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-auth')) {
    adminRedirectWithMessage('verify-2fa.php', 'Security check failed. Please try again.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin = adminLoadPendingVerification($pdo);

    if (isset($_POST['resend_code'])) {
        $otp = adminGenerateOtp();

        try {
            adminPersistVerificationCode($pdo, (int) $admin['user_id'], $otp);
        } catch (PDOException $e) {
            error_log('Failed to persist admin OTP: ' . $e->getMessage());
            adminRedirectWithMessage('verify-2fa.php', 'Unable to send a new verification code right now. Please try again later.');
        }

        if (! adminSendOtp($admin['email'], $otp)) {
            adminRedirectWithMessage('verify-2fa.php', 'Unable to send a new verification code right now. Please try again later.');
        }

        adminRedirectWithMessage('verify-2fa.php', 'A new verification code has been sent.', 'success');
    }

    $submittedCode = preg_replace('/\D+/', '', (string) ($_POST['otp_code'] ?? ''));
    $backupCode = trim((string) ($_POST['backup_code'] ?? ''));
    $sessionCode = preg_replace('/\D+/', '', (string) ($_SESSION['admin_2fa_code'] ?? ''));
    $sessionExpires = (int) ($_SESSION['admin_2fa_expires'] ?? 0);
    $dbCode = preg_replace('/\D+/', '', (string) ($admin['email_verification_code'] ?? ''));
    $dbExpires = !empty($admin['email_verification_expires']) ? strtotime((string) $admin['email_verification_expires']) : 0;
    $expiresCandidates = array_filter([$sessionExpires, $dbExpires], static fn ($value) => $value > 0);
    $expiresAt = $expiresCandidates !== [] ? min($expiresCandidates) : 0;

    if ($submittedCode === '' && $backupCode === '') {
        adminRedirectWithMessage('verify-2fa.php', 'Please enter the verification code.');
    }

    if ($expiresAt > 0 && time() > $expiresAt) {
        $_SESSION['admin_2fa_attempts'] = (int) ($_SESSION['admin_2fa_attempts'] ?? 0) + 1;
        adminRedirectWithMessage('verify-2fa.php', 'The verification code has expired. Please request a new code.');
    }

    $backupCodes = $_SESSION['admin_2fa_backup_codes'] ?? [];
    $backupMatch = false;

    if ($backupCode !== '' && is_array($backupCodes)) {
        foreach ($backupCodes as $index => $storedBackupCode) {
            if (hash_equals((string) $storedBackupCode, $backupCode)) {
                unset($_SESSION['admin_2fa_backup_codes'][$index]);
                $backupMatch = true;
                break;
            }
        }
    }

    $otpMatch = $submittedCode !== '' && (
        ($sessionCode !== '' && hash_equals($sessionCode, $submittedCode)) ||
        ($dbCode !== '' && hash_equals($dbCode, $submittedCode))
    );

    if (! $otpMatch && ! $backupMatch) {
        $_SESSION['admin_2fa_attempts'] = (int) ($_SESSION['admin_2fa_attempts'] ?? 0) + 1;
        adminRedirectWithMessage('verify-2fa.php', 'The verification code is incorrect.');
    }

    try {
        $clearStmt = $pdo->prepare('UPDATE Users SET email_verification_code = NULL, email_verification_expires = NULL WHERE user_id = :user_id');
        $clearStmt->execute(['user_id' => (int) $admin['user_id']]);
    } catch (PDOException $e) {
        error_log('Failed to clear admin verification code: ' . $e->getMessage());
        adminRedirectWithMessage('verify-2fa.php', 'Unable to finalize verification right now. Please try again later.');
    }

    adminFinalizeVerification($admin);
}

$errorMessage = $_SESSION['admin_error'] ?? '';
$successMessage = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_error'], $_SESSION['admin_success']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Verification | Smart Rental</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="flex min-h-screen items-center justify-center px-4 py-12">
        <div class="w-full max-w-md rounded-[2rem] border border-slate-800 bg-slate-950/95 p-8 shadow-2xl shadow-slate-950/40">
            <div class="text-center">
                <p class="text-sm font-semibold uppercase tracking-[0.35em] text-slate-400">Smart Rental</p>
                <h1 class="mt-4 text-3xl font-black tracking-tight text-white">Admin Verification</h1>
                <p class="mt-3 text-sm leading-6 text-slate-400">Enter the one-time code sent to your email to continue.</p>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <div class="mt-6 rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($successMessage !== ''): ?>
                <div class="mt-6 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                    <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form action="verify-2fa.php" method="POST" class="mt-8 space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken('admin-auth'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="rounded-3xl border border-slate-700 bg-slate-900/90 p-5">
                    <label for="otp_code" class="block text-xs font-semibold uppercase tracking-[0.26em] text-slate-500">Verification Code</label>
                    <input id="otp_code" name="otp_code" type="text" inputmode="numeric" autocomplete="one-time-code" required class="mt-3 w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-center text-xl font-black tracking-[0.35em] text-slate-100 outline-none transition focus:border-[#facd05] focus:ring-2 focus:ring-[#facd05]/20" placeholder="000000" />
                </div>

                <input type="hidden" name="admin_verify_2fa" value="1">
                <button type="submit" class="w-full rounded-3xl bg-gradient-to-r from-[#1b4b4b] via-slate-800 to-[#143a3a] px-5 py-3 text-sm font-extrabold uppercase tracking-[0.18em] text-white shadow-lg shadow-slate-950/30 transition hover:from-[#143a3a] hover:to-[#1b4b4b]">
                    Verify Access
                </button>
            </form>

            <form action="verify-2fa.php" method="POST" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken('admin-auth'), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="admin_verify_2fa" value="1">
                <input type="hidden" name="resend_code" value="1">
                <button type="submit" class="w-full rounded-2xl border border-slate-700 px-4 py-3 text-sm font-semibold text-slate-300 transition hover:border-[#facd05] hover:text-[#facd05]">
                    Resend Code
                </button>
            </form>
        </div>
    </div>
</body>
</html>
