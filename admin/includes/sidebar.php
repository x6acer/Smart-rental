
<aside class="fixed mt-16 flex flex-col lg:mt-0 top-0 px-5 left-0 bg-white text-gray-900 h-screen z-50 border-r border-gray-200 w-[290px] -translate-x-full lg:translate-x-0">
    <div class="py-8">
        <a class="ml-0 text-2xl font-bold text-gray-800 flex items-center gap-2" href="dashboard.php">
            <strong class="text-purple-500">Smart</strong><span class="bg-purple-500 text-white px-1 rounded-sm">Rental</span>
        </a>
        <ul class="mt-6">
            <li class="relative px-6 py-3">
                <?php if (basename($_SERVER['PHP_SELF']) === 'dashboard.php'): ?>
                <span class="absolute inset-y-0 left-0 w-1 bg-purple-600 rounded-tr-lg rounded-br-lg"></span>
                <?php endif; ?>
                <a class="inline-flex items-center w-full text-sm font-semibold transition-colors duration-150 hover:text-gray-800 <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'text-gray-800' : ''; ?>" 
                   href="dashboard.php">
                    <svg class="w-5 h-5" aria-hidden="true" fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" stroke="currentColor">
                        <path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                    <span class="ml-4">Dashboard</span>
                </a>
            </li>

            <li class="relative px-6 py-3">
                <?php if (basename($_SERVER['PHP_SELF']) === 'rentals.php'): ?>
                <span class="absolute inset-y-0 left-0 w-1 bg-purple-600 rounded-tr-lg rounded-br-lg"></span>
                <?php endif; ?>
                <a class="inline-flex items-center w-full text-sm font-semibold transition-colors duration-150 hover:text-gray-800 <?php echo basename($_SERVER['PHP_SELF']) === 'rentals.php' ? 'text-gray-800' : ''; ?>"
                   href="rentals.php">
                    <svg class="w-5 h-5" aria-hidden="true" fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" stroke="currentColor">
                        <path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    <span class="ml-4">Rentals</span>
                </a>
            </li>

            <li class="relative px-6 py-3">
                <?php if (basename($_SERVER['PHP_SELF']) === 'categories.php'): ?>
                <span class="absolute inset-y-0 left-0 w-1 bg-purple-600 rounded-tr-lg rounded-br-lg"></span>
                <?php endif; ?>
                <a class="inline-flex items-center w-full text-sm font-semibold transition-colors duration-150 hover:text-gray-800 <?php echo basename($_SERVER['PHP_SELF']) === 'categories.php' ? 'text-gray-800' : ''; ?>"
                   href="categories.php">
                    <svg class="w-5 h-5" aria-hidden="true" fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" stroke="currentColor">
                        <path d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                    </svg>
                    <span class="ml-4">Categories</span>
                </a>
            </li>

            <li class="relative px-6 py-3">
                <?php if (basename($_SERVER['PHP_SELF']) === 'orders.php'): ?>
                <span class="absolute inset-y-0 left-0 w-1 bg-purple-600 rounded-tr-lg rounded-br-lg"></span>
                <?php endif; ?>
                <a class="inline-flex items-center w-full text-sm font-semibold transition-colors duration-150 hover:text-gray-800 <?php echo basename($_SERVER['PHP_SELF']) === 'orders.php' ? 'text-gray-800' : ''; ?>"
                   href="orders.php">
                    <svg class="w-5 h-5" aria-hidden="true" fill="none" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24" stroke="currentColor">
                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                    <span class="ml-4">Orders</span>
                </a>
            </li>

            <li class="relative px-6 py-3">
                <?php if (basename($_SERVER['PHP_SELF']) === 'customers.php'): ?>
                <span class="absolute inset-y-0 left-0 w-1 bg-purple-600 rounded-tr-lg rounded-br-lg"></span>
                <?php endif; ?>
                <a class="inline-flex items-center w-full text-sm font-semibold transition-colors duration-150 hover:text-gray-800 <?php echo basename($_SERVER['PHP_SELF']) === 'customers.php' ? 'text-gray-800' : ''; ?>"
                   href="customers.php">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    <span class="ml-4">Customers</span>
                </a>
            </li>
        </ul>
    </div>
</aside>
