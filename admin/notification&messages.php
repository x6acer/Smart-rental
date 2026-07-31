<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/operational-logic.php';
require_once __DIR__ . '/../includes/notification-dispatch.php';
require_once __DIR__ . '/../vendor/autoload.php';

$currentPage = 'notification&messages.php';

$messageNotice = '';
$messageNoticeType = 'info';
$notifications = [];
$activeNotification = null;
$messageTypeFilter = isset($_GET['type']) ? trim((string) $_GET['type']) : 'all';
$allowedTypes = ['all', 'Direct', 'System', 'Broadcast'];
if (!in_array($messageTypeFilter, $allowedTypes, true)) {
    $messageTypeFilter = 'all';
}

function queueNotificationMessage(PDO $pdo, string $recipientRole, string $title, string $message, string $notificationType = 'System', ?string $recipientEmail = null): void
{
    $config = require __DIR__ . '/../config/app.php';
    $service = new NotificationDispatchService($pdo, $config);
    $channels = ['in_app'];
    if ($recipientEmail !== null && $recipientEmail !== '') {
        $channels[] = 'email';
    }

    $service->dispatch([
        'recipient_role' => $recipientRole,
        'title' => $title,
        'message' => $message,
        'notification_type' => $notificationType,
        'channels' => $channels,
        'recipient_email' => $recipientEmail,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_broadcast'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-notifications')) {
        $messageNotice = 'Security check failed. Please try again.';
        $messageNoticeType = 'error';
    } else {
        $messageTitle = trim((string) ($_POST['message_title'] ?? ''));
        $messageBody = trim((string) ($_POST['message_body'] ?? ''));
        $notificationType = trim((string) ($_POST['notification_type'] ?? 'Broadcast')) ?: 'Broadcast';

        if ($messageTitle === '' || $messageBody === '') {
            $messageNotice = 'Please provide both a title and a message body.';
            $messageNoticeType = 'error';
        } else {
            queueNotificationMessage($pdo, 'Admin', $messageTitle, $messageBody, $notificationType, null);
            $messageNotice = 'Broadcast was successfully created and stored.';
            $messageNoticeType = 'success';
        }
    }
}

try {
    $sql = 'SELECT notification_id, title, message, notification_type, is_read, created_at
            FROM Admin_Notifications
            WHERE recipient_role = :role';
    $params = ['role' => 'Admin'];

    if ($messageTypeFilter !== 'all') {
        $sql .= ' AND notification_type = :notification_type';
        $params['notification_type'] = $messageTypeFilter;
    }

    $sql .= ' ORDER BY notification_id DESC LIMIT 20';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();

    $selectedNotificationId = filter_input(INPUT_GET, 'notification_id', FILTER_VALIDATE_INT);
    if ($selectedNotificationId !== null) {
        foreach ($notifications as $notification) {
            if ((int) $notification['notification_id'] === $selectedNotificationId) {
                $activeNotification = $notification;
                markAdminNotificationRead($selectedNotificationId);
                break;
            }
        }
    }

    if ($activeNotification === null && !empty($notifications)) {
        $activeNotification = $notifications[0];
        if ($activeNotification['is_read'] == 0) {
            markAdminNotificationRead((int) $activeNotification['notification_id']);
        }
    }

    $unreadCount = getAdminUnreadNotificationCount('Admin');
    $headerBadge = $unreadCount > 0 ? $unreadCount . ' unread' : 'All caught up';
} catch (PDOException $e) {
    $notifications = [];
    $activeNotification = null;
    $headerBadge = 'Unable to load updates';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Communication Hub | Smart Rental Control</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --brand-primary: #1b4b4b;
            --brand-accent: #facd05;
        }
        .custom-scrollbar::-webkit-scrollbar { width: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: var(--brand-primary); border-radius: 10px; }
    </style>
</head>

