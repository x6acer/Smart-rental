<?php
require_once __DIR__ . '/includes/admin-guard.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/security.php';
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
    error_log('Failed to load tracking inventory: ' . $e->getMessage());
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Live Tracking | Smart Rental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen">
    <div class="max-w-7xl mx-auto px-6 py-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-black uppercase tracking-tight">Live Tracking & Geofence Monitoring</h1>
                <p class="text-sm text-slate-500 mt-1">A lightweight real-time feed for vehicle movement and violation events.</p>
            </div>
            <div class="rounded-full border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-bold uppercase tracking-wider text-amber-700">Auto-refreshing</div>
        </div>
        <div class="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
            <div class="bg-white rounded-3xl border border-slate-200 p-6 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-black uppercase tracking-wider text-slate-700">Vehicle Feed</h2>
                    <span id="tracking-status" class="text-xs font-bold uppercase text-emerald-600">Monitoring</span>
                </div>
                <div id="tracking-list" class="space-y-3"></div>
            </div>
            <div class="bg-white rounded-3xl border border-slate-200 p-6 shadow-sm">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-700 mb-4">Active Violations</h2>
                <div id="violation-panel" class="space-y-3"></div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        const trackingList = document.getElementById('tracking-list');
        const violationPanel = document.getElementById('violation-panel');
        const trackingStatus = document.getElementById('tracking-status');

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, function (char) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
            });
        }

        function renderVehicles(vehicles) {
            if (!trackingList) return;
            if (!vehicles.length) {
                trackingList.innerHTML = '<div class="empty-state"><div class="es-icon">📡</div><div>No telemetry has been recorded yet.</div></div>';
                return;
            }
            trackingList.innerHTML = vehicles.map(function (vehicle) {
                const breach = Number(vehicle.geofence_violation || 0) === 1;
                const lat = vehicle.current_latitude !== null ? Number(vehicle.current_latitude).toFixed(4) : '—';
                const lng = vehicle.current_longitude !== null ? Number(vehicle.current_longitude).toFixed(4) : '—';
                const speed = vehicle.speed !== null ? Number(vehicle.speed).toFixed(1) : '—';
                return '<div class="rounded-2xl border ' + (breach ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-slate-50') + ' p-4">' +
                    '<div class="flex items-center justify-between gap-3">' +
                    '<div>' +
                    '<div class="text-xs font-black uppercase tracking-wider ' + (breach ? 'text-rose-600' : 'text-slate-700') + '">' + escapeHtml(vehicle.make + ' ' + vehicle.model) + '</div>' +
                    '<div class="text-[11px] text-slate-500 mt-1">Lat ' + escapeHtml(lat) + ' • Lng ' + escapeHtml(lng) + '</div>' +
                    '</div>' +
                    '<span class="text-[10px] font-black uppercase px-2.5 py-1 rounded-full ' + (breach ? 'bg-rose-600 text-white' : 'bg-emerald-600 text-white') + '">' + (breach ? 'Breach' : 'Healthy') + '</span>' +
                    '</div>' +
                    '<div class="mt-3 flex items-center gap-4 text-xs text-slate-600">' +
                    '<span>Speed: ' + escapeHtml(speed) + ' km/h</span>' +
                    '<span>Updated: ' + escapeHtml(vehicle.recorded_at || '—') + '</span>' +
                    '</div>' +
                    '</div>';
            }).join('');
        }

        function renderViolations(vehicles) {
            if (!violationPanel) return;
            const breaches = vehicles.filter(function (vehicle) { return Number(vehicle.geofence_violation || 0) === 1; });
            if (!breaches.length) {
                violationPanel.innerHTML = '<div class="empty-state"><div class="es-icon">🛰️</div><div>No active geofence breaches detected.</div></div>';
                return;
            }
            violationPanel.innerHTML = breaches.map(function (vehicle) {
                return '<div class="rounded-2xl border border-rose-200 bg-rose-50 p-4">' +
                    '<div class="text-sm font-black uppercase text-rose-700">' + escapeHtml(vehicle.make + ' ' + vehicle.model) + '</div>' +
                    '<div class="text-xs text-rose-700 mt-2">Coordinate: ' + escapeHtml(vehicle.current_latitude !== null ? Number(vehicle.current_latitude).toFixed(4) : '—') + ', ' + escapeHtml(vehicle.current_longitude !== null ? Number(vehicle.current_longitude).toFixed(4) : '—') + '</div>' +
                    '<div class="text-xs text-rose-700 mt-2">Speed: ' + escapeHtml(vehicle.speed !== null ? Number(vehicle.speed).toFixed(1) : '—') + ' km/h</div>' +
                    '<a href="support.php" class="inline-flex mt-3 text-[10px] font-black uppercase tracking-wider text-rose-700 underline">Contact Driver</a>' +
                    '</div>';
            }).join('');
        }

        function refreshTracking() {
            trackingStatus.textContent = 'Refreshing';
            fetch('monitoring.php?ajax=1', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) { return response.json(); }).then(function (payload) {
                if (payload && Array.isArray(payload.vehicles)) {
                    renderVehicles(payload.vehicles);
                    renderViolations(payload.vehicles);
                    trackingStatus.textContent = 'Monitoring';
                }
            }).catch(function () {
                trackingStatus.textContent = 'Offline';
            });
        }

        refreshTracking();
        setInterval(refreshTracking, 5000);
    })();
    </script>
</body>
</html>
