<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../config/api_keys.php';

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

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get request body
$data = json_decode(file_get_contents('php://input'), true);

// Validate request data
if (!isset($data['conversation_id']) || !isset($data['receiver_id']) || !isset($data['message'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$conversationId = $data['conversation_id'];
$receiverId = $data['receiver_id'];
$message = $data['message'];

try {
    // Get database connection
    $conn = getDbConnection();
    
    // Check if user is part of the conversation
    $stmt = $conn->prepare("
        SELECT id, conversation_id FROM conversations 
        WHERE (conversation_id = ? OR id = ?) 
        AND (user1_id = ? OR user2_id = ?)
    ");
    
    $stmt->bind_param("siii", $conversationId, $conversationId, $currentUser['id'], $currentUser['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized access to conversation'
        ]);
        exit();
    }
    
    // Get the conversation database ID and channel ID
    $row = $result->fetch_assoc();
    $conversationDbId = $row['id'];
    $channelId = $row['conversation_id'] ?: $row['id'];
    
    // Insert message
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        INSERT INTO messages (conversation_id, sender_id, receiver_id, message, created_at, is_read)
        VALUES (?, ?, ?, ?, ?, 0)
    ");
    
    $stmt->bind_param("iiiss", $conversationDbId, $currentUser['id'], $receiverId, $message, $now);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        $messageId = $stmt->insert_id;
        
        // Get the created message
        $stmt = $conn->prepare("
            SELECT 
                id,
                sender_id,
                receiver_id,
                message,
                created_at,
                is_read
            FROM messages 
            WHERE id = ?
        ");
        
        $stmt->bind_param("i", $messageId);
        $stmt->execute();
        $result = $stmt->get_result();
        $messageData = $result->fetch_assoc();
        
        // Update conversation last_message_id and updated_at
        $stmt = $conn->prepare("
            UPDATE conversations 
            SET last_message_id = ?, updated_at = ? 
            WHERE id = ?
        ");
        
        $stmt->bind_param("isi", $messageId, $now, $conversationDbId);
        $stmt->execute();
        
        // Get Ably API key for realtime notification
        $apiKey = getApiKey('ably');
        
        if ($apiKey) {
            // Send realtime notification using Ably REST API
            $ch = curl_init();
            $url = "https://rest.ably.io/channels/conversation-" . $channelId . "/messages";
            
            // Use Basic Auth with the API key
            curl_setopt($ch, CURLOPT_USERPWD, $apiKey);
            
            $headers = [
                'Content-Type: application/json'
            ];
            
            $postData = json_encode([
                'name' => 'new-message',
                'data' => $messageData
            ]);
            
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Add notification info to response
            $messageData['notification_sent'] = ($httpCode >= 200 && $httpCode < 300);
            $messageData['notification_status'] = $httpCode;
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Message sent successfully',
            'data' => $messageData
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send message'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send message: ' . $e->getMessage()
    ]);
}
?> 