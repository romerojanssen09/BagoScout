<?php
// Determine if user is logged in
$isLoggedIn = isset($_SESSION['user_id']);

// Get current page for active link highlighting
$currentPage = basename($_SERVER['PHP_SELF']);

// Determine the path prefix based on the current directory depth
$pathPrefix = '';

// Get the current script path
$currentPath = $_SERVER['PHP_SELF'];

// Root level (index.php)
if (strpos($currentPath, '/bagoscout/index.php') !== false) {
    $pathPrefix = 'pages/';
}
// Auth directory (register and login pages)
elseif (strpos($currentPath, '/pages/auth/') !== false) {
    $pathPrefix = '../';
}
// Pages directory (about, contact)
elseif (strpos($currentPath, '/pages/') !== false) {
    $pathPrefix = '';
} elseif (strpos($currentPath, '/bagoscout/index.php') !== false) {
    // Root level (index.php)
    $pathPrefix = 'pages/';
}

// Fix for auth directory links to prevent path duplication
if (strpos($currentPath, '/pages/auth/') !== false) {
    // Override specific links in the header
    $indexLink = '../../index.php';
    $aboutLink = '../about.php';
    $contactLink = '../contact.php';
    $registerLink = 'register-step1.php';
} elseif (strpos($currentPath, '/pages/') !== false) {
    // Fix for pages directory
    $indexLink = '../index.php';
    $aboutLink = 'about.php';
    $contactLink = 'contact.php';
    $registerLink = 'auth/register-step1.php';
} elseif (strpos($currentPath, '/bagoscout/index.php') !== false) {
    // Root level (index.php)
    $indexLink = 'index.php';
    $aboutLink = 'pages/about.php';
    $contactLink = 'pages/contact.php';
    $registerLink = 'pages/auth/register-step1.php';
}

// Get user data if logged in
$user = null;
if ($isLoggedIn && function_exists('getCurrentUser')) {
    $user = getCurrentUser();
}
?>

<!-- Header -->
<header class="bg-white py-4 shadow-sm">
    <div class="container mx-auto px-4">
        <div class="flex justify-between items-center">
            <!-- Logo -->
            <a href="<?php echo $indexLink; ?>" class="flex items-center">
                <span class="text-3xl font-bold text-blue-500">B</span>
                <span class="text-xl font-semibold text-gray-800">agoScout.</span>
            </a>
            
            <!-- Desktop Navigation -->
            <nav class="hidden md:flex space-x-6">
                <a href="<?php echo $indexLink; ?>" class="nav-link <?php echo $currentPage == 'index.php' ? 'active' : ''; ?>">Home</a>
                <a href="<?php echo $aboutLink; ?>" class="nav-link <?php echo $currentPage == 'about.php' ? 'active' : ''; ?>">About</a>
                <a href="<?php echo $contactLink; ?>" class="nav-link <?php echo $currentPage == 'contact.php' ? 'active' : ''; ?>">Contact</a>
                <?php if ($isLoggedIn): ?>
                <a href="<?php echo $pathPrefix; ?>dashboard.php" class="nav-link <?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>">Dashboard</a>
                <?php endif; ?>
            </nav>
            
            <!-- Desktop Auth Buttons -->
            <div class="hidden md:flex items-center space-x-4">
                <?php if ($isLoggedIn): ?>
                    <!-- User Menu -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="flex items-center space-x-2 focus:outline-none">
                            <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center text-white">
                                <?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
                            </div>
                            <span class="font-medium text-gray-700"><?php echo htmlspecialchars($user['name'] ?? 'User'); ?></span>
                            <i class="fas fa-chevron-down text-xs text-gray-500"></i>
                        </button>
                        
                        <div x-show="open" @click.away="open = false" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50 hidden">
                            <a href="<?php echo $pathPrefix; ?>dashboard.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                <i class="fas fa-tachometer-alt mr-2"></i> Dashboard
                            </a>
                            <a href="<?php echo $pathPrefix; ?>profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                <i class="fas fa-user mr-2"></i> Profile
                            </a>
                            <a href="<?php echo $pathPrefix; ?>message.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                <i class="fas fa-comments mr-2"></i> Messages
                            </a>
                            <a href="<?php echo $pathPrefix; ?>settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                <i class="fas fa-cog mr-2"></i> Settings
                            </a>
                            <div class="border-t border-gray-100"></div>
                            <a href="<?php echo $pathPrefix; ?>logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                                <i class="fas fa-sign-out-alt mr-2"></i> Logout
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <button id="loginBtn" class="px-4 py-2 bg-gray-100 text-gray-800 font-medium rounded-md hover:bg-gray-200 transition duration-300">Log In</button>
                    <a href="<?php echo $registerLink; ?>" class="px-4 py-2 bg-blue-800 text-white font-medium rounded-md hover:bg-blue-900 transition duration-300">Create an account</a>
                <?php endif; ?>
            </div>

            <!-- Mobile Menu Button -->
            <button id="mobileMenuBtn" class="md:hidden text-gray-600 hover:text-gray-800 focus:outline-none">
                <i class="fas fa-bars text-2xl"></i>
            </button>
        </div>
    </div>
