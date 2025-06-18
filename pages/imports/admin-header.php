<?php
// admin-header.php - Complete Admin Dashboard Header

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Get admin information
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$admin = null;
$pendingUsers = 0;
$unreadMessages = 0;

$conn = getDbConnection();
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->bind_param("i", $_SESSION['admin_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: login.php");
    exit();
}

$admin = $result->fetch_assoc();

// Get pending approval count
$pendingStmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE status = 'under_review'");
$pendingStmt->execute();
$pendingUsers = $pendingStmt->get_result()->fetch_assoc()['count'];

// Get unread messages count
$messageStmt = $conn->prepare("SELECT COUNT(*) as count FROM contact_messages WHERE status = 'unread'");
$messageStmt->execute();
$unreadMessages = $messageStmt->get_result()->fetch_assoc()['count'];

$conn->close();

// Get current page for active link highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BagoScout - <?php echo isset($pageTitle) ? $pageTitle : 'Admin Panel'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../../assets/css/custom.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.ably.io/lib/ably.min-1.js"></script>
    <script src="../../assets/js/admin-realtime.js"></script>
    <style>
        .active-nav-link {
            background-color: #2563eb;
            color: white;
        }
        .profile-initial {
            background-color: #4f46e5;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.2rem;
        }
        
        /* Burger menu styles */
        .burger-menu {
            width: 18px;
            height: 15px;
            position: relative;
            cursor: pointer;
            display: inline-block;
        }
        
        .burger-menu span {
            display: block;
            position: absolute;
            height: 3px;
            width: 100%;
            background: white;
            border-radius: 3px;
            opacity: 1;
            left: 0;
            transform: rotate(0deg);
            transition: .25s ease-in-out;
        }
        
        .burger-menu span:nth-child(1) {
            top: 0px;
        }
        
        .burger-menu span:nth-child(2), .burger-menu span:nth-child(3) {
            top: 8px;
        }
        
        .burger-menu span:nth-child(4) {
            top: 16px;
        }
        
        .burger-menu.open span:nth-child(1) {
            top: 8px;
            width: 0%;
            left: 50%;
        }
        
        .burger-menu.open span:nth-child(2) {
            transform: rotate(45deg);
        }
        
        .burger-menu.open span:nth-child(3) {
            transform: rotate(-45deg);
        }
        
        .burger-menu.open span:nth-child(4) {
            top: 8px;
            width: 0%;
            left: 50%;
        }
        
        /* Mobile sidebar styles */
        @media (max-width: 768px) {
            .mobile-sidebar-overlay {
                background-color: rgba(0, 0, 0, 0.5);
                transition: opacity 0.3s ease;
                z-index: 800;
            }
            
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                z-index: 900;
                height: 100vh;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .mobile-menu-btn {
                display: flex !important;
                z-index: 1000;
            }
        }
        
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 15px;
            left: 15px;
        }
        
        /* Desktop styles */
        @media (min-width: 769px) {
            .main-content {
                margin-left: 16rem;
            }
            
            .sidebar {
                height: 100vh;
                overflow-y: auto;
            }
        }
    </style>
</head>
<body class="bg-gray-100">

<!-- Mobile Menu Button -->
<div class="mobile-menu-btn fixed bg-blue-600 text-white p-1 rounded-md shadow-lg md:hidden z-[1000] flex items-center justify-center" id="mobileMenuBtn" style="box-shadow: 0 4px 10px rgba(0,0,0,0.3); top: 10px; left: 10px; width: 35px; height: 35px;">
    <div class="burger-menu">
        <span></span>
        <span></span>
        <span></span>
        <span></span>
    </div>
</div>

