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

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get request body
$data = json_decode(file_get_contents('php://input'), true);

// Validate request data
if (!isset($data['participant_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing participant_id']);
    exit();
}

$participantId = $data['participant_id'];

// Check if participant exists
try {
    // Get database connection
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->bind_param("i", $participantId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Participant not found'
        ]);
        exit();
    }
    
    // Check if conversation already exists
    $stmt = $conn->prepare("
        SELECT conversation_id FROM conversations 
        WHERE (user1_id = ? AND user2_id = ?)
        OR (user1_id = ? AND user2_id = ?)
    ");
    
    $stmt->bind_param("iiii", $currentUser['id'], $participantId, $participantId, $currentUser['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $conversation = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'message' => 'Conversation already exists',
            'conversation_id' => $conversation['conversation_id']
        ]);
        exit();
    }
    
    // Create new conversation
    $conversationId = md5($currentUser['id'] . '-' . $participantId . '-' . time());
    $now = date('Y-m-d H:i:s');
    
    $stmt = $conn->prepare("
        INSERT INTO conversations (conversation_id, user1_id, user2_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param("siisd", $conversationId, $currentUser['id'], $participantId, $now, $now);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Conversation created successfully',
            'conversation_id' => $conversationId
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create conversation'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create conversation: ' . $e->getMessage()
    ]);
}
?> 