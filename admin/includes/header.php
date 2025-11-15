<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>SmartRental Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="../assets/css/tailwind.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
        .sidebar {
            transition: transform 0.3s ease-in-out;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.show {
                transform: translateX(0);
            }
        }
    </style>
</head>
<body class="bg-gray-200">
    <div class="min-h-screen">
        <div class="flex">
            <?php require_once 'sidebar.php'; ?>
            <div class="flex-1 transition-all duration-300 ease-in-out lg:ml-[290px]">
                <header class="sticky top-0 flex bg-white border-b border-gray-200 z-50">
                    <div class="flex flex-col items-center justify-between flex-grow lg:flex-row lg:px-6">
                        <div class="w-full lg:w-auto px-4 py-3">
                            <form>
                                <div class="relative">
                                    <span class="absolute -translate-y-1/2 pointer-events-none left-4 top-1/2">
                                        <svg class="fill-gray-500" width="15" height="15" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                            <path fill-rule="evenodd" clip-rule="evenodd" d="M3.04175 9.37363C3.04175 5.87693 5.87711 3.04199 9.37508 3.04199C12.8731 3.04199 15.7084 5.87693 15.7084 9.37363C15.7084 12.8703 12.8731 15.7053 9.37508 15.7053C5.87711 15.7053 3.04175 12.8703 3.04175 9.37363ZM9.37508 1.54199C5.04902 1.54199 1.54175 5.04817 1.54175 9.37363C1.54175 13.6991 5.04902 17.2053 9.37508 17.2053C11.2674 17.2053 13.003 16.5344 14.357 15.4176L17.177 18.238C17.4699 18.5309 17.9448 18.5309 18.2377 18.238C18.5306 17.9451 18.5306 17.4703 18.2377 17.1774L15.418 14.3573C16.5365 13.0033 17.2084 11.2669 17.2084 9.37363C17.2084 5.04817 13.7011 1.54199 9.37508 1.54199Z" fill=""/>
                                        </svg>
                                    </span>
                                    <input type="text" class="h-8 w-full rounded-lg border border-gray-200 bg-transparent py-2.5 pl-10 pr-10 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-purple-300 focus:outline-none focus:ring focus:ring-purple-500/10 xl:w-[430px]" placeholder="Search here..." />
                                </div>
                            </form>
                        </div>

                        <div class="px-4 py-3">
                            <div class="relative flex items-center gap-3">
                                <button class="flex items-center text-gray-700">
                                    <span class="m-1 overflow-hidden rounded-full h-10 w-10">
                                        <img src="../assets/images/myimage.jpg" alt="User" />
                                    </span>
                                    <span class="block mr-1 font-medium text-sm">Administrator</span>
                                </button>
                                <a href="logout.php" class="ml-2 bg-purple-600 text-white px-3 py-2 rounded text-sm font-semibold">Logout</a>
                            </div>
                        </div>
                    </div>
                </header>

                <section class="min-h-screen bg-gray-100">
                    <main class="p-6 mx-auto max-w-screen-2xl">
                        <?php if (isset($error) && $error): ?>
                        <div class="mb-4">
                            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded" role="alert">
                                <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php
                        
                        if (function_exists('get_flash_message')) {
                            $flash = get_flash_message();
                            if ($flash) {
                                $type = $flash['type'] ?? 'info';
                                $msg = $flash['message'] ?? '';
                                $bg = 'bg-blue-100 text-blue-700 border-blue-200';
                                if ($type === 'success') { $bg = 'bg-green-100 text-green-700 border-green-200'; }
                                if ($type === 'error' || $type === 'danger') { $bg = 'bg-red-100 text-red-700 border-red-400'; }
                                echo '<div class="mb-4"><div class="'.$bg.' border px-4 py-3 rounded" role="alert">'.htmlspecialchars($msg).'</div></div>';
                            }
                        }
                        ?>