<div class="flex relative">
    <!-- Mobile sidebar overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-[800] hidden md:hidden"></div>
    
    <!-- Sidebar -->
    <div id="sidebar" class="sidebar w-64 bg-gray-800 text-white fixed h-full overflow-y-auto z-[900] md:translate-x-0 -translate-x-full transition-transform duration-300 ease-in-out">
        <div class="p-4 border-b border-gray-700">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <span class="text-2xl font-bold text-blue-500">B</span>
                    <span class="text-xl font-semibold text-white">agoScout</span>
                </div>
                <!-- Close button for mobile -->
                <button class="md:hidden text-gray-300 hover:text-white" id="closeSidebarBtn">
                    <div class="burger-menu open">
                        <span></span>
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </button>
            </div>
            <p class="text-xs text-gray-400 mt-1">Admin Panel</p>
        </div>
        
        <div class="p-4 border-b border-gray-700">
            <div class="flex items-center space-x-3">
                <div class="profile-initial">
                    <?php echo isset($admin) ? strtoupper(substr($admin['first_name'] . ' ' . $admin['last_name'], 0, 1)) : 'A'; ?>
                </div>
                <div>
                    <p class="text-sm font-medium text-white"><?php echo isset($admin) ? htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) : 'Admin'; ?></p>
                    <p class="text-xs text-gray-400">Administrator</p>
                </div>
            </div>
        </div>
        
        <nav class="mt-4">
            <div class="px-4 py-2">
                <p class="text-xs uppercase text-gray-500 font-semibold tracking-wider">Main</p>
            </div>
            <ul>
                <li>
                    <a href="dashboard.php" class="flex items-center px-6 py-3 hover:bg-gray-700 transition duration-200 <?php echo $currentPage === 'dashboard.php' ? 'active-nav-link' : ''; ?>">
                        <i class="fas fa-tachometer-alt w-5 mr-3"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="users.php" class="flex items-center px-6 py-3 hover:bg-gray-700 transition duration-200 <?php echo $currentPage === 'users.php' ? 'active-nav-link' : ''; ?>">
                        <i class="fas fa-user-shield w-5 mr-3"></i>
                        <span>Requests</span>
                        <?php if ($pendingUsers > 0): ?>
                        <span class="ml-auto inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-red-100 bg-red-600 rounded-full"><?php echo $pendingUsers; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="employers.php" class="flex items-center px-6 py-3 hover:bg-gray-700 transition duration-200 <?php echo $currentPage === 'employers.php' || $currentPage === 'view-employer.php' ? 'active-nav-link' : ''; ?>">
                        <i class="fas fa-building w-5 mr-3"></i>
                        <span>Employers</span>
                    </a>
                </li>
                <li>
                    <a href="jobseekers.php" class="flex items-center px-6 py-3 hover:bg-gray-700 transition duration-200 <?php echo $currentPage === 'jobseekers.php' || $currentPage === 'view-jobseeker.php' ? 'active-nav-link' : ''; ?>">
                        <i class="fas fa-user-tie w-5 mr-3"></i>
                        <span>Job Seekers</span>
                    </a>
                </li>
                
                <div class="px-4 py-2 mt-4">
                    <p class="text-xs uppercase text-gray-500 font-semibold tracking-wider">Management</p>
                </div>
                
                <li>
                    <a href="contacts.php" class="flex items-center px-6 py-3 hover:bg-gray-700 transition duration-200 <?php echo $currentPage === 'contacts.php' ? 'active-nav-link' : ''; ?>">
                        <i class="fas fa-envelope w-5 mr-3"></i>
                        <span>Messages</span>
                        <?php if ($unreadMessages > 0): ?>
                        <span class="ml-auto inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-red-100 bg-red-600 rounded-full"><?php echo $unreadMessages; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                
                <div class="px-4 py-2 mt-4">
                    <p class="text-xs uppercase text-gray-500 font-semibold tracking-wider">Account</p>
                </div>
                
                <li>
                    <a href="settings.php" class="flex items-center px-6 py-3 hover:bg-gray-700 transition duration-200 <?php echo $currentPage === 'settings.php' ? 'active-nav-link' : ''; ?>">
                        <i class="fas fa-cog w-5 mr-3"></i>
                        <span>Settings</span>
                    </a>
                </li>
                <li>
                    <a href="logout.php" class="flex items-center px-6 py-3 text-red-400 hover:bg-gray-700 transition duration-200">
                        <i class="fas fa-sign-out-alt w-5 mr-3"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    
    <!-- Main Content Container -->
    <div class="main-content w-full">
        <!-- Individual page content will be inserted here -->

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const sidebar = document.getElementById('sidebar');
                const sidebarOverlay = document.getElementById('sidebarOverlay');
                const mobileMenuBtn = document.getElementById('mobileMenuBtn');
                const burgerMenu = document.querySelector('#mobileMenuBtn .burger-menu');
                const closeSidebarBtn = document.getElementById('closeSidebarBtn');
                
                // Function to open sidebar
                function openSidebar() {
                    sidebar.classList.add('open');
                    sidebarOverlay.classList.remove('hidden');
                    burgerMenu.classList.add('open');
                    document.body.style.overflow = 'hidden';
                }
                
                // Function to close sidebar
                function closeSidebar() {
                    sidebar.classList.remove('open');
                    sidebarOverlay.classList.add('hidden');
                    burgerMenu.classList.remove('open');
                    document.body.style.overflow = '';
                }
                
                // Toggle sidebar on mobile menu button click
                mobileMenuBtn.addEventListener('click', function() {
                    if (sidebar.classList.contains('open')) {
                        closeSidebar();
                    } else {
                        openSidebar();
                    }
                });
                
                // Close sidebar when close button is clicked
                closeSidebarBtn.addEventListener('click', closeSidebar);
                
                // Close sidebar when overlay is clicked
                sidebarOverlay.addEventListener('click', closeSidebar);
                
                // Close sidebar when clicking on a link (for mobile)
                const navLinks = document.querySelectorAll('#sidebar nav a');
                navLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        if (window.innerWidth < 768) {
                            closeSidebar();
                        }
                    });
                });
                
                // Handle window resize
                window.addEventListener('resize', function() {
                    if (window.innerWidth >= 768) {
                        closeSidebar();
                    }
                });
            });
        </script>
</body>
</html>