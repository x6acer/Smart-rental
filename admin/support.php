<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/security.php';

ensureSupportTicketSchema();

$currentPage = 'support.php';

$supportNotice = '';
$supportNoticeType = 'info';
$tickets = [];
$ticketFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : 'all';
$ticketFilter = in_array($ticketFilter, ['all', 'Open', 'In_Progress', 'Resolved'], true) ? $ticketFilter : 'all';
$ticketView = isset($_GET['view']) ? trim((string) $_GET['view']) : 'all';
$ticketView = in_array($ticketView, ['all', 'assigned', 'escalated'], true) ? $ticketView : 'all';
$activeTicket = null;
$caseNotes = [];
$selectedTicketId = filter_input(INPUT_GET, 'ticket_id', FILTER_VALIDATE_INT);
$selectedTicketId = $selectedTicketId ? (int) $selectedTicketId : null;
$escalationOptions = ['Supervisor', 'Finance', 'Legal'];
$adminUsers = [];
$assignedAdminId = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-support')) {
        $supportNotice = 'Security check failed. Please try again.';
        $supportNoticeType = 'error';
    } elseif (isset($_POST['case_note_action'], $_POST['ticket_id'])) {
        $noteAction = strtolower(trim((string) ($_POST['case_note_action'] ?? '')));
        $targetTicketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
        $noteContent = trim((string) ($_POST['note_content'] ?? ''));
        $escalationLevel = trim((string) ($_POST['escalation_level'] ?? '')) ?: null;
        $assignedAdminId = filter_input(INPUT_POST, 'assigned_admin_id', FILTER_VALIDATE_INT);

        if ($targetTicketId && $noteAction === 'add_note' && $noteContent !== '') {
            try {
                $pdo->beginTransaction();
                addSupportCaseNote($targetTicketId, $noteContent, $escalationLevel, (int) ($_SESSION['admin_id'] ?? 0));

                $updateFields = [];
                $updateParams = ['ticket_id' => $targetTicketId];

                if ($escalationLevel) {
                    $updateFields[] = 'escalation_level = :escalation_level';
                    $updateParams['escalation_level'] = $escalationLevel;
                }

                if ($assignedAdminId) {
                    $updateFields[] = 'assigned_admin_id = :assigned_admin_id';
                    $updateParams['assigned_admin_id'] = $assignedAdminId;
                }

                if (!empty($updateFields)) {
                    $setClause = implode(', ', $updateFields);
                    $pdo->prepare('UPDATE Support_Tickets SET ' . $setClause . ' WHERE ticket_id = :ticket_id')->execute($updateParams);
                }

                $pdo->commit();
                $supportNotice = 'Case note added successfully.';
                $supportNoticeType = 'success';
                logAdminAction((int) ($_SESSION['admin_id'] ?? 0), 'support_case_note_added', 'Support_Tickets', $targetTicketId, 'Admin added a support note to ticket #' . $targetTicketId);

                if ($escalationLevel) {
                    createAdminNotification('Support ticket escalated', 'Ticket #' . $targetTicketId . ' was escalated to ' . $escalationLevel . '.', 'Support');
                }

                if ($assignedAdminId) {
                    $assignedAdminEmail = '';
                    try {
                        $adminStmt = $pdo->prepare('SELECT email FROM Users WHERE user_id = :admin_id LIMIT 1');
                        $adminStmt->execute(['admin_id' => $assignedAdminId]);
                        $adminRow = $adminStmt->fetch(PDO::FETCH_ASSOC);
                        $assignedAdminEmail = $adminRow['email'] ?? '';
                    } catch (PDOException $e) {
                        error_log('Failed to load assigned admin email: ' . $e->getMessage());
                    }
                    createAdminNotification('Support ticket assigned', 'Ticket #' . $targetTicketId . ' was assigned to ' . ($assignedAdminEmail ?: 'an administrator') . '.', 'Support');
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $supportNotice = 'Unable to add case note.';
                $supportNoticeType = 'error';
            }
        }
    } elseif (isset($_POST['ticket_action'], $_POST['ticket_id'])) {
        $action = strtolower(trim((string) ($_POST['ticket_action'] ?? '')));
        $targetTicketId = filter_input(INPUT_POST, 'ticket_id', FILTER_VALIDATE_INT);
        $statusMap = [
            'open' => 'Open',
            'in_progress' => 'In_Progress',
            'resolve' => 'Resolved',
        ];

        if ($targetTicketId && isset($statusMap[$action])) {
            try {
                $selectedTicketId = $targetTicketId;
                $resolvedAt = $statusMap[$action] === 'Resolved' ? date('Y-m-d H:i:s') : null;
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE Support_Tickets SET status = :status, resolved_at = :resolved_at WHERE ticket_id = :ticket_id')->execute([
                    'status' => $statusMap[$action],
                    'resolved_at' => $resolvedAt,
                    'ticket_id' => $targetTicketId,
                ]);
                $pdo->commit();
                $supportNotice = 'Support ticket updated.';
                $supportNoticeType = 'success';
                logAdminAction((int) ($_SESSION['admin_id'] ?? 0), 'support_ticket_updated', 'Support_Tickets', $targetTicketId, 'Admin updated support ticket #' . $targetTicketId . ' to ' . $statusMap[$action]);
                createAdminNotification('Support ticket updated', 'Support ticket #' . $targetTicketId . ' was updated to ' . $statusMap[$action] . '.', 'Support');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $supportNotice = 'Unable to update that ticket right now.';
                $supportNoticeType = 'error';
            }
        } else {
            $supportNotice = 'Invalid ticket action.';
            $supportNoticeType = 'error';
        }
    }
}

