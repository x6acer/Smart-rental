<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/audit.php';

$currentPage = 'insurance.php';
$pageTitle = 'Insurance & Claims';
$pageSubtitle = 'Policy control and claim adjudication';
$headerBadge = 'RISK OPS';
$notice = '';
$noticeType = 'info';
$csrfContext = 'admin-insurance';

function ensureInsuranceTables(PDO $pdo): void
{
    // Try to detect if table has correct schema by actually querying the columns we need
    $hasCorrectSchema = false;
    try {
        $testStmt = $pdo->query('SELECT policy_name, policy_description FROM Insurance_Policies LIMIT 1');
        $hasCorrectSchema = $testStmt !== false;
    } catch (PDOException $e) {
        // Table doesn't exist or has wrong columns
        $hasCorrectSchema = false;
    }
    
    if (!$hasCorrectSchema) {
        // Table is missing or has wrong schema - drop and recreate
        error_log('Recreating Insurance_Policies table - schema missing or incorrect');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $pdo->exec('DROP TABLE IF EXISTS Insurance_Policies');
            $pdo->exec(
                'CREATE TABLE Insurance_Policies (
                    policy_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    policy_name VARCHAR(100) NOT NULL,
                    policy_description TEXT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    base_multiplier DECIMAL(6, 2) NOT NULL DEFAULT 1.00,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_policy_name (policy_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            error_log('Insurance_Policies table created successfully with correct schema');
        } catch (PDOException $e) {
            error_log('Failed to recreate Insurance_Policies table: ' . $e->getMessage());
            throw $e;
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS Claim_Payout_Logs (
            payout_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            claim_id BIGINT UNSIGNED NOT NULL,
            admin_id BIGINT UNSIGNED NULL,
            action VARCHAR(50) NOT NULL,
            payout_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            note TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_claim_payout_claim FOREIGN KEY (claim_id) REFERENCES Claims(claim_id) ON DELETE CASCADE,
            CONSTRAINT fk_claim_payout_admin FOREIGN KEY (admin_id) REFERENCES Users(user_id) ON DELETE SET NULL,
            INDEX idx_claim_log (claim_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensureDefaultInsurancePolicies(PDO $pdo): void
{
    $countStmt = $pdo->query('SELECT COUNT(*) AS policy_count FROM Insurance_Policies');
    $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($countRow) || (int) ($countRow['policy_count'] ?? 0) > 0) {
        return;
    }

    $seedPolicies = [
        ['Basic Protection', 'Standard coverage for minor damage claims.', 1, 1.00],
        ['Premium Shield', 'Enhanced coverage for premium vehicles and high-value bookings.', 1, 1.35],
        ['Comprehensive Cover', 'Expanded protection for premium and executive fleet bookings.', 0, 1.70],
    ];

    $insertStmt = $pdo->prepare(
        'INSERT INTO Insurance_Policies (policy_name, policy_description, is_active, base_multiplier) VALUES (:policy_name, :policy_description, :is_active, :base_multiplier)'
    );

    foreach ($seedPolicies as $policy) {
        $insertStmt->execute([
            'policy_name' => $policy[0],
            'policy_description' => $policy[1],
            'is_active' => $policy[2],
            'base_multiplier' => $policy[3],
        ]);
    }
}

function loadInsurancePolicies(PDO $pdo): array
{
    ensureInsuranceTables($pdo);
    ensureDefaultInsurancePolicies($pdo);

    $stmt = $pdo->query(
        'SELECT policy_id, policy_name, policy_description, is_active, base_multiplier
         FROM Insurance_Policies
         ORDER BY policy_name ASC'
    );

    $policies = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $policies[] = [
            'policy_id' => (int) $row['policy_id'],
            'policy_name' => (string) $row['policy_name'],
            'policy_description' => (string) $row['policy_description'],
            'is_active' => (bool) $row['is_active'],
            'base_multiplier' => (float) $row['base_multiplier'],
        ];
    }

    return $policies;
}

function loadClaims(PDO $pdo): array
{
    ensureInsuranceTables($pdo);

    $stmt = $pdo->query(
        'SELECT c.claim_id, c.booking_id, c.claim_type, c.claim_status, c.claim_amount, c.claim_description,
                c.evidence_photos, c.created_at, c.resolved_at,
                u_customer.email AS customer_email,
                u_owner.email AS owner_email
         FROM Claims c
         LEFT JOIN Users u_customer ON u_customer.user_id = c.customer_id
         LEFT JOIN Users u_owner ON u_owner.user_id = c.owner_id
         WHERE c.claim_status IN ("Open", "Under_Review", "Owner_Responded")
         ORDER BY c.created_at DESC'
    );

    $claims = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $claims[] = [
            'claim_id' => (int) $row['claim_id'],
            'booking_id' => (int) $row['booking_id'],
            'claim_type' => (string) $row['claim_type'],
            'claim_status' => (string) $row['claim_status'],
            'claim_amount' => (float) ($row['claim_amount'] ?? 0),
            'claim_description' => (string) $row['claim_description'],
            'evidence_photos' => (string) ($row['evidence_photos'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'resolved_at' => $row['resolved_at'] !== null ? (string) $row['resolved_at'] : null,
            'customer_email' => $row['customer_email'] !== null ? (string) $row['customer_email'] : 'Pending',
            'owner_email' => $row['owner_email'] !== null ? (string) $row['owner_email'] : 'Pending',
        ];
    }

    return $claims;
}

function loadClaimLogs(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT payout_log_id, claim_id, action, payout_amount, note, created_at
         FROM Claim_Payout_Logs
         ORDER BY created_at DESC'
    );

    $logs = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $claimId = (int) $row['claim_id'];
        $logs[$claimId][] = [
            'action' => (string) $row['action'],
            'payout_amount' => (float) $row['payout_amount'],
            'note' => (string) $row['note'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    return $logs;
}

function decodeEvidenceItems(string $rawValue): array
{
    if (trim($rawValue) === '') {
        return [];
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map(static function ($item): string {
        if (!is_string($item)) {
            return '';
        }

        return trim($item);
    }, $decoded), static function ($item): bool {
        return $item !== '';
    }));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_policies'])) {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null, $csrfContext)) {
            $notice = 'Security check failed. Please try again.';
            $noticeType = 'error';
        } else {
            $policyIds = $_POST['policy_id'] ?? [];
            $activeFlags = $_POST['is_active'] ?? [];
            $multiplierValues = $_POST['base_multiplier'] ?? [];

            try {
                $pdo->beginTransaction();
                foreach ($policyIds as $index => $policyId) {
                    $policyIdValue = (int) $policyId;
                    if ($policyIdValue <= 0) {
                        continue;
                    }

                    $isActiveValue = isset($activeFlags[$index]) ? 1 : 0;
                    $multiplierValue = (float) ($multiplierValues[$index] ?? 1.0);
                    $updateStmt = $pdo->prepare(
                        'UPDATE Insurance_Policies SET is_active = :is_active, base_multiplier = :base_multiplier WHERE policy_id = :policy_id'
                    );
                    $updateStmt->execute([
                        'is_active' => $isActiveValue,
                        'base_multiplier' => $multiplierValue,
                        'policy_id' => $policyIdValue,
                    ]);
                }
                $pdo->commit();
                logAdminActivity('insurance_policies_updated', 'Insurance policy multipliers and active status were updated.', (int) ($_SESSION['admin_id'] ?? 0), 'Insurance_Policies', null);
                $notice = 'Insurance policy settings were updated successfully.';
                $noticeType = 'success';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Failed to update insurance policies: ' . $e->getMessage());
                $notice = 'Unable to update the policy settings right now.';
                $noticeType = 'error';
            }
        }
    } elseif (isset($_POST['update_claim'])) {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null, $csrfContext)) {
            $notice = 'Security check failed. Please try again.';
            $noticeType = 'error';
        } else {
            $claimId = (int) ($_POST['claim_id'] ?? 0);
            $statusValue = trim((string) ($_POST['claim_status'] ?? 'Under_Review'));
            $noteValue = trim((string) ($_POST['claim_note'] ?? ''));
            $payoutAmount = (float) ($_POST['payout_amount'] ?? 0);
            $resolutionMode = trim((string) ($_POST['resolution_mode'] ?? 'no_refund'));

            $normalizedStatus = match ($statusValue) {
                'Approved' => 'Approved',
                'Rejected' => 'Rejected',
                default => 'Under_Review',
            };

            $resolutionType = match ($resolutionMode) {
                'full_refund' => 'Full_Refund',
                'partial_refund' => 'Partial_Refund',
                'platform_arbitration' => 'Platform_Arbitration',
                default => 'No_Refund',
            };

            try {
                $pdo->beginTransaction();

                $claimLookupStmt = $pdo->prepare('SELECT claim_amount, booking_id FROM Claims WHERE claim_id = :claim_id LIMIT 1');
                $claimLookupStmt->execute(['claim_id' => $claimId]);
                $claimLookup = $claimLookupStmt->fetch(PDO::FETCH_ASSOC);

                $effectivePayout = $payoutAmount;
                if ($effectivePayout <= 0 && is_array($claimLookup) && isset($claimLookup['claim_amount'])) {
                    $effectivePayout = max(0.0, (float) $claimLookup['claim_amount']);
                }

                $updateStmt = $pdo->prepare(
                    'UPDATE Claims SET claim_status = :claim_status, resolution_type = :resolution_type, resolved_at = :resolved_at WHERE claim_id = :claim_id'
                );
                $updateStmt->execute([
                    'claim_status' => $normalizedStatus,
                    'resolution_type' => $resolutionType,
                    'resolved_at' => in_array($normalizedStatus, ['Approved', 'Rejected'], true) ? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') : null,
                    'claim_id' => $claimId,
                ]);

                $shouldProcessRefund = $normalizedStatus === 'Approved' && in_array($resolutionType, ['Full_Refund', 'Partial_Refund', 'Platform_Arbitration'], true) && $effectivePayout > 0;
                if ($shouldProcessRefund && is_array($claimLookup)) {
                    $bookingId = isset($claimLookup['booking_id']) ? (int) $claimLookup['booking_id'] : 0;
                    $transactionStmt = $pdo->prepare(
                        'SELECT transaction_id, booking_id, total_price, payment_status FROM Transactions WHERE booking_id = :booking_id LIMIT 1'
                    );
                    $transactionStmt->execute(['booking_id' => $bookingId]);
                    $transaction = $transactionStmt->fetch(PDO::FETCH_ASSOC);

                    if ($transaction !== false) {
                        $pdo->prepare('UPDATE Transactions SET payment_status = :status WHERE transaction_id = :transaction_id')->execute([
                            'status' => 'Refunded',
                            'transaction_id' => (int) $transaction['transaction_id'],
                        ]);
                        $pdo->prepare("UPDATE Escrows SET status = 'refunded' WHERE booking_id = :booking_id AND status <> 'refunded'")->execute([
                            'booking_id' => $bookingId,
                        ]);
                    }
                }

                if ($noteValue !== '' || $effectivePayout > 0) {
                    $logStmt = $pdo->prepare(
                        'INSERT INTO Claim_Payout_Logs (claim_id, admin_id, action, payout_amount, note)
                         VALUES (:claim_id, :admin_id, :action, :payout_amount, :note)'
                    );
                    $logStmt->execute([
                        'claim_id' => $claimId,
                        'admin_id' => (int) ($_SESSION['admin_id'] ?? 0),
                        'action' => $normalizedStatus,
                        'payout_amount' => $effectivePayout,
                        'note' => $noteValue !== '' ? $noteValue : 'Claim review updated by admin.',
                    ]);
                }

                $pdo->commit();
                logAdminActivity('claim_status_updated', 'Claim ' . $claimId . ' was updated to ' . $normalizedStatus . ' with ' . $resolutionType . '.', (int) ($_SESSION['admin_id'] ?? 0), 'Claims', $claimId);
                $notice = 'Claim review workflow updated.';
                $noticeType = 'success';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Failed to update claim workflow: ' . $e->getMessage());
                $notice = 'Unable to update the claim workflow at the moment.';
                $noticeType = 'error';
            }
        }
    }
}

$insurancePolicies = loadInsurancePolicies($pdo);
$claims = loadClaims($pdo);
$claimLogs = loadClaimLogs($pdo);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken($csrfContext), ENT_QUOTES, 'UTF-8'); ?>" />
    <title>Insurance & Claims | Smart Rental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root { --brand-primary: #1b4b4b; --brand-accent: #facd05; }
        body { background: #f8fafc; }
    </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
    <div class="flex min-h-screen">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="flex-1 lg:ml-[280px] px-6 py-8 lg:px-8">
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <section class="mt-8 space-y-8">
                <?php if ($notice !== ''): ?>
                    <div class="rounded-2xl border px-4 py-3 text-sm font-semibold <?= $noticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'; ?>" data-alert-container>
                        <?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
                    <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-[0.35em] text-slate-400">Policy management</p>
                                <h3 class="mt-2 text-xl font-black text-slate-900">Insurance coverage matrix</h3>
                            </div>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-[10px] font-black uppercase tracking-[0.25em] text-emerald-700">Active</span>
                        </div>

                        <form method="post" class="mt-6 space-y-4" data-validate>
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken($csrfContext), ENT_QUOTES, 'UTF-8'); ?>" />
                            <input type="hidden" name="save_policies" value="1" />
                            <?php foreach ($insurancePolicies as $policy): ?>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <input type="hidden" name="policy_id[]" value="<?= (int) $policy['policy_id']; ?>" />
                                                <span class="text-sm font-black text-slate-900"><?= htmlspecialchars($policy['policy_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="rounded-full <?= $policy['is_active'] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'; ?> px-2 py-0.5 text-[10px] font-black uppercase tracking-wider">
                                                    <?= $policy['is_active'] ? 'Enabled' : 'Disabled'; ?>
                                                </span>
                                            </div>
                                            <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($policy['policy_description'], ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                            <input type="checkbox" name="is_active[]" value="1" <?= $policy['is_active'] ? 'checked' : ''; ?> class="h-4 w-4 rounded border-slate-300 text-[#1b4b4b] focus:ring-[#1b4b4b]" />
                                            Enabled
                                        </label>
                                    </div>
                                    <div class="mt-4 grid gap-3 md:grid-cols-[1fr_auto]">
                                        <label class="block text-sm text-slate-600">
                                            <span class="mb-2 block text-[10px] font-black uppercase tracking-[0.3em] text-slate-400">Daily base multiplier</span>
                                            <input type="number" step="0.05" min="0.5" max="5" name="base_multiplier[]" value="<?= htmlspecialchars((string) $policy['base_multiplier'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 outline-none focus:border-[#1b4b4b]" />
                                        </label>
                                        <div class="flex items-end">
                                            <button type="submit" class="rounded-2xl bg-[#1b4b4b] px-4 py-3 text-sm font-black uppercase tracking-[0.2em] text-white">Save</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </form>
                    </section>

                    <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
                        <p class="text-[10px] font-black uppercase tracking-[0.35em] text-slate-400">Operational snapshot</p>
                        <h3 class="mt-2 text-xl font-black text-slate-900">Claims funnel</h3>
                        <div class="mt-6 space-y-4">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p class="text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">Open claims</p>
                                <p class="mt-2 text-3xl font-black text-slate-900"><?= count($claims); ?></p>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p class="text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">Protected policies</p>
                                <p class="mt-2 text-3xl font-black text-slate-900"><?= count(array_filter($insurancePolicies, static function ($policy): bool { return $policy['is_active']; })); ?></p>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <p class="text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">Audit trail</p>
                                <p class="mt-2 text-sm leading-6 text-slate-600">Administrative actions are written to the secure audit log with every claim update and policy change.</p>
                            </div>
                        </div>
                    </section>
                </div>

                <section class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.35em] text-slate-400">Claims processing pipeline</p>
                            <h3 class="mt-2 text-xl font-black text-slate-900">Active claim review queue</h3>
                        </div>
                        <div class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[10px] font-black uppercase tracking-[0.25em] text-slate-600">Secure workflow</div>
                    </div>

                    <div class="mt-6 space-y-6">
                        <?php if ($claims === []): ?>
                            <div class="empty-state px-6 py-10 text-sm">
                                <div class="es-icon">🩺</div>
                                <div>No active claims are awaiting review right now.</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($claims as $claim): ?>
                                <?php $claimEvidence = decodeEvidenceItems($claim['evidence_photos']); ?>
                                <div class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5" data-claim-card="<?= (int) $claim['claim_id']; ?>">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="rounded-full bg-[#1b4b4b] px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.25em] text-white"><?= htmlspecialchars($claim['claim_type'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="rounded-full bg-amber-100 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.25em] text-amber-700" data-claim-status-display="<?= (int) $claim['claim_id']; ?>"><?= htmlspecialchars($claim['claim_status'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                            <p class="mt-3 text-sm font-semibold text-slate-700">Booking #<?= (int) $claim['booking_id']; ?> · Customer <?= htmlspecialchars($claim['customer_email'], ENT_QUOTES, 'UTF-8'); ?> · Owner <?= htmlspecialchars($claim['owner_email'], ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="mt-2 text-sm leading-6 text-slate-600"><?= nl2br(htmlspecialchars($claim['claim_description'], ENT_QUOTES, 'UTF-8')); ?></p>
                                        </div>
                                        <div class="text-right text-sm text-slate-500">
                                            <p class="font-semibold text-slate-700">Amount: <?= htmlspecialchars(number_format($claim['claim_amount'], 2), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="mt-1">Opened <?= htmlspecialchars((string) $claim['created_at'], ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                    </div>

                                    <?php if ($claimEvidence !== []): ?>
                                        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-3">
                                            <p class="text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">Evidence</p>
                                            <ul class="mt-2 space-y-1 text-sm text-slate-600">
                                                <?php foreach ($claimEvidence as $evidencePath): ?>
                                                    <?php
                                                        $normalizedPath = ltrim(str_replace('\\', '/', $evidencePath), '/');
                                                        if (preg_match('#^(uploads/[^/]+)/#', $normalizedPath, $matches)) {
                                                            $serveUrl = '../serve-document.php?file=' . urlencode($normalizedPath);
                                                        } else {
                                                            $serveUrl = '../serve-document.php?file=' . urlencode('uploads/documents/' . basename($normalizedPath));
                                                        }
                                                    ?>
                                                    <li><a href="<?= htmlspecialchars($serveUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="font-semibold text-[#1b4b4b] hover:underline"><?= htmlspecialchars(basename($normalizedPath), ENT_QUOTES, 'UTF-8'); ?></a></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>

                                    <form method="post" class="mt-5 grid gap-4 lg:grid-cols-[1.1fr_0.4fr_0.4fr]" data-claim-form>
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken($csrfContext), ENT_QUOTES, 'UTF-8'); ?>" />
                                        <input type="hidden" name="update_claim" value="1" />
                                        <input type="hidden" name="claim_id" value="<?= (int) $claim['claim_id']; ?>" />
                                        <label class="block text-sm text-slate-600">
                                            <span class="mb-2 block text-[10px] font-black uppercase tracking-[0.3em] text-slate-400">Review note</span>
                                            <textarea name="claim_note" rows="3" class="js-claim-note w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-[#1b4b4b]" placeholder="Add notes for the review log..."></textarea>
                                        </label>
                                        <label class="block text-sm text-slate-600">
                                            <span class="mb-2 block text-[10px] font-black uppercase tracking-[0.3em] text-slate-400">Decision</span>
                                            <select name="claim_status" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 outline-none focus:border-[#1b4b4b]">
                                                <option value="Under_Review" <?= $claim['claim_status'] === 'Under_Review' ? 'selected' : ''; ?>>Under Review</option>
                                                <option value="Approved" <?= $claim['claim_status'] === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                                <option value="Rejected" <?= $claim['claim_status'] === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                            </select>
                                        </label>
                                        <label class="block text-sm text-slate-600">
                                            <span class="mb-2 block text-[10px] font-black uppercase tracking-[0.3em] text-slate-400">Resolution</span>
                                            <select name="resolution_mode" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 outline-none focus:border-[#1b4b4b]">
                                                <option value="no_refund">No refund</option>
                                                <option value="full_refund">Full refund</option>
                                                <option value="partial_refund">Partial refund</option>
                                                <option value="platform_arbitration">Platform arbitration</option>
                                            </select>
                                        </label>
                                        <label class="block text-sm text-slate-600">
                                            <span class="mb-2 block text-[10px] font-black uppercase tracking-[0.3em] text-slate-400">Payout amount</span>
                                            <input type="number" step="0.01" min="0" name="payout_amount" value="0.00" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 outline-none focus:border-[#1b4b4b]" />
                                        </label>
                                        <div class="lg:col-span-3 flex flex-wrap gap-3">
                                            <button type="submit" class="rounded-2xl bg-[#1b4b4b] px-4 py-3 text-sm font-black uppercase tracking-[0.2em] text-white">Save review</button>
                                            <button type="button" class="js-claim-action rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-black uppercase tracking-[0.2em] text-emerald-700" data-claim-id="<?= (int) $claim['claim_id']; ?>" data-claim-action="approve">Approve</button>
                                            <button type="button" class="js-claim-action rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-black uppercase tracking-[0.2em] text-red-700" data-claim-id="<?= (int) $claim['claim_id']; ?>" data-claim-action="reject">Reject</button>
                                        </div>
                                    </form>

                                    <?php $claimHistory = $claimLogs[(int) $claim['claim_id']] ?? []; ?>
                                    <?php if ($claimHistory !== []): ?>
                                        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-3">
                                            <p class="text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">Payout log</p>
                                            <ul class="mt-2 space-y-2 text-sm text-slate-600">
                                                <?php foreach ($claimHistory as $historyItem): ?>
                                                    <li class="rounded-xl bg-slate-50 px-3 py-2">
                                                        <span class="font-semibold text-slate-700"><?= htmlspecialchars($historyItem['action'], ENT_QUOTES, 'UTF-8'); ?></span> · <?= htmlspecialchars(number_format($historyItem['payout_amount'], 2), ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars($historyItem['note'], ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars($historyItem['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </section>
        </main>
    </div>

    <script src="js/admin-app.js" defer></script>
</body>
</html>
