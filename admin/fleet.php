<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';

$currentPage = 'fleet.php';

$fleetTelemetry = [];
$fleetUtilizationPercent = 0;
$telemetryAlertCount = 0;

try {
    $stmt = $pdo->prepare(
        'SELECT v.vehicle_id, v.make, v.model, v.year, v.status, gt.telemetry_id, gt.current_latitude, gt.current_longitude, gt.route_history, gt.geofence_violation,
                COALESCE(booking_counts.active_booking_count, 0) AS active_booking_count
         FROM Vehicles v
         LEFT JOIN GPS_Telemetry gt ON gt.vehicle_id = v.vehicle_id
         LEFT JOIN (
             SELECT vehicle_id, COUNT(*) AS active_booking_count
             FROM Bookings
             WHERE booking_status IN ("Confirmed", "Active")
             GROUP BY vehicle_id
         ) booking_counts ON booking_counts.vehicle_id = v.vehicle_id
         WHERE v.status IN ("Active", "Idle", "Service")
         ORDER BY gt.telemetry_id DESC, v.vehicle_id DESC'
    );
    $stmt->execute();
    $telemetryRows = $stmt->fetchAll();

    $vehicleIds = [];
    foreach ($telemetryRows as $row) {
        $vehicleId = isset($row['vehicle_id']) ? (int) $row['vehicle_id'] : 0;
        if ($vehicleId > 0 && !in_array($vehicleId, $vehicleIds, true)) {
            $vehicleIds[] = $vehicleId;
        }

        $routeHistory = json_decode((string) ($row['route_history'] ?? '[]'), true);
        if (!is_array($routeHistory)) {
            $routeHistory = [];
        }

        $fleetTelemetry[] = [
            'vehicle_id' => $vehicleId,
            'vehicle_label' => trim((string) (($row['make'] ?? '') . ' ' . ($row['model'] ?? ''))),
            'vehicle_tag' => isset($row['year']) && $row['year'] !== null ? (string) $row['year'] : 'Vehicle',
            'status' => (string) ($row['status'] ?? 'Idle'),
            'latitude' => isset($row['current_latitude']) ? (float) $row['current_latitude'] : 0.0,
            'longitude' => isset($row['current_longitude']) ? (float) $row['current_longitude'] : 0.0,
            'route_history' => $routeHistory,
            'geofence_violation' => (bool) ($row['geofence_violation'] ?? false),
            'active_booking_count' => (int) ($row['active_booking_count'] ?? 0),
        ];
    }

    $fleetUtilizationPercent = count($vehicleIds) > 0 ? (int) round((count(array_filter($fleetTelemetry, static function ($item) { return (int) $item['active_booking_count'] > 0; })) / count($vehicleIds)) * 100) : 0;
    $telemetryAlertCount = count(array_filter($fleetTelemetry, static function ($item) { return $item['geofence_violation']; }));
} catch (PDOException $e) {
    $fleetTelemetry = [];
    $fleetUtilizationPercent = 0;
    $telemetryAlertCount = 0;
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Fleet Monitoring & GPS | Smart Rental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
      :root {
        --brand-primary: #1b4b4b;
        --brand-accent: #facd05;
      }
      .map-placeholder {
        background-image: radial-gradient(#e2e8f0 1px, transparent 1px);
        background-size: 20px 20px;
      }
    </style>
  </head>

  <body class="min-h-screen bg-[#f8fafc] font-sans text-slate-900">
    <div class="min-h-screen flex">
      <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

      <div class="flex-grow lg:ml-[280px] flex min-h-screen flex-col">
        <?php
        $pageTitle = 'Fleet Command';
        $pageSubtitle = 'Live vehicle monitoring and geofence events';
        $showStatusBadge = false;
        require_once __DIR__ . '/includes/header.php';
        ?>

        <main class="flex-1 min-h-0 flex overflow-hidden p-8">
          <div class="flex-1 flex flex-col overflow-hidden">
            <?php $fleetPrimaryItem = $fleetTelemetry[0] ?? null; ?>
            <?php if ($fleetPrimaryItem): ?>
            <div
              class="p-5 border-b border-gray-50 hover:bg-gray-50 cursor-pointer"
            >
              <div class="flex justify-between items-start mb-2">
                <span class="text-xs font-black uppercase <?= $fleetPrimaryItem['geofence_violation'] ? 'text-red-600' : 'text-green-600'; ?>" data-status-live="<?= $fleetPrimaryItem['geofence_violation'] ? 'active' : 'active'; ?>"
                  ><?= $fleetPrimaryItem['geofence_violation'] ? '⚠ Breach' : '● In Motion'; ?></span
                >
                <span class="text-[9px] font-bold text-gray-400">Live</span>
              </div>
              <div class="flex gap-4">
                <div
                  class="w-12 h-12 bg-gray-100 rounded-lg overflow-hidden shrink-0"
                >
                  <div class="w-full h-full flex items-center justify-center bg-brand/10 text-brand font-black text-xs"><?= htmlspecialchars(strtoupper(substr($fleetPrimaryItem['vehicle_label'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div>
                  <p class="text-xs font-black uppercase"><?= htmlspecialchars((string) $fleetPrimaryItem['vehicle_label'], ENT_QUOTES, 'UTF-8'); ?> (<?= htmlspecialchars((string) $fleetPrimaryItem['vehicle_tag'], ENT_QUOTES, 'UTF-8'); ?>)</p>
                  <p class="text-[10px] text-gray-500">Active bookings: <?= (int) $fleetPrimaryItem['active_booking_count']; ?></p>
                  <p
                    class="text-[9px] font-bold text-brand mt-2 uppercase tracking-tighter" data-copy="<?= htmlspecialchars(number_format($fleetPrimaryItem['latitude'], 4, '.', ',') . ', ' . number_format($fleetPrimaryItem['longitude'], 4, '.', ','), ENT_QUOTES, 'UTF-8'); ?>"
                  >
                    Loc: <?= htmlspecialchars(number_format($fleetPrimaryItem['latitude'], 4, '.', ',') . ', ' . number_format($fleetPrimaryItem['longitude'], 4, '.', ','), ENT_QUOTES, 'UTF-8'); ?>
                  </p>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <?php $fleetSecondaryItem = $fleetTelemetry[1] ?? null; ?>
            <?php if ($fleetSecondaryItem): ?>
            <div class="p-5 border-b border-gray-50 opacity-60">
              <div class="flex justify-between items-start mb-2">
                <span class="text-xs font-black uppercase text-gray-400"
                  >◌ <?= htmlspecialchars((string) $fleetSecondaryItem['status'], ENT_QUOTES, 'UTF-8'); ?></span
                >
                <span class="text-[9px] font-bold text-gray-400"><?= (int) $fleetSecondaryItem['active_booking_count']; ?> active</span>
              </div>
              <div class="flex gap-4">
                <div class="w-12 h-12 bg-gray-200 rounded-lg shrink-0 flex items-center justify-center text-gray-500 font-black text-xs"><?= htmlspecialchars(strtoupper(substr($fleetSecondaryItem['vehicle_label'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>
                <div>
                  <p class="text-xs font-black uppercase"><?= htmlspecialchars((string) $fleetSecondaryItem['vehicle_label'], ENT_QUOTES, 'UTF-8'); ?></p>
                  <p class="text-[10px] text-gray-400">
                    Last seen near <?= htmlspecialchars(number_format($fleetSecondaryItem['latitude'], 4, '.', ','), ENT_QUOTES, 'UTF-8'); ?>, <?= htmlspecialchars(number_format($fleetSecondaryItem['longitude'], 4, '.', ','), ENT_QUOTES, 'UTF-8'); ?>
                  </p>
                </div>
              </div>
            </div>
            <?php endif; ?>
          </div>

          <section
            class="flex-grow relative map-placeholder bg-gray-50 flex items-center justify-center"
          >
            <div class="absolute top-6 left-6 flex flex-col gap-2 z-10">
              <button
                class="bg-white p-3 rounded-xl shadow-lg border border-gray-100 hover:text-brand transition"
              >
                ➕
              </button>
              <button
                class="bg-white p-3 rounded-xl shadow-lg border border-gray-100 hover:text-brand transition"
              >
                ➖
              </button>
              <button
                class="bg-white p-3 rounded-xl shadow-lg border border-gray-100 hover:text-brand transition"
              >
                🎯
              </button>
            </div>

            <div class="text-center">
              <div
                class="w-20 h-20 border-4 border-brand border-t-transparent rounded-full animate-spin mx-auto mb-4 opacity-20"
              ></div>
              <p
                class="text-[10px] font-black uppercase text-gray-300 tracking-[0.5em]"
              >
                Utilization <?= htmlspecialchars((string) $fleetUtilizationPercent, ENT_QUOTES, 'UTF-8'); ?>% • Alerts <?= htmlspecialchars((string) $telemetryAlertCount, ENT_QUOTES, 'UTF-8'); ?>
              </p>
            </div>

            <div class="absolute top-[30%] left-[45%] group cursor-pointer">
              <div
                class="w-8 h-8 bg-red-600 rounded-full border-4 border-white shadow-xl flex items-center justify-center text-white animate-bounce"
              >
                <span class="text-[10px] font-black">!</span>
              </div>
              <div
                class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 hidden group-hover:block w-48 bg-white p-4 rounded-2xl shadow-2xl border border-red-100"
              >
                <p class="text-[10px] font-black uppercase text-red-500 mb-1">
                  Breach Detected
                </p>
                <p class="text-xs font-black">Ford Explorer</p>
                <p class="text-[10px] text-gray-500">Speed: 85km/h</p>
                <button
                  class="w-full mt-3 py-2 bg-brand text-white text-[9px] font-black uppercase rounded-lg"
                >
                  View Details
                </button>
              </div>
            </div>
          </section>

          <section
            class="hidden xl:flex w-80 bg-white border-l border-gray-200 flex-col shrink-0"
          >
            <div class="p-8">
              <h3
                class="text-sm font-black uppercase tracking-widest text-gray-400 mb-8"
              >
                Unit Telemetry
              </h3>

              <div class="space-y-10">
                <div>
                  <p
                    class="text-[10px] font-black text-gray-400 uppercase mb-4"
                  >
                    Boundary Stats
                  </p>
                  <div class="space-y-4">
                    <div class="flex justify-between text-xs font-bold">
                      <span class="text-gray-500">Accra Perimeter</span>
                      <span class="text-green-600">Secure</span>
                    </div>
                    <div class="flex justify-between text-xs font-bold">
                      <span class="text-gray-500">Exit Gates</span>
                      <span class="text-red-500">1 Alert</span>
                    </div>
                  </div>
                </div>

                <hr class="border-gray-50" />

                <div>
                  <p
                    class="text-[10px] font-black text-gray-400 uppercase mb-4"
                  >
                    Intervention Tools
                  </p>
                  <div class="grid grid-cols-1 gap-3">
                    <button
                      class="w-full py-4 bg-gray-900 text-white rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-black transition"
                    >
                      Notify Renter
                    </button>
                    <button
                      class="w-full py-4 border-2 border-red-500 text-red-500 rounded-2xl font-black text-[10px] uppercase tracking-widest hover:bg-red-500 hover:text-white transition"
                    >
                      Remote Immobilize
                    </button>
                  </div>
                  <p class="text-[8px] text-gray-400 italic mt-3 text-center">
                    Protocol: Immobilization requires Senior Admin approval.
                  </p>
                </div>
              </div>
            </div>

            <div class="mt-auto p-8 bg-gray-50 border-t border-gray-100">
              <div class="flex items-center gap-3">
                <span class="text-xl">🛡️</span>
                <div>
                  <p class="text-xs font-black uppercase">Fleet Safety</p>
                  <p class="text-[9px] text-gray-500 font-bold">
                    Encrypted GPS Relay
                  </p>
                </div>
              </div>
            </div>
          </section>
        </main>

        <footer
          class="bg-white border-t border-gray-200 py-2 px-8 flex justify-between items-center shrink-0"
        >
          <p
            class="text-[8px] font-black text-gray-400 uppercase tracking-widest"
          >
            © 2026 Smart Rental Fleet Monitoring System • v4.2
          </p>
          <p class="text-[8px] font-black text-green-500 uppercase">
            Relay Latency: 42ms
          </p>
        </footer>
      </div>
    </div>
    <script src="js/admin-app.js" defer></script>
  </body>
</html>

