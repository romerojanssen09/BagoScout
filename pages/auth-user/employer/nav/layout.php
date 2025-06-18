<?php
// session_start();
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../../../");
    exit();
}

// Check if user is an employer
if (!hasRole('employer')) {
    redirect('../../../');
}

// Get current user
$currentUser = getCurrentUser();
if (!$currentUser) {
    header("Location: ../../../");
    exit();
}

// Default page title if not set
if (!isset($pageTitle)) {
    $pageTitle = "Dashboard";
}

// Default content if not set
if (!isset($content)) {
    $content = '';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BagoScout - <?php echo $pageTitle; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="../../../assets/css/custom.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.ably.io/lib/ably.min-1.js"></script>
    <script src="/bagoscout/assets/js/call-handler.js"></script>
    <script src="/bagoscout/assets/js/call-notification.js"></script>
    <script src="/bagoscout/assets/js/status-change-listener.js"></script>
    <script
        src="https://unpkg.com/@dotlottie/player-component@2.7.12/dist/dotlottie-player.mjs"
        type="module"></script>
    <?php if (isset($extraHeadContent)) echo $extraHeadContent; ?>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            overflow: hidden;
        }

        .main-layout {
            display: flex;
            height: 100vh;
        }

        .sidebar {
            width: 280px;
            flex-shrink: 0;
            transition: transform 0.3s ease;
        }

        .content-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .header {
            height: 64px;
            flex-shrink: 0;
        }

        .content-area {
            flex: 1;
            overflow-y: auto;
            /* padding: 1.5rem; */
        }

        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                z-index: 50;
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 40;
            }

            .sidebar-overlay.show {
                display: block;
            }
        }
    </style>
</head>

