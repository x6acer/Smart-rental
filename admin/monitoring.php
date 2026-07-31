<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/includes/audit.php';

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    require_once __DIR__ . '/../includes/operational-logic.php';
    ensureOperationalTables($pdo);

    $vehicles = [];
    try {
        $stmt = $pdo->prepare(
            'SELECT v.vehicle_id, v.make, v.model, v.status, gt.current_latitude, gt.current_longitude, gt.geofence_violation, gt.speed, gt.recorded_at
             FROM Vehicles v
             LEFT JOIN GPS_Telemetry gt ON gt.vehicle_id = v.vehicle_id
             ORDER BY v.vehicle_id DESC'
        );
        $stmt->execute();
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Failed to load telemetry payload for monitoring AJAX: ' . $e->getMessage());
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'vehicles' => $vehicles], JSON_UNESCAPED_SLASHES);
    exit;
}

$currentPage = 'monitoring.php';
$pageTitle = 'System Monitoring';
$pageSubtitle = 'Live diagnostics, logs, and backup operations';
$headerBadge = 'OPS CENTER';
$notice = '';
$noticeType = 'info';
$csrfContext = 'admin-monitoring';

function formatBytes(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = max((float) $bytes, 0.0);
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return round($value, 2) . ' ' . $units[$index];
}

function resolveLogCandidates(): array
{
    $candidates = [];
    $root = __DIR__ . '/../storage/logs';
    if (is_dir($root)) {
        $candidates[] = $root . '/admin_users_error.log';
        $candidates[] = $root . '/error.log';
        $candidates[] = $root . '/php-error.log';
    }

    $configuredPath = ini_get('error_log');
    if (is_string($configuredPath) && $configuredPath !== '') {
        $candidates[] = $configuredPath;
    }

    $candidates[] = '/var/log/apache2/error.log';
    $candidates[] = '/var/log/httpd/error_log';
    $candidates[] = 'C:/xampp/apache/logs/error.log';

    return array_values(array_unique(array_filter($candidates, static function ($candidate): bool {
        return is_string($candidate) && $candidate !== '';
    })));
}

function readTailLogLines(string $logPath, int $limit = 260): array
{
    if (!is_file($logPath) || !is_readable($logPath)) {
        return [];
    }

    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    return array_slice($lines, -$limit);
}

function ensureBackupDirectory(): string
{
    $backupDir = __DIR__ . '/../storage/backups';
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Unable to create backup directory.');
        }
    }

    $htaccessPath = $backupDir . '/.htaccess';
    if (!is_file($htaccessPath)) {
        file_put_contents($htaccessPath, "Order deny,allow\nDeny from all\n", LOCK_EX);
    }

    return $backupDir;
}

function escapeSqlValue(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    $escaped = str_replace(["\\", "'", "\r", "\n"], ["\\\\", "\\'", "\\r", "\\n"], (string) $value);
    return "'{$escaped}'";
}

function createDatabaseBackup(PDO $pdo): string
{
    $backupDir = ensureBackupDirectory();
    $timestamp = (new DateTimeImmutable('now'))->format('Ymd_His');
    $backupFile = $backupDir . '/db_backup_' . $timestamp . '.sql';
    $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $statements = [];
    $statements[] = '-- Smart Rental database backup';
    $statements[] = '-- Generated at ' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $statements[] = '-- Database: ' . $databaseName;
    $statements[] = '';

    $tables = [];
    foreach ($pdo->query('SHOW TABLES') as $row) {
        $tableName = current($row);
        if (is_string($tableName) && $tableName !== '') {
            $tables[] = $tableName;
        }
    }

    foreach ($tables as $tableName) {
        $createResult = $pdo->query('SHOW CREATE TABLE `' . $tableName . '`');
        $createRow = $createResult->fetch(PDO::FETCH_ASSOC);
        if (is_array($createRow) && isset($createRow['Create Table'])) {
            $statements[] = 'DROP TABLE IF EXISTS `' . $tableName . '`;';
            $statements[] = (string) $createRow['Create Table'] . ';';
            $statements[] = '';
        }

        $rows = $pdo->query('SELECT * FROM `' . $tableName . '`');
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $columns = array_keys($row);
            $values = array_map(static function ($value): string { return escapeSqlValue($value); }, array_values($row));
            $statements[] = 'INSERT INTO `' . $tableName . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $values) . ');';
        }
        $statements[] = '';
    }

    file_put_contents($backupFile, implode(PHP_EOL, $statements) . PHP_EOL, LOCK_EX);
    return $backupFile;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_backup'])) {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? null, $csrfContext)) {
            $notice = 'Security check failed. Please try again.';
            $noticeType = 'error';
        } else {
            try {
                $backupFile = createDatabaseBackup($pdo);
                $notice = 'Backup generated successfully.';
                $noticeType = 'success';
                $backupFilename = basename($backupFile);
            } catch (Throwable $exception) {
                error_log('Backup generation failed: ' . $exception->getMessage());
                $notice = 'Unable to generate a database backup right now.';
                $noticeType = 'error';
                $backupFilename = null;
            }
        }
    }
}

