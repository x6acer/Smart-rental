<?php

require_once __DIR__ . '/../../db.php';

function ensureAdminAuditTable(): void
{
    global $pdo;

    if (!isset($pdo)) {
        return;
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS Audit_Logs (
                log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_id BIGINT UNSIGNED NULL,
                action_type VARCHAR(100) NOT NULL,
                target_table VARCHAR(100) NULL,
                target_id BIGINT UNSIGNED NULL,
                details TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_audit_logs_admin FOREIGN KEY (admin_id) REFERENCES Users(user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS Admin_Audit_Log (
                log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_id BIGINT UNSIGNED NULL,
                action VARCHAR(100) NOT NULL,
                entity_type VARCHAR(100) NULL,
                entity_id BIGINT UNSIGNED NULL,
                details TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES Users(user_id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (PDOException $e) {
        error_log('Failed to create admin audit tables: ' . $e->getMessage());
    }
}

function ensureAdminNotificationTable(): void
{
    global $pdo;

    if (!isset($pdo)) {
        return;
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS Admin_Notifications (
                notification_id INT AUTO_INCREMENT PRIMARY KEY,
                recipient_role VARCHAR(50) NOT NULL DEFAULT "Admin",
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                notification_type VARCHAR(50) NOT NULL DEFAULT "System",
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (PDOException $e) {
        error_log('Failed to create admin notification table: ' . $e->getMessage());
    }
}

function createAdminNotification(string $title, string $message, string $notificationType = 'System', string $recipientRole = 'Admin'): void
{
    global $pdo;

    if (!isset($pdo)) {
        return;
    }

    ensureAdminNotificationTable();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO Admin_Notifications (recipient_role, title, message, notification_type, is_read)
             VALUES (:recipient_role, :title, :message, :notification_type, :is_read)'
        );
        $stmt->execute([
            'recipient_role' => $recipientRole,
            'title' => $title,
            'message' => $message,
            'notification_type' => $notificationType,
            'is_read' => 0,
        ]);
    } catch (PDOException $e) {
        error_log('Failed to create admin notification: ' . $e->getMessage());
    }
}

function markAdminNotificationRead(int $notificationId): void
{
    global $pdo;

    if (!isset($pdo)) {
        return;
    }

    try {
        $stmt = $pdo->prepare('UPDATE Admin_Notifications SET is_read = 1 WHERE notification_id = :notification_id');
        $stmt->execute(['notification_id' => $notificationId]);
    } catch (PDOException $e) {
        error_log('Failed to mark admin notification read: ' . $e->getMessage());
    }
}

function getAdminUnreadNotificationCount(string $recipientRole = 'Admin'): int
{
    global $pdo;

    if (!isset($pdo)) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS unread_count
             FROM Admin_Notifications
             WHERE recipient_role = :recipient_role
               AND is_read = 0'
        );
        $stmt->execute(['recipient_role' => $recipientRole]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return isset($row['unread_count']) ? (int)$row['unread_count'] : 0;
    } catch (PDOException $e) {
        error_log('Failed to count unread admin notifications: ' . $e->getMessage());
        return 0;
    }
}

function logAdminAction($admin_id, $action_type, $target_table, $target_id, $details): void
{
    global $pdo;

    if (!isset($pdo)) {
        return;
    }

    ensureAdminAuditTable();

    $adminId = max(0, (int) $admin_id);
    $actionType = trim((string) $action_type);
    $targetTable = trim((string) $target_table);
    $targetId = $target_id !== null && $target_id !== '' ? (int) $target_id : null;
    $detailsText = trim((string) $details);

    if ($actionType === '') {
        $actionType = 'admin_action';
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO Audit_Logs (admin_id, action_type, target_table, target_id, details)
             VALUES (:admin_id, :action_type, :target_table, :target_id, :details)'
        );
        $stmt->execute([
            'admin_id' => $adminId > 0 ? $adminId : null,
            'action_type' => $actionType,
            'target_table' => $targetTable !== '' ? $targetTable : null,
            'target_id' => $targetId !== null && $targetId > 0 ? $targetId : null,
            'details' => $detailsText !== '' ? $detailsText : null,
        ]);
    } catch (PDOException $e) {
        error_log('Failed to write admin audit log: ' . $e->getMessage());
    }

    try {
        $legacyStmt = $pdo->prepare(
            'INSERT INTO Admin_Audit_Log (admin_id, action, entity_type, entity_id, details)
             VALUES (:admin_id, :action, :entity_type, :entity_id, :details)'
        );
        $legacyStmt->execute([
            'admin_id' => $adminId > 0 ? $adminId : null,
            'action' => $actionType,
            'entity_type' => $targetTable !== '' ? $targetTable : null,
            'entity_id' => $targetId !== null && $targetId > 0 ? $targetId : null,
            'details' => $detailsText !== '' ? $detailsText : null,
        ]);
    } catch (PDOException $e) {
        error_log('Failed to write legacy admin audit log entry: ' . $e->getMessage());
    }
}

function logAdminActivity(string $action, string $details = '', ?int $adminId = null, ?string $entityType = null, ?int $entityId = null): void
{
    logAdminAction(
        $adminId ?? (int) ($_SESSION['admin_id'] ?? 0),
        $action,
        $entityType ?? 'General',
        $entityId,
        $details
    );
}

function ensureSupportCaseNotesTable(): void
{
    global $pdo;

    if (!isset($pdo)) {
        return;
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS Support_Case_Notes (
                note_id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id BIGINT UNSIGNED NOT NULL,
                admin_id INT NULL,
                note_content TEXT NOT NULL,
                escalation_level VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (ticket_id) REFERENCES Support_Tickets(ticket_id) ON DELETE CASCADE,
                INDEX idx_ticket (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (PDOException $e) {
        error_log('Failed to create support case notes table: ' . $e->getMessage());
    }
}

function ensureSupportTicketSchema(): void
{
    global $pdo;

    if (!isset($pdo)) {
        return;
    }

    try {
        $pdo->exec(
            'ALTER TABLE Support_Tickets
             ADD COLUMN IF NOT EXISTS escalation_level VARCHAR(50) DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS assigned_admin_id INT DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS resolved_at DATETIME DEFAULT NULL'
        );
    } catch (PDOException $e) {
        error_log('Failed to ensure support ticket schema: ' . $e->getMessage());
    }
}

function addSupportCaseNote(int $ticketId, string $noteContent, ?string $escalationLevel = null, ?int $adminId = null): void
{
    global $pdo;

    if (!isset($pdo)) {
        return;
    }

    ensureSupportCaseNotesTable();

    $adminId = $adminId ?? (int) ($_SESSION['admin_id'] ?? 0);
    $noteContent = trim($noteContent);

    if ($noteContent === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO Support_Case_Notes (ticket_id, admin_id, note_content, escalation_level)
             VALUES (:ticket_id, :admin_id, :note_content, :escalation_level)'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'admin_id' => $adminId > 0 ? $adminId : null,
            'note_content' => $noteContent,
            'escalation_level' => $escalationLevel,
        ]);
    } catch (PDOException $e) {
        error_log('Failed to add support case note: ' . $e->getMessage());
    }
}

function getSupportCaseNotes(int $ticketId): array
{
    global $pdo;

    if (!isset($pdo)) {
        return [];
    }

    ensureSupportCaseNotesTable();

    try {
        $stmt = $pdo->prepare(
            'SELECT note_id, admin_id, note_content, escalation_level, created_at
             FROM Support_Case_Notes
             WHERE ticket_id = :ticket_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['ticket_id' => $ticketId]);
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('Failed to retrieve support case notes: ' . $e->getMessage());
        return [];
    }
}

function ensureVehicleInspectionNotesTable(): void
{
    global $pdo;

    if (!isset($pdo)) {
        return;
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS Vehicle_Inspection_Notes (
                inspection_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vehicle_id BIGINT UNSIGNED NOT NULL,
                admin_id BIGINT UNSIGNED NOT NULL,
                note_content LONGTEXT NOT NULL,
                document_type VARCHAR(100),
                inspection_status ENUM("Pending", "Approved", "Rejected") DEFAULT "Pending",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (vehicle_id) REFERENCES Vehicles(vehicle_id),
                FOREIGN KEY (admin_id) REFERENCES Users(user_id)
            )'
        );
    } catch (PDOException $e) {
        error_log('Failed to create Vehicle_Inspection_Notes table: ' . $e->getMessage());
    }
}

function addVehicleInspectionNote(int $vehicleId, string $content, string $documentType, int $adminId, string $inspectionStatus = 'Pending'): bool
{
    global $pdo;

    if (!isset($pdo)) {
        return false;
    }

    ensureVehicleInspectionNotesTable();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO Vehicle_Inspection_Notes (vehicle_id, admin_id, note_content, document_type, inspection_status)
             VALUES (:vehicle_id, :admin_id, :note_content, :document_type, :inspection_status)'
        );
        $stmt->execute([
            'vehicle_id' => $vehicleId,
            'admin_id' => $adminId,
            'note_content' => $content,
            'document_type' => $documentType,
            'inspection_status' => $inspectionStatus,
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('Failed to add vehicle inspection note: ' . $e->getMessage());
        return false;
    }
}

function getVehicleInspectionNotes(int $vehicleId): array
{
    global $pdo;

    if (!isset($pdo)) {
        return [];
    }

    ensureVehicleInspectionNotesTable();

    try {
        $stmt = $pdo->prepare(
            'SELECT inspection_id, vehicle_id, admin_id, note_content, document_type, inspection_status, created_at
             FROM Vehicle_Inspection_Notes
             WHERE vehicle_id = :vehicle_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['vehicle_id' => $vehicleId]);
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('Failed to retrieve vehicle inspection notes: ' . $e->getMessage());
        return [];
    }
}

function ensureAdminTaskTable(): void
{
    global $pdo;

    if (!isset($pdo)) {
        return;
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS Admin_Tasks (
                task_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                assigned_by BIGINT UNSIGNED NOT NULL,
                assigned_to BIGINT UNSIGNED NOT NULL,
                task_type VARCHAR(100) NOT NULL,
                entity_type VARCHAR(100),
                entity_id BIGINT UNSIGNED,
                title VARCHAR(255) NOT NULL,
                description LONGTEXT,
                priority ENUM("Low", "Medium", "High", "Critical") DEFAULT "Medium",
                status ENUM("Pending", "In_Progress", "Completed", "Overdue") DEFAULT "Pending",
                due_date DATETIME,
                completed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (assigned_by) REFERENCES Users(user_id),
                FOREIGN KEY (assigned_to) REFERENCES Users(user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (PDOException $e) {
        error_log('Failed to create Admin_Tasks table: ' . $e->getMessage());
    }
}

function assignAdminTask(int $assignedBy, int $assignedTo, string $taskType, string $title, string $description = '', string $entityType = '', int $entityId = 0, string $priority = 'Medium', ?string $dueDate = null): bool
{
    global $pdo;

    if (!isset($pdo)) {
        return false;
    }

    ensureAdminTaskTable();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO Admin_Tasks (assigned_by, assigned_to, task_type, title, description, entity_type, entity_id, priority, due_date)
             VALUES (:assigned_by, :assigned_to, :task_type, :title, :description, :entity_type, :entity_id, :priority, :due_date)'
        );
        $stmt->execute([
            'assigned_by' => $assignedBy,
            'assigned_to' => $assignedTo,
            'task_type' => $taskType,
            'title' => $title,
            'description' => $description,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'priority' => $priority,
            'due_date' => $dueDate,
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('Failed to assign admin task: ' . $e->getMessage());
        return false;
    }
}

function getAdminTasksAssignedTo(int $adminId, string $status = ''): array
{
    global $pdo;

    if (!isset($pdo)) {
        return [];
    }

    ensureAdminTaskTable();

    try {
        $query = 'SELECT t.task_id, t.assigned_by, t.task_type, t.entity_type, t.entity_id, t.title, t.description, t.priority, t.status, t.due_date, t.created_at, a.email AS assigned_by_email FROM Admin_Tasks t JOIN Users a ON a.user_id = t.assigned_by WHERE t.assigned_to = :admin_id';
        
        if ($status !== '') {
            $query .= ' AND t.status = :status';
        }
        
        $query .= ' ORDER BY CASE WHEN t.priority = "Critical" THEN 0 WHEN t.priority = "High" THEN 1 WHEN t.priority = "Medium" THEN 2 ELSE 3 END, t.due_date ASC';
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($status !== '' ? ['admin_id' => $adminId, 'status' => $status] : ['admin_id' => $adminId]);
        return $stmt->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('Failed to retrieve admin tasks: ' . $e->getMessage());
        return [];
    }
}

function updateTaskStatus(int $taskId, string $newStatus): bool
{
    global $pdo;

    if (!isset($pdo)) {
        return false;
    }

    try {
        $completedAt = $newStatus === 'Completed' ? date('Y-m-d H:i:s') : null;
        $stmt = $pdo->prepare('UPDATE Admin_Tasks SET status = :status, completed_at = :completed_at WHERE task_id = :task_id');
        $stmt->execute([
            'status' => $newStatus,
            'completed_at' => $completedAt,
            'task_id' => $taskId,
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('Failed to update task status: ' . $e->getMessage());
        return false;
    }
}
