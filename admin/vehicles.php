<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/security.php';

$vehicleNotice = '';
$vehicleNoticeType = 'info';
$pendingVehicles = [];
$selectedVehicleId = null;
$vehicleDetails = null;
$inspectionNotes = [];

// Handle inspection note addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_inspection_note'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-vehicles')) {
        $vehicleNotice = 'Security check failed. Please try again.';
        $vehicleNoticeType = 'error';
    } else {
        $vehicleId = filter_input(INPUT_POST, 'vehicle_id', FILTER_VALIDATE_INT);
        $noteContent = trim((string) ($_POST['inspection_note_content'] ?? ''));
        $documentType = trim((string) ($_POST['document_type'] ?? ''));
        
        if ($vehicleId && $noteContent && $documentType) {
            if (addVehicleInspectionNote($vehicleId, $noteContent, $documentType, (int) ($_SESSION['admin_id'] ?? 0))) {
                $vehicleNotice = 'Inspection note added successfully.';
                $vehicleNoticeType = 'success';
                logAdminAction((int) ($_SESSION['admin_id'] ?? 0), 'vehicle_inspection_note', 'Vehicles', $vehicleId, 'Admin added inspection note for vehicle ' . $vehicleId);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vehicle_action'], $_POST['vehicle_id'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null, 'admin-vehicles')) {
        $vehicleNotice = 'Security check failed. Please try again.';
        $vehicleNoticeType = 'error';
    } else {
        $action = strtolower(trim((string) ($_POST['vehicle_action'] ?? '')));
        $targetVehicleId = filter_input(INPUT_POST, 'vehicle_id', FILTER_VALIDATE_INT);

        if ($targetVehicleId && in_array($action, ['approve', 'reject'], true)) {
            try {
                $pdo->beginTransaction();

            if ($action === 'approve') {
                $stmt = $pdo->prepare('UPDATE Vehicles SET status = :status WHERE vehicle_id = :vehicle_id');
                $stmt->execute([
                    'status' => 'Idle',
                    'vehicle_id' => $targetVehicleId,
                ]);

                $verificationCheck = $pdo->prepare('SELECT 1 FROM Vehicle_Verifications WHERE vehicle_id = :vehicle_id LIMIT 1');
                $verificationCheck->execute(['vehicle_id' => $targetVehicleId]);

                if ($verificationCheck->fetch()) {
                    $verificationStmt = $pdo->prepare('UPDATE Vehicle_Verifications SET verification_status = :status WHERE vehicle_id = :vehicle_id');
                    $verificationStmt->execute([
                        'status' => 'Verified',
                        'vehicle_id' => $targetVehicleId,
                    ]);
                } else {
                    $insertVerificationStmt = $pdo->prepare('INSERT INTO Vehicle_Verifications (vehicle_id, verification_status) VALUES (:vehicle_id, :status)');
                    $insertVerificationStmt->execute([
                        'vehicle_id' => $targetVehicleId,
                        'status' => 'Verified',
                    ]);
                }

                $vehicleNotice = 'Vehicle approved successfully.';
                $vehicleNoticeType = 'success';
                logAdminAction((int) ($_SESSION['admin_id'] ?? 0), 'vehicle_approved', 'Vehicles', $targetVehicleId, 'Admin approved vehicle ' . $targetVehicleId);
                createAdminNotification('Vehicle approved', 'Vehicle #' . $targetVehicleId . ' passed admin verification and is now live.', 'Fleet');
            } else {
                $stmt = $pdo->prepare('UPDATE Vehicle_Verifications SET verification_status = :status WHERE vehicle_id = :vehicle_id');
                $stmt->execute([
                    'status' => 'Rejected',
                    'vehicle_id' => $targetVehicleId,
                ]);

                $vehicleNotice = 'Vehicle rejected successfully.';
                $vehicleNoticeType = 'error';
                logAdminAction((int) ($_SESSION['admin_id'] ?? 0), 'vehicle_rejected', 'Vehicles', $targetVehicleId, 'Admin rejected vehicle ' . $targetVehicleId);
                createAdminNotification('Vehicle rejected', 'Vehicle #' . $targetVehicleId . ' was rejected during admin verification.', 'Fleet');
            }

                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $vehicleNotice = 'Unable to process the requested vehicle action.';
                $vehicleNoticeType = 'error';
            }
        } else {
            $vehicleNotice = 'Invalid request.';
            $vehicleNoticeType = 'error';
        }
    }
}

// Check for vehicle review request
$selectedVehicleId = isset($_GET['review_vehicle']) ? (int) $_GET['review_vehicle'] : null;

try {
    $stmt = $pdo->prepare(
        "SELECT v.vehicle_id, v.vin, v.make, v.model, v.year, v.status, v.owner_id, COALESCE(vv.verification_status, 'Pending') AS verification_status, u.email AS owner_email, p.full_name AS owner_name
         FROM Vehicles v
         LEFT JOIN Vehicle_Verifications vv ON vv.vehicle_id = v.vehicle_id
         LEFT JOIN Users u ON u.user_id = v.owner_id
         LEFT JOIN User_Profiles p ON p.user_id = v.owner_id
         WHERE COALESCE(vv.verification_status, 'Pending') = 'Pending'
         ORDER BY v.vehicle_id DESC"
    );
    $stmt->execute();
    $pendingVehicles = $stmt->fetchAll();
    
    // Load selected vehicle details if review_vehicle parameter is set
    if ($selectedVehicleId) {
        $detailStmt = $pdo->prepare(
            'SELECT v.vehicle_id, v.vin, v.make, v.model, v.year, v.status, v.owner_id, COALESCE(vv.verification_status, "Pending") AS verification_status, u.email AS owner_email, p.full_name AS owner_name, p.phone_number
             FROM Vehicles v
             LEFT JOIN Vehicle_Verifications vv ON vv.vehicle_id = v.vehicle_id
             LEFT JOIN Users u ON u.user_id = v.owner_id
             LEFT JOIN User_Profiles p ON p.user_id = v.owner_id
             WHERE v.vehicle_id = :vehicleId'
        );
        $detailStmt->execute([
            'vehicleId' => $selectedVehicleId,
        ]);
        $vehicleDetails = $detailStmt->fetch();
        
        if ($vehicleDetails) {
            $inspectionNotes = getVehicleInspectionNotes($selectedVehicleId);
        }
    }
} catch (PDOException $e) {
    $pendingVehicles = [];
    $vehicleNotice = 'Unable to load pending vehicles at this time.';
    $vehicleNoticeType = 'error';
    error_log('Admin pending vehicles load error: ' . $e->getMessage());
    error_log('Admin pending vehicles load error: ' . $e->getMessage());
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Fleet Verification & Management | Smart Rental Admin</title>
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
        <?php $currentPage = 'vehicles.php'; require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="flex-grow lg:ml-[280px] min-h-screen flex flex-col">
            <?php
            $pageTitle = 'Vehicle Verification';
            $pageSubtitle = 'Pending host vehicle assets requiring compliance review';
            $showSearch = false;
            require_once __DIR__ . '/includes/header.php';
            ?>

            <main class="p-8 flex-1">
                <?php if ($vehicleNotice !== ''): ?>
                    <div class="mb-6 rounded-2xl border px-4 py-3 text-sm font-semibold <?= $vehicleNoticeType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'; ?>">
                        <?= htmlspecialchars($vehicleNotice, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <section class="bg-white rounded-[2rem] border border-slate-200/70 shadow-sm overflow-hidden">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 border-b border-slate-100 px-8 py-6">
                        <div>
                            <h3 class="text-lg font-bold text-slate-900">Technical compliance workspace</h3>
                            <p class="text-sm text-slate-500 mt-1">High-end review board for pending host fleet assets.</p>
                        </div>
                        <div class="flex items-center gap-3 rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-600">
                            <span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>
                            <?= count($pendingVehicles); ?> pending verification items
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left">
                            <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-[0.22em] text-slate-400">
                                <tr>
                                    <th class="px-8 py-5">Vehicle</th>
                                    <th class="px-4 py-5">Owner</th>
                                    <th class="px-4 py-5">VIN</th>
                                    <th class="px-4 py-5">Compliance</th>
                                    <th class="px-4 py-5">Status</th>
                                    <th class="px-4 py-5 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (!empty($pendingVehicles)): ?>
                                    <?php foreach ($pendingVehicles as $vehicle): ?>
                                        <?php
                                        $vehicleName = trim((string) (($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? '')));
                                        $vehicleName = $vehicleName !== '' ? $vehicleName : 'Unnamed Vehicle';
                                        $vehicleYear = $vehicle['year'] ?? '';
                                        $ownerName = trim((string) ($vehicle['owner_name'] ?? '')) !== '' ? $vehicle['owner_name'] : ($vehicle['owner_email'] ?? 'Unknown Owner');
                                        $verificationStatus = $vehicle['verification_status'] ?? 'Pending';
                                        $statusClass = $verificationStatus === 'Verified' ? 'bg-emerald-50 text-emerald-700' : ($verificationStatus === 'Rejected' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700');
                                        ?>
                                        <tr class="hover:bg-slate-50/70 transition">
                                            <td class="px-8 py-6">
                                                <div class="flex items-center gap-4">
                                                    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-sm font-bold text-[#1b4b4b]">
                                                        <?= htmlspecialchars(strtoupper(substr($vehicleName, 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars($vehicleName, ENT_QUOTES, 'UTF-8'); ?></p>
                                                        <p class="text-xs text-slate-500"><?= htmlspecialchars($vehicleYear !== '' ? (string) $vehicleYear : 'Vehicle', ENT_QUOTES, 'UTF-8'); ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-6">
                                                <p class="text-sm font-semibold text-slate-700"><?= htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'); ?></p>
                                                <p class="text-xs text-slate-500"><?= htmlspecialchars((string) ($vehicle['owner_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                            </td>
                                            <td class="px-4 py-6 text-sm text-slate-600">
                                                <?= htmlspecialchars((string) ($vehicle['vin'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td class="px-4 py-6">
                                                <div class="flex flex-wrap gap-2 text-[11px] font-semibold">
                                                    <span class="rounded-full bg-blue-50 px-2.5 py-1 text-blue-700">Ownership docs</span>
                                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">Insurance docs</span>
                                                </div>
                                            </td>
                                            <td class="px-4 py-6">
                                                <span class="inline-flex items-center rounded-full px-3 py-1 text-[11px] font-semibold <?= $statusClass; ?>">
                                                    <?= htmlspecialchars($verificationStatus === 'Pending' ? 'Inspection pending' : $verificationStatus, ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-6 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <a href="vehicles.php?review_vehicle=<?= (int) $vehicle['vehicle_id']; ?>" class="inline-flex items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-[11px] font-semibold text-blue-700 transition hover:bg-blue-100">
                                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                        Review
                                                    </a>
                                                    <form method="post" class="inline-flex">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-vehicles'), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['vehicle_id']; ?>">
                                                        <input type="hidden" name="vehicle_action" value="approve">
                                                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-700 transition hover:bg-emerald-100">
                                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                                            Approve
                                                        </button>
                                                    </form>
                                                    <form method="post" class="inline-flex">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-vehicles'), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['vehicle_id']; ?>">
                                                        <input type="hidden" name="vehicle_action" value="reject">
                                                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-semibold text-red-700 transition hover:bg-red-100">
                                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                            Reject
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-8 py-12 text-center text-sm text-slate-500"><div class="empty-state inline-block w-full"><div class="es-icon">🔎</div><div>No pending vehicle verifications were found.</div></div></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <?php if ($selectedVehicleId && $vehicleDetails): ?>
                    <section class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-8">
                        <!-- Vehicle Details Panel -->
                        <div class="lg:col-span-2">
                            <div class="bg-white rounded-2xl border border-slate-200/70 shadow-sm overflow-hidden">
                                <div class="border-b border-slate-100 px-8 py-6">
                                    <h3 class="text-lg font-bold text-slate-900">📋 Vehicle Inspection Review</h3>
                                    <p class="text-sm text-slate-500 mt-1">Full technical assessment and document review</p>
                                </div>

                                <div class="p-8 space-y-6">
                                    <!-- Vehicle Details -->
                                    <div class="grid grid-cols-2 gap-4">
                                        <div class="border border-slate-100 rounded-lg p-4">
                                            <p class="text-xs text-slate-400 uppercase font-bold mb-1">Make & Model</p>
                                            <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars(($vehicleDetails['make'] ?? '') . ' ' . ($vehicleDetails['model'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="border border-slate-100 rounded-lg p-4">
                                            <p class="text-xs text-slate-400 uppercase font-bold mb-1">Year</p>
                                            <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars((string) ($vehicleDetails['year'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="border border-slate-100 rounded-lg p-4">
                                            <p class="text-xs text-slate-400 uppercase font-bold mb-1">VIN</p>
                                            <p class="text-sm font-mono text-slate-800"><?= htmlspecialchars((string) ($vehicleDetails['vin'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="border border-slate-100 rounded-lg p-4">
                                            <p class="text-xs text-slate-400 uppercase font-bold mb-1">License Plate</p>
                                            <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars((string) ($vehicleDetails['license_plate'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="border border-slate-100 rounded-lg p-4">
                                            <p class="text-xs text-slate-400 uppercase font-bold mb-1">Color</p>
                                            <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars((string) ($vehicleDetails['color'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <div class="border border-slate-100 rounded-lg p-4">
                                            <p class="text-xs text-slate-400 uppercase font-bold mb-1">Mileage</p>
                                            <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars((string) ($vehicleDetails['mileage'] ?? '0') . ' km', ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                    </div>

                                    <!-- Owner Details -->
                                    <div class="border-t border-slate-100 pt-6">
                                        <h4 class="text-sm font-bold text-slate-800 mb-4">Owner Information</h4>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="border border-slate-100 rounded-lg p-4">
                                                <p class="text-xs text-slate-400 uppercase font-bold mb-1">Name</p>
                                                <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars((string) ($vehicleDetails['owner_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                            <div class="border border-slate-100 rounded-lg p-4">
                                                <p class="text-xs text-slate-400 uppercase font-bold mb-1">Email</p>
                                                <p class="text-sm text-blue-600"><?= htmlspecialchars((string) ($vehicleDetails['owner_email'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                            <div class="border border-slate-100 rounded-lg p-4 col-span-2">
                                                <p class="text-xs text-slate-400 uppercase font-bold mb-1">Insurance Coverage</p>
                                                <p class="text-sm font-semibold text-slate-800"><?= htmlspecialchars((string) ($vehicleDetails['coverage_tier'] ?? 'Not specified'), ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Inspection Notes Form -->
                                    <div class="border-t border-slate-100 pt-6">
                                        <h4 class="text-sm font-bold text-slate-800 mb-4">Add Inspection Note</h4>
                                        <form method="POST" class="space-y-4">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-vehicles'), ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="vehicle_id" value="<?= (int) $selectedVehicleId; ?>">
                                            <input type="hidden" name="add_inspection_note" value="1">
                                            
                                            <div>
                                                <label class="block text-xs font-bold text-slate-700 mb-2">Document Type</label>
                                                <select name="document_type" required class="w-full px-4 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-slate-600">
                                                    <option value="">Select document type...</option>
                                                    <option value="registration">Vehicle Registration</option>
                                                    <option value="insurance">Insurance Certificate</option>
                                                    <option value="inspection">Inspection Report</option>
                                                    <option value="ownership">Proof of Ownership</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                            
                                            <div>
                                                <label class="block text-xs font-bold text-slate-700 mb-2">Inspection Notes</label>
                                                <textarea name="inspection_note_content" required rows="4" placeholder="Document findings, issues, or approvals..." class="w-full px-4 py-3 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-slate-600 resize-none"></textarea>
                                            </div>
                                            
                                            <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white text-sm font-bold rounded-lg hover:bg-blue-700 transition">📝 Add Note</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Inspection Notes Timeline -->
                        <div>
                            <div class="bg-white rounded-2xl border border-slate-200/70 shadow-sm overflow-hidden">
                                <div class="border-b border-slate-100 px-6 py-4">
                                    <h4 class="text-sm font-bold text-slate-800">Inspection Timeline</h4>
                                    <p class="text-xs text-slate-500 mt-1"><?= count($inspectionNotes); ?> notes recorded</p>
                                </div>

                                <div class="p-6 max-h-[600px] overflow-y-auto space-y-4">
                                    <?php if (!empty($inspectionNotes)): ?>
                                        <?php foreach ($inspectionNotes as $note): ?>
                                            <div class="border-l-2 border-blue-400 pl-4 py-2">
                                                <div class="flex items-start justify-between mb-2">
                                                    <div>
                                                        <p class="text-xs font-bold text-slate-700"><?= htmlspecialchars((string) ($note['document_type'] ?? 'Note'), ENT_QUOTES, 'UTF-8'); ?></p>
                                                        <p class="text-[10px] text-slate-500 mt-0.5"><?= htmlspecialchars(date('M d, Y H:i', strtotime((string) ($note['created_at'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?></p>
                                                    </div>
                                                    <span class="text-[10px] font-bold px-2 py-1 rounded-full <?= ($note['inspection_status'] === 'Approved' ? 'bg-emerald-100 text-emerald-700' : ($note['inspection_status'] === 'Rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700')); ?>">
                                                        <?= htmlspecialchars((string) ($note['inspection_status'] ?? 'Pending'), ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </div>
                                                <p class="text-xs text-slate-700 leading-relaxed"><?= htmlspecialchars((string) ($note['note_content'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-xs text-slate-400 text-center py-8">No inspection notes yet</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Final Action Buttons -->
                            <div class="mt-6 space-y-2">
                                <form method="POST" data-confirm="Approve this vehicle?" data-confirm-label="Approve">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-vehicles'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="vehicle_id" value="<?= (int) $selectedVehicleId; ?>">
                                    <input type="hidden" name="vehicle_action" value="approve">
                                    <button type="submit" class="w-full px-4 py-3 bg-emerald-600 text-white text-sm font-bold rounded-lg hover:bg-emerald-700 transition">✓ Approve Vehicle</button>
                                </form>
                                <form method="POST" data-confirm="Reject this vehicle?" data-confirm-label="Reject">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken('admin-vehicles'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="vehicle_id" value="<?= (int) $selectedVehicleId; ?>">
                                    <input type="hidden" name="vehicle_action" value="reject">
                                    <button type="submit" class="w-full px-4 py-3 bg-red-600 text-white text-sm font-bold rounded-lg hover:bg-red-700 transition">✗ Reject Vehicle</button>
                                </form>
                                <a href="vehicles.php" class="block px-4 py-3 border border-slate-200 text-slate-700 text-sm font-bold rounded-lg hover:bg-slate-50 transition text-center">← Back to List</a>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
            </main>

            <footer class="bg-white border-t border-slate-200/80 h-16 flex items-center justify-center px-8 text-center">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Smart Rental Core Trust Architecture v4.2.0 • Restricted Institutional Access</p>
            </footer>
        </div>
    </div>
</body>

    <div class="fixed inset-0 bg-[#1b4b4b]/60 backdrop-blur-sm z-[100] flex items-center justify-center p-6 hidden">
        <div class="bg-white rounded-[2rem] max-w-md w-full p-8 shadow-2xl">
            <h3 class="text-xl font-black uppercase mb-2">Rejection Reason</h3>
            <p class="text-xs text-gray-500 mb-6">Owner will receive this feedback via system notification.</p>
            <textarea class="w-full p-4 bg-gray-50 border border-gray-100 rounded-2xl outline-none focus:border-brand h-32 text-sm" placeholder="e.g. VIN documentation is expired or photo quality is too low..."></textarea>
            <div class="flex gap-3 mt-6">
                <button class="flex-grow py-3 bg-brand text-white rounded-xl font-black text-xs uppercase tracking-widest">Confirm Rejection</button>
                <button class="flex-grow py-3 text-gray-400 font-black text-xs uppercase tracking-widest">Cancel</button>
            </div>
        </div>
    </div>
    <script src="js/admin-app.js" defer></script>
</body>
</html>
