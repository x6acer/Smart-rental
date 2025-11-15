<?php
/**
 * Helper functions for consistent rental status display across customer pages
 */

// Handle AJAX requests for status badge rendering
if (isset($_GET['action']) && $_GET['action'] === 'renderStatus' && isset($_GET['status'])) {
    ob_start();
    renderRentalStatusBadge($_GET['status']);
    echo ob_get_clean();
    exit;
}

// Status colors for Tailwind classes
function getRentalStatusColors($status) {
    $statusColors = [
        'available' => 'bg-green-100 text-green-700',
        'rented' => 'bg-blue-100 text-blue-700',
        'maintenance' => 'bg-yellow-100 text-yellow-700'
    ];
    $normalizedStatus = strtolower($status ?? '');
    return $statusColors[$normalizedStatus] ?? 'bg-gray-200 text-gray-800';
}

// Status labels for display
function getRentalStatusLabel($status) {
    $statusLabels = [
        'available' => 'Available',
        'rented' => 'Rented',
        'maintenance' => 'Maintenance'
    ];
    $normalizedStatus = strtolower($status ?? '');
    return $statusLabels[$normalizedStatus] ?? ucfirst($status);
}

// Get status message for unavailable vehicles
function getRentalStatusMessage($status) {
    $normalizedStatus = strtolower($status ?? '');
    switch ($normalizedStatus) {
        case 'rented':
            return "This vehicle is currently rented";
        case 'maintenance':
            return "This vehicle is under maintenance";
        case 'available':
            return "";
        default:
            return "This vehicle is not available";
    }
}

// Check if a vehicle is available for rent
function isVehicleAvailable($status) {
    return strtolower($status ?? '') === 'available';
}

// Render status badge with consistent styling
function renderRentalStatusBadge($status, $additionalClasses = '') {
    $colorClass = getRentalStatusColors($status);
    $label = getRentalStatusLabel($status);
    echo '<span class="inline-block px-3 py-1 rounded-full text-sm ' . $colorClass . ' ' . $additionalClasses . '">';
    echo htmlspecialchars($label);
    echo '</span>';
}