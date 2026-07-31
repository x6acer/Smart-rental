<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/audit.php';

$currentPage = 'audit-trail.php';

$entries = [];
$totalEntries = 0;
$totalPages = 1;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

try {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM Audit_Logs');
    $countStmt->execute();
    $totalEntries = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT al.log_id, al.action_type AS action, al.target_table AS entity_type, al.target_id AS entity_id, al.details, al.created_at, u.email AS admin_email
         FROM Audit_Logs al
         LEFT JOIN Users u ON u.user_id = al.admin_id
         ORDER BY al.log_id DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $totalPages = max(1, (int) ceil($totalEntries / $perPage));
    $page = min($page, $totalPages);
} catch (PDOException $e) {
    $entries = [];
    $totalEntries = 0;
    $totalPages = 1;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Audit Trail | Smart Rental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#f8fafc] text-slate-900 antialiased">
    <div class="min-h-screen flex">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="flex-grow lg:ml-[280px] min-h-screen flex flex-col">
            <?php
            $pageTitle = 'Audit Trail';
            $pageSubtitle = 'Recent administrator activity and compliance events';
            require_once __DIR__ . '/includes/header.php';
            ?>

            <main class="p-8 flex-1">
                <section class="bg-white rounded-[2rem] border border-slate-200/70 shadow-sm overflow-hidden">
                    <div class="border-b border-slate-100 px-8 py-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900">System activity log</h3>
                            <p class="text-sm text-slate-500 mt-1">The latest admin actions are captured here for oversight and review.</p>
                        </div>
                        <div class="text-sm text-slate-500">Showing <?= (int) min($offset + 1, max($totalEntries, 0)); ?>–<?= (int) min($offset + count($entries), $totalEntries); ?> of <?= (int) $totalEntries; ?> entries</div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left">
                            <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-[0.22em] text-slate-400">
                                <tr>
                                    <th class="px-8 py-5">Time</th>
                                    <th class="px-4 py-5">Admin</th>
                                    <th class="px-4 py-5">Action</th>
                                    <th class="px-4 py-5">Entity</th>
                                    <th class="px-4 py-5">Details</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (!empty($entries)): ?>
                                    <?php foreach ($entries as $entry): ?>
                                        <tr class="hover:bg-slate-50/70 transition">
                                            <td class="px-8 py-6 text-sm text-slate-600"><?= htmlspecialchars((string) ($entry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="px-4 py-6 text-sm font-semibold text-slate-700"><?= htmlspecialchars((string) ($entry['admin_email'] ?? 'System'), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="px-4 py-6 text-sm font-semibold text-slate-700"><?= htmlspecialchars((string) ($entry['action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="px-4 py-6 text-sm text-slate-600"><?= htmlspecialchars((string) (($entry['entity_type'] ?? '') . ($entry['entity_id'] ? ' #' . $entry['entity_id'] : '')), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="px-4 py-6 text-sm text-slate-600"><?= htmlspecialchars((string) ($entry['details'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-8 py-12 text-center text-sm text-slate-500">No audit entries have been recorded yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="flex flex-col gap-3 border-t border-slate-100 px-8 py-5 md:flex-row md:items-center md:justify-between">
                            <p class="text-sm text-slate-500">Page <?= (int) $page; ?> of <?= (int) $totalPages; ?></p>
                            <div class="flex items-center gap-2">
                                <?php $prevPage = max(1, $page - 1); ?>
                                <a href="audit-trail.php?page=<?= (int) $prevPage; ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">Previous</a>
                                <?php $nextPage = min($totalPages, $page + 1); ?>
                                <a href="audit-trail.php?page=<?= (int) $nextPage; ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition">Next</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </main>
        </div>
    </div>
</body>
</html>
