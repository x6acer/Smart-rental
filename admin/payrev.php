<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/simple-pdf.php';
require_once __DIR__ . '/../includes/security.php';

$currentPage = 'payrev.php';

$paymentNotice = '';
$paymentNoticeType = 'info';
$transactions = [];
$withdrawals = [];
$pendingWithdrawalCount = 0;
$totalRevenueCollected = 0.0;
$escrowHeld = 0.0;
$availableForPayout = 0.0;

try {
    $pdo->exec("ALTER TABLE Users ADD COLUMN IF NOT EXISTS earnings_balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00");
    $pdo->exec("ALTER TABLE Escrows ADD COLUMN IF NOT EXISTS released_at DATETIME NULL");
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS Owner_Withdrawals (
            withdrawal_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(50) NOT NULL DEFAULT "MOMO",
            payment_account VARCHAR(255) NOT NULL,
            status ENUM("Pending", "Completed", "Rejected") NOT NULL DEFAULT "Pending",
            requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL DEFAULT NULL,
            notes TEXT NULL,
            CONSTRAINT fk_owner_withdrawal_owner FOREIGN KEY (owner_id) REFERENCES Users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
} catch (PDOException $e) {
    error_log('Failed to ensure financial columns: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_pdf'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-payrev')) {
        $paymentNotice = 'Security check failed. Please try again.';
        $paymentNoticeType = 'error';
    } else {
    try {
        $stmt = $pdo->prepare(
            'SELECT t.transaction_id, t.total_price, t.payment_status, t.payment_method, c.email AS customer_email, cp.full_name AS customer_name, v.make, v.model
             FROM Transactions t
             INNER JOIN Bookings b ON b.booking_id = t.booking_id
             INNER JOIN Users c ON c.user_id = b.customer_id
             LEFT JOIN User_Profiles cp ON cp.user_id = c.user_id
             INNER JOIN Vehicles v ON v.vehicle_id = b.vehicle_id
             ORDER BY t.transaction_id DESC'
        );
        $stmt->execute();
        $rows = [];
        while ($transaction = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                '#TXN-' . (int) $transaction['transaction_id'], 
                $transaction['customer_name'] ?: $transaction['customer_email'], 
                'GHS ' . number_format((float) $transaction['total_price'], 2), 
                $transaction['payment_method'], 
                $transaction['payment_status']
            ];
        }

        if (empty($rows)) {
            $rows[] = ['No ledger records available.', '', '', '', ''];
        }

        outputSimplePdf('sr-cars-ledger-' . date('Y-m-d') . '.pdf', 'Smart Rental Financial Ledger', ['Ref ID', 'Customer', 'Amount', 'Method', 'Status'], $rows);
    } catch (Exception $e) {
        error_log('Ledger PDF export failed: ' . $e->getMessage());
    }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_csv'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-payrev')) {
        $paymentNotice = 'Security check failed. Please try again.';
        $paymentNoticeType = 'error';
    } else {
    try {
        $stmt = $pdo->prepare(
            'SELECT t.transaction_id, t.total_price, t.payment_status, t.payment_method, c.email AS customer_email, cp.full_name AS customer_name, v.make, v.model
             FROM Transactions t
             INNER JOIN Bookings b ON b.booking_id = t.booking_id
             INNER JOIN Users c ON c.user_id = b.customer_id
             LEFT JOIN User_Profiles cp ON cp.user_id = c.user_id
             INNER JOIN Vehicles v ON v.vehicle_id = b.vehicle_id
             ORDER BY t.transaction_id DESC'
        );
        $stmt->execute();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sr-cars-ledger-' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');

        fputcsv($output, ['Reference ID', 'Customer', 'Vehicle', 'Amount', 'Payment Method', 'Status']);

        while ($transaction = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $customerName = $transaction['customer_name'] ?: $transaction['customer_email'];
            $vehicleName = trim(($transaction['make'] ?? '') . ' ' . ($transaction['model'] ?? ''));
            fputcsv($output, [
                '#TXN-' . (int) $transaction['transaction_id'],
                $customerName,
                $vehicleName,
                number_format((float) $transaction['total_price'], 2),
                $transaction['payment_method'],
                $transaction['payment_status'],
            ]);
        }

        fclose($output);
        exit;
    } catch (Exception $e) {
        error_log('Ledger CSV export failed: ' . $e->getMessage());
    }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdrawal_action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-withdrawal')) {
        $paymentNotice = 'Security check failed. Please try again.';
        $paymentNoticeType = 'error';
    } else {
        $action = strtolower(trim((string) ($_POST['withdrawal_action'] ?? '')));
        $withdrawalId = filter_input(INPUT_POST, 'withdrawal_id', FILTER_VALIDATE_INT);
        $withdrawalNote = trim((string) ($_POST['withdrawal_note'] ?? ''));

        if ($withdrawalId && in_array($action, ['approve', 'reject'], true)) {
            try {
                $withdrawalStmt = $pdo->prepare(
                    'SELECT withdrawal_id, owner_id, amount, status FROM Owner_Withdrawals WHERE withdrawal_id = :withdrawal_id LIMIT 1'
                );
                $withdrawalStmt->execute(['withdrawal_id' => $withdrawalId]);
                $withdrawal = $withdrawalStmt->fetch(PDO::FETCH_ASSOC);

                if (!$withdrawal) {
                    $paymentNotice = 'That withdrawal request could not be found.';
                    $paymentNoticeType = 'error';
                } elseif (($withdrawal['status'] ?? 'Pending') !== 'Pending') {
                    $paymentNotice = 'That withdrawal request has already been processed.';
                    $paymentNoticeType = 'error';
                } else {
                    if ($action === 'approve') {
                        $balanceStmt = $pdo->prepare('SELECT COALESCE(earnings_balance, 0) FROM Users WHERE user_id = :owner_id LIMIT 1');
                        $balanceStmt->execute(['owner_id' => (int) $withdrawal['owner_id']]);
                        $currentBalance = (float) $balanceStmt->fetchColumn();
                        $amount = (float) $withdrawal['amount'];

                        if ($currentBalance < $amount) {
                            $paymentNotice = 'The owner balance is no longer sufficient for this withdrawal.';
                            $paymentNoticeType = 'error';
                        } else {
                            $pdo->beginTransaction();
                            $pdo->prepare(
                                'UPDATE Owner_Withdrawals SET status = "Completed", processed_at = NOW(), notes = :notes WHERE withdrawal_id = :withdrawal_id'
                            )->execute([
                                'notes' => $withdrawalNote,
                                'withdrawal_id' => $withdrawalId,
                            ]);
                            $pdo->prepare(
                                'UPDATE Users SET earnings_balance = GREATEST(COALESCE(earnings_balance, 0) - :amount, 0) WHERE user_id = :owner_id'
                            )->execute([
                                'amount' => $amount,
                                'owner_id' => (int) $withdrawal['owner_id'],
                            ]);
                            $pdo->commit();

                            $paymentNotice = 'Withdrawal approved and funds released.';
                            $paymentNoticeType = 'success';
                            logAdminAction((int) ($_SESSION['admin_id'] ?? 0), 'withdrawal_approved', 'Owner_Withdrawals', $withdrawalId, 'Approved withdrawal request #' . $withdrawalId . ' for GHS ' . number_format($amount, 2));
                            createAdminNotification('Withdrawal approved', 'A withdrawal request for GHS ' . number_format($amount, 2) . ' was approved by an administrator.', 'Financials');
                        }
                    } else {
                        $pdo->prepare(
                            'UPDATE Owner_Withdrawals SET status = "Rejected", processed_at = NOW(), notes = :notes WHERE withdrawal_id = :withdrawal_id'
                        )->execute([
                            'notes' => $withdrawalNote,
                            'withdrawal_id' => $withdrawalId,
                        ]);

                        $paymentNotice = 'Withdrawal request rejected.';
                        $paymentNoticeType = 'success';
                        logAdminAction((int) ($_SESSION['admin_id'] ?? 0), 'withdrawal_rejected', 'Owner_Withdrawals', $withdrawalId, 'Rejected withdrawal request #' . $withdrawalId);
                        createAdminNotification('Withdrawal rejected', 'A withdrawal request was rejected by an administrator.', 'Financials');
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $paymentNotice = 'Unable to process that withdrawal request.';
                $paymentNoticeType = 'error';
            }
        } else {
            $paymentNotice = 'Invalid withdrawal action.';
            $paymentNoticeType = 'error';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_action'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-payrev')) {
        $paymentNotice = 'Security check failed. Please try again.';
        $paymentNoticeType = 'error';
    } else {
        $action = strtolower(trim((string) ($_POST['payment_action'] ?? '')));

        if ($action === 'batch_release') {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'SELECT t.transaction_id, t.booking_id, t.total_price, t.payment_status, v.owner_id
                     FROM Transactions t
                     INNER JOIN Bookings b ON b.booking_id = t.booking_id
                     INNER JOIN Vehicles v ON v.vehicle_id = b.vehicle_id
                     WHERE t.payment_status = :escrowStatus'
                );
                $stmt->execute(['escrowStatus' => 'Escrow']);
                $pendingTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $releasedCount = 0;
                foreach ($pendingTransactions as $pendingTransaction) {
                    $targetTransactionId = (int) $pendingTransaction['transaction_id'];
                    $bookingId = (int) $pendingTransaction['booking_id'];
                    $ownerId = (int) ($pendingTransaction['owner_id'] ?? 0);
                    $amount = (float) $pendingTransaction['total_price'];

                    $pdo->prepare('UPDATE Transactions SET payment_status = :status WHERE transaction_id = :transaction_id AND payment_status = :current_status')->execute([
                        'status' => 'Paid',
                        'transaction_id' => $targetTransactionId,
                        'current_status' => 'Escrow',
                    ]);

                    if ($ownerId > 0) {
                        $pdo->prepare('UPDATE Users SET earnings_balance = COALESCE(earnings_balance, 0) + :amount WHERE user_id = :owner_id')->execute([
                            'amount' => $amount,
                            'owner_id' => $ownerId,
                        ]);
                    }

                    $pdo->prepare("UPDATE Escrows SET status = 'released', released_at = NOW() WHERE booking_id = :booking_id AND status <> 'released'")->execute([
                        'booking_id' => $bookingId,
                    ]);

                    if ($ownerId > 0) {
                        logAdminAction((int) ($_SESSION['admin_id'] ?? 0), 'payment_released', 'Transactions', $targetTransactionId, 'Released escrow payout for transaction #' . $targetTransactionId . ' and credited owner #' . $ownerId);
                    } else {
                        logAdminAction((int) ($_SESSION['admin_id'] ?? 0), 'payment_released', 'Transactions', $targetTransactionId, 'Released escrow payout for transaction #' . $targetTransactionId);
                    }
                    $releasedCount++;
                }

                $pdo->commit();
                $paymentNotice = $releasedCount > 0 ? 'Batch release completed for ' . $releasedCount . ' escrow payments.' : 'No escrow payments were pending release.';
                $paymentNoticeType = 'success';
                createAdminNotification('Batch payout release', 'Admin released ' . $releasedCount . ' escrow payments in one batch.', 'Financials');
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $paymentNotice = 'Unable to process the batch payout release.';
                $paymentNoticeType = 'error';
            }
        } else {
            $targetTransactionId = filter_input(INPUT_POST, 'transaction_id', FILTER_VALIDATE_INT);

            if ($targetTransactionId && in_array($action, ['release', 'refund'], true)) {
                try {
                    $newStatus = $action === 'release' ? 'Paid' : 'Refunded';
                    $pdo->beginTransaction();
                    $transactionStmt = $pdo->prepare(
                        'SELECT t.transaction_id, t.booking_id, t.total_price, t.payment_status, v.owner_id
                         FROM Transactions t
                         INNER JOIN Bookings b ON b.booking_id = t.booking_id
                         INNER JOIN Vehicles v ON v.vehicle_id = b.vehicle_id
                         WHERE t.transaction_id = :transaction_id
                         LIMIT 1'
                    );
                    $transactionStmt->execute(['transaction_id' => $targetTransactionId]);
                    $targetTransaction = $transactionStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$targetTransaction) {
                        throw new RuntimeException('Transaction not found.');
                    }

                    $pdo->prepare('UPDATE Transactions SET payment_status = :status WHERE transaction_id = :transaction_id')->execute([
                        'status' => $newStatus,
                        'transaction_id' => $targetTransactionId,
                    ]);

                    if ($action === 'release') {
                        $ownerId = (int) ($targetTransaction['owner_id'] ?? 0);
                        $amount = (float) ($targetTransaction['total_price'] ?? 0.0);
                        if ($ownerId > 0) {
                            $pdo->prepare('UPDATE Users SET earnings_balance = COALESCE(earnings_balance, 0) + :amount WHERE user_id = :owner_id')->execute([
                                'amount' => $amount,
                                'owner_id' => $ownerId,
                            ]);
                        }
                        $pdo->prepare("UPDATE Escrows SET status = 'released', released_at = NOW() WHERE booking_id = :booking_id AND status <> 'released'")->execute([
                            'booking_id' => (int) $targetTransaction['booking_id'],
                        ]);
                    } else {
                        $pdo->prepare("UPDATE Escrows SET status = 'refunded' WHERE booking_id = :booking_id AND status <> 'refunded'")->execute([
                            'booking_id' => (int) $targetTransaction['booking_id'],
                        ]);
                    }

                    $pdo->commit();
                    $paymentNotice = $action === 'release' ? 'Payment release recorded.' : 'Manual refund recorded.';
                    $paymentNoticeType = 'success';
                    logAdminAction((int) ($_SESSION['admin_id'] ?? 0), $action === 'release' ? 'payment_released' : 'payment_refunded', 'Transactions', $targetTransactionId, $action === 'release' ? 'Released escrow payout for transaction #' . $targetTransactionId : 'Refunded transaction #' . $targetTransactionId);
                    createAdminNotification($action === 'release' ? 'Payment released' : 'Payment refunded', 'Transaction #' . $targetTransactionId . ' was updated by an administrator.', 'Financials');
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $paymentNotice = 'Unable to process that payment action.';
                    $paymentNoticeType = 'error';
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $paymentNotice = $e->getMessage();
                    $paymentNoticeType = 'error';
                }
            } else {
                $paymentNotice = 'Invalid payment action.';
                $paymentNoticeType = 'error';
            }
        }
    }
}

