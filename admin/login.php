<?php
session_start();
require_once __DIR__ . '/../includes/security.php';

if (!empty($_SESSION['admin_logged_in']) && ($_SESSION['user_role'] ?? '') === 'Admin') {
    header('Location: dashboard.php');
    exit;
}

$errorMessage = $_SESSION['admin_error'] ?? '';
unset($_SESSION['admin_error']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Login | Smart Rental</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --brand-primary: #1b4b4b;
            --brand-accent: #facd05;
            --bg-dark: #0f172a;
            --card-dark: rgba(15, 23, 42, 0.92);
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-12 sm:px-6 lg:px-8">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,_rgba(250,205,5,0.15),_transparent_38%),radial-gradient(circle_at_bottom_right,_rgba(59,130,246,0.14),_transparent_28%)]"></div>

        <div class="relative w-full max-w-md space-y-8 rounded-[2rem] border border-slate-800 bg-slate-950/95 p-8 shadow-2xl shadow-slate-950/40 backdrop-blur-xl">
            <div class="text-center">
                <p class="text-sm font-semibold uppercase tracking-[0.35em] text-slate-400">Smart Rental</p>
                <h1 class="mt-4 text-4xl font-black tracking-tight text-white">Admin Portal</h1>
                <p class="mt-3 text-sm leading-6 text-slate-400">Secure sign in for authorized administrators only.</p>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <div class="rounded-2xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form action="includes/auth.php" method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken('admin-auth'), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="rounded-3xl border border-slate-700 bg-slate-900/90 p-5 shadow-sm shadow-slate-950/30">
                    <label for="email" class="block text-xs font-semibold uppercase tracking-[0.26em] text-slate-500">Admin Email</label>
                    <input id="email" name="email" type="email" autocomplete="email" required class="mt-3 w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-[#facd05] focus:ring-2 focus:ring-[#facd05]/20" placeholder="admin@smartrental.com" />
                </div>

                <div class="rounded-3xl border border-slate-700 bg-slate-900/90 p-5 shadow-sm shadow-slate-950/30">
                    <label for="password" class="block text-xs font-semibold uppercase tracking-[0.26em] text-slate-500">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required class="mt-3 w-full rounded-2xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-[#facd05] focus:ring-2 focus:ring-[#facd05]/20" placeholder="Enter your password" />
                    <p class="mt-2 text-xs text-slate-500">A one-time verification code will be sent to your email after sign-in.</p>
                </div>

                <input type="hidden" name="admin_login" value="1">
                <button type="submit" class="w-full rounded-3xl bg-gradient-to-r from-[#1b4b4b] via-slate-800 to-[#143a3a] px-5 py-3 text-sm font-extrabold uppercase tracking-[0.18em] text-white shadow-lg shadow-slate-950/30 transition hover:from-[#143a3a] hover:to-[#1b4b4b]">
                    Sign In
                </button>
            </form>

            <div class="rounded-3xl border border-slate-700 bg-slate-900/90 p-5 text-center text-sm text-slate-400">
                <p class="font-semibold text-slate-100">Admin Access</p>
                <p class="mt-2">Manage bookings, fleet, payouts, compliance, and system insights from one secure console.</p>
            </div>

            <div class="text-center">
                <a href="../customer-landing.php" class="text-sm font-semibold text-[#facd05] transition hover:text-white">Back to Main Site</a>
            </div>
        </div>
    </div>
    <script src="js/admin-app.js" defer></script>
</body>
</html>
