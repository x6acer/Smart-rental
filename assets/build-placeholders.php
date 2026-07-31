<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Run this script from the command line only.';
    exit(1);
}

$root = __DIR__;
$brandPrimary = '#1b4b4b';
$brandAccent = '#facd05';

function srBuildSvg(string $title, string $subtitle = 'Smart Rental', int $width = 1600, int $height = 900, string $primary = '#1b4b4b', string $accent = '#facd05'): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeSubtitle = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');

    $titleY = (int) round($height * 0.55);
    $subtitleY = $titleY + 56;
    $barY = $subtitleY + 36;

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}" role="img" aria-label="{$safeTitle}">
  <defs>
    <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="{$primary}"/>
      <stop offset="100%" stop-color="#0f3030"/>
    </linearGradient>
  </defs>
  <rect width="{$width}" height="{$height}" fill="url(#bg)"/>
  <rect x="80" y="80" width="{$width}" height="{$height}" fill="{$accent}" opacity="0.08" rx="48"/>
  <circle cx="220" cy="180" r="90" fill="{$accent}" opacity="0.18"/>
  <circle cx="{$width}" cy="0" r="260" fill="{$accent}" opacity="0.12"/>
  <text x="120" y="{$titleY}" fill="#ffffff" font-family="Segoe UI, Arial, sans-serif" font-size="72" font-weight="800">{$safeTitle}</text>
  <text x="120" y="{$subtitleY}" fill="{$accent}" font-family="Segoe UI, Arial, sans-serif" font-size="28" font-weight="700" letter-spacing="6">{$safeSubtitle}</text>
  <rect x="120" y="{$barY}" width="220" height="10" fill="{$accent}" rx="5"/>
</svg>
SVG;
}

function srBuildIconSvg(string $label, string $primary = '#1b4b4b', string $accent = '#facd05'): string
{
    $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="320" height="120" viewBox="0 0 320 120" role="img" aria-label="{$safeLabel}">
  <rect width="320" height="120" rx="20" fill="{$primary}"/>
  <rect x="16" y="16" width="288" height="88" rx="16" fill="#ffffff" opacity="0.08"/>
  <text x="160" y="72" fill="{$accent}" font-family="Segoe UI, Arial, sans-serif" font-size="28" font-weight="800" text-anchor="middle">{$safeLabel}</text>
</svg>
SVG;
}

function srWriteAsset(string $absolutePath, string $contents): void
{
    $directory = dirname($absolutePath);

    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    file_put_contents($absolutePath, $contents);
    echo 'Created ' . str_replace('\\', '/', $absolutePath) . PHP_EOL;
}

