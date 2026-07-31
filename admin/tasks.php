<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/security.php';

$currentPage = 'tasks.php';
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$taskNotice = '';
$taskNoticeType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_task'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-tasks')) {
        $taskNotice = 'Security check failed. Please try again.';
        $taskNoticeType = 'error';
    } else {
        $taskTitle = trim((string) ($_POST['task_title'] ?? ''));
        $taskDescription = trim((string) ($_POST['task_description'] ?? ''));
        $taskType = trim((string) ($_POST['task_type'] ?? 'Operational')) ?: 'Operational';
        $taskPriority = trim((string) ($_POST['task_priority'] ?? 'Medium')) ?: 'Medium';
        $taskDueDate = trim((string) ($_POST['task_due_date'] ?? ''));
        $assignedAdminId = filter_input(INPUT_POST, 'assigned_admin_id', FILTER_VALIDATE_INT);

        if ($taskTitle === '') {
            $taskNotice = 'Please provide a task title.';
            $taskNoticeType = 'error';
        } else {
            $assignedTo = $assignedAdminId ?: $adminId;
            $created = assignAdminTask($adminId, $assignedTo, $taskType, $taskTitle, $taskDescription, 'Admin_Task', 0, $taskPriority, $taskDueDate !== '' ? $taskDueDate : null);
            if ($created) {
                $taskNotice = 'Task created and assigned successfully.';
                $taskNoticeType = 'success';
                logAdminAction($adminId, 'task_created', 'Admin_Tasks', null, 'Created task: ' . $taskTitle);
                createAdminNotification('New admin task', 'A new task was created and assigned to an administrator.', 'Admin');
            } else {
                $taskNotice = 'Unable to create the task right now.';
                $taskNoticeType = 'error';
            }
        }
    }
}

// Handle task status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['task_action'], $_POST['task_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-tasks')) {
        $taskNotice = 'Security check failed. Please try again.';
        $taskNoticeType = 'error';
    } else {
        $taskId = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
        $action = trim((string) ($_POST['task_action'] ?? ''));

        if ($taskId && in_array($action, ['start', 'complete'], true)) {
            try {
                $newStatus = $action === 'start' ? 'In_Progress' : 'Completed';
                if (updateTaskStatus($taskId, $newStatus)) {
                    $taskNotice = 'Task status updated successfully.';
                    $taskNoticeType = 'success';
                    logAdminActivity('task_status_updated', 'Admin updated task ' . $taskId . ' to ' . $newStatus, $adminId, 'Task', $taskId);
                    createAdminNotification('Task updated', 'Task #' . $taskId . ' marked as ' . str_replace('_', ' ', $newStatus), 'Admin');
                }
            } catch (Exception $e) {
                $taskNotice = 'Unable to update task status.';
                $taskNoticeType = 'error';
            }
        }
    }
}