try {
    $adminUsersStmt = $pdo->prepare('SELECT user_id, email FROM Users WHERE user_role = :role ORDER BY email ASC');
    $adminUsersStmt->execute(['role' => 'Admin']);
    $adminUsers = $adminUsersStmt->fetchAll();

    $sql = 'SELECT st.ticket_id, st.subject, st.description, st.status, st.escalation_level, st.assigned_admin_id, u.email AS user_email, p.full_name AS user_name, a.email AS assigned_admin_email
            FROM Support_Tickets st
            INNER JOIN Users u ON u.user_id = st.user_id
            LEFT JOIN User_Profiles p ON p.user_id = u.user_id
            LEFT JOIN Users a ON a.user_id = st.assigned_admin_id';
    $params = [];
    $clauses = [];

    if ($ticketFilter !== 'all') {
        $clauses[] = 'st.status = :status';
        $params['status'] = $ticketFilter;
    }

    if ($ticketView === 'assigned') {
        $clauses[] = 'st.assigned_admin_id = :assigned_admin_id';
        $params['assigned_admin_id'] = (int) ($_SESSION['admin_id'] ?? 0);
    } elseif ($ticketView === 'escalated') {
        $clauses[] = 'st.escalation_level IS NOT NULL AND st.escalation_level <> ""';
    }

    if (!empty($clauses)) {
        $sql .= ' WHERE ' . implode(' AND ', $clauses);
    }

    $sql .= ' ORDER BY st.ticket_id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();

    if ($selectedTicketId !== null) {
        foreach ($tickets as $ticket) {
            if ((int) $ticket['ticket_id'] === $selectedTicketId) {
                $activeTicket = $ticket;
                break;
            }
        }
    }

    if ($activeTicket === null && !empty($tickets)) {
        $activeTicket = $tickets[0];
    }

    // Load case notes for the active ticket
    if ($activeTicket !== null) {
        $caseNotes = getSupportCaseNotes((int) $activeTicket['ticket_id']);
    }
} catch (PDOException $e) {
    $tickets = [];
    $supportNotice = 'Unable to load support tickets at this time.';
    $supportNoticeType = 'error';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Support Management | Smart Rental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --brand-primary: #1b4b4b;
            --brand-accent: #facd05;
        }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
    </style>
</head>

