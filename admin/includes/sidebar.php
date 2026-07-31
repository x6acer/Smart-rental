<?php
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['PHP_SELF']);
}

$currentPage = preg_replace('/\?.*$/', '', $currentPage);

function sidebarNavItemClass(string $href, string $currentPage): string
{
    return $href === $currentPage
        ? 'flex items-center gap-3 px-4 py-3 bg-[#1b4b4b] text-white rounded-xl shadow-sm transition'
        : 'flex items-center gap-3 px-4 py-3 hover:bg-slate-50 rounded-xl text-slate-700 hover:text-[#1b4b4b] transition';
}

$navSections = [
    [
        'title' => 'Core Operations',
        'items' => [
            [
                'label' => 'Dashboard Overview',
                'href' => 'dashboard.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2V16zM14 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2V16z"/></svg>',
            ],
            [
                'label' => 'User Management',
                'href' => 'users.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>',
            ],
            [
                'label' => 'Vehicle Verification',
                'href' => 'vehicles.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>',
            ],
            [
                'label' => 'Booking Control',
                'href' => 'booking.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
            ],
        ],
    ],
    [
        'title' => 'Financials & Security',
        'items' => [
            [
                'label' => 'Escrow & Payouts',
                'href' => 'payrev.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            ],
            [
                'label' => 'Dispute Resolution',
                'href' => 'support.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>',
            ],
            [
                'label' => 'Insurance & Claims',
                'href' => 'insurance.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>',
            ],
            [
                'label' => 'Global Settings',
                'href' => 'settings.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.607 2.297.195 2.573-1.066z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>',
            ],
        ],
    ],
    [
        'title' => 'Team Coordination',
        'items' => [
            [
                'label' => 'Task Board',
                'href' => 'tasks.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>',
            ],
        ],
    ],
    [
        'title' => 'Monitoring Tools',
        'items' => [
            [
                'label' => 'Audit Trail',
                'href' => 'audit-trail.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
            ],
            [
                'label' => 'Notification Center',
                'href' => 'notification&messages.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V4a2 2 0 10-4 0v1.341A6.002 6.002 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>',
            ],
            [
                'label' => 'Reports & Analytics',
                'href' => 'reports.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
            ],
            [
                'label' => 'System Monitoring',
                'href' => 'monitoring.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
            ],
            [
                'label' => 'AI Insights',
                'href' => 'ai&analytics.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>',
            ],
            [
                'label' => 'Fleet Monitoring',
                'href' => 'fleet.php',
                'icon' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
            ],
        ],
    ],
];
?>

<aside class="fixed top-0 left-0 bg-white border-r border-slate-200 h-screen z-50 w-[280px] -translate-x-full lg:translate-x-0 flex flex-col justify-between px-6 transition-transform duration-200 shadow-sm">
    <div>
        <div class="h-20 flex items-center border-b border-slate-100">
            <div>
                <h1 class="text-xl font-extrabold tracking-tight text-[#1b4b4b] uppercase">
                    Smart<span class="text-[#facd05]">Rental</span>
                </h1>
                <span class="inline-block text-[10px] font-bold bg-[#1b4b4b] text-white px-2 py-0.5 rounded-md mt-1 tracking-wide">SYSTEM ADMINISTRATION</span>
            </div>
        </div>

        <nav class="mt-8 space-y-7 h-[calc(100vh-180px)] overflow-y-auto pr-1">
            <?php foreach ($navSections as $section): ?>
                <div>
                    <h2 class="text-[10px] font-bold uppercase text-slate-400 tracking-wider mb-3"><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                    <ul class="space-y-1.5 text-sm font-semibold text-slate-600">
                        <?php foreach ($section['items'] as $item): ?>
                            <?php $isActive = ($currentPage === $item['href']); ?>
                            <li>
                                <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>" class="<?= sidebarNavItemClass($item['href'], $currentPage); ?>"<?php if ($isActive): ?> aria-current="page"<?php endif; ?>>
                                    <?php if ($isActive): ?>
                                        <span class="text-[#facd05]">
                                            <?= $item['icon']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-400">
                                            <?= $item['icon']; ?>
                                        </span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </nav>
    </div>

    <div class="py-5 border-t border-slate-100">
        <a href="./includes/logout.php" class="flex items-center gap-2 text-xs font-bold uppercase text-red-500 hover:text-red-700 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            Terminate Session
        </a>
    </div>
</aside>