<body class="bg-gray-100" <?php
                            if (isset($bodyAttributes)) {
                                echo $bodyAttributes;
                            } else {
                                echo 'data-user-id="' . $currentUser['id'] . '" ' .
                                    'data-user-name="' . htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']) . '" ' .
                                    'data-user-role="employer" ' .
                                    'data-first-name="' . htmlspecialchars($currentUser['first_name']) . '" ' .
                                    'data-last-name="' . htmlspecialchars($currentUser['last_name']) . '"';
                            }
                            ?>>
    <div class="main-layout">
        <!-- Sidebar -->
        <div id="sidebar" class="sidebar">
            <?php include_once 'nav/nav.php'; ?>
        </div>

        <!-- Sidebar Overlay (Mobile) -->
        <div id="sidebar-overlay" class="sidebar-overlay"></div>

        <!-- Content Wrapper -->
        <div class="content-wrapper">
            <!-- Header -->
            <header class="header bg-white shadow-sm">
                <div class="px-4 h-full flex justify-between items-center">
                    <!-- Left: Mobile menu button -->
                    <div class="flex items-center">
                        <button id="mobile-menu-button" class="md:hidden p-2 rounded-md text-gray-600 hover:text-gray-800 focus:outline-none">
                            <i class="fas fa-bars"></i>
                        </button>
                        <h1 class="ml-2 md:ml-0 text-lg font-semibold text-gray-800"><?php echo $pageTitle; ?></h1>
                    </div>

                    <!-- Right: Notifications & User Menu -->
                    <div class="flex items-center space-x-4">
                        <!-- Notifications -->
                        <div class="relative" id="notifications-dropdown">
                            <button type="button" class="p-1 rounded-full text-gray-600 hover:text-gray-800 focus:outline-none" id="notifications-button">
                                <span class="sr-only">Notifications</span>
                                <i class="fas fa-bell text-xl"></i>
                                <span id="notifications-badge" class="hidden absolute -top-1 -right-1 bg-red-500 text-white text-xs w-5 h-5 flex items-center justify-center rounded-full">0</span>
                            </button>

                            <!-- Notifications Dropdown -->
                            <div class="hidden absolute right-0 mt-2 w-80 bg-white rounded-md shadow-lg py-1 z-50" id="notifications-menu">
                                <div class="px-4 py-2 border-b border-gray-200 flex justify-between items-center">
                                    <h3 class="text-sm font-semibold text-gray-800">Notifications</h3>
                                    <button type="button" class="text-xs text-blue-600 hover:text-blue-800" id="mark-all-read">Mark all as read</button>
                                </div>
                                <div class="max-h-64 overflow-y-auto" id="notifications-list">
                                    <div class="px-4 py-2 text-sm text-gray-500 text-center">
                                        <dotlottie-player
                                            src="https://lottie.host/466d7be2-3610-4ed8-8e8f-e99e8bfbfdd9/3NvcSCOcK0.lottie"
                                            background="transparent"
                                            speed="1"
                                            style="width: 300px; height: 300px"
                                            loop
                                            autoplay></dotlottie-player>
                                    </div>
                                </div>
                                <div class="px-4 py-2 border-t border-gray-200">
                                    <a href="notifications.php" class="text-xs text-blue-600 hover:text-blue-800 block text-center">View all notifications</a>
                                </div>
                            </div>
                        </div>

                        <!-- User Menu -->
                        <div class="relative" id="user-dropdown">
                            <button type="button" class="flex items-center focus:outline-none" id="user-menu-button">
                                <div class="w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold">
                                    <?php if (isset($currentUser['profile']) && $currentUser['profile']): ?>
                                        <img src="<?php echo $currentUser['profile']; ?>" alt="Profile" class="w-full h-full rounded-full object-cover">
                                    <?php else: ?>
                                        <?php echo strtoupper(substr($currentUser['first_name'], 0, 1)); ?>
                                    <?php endif; ?>
                                </div>
                                <span class="ml-2 text-sm font-medium text-gray-700 hidden sm:inline"><?php echo $currentUser['first_name']; ?></span>
                                <i class="fas fa-chevron-down ml-1 text-xs text-gray-400 hidden sm:inline"></i>
                            </button>

                            <!-- User Dropdown -->
                            <div class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50" id="user-menu">
                                <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-user mr-2"></i> Profile
                                </a>
                                <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i class="fas fa-cog mr-2"></i> Settings
                                </a>
                                <a href="../logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <div class="content-area md:p-4 p-2">
                <?php echo $content; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // User dropdown
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenu = document.getElementById('user-menu');

            userMenuButton.addEventListener('click', function() {
                userMenu.classList.toggle('hidden');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                if (!userMenuButton.contains(event.target) && !userMenu.contains(event.target)) {
                    userMenu.classList.add('hidden');
                }
            });

            // Mobile sidebar toggle
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebar-overlay');

            if (mobileMenuButton && sidebar && sidebarOverlay) {
                mobileMenuButton.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                    sidebarOverlay.classList.toggle('show');
                });

                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.remove('show');
                    sidebarOverlay.classList.remove('show');
                });
            }

            // Notifications dropdown
            const notificationsButton = document.getElementById('notifications-button');
            const notificationsMenu = document.getElementById('notifications-menu');
            const notificationsBadge = document.getElementById('notifications-badge');
            const notificationsList = document.getElementById('notifications-list');
            const markAllReadButton = document.getElementById('mark-all-read');

            notificationsButton.addEventListener('click', function() {
                notificationsMenu.classList.toggle('hidden');
                loadNotifications();
            });

            // Close notifications dropdown when clicking outside
            document.addEventListener('click', function(event) {
                if (!notificationsButton.contains(event.target) && !notificationsMenu.contains(event.target)) {
                    notificationsMenu.classList.add('hidden');
                }
            });

            // Load notifications
            function loadNotifications() {
                fetch('/api/notifications.php?action=get_notifications&limit=5')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            renderNotifications(data.notifications);
                        }
                    })
                    .catch(error => console.error('Error loading notifications:', error));
            }

            // Render notifications
            function renderNotifications(notifications) {
                if (notifications.length === 0) {
                    notificationsList.innerHTML = `
                    <div class="px-4 py-2 text-sm text-gray-500 text-center">
                        <p>No notifications</p>
                    </div>
                `;
                    return;
                }

                let html = '';

                notifications.forEach(notification => {
                    const isUnread = notification.is_read === '0';
                    const formattedDate = formatTimeAgo(notification.created_at);
                    let icon = '';

                    switch (notification.type) {
                        case 'message':
                            icon = '<i class="fas fa-envelope text-blue-500"></i>';
                            break;
                        case 'application':
                            icon = '<i class="fas fa-file-alt text-green-500"></i>';
                            break;
                        case 'comment':
                            icon = '<i class="fas fa-comment text-purple-500"></i>';
                            break;
                        case 'job':
                            icon = '<i class="fas fa-briefcase text-orange-500"></i>';
                            break;
                        case 'status_change':
                            icon = '<i class="fas fa-exchange-alt text-red-500"></i>';
                            break;
                        default:
                            icon = '<i class="fas fa-bell text-gray-500"></i>';
                    }

                    html += `
                    <div class="px-4 py-3 hover:bg-gray-50 border-b border-gray-100 ${isUnread ? 'bg-blue-50' : ''}" data-id="${notification.id}" data-reference-id="${notification.reference_id || ''}" data-reference-type="${notification.reference_type || ''}">
                        <div class="flex">
                            <div class="flex-shrink-0 mr-3">
                                ${icon}
                            </div>
                            <div class="flex-1">
                                <p class="text-sm text-gray-800">${notification.content}</p>
                                <p class="text-xs text-gray-500 mt-1">${formattedDate}</p>
                            </div>
                            ${isUnread ? '<button class="mark-read-btn text-xs text-blue-600 hover:text-blue-800 ml-2" data-id="' + notification.id + '">Mark read</button>' : ''}
                        </div>
                    </div>
                `;
                });

                notificationsList.innerHTML = html;

                // Add event listeners for mark as read buttons
                document.querySelectorAll('.mark-read-btn').forEach(button => {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();

                        const notificationId = this.getAttribute('data-id');
                        markAsRead(notificationId);
                    });
                });

                // Add event listeners for notification clicks
                document.querySelectorAll('#notifications-list > div').forEach(notification => {
                    notification.addEventListener('click', function() {
                        const notificationId = this.getAttribute('data-id');
                        const referenceId = this.getAttribute('data-reference-id');
                        const referenceType = this.getAttribute('data-reference-type');

                        // Mark as read
                        markAsRead(notificationId);

                        // Navigate to the reference if available
                        if (referenceId && referenceType) {
                            navigateToReference(referenceId, referenceType);
                        }
                    });
                });
            }

            // Mark notification as read
            function markAsRead(notificationId) {
                const formData = new FormData();
                formData.append('notification_id', notificationId);

                fetch('/api/notifications.php?action=mark_as_read', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            loadNotifications();
                            updateNotificationCount();
                        }
                    })
                    .catch(error => console.error('Error marking notification as read:', error));
            }

            // Mark all notifications as read
            markAllReadButton.addEventListener('click', function() {
                fetch('/api/notifications.php?action=mark_all_as_read', {
                        method: 'POST'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            loadNotifications();
                            updateNotificationCount();
                        }
                    })
                    .catch(error => console.error('Error marking all notifications as read:', error));
            });

            // Update notification count
            function updateNotificationCount() {
                fetch('../../../api/notifications.php?action=get_unread_count')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (data.count > 0) {
                                notificationsBadge.textContent = data.count > 99 ? '99+' : data.count;
                                notificationsBadge.classList.remove('hidden');
                            } else {
                                notificationsBadge.classList.add('hidden');
                            }
                        }
                    })
                    .catch(error => console.error('Error updating notification count:', error));
            }

            // Format time ago
            function formatTimeAgo(datetime) {
                const now = new Date();
                const date = new Date(datetime);
                const seconds = Math.floor((now - date) / 1000);

                let interval = Math.floor(seconds / 31536000);
                if (interval > 1) return interval + ' years ago';
                if (interval === 1) return '1 year ago';

                interval = Math.floor(seconds / 2592000);
                if (interval > 1) return interval + ' months ago';
                if (interval === 1) return '1 month ago';

                interval = Math.floor(seconds / 86400);
                if (interval > 1) return interval + ' days ago';
                if (interval === 1) return '1 day ago';

                interval = Math.floor(seconds / 3600);
                if (interval > 1) return interval + ' hours ago';
                if (interval === 1) return '1 hour ago';

                interval = Math.floor(seconds / 60);
                if (interval > 1) return interval + ' minutes ago';
                if (interval === 1) return '1 minute ago';

                if (seconds < 10) return 'just now';

                return Math.floor(seconds) + ' seconds ago';
            }

            // Navigate to reference
            function navigateToReference(referenceId, referenceType) {
                switch (referenceType) {
                    case 'message':
                        window.location.href = 'message.php?conversation=' + referenceId;
                        break;
                    case 'job':
                        window.location.href = 'view-job.php?id=' + referenceId;
                        break;
                    case 'application':
                        window.location.href = 'view-applications.php?job=' + referenceId;
                        break;
                        // Add more cases as needed
                }
            }

            // Initialize Ably for real-time notifications
            function initAbly() {
                fetch('../../../api/ably-auth.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.token) {
                            const ably = new Ably.Realtime({
                                token: data.token,
                                clientId: data.clientId
                            });

                            ably.connection.on('connected', () => {
                                console.log('Connected to Ably for notifications');

                                // Subscribe to private channel for notifications
                                const privateChannel = ably.channels.get(`private:user-${data.user_id}`);

                                privateChannel.subscribe('notification', (message) => {
                                    // Update notification count
                                    updateNotificationCount();

                                    // Show notification if menu is open
                                    if (!notificationsMenu.classList.contains('hidden')) {
                                        loadNotifications();
                                    }

                                    // Show browser notification
                                    const notificationData = JSON.parse(message.data);
                                    showBrowserNotification(notificationData.content);
                                });
                            });
                        }
                    })
                    .catch(error => console.error('Error initializing Ably:', error));
            }

            // Show browser notification
            function showBrowserNotification(message) {
                if ('Notification' in window) {
                    if (Notification.permission === 'granted') {
                        new Notification('BagoScout', {
                            body: message,
                            icon: '/assets/images/logo.png'
                        });
                    } else if (Notification.permission !== 'denied') {
                        Notification.requestPermission().then(permission => {
                            if (permission === 'granted') {
                                new Notification('BagoScout', {
                                    body: message,
                                    icon: '/assets/images/logo.png'
                                });
                            }
                        });
                    }
                }
            }

            // Initialize
            updateNotificationCount();
            initAbly();
        });
    </script>

    <?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>

</html>