<body class="bg-[#f8fafc] text-slate-900 antialiased h-screen overflow-hidden">
    <div class="flex h-full">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="flex-1 flex flex-col overflow-hidden lg:ml-[280px]">
            <?php
            $pageTitle = 'Support Helpdesk';
            $pageSubtitle = 'Dispute resolution and case management';
            $showStatusBadge = false;
            require_once __DIR__ . '/includes/header.php';
            ?>

            <div class="flex flex-grow overflow-hidden">
                
                <section class="w-full md:w-80 lg:w-96 bg-white border-r border-gray-100 flex flex-col shrink-0">
                    <div class="p-6 border-b border-gray-50 bg-gray-50/50">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-xs font-black uppercase tracking-widest text-gray-400">Incoming Queue</h3>
                            <button class="text-[18px]">⚙️</button>
                        </div>
                        <div class="flex flex-wrap gap-2 mb-4" data-tab-group="support-filter">
                            <a href="?status=all&view=all" class="flex-grow py-2 <?= $ticketFilter === 'all' && $ticketView === 'all' ? 'bg-brand text-white' : 'bg-white border border-gray-200 text-gray-400 hover:text-brand' ?> rounded-lg text-[10px] font-black uppercase text-center">All</a>
                            <a href="?status=all&view=assigned" class="flex-grow py-2 <?= $ticketView === 'assigned' ? 'bg-brand text-white' : 'bg-white border border-gray-200 text-gray-400 hover:text-brand' ?> rounded-lg text-[10px] font-black uppercase text-center">Assigned to Me</a>
                            <a href="?status=all&view=escalated" class="flex-grow py-2 <?= $ticketView === 'escalated' ? 'bg-brand text-white' : 'bg-white border border-gray-200 text-gray-400 hover:text-brand' ?> rounded-lg text-[10px] font-black uppercase text-center">Escalated</a>
                            <a href="?status=In_Progress&view=all" class="flex-grow py-2 <?= $ticketFilter === 'In_Progress' ? 'bg-brand text-white' : 'bg-white border border-gray-200 text-gray-400 hover:text-brand' ?> rounded-lg text-[10px] font-black uppercase text-center">In Progress</a>
                        </div>
                        <input type="text" placeholder="Search ticket ID or user..." class="w-full p-3 bg-white border border-gray-100 rounded-xl text-xs outline-none focus:border-brand" data-search-tickets="true">
                    </div>

                    <div class="flex-grow overflow-y-auto custom-scrollbar">
                        <?php if (!empty($tickets)): ?>
                            <?php foreach ($tickets as $ticket): ?>
                                <a href="?status=<?= urlencode($ticketFilter) ?>&view=<?= urlencode($ticketView) ?>&ticket_id=<?= (int) $ticket['ticket_id']; ?>" class="block p-6 border-b border-gray-50 hover:bg-gray-50 transition <?= $activeTicket && (int) $activeTicket['ticket_id'] === (int) $ticket['ticket_id'] ? 'bg-gray-50 border-l-4 border-brand' : '' ?>">
                                    <div class="flex justify-between items-start mb-2 gap-2">
                                        <span class="text-[9px] font-black uppercase text-brand bg-green-50 px-2 py-0.5 rounded"><?= htmlspecialchars((string) $ticket['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if (!empty($ticket['escalation_level'])): ?>
                                            <span class="text-[9px] font-black uppercase text-orange-700 bg-orange-50 px-2 py-0.5 rounded">Escalated</span>
                                        <?php endif; ?>
                                        <?php if (!empty($ticket['assigned_admin_email'])): ?>
                                            <span class="text-[9px] font-black uppercase text-brand bg-slate-50 px-2 py-0.5 rounded">Assigned</span>
                                        <?php endif; ?>
                                        <time class="text-[8px] font-bold text-gray-400 uppercase">#<?= (int) $ticket['ticket_id']; ?></time>
                                    </div>
                                    <h4 class="text-xs font-black uppercase"><?= htmlspecialchars((string) $ticket['subject'], ENT_QUOTES, 'UTF-8'); ?></h4>
                                    <p class="text-[10px] text-gray-500 mt-1 italic"><?= htmlspecialchars((string) $ticket['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <div class="mt-4 flex items-center justify-between gap-3">
                                        <span class="text-[9px] font-bold text-gray-400 uppercase tracking-tighter"><?= htmlspecialchars((string) ($ticket['user_name'] ?: $ticket['user_email'] ?: 'Unknown sender'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <div class="flex items-center gap-2">
                                            <?php if (!empty($ticket['assigned_admin_email'])): ?>
                                                <span class="text-[9px] text-slate-500 uppercase">Owner: <?= htmlspecialchars((string) $ticket['assigned_admin_email'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php endif; ?>
                                            <span class="text-[9px] font-black text-brand uppercase"><?= htmlspecialchars((string) $ticket['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state p-6 text-sm">
                                <div class="es-icon">🎫</div>
                                <div>No support tickets were found.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="flex-grow bg-[#f9fbfb] flex flex-col">
                    <?php if (!empty($supportNotice)): ?>
                        <div class="mx-6 mt-6 rounded-2xl border px-4 py-3 text-sm <?= $supportNoticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700' ?>">
                            <?= htmlspecialchars((string) $supportNotice, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($activeTicket): ?>
                        <header class="bg-white p-6 border-b border-gray-100 flex justify-between items-center shrink-0">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 bg-red-100 rounded-2xl flex items-center justify-center text-red-600 font-bold text-xl">!</div>
                                <div>
                                    <h3 class="text-sm font-black uppercase leading-none">Ticket #<?= (int) $activeTicket['ticket_id']; ?>: <?= htmlspecialchars((string) $activeTicket['subject'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <div class="flex flex-wrap gap-4 mt-2">
                                        <span class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Requester: <?= htmlspecialchars((string) ($activeTicket['user_name'] ?: $activeTicket['user_email'] ?: 'Unknown sender'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="text-[9px] font-bold text-gray-400 uppercase tracking-widest">Status: <?= htmlspecialchars((string) $activeTicket['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if (!empty($activeTicket['escalation_level'])): ?>
                                            <span class="text-[9px] font-bold text-orange-600 uppercase tracking-widest">Escalation: <?= htmlspecialchars((string) $activeTicket['escalation_level'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($activeTicket['assigned_admin_email'])): ?>
                                            <span class="text-[9px] font-bold text-brand uppercase tracking-widest">Assigned to: <?= htmlspecialchars((string) $activeTicket['assigned_admin_email'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <span class="rounded-full bg-amber-50 px-3 py-2 text-[10px] font-black uppercase text-amber-700"><?= htmlspecialchars((string) $activeTicket['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </header>

                        <div class="flex-grow overflow-y-auto p-8 space-y-6 custom-scrollbar">
                            <div class="max-w-3xl rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                                <p class="text-[10px] font-black uppercase tracking-[0.25em] text-gray-400">Issue details</p>
                                <p class="mt-3 text-sm leading-relaxed text-gray-700"><?= nl2br(htmlspecialchars((string) $activeTicket['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                            </div>

                            <div class="max-w-3xl rounded-2xl border border-blue-100 bg-blue-50 p-6 shadow-sm">
                                <p class="text-[10px] font-black uppercase tracking-[0.25em] text-blue-700">Case notes & escalation</p>
                                <p class="mt-2 text-sm text-blue-700">Add investigative notes or escalate this ticket to a specialist.</p>
                            </div>

                            <?php if (!empty($caseNotes)): ?>
                                <div class="max-w-3xl rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                                    <p class="text-[10px] font-black uppercase tracking-[0.25em] text-gray-400 mb-4">Case history</p>
                                    <div class="space-y-3">
                                        <?php foreach ($caseNotes as $note): ?>
                                            <div class="flex gap-3 pb-3 border-b border-gray-100 last:border-b-0">
                                                <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center text-[10px] font-bold text-slate-600 shrink-0">N</div>
                                                <div class="flex-grow">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        <p class="text-xs font-semibold text-gray-800">Admin note</p>
                                                        <?php if ($note['escalation_level']): ?>
                                                            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold text-orange-700 bg-orange-50">Escalated: <?= htmlspecialchars((string) $note['escalation_level'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <p class="text-xs text-gray-700 mt-1"><?= htmlspecialchars((string) $note['note_content'], ENT_QUOTES, 'UTF-8'); ?></p>
                                                    <p class="text-[10px] text-gray-400 mt-1"><?= htmlspecialchars(date('M d, Y g:i A', strtotime((string) $note['created_at'])), ENT_QUOTES, 'UTF-8'); ?></p>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <footer class="p-6 bg-white border-t border-gray-100 shrink-0">
                            <div class="space-y-4">
                                <form method="post" data-support-composer="true" class="flex flex-col gap-4">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-support'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="ticket_id" value="<?= (int) $activeTicket['ticket_id']; ?>">
                                    <input type="hidden" name="case_note_action" value="add_note">
                                    <div>
                                        <label class="block text-[10px] font-black uppercase text-gray-400 tracking-wider mb-2">Add case note</label>
                                        <textarea name="note_content" placeholder="Document your investigation, findings, or actions taken..." class="w-full p-3 bg-gray-50 border border-gray-200 rounded-xl text-xs outline-none focus:border-brand resize-none h-20"></textarea>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <select name="escalation_level" class="rounded-2xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-[10px] font-bold uppercase outline-none">
                                            <option value="">— No escalation</option>
                                            <option value="Supervisor">Escalate to Supervisor</option>
                                            <option value="Finance">Escalate to Finance</option>
                                            <option value="Legal">Escalate to Legal</option>
                                        </select>
                                        <select name="assigned_admin_id" class="rounded-2xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-[10px] font-bold uppercase outline-none">
                                            <option value="">— Assign to admin</option>
                                            <?php foreach ($adminUsers as $adminUser): ?>
                                                <option value="<?= (int) $adminUser['user_id']; ?>" <?= $activeTicket['assigned_admin_id'] === (int) $adminUser['user_id'] ? 'selected' : ''; ?>><?= htmlspecialchars((string) $adminUser['email'], ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="rounded-2xl bg-blue-500 px-6 py-2.5 text-[10px] font-black uppercase tracking-widest text-white shadow-lg hover:bg-blue-600 transition">Add note</button>
                                </form>

                                <form method="post" class="flex flex-col gap-4 md:flex-row md:items-center pt-4 border-t border-gray-100">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-support'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="ticket_id" value="<?= (int) $activeTicket['ticket_id']; ?>">
                                    <select name="ticket_action" class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-[10px] font-black uppercase outline-none">
                                        <option value="open" <?= $activeTicket['status'] === 'Open' ? 'selected' : '' ?>>Open</option>
                                        <option value="in_progress" <?= $activeTicket['status'] === 'In_Progress' ? 'selected' : '' ?>>In Progress</option>
                                        <option value="resolve" <?= $activeTicket['status'] === 'Resolved' ? 'selected' : '' ?>>Resolved</option>
                                    </select>
                                    <button class="rounded-2xl bg-brand px-8 py-3 text-[10px] font-black uppercase tracking-widest text-white shadow-lg">Update ticket status</button>
                                </form>
                            </div>
                        </footer>
                    <?php else: ?>
                        <div class="flex flex-1 items-center justify-center p-8 text-center text-sm text-gray-500">
                            No support tickets match the current filter.
                        </div>
                    <?php endif; ?>
                </section>

                <aside class="hidden xl:flex w-72 bg-white border-l border-gray-200 flex-col shrink-0 p-8">
                    <h3 class="text-xs font-black uppercase tracking-widest text-gray-400 mb-8">Performance KPIs</h3>
                    
                    <div class="space-y-10">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase mb-4 tracking-tighter">Queue Health</p>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-xs font-bold text-gray-600">Avg. Response</span>
                                <span class="text-xs font-black text-brand">14m 20s</span>
                            </div>
                            <div class="w-full bg-gray-100 h-1.5 rounded-full">
                                <div class="bg-green-500 h-full w-[90%]"></div>
                            </div>
                        </div>

                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase mb-4 tracking-tighter">Resolution CSAT</p>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-xs font-bold text-gray-600">Current Rating</span>
                                <span class="text-xs font-black text-[#facd05]">4.85 ★</span>
                            </div>
                        </div>

                        <hr class="border-gray-50">

                        <div class="bg-gray-50 p-4 rounded-2xl">
                            <p class="text-[9px] font-black uppercase text-gray-400 mb-2 italic">Support Pro-Tip:</p>
                            <p class="text-[10px] font-medium text-gray-600 leading-relaxed">"Always check the Handover Photo Log (Flow 4.4) before approving damage claims."</p>
                        </div>
                    </div>
                </aside>

            </div>
        </div>
    </div>
    <script src="js/admin-app.js" defer></script>
</body>
</html>