$assets = [
    'images/dextar-vision-4JztXiioPHI-unsplash.jpg' => srBuildSvg('Premium Fleet Hero', 'Comfortable driving everywhere', 1920, 1080, $brandPrimary, $brandAccent),
    'images/homepage1.jpg' => srBuildSvg('SUV Collection', 'Explore SUVs', 1200, 800, $brandPrimary, $brandAccent),
    'images/homepage2.jpg' => srBuildSvg('Sedan Collection', 'Explore Sedans', 1200, 800, $brandPrimary, $brandAccent),
    'images/homepage3.jpg' => srBuildSvg('Truck Collection', 'Explore Trucks', 1200, 800, $brandPrimary, $brandAccent),
    'images/homepage4.jpg' => srBuildSvg('Luxury Collection', 'Explore Luxury', 1200, 800, $brandPrimary, $brandAccent),
    'images/about.jpg' => srBuildSvg('Trusted Fleet Quality', 'Verified owners and vehicles', 1200, 900, $brandPrimary, $brandAccent),
    'images/cotm1.jpg' => srBuildSvg('Ford Explorer Offer', 'Car of the month', 900, 700, $brandPrimary, $brandAccent),
    'images/cotm2.jpg' => srBuildSvg('Honda Accord Offer', 'Car of the month', 900, 700, $brandPrimary, $brandAccent),
    'images/cotm3.jpg' => srBuildSvg('Mercedes C-Class Offer', 'Car of the month', 900, 700, $brandPrimary, $brandAccent),
    'images/request-bg.jpg' => srBuildSvg('Request A Quote', 'Tailored rental packages', 1920, 1080, $brandPrimary, $brandAccent),
    'images/history.jpg' => srBuildSvg('Owner Partner Hero', 'Turn your car into revenue', 1600, 900, $brandPrimary, $brandAccent),
    'images/owner-dashboard-preview.jpg' => srBuildSvg('Owner Dashboard Preview', 'Fleet management workspace', 1400, 900, $brandPrimary, $brandAccent),
    'images/signup-image.jpg' => srBuildSvg('Customer Sign In', 'Browse and book verified vehicles', 1200, 1400, $brandPrimary, $brandAccent),
    'images/signup1-image.jpg' => srBuildSvg('Owner Sign In', 'Manage your fleet with confidence', 1200, 1400, $brandPrimary, $brandAccent),
    'images/signup3-image.jpg' => srBuildSvg('Create Customer Account', 'Join Smart Rental today', 1200, 1400, $brandPrimary, $brandAccent),
    'images/login-image.jpg' => srBuildSvg('Owner Registration', 'Partner with Smart Rental', 1200, 1400, $brandPrimary, $brandAccent),
    'images/Cars/SUVs/ford-explorer.jpg' => srBuildSvg('Ford Explorer XLT', 'SUV • Automatic • Petrol', 1200, 800, $brandPrimary, $brandAccent),
    'images/Cars/SUVs/ford-explorer-2.jpg' => srBuildSvg('Ford Explorer Interior', 'Spacious cabin', 900, 900, $brandPrimary, $brandAccent),
    'images/Cars/SUVs/ford-explorer-3.jpg' => srBuildSvg('Ford Explorer Profile', 'Premium SUV', 900, 900, $brandPrimary, $brandAccent),
    'images/Cars/SUVs/toyota-highlander.jpg' => srBuildSvg('Toyota Highlander', 'Hybrid SUV', 1200, 800, $brandPrimary, $brandAccent),
    'images/Cars/SEDAN/kiak5-1.jpg' => srBuildSvg('Kia K5 GT-Line', 'Sedan • Automatic', 1200, 800, $brandPrimary, $brandAccent),
    'images/Cars/SEDAN/kiak5-2.jpg' => srBuildSvg('Kia K5 Interior', 'Modern sedan cabin', 900, 900, $brandPrimary, $brandAccent),
    'images/Cars/SEDAN/kiak5-3.jpg' => srBuildSvg('Kia K5 Profile', 'Efficient daily driver', 900, 900, $brandPrimary, $brandAccent),
    'images/Cars/SEDAN/honda-accord.jpg' => srBuildSvg('Honda Accord Sport', 'Sedan • Automatic', 1200, 800, $brandPrimary, $brandAccent),
    'images/Cars/SEDAN/honda-accord-2.jpg' => srBuildSvg('Honda Accord Interior', 'Comfort first', 900, 900, $brandPrimary, $brandAccent),
    'images/Cars/SEDAN/honda-accord-3.jpg' => srBuildSvg('Honda Accord Profile', 'Reliable sedan', 900, 900, $brandPrimary, $brandAccent),
    'images/Cars/LUXURY/mercedes-c-class.jpg' => srBuildSvg('Mercedes C-Class', 'Luxury • Automatic', 1200, 800, $brandPrimary, $brandAccent),
    'icons/mtn-momo.svg' => srBuildIconSvg('MTN MoMo', $brandPrimary, $brandAccent),
    'icons/visa-mastercard.svg' => srBuildIconSvg('Visa / Mastercard', $brandPrimary, $brandAccent),
];

foreach ($assets as $relativePath => $svgContents) {
    srWriteAsset($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $svgContents);
}

$uploadPlaceholders = [
    'uploads/seed/ownership-placeholder.pdf' => '%PDF-1.4 Smart Rental seed ownership placeholder',
    'uploads/seed/vehicle-placeholder.jpg' => srBuildSvg('Uploaded Vehicle Photo', 'Owner listing placeholder', 1200, 800, $brandPrimary, $brandAccent),
];

foreach ($uploadPlaceholders as $relativePath => $contents) {
    srWriteAsset(dirname($root) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath), $contents);
}

echo PHP_EOL . 'Asset placeholders generated successfully.' . PHP_EOL;
