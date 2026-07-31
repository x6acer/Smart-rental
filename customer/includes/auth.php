<?php
session_start();

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/send-otp.php';
require_once __DIR__ . '/../../includes/security.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function redirectWithError(string $location, string $errorMessage, array $oldInput = []): void
{
    $_SESSION['auth_error'] = $errorMessage;
    $_SESSION['auth_old'] = $oldInput;
    header('Location: ' . $location);
    exit;
}

function sanitizeText(string $value): string
{
    return trim($value);
}

function normalizeRedirectTarget(string $redirectTo): string
{
    $redirectTo = trim($redirectTo);

    if ($redirectTo === '') {
        return '';
    }

    if (preg_match('/^[a-zA-Z0-9_&?=\-./]+$/', $redirectTo) !== 1) {
        return '';
    }

    if (preg_match('/^(?:https?:)?\/\//i', $redirectTo) === 1) {
        return '';
    }

    return $redirectTo;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit;
}

$csrfContext = isset($_POST['login']) ? 'customer-auth' : (isset($_POST['register']) ? 'customer-auth' : 'default');
if (!verifyCsrfToken($_POST['csrf_token'] ?? null, $csrfContext)) {
    redirectWithError('../login.php', 'Security check failed. Please try again.');
}

if (isset($_POST['register'])) {
    $fullName = sanitizeText($_POST['full_name'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $phoneNumber = sanitizeText($_POST['phone_number'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $acceptTerms = isset($_POST['accept_terms']);

    $oldInput = [
        'full_name' => $fullName,
        'email' => $email ?: '',
        'phone_number' => $phoneNumber,
    ];

    if ($fullName === '' || $email === false || $phoneNumber === '' || $password === '' || $confirmPassword === '') {
        redirectWithError('../register.php', 'All fields are required.', $oldInput);
    }

    if (!$acceptTerms) {
        redirectWithError('../register.php', 'You must agree to the terms of service.', $oldInput);
    }

    if ($password !== $confirmPassword) {
        redirectWithError('../register.php', 'Passwords do not match.', $oldInput);
    }

    if (strlen($password) < 8) {
        redirectWithError('../register.php', 'Password must contain at least 8 characters.', $oldInput);
    }

    try {
        $checkStmt = $pdo->prepare('SELECT user_id FROM Users WHERE email = :email LIMIT 1');
        $checkStmt->execute(['email' => $email]);

        if ($checkStmt->fetch()) {
            redirectWithError('../register.php', 'A user with that email address already exists.', $oldInput);
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $insertUser = $pdo->prepare(
            'INSERT INTO Users (email, phone_number, password_hash, user_role, account_status) VALUES (:email, :phone_number, :password_hash, :user_role, :account_status)'
        );
        $insertUser->execute([
            'email' => $email,
            'phone_number' => $phoneNumber,
            'password_hash' => $passwordHash,
            'user_role' => 'Customer',
            'account_status' => 'Pending',
        ]);

        $userId = $pdo->lastInsertId();

        $insertProfile = $pdo->prepare(
            'INSERT INTO User_Profiles (user_id, full_name) VALUES (:user_id, :full_name)'
        );
        $insertProfile->execute([
            'user_id' => $userId,
            'full_name' => $fullName,
        ]);

        regenerateSessionId();
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_name'] = $fullName;
        $_SESSION['user_role'] = 'Customer';
        $_SESSION['logged_in'] = true;
        $_SESSION['verification_pending'] = true;

        if (!sendCustomerVerificationOtp($email, $fullName, (int) $userId)) {
            unset($_SESSION['verification_pending'], $_SESSION['email_otp'], $_SESSION['otp_expires']);
            $deleteProfile = $pdo->prepare('DELETE FROM User_Profiles WHERE user_id = :user_id');
            $deleteProfile->execute(['user_id' => $userId]);

            $deleteUser = $pdo->prepare('DELETE FROM Users WHERE user_id = :user_id');
            $deleteUser->execute(['user_id' => $userId]);

            redirectWithError('../register.php', 'Unable to send verification email. Please try again later.', $oldInput);
        }

        $_SESSION['auth_success'] = 'Registration successful. A verification code has been sent to your inbox.';

        header('Location: ../verify-email.php');
        exit;
    } catch (PDOException $e) {
        redirectWithError('../register.php', 'Unable to complete registration. Please try again later.', $oldInput);
    }
}

if (isset($_POST['login'])) {
    $identifier = sanitizeText($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    $redirectTo = normalizeRedirectTarget((string) ($_POST['redirect_to'] ?? ''));
    $oldInput = ['identifier' => $identifier];

    if ($identifier === '' || $password === '') {
        redirectWithError('../login.php', 'Please enter your email or phone number and password.', $oldInput);
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT u.user_id, u.email, u.phone_number, u.password_hash, u.user_role, u.account_status, p.full_name
             FROM Users u
             LEFT JOIN User_Profiles p ON p.user_id = u.user_id
             WHERE (u.email = :email_identifier OR u.phone_number = :phone_identifier) AND u.user_role = :role
             LIMIT 1'
        );
        $stmt->execute([
            'email_identifier' => $identifier,
            'phone_identifier' => $identifier,
            'role' => 'Customer',
        ]);
        $user = $stmt->fetch();

        if (! $user || ! password_verify($password, $user['password_hash'])) {
            redirectWithError('../login.php', 'Login failed. Please check your credentials and try again.', $oldInput);
        }

        if ($user['account_status'] !== 'Active') {
            regenerateSessionId();
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name'] = $user['full_name'] ?? '';
            $_SESSION['user_role'] = $user['user_role'];
            $_SESSION['verification_pending'] = true;

            $otp = str_pad((string) rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['email_otp'] = $otp;
            $_SESSION['otp_expires'] = time() + 600;

            if (!sendCustomerVerificationOtp($user['email'], $_SESSION['user_name'] ?: 'Customer', (int) $user['user_id'], $otp)) {
                error_log('Login verification email failed: sendCustomerVerificationOtp returned false for user_id ' . $user['user_id']);
                $_SESSION['auth_error'] = 'Unable to send verification code right now. Please try again.';
                header('Location: ../verify-email.php');
                exit;
            }

            $_SESSION['auth_success'] = 'A verification code was sent to your email. Enter it now to complete login.';
            header('Location: ../verify-email.php');
            exit;
        }

        regenerateSessionId();
        $_SESSION = [];

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['user_name'] = $user['full_name'] ?? '';
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['user_role'];
        $_SESSION['logged_in'] = true;

        header('Location: ../' . ($redirectTo !== '' ? $redirectTo : 'dashboard.php'));
        exit;
    } catch (PDOException $e) {
        redirectWithError('../login.php', 'Unable to process login right now. Please try again later.', $oldInput);
    }
}

header('Location: ../login.php');
exit;