$logCandidates = resolveLogCandidates();
$selectedLogPath = '';
$logEntries = [];
foreach ($logCandidates as $candidate) {
    $entries = readTailLogLines($candidate);
    if ($entries !== []) {
        $selectedLogPath = $candidate;
        $logEntries = $entries;
        break;
    }
}

$diskPath = '/';
$diskFree = @disk_free_space($diskPath);
if ($diskFree === false || $diskFree === null) {
    $diskFree = @disk_free_space(getcwd());
}
$diskTotal = @disk_total_space($diskPath);
if ($diskTotal === false || $diskTotal === null) {
    $diskTotal = @disk_total_space(getcwd());
}

$loadAverages = [];
if (function_exists('sys_getloadavg')) {
    $loadAverages = sys_getloadavg();
}

$diagnosticRows = [];
try {
    $diagnosticRows[] = ['label' => 'Users', 'count' => (int) $pdo->query('SELECT COUNT(*) FROM Users')->fetchColumn()];
    $diagnosticRows[] = ['label' => 'Bookings', 'count' => (int) $pdo->query('SELECT COUNT(*) FROM Bookings')->fetchColumn()];
    $diagnosticRows[] = ['label' => 'Vehicles', 'count' => (int) $pdo->query('SELECT COUNT(*) FROM Vehicles')->fetchColumn()];
    $diagnosticRows[] = ['label' => 'Claims', 'count' => (int) $pdo->query('SELECT COUNT(*) FROM Claims')->fetchColumn()];
} catch (PDOException $exception) {
    error_log('Monitoring diagnostics failed: ' . $exception->getMessage());
    $diagnosticRows = [
        ['label' => 'Users', 'count' => 0],
        ['label' => 'Bookings', 'count' => 0],
        ['label' => 'Vehicles', 'count' => 0],
        ['label' => 'Claims', 'count' => 0],
    ];
}

