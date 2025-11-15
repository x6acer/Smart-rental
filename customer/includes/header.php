<?php

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

require_once __DIR__ . '/../../config.php';
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo $page_title ?? 'Smart Rental'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
      @import url('https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&family=Segoe+UI:wght@400;700&display=swap');
    </style>
  </head>
  <body class="font-['Segoe_UI',Tahoma,Geneva,Verdana,sans-serif] bg-[#f9f9f8] text-[#1b4b4b] p-0 m-0">
  
    <header class="font-['Open_Sans',Arial,sans-serif] bg-[#f9f9f8] shadow-[0_2px_8px_#f7f7f7] px-[clamp(1rem,6vw,100px)] pb-2">
      <div class="flex items-center justify-between py-4 px-12 max-lg:px-[clamp(1rem,4vw,40px)] max-md:flex-col max-md:gap-4 max-md:px-2">
        <div class="logo flex items-center gap-2">
          <h1 class="text-2xl font-bold text-[#1b4b4b] tracking-wider leading-tight m-0 flex gap-2">Smart<br />Rental</h1>
        </div>
        <nav class="flex items-center gap-7 max-md:gap-2 max-md:flex-wrap text-base">
          <ul id="nav" class="flex items-center gap-8 list-none m-0 p-0 max-md:gap-2">
            <li><a href="/smart_rental/customer/index.php" class="no-underline text-[#1b4b4b] font-bold text-base tracking-wide pb-0.5 hover:text-[#0d3636] hover:border-b-2 hover:border-[#1b4b4b]">Home</a></li>
            <li><a href="/smart_rental/customer/about.php" class="no-underline text-[#1b4b4b] font-bold text-base tracking-wide pb-0.5 hover:text-[#0d3636] hover:border-b-2 hover:border-[#1b4b4b]">About</a></li>
            <li><a href="/smart_rental/customer/items_details.php" class="no-underline text-[#1b4b4b] font-bold text-base tracking-wide pb-0.5 hover:text-[#0d3636] hover:border-b-2 hover:border-[#1b4b4b]">Browse</a></li>
            <li><a href="/smart_rental/customer/cart.php" class="no-underline text-[#1b4b4b] font-bold text-base tracking-wide pb-0.5 hover:text-[#0d3636] hover:border-b-2 hover:border-[#1b4b4b]">Cart</a></li>
          </ul>
          <div id="acc-btn" class="flex gap-2 ml-6 max-md:ml-0">
            <?php if(isset($_SESSION['customer_id'])): ?>
              <p class="m-0">
                <a href="/smart_rental/customer/order_summary.php" class="text-[#1b4b4b] font-bold rounded px-4 py-2 transition bg-transparent hover:bg-[#0d3636] hover:text-[#f9f9f8]">My Orders</a>
              </p>
              <p class="m-0">
                <a href="/smart_rental/customer/logout.php" class="bg-[#268383ad] text-white font-bold rounded px-4 py-2 transition hover:bg-[#0d3636] hover:text-[#f9f9f8]">Logout</a>
              </p>
            <?php else: ?>
              <p class="m-0">
                <a href="/smart_rental/customer/login.php" class="text-[#1b4b4b] font-bold rounded px-4 py-2 transition bg-transparent hover:bg-[#0d3636] hover:text-[#f9f9f8]">Log in</a>
              </p>
              <p class="m-0">
                <a href="/smart_rental/customer/register.php" class="bg-[#268383ad] text-white font-bold rounded px-4 py-2 transition hover:bg-[#0d3636] hover:text-[#f9f9f8]">Sign Up</a>
              </p>
            <?php endif; ?>
          </div>
        </nav>
      </div>
      <div id="sub-header" class="flex items-center justify-between bg-[#f9f9f8] px-12 py-4 gap-6 border-t border-[#e0e0e0] flex-wrap max-lg:px-[clamp(1rem,4vw,40px)] max-md:flex-col max-md:items-stretch max-md:px-2">
        <div class="flex items-center gap-4 relative group">
          <button class="flex items-center bg-[#1b4b4b] text-[#f9f9f8] font-bold text-base px-5 py-2 rounded mr-2 gap-2">
            <span class="flex flex-col gap-[2px]">
              <span class="block w-5 h-0.5 bg-white"></span>
              <span class="block w-5 h-0.5 bg-white"></span>
              <span class="block w-5 h-0.5 bg-white"></span>
            </span>
            <span>Categories</span>
            <span>▾</span>
          </button>
          <div class="relative">
            <ul class="bg-[#f9f9f8] p-2 rounded shadow-lg list-none absolute hidden min-w-[8.75rem] mt-1 z-10 group-hover:block group-focus-within:block">
              <?php
              require_once __DIR__ . '/../../db_connect.php';
              $db = db_get_conn();
              // Detect whether categories.slug exists in this database. If it
              // does, use slug-based URLs; otherwise fall back to category id.
              $colSlugRes = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'slug'");
              $hasSlug = false;
              if ($colSlugRes) {
                  $rr = $colSlugRes->fetch_assoc();
                  $hasSlug = !empty($rr['cnt']);
              }

              if ($hasSlug) {
                  $categories = $db->query("SELECT name, slug FROM categories ORDER BY name");
              } else {
                  $categories = $db->query("SELECT id, name FROM categories ORDER BY name");
              }

              while($cat = $categories->fetch_assoc()): 
              ?>
                <li>
                  <?php if ($hasSlug): ?>
                  <a href="/smart_rental/customer/items_details.php?category=<?php echo urlencode($cat['slug']); ?>" 
                     class="block px-4 py-2 text-[#333] text-base no-underline hover:bg-[#e6f5f4]">
                    <?php echo htmlspecialchars($cat['name']); ?>
                  </a>
                  <?php else: ?>
                  <a href="/smart_rental/customer/items_details.php?category_id=<?php echo intval($cat['id']); ?>" 
                     class="block px-4 py-2 text-[#333] text-base no-underline hover:bg-[#e6f5f4]">
                    <?php echo htmlspecialchars($cat['name']); ?>
                  </a>
                  <?php endif; ?>
                </li>
              <?php endwhile; ?>
            </ul>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <form action="/smart_rental/customer/items_details.php" method="GET" class="flex">
            <input type="text" name="search" placeholder="Search vehicles..." 
                   class="border-none px-3 py-2 rounded-l bg-white text-base shadow outline-none"
                   value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"/>
            <button type="submit" class="rounded-r px-4 py-2 bg-[#1b4b4b] text-[#f9f9f8] font-bold ml-[-0.5rem]">Search</button>
          </form>
        </div>
        <div class="contact-info flex items-center gap-2 bg-[#f9f9f8] rounded-full px-4 py-2 shadow">
          <div><p class="m-0 font-bold text-[#1b4b4b]"><a href="tel:+233200853940" class="no-underline text-[#1b4b4b]">+233 20 085 3940</a></p></div>
        </div>
      </div>
    </header>
    <main class="mt-10 px-[clamp(1rem,6vw,100px)] max-lg:px-[clamp(1rem,4vw,40px)] max-md:px-2">