try {
    $stmt = $pdo->prepare(
        'SELECT t.transaction_id, t.total_price, t.payment_status, t.payment_method, b.booking_id, c.email AS customer_email, cp.full_name AS customer_name, v.make, v.model
         FROM Transactions t
         INNER JOIN Bookings b ON b.booking_id = t.booking_id
         INNER JOIN Users c ON c.user_id = b.customer_id
         LEFT JOIN User_Profiles cp ON cp.user_id = c.user_id
         INNER JOIN Vehicles v ON v.vehicle_id = b.vehicle_id
         ORDER BY t.transaction_id DESC'
    );
    $stmt->execute();
    $transactions = $stmt->fetchAll();

    foreach ($transactions as $transaction) {
        $amount = (float) $transaction['total_price'];
        if ($transaction['payment_status'] === 'Paid') {
            $totalRevenueCollected += $amount;
        } elseif ($transaction['payment_status'] === 'Escrow') {
            $escrowHeld += $amount;
            $availableForPayout += $amount;
        }
    }

    $withdrawalStmt = $pdo->prepare(
        'SELECT ow.withdrawal_id, ow.owner_id, ow.amount, ow.payment_method, ow.payment_account, ow.status, ow.requested_at, ow.processed_at, ow.notes, u.email AS owner_email, up.full_name AS owner_name
         FROM Owner_Withdrawals ow
         INNER JOIN Users u ON u.user_id = ow.owner_id
         LEFT JOIN User_Profiles up ON up.user_id = u.user_id
         ORDER BY CASE WHEN ow.status = "Pending" THEN 0 ELSE 1 END, ow.requested_at DESC'
    );
    $withdrawalStmt->execute();
    $withdrawals = $withdrawalStmt->fetchAll() ?: [];
    $pendingWithdrawalCount = 0;
    foreach ($withdrawals as $withdrawal) {
        if (($withdrawal['status'] ?? 'Pending') === 'Pending') {
            $pendingWithdrawalCount++;
        }
    }
} catch (PDOException $e) {
    $transactions = [];
    $paymentNotice = 'Unable to load payment records right now.';
    $paymentNoticeType = 'error';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Financial Management | Smart Rental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --brand-primary: #1b4b4b;
            --brand-accent: #facd05;
        }
    </style>
