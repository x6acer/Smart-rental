<?php
$page_title = "About Us - Smart Rental";
include __DIR__ . '/includes/header.php';
?>

<main class="mt-10 px-[clamp(1rem,6vw,100px)] max-lg:px-[clamp(1rem,4vw,40px)] max-md:px-2">

<!-- About Us section -->
<section id="about" class="py-16 bg-white">
    <div class="max-w-7xl mx-auto px-6 grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
        <div class="max-w-xl">
            <p class="text-yellow-500 font-semibold tracking-wide">— MEET FASTCAR</p>
            <h2 class="text-5xl font-bold text-gray-900 mt-3">About Us</h2>
            <p class="text-2xl font-medium text-gray-700 mt-4">We do everything for you to drive with maximum comfort!</p>
            <p class="text-gray-600 mt-6">Our company has been operating on the US car rental market for over 15 years. You can rent a car for any purpose, from a business trip to conquering roads with difficult surfaces.</p>
            <p class="text-gray-600 mt-4">We have several pick-up locations in New York so that you can take and leave the rental car in any convenient place. FASTCAR offers the most favorable conditions for car rental.</p>
            <div class="mt-8">
                <a href="tel:+233200853940" class="inline-block bg-[#2B98F6] text-white px-8 py-4 rounded-lg font-semibold hover:bg-[#2488e2] transition duration-300">CONTACT US</a>
            </div>
        </div>
        <div class="relative rounded-lg overflow-hidden h-[600px] bg-gray-100">
            <img src="/smart_rental/assets/images/about.jpg" alt="about-car" class="w-full h-full object-cover object-center">
        </div>
    </div>
</section>

<!-- Our Story section -->
<section id="history" class="py-16 bg-white">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-8">
            <h2 class="text-3xl font-extrabold text-gray-800">Our Story</h2>
            <p class="text-gray-600 mt-2">A short timeline of how SMART RENTAL grew to serve drivers across the region.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-start">
            <div>
                <ul class="space-y-6">
                    <li class="flex">
                        <div class="w-12 flex-shrink-0">
                            <div class="h-12 w-12 rounded-full bg-yellow-400 flex items-center justify-center font-bold">2014</div>
                        </div>
                        <div class="ml-4">
                            <h4 class="font-semibold">Founded</h4>
                            <p class="text-gray-600">SMART RENTAL began as a small local fleet with a simple mission: make car rental easy and dependable.</p>
                        </div>
                    </li>
                    <li class="flex">
                        <div class="w-12 flex-shrink-0">
                            <div class="h-12 w-12 rounded-full bg-yellow-400 flex items-center justify-center font-bold">2017</div>
                        </div>
                        <div class="ml-4">
                            <h4 class="font-semibold">Expansion</h4>
                            <p class="text-gray-600">We opened additional pickup locations and invested in a modern booking system to improve availability.</p>
                        </div>
                    </li>
                    <li class="flex">
                        <div class="w-12 flex-shrink-0">
                            <div class="h-12 w-12 rounded-full bg-yellow-400 flex items-center justify-center font-bold">2020</div>
                        </div>
                        <div class="ml-4">
                            <h4 class="font-semibold">Fleet Growth</h4>
                            <p class="text-gray-600">We diversified our fleet to include SUVs, luxury vehicles and trucks to meet more customer needs.</p>
                        </div>
                    </li>
                    <li class="flex">
                        <div class="w-12 flex-shrink-0">
                            <div class="h-12 w-12 rounded-full bg-yellow-400 flex items-center justify-center font-bold">2024</div>
                        </div>
                        <div class="ml-4">
                            <h4 class="font-semibold">Today</h4>
                            <p class="text-gray-600">We continue improving our service with transparent pricing, 24/7 support and regular maintenance standards.</p>
                        </div>
                    </li>
                </ul>
            </div>
            <div class="rounded-lg overflow-hidden shadow-lg">
                <img src="/smart_rental/assets/images/history.jpg" alt="Our history" class="w-full h-80 object-cover">
            </div>
        </div>
    </div>
</section>

<!-- Team section -->
<section id="team" class="py-16 bg-gray-50">
    <div class="max-w-7xl mx-auto px-6 text-center mb-8">
        <h2 class="text-3xl font-extrabold text-gray-800">Meet the Team</h2>
        <p class="text-gray-600 mt-2">A small team with a big commitment to great service.</p>
    </div>
    <div class="max-w-6xl mx-auto px-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6">
        <div class="text-center bg-white p-4 rounded-lg shadow">
            <img src="/smart_rental/assets/images/team1.jpg" alt="team member" class="w-28 h-28 rounded-full mx-auto object-cover">
            <h4 class="mt-4 font-bold">Muniru Mohammed</h4>
            <p class="text-sm text-gray-500">Operations Manager</p>
        </div>
        <div class="text-center bg-white p-4 rounded-lg shadow">
            <img src="/smart_rental/assets/images/team2.jpeg" alt="team member" class="w-28 h-28 rounded-full mx-auto object-cover">
            <h4 class="mt-4 font-bold">x6acer</h4>
            <p class="text-sm text-gray-500">Fleet Coordinator</p>
        </div>
        <div class="text-center bg-white p-4 rounded-lg shadow">
            <img src="/smart_rental/assets/images/team3.png" alt="team member" class="w-28 h-28 rounded-full mx-auto object-cover">
            <h4 class="mt-4 font-bold">O.G</h4>
            <p class="text-sm text-gray-500">Customer Success</p>
        </div>
        <div class="text-center bg-white p-4 rounded-lg shadow">
            <img src="/smart_rental/assets/images/team4.jpg" alt="team member" class="w-28 h-28 rounded-full mx-auto object-cover">
            <h4 class="mt-4 font-bold">Shadow</h4>
            <p class="text-sm text-gray-500">Lead Mechanic</p>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
