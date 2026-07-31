<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../db.php';

function sendCustomerVerificationOtp(string $recipientEmail, string $recipientName = '', ?int $userId = null, ?string $otp = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    global $pdo;

    if ($otp === null) {
        $otp = str_pad((string) rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    $verificationUrl = buildCustomerVerificationUrl($otp);

    $expiresAt = (new DateTime('+10 minutes'))->format('Y-m-d H:i:s');

    if ($userId !== null) {
        try {
            $stmt = $pdo->prepare(
                'UPDATE Users
                 SET email_verification_code = :code,
                     email_verification_expires = :expires,
                     email_verification_attempts = email_verification_attempts + 1
                 WHERE user_id = :user_id'
            );
            $stmt->execute([
                'code' => $otp,
                'expires' => $expiresAt,
                'user_id' => $userId,
            ]);
            
            // Sync session values with DB state
            $_SESSION['email_otp'] = $otp;
            $_SESSION['otp_expires'] = time() + 600;
        } catch (Throwable $e) {
            error_log('Failed to persist verification OTP: ' . $e->getMessage());
            return false;
        }
    } else {
        $_SESSION['email_otp'] = $otp;
        $_SESSION['otp_expires'] = time() + 600;
    }

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
        $mail->addAddress($recipientEmail, $recipientName ?: 'Customer');

        $mail->isHTML(true);
        $mail->Subject = 'Smart Rental - Secure Account Access Code';
        $mail->Body = buildCustomerOtpHtml($recipientName ?: 'Customer', $otp, $verificationUrl);
        $mail->AltBody = 'Smart Rental secure account access code: ' . $otp . '. You can also verify directly at: ' . $verificationUrl . '. This code expires in 10 minutes.';

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('OTP email send failed: ' . $mail->ErrorInfo);
        return false;
    }
}

function buildCustomerVerificationUrl(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . '/SR-cars-new/customer/verify-email.php?token=' . rawurlencode($token);
}

function buildCustomerOtpHtml(string $recipientName, string $otp, string $verificationUrl): string
{
    $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $safeVerificationUrl = htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8');

    return '<!doctype html>
<html>
<body style="margin:0;padding:0;background:#f9f9f8;font-family:Arial,Helvetica,sans-serif;color:#1b4b4b;">
  <div style="max-width:640px;margin:0 auto;padding:40px 20px;">
    <div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:24px;padding:40px;box-shadow:0 10px 30px rgba(0,0,0,0.04);">
      <div style="font-size:14px;font-weight:700;letter-spacing:0.2em;text-transform:uppercase;color:#facd05;margin-bottom:18px;">Smart Rental</div>
      <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;color:#1b4b4b;">Secure Account Access Code</h1>
      <p style="margin:0 0 20px;font-size:16px;line-height:1.6;color:#374151;">Hello ' . $safeName . ',</p>
      <p style="margin:0 0 28px;font-size:16px;line-height:1.6;color:#374151;">Use the verification code below to complete your Smart Rental account setup. This code expires in 10 minutes.</p>
      <div style="display:inline-block;background:#1b4b4b;color:#ffffff;font-size:32px;font-weight:800;letter-spacing:0.25em;padding:18px 28px;border-radius:18px;margin-bottom:28px;">' . $safeOtp . '</div>
            <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#374151;">Or verify instantly with this secure link:</p>
            <p style="margin:0 0 24px;font-size:14px;line-height:1.6;word-break:break-all;"><a href="' . $safeVerificationUrl . '" style="color:#1b4b4b;font-weight:700;">' . $safeVerificationUrl . '</a></p>
      <p style="margin:0;font-size:14px;line-height:1.6;color:#6b7280;">If you did not request this code, you can ignore this email.</p>
      <p style="margin:24px 0 0;font-size:12px;letter-spacing:0.18em;text-transform:uppercase;color:#9ca3af;">Smart Rental - Secure Account Access</p>
    </div>
  </div>
</body>
</html>';
}