// Load tasks
try {
    $assignedTasks = getAdminTasksAssignedTo($adminId);
    $adminUsersStmt = $pdo->prepare('SELECT user_id, email FROM Users WHERE user_role = :role ORDER BY email ASC');
    $adminUsersStmt->execute(['role' => 'Admin']);
    $adminUsers = $adminUsersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Get overview stats
    $statsStmt = $pdo->prepare(
        'SELECT 
            SUM(status = "Pending") AS pending_count,
            SUM(status = "In_Progress") AS in_progress_count,
            SUM(status = "Completed") AS completed_count,
            SUM(status = "Overdue") AS overdue_count
         FROM Admin_Tasks WHERE assigned_to = :admin_id'
    );
    $statsStmt->execute(['admin_id' => $adminId]);
    $taskStats = $statsStmt->fetch() ?: ['pending_count' => 0, 'in_progress_count' => 0, 'completed_count' => 0, 'overdue_count' => 0];
} catch (PDOException $e) {
    $assignedTasks = [];
    $adminUsers = [];
    $taskStats = ['pending_count' => 0, 'in_progress_count' => 0, 'completed_count' => 0, 'overdue_count' => 0];
    error_log('Failed to load tasks: ' . $e->getMessage());
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Task Board | Smart Rental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>

<body class="bg-[#f8fafc] text-slate-900 antialiased">
    <div class="min-h-screen flex">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="flex-grow lg:ml-[280px] min-h-screen flex flex-col">
            <?php
            $pageTitle = 'Task Board';
            $pageSubtitle = 'Manage assigned actions and team coordination';
            $showSearch = false;
            require_once __DIR__ . '/includes/header.php';
            ?>

            <main class="p-8 flex-1">
                <?php if ($taskNotice !== ''): ?>
                    <div class="mb-6 rounded-2xl border px-4 py-3 text-sm font-semibold <?= $taskNoticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'; ?>">
                        <?= htmlspecialchars($taskNotice, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <!-- Task Stats -->
                <section class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-2xl border border-slate-200/70 shadow-sm">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Pending</p>
                                <h3 class="text-3xl font-extrabold text-slate-900 mt-2"><?= (int) ($taskStats['pending_count'] ?? 0); ?></h3>
                            </div>
                            <div class="p-2.5 rounded-xl bg-amber-50 border border-amber-100 text-amber-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl border border-slate-200/70 shadow-sm">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">In Progress</p>
                                <h3 class="text-3xl font-extrabold text-slate-900 mt-2"><?= (int) ($taskStats['in_progress_count'] ?? 0); ?></h3>
                            </div>
                            <div class="p-2.5 rounded-xl bg-blue-50 border border-blue-100 text-blue-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl border border-slate-200/70 shadow-sm">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Completed</p>
                                <h3 class="text-3xl font-extrabold text-slate-900 mt-2"><?= (int) ($taskStats['completed_count'] ?? 0); ?></h3>
                            </div>
                            <div class="p-2.5 rounded-xl bg-emerald-50 border border-emerald-100 text-emerald-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl border border-slate-200/70 shadow-sm">
                        <div class="flex justify-between items-start">
                            <div>
                                <p class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Overdue</p>
                                <h3 class="text-3xl font-extrabold text-red-600 mt-2"><?= (int) ($taskStats['overdue_count'] ?? 0); ?></h3>
                            </div>
                            <div class="p-2.5 rounded-xl bg-red-50 border border-red-100 text-red-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4v2m0 4v2M6.75 3h10.5a2.25 2.25 0 012.25 2.25v16.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 21.75V5.25A2.25 2.25 0 016.75 3z"/></svg>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="mb-8 rounded-[2rem] border border-slate-200/70 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between gap-4 border-b border-slate-100 pb-4">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900">Create Internal Task</h3>
                            <p class="text-sm text-slate-500 mt-1">Create operational follow-ups for the admin team.</p>
                        </div>
                    </div>
                    <form method="post" class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-tasks'), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="create_task" value="1">
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-black uppercase text-gray-400 tracking-widest mb-2">Task title</label>
                            <input type="text" name="task_title" required class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-brand" placeholder="Review vehicle ID #104">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-[10px] font-black uppercase text-gray-400 tracking-widest mb-2">Task description</label>
                            <textarea name="task_description" rows="3" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-brand" placeholder="Add context for the team"></textarea>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase text-gray-400 tracking-widest mb-2">Task type</label>
                            <select name="task_type" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-brand">
                                <option value="Operational">Operational</option>
                                <option value="Review">Review</option>
                                <option value="Compliance">Compliance</option>
                                <option value="Finance">Finance</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase text-gray-400 tracking-widest mb-2">Priority</label>
                            <select name="task_priority" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-brand">
                                <option value="Medium">Medium</option>
                                <option value="High">High</option>
                                <option value="Critical">Critical</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase text-gray-400 tracking-widest mb-2">Assign to</label>
                            <select name="assigned_admin_id" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-brand">
                                <option value="">Assign to me</option>
                                <?php foreach ($adminUsers as $adminUser): ?>
                                    <option value="<?= (int) $adminUser['user_id']; ?>"><?= htmlspecialchars((string) $adminUser['email'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase text-gray-400 tracking-widest mb-2">Due date</label>
                            <input type="date" name="task_due_date" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-brand">
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" class="rounded-2xl bg-brand px-6 py-3 text-[10px] font-black uppercase tracking-widest text-white">Create Task</button>
                        </div>
                    </form>
                </section>

                <!-- Tasks Table -->
                <section class="bg-white rounded-[2rem] border border-slate-200/70 shadow-sm overflow-hidden">
                    <div class="border-b border-slate-100 px-8 py-6">
                        <h3 class="text-lg font-bold text-slate-900">Your Assigned Tasks</h3>
                        <p class="text-sm text-slate-500 mt-1">Tasks assigned to you by other admins.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left">
                            <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-[0.22em] text-slate-400">
                                <tr>
                                    <th class="px-8 py-5">Task</th>
                                    <th class="px-4 py-5">Assigned By</th>
                                    <th class="px-4 py-5">Type</th>
                                    <th class="px-4 py-5">Priority</th>
                                    <th class="px-4 py-5">Due Date</th>
                                    <th class="px-4 py-5">Status</th>
                                    <th class="px-4 py-5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (!empty($assignedTasks)): ?>
                                    <?php foreach ($assignedTasks as $task): ?>
                                        <?php
                                        $statusClass = match($task['status']) {
                                            'Pending' => 'bg-amber-50 text-amber-700',
                                            'In_Progress' => 'bg-blue-50 text-blue-700',
                                            'Completed' => 'bg-emerald-50 text-emerald-700',
                                            'Overdue' => 'bg-red-50 text-red-700',
                                            default => 'bg-slate-50 text-slate-700'
                                        };
                                        
                                        $priorityColor = match($task['priority']) {
                                            'Critical' => 'text-red-700 bg-red-50',
                                            'High' => 'text-orange-700 bg-orange-50',
                                            'Medium' => 'text-blue-700 bg-blue-50',
                                            default => 'text-slate-700 bg-slate-50'
                                        };
                                        
                                        $dueDate = $task['due_date'] ? new DateTime($task['due_date']) : null;
                                        $isOverdue = $dueDate && $dueDate < new DateTime() && $task['status'] !== 'Completed';
                                        ?>
                                        <tr class="hover:bg-slate-50/70 transition">
                                            <td class="px-8 py-6">
                                                <div>
                                                    <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?></p>
                                                    <?php if ($task['description']): ?>
                                                        <p class="text-xs text-slate-500 mt-1 line-clamp-2"><?= htmlspecialchars(substr($task['description'], 0, 100), ENT_QUOTES, 'UTF-8'); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="px-4 py-6">
                                                <p class="text-sm text-slate-600"><?= htmlspecialchars($task['assigned_by_email'], ENT_QUOTES, 'UTF-8'); ?></p>
                                            </td>
                                            <td class="px-4 py-6">
                                                <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-slate-100 text-slate-700"><?= htmlspecialchars(str_replace('_', ' ', $task['task_type']), ENT_QUOTES, 'UTF-8'); ?></span>
                                            </td>
                                            <td class="px-4 py-6">
                                                <span class="text-xs font-bold px-2.5 py-1 rounded-full <?= $priorityColor; ?>"><?= htmlspecialchars($task['priority'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            </td>
                                            <td class="px-4 py-6 text-sm <?= $isOverdue ? 'text-red-700 font-bold' : 'text-slate-600'; ?>">
                                                <?php if ($dueDate): ?>
                                                    <?= htmlspecialchars($dueDate->format('M d, Y'), ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php if ($isOverdue): ?>
                                                        <span class="block text-[10px] font-bold mt-1">⚠️ OVERDUE</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-6">
                                                <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold <?= $statusClass; ?>">
                                                    <?= htmlspecialchars(str_replace('_', ' ', $task['status']), ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-6 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <?php if ($task['status'] === 'Pending'): ?>
                                                        <form method="POST" class="inline-flex">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-tasks'), ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="task_id" value="<?= (int) $task['task_id']; ?>">
                                                            <input type="hidden" name="task_action" value="start">
                                                            <button type="submit" class="text-xs font-bold px-3 py-1.5 rounded-lg border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100 transition">
                                                                Start
                                                            </button>
                                                        </form>
                                                    <?php elseif ($task['status'] === 'In_Progress'): ?>
                                                        <form method="POST" class="inline-flex">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-tasks'), ENT_QUOTES, 'UTF-8'); ?>">
                                                            <input type="hidden" name="task_id" value="<?= (int) $task['task_id']; ?>">
                                                            <input type="hidden" name="task_action" value="complete">
                                                            <button type="submit" class="text-xs font-bold px-3 py-1.5 rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 transition">
                                                                Complete
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-xs font-semibold text-slate-400">—</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="px-8 py-12 text-center text-sm text-slate-500">
                                            ✓ No assigned tasks. You're all caught up!
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>

            <footer class="bg-white border-t border-slate-200/80 h-16 flex items-center justify-center px-8 text-center">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Smart Rental Core Trust Architecture v4.2.0 • Restricted Institutional Access</p>
            </footer>
        </div>
    </div>
    <script src="js/admin-app.js" defer></script>
</body>
</html>
