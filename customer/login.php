<?php
session_start();
require_once __DIR__ . '/../includes/maintenance-check.php';
require_once __DIR__ . '/../includes/security.php';
enforceMaintenanceMode($pdo);

$authError = $_SESSION['auth_error'] ?? '';
$authSuccess = $_SESSION['auth_success'] ?? '';
$authOld = $_SESSION['auth_old'] ?? [];
$redirectTo = $_GET['redirect'] ?? ($_POST['redirect_to'] ?? '');

if (!is_string($redirectTo)) {
    $redirectTo = '';
}

$redirectTo = trim($redirectTo);

if ($redirectTo !== '' && !preg_match('#^[A-Za-z0-9_&?=./:-]+$#', $redirectTo)) {
    $redirectTo = '';
}

unset($_SESSION['auth_error'], $_SESSION['auth_success'], $_SESSION['auth_old']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Smart Rental</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --color-white: rgba(255, 255, 255, 1);
            --color-cream-50: rgba(252, 252, 249, 1);
            --color-gray-200: rgba(245, 245, 245, 1);
            --color-gray-300: rgba(167, 169, 169, 1);
            --color-slate-500: rgba(98, 108, 113, 1);
            --color-slate-900: rgba(19, 52, 59, 1);
            --color-teal-500: #1b4b4b; /* Primary Brand Green */
            --color-teal-600: #143a3a;
            --color-yellow-400: #facd05; /* Trust Accent */

            --font-family-base: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --radius-base: 8px;
            --space-8: 8px;
            --space-16: 16px;
            --space-24: 24px;
            --space-32: 32px;
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-family-base);
            color: var(--color-slate-900);
            line-height: 1.6;
            background-color: var(--color-cream-50);
            overflow: hidden;
        }

        .split-layout { display: flex; height: 100vh; width: 100%; }

        .split-layout__left {
            flex: 0 0 50%;
            display: flex;
            flex-direction: column;
            padding: var(--space-32);
            overflow-y: auto;
        }

        .split-layout__right {
            flex: 1;
            position: relative;
            background-color: var(--color-teal-500);
        }

        .hero-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0.8;
        }

        .logo h1 {
            font-size: 24px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: -1px;
            color: var(--color-teal-500);
            margin-bottom: var(--space-32);
        }

        .logo span { color: var(--color-yellow-400); }

        .content-container {
            max-width: 400px;
            width: 100%;
            margin: auto;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .section-header { margin-bottom: var(--space-32); text-align: center; }

        .heading { font-size: 32px; font-weight: 800; margin-bottom: var(--space-8); }

        .subtitle { font-size: 16px; color: var(--color-slate-500); }

        .login-form { display: flex; flex-direction: column; gap: var(--space-16); }

        .form-group { display: flex; flex-direction: column; gap: 6px; }

        .form-label { font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--color-slate-500); }

        .form-control {
            width: 100%;
            padding: 14px var(--space-16);
            font-size: 15px;
            border: 1px solid #e2e8f0;
            border-radius: var(--radius-base);
            transition: 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--color-teal-500);
            box-shadow: 0 0 0 3px rgba(27, 75, 75, 0.1);
        }

        .options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            margin: var(--space-8) 0;
        }

        .remember-me { display: flex; align-items: center; gap: var(--space-8); cursor: pointer; }

        .remember-me input { accent-color: var(--color-teal-500); width: 16px; height: 16px; }

        .forgot-password a { color: var(--color-teal-500); text-decoration: none; font-weight: 600; }

        .btn {
            padding: 16px;
            border-radius: var(--radius-base);
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
            border: none;
            text-transform: uppercase;
        }

        .btn--primary {
            background: var(--color-teal-500);
            color: var(--color-white);
        }

        .btn--primary:hover {
            background: var(--color-teal-600);
            box-shadow: var(--shadow-md);
        }

        .signup-prompt {
            text-align: center;
            margin-top: var(--space-24);
            font-size: 14px;
            color: var(--color-slate-500);
        }

        .signup-prompt a { color: var(--color-teal-500); font-weight: 700; text-decoration: none; }

        .footer { margin-top: auto; padding-top: var(--space-32); font-size: 12px; color: var(--color-slate-500); }

        .error-message {
            border: 1px solid rgba(248, 113, 113, 0.4);
            background: rgba(254, 226, 226, 0.9);
            color: #991b1b;
            padding: 14px 16px;
            border-radius: var(--radius-base);
        }

        @media (max-width: 768px) {
            .split-layout__right { display: none; }
            .split-layout__left { flex: 0 0 100%; }
        }
    </style>
