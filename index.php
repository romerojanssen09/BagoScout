<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Set page title
$pageTitle = "Job Portal for Employers and Job Seekers";

// Check if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BagoScout - <?php echo $pageTitle; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="assets/css/custom.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-white font-sans">
    <?php include 'pages/imports/header.php'; ?>
    
    <!-- Main Content -->
    <main class="min-h-[80vh] pt-12 md:py-8">
        <!-- Hero Section -->
        <section class="container mx-auto px-4">
            <div class="flex flex-col lg:flex-row items-center gap-12">
                <!-- Left Content -->
                <div class="w-full lg:w-1/2 text-center lg:text-left">
                    <div class="mb-8">
                        <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-gray-900 mb-6 leading-tight">
                            A <span class="text-indigo-600">Job Portal</span> for<br class="hidden md:block">
                            Employers<br class="hidden md:block">
                            and Job Seekers.
                        </h1>
                        <p class="text-base md:text-lg text-gray-600 mb-8 max-w-2xl mx-auto lg:mx-0">
                            Bago Scout is a platform tailored to optimize your job
                            seeking & hiring experience with the use of Geospatial
                            Feature, Smart Recommendations, and more.
                        </p>
                        <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
                            <a href="pages/auth/register-step1.php" class="px-6 py-3 bg-blue-800 text-white font-medium rounded-md hover:bg-blue-900 transition duration-300 text-center">Get Started</a>
                            <a href="pages/about.php" class="px-6 py-3 bg-gray-100 text-gray-800 font-medium rounded-md hover:bg-gray-200 transition duration-300 text-center">Learn More</a>
                        </div>
                    </div>
                    
                    <!-- Search Features -->
                    <div class="grid grid-cols-1 sm:grid-cols-2  gap-6 max-w-2xl mx-auto lg:mx-0">
                        <div class="flex items-start p-4 bg-white rounded-lg shadow-sm hover:shadow-md transition duration-300">
                            <div class="mr-4 text-blue-600">
                                <i class="fas fa-search text-2xl"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-800 mb-2">Find Quick Job Offers</h3>
                                <p class="text-gray-600 text-sm">Search for available jobs in your local area.</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start p-4 bg-white rounded-lg shadow-sm hover:shadow-md transition duration-300">
                            <div class="mr-4 text-blue-600">
                                <i class="fas fa-map-marker-alt text-2xl"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-800 mb-2">A Job Portal for Locals</h3>
                                <p class="text-gray-600 text-sm">Tailored for the residents of Bago City.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Content - Map Illustration -->
                <div class="w-full lg:w-1/2">
                    <img src="assets/images/map-bg.png" alt="Map" class="w-full h-auto rounded-lg">
                </div>
            </div>
        </section>

        <!-- Additional Features Section -->
        <section class="mt-20 px-12">
            <h2 class="text-3xl md:text-4xl font-bold text-center mb-12">Why Choose BagoScout?</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="p-6 bg-white rounded-lg shadow-sm hover:shadow-md transition duration-300">
                    <div class="text-blue-600 mb-4">
                        <i class="fas fa-bullseye text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-3">Smart Matching</h3>
                    <p class="text-gray-600">Our AI-powered system matches you with the perfect job opportunities based on your skills and preferences.</p>
                </div>

                <!-- Feature 2 -->
                <div class="p-6 bg-white rounded-lg shadow-sm hover:shadow-md transition duration-300">
                    <div class="text-blue-600 mb-4">
                        <i class="fas fa-map-marked-alt text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-3">Local Focus</h3>
                    <p class="text-gray-600">Specifically designed for Bago City residents and businesses, ensuring relevant local opportunities.</p>
                </div>

                <!-- Feature 3 -->
                <div class="p-6 bg-white rounded-lg shadow-sm hover:shadow-md transition duration-300">
                    <div class="text-blue-600 mb-4">
                        <i class="fas fa-shield-alt text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-3">Verified Listings</h3>
                    <p class="text-gray-600">All job listings and employers are verified to ensure a safe and reliable job search experience.</p>
                </div>
            </div>
        </section>
    </main>
    
    <?php include 'pages/imports/footer.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Check for registration success
            <?php if (isset($_SESSION['registration_success'])): ?>
            Swal.fire({
                title: 'Registration Successful!',
                text: 'Your account has been created successfully. You can now log in.',
                icon: 'success',
                confirmButtonText: 'OK',
                confirmButtonColor: '#2563eb'
            }).then((result) => {
                <?php unset($_SESSION['registration_success']); ?>
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>
