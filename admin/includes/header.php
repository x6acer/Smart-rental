<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Admin Workspace';
}
if (!isset($pageSubtitle)) {
    $pageSubtitle = 'Operational overview';
}
if (!isset($headerBadge)) {
    $headerBadge = 'READY';
}
if (!isset($headerAction)) {
    $headerAction = null;
}
if (!isset($headerSearchPlaceholder)) {
    $headerSearchPlaceholder = 'Search workspace';
}
if (!isset($headerSearchValue)) {
    $headerSearchValue = '';
}
if (!isset($showSearch)) {
    $showSearch = false;
}
if (!isset($showStatusBadge)) {
    $showStatusBadge = true;
}
if (!isset($profileName)) {
    $profileName = 'Muniru Mohammed';
}
if (!isset($profileRole)) {
    $profileRole = 'Super Admin Access';
}
if (!isset($profileInitials)) {
    $profileInitials = 'MM';
}
?>

<style>
    /* Small animation for admin alerts: used by admin-app.js -> animate-slideIn */
    @keyframes slideIn {
        0% {
            opacity: 0;
            transform: translateY(-8px) scale(0.995);
        }
        100% {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .animate-slideIn {
        animation: slideIn .32s cubic-bezier(.2,.9,.2,1) both;
    }

    /* Reusable empty-state card for admin UI */
    .empty-state {
        border: 1px dashed rgba(148,163,184,0.18);
        background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(249,250,251,0.9));
        padding: 1.25rem 1.5rem;
        border-radius: 0.75rem;
        text-align: center;
        color: #6b7280;
    }
    .empty-state .es-icon { font-size: 1.5rem; margin-bottom: 0.5rem; opacity: .9; }
</style>

<header class="sticky top-0 bg-white/80 backdrop-blur-md border-b border-slate-200/80 z-40 px-8 h-20 flex justify-between items-center">
    <div>
        <h2 class="text-xs font-bold uppercase tracking-widest text-slate-400"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <div class="flex items-center gap-6">
        <?php if ($showStatusBadge): ?>
            <div class="flex items-center gap-2 text-[10px] font-bold uppercase text-emerald-600 bg-emerald-50 border border-emerald-100 px-3 py-1 rounded-full">
                <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                Gateway Online
            </div>
        <?php endif; ?>

        <?php if (!empty($headerBadge)): ?>
            <div class="flex items-center gap-2 text-[10px] font-bold uppercase text-slate-700 bg-slate-100 border border-slate-200 px-3 py-1 rounded-full">
                <?= htmlspecialchars($headerBadge, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php if ($showSearch): ?>
            <form method="get" action="<?= htmlspecialchars(basename($_SERVER['PHP_SELF']), ENT_QUOTES, 'UTF-8'); ?>" class="relative hidden md:block">
                <input type="text" name="q" value="<?= htmlspecialchars($headerSearchValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?= htmlspecialchars($headerSearchPlaceholder, ENT_QUOTES, 'UTF-8'); ?>" class="w-72 pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs outline-none focus:border-[#1b4b4b]" />
                <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35m1.85-5.15a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </form>
        <?php endif; ?>

        <?php if ($headerAction !== null): ?>
            <div class="hidden md:block">
                <?= $headerAction; ?>
            </div>
        <?php endif; ?>

        <div class="flex items-center gap-3 border-l pl-6 border-slate-200">
            <div class="text-right">
                <p class="text-xs font-bold text-slate-800"><?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="text-[9px] text-slate-400 font-extrabold uppercase tracking-wide"><?= htmlspecialchars($profileRole, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="w-10 h-10 bg-[#1b4b4b] rounded-xl flex items-center justify-center text-[#facd05] font-bold text-sm shadow-sm"><?= htmlspecialchars($profileInitials, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>
</header>