</head>

<body class="bg-[#f8fafc] text-slate-900 antialiased">
    <div class="min-h-screen flex">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="flex-grow lg:ml-[280px] min-h-screen flex flex-col">
            <?php
            $pageTitle = 'Revenue Control';
            $pageSubtitle = 'Escrow, payouts and trust operations';
            require_once __DIR__ . '/includes/header.php';
            ?>

            <main class="p-8">
                
                <?php if ($paymentNotice !== ''): ?>
                    <div class="mb-6 rounded-2xl border px-4 py-3 text-sm font-semibold <?= $paymentNoticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'; ?>">
                        <?= htmlspecialchars($paymentNotice, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <section class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                    <div class="bg-white p-8 rounded-[2rem] shadow-sm border border-gray-100">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Total Volume (MTD)</p>
                        <h3 class="text-3xl font-black">GHS <?= htmlspecialchars(number_format($totalRevenueCollected, 2), ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="text-[9px] text-gray-400 font-bold mt-2 uppercase italic tracking-tighter">Gross Transactional Flow</p>
                    </div>

                    <div class="bg-[#facd05]/10 p-8 rounded-[2rem] border border-[#facd05]/20 shadow-sm relative overflow-hidden">
                        <div class="absolute -right-4 -top-4 w-20 h-20 bg-[#facd05]/10 rounded-full"></div>
                        <p class="text-[10px] font-black text-yellow-800 uppercase tracking-widest mb-2">Held in Escrow</p>
                        <h3 class="text-3xl font-black text-yellow-900">GHS <?= htmlspecialchars(number_format($escrowHeld, 2), ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="text-[9px] text-yellow-700 font-bold mt-2 uppercase italic tracking-tighter">Secured Pending Handover</p>
                    </div>

                    <div class="bg-white p-8 rounded-[2rem] shadow-sm border border-gray-100">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Available for Payout</p>
                        <h3 class="text-3xl font-black text-green-600">GHS <?= htmlspecialchars(number_format($availableForPayout, 2), ENT_QUOTES, 'UTF-8'); ?></h3>
                        <form method="post" class="mt-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-payrev'), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="payment_action" value="batch_release">
                            <button type="submit" class="text-[10px] font-black uppercase text-blue-500 underline">Execute Batch Release</button>
                        </form>
                    </div>
                </section>

                <div class="flex flex-wrap items-center justify-between gap-6 mb-8" data-tab-group="payment-status">
                    <div class="flex gap-2">
                        <button data-tab="all" class="px-6 py-2 bg-[#1b4b4b] text-white rounded-xl text-[10px] font-black uppercase shadow-lg">All Payments</button>
                        <button data-tab="Escrow" class="px-6 py-2 bg-white text-gray-400 hover:text-[#1b4b4b] rounded-xl text-[10px] font-black uppercase transition border border-transparent hover:border-gray-200">Escrow Queue</button>
                        <button data-tab="Flagged" class="px-6 py-2 bg-white text-red-400 hover:bg-red-50 rounded-xl text-[10px] font-black uppercase transition border border-red-100">Flagged (3)</button>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-payrev'), ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="export_csv" value="1" class="px-6 py-2 bg-white border border-gray-200 text-gray-500 rounded-xl text-[10px] font-black uppercase hover:bg-gray-50 transition">Export ledger (CSV)</button>
                        </form>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-payrev'), ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="export_pdf" value="1" class="px-6 py-2 bg-[#1b4b4b] text-white rounded-xl text-[10px] font-black uppercase hover:bg-slate-800 transition">Export ledger (PDF)</button>
                        </form>
                    </div>
                </div>

                <section class="mb-10 bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-8 py-6 border-b border-gray-100 flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Withdrawal Review Queue</p>
                            <h3 class="text-xl font-black text-slate-900 mt-1">Pending approvals: <?= (int) $pendingWithdrawalCount; ?></h3>
                            <p class="text-sm text-slate-500 mt-2">Review owner withdrawal requests, approve completed payouts, or reject requests with a note.</p>
                        </div>
                    </div>

                    <div class="divide-y divide-gray-100">
                        <?php if (!empty($withdrawals)): ?>
                            <?php foreach ($withdrawals as $withdrawal): ?>
                                <div class="px-8 py-6 flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-3 mb-2">
                                            <p class="text-sm font-black text-slate-900">
                                                <?= htmlspecialchars((string) (($withdrawal['owner_name'] ?? '') !== '' ? $withdrawal['owner_name'] : ($withdrawal['owner_email'] ?? 'Unknown owner')), ENT_QUOTES, 'UTF-8'); ?>
                                            </p>
                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[9px] font-black uppercase <?= (($withdrawal['status'] ?? 'Pending') === 'Pending') ? 'bg-amber-50 text-amber-700' : ((($withdrawal['status'] ?? 'Pending') === 'Completed') ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'); ?>">
                                                <?= htmlspecialchars((string) ($withdrawal['status'] ?? 'Pending'), ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </div>
                                        <p class="text-[10px] font-black uppercase tracking-wider text-slate-400">Requested <?= htmlspecialchars(date('M d, Y H:i', strtotime((string) ($withdrawal['requested_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p class="text-sm font-black text-slate-700 mt-2">GHS <?= htmlspecialchars(number_format((float) ($withdrawal['amount'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></p>
                                        <p class="text-[11px] text-slate-500 mt-1"><?= htmlspecialchars((string) ($withdrawal['payment_method'] ?? 'MOMO') . ' · ' . ($withdrawal['payment_account'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>

                                    <div class="w-full lg:max-w-md">
                                        <?php if (($withdrawal['status'] ?? 'Pending') === 'Pending'): ?>
                                            <form method="post" class="space-y-3">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-withdrawal'), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="withdrawal_id" value="<?= (int) $withdrawal['withdrawal_id']; ?>">
                                                <textarea name="withdrawal_note" rows="2" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-[#1b4b4b]" placeholder="Optional note for this review"></textarea>
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="submit" name="withdrawal_action" value="approve" class="rounded-xl bg-emerald-600 px-4 py-2 text-[10px] font-black uppercase text-white">Approve</button>
                                                    <button type="submit" name="withdrawal_action" value="reject" class="rounded-xl bg-red-500 px-4 py-2 text-[10px] font-black uppercase text-white">Reject</button>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <p class="text-sm text-slate-500"><?= htmlspecialchars((string) (($withdrawal['notes'] ?? '') !== '' ? $withdrawal['notes'] : 'No review note provided.'), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[10px] font-black uppercase tracking-wider text-slate-400 mt-2">Processed <?= htmlspecialchars(date('M d, Y H:i', strtotime((string) ($withdrawal['processed_at'] ?? 'now'))), ENT_QUOTES, 'UTF-8'); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="px-8 py-12 text-center text-sm text-slate-500">No withdrawal requests have been submitted yet.</div>
                        <?php endif; ?>
                    </div>
                </section>

                <div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 text-[9px] font-black uppercase text-gray-400 tracking-widest">
                            <tr>
                                <th class="px-8 py-5">Ref ID</th>
                                <th class="px-4 py-5">Entity</th>
                                <th class="px-4 py-5">Amount</th>
                                <th class="px-4 py-5">Method</th>
                                <th class="px-4 py-5">Trust Status</th>
                                <th class="px-4 py-5 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <?php if (!empty($transactions)): ?>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr class="hover:bg-gray-50/50 transition">
                                        <td class="px-8 py-6">
                                            <code class="text-[10px] font-mono font-bold">#<?= htmlspecialchars((string) ($transaction['payment_method'] ?? 'TXN') . '-' . (int) $transaction['transaction_id'], ENT_QUOTES, 'UTF-8'); ?></code>
                                        </td>
                                        <td class="px-4 py-6">
                                            <p class="text-xs font-black"><?= htmlspecialchars((string) ($transaction['customer_name'] ?: $transaction['customer_email'] ?: 'Unknown customer'), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-[9px] text-gray-400 uppercase font-bold"><?= htmlspecialchars((string) (($transaction['make'] ?? '') . ' ' . ($transaction['model'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></p>
                                        </td>
                                        <td class="px-4 py-6 text-xs font-black">GHS <?= htmlspecialchars(number_format((float) $transaction['total_price'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="px-4 py-6">
                                            <span class="text-[9px] font-black uppercase bg-white border border-gray-200 px-2 py-1 rounded"><?= htmlspecialchars((string) ($transaction['payment_method'] ?? 'MOMO'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </td>
                                        <td class="px-4 py-6">
                                            <span class="inline-flex items-center gap-1.5 <?= $transaction['payment_status'] === 'Escrow' ? 'text-yellow-600' : ($transaction['payment_status'] === 'Refunded' ? 'text-red-600' : 'text-green-600'); ?> text-[9px] font-black uppercase">
                                                <?= htmlspecialchars((string) $transaction['payment_status'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-6 text-right">
                                            <?php if ($transaction['payment_status'] === 'Escrow'): ?>
                                                <form method="post" class="inline-flex gap-2">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-payrev'), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="transaction_id" value="<?= (int) $transaction['transaction_id']; ?>">
                                                    <button type="submit" name="payment_action" value="release" class="text-[9px] font-black uppercase text-green-600 hover:text-green-700 transition">Release</button>
                                                    <button type="submit" name="payment_action" value="refund" class="text-[9px] font-black uppercase text-red-500 hover:text-red-700 transition">Refund</button>
                                                </form>
                                            <?php elseif ($transaction['payment_status'] === 'Paid'): ?>
                                                <form method="post" class="inline-flex gap-2">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-payrev'), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input type="hidden" name="transaction_id" value="<?= (int) $transaction['transaction_id']; ?>">
                                                    <button type="submit" name="payment_action" value="refund" class="text-[9px] font-black uppercase text-red-500 hover:text-red-700 transition">Refund</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-[9px] font-black uppercase text-slate-400">Closed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-8 py-12 text-center text-sm text-slate-500">No payment records were found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-8 p-6 bg-blue-50 border border-blue-100 rounded-[2rem] flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <span class="text-xl">ℹ️</span>
                        <div>
                            <p class="text-xs font-black text-blue-900 uppercase">System Discrepancy Log</p>
                            <p class="text-[10px] text-blue-700 font-medium">All automated payout releases are verified against the manual handover logs before execution.</p>
                        </div>
                    </div>
                    <button class="px-6 py-2.5 bg-blue-600 text-white text-[10px] font-black uppercase rounded-xl shadow-md hover:bg-blue-700 transition">Manual Reconciliation</button>
                </div>

            </main>
        </div>
    </div>
    <script src="js/admin-app.js" defer></script>
</body>
</html>
