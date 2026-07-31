<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/../../includes/security.php';

function redirectWithMessage(string $location, string $message, string $messageType = 'error'): void
{
    if ($messageType === 'success') {
        $_SESSION['admin_success'] = $message;
    } else {
        $_SESSION['admin_error'] = $message;
    }

    header('Location: ' . $location);
    exit;
}

function generateAdminOtp(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sendAdminOtp(string $recipientEmail, string $otp): bool
{
    $mail = new PHPMailer(true);
    $config = require __DIR__ . '/../../config/app.php';
    $brevoConfig = $config['brevo'];

    try {
        $mail->isSMTP();
        $mail->Host = $brevoConfig['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $brevoConfig['username'];
        $mail->Password = $brevoConfig['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $brevoConfig['port'];

        $mail->setFrom('smarental.cv@gmail.com', 'Smart Rental');
        $mail->addAddress($recipientEmail, 'Admin');
        $mail->isHTML(true);
        $mail->Subject = 'Smart Rental Admin Verification Code';
        $mail->Body = '<p>Your Smart Rental admin verification code is <strong>' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</strong>.</p><p>This code expires in 10 minutes.</p>';
        $mail->AltBody = 'Your Smart Rental admin verification code is ' . $otp . '. This code expires in 10 minutes.';

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Admin OTP email failed: ' . $e->getMessage());
        return false;
    }
}

function isAdminTwoFactorEnabled(int $userId): bool
{
    global $pdo;

    try {
        $stmt = $pdo->prepare(
            'SELECT up.business_settings
             FROM Users u
             LEFT JOIN User_Profiles up ON u.user_id = up.user_id
             WHERE u.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['business_settings'])) {
            $settings = json_decode((string) $row['business_settings'], true);
            if (is_array($settings) && array_key_exists('two_factor_enabled', $settings)) {
                return (bool) $settings['two_factor_enabled'];
            }
        }
    } catch (PDOException $e) {
        error_log('Failed to load admin 2FA setting: ' . $e->getMessage());
    }

    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-auth')) {
    redirectWithMessage('../login.php', 'Security check failed. Please try again.');
}

if (isset($_POST['admin_login'])) {
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';

    if (! $email || trim($password) === '') {
        redirectWithMessage('../login.php', 'Please enter both email and password.');
    }

    try {
        $stmt = $pdo->prepare('SELECT user_id, email, password_hash, user_role FROM Users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch();

        if (! $admin || $admin['user_role'] !== 'Admin') {
            redirectWithMessage('../login.php', 'Invalid admin credentials.');
        }

        if (! password_verify($password, $admin['password_hash'])) {
            redirectWithMessage('../login.php', 'Invalid admin credentials.');
        }

        regenerateSessionId();

        if (! isAdminTwoFactorEnabled((int) $admin['user_id'])) {
            $_SESSION['admin_id'] = $admin['user_id'];
            $_SESSION['admin_email'] = $admin['email'];
            $_SESSION['user_role'] = 'Admin';
            $_SESSION['admin_logged_in'] = true;

            logAdminActivity('admin_login_success', 'Admin signed in without 2FA via system setting.', (int) $admin['user_id'], 'Admin', (int) $admin['user_id']);
            createAdminNotification('Admin sign-in recorded', 'An admin signed in without 2FA using the current system configuration.', 'Security');

            header('Location: ../dashboard.php');
            exit;
        }

        $otp = generateAdminOtp();
        $expiresAt = (new DateTime('+10 minutes'))->format('Y-m-d H:i:s');

        try {
            $otpStmt = $pdo->prepare('UPDATE Users SET email_verification_code = :code, email_verification_expires = :expires WHERE user_id = :user_id');
            $otpStmt->execute([
                'code' => $otp,
                'expires' => $expiresAt,
                'user_id' => $admin['user_id'],
            ]);
        } catch (PDOException $e) {
            error_log('Failed to persist admin OTP: ' . $e->getMessage());
        }

        $otpSent = sendAdminOtp($admin['email'], $otp);

        $_SESSION['admin_2fa_pending'] = true;
        $_SESSION['admin_2fa_user_id'] = $admin['user_id'];
        $_SESSION['admin_2fa_email'] = $admin['email'];
        $_SESSION['admin_2fa_code'] = $otp;
        $_SESSION['admin_2fa_expires'] = time() + 600;
        $_SESSION['admin_2fa_attempts'] = 0;
        $_SESSION['admin_success'] = $otpSent
            ? 'A verification code has been sent to your email.'
            : 'A verification code was prepared. If you do not receive it, contact support.';

        header('Location: ../verify-2fa.php');
        exit;
    } catch (PDOException $e) {
        redirectWithMessage('../login.php', 'Unable to process login at this time. Please try again later.');
    }
}

header('Location: ../login.php');
exit;
