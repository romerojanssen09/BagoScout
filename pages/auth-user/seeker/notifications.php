<?php
session_start();
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn() || !hasRole('jobseeker')) {
    header("Location: ../../../index.php");
    exit();
}

// Get current user
$currentUser = getCurrentUser();

// Set page title and content
$pageTitle = "Notifications";
$content = '';

// Get notifications
$conn = getDbConnection();
$stmt = $conn->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $currentUser['id']);
$stmt->execute();
$result = $stmt->get_result();
$notifications = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

// Mark all notifications as read
markAllNotificationsAsRead($currentUser['id']);

// Create notification list HTML
$notificationsList = '';

if (empty($notifications)) {
    $notificationsList = '
        <div class="text-center py-8">
            <div class="mx-auto w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                <i class="fas fa-bell text-gray-400 text-xl"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900">No notifications</h3>
            <p class="text-gray-500 mt-1">You don\'t have any notifications at this time.</p>
        </div>
    ';
} else {
    foreach ($notifications as $notification) {
        $isRead = $notification['is_read'] == 1;
        $formattedDate = time_elapsed_string($notification['created_at']);
        $icon = '';
        
        // Set icon based on notification type
        switch ($notification['type']) {
            case 'message':
                $icon = '<i class="fas fa-envelope text-blue-500"></i>';
                break;
            case 'application':
                $icon = '<i class="fas fa-file-alt text-green-500"></i>';
                break;
            case 'comment':
                $icon = '<i class="fas fa-comment text-purple-500"></i>';
                break;
            case 'job':
                $icon = '<i class="fas fa-briefcase text-orange-500"></i>';
                break;
            case 'status_change':
                $icon = '<i class="fas fa-exchange-alt text-red-500"></i>';
                break;
            default:
                $icon = '<i class="fas fa-bell text-gray-500"></i>';
        }
        
        // Build notification item
        $notificationsList .= '
            <div class="p-4 border-b border-gray-200 ' . ($isRead ? '' : 'bg-blue-50') . '">
                <div class="flex">
                    <div class="flex-shrink-0 mr-4">
                        <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center">
                            ' . $icon . '
                        </div>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm text-gray-800">' . htmlspecialchars($notification['content']) . '</p>
                        <p class="text-xs text-gray-500 mt-1">' . $formattedDate . '</p>
                    </div>
                </div>
            </div>
        ';
    }
}

// Page content
$content = '
<div class="bg-white rounded-lg shadow-sm overflow-hidden">
    <div class="px-4 py-5 border-b border-gray-200 sm:px-6">
        <h3 class="text-lg leading-6 font-medium text-gray-900">Notifications</h3>
        <p class="mt-1 text-sm text-gray-500">
            View all your notifications and updates.
        </p>
    </div>
    <div class="divide-y divide-gray-200">
        ' . $notificationsList . '
    </div>
</div>
';

// Include layout
include 'nav/layout.php';
?> 