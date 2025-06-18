<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$type = isset($_GET['type']) ? $_GET['type'] : '';

switch ($type) {
    case 'messaging':
        getMessagingStats();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid stats type']);
}

/**
 * Get messaging statistics
 */
function getMessagingStats() {
    $conn = getDbConnection();
    $stats = [];
    
    // Get total conversations
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM conversations");
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['total_conversations'] = $result->fetch_assoc()['count'];
    
    // Get total messages
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM messages");
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['total_messages'] = $result->fetch_assoc()['count'];
    
    // Get active users in last 24 hours
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT sender_id) as count 
        FROM messages 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['active_users'] = $result->fetch_assoc()['count'];
    
    // Get messages per day for the last 7 days
    $stmt = $conn->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as count
        FROM messages
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messagesByDay = [];
    while ($row = $result->fetch_assoc()) {
        $messagesByDay[$row['date']] = $row['count'];
    }
    $stats['messages_by_day'] = $messagesByDay;
    
    // Get top 5 most active conversations
    $stmt = $conn->prepare("
        SELECT 
            m.conversation_id,
            COUNT(*) as message_count,
            MAX(m.created_at) as last_message,
            u1.first_name as user1_first_name,
            u1.last_name as user1_last_name,
            u2.first_name as user2_first_name,
            u2.last_name as user2_last_name
        FROM messages m
        JOIN conversations c ON m.conversation_id = c.conversation_id
        JOIN users u1 ON c.user1_id = u1.id
        JOIN users u2 ON c.user2_id = u2.id
        GROUP BY m.conversation_id
        ORDER BY message_count DESC
        LIMIT 5
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $topConversations = [];
    while ($row = $result->fetch_assoc()) {
        $topConversations[] = [
            'conversation_id' => $row['conversation_id'],
            'message_count' => $row['message_count'],
            'last_message' => $row['last_message'],
            'participants' => $row['user1_first_name'] . ' ' . $row['user1_last_name'] . ' and ' . 
                              $row['user2_first_name'] . ' ' . $row['user2_last_name']
        ];
    }
    $stats['top_conversations'] = $topConversations;
    
    $conn->close();
    
    echo json_encode($stats);
} 