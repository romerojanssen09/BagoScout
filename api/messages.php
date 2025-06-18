<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../config/api_keys.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
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

// Initialize Ably
$ablyApiKey = getApiKey('ably');
if (empty($ablyApiKey)) {
    http_response_code(500);
    echo json_encode(['error' => 'Ably API key not configured', 'success' => false]);
    exit();
}

// Create error log file for debugging Ably issues
$ablyLogFile = __DIR__ . '/ably_messages.log';
// error_log("Initializing messaging API with Ably authentication: " . date('Y-m-d H:i:s') . "\n", 3, $ablyLogFile);

// Prepare key parts for REST API calls
$ablyKeyParts = explode(':', $ablyApiKey);
if (count($ablyKeyParts) !== 2) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid Ably API key format', 'success' => false]);
    exit();
}

// Handle different operations
$action = isset($_GET['action']) ? $_GET['action'] : '';

// If no action but conversation_id is provided, default to fetching messages
if (empty($action) && isset($_GET['conversation_id'])) {
    $action = 'get_messages';
}

switch ($action) {
    case 'get_token':
        // Generate client-side token for Ably
        try {
            // Create a basic token for client-side use
            $timestamp = time();
            $nonce = bin2hex(random_bytes(8));
            $userId = 'user-' . $currentUser['id'];
            
            // Create a simple capability JSON
            $capabilities = json_encode([
                'chat' => ['subscribe', 'publish', 'presence'],
                'chat:*' => ['subscribe', 'publish', 'presence'],
                'private:user-' . $currentUser['id'] => ['subscribe', 'publish', 'presence'],
                'jobs' => ['subscribe'],
                'applications' => ['subscribe', 'publish']
            ]);
            
            // Generate a token that will work with client-side Ably
            // This is a simple approach - in production, you'd use proper token authentication
            echo json_encode([
                'success' => true,
                'token' => $ablyApiKey, // Using API key as token
                'user_id' => $currentUser['id'],
                'name' => $currentUser['first_name'] . ' ' . $currentUser['last_name'],
                'expires' => time() + 3600 // Token expires in 1 hour
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to generate token: ' . $e->getMessage(), 'success' => false]);
        }
        break;
        
    case 'get_conversations':
        // Get all conversations for the current user
        $conn = getDbConnection();
        $stmt = $conn->prepare("
            SELECT c.*, 
                   u1.first_name as user1_first_name, u1.last_name as user1_last_name, u1.profile as user1_profile,
                   u2.first_name as user2_first_name, u2.last_name as user2_last_name, u2.profile as user2_profile,
                   m.message as last_message, m.created_at as last_message_time,
                   (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND receiver_id = ? AND is_read = 0) as unread_count
            FROM conversations c
            JOIN users u1 ON c.user1_id = u1.id
            JOIN users u2 ON c.user2_id = u2.id
            LEFT JOIN messages m ON c.last_message_id = m.id
            WHERE c.user1_id = ? OR c.user2_id = ?
            ORDER BY c.updated_at DESC
        ");
        $stmt->bind_param("iii", $currentUser['id'], $currentUser['id'], $currentUser['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $conversations = [];
        while ($row = $result->fetch_assoc()) {
            // Determine other user in conversation
            $otherUserId = $row['user1_id'] == $currentUser['id'] ? $row['user2_id'] : $row['user1_id'];
            $otherUserName = $row['user1_id'] == $currentUser['id'] 
                ? $row['user2_first_name'] . ' ' . $row['user2_last_name']
                : $row['user1_first_name'] . ' ' . $row['user1_last_name'];
            $otherUserProfile = $row['user1_id'] == $currentUser['id'] ? $row['user2_profile'] : $row['user1_profile'];
            
            $conversations[] = [
                'id' => $row['id'],
                'conversation_id' => $row['id'],
                'other_user_id' => $otherUserId,
                'other_user_name' => $otherUserName,
                'other_user_profile' => $otherUserProfile,
                'last_message' => $row['last_message'],
                'last_message_time' => $row['last_message_time'],
                'unread_count' => $row['unread_count'],
                'updated_at' => $row['updated_at']
            ];
        }
        
        echo json_encode(['success' => true, 'conversations' => $conversations]);
        break;
        
    case 'get_messages':
        // Get messages for a specific conversation
        if (!isset($_GET['conversation_id'])) {
            echo json_encode(['success' => false, 'message' => 'Conversation ID is required']);
            exit();
        }
        
        $conversationId = $_GET['conversation_id'];
        
        try {
            $conn = getDbConnection();
            
            // First get the conversation details
            $stmt = $conn->prepare("
                SELECT * FROM conversations 
                WHERE id = ? AND (user1_id = ? OR user2_id = ?)
            ");
            
            if (!$stmt) {
                throw new Exception("Prepare statement failed: " . $conn->error);
            }
            
            $stmt->bind_param("iii", $conversationId, $currentUser['id'], $currentUser['id']);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Conversation not found or you do not have access to it'
                ]);
                exit();
            }
            
            $row = $result->fetch_assoc();
            $conversationDbId = $row['id'];
            $otherUserId = $row['user1_id'] == $currentUser['id'] ? $row['user2_id'] : $row['user1_id'];
            
            // Get messages for the conversation
            $stmt = $conn->prepare("
                SELECT 
                    m.*,
                    'message' as record_type
                FROM messages m
                WHERE m.conversation_id = ? 
                
                UNION ALL
                
                SELECT 
                    c.id,
                    c.conversation_id,
                    c.initiator_id as sender_id,
                    c.recipient_id as receiver_id,
                    CONCAT('Call ', c.call_type, ' (', c.status, ') (call-', c.call_id, ')') as message,
                    NULL as attachment,
                    NULL as attachment_type,
                    NULL as attachment_name,
                    NULL as file_path,
                    NULL as file_name,
                    1 as is_read,
                    0 as is_edited,
                    1 as is_system,
                    c.updated_at,
                    c.created_at,
                    'call' as record_type
                FROM calls c
                WHERE c.conversation_id = ?
                
                ORDER BY created_at ASC
            ");
            
            if (!$stmt) {
                throw new Exception("Prepare statement failed: " . $conn->error);
            }
            
            $stmt->bind_param("ii", $conversationDbId, $conversationDbId);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $result = $stmt->get_result();
            $messages = [];
            
            while ($row = $result->fetch_assoc()) {
                // For call records or system messages related to calls
                if ($row['record_type'] === 'call' || ($row['is_system'] == 1 && strpos($row['message'], 'call-') !== false)) {
                    // Extract call ID from message if present
                    if (preg_match('/\(call-([^\)]+)\)/', $row['message'], $matches)) {
                        $callId = $matches[1];
                        
                        // Get call details
                        $callStmt = $conn->prepare("
                            SELECT c.* 
                            FROM calls c
                            WHERE c.call_id = ?
                        ");
                        
                        $callStmt->bind_param("s", $callId);
                        $callStmt->execute();
                        $callResult = $callStmt->get_result();
                        
                        if ($callRow = $callResult->fetch_assoc()) {
                            // Add call details to the message
                            $row['call_details'] = [
                                'id' => $callRow['id'],
                                'call_id' => $callRow['call_id'],
                                'type' => $callRow['call_type'],
                                'status' => $callRow['status'],
                                'duration' => $callRow['duration'],
                                'initiator_id' => $callRow['initiator_id'],
                                'recipient_id' => $callRow['recipient_id'],
                                'formatted_duration' => $callRow['duration'] > 0 ? formatCallDuration($callRow['duration']) : null,
                                'created_at' => $callRow['created_at'],
                                'updated_at' => $callRow['updated_at']
                            ];
                        }
                        
                        $callStmt->close();
                    }
                }
                
                // Remove the temporary record_type field
                unset($row['record_type']);
                
                $messages[] = $row;
            }
            
            // Debug: Log the number of messages found
            error_log("Found " . count($messages) . " messages for conversation");
            
            echo json_encode([
                'success' => true,
                'messages' => $messages
            ]);
        } catch (Exception $e) {
            error_log("Error in messages.php: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch messages: ' . $e->getMessage(),
                'conversation_id' => $conversationId
            ]);
        }
        break;
        
    case 'send_message':
        // Send a new message
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed', 'success' => false]);
            exit();
        }
        
        // Get conversation_id from POST or GET for backward compatibility
        $conversationId = isset($_POST['conversation_id']) ? $_POST['conversation_id'] : null;
        
        // If not in POST, check GET params
        if (!$conversationId && isset($_GET['conversation_id'])) {
            $conversationId = $_GET['conversation_id'];
        }
        
        // Check for message content in either 'message' or 'content' field for backward compatibility
        $content = '';
        if (isset($_POST['message']) && !empty($_POST['message'])) {
            $content = $_POST['message'];
        } elseif (isset($_POST['content']) && !empty($_POST['content'])) {
            $content = $_POST['content'];
        }
        
        $hasFile = isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;
        
        if (!$conversationId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing conversation_id', 'success' => false]);
            exit();
        }
        
        // Verify user is part of this conversation
        $conn = getDbConnection();
        $stmt = $conn->prepare("
            SELECT * FROM conversations 
            WHERE id = ? AND (user1_id = ? OR user2_id = ?)
        ");
        $stmt->bind_param("iii", $conversationId, $currentUser['id'], $currentUser['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized to access this conversation', 'success' => false]);
            exit();
        }
        
        $conversation = $result->fetch_assoc();
        $receiverId = $conversation['user1_id'] == $currentUser['id'] ? $conversation['user2_id'] : $conversation['user1_id'];
        
        // Handle file upload if exists
        $filePath = null;
        $fileName = null;
        
        if ($hasFile) {
            $userRole = $currentUser['role'];
            $userId = $currentUser['id'];
            $uploadDir = "../uploads/{$userRole}/{$userId}/documents/messages/";
            
            // Create directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $fileExt = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            $fileName = $_FILES['file']['name'];
            $filePath = $uploadDir . time() . '_' . $fileName;
            
            // Move uploaded file
            if (move_uploaded_file($_FILES['file']['tmp_name'], $filePath)) {
                // File uploaded successfully
                $relativePath = str_replace("..", "", $filePath);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to upload file', 'success' => false]);
                exit();
            }
        }
        
        // Insert message
        $stmt = $conn->prepare("
            INSERT INTO messages (conversation_id, sender_id, receiver_id, message, file_path, file_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("siisss", $conversation['id'], $currentUser['id'], $receiverId, $content, $relativePath, $fileName);
        
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to send message: ' . $stmt->error, 'success' => false]);
            exit();
        }
        
        $messageId = $stmt->insert_id;
        
        // Update conversation last message
        $stmt = $conn->prepare("
            UPDATE conversations 
            SET last_message_id = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("ii", $messageId, $conversation['id']);
        $stmt->execute();
        
        // Get the message with sender details
        $stmt = $conn->prepare("
            SELECT m.*, 
                   u.first_name, u.last_name, u.profile
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.id = ?
        ");
        $stmt->bind_param("i", $messageId);
        $stmt->execute();
        $result = $stmt->get_result();
        $message = $result->fetch_assoc();
        
        $messageData = [
            'id' => $message['id'],
            'sender_id' => $message['sender_id'],
            'receiver_id' => $message['receiver_id'],
            'sender_name' => $message['first_name'] . ' ' . $message['last_name'],
            'sender_profile' => $message['profile'],
            'message' => $message['message'],
            'content' => $message['message'],
            'file_path' => $message['file_path'] ?? null,
            'file_name' => $message['file_name'] ?? null,
            'created_at' => $message['created_at']
        ];
        
        // Comment out notifications for chat messages as requested
        // Send notification to receiver
        /*
        createNotification(
            $receiverId,
            'message',
            $currentUser['first_name'] . ' ' . $currentUser['last_name'] . ' sent you a message',
            $conversationId,
            'message'
        );
        */
        
        // Publish message to Ably for realtime delivery
        try {
            // Initialize Ably REST client
            $curl = curl_init();
            
            // Ensure consistent channel naming for all communications
            $channelName = 'conversation-' . $conversationId;
            
            // Create a clientId that matches the format used in authentication
            $clientIdForMessage = 'user-' . $currentUser['id'];
            
            // Set cURL options
            curl_setopt_array($curl, [
                CURLOPT_URL => "https://rest.ably.io/channels/" . $channelName . "/messages",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode([
                    'name' => 'new-message',
                    'data' => $messageData,
                    'clientId' => $clientIdForMessage  // Use the explicitly formatted clientId
                ]),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode($ablyApiKey),
                    'Content-Type: application/json'
                ],
            ]);
            
            // Execute cURL request
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $err = curl_error($curl);
            
            curl_close($curl);
            
            if ($err) {
                error_log('Ably publishing error: ' . $err . "\n", 3, $ablyLogFile);
            } else {
                error_log('Ably message published successfully to channel: ' . $channelName . "\n", 3, $ablyLogFile);
                if ($httpCode >= 400) {
                    error_log('Ably HTTP error: ' . $httpCode . ' - ' . $response . "\n", 3, $ablyLogFile);
                }
            }
        } catch (Exception $e) {
            error_log('Failed to publish message to Ably: ' . $e->getMessage() . "\n", 3, $ablyLogFile);
        }
        
        echo json_encode(['success' => true, 'message' => $messageData]);
        break;
        
    case 'edit_message':
        // Edit an existing message
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed', 'success' => false]);
            exit();
        }
        
        if (!isset($_POST['message_id']) || !isset($_POST['content'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields', 'success' => false]);
            exit();
        }
        
        $messageId = $_POST['message_id'];
        $content = $_POST['content'];
        
        // Verify user owns this message
        $conn = getDbConnection();
        $stmt = $conn->prepare("
            SELECT m.*, c.id as conversation_db_id
            FROM messages m
            JOIN conversations c ON m.conversation_id = c.id
            WHERE m.id = ? AND m.sender_id = ?
        ");
        $stmt->bind_param("ii", $messageId, $currentUser['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized to edit this message', 'success' => false]);
            exit();
        }
        
        $message = $result->fetch_assoc();
        $conversationId = $message['conversation_id'];
        $conversationDbId = $message['conversation_db_id'];
        
        // Update message
        $stmt = $conn->prepare("
            UPDATE messages 
            SET message = ?, is_edited = 1, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("si", $content, $messageId);
        
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to edit message', 'success' => false]);
            exit();
        }
        
        // Get updated message with all details
        $stmt = $conn->prepare("
            SELECT m.*, u.first_name, u.last_name, u.profile
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.id = ?
        ");
        $stmt->bind_param("i", $messageId);
        $stmt->execute();
        $result = $stmt->get_result();
        $updatedMessage = $result->fetch_assoc();
        
        // Send update via Ably
        try {
            // Initialize Ably REST client
            $curl = curl_init();
            
            // Set cURL options
            curl_setopt_array($curl, [
                CURLOPT_URL => "https://rest.ably.io/channels/conversation-" . $conversationId . "/messages",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode([
                    'name' => 'message-edited',
                    'data' => [
                        'id' => $messageId,
                        'sender_id' => $currentUser['id'],
                        'message' => $content,
                        'is_edited' => 1,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ]),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode($ablyApiKey),
                    'Content-Type: application/json'
                ],
            ]);
            
            // Execute cURL request
            $response = curl_exec($curl);
            $err = curl_error($curl);
            
            curl_close($curl);
            
            if ($err) {
                error_log('Ably publishing error for edited message: ' . $err . "\n", 3, $ablyLogFile);
            }
        } catch (Exception $e) {
            error_log('Failed to publish edited message to Ably: ' . $e->getMessage() . "\n", 3, $ablyLogFile);
        }
        
        echo json_encode([
            'success' => true,
            'message' => $updatedMessage
        ]);
        break;
        
    case 'delete_message':
        // Delete a message
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed', 'success' => false]);
            exit();
        }
        
        if (!isset($_POST['message_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Message ID is required', 'success' => false]);
            exit();
        }
        
        $messageId = $_POST['message_id'];
        
        // Verify user owns this message
        $conn = getDbConnection();
        $stmt = $conn->prepare("
            SELECT m.*, c.id as conversation_db_id
            FROM messages m
            JOIN conversations c ON m.conversation_id = c.id
            WHERE m.id = ? AND m.sender_id = ?
        ");
        $stmt->bind_param("ii", $messageId, $currentUser['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Not authorized to delete this message', 'success' => false]);
            exit();
        }
        
        $message = $result->fetch_assoc();
        $conversationId = $message['conversation_id'];
        $conversationDbId = $message['conversation_db_id'];
        
        // Delete message
        $stmt = $conn->prepare("DELETE FROM messages WHERE id = ?");
        $stmt->bind_param("i", $messageId);
        
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete message', 'success' => false]);
            exit();
        }
        
        // Update conversation last message if needed
        $stmt = $conn->prepare("
            SELECT id FROM messages 
            WHERE conversation_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->bind_param("i", $conversationDbId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $lastMessage = $result->fetch_assoc();
            $lastMessageId = $lastMessage['id'];
            
            $stmt = $conn->prepare("
                UPDATE conversations 
                SET last_message_id = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ii", $lastMessageId, $conversationDbId);
            $stmt->execute();
        } else {
            // No messages left, set last_message_id to NULL
            $stmt = $conn->prepare("
                UPDATE conversations 
                SET last_message_id = NULL
                WHERE id = ?
            ");
            $stmt->bind_param("i", $conversationDbId);
            $stmt->execute();
        }
        
        // Send update via Ably
        try {
            // Initialize Ably REST client
            $curl = curl_init();
            
            // Set cURL options
            curl_setopt_array($curl, [
                CURLOPT_URL => "https://rest.ably.io/channels/conversation-" . $conversationId . "/messages",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode([
                    'name' => 'message-deleted',
                    'data' => [
                        'id' => $messageId,
                        'sender_id' => $currentUser['id'],
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic ' . base64_encode($ablyApiKey),
                    'Content-Type: application/json'
                ],
            ]);
            
            // Execute cURL request
            $response = curl_exec($curl);
            $err = curl_error($curl);
            
            curl_close($curl);
            
            if ($err) {
                error_log('Ably publishing error for deleted message: ' . $err . "\n", 3, $ablyLogFile);
            }
        } catch (Exception $e) {
            error_log('Failed to publish deleted message to Ably: ' . $e->getMessage() . "\n", 3, $ablyLogFile);
        }
        
        echo json_encode(['success' => true]);
        break;
        
    case 'create_conversation':
        // Create a new conversation with another user
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['user_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'User ID is required']);
            exit();
        }
        
        $otherUserId = (int)$data['user_id'];
        
        // Check if user exists
        $otherUser = getUserById($otherUserId);
        if (!$otherUser) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            exit();
        }
        
        // Check if conversation already exists
        $conn = getDbConnection();
        $stmt = $conn->prepare("
            SELECT * FROM conversations 
            WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)
        ");
        $stmt->bind_param("iiii", $currentUser['id'], $otherUserId, $otherUserId, $currentUser['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $conversation = $result->fetch_assoc();
            echo json_encode(['conversation_id' => $conversation['conversation_id']]);
            exit();
        }
        
        // Create new conversation
        $conversationId = bin2hex(random_bytes(16)); // Generate unique ID
        
        $stmt = $conn->prepare("
            INSERT INTO conversations (conversation_id, user1_id, user2_id, updated_at, created_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        $stmt->bind_param("sii", $conversationId, $currentUser['id'], $otherUserId);
        
        if ($stmt->execute()) {
            echo json_encode([
                'conversation_id' => $conversationId,
                'other_user' => [
                    'id' => $otherUser['id'],
                    'name' => $otherUser['first_name'] . ' ' . $otherUser['last_name'],
                    'profile' => $otherUser['profile']
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create conversation']);
        }
        break;
        
    case 'search_users':
        // Search for users to start a conversation with
        $searchTerm = isset($_GET['term']) ? $_GET['term'] : '';
        
        if (empty($searchTerm)) {
            http_response_code(400);
            echo json_encode(['error' => 'Search term is required']);
            exit();
        }
        
        $conn = getDbConnection();
        $searchTerm = "%$searchTerm%";
        
        // Get current user role to determine who they can message
        $userRole = $currentUser['role'];
        
        if ($userRole === 'jobseeker') {
            // Jobseekers can only message employers
            $stmt = $conn->prepare("
                SELECT u.id, u.first_name, u.last_name, u.profile, u.role, e.company_name
                FROM users u
                JOIN employers e ON u.id = e.user_id
                WHERE u.role = 'employer' 
                AND u.status = 'active'
                AND (u.first_name LIKE ? OR u.last_name LIKE ? OR e.company_name LIKE ?)
                LIMIT 10
            ");
            $stmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
        } elseif ($userRole === 'employer') {
            // Employers can only message jobseekers
            $stmt = $conn->prepare("
                SELECT u.id, u.first_name, u.last_name, u.profile, u.role, j.skills
                FROM users u
                JOIN jobseekers j ON u.id = j.user_id
                WHERE u.role = 'jobseeker' 
                AND u.status = 'active'
                AND (u.first_name LIKE ? OR u.last_name LIKE ? OR j.skills LIKE ?)
                LIMIT 10
            ");
            $stmt->bind_param("sss", $searchTerm, $searchTerm, $searchTerm);
        } else {
            // Admin can message anyone
            $stmt = $conn->prepare("
                SELECT u.id, u.first_name, u.last_name, u.profile, u.role
                FROM users u
                WHERE u.id != ? 
                AND u.status = 'active'
                AND (u.first_name LIKE ? OR u.last_name LIKE ?)
                LIMIT 10
            ");
            $stmt->bind_param("iss", $currentUser['id'], $searchTerm, $searchTerm);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = [
                'id' => $row['id'],
                'name' => $row['first_name'] . ' ' . $row['last_name'],
                'profile' => $row['profile'],
                'role' => $row['role'],
                'company_name' => isset($row['company_name']) ? $row['company_name'] : null,
                'skills' => isset($row['skills']) ? $row['skills'] : null
            ];
        }
        
        echo json_encode(['users' => $users]);
        break;
        
    case 'fetch_messages':
        // Check if conversation_id is provided
        if (!isset($_GET['conversation_id'])) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['success' => false, 'message' => 'Conversation ID is required']);
            exit();
        }

        $conversationId = $_GET['conversation_id'];

        try {
            // Get database connection
            $conn = getDbConnection();
            
            // Check if user is part of the conversation
            $stmt = $conn->prepare("
                SELECT id FROM conversations 
                WHERE id = ? 
                AND (user1_id = ? OR user2_id = ?)
            ");
            
            $stmt->bind_param("iii", $conversationId, $currentUser['id'], $currentUser['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Unauthorized access to conversation'
                ]);
                exit();
            }
            
            // Get messages for the conversation
            $stmt = $conn->prepare("
                SELECT 
                    id,
                    sender_id,
                    receiver_id,
                    message,
                    created_at,
                    is_read,
                    is_system,
                    file_path,
                    file_name,
                    is_edited
                FROM messages 
                WHERE conversation_id = ? 
                ORDER BY created_at ASC
            ");
            
            $stmt->bind_param("i", $conversationId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $messages = [];
            while ($row = $result->fetch_assoc()) {
                $messages[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'messages' => $messages
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch messages: ' . $e->getMessage()
            ]);
        }
        break;

    case 'mark_read':
        try {
            // Mark messages as read for a conversation
            $conversationId = $_GET['conversation_id'];
            $conn = getDbConnection();
            
            // Verify user has access to this conversation
            $stmt = $conn->prepare("
                SELECT * FROM conversations 
                WHERE conversation_id = ? 
                AND (user1_id = ? OR user2_id = ?)
            ");
            $stmt->bind_param("sii", $conversationId, $currentUser['id'], $currentUser['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Unauthorized access to conversation'
                ]);
                exit();
            }
            
            $conversation = $result->fetch_assoc();
            $otherUserId = $conversation['user1_id'] == $currentUser['id'] ? $conversation['user2_id'] : $conversation['user1_id'];
            
            // Update all unread messages sent to current user
            $stmt = $conn->prepare("
                UPDATE messages 
                SET is_read = 1 
                WHERE conversation_id = ? 
                AND receiver_id = ? 
                AND is_read = 0
            ");
            $stmt->bind_param("si", $conversation['id'], $currentUser['id']);
            $stmt->execute();
            
            // Publish read status to Ably
            try {
                // Initialize Ably REST client
                $curl = curl_init();
                
                // Set cURL options
                curl_setopt_array($curl, [
                    CURLOPT_URL => "https://rest.ably.io/channels/conversation-" . $conversationId . "/messages",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => json_encode([
                        'name' => 'read-status',
                        'data' => [
                            'user_id' => $currentUser['id'],
                            'conversation_id' => $conversationId,
                            'timestamp' => date('Y-m-d H:i:s')
                        ]
                    ]),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Basic ' . base64_encode($ablyApiKey),
                        'Content-Type: application/json'
                    ],
                ]);
                
                // Execute cURL request
                $response = curl_exec($curl);
                $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $err = curl_error($curl);
                
                curl_close($curl);
                
                if ($err) {
                    error_log('Ably publishing error: ' . $err . "\n", 3, $ablyLogFile);
                } else {
                    error_log('Ably message published successfully to channel: ' . $channelName . "\n", 3, $ablyLogFile);
                    if ($httpCode >= 400) {
                        error_log('Ably HTTP error: ' . $httpCode . ' - ' . $response . "\n", 3, $ablyLogFile);
                    }
                }
            } catch (Exception $e) {
                error_log('Failed to publish read status to Ably: ' . $e->getMessage() . "\n", 3, $ablyLogFile);
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to mark messages as read: ' . $e->getMessage()
            ]);
        }
        break;

    case 'mark_as_read':
        try {
            // Mark messages as read for a conversation
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                exit();
            }
            
            if (!isset($_POST['conversation_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Conversation ID is required']);
                exit();
            }
            
            $conversationId = $_POST['conversation_id'];
            $conn = getDbConnection();
            
            // Verify user has access to this conversation
            $stmt = $conn->prepare("
                SELECT * FROM conversations 
                WHERE id = ? 
                AND (user1_id = ? OR user2_id = ?)
            ");
            $stmt->bind_param("iii", $conversationId, $currentUser['id'], $currentUser['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Unauthorized access to conversation'
                ]);
                exit();
            }
            
            $conversation = $result->fetch_assoc();
            $otherUserId = $conversation['user1_id'] == $currentUser['id'] ? $conversation['user2_id'] : $conversation['user1_id'];
            
            // Update all unread messages sent to current user
            $stmt = $conn->prepare("
                UPDATE messages 
                SET is_read = 1 
                WHERE conversation_id = ? 
                AND receiver_id = ? 
                AND is_read = 0
            ");
            $stmt->bind_param("ii", $conversation['id'], $currentUser['id']);
            $stmt->execute();
            
            // Publish read status to Ably
            try {
                // Initialize Ably REST client
                $curl = curl_init();
                
                // Ensure consistent channel naming
                $channelName = 'conversation-' . $conversationId;
                
                // Set cURL options
                curl_setopt_array($curl, [
                    CURLOPT_URL => "https://rest.ably.io/channels/" . $channelName . "/messages",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS => json_encode([
                        'name' => 'read-status',
                        'data' => [
                            'user_id' => $currentUser['id'],
                            'conversation_id' => $conversationId,
                            'timestamp' => date('Y-m-d H:i:s')
                        ],
                        'clientId' => 'user-' . $currentUser['id']  // Add consistent clientId format
                    ]),
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Basic ' . base64_encode($ablyApiKey),
                        'Content-Type: application/json'
                    ],
                ]);
                
                // Execute cURL request
                $response = curl_exec($curl);
                $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $err = curl_error($curl);
                
                curl_close($curl);
                
                if ($err) {
                    error_log('Ably publishing error: ' . $err . "\n", 3, $ablyLogFile);
                } else {
                    error_log('Ably message published successfully to channel: ' . $channelName . "\n", 3, $ablyLogFile);
                    if ($httpCode >= 400) {
                        error_log('Ably HTTP error: ' . $httpCode . ' - ' . $response . "\n", 3, $ablyLogFile);
                    }
                }
            } catch (Exception $e) {
                error_log('Failed to publish read status to Ably: ' . $e->getMessage() . "\n", 3, $ablyLogFile);
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to mark messages as read: ' . $e->getMessage()
            ]);
        }
        break;

    case 'system_message':
        addSystemMessage();
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action', 'success' => false]);
}

/**
 * Add a system message to a conversation
 */
function addSystemMessage() {
    global $currentUser, $ablyLogFile;
    
    // Get request body
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['conversation_id']) || !isset($data['message'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        return;
    }
    
    $conversationId = $data['conversation_id'];
    $message = $data['message'];
    $senderId = isset($data['sender_id']) ? $data['sender_id'] : $currentUser['id'];
    $receiverId = isset($data['receiver_id']) ? $data['receiver_id'] : null;
    $callId = isset($data['call_id']) ? $data['call_id'] : null;
    
    $conn = getDbConnection();
    
    // First check if this is a duplicate call message that might have been sent already
    if ($callId && (strpos(strtolower($message), 'call') !== false)) {
        $stmt = $conn->prepare("
            SELECT id FROM messages 
            WHERE conversation_id = ? 
            AND is_system = 1 
            AND message LIKE ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        ");
        
        $likePattern = '%' . $conn->real_escape_string($message) . '%';
        $stmt->bind_param("is", $conversationId, $likePattern);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // We already have a similar message recently added, let's not duplicate it
            echo json_encode([
                'success' => true,
                'message' => 'Similar system message already exists'
            ]);
            $stmt->close();
            $conn->close();
            return;
        }
        $stmt->close();
    }
    
    // Insert system message
    $stmt = $conn->prepare("
        INSERT INTO messages (conversation_id, sender_id, receiver_id, message, is_system, created_at)
        VALUES (?, ?, ?, ?, 1, NOW())
    ");
    
    $stmt->bind_param("iiis", $conversationId, $senderId, $receiverId, $message);
    $success = $stmt->execute();
    
    if ($success) {
        $messageId = $stmt->insert_id;
        
        // Update the conversation's last_message_id to show this system message
        $updateStmt = $conn->prepare("
            UPDATE conversations SET last_message_id = ?, updated_at = NOW()
            WHERE (conversation_id = ? OR id = ?)
        ");
        $updateStmt->bind_param("iss", $messageId, $conversationId, $conversationId);
        $updateStmt->execute();
        $updateStmt->close();
        
        // If this is a call-related message, publish it to Ably for real-time updates
        if (strpos(strtolower($message), 'call') !== false) {
            try {
                $ablyApiKey = getApiKey('ably');
                if (!empty($ablyApiKey)) {
                    // Initialize Ably REST client
                    $curl = curl_init();
                    
                    $messageData = [
                        'id' => $messageId,
                        'sender_id' => $senderId,
                        'receiver_id' => $receiverId,
                        'message' => $message,
                        'is_system' => 1,
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    
                    // Ensure consistent channel naming
                    $channelName = 'conversation-' . $conversationId;
                    
                    // Set cURL options
                    curl_setopt_array($curl, [
                        CURLOPT_URL => "https://rest.ably.io/channels/" . $channelName . "/messages",
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => '',
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => 'POST',
                        CURLOPT_POSTFIELDS => json_encode([
                            'name' => 'new-message',
                            'data' => $messageData,
                            'clientId' => 'user-' . $senderId // Add consistent clientId format
                        ]),
                        CURLOPT_HTTPHEADER => [
                            'Authorization: Basic ' . base64_encode($ablyApiKey),
                            'Content-Type: application/json'
                        ],
                    ]);
                    
                    // Execute cURL request
                    $response = curl_exec($curl);
                    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    $err = curl_error($curl);
                    
                    curl_close($curl);
                    
                    if ($err) {
                        error_log('Ably publishing error: ' . $err . "\n", 3, $ablyLogFile);
                    } else {
                        error_log('Ably message published successfully to channel: ' . $channelName . "\n", 3, $ablyLogFile);
                        if ($httpCode >= 400) {
                            error_log('Ably HTTP error: ' . $httpCode . ' - ' . $response . "\n", 3, $ablyLogFile);
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('Failed to publish system message to Ably: ' . $e->getMessage() . "\n", 3, $ablyLogFile);
            }
        }
        
        echo json_encode([
            'success' => true,
            'message_id' => $messageId,
            'message' => 'System message added successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to add system message: ' . $stmt->error
        ]);
    }
    
    $stmt->close();
    $conn->close();
}

/**
 * Format call duration in a consistent way (matching JS implementation)
 * @param int $seconds Call duration in seconds
 * @return string Formatted duration string
 */
function formatCallDuration($seconds) {
    if (!$seconds) return '0 seconds';
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $remainingSeconds = $seconds % 60;
    
    $formattedDuration = '';
    
    if ($hours > 0) {
        $formattedDuration .= $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
    }
    
    if ($minutes > 0) {
        if ($formattedDuration) $formattedDuration .= ' ';
        $formattedDuration .= $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
    }
    
    if ($remainingSeconds > 0 || (!$hours && !$minutes)) {
        if ($formattedDuration) $formattedDuration .= ' ';
        $formattedDuration .= $remainingSeconds . ' ' . ($remainingSeconds === 1 ? 'second' : 'seconds');
    }
    
    return $formattedDuration;
}