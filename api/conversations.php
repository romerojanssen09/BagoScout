<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Set content type header first to ensure proper JSON response
header('Content-Type: application/json');

// Check if user is logged in
session_start();
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get current user
$currentUser = getCurrentUser();
if (!$currentUser) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit();
}

try {
    // Get database connection
    $conn = getDbConnection();
    
    // Get all conversations for the current user
    $stmt = $conn->prepare("
        SELECT 
            c.id AS id,
            CASE 
                WHEN c.user1_id = ? THEN c.user2_id
                ELSE c.user1_id
            END AS participant_id,
            CASE 
                WHEN c.user1_id = ? THEN CONCAT(u2.first_name, ' ', u2.last_name)
                ELSE CONCAT(u1.first_name, ' ', u1.last_name)
            END AS participant_name,
            CASE 
                WHEN c.user1_id = ? THEN u2.profile
                ELSE u1.profile
            END AS participant_avatar,
            (
                SELECT message 
                FROM messages 
                WHERE conversation_id = c.id
                ORDER BY created_at DESC 
                LIMIT 1
            ) AS last_message,
            (
                SELECT created_at 
                FROM messages 
                WHERE conversation_id = c.id
                ORDER BY created_at DESC 
                LIMIT 1
            ) AS last_message_time,
            (
                SELECT COUNT(*) 
                FROM messages 
                WHERE conversation_id = c.id
                AND receiver_id = ? 
                AND is_read = 0
            ) AS unread_count
        FROM conversations c
        JOIN users u1 ON c.user1_id = u1.id
        JOIN users u2 ON c.user2_id = u2.id
        WHERE c.user1_id = ? OR c.user2_id = ?
        ORDER BY last_message_time DESC
    ");
    
    if (!$stmt) {
        throw new Exception("Prepare statement failed: " . $conn->error);
    }
    
    $userId = $currentUser['id'];
    $stmt->bind_param("iiiiii", $userId, $userId, $userId, $userId, $userId, $userId);
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    $conversations = [];
    while ($row = $result->fetch_assoc()) {
        $conversations[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'conversations' => $conversations
    ]);
} catch (Exception $e) {
    error_log("Error in conversations.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch conversations: ' . $e->getMessage()
    ]);
}
?> 