<body class="bg-[#f8fafc] font-sans text-slate-900 antialiased h-screen overflow-hidden flex">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden lg:ml-[280px]">
        <?php
        $pageTitle = 'Notification Center';
        $pageSubtitle = 'Internal messaging and broadcast operations';
        $showStatusBadge = false;
        $headerAction = '<button type="button" data-open-broadcast-form="true" class="bg-[#1b4b4b] text-[#facd05] text-[10px] font-black uppercase px-4 py-1.5 rounded-full shadow-lg">+ New Broadcast</button>';
        require_once __DIR__ . '/includes/header.php';
        ?>

        <div class="flex flex-grow overflow-hidden">
            
            <section class="w-80 lg:w-96 bg-white border-r border-gray-200 flex flex-col shrink-0">
                <div class="p-6 bg-gray-50/50 flex flex-col gap-4 border-b border-gray-100">
                    <div class="flex gap-1" data-tab-group="notification-type">
                        <a href="notification&messages.php?type=all" class="flex-1 py-2 <?= $messageTypeFilter === 'all' ? 'bg-brand text-white' : 'bg-white border border-gray-200 text-gray-400 hover:text-brand'; ?> rounded-lg text-[9px] font-black uppercase text-center">All</a>
                        <a href="notification&messages.php?type=Direct" class="flex-1 py-2 <?= $messageTypeFilter === 'Direct' ? 'bg-brand text-white' : 'bg-white border border-gray-200 text-gray-400 hover:text-brand'; ?> rounded-lg text-[9px] font-black uppercase text-center">Direct</a>
                        <a href="notification&messages.php?type=System" class="flex-1 py-2 <?= $messageTypeFilter === 'System' ? 'bg-brand text-white' : 'bg-white border border-gray-200 text-gray-400 hover:text-brand'; ?> rounded-lg text-[9px] font-black uppercase text-center">System</a>
                        <a href="notification&messages.php?type=Broadcast" class="flex-1 py-2 <?= $messageTypeFilter === 'Broadcast' ? 'bg-brand text-white' : 'bg-white border border-gray-200 text-gray-400 hover:text-brand'; ?> rounded-lg text-[9px] font-black uppercase text-center">Broadcast</a>
                    </div>
                </div>

                <div class="flex-grow overflow-y-auto custom-scrollbar">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <a href="notification&messages.php?type=<?= urlencode($messageTypeFilter); ?>&notification_id=<?= (int) $notification['notification_id']; ?>" class="block p-6 border-b border-gray-50 hover:bg-gray-50 transition <?= $activeNotification && (int) $activeNotification['notification_id'] === (int) $notification['notification_id'] ? 'bg-gray-50 border-l-4 border-brand' : ''; ?>">
                                <div class="flex justify-between items-start mb-2">
                                    <span class="text-[9px] font-black uppercase text-brand bg-green-50 px-2 py-0.5 rounded"><?= htmlspecialchars((string) ($notification['notification_type'] ?? 'System'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <time class="text-[8px] font-bold text-gray-400">#<?= (int) $notification['notification_id']; ?></time>
                                </div>
                                <h4 class="text-xs font-black uppercase"><?= htmlspecialchars((string) ($notification['title'] ?? 'Admin Alert'), ENT_QUOTES, 'UTF-8'); ?></h4>
                                <p class="text-[10px] text-gray-500 mt-1 line-clamp-1"><?= htmlspecialchars((string) ($notification['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                <div class="mt-3 flex items-center justify-between text-[9px] text-gray-400 uppercase">
                                    <span><?= $notification['is_read'] ? 'Seen' : 'New'; ?></span>
                                    <span><?= htmlspecialchars(date('M d, Y', strtotime((string) $notification['created_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-6 text-sm text-gray-500">No notifications available.</div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="flex-grow flex flex-col bg-[#f9fbfb]">
                
                <div class="flex-grow overflow-y-auto p-10 space-y-8 custom-scrollbar">
                    
                    <div class="flex justify-center">
                        <span class="text-[9px] font-black uppercase text-gray-300 tracking-[0.3em] bg-white px-4 py-1 rounded-full border border-gray-100">Monday, 27 April 2026</span>
                    </div>

                    <div class="max-w-3xl mx-auto w-full bg-white border border-gray-100 p-8 rounded-[2rem] shadow-sm">
                        <div class="flex items-center justify-between mb-6">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 bg-[#facd05] rounded-xl flex items-center justify-center text-brand font-black">📣</div>
                                <div>
                                    <h3 class="text-sm font-black uppercase"><?= htmlspecialchars((string) ($activeNotification['title'] ?? 'System Alert'), ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest">Target: <?= htmlspecialchars((string) ($activeNotification['notification_type'] ?? 'Admin Portal'), ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                            </div>
                            <span class="bg-brand text-white text-[9px] font-black px-3 py-1 rounded-full uppercase"><?= htmlspecialchars((string) ($activeNotification['is_read'] ? 'Seen' : 'New'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="text-xs text-gray-600 leading-relaxed space-y-4">
                            <p class="font-bold">Operational Update</p>
                            <p><?= htmlspecialchars((string) ($activeNotification['message'] ?? 'No active notification available.'), ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                        <div class="mt-8 pt-6 border-t border-gray-50 flex items-center justify-between">
                            <div class="flex gap-4">
                                <span class="text-[9px] font-bold text-gray-400">SENT: 450</span>
                                <span class="text-[9px] font-bold text-green-500">READ: 312 (69%)</span>
                            </div>
                            <button class="text-[10px] font-black uppercase text-brand underline decoration-[#facd05] decoration-2 underline-offset-4">View Full Analytics</button>
                        </div>
                    </div>

                    <div class="max-w-3xl mx-auto w-full flex gap-6 items-start">
                        <div class="w-10 h-10 bg-gray-200 rounded-xl shrink-0 flex items-center justify-center font-bold text-xs">O</div>
                        <div class="bg-white p-6 rounded-[2rem] rounded-tl-none border border-gray-100 shadow-sm flex-grow">
                            <div class="flex justify-between mb-4">
                                <p class="text-xs font-black uppercase">Elite Fleet Ghana (Owner)</p>
                                <p class="text-[9px] font-bold text-gray-300">17:42</p>
                            </div>
                            <p class="text-xs text-gray-600 leading-relaxed">Regarding the damage claim on Ford Explorer #G-2024-XP. We have provided the high-resolution images as requested. Renter is disputing the scratch location.</p>
                        </div>
                    </div>
                </div>

                <footer class="p-8 bg-white border-t border-gray-200 shrink-0">
                    <div class="max-w-3xl mx-auto">
                        <?php if ($messageNotice !== ''): ?>
                            <div class="mb-4 rounded-2xl border px-4 py-3 text-sm <?= $messageNoticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'; ?>">
                                <?= htmlspecialchars($messageNotice, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" data-broadcast-form="true" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-notifications'), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <input name="message_title" type="text" placeholder="Message Title" class="w-full p-4 bg-gray-50 border border-gray-100 rounded-3xl text-sm outline-none focus:border-brand" required>
                                <select name="notification_type" class="w-full p-4 bg-gray-50 border border-gray-100 rounded-3xl text-sm outline-none focus:border-brand">
                                    <option value="Broadcast">Broadcast</option>
                                    <option value="System">System</option>
                                    <option value="Direct">Direct</option>
                                </select>
                                <input type="hidden" name="send_broadcast" value="1">
                            </div>
                            <div class="relative">
                                <textarea name="message_body" placeholder="Type a broadcast message or internal note to admin team..." class="w-full p-4 bg-gray-50 border border-gray-100 rounded-3xl text-sm outline-none focus:border-brand min-h-[120px] resize-none" required></textarea>
                                <div class="absolute right-4 bottom-4 flex gap-3 text-gray-300">
                                    <span class="cursor-pointer hover:text-brand">📎</span>
                                    <span class="cursor-pointer hover:text-brand">🎨</span>
                                </div>
                            </div>
                            <div class="flex justify-end">
                                <button type="submit" class="px-10 py-5 bg-brand text-white rounded-[2rem] font-black uppercase text-xs tracking-widest shadow-xl hover:bg-gray-800 transition">Publish Message</button>
                            </div>
                        </form>
                    </div>
                </footer>

            </section>
        </div>
    </main>

    <footer class="fixed bottom-4 left-8 text-[9px] font-black text-gray-300 uppercase tracking-[0.4em] pointer-events-none">
        Institutional Messaging Protocol v4.0 • Encrypted
    </footer>
    <script src="js/admin-app.js" defer></script>
</body>
</html>
