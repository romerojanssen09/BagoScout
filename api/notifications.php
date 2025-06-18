<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized', 'success' => false]);
    exit();
}

// Get current user
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Handle different actions
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'get_notifications':
        // Get notifications for the current user
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        $unreadOnly = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';
        
        $notifications = getUserNotifications($userId, $limit, $unreadOnly);
        
        echo json_encode([
            'success' => true,
            'notifications' => $notifications
        ]);
        break;
        
    case 'mark_as_read':
        // Mark a notification as read
        if (!isset($_POST['notification_id'])) {
            echo json_encode(['error' => 'Notification ID is required', 'success' => false]);
            exit();
        }
        
        $notificationId = intval($_POST['notification_id']);
        $success = markNotificationAsRead($notificationId, $userId);
        
        echo json_encode([
            'success' => $success
        ]);
        break;
        
    case 'mark_all_as_read':
        // Mark all notifications as read
        $success = markAllNotificationsAsRead($userId);
        
        echo json_encode([
            'success' => $success
        ]);
        break;
        
    case 'get_unread_count':
        // Get unread notification count
        $count = getUnreadNotificationCount($userId);
        
        echo json_encode([
            'success' => true,
            'count' => $count
        ]);
        break;
        
    default:
        echo json_encode(['error' => 'Invalid action', 'success' => false]);
        break;
} 