</head>
<body>
    <div class="split-layout">
        <div class="split-layout__left">
            <header>
                <div class="logo">
                    <h1>Smart<span>Rental</span></h1>
                </div>
            </header>
            
            <div class="content-container">
                <section class="login-section">
                    <div class="section-header">
                        <h2 class="heading">Welcome Back</h2>
                        <p class="subtitle">Enter your credentials to access your dashboard.</p>
                    </div>

                    <?php if ($authError || $authSuccess): ?>
                        <div role="alert" class="mb-6 rounded-2xl border px-4 py-4 shadow-sm <?php echo $authError ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700'; ?>">
                            <div class="flex items-start justify-between gap-4">
                                <p class="text-sm font-medium"><?php echo htmlspecialchars($authError ?: $authSuccess); ?></p>
                                <button type="button" onclick="this.closest('[role=alert]').remove()" class="text-sm font-bold text-current opacity-70 hover:opacity-100">×</button>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <form class="login-form" action="includes/auth.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken('customer-auth'), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo htmlspecialchars($redirectTo); ?>">
                        <div class="form-group">
                            <label for="identifier" class="form-label">Email or Phone Number</label>
                            <input type="text" id="identifier" name="identifier" class="form-control" autocomplete="username" placeholder="Email or Phone Number" required value="<?php echo htmlspecialchars($authOld['identifier'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" placeholder="••••••••" required>
                        </div>
                        <div class="options">
                            <label class="remember-me">
                                <input type="checkbox" name="remember"> Remember Me
                            </label>
                            <div class="forgot-password">
                                <a href="#" data-open-modal="forgotPasswordModal">Forgot Password?</a>
                            </div>
                        </div>
                        <button type="submit" name="login" class="btn btn--primary">Sign In</button>
                    </form>
                    
                    <div class="signup-prompt">
                        New to Smart Rental? 
                        <a href="register.php">Create an account</a>
                    </div>
                </section>
            </div>
            
            <footer class="footer">
                <span>&copy; 2026 Smart Rental. Secure Authentication Protocol Active.</span>
            </footer>
        </div>
        
        <div class="split-layout__right">
            <img src="assets/images/signup-image.jpg" alt="Fleet selection" class="hero-image">
            <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(to top, rgba(27,75,75,0.9), transparent);"></div>
            <div style="position: absolute; bottom: 40px; left: 40px; color: white;">
                <h3 style="font-size: 28px; font-weight: 800;">Your Journey Awaits</h3>
                <p style="opacity: 0.7; max-width: 300px;">Access the most trusted vehicle rental network in the region.</p>
            </div>
        </div>
    </div>

    <div id="forgotPasswordModal" class="fixed inset-0 bg-[#1b4b4b]/80 backdrop-blur-sm z-50 flex items-center justify-center p-6 hidden" aria-hidden="true">
        <div class="bg-white rounded-[2rem] max-w-md w-full p-8 relative">
            <button data-close-modal="forgotPasswordModal" class="absolute top-5 right-5 text-gray-400 hover:text-[#1b4b4b]">✕</button>
            <h2 class="text-2xl font-black uppercase tracking-tight mb-4">Forgot Password</h2>
            <p class="text-sm text-gray-500 mb-6">Enter your email and we’ll send a reset link to restore access.</p>
            <form class="space-y-4" action="../password-reset.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken('password-reset'), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="password_reset_request" value="1">
                <input type="hidden" name="role" value="Customer">
                <div>
                    <label class="text-[10px] font-black uppercase text-gray-400">Email Address</label>
                    <input type="email" name="email" required class="w-full mt-3 p-4 rounded-2xl border border-gray-200 outline-none focus:ring-2 focus:ring-[#1b4b4b]" placeholder="john@example.com">
                </div>
                <button type="submit" data-close-modal="forgotPasswordModal" class="w-full py-4 rounded-2xl bg-[#1b4b4b] text-white font-black uppercase tracking-widest text-sm hover:bg-gray-800 transition">Send Reset Link</button>
            </form>
        </div>
    </div>

    <script src="./js/cust-ui.js"></script>
</body>
</html>