$healthStatus = 'Healthy';
$healthTone = 'emerald';
if ($diskFree !== false && $diskTotal !== false && $diskTotal > 0) {
    $diskUsageRatio = $diskFree / $diskTotal;
    if ($diskUsageRatio < 0.15) {
        $healthStatus = 'Storage pressure';
        $healthTone = 'amber';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>System Monitoring | Smart Rental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root { --brand-primary: #1b4b4b; --brand-accent: #facd05; }
        body { background: #020617; color: #f8fafc; }
        .console-scroll { scrollbar-width: thin; scrollbar-color: #334155 #020617; }
        .console-scroll::-webkit-scrollbar { width: 8px; }
        .console-scroll::-webkit-scrollbar-track { background: #020617; }
        .console-scroll::-webkit-scrollbar-thumb { background: #334155; border-radius: 999px; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
    <div class="flex min-h-screen">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="flex-1 lg:ml-[280px] px-6 py-8 lg:px-8">
            <?php require_once __DIR__ . '/includes/header.php'; ?>

            <section class="mt-8 space-y-8">
                <?php if ($notice !== ''): ?>
                    <div class="rounded-2xl border px-4 py-3 text-sm font-semibold <?= $noticeType === 'success' ? 'border-emerald-400/30 bg-emerald-950/60 text-emerald-300' : 'border-red-400/30 bg-red-950/60 text-red-300'; ?>" data-alert-container>
                        <?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
                    <section class="rounded-[2rem] border border-slate-800 bg-slate-900/80 p-6 shadow-2xl shadow-black/20">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-[0.35em] text-slate-500">Diagnostics</p>
                                <h3 class="mt-2 text-xl font-black text-white">System health snapshot</h3>
                            </div>
                            <span class="rounded-full bg-<?= $healthTone; ?>-950/60 px-3 py-1 text-[10px] font-black uppercase tracking-[0.25em] text-<?= $healthTone; ?>-300"><?= htmlspecialchars($healthStatus, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>

                        <div class="mt-6 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-slate-800 bg-slate-950/80 p-4">
                                <p class="text-[10px] font-black uppercase tracking-[0.25em] text-slate-500">Disk free</p>
                                <p class="mt-2 text-2xl font-black text-white"><?= $diskFree !== false ? htmlspecialchars(formatBytes((float) $diskFree), ENT_QUOTES, 'UTF-8') : 'Unavailable'; ?></p>
                            </div>
                            <div class="rounded-2xl border border-slate-800 bg-slate-950/80 p-4">
                                <p class="text-[10px] font-black uppercase tracking-[0.25em] text-slate-500">Memory usage</p>
                                <p class="mt-2 text-2xl font-black text-white"><?= htmlspecialchars(formatBytes(memory_get_usage(true)), ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div class="rounded-2xl border border-slate-800 bg-slate-950/80 p-4">
                                <p class="text-[10px] font-black uppercase tracking-[0.25em] text-slate-500">Peak memory</p>
                                <p class="mt-2 text-2xl font-black text-white"><?= htmlspecialchars(formatBytes(memory_get_peak_usage(true)), ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div class="rounded-2xl border border-slate-800 bg-slate-950/80 p-4">
                                <p class="text-[10px] font-black uppercase tracking-[0.25em] text-slate-500">Load average</p>
                                <p class="mt-2 text-2xl font-black text-white"><?= $loadAverages !== [] ? htmlspecialchars((string) $loadAverages[0], ENT_QUOTES, 'UTF-8') : 'Unavailable'; ?></p>
                            </div>
                        </div>

                        <div class="mt-6 space-y-3">
                            <?php foreach ($diagnosticRows as $row): ?>
                                <div class="flex items-center justify-between rounded-2xl border border-slate-800 bg-slate-950/70 px-4 py-3 text-sm">
                                    <span class="font-semibold text-slate-300"><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="font-black text-white"><?= (int) $row['count']; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="rounded-[2rem] border border-slate-800 bg-slate-900/80 p-6 shadow-2xl shadow-black/20">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-[0.35em] text-slate-500">Backup operations</p>
                                <h3 class="mt-2 text-xl font-black text-white">Database backup workflow</h3>
                            </div>
                            <form method="post" class="flex items-center gap-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken($csrfContext), ENT_QUOTES, 'UTF-8'); ?>" />
                                <input type="hidden" name="generate_backup" value="1" />
                                <button type="submit" class="rounded-2xl bg-[#facd05] px-4 py-3 text-sm font-black uppercase tracking-[0.2em] text-[#1b4b4b]">Generate Backup</button>
                            </form>
                        </div>

                        <div class="mt-6 rounded-2xl border border-slate-800 bg-slate-950/80 p-4 text-sm text-slate-400">
                            <p class="font-semibold text-slate-300">Protected storage target</p>
                            <p class="mt-2 leading-6">Backups are saved inside the restricted storage area and remain available only to authenticated administrators through a download link.</p>
                            <?php if (!empty($backupFilename)): ?>
                                <a href="../storage/backups/<?= htmlspecialchars($backupFilename, ENT_QUOTES, 'UTF-8'); ?>" class="mt-4 inline-flex rounded-2xl border border-emerald-400/30 bg-emerald-950/60 px-4 py-3 text-sm font-semibold text-emerald-300" download>
                                    Download latest backup
                                </a>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <section class="rounded-[2rem] border border-slate-800 bg-slate-900/80 p-6 shadow-2xl shadow-black/20">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-[0.35em] text-slate-500">Live error log</p>
                            <h3 class="mt-2 text-xl font-black text-white">Application console</h3>
                        </div>
                        <span class="rounded-full border border-slate-800 bg-slate-950/70 px-3 py-1 text-[10px] font-black uppercase tracking-[0.25em] text-slate-400">
                            <?= htmlspecialchars($selectedLogPath !== '' ? basename($selectedLogPath) : 'No readable log', ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>

                    <div class="console-scroll mt-6 overflow-x-auto rounded-[1.5rem] border border-slate-800 bg-black p-4 font-mono text-sm leading-6">
                        <?php if ($logEntries === []): ?>
                            <p class="text-slate-500">No error log entries were available from the configured locations.</p>
                        <?php else: ?>
                            <?php foreach ($logEntries as $line): ?>
                                <div class="whitespace-pre-wrap text-slate-300"><?= htmlspecialchars((string) $line, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </section>
        </main>
    </div>
</body>
</html>