</header>

<!-- Mobile Menu (Hidden by default) -->
<div id="mobileMenu" class="fixed inset-0 bg-gray-900 bg-opacity-95 z-50 hidden">
    <div class="flex flex-col h-full">
        <!-- Mobile Menu Header -->
        <div class="flex justify-between items-center p-4 border-b border-gray-800">
            <a href="<?php echo $indexLink; ?>" class="flex items-center">
                <span class="text-3xl font-bold text-blue-500">B</span>
                <span class="text-xl font-semibold text-white">agoScout.</span>
            </a>
            <button id="closeMobileMenu" class="text-white text-2xl hover:text-gray-300">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Mobile Navigation -->
        <nav class="flex-1 overflow-y-auto py-4">
            <div class="px-4 space-y-1">
                <a href="<?php echo $indexLink; ?>" class="block py-3 text-white text-lg font-medium hover:bg-gray-800 px-4 rounded-md">Home</a>
                <a href="<?php echo $aboutLink; ?>" class="block py-3 text-white text-lg font-medium hover:bg-gray-800 px-4 rounded-md">About</a>
                <a href="<?php echo $contactLink; ?>" class="block py-3 text-white text-lg font-medium hover:bg-gray-800 px-4 rounded-md">Contact</a>
                
                <?php if ($isLoggedIn): ?>
                    <a href="<?php echo $pathPrefix; ?>dashboard.php" class="block py-3 text-white text-lg font-medium hover:bg-gray-800 px-4 rounded-md">Dashboard</a>
                    <a href="<?php echo $pathPrefix; ?>profile.php" class="block py-3 text-white text-lg font-medium hover:bg-gray-800 px-4 rounded-md">Profile</a>
                    <a href="<?php echo $pathPrefix; ?>message.php" class="block py-3 text-white text-lg font-medium hover:bg-gray-800 px-4 rounded-md">Messages</a>
                    <a href="<?php echo $pathPrefix; ?>settings.php" class="block py-3 text-white text-lg font-medium hover:bg-gray-800 px-4 rounded-md">Settings</a>
                    <a href="<?php echo $pathPrefix; ?>logout.php" class="block py-3 text-red-400 text-lg font-medium hover:bg-gray-800 px-4 rounded-md">Logout</a>
                <?php else: ?>
                    <div class="pt-4 space-y-3">
                        <button id="mobileLoginBtn" class="w-full px-4 py-3 bg-gray-100 text-gray-800 font-medium rounded-md hover:bg-gray-200 transition duration-300">Log In</button>
                        <a href="<?php echo $registerLink; ?>" class="block w-full px-4 py-3 bg-blue-800 text-white font-medium rounded-md hover:bg-blue-900 transition duration-300 text-center">Create an account</a>
                    </div>
                <?php endif; ?>
            </div>
        </nav>
    </div>
</div>

<script>
    // Mobile menu functionality
    document.addEventListener('DOMContentLoaded', function() {
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        const closeMobileMenu = document.getElementById('closeMobileMenu');
        const mobileLoginBtn = document.getElementById('mobileLoginBtn');
        
        if (mobileMenuBtn && mobileMenu) {
            mobileMenuBtn.addEventListener('click', function() {
                mobileMenu.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            });
            
            if (closeMobileMenu) {
                closeMobileMenu.addEventListener('click', function() {
                    mobileMenu.classList.add('hidden');
                    document.body.style.overflow = '';
                });
            }
            
            if (mobileLoginBtn) {
                mobileLoginBtn.addEventListener('click', function() {
                    mobileMenu.classList.add('hidden');
                    document.body.style.overflow = '';
                    
                    // Trigger the login modal
                    const loginModal = document.getElementById('loginModal');
                    if (loginModal) {
                        loginModal.classList.add('active');
                    }
                });
            }
        }
        
        // User dropdown menu functionality
        const userMenu = document.querySelector('.relative[x-data]');
        if (userMenu) {
            const dropdown = userMenu.querySelector('div[x-show]');
            
            userMenu.querySelector('button').addEventListener('click', function() {
                dropdown.classList.toggle('hidden');
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                if (userMenu && !userMenu.contains(event.target)) {
                    dropdown.classList.add('hidden');
                }
            });
        }
    });
</script>
