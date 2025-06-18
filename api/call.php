<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get current user
$currentUser = getCurrentUser();
if (!$currentUser) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Set header for JSON response
header('Content-Type: application/json');

// Handle different actions
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'check_status':
        checkUserCallStatus();
        break;
        
    case 'create':
        createCallRecord();
        break;
        
    case 'update':
        updateCallStatus();
        break;
        
    case 'get_history':
        getCallHistory();
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

/**
 * Check if a user is currently in a call
 */
function checkUserCallStatus() {
    $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        return;
    }
    
    $conn = getDbConnection();
    
    // Check for active calls where user is participant
    $stmt = $conn->prepare("
        SELECT * FROM calls 
        WHERE (initiator_id = ? OR recipient_id = ?) 
        AND status IN ('initiated', 'connected') 
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $inCall = $result->num_rows > 0;
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'in_call' => $inCall
    ]);
}

/**
 * Create a new call record
 */
function createCallRecord() {
    global $currentUser;
    
    // Get request body
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['recipientId']) || !isset($data['callType'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        return;
    }
    
    $callId = $data['callId'];
    $initiatorId = $currentUser['id'];
    $recipientId = intval($data['recipientId']);
    $callType = $data['callType'];
    $status = 'initiated';
    $conversationId = getMessagesConversationId($initiatorId, $recipientId);
    
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("
        INSERT INTO calls (call_id, conversation_id, initiator_id, recipient_id, call_type, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("siiiss", $callId, $conversationId, $initiatorId, $recipientId, $callType, $status);
    
    $success = $stmt->execute();
    
    if ($success) {
        echo json_encode([
            'success' => true,
            'call_id' => $callId,
            'message' => 'Call record created successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create call record: ' . $stmt->error
        ]);
    }
    
    $stmt->close();
    $conn->close();
}

/**
 * Update call status
 */
function updateCallStatus() {
    // Get request body
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['callId']) || !isset($data['status'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        return;
    }
    
    $callId = $data['callId'];
    $status = $data['status'];
    $duration = isset($data['duration']) ? intval($data['duration']) : null;
    
    $conn = getDbConnection();
    
    // Check if duration is provided for ended calls
    if ($status === 'ended' && $duration !== null) {
        $stmt = $conn->prepare("
            UPDATE calls 
            SET status = ?, 
                duration = ?,
                updated_at = NOW() 
            WHERE call_id = ?
        ");
        
        $stmt->bind_param("sis", $status, $duration, $callId);
    } else {
        $stmt = $conn->prepare("
            UPDATE calls 
            SET status = ?, 
                updated_at = NOW() 
            WHERE call_id = ?
        ");
        
        $stmt->bind_param("ss", $status, $callId);
    }
    
    $success = $stmt->execute();
    
    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Call status updated successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update call status: ' . $stmt->error
        ]);
    }
    
    $stmt->close();
    $conn->close();
}

/**
 * Get call history for a user
 */
function getCallHistory() {
    global $currentUser;
    
    // Get optional other user ID to filter calls with a specific user
    $otherUserId = isset($_GET['other_user_id']) ? intval($_GET['other_user_id']) : null;
    
    $conn = getDbConnection();
    
    // Prepare query based on whether we're filtering by other user
    if ($otherUserId) {
        $stmt = $conn->prepare("
            SELECT 
                c.*,
                u1.first_name AS initiator_first_name,
                u1.last_name AS initiator_last_name,
                u2.first_name AS recipient_first_name,
                u2.last_name AS recipient_last_name
            FROM calls c
            JOIN users u1 ON c.initiator_id = u1.id
            JOIN users u2 ON c.recipient_id = u2.id
            WHERE (c.initiator_id = ? AND c.recipient_id = ?) 
               OR (c.initiator_id = ? AND c.recipient_id = ?)
            ORDER BY c.created_at DESC
            LIMIT 50
        ");
        
        $stmt->bind_param("iiii", $currentUser['id'], $otherUserId, $otherUserId, $currentUser['id']);
    } else {
        $stmt = $conn->prepare("
            SELECT 
                c.*,
                u1.first_name AS initiator_first_name,
                u1.last_name AS initiator_last_name,
                u2.first_name AS recipient_first_name,
                u2.last_name AS recipient_last_name
            FROM calls c
            JOIN users u1 ON c.initiator_id = u1.id
            JOIN users u2 ON c.recipient_id = u2.id
            WHERE c.initiator_id = ? OR c.recipient_id = ?
            ORDER BY c.created_at DESC
            LIMIT 50
        ");
        
        $stmt->bind_param("ii", $currentUser['id'], $currentUser['id']);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $calls = [];
    while ($row = $result->fetch_assoc()) {
        // Format duration if available
        if ($row['duration']) {
            require_once '../includes/functions.php';
            $row['formatted_duration'] = formatCallDuration($row['duration']);
        } else {
            $row['formatted_duration'] = null;
        }
        
        // Add a flag to indicate if current user was the initiator
        $row['is_initiator'] = ($row['initiator_id'] == $currentUser['id']);
        
        // Add other user details
        if ($row['is_initiator']) {
            $row['other_user_id'] = $row['recipient_id'];
            $row['other_user_name'] = $row['recipient_first_name'] . ' ' . $row['recipient_last_name'];
        } else {
            $row['other_user_id'] = $row['initiator_id'];
            $row['other_user_name'] = $row['initiator_first_name'] . ' ' . $row['initiator_last_name'];
        }
        
        $calls[] = $row;
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'calls' => $calls
    ]);
}

function getMessagesConversationId($userId1, $userId2) {
    $conn = getDbConnection();

    $stmt = $conn->prepare("
        SELECT id FROM conversations WHERE (user1_id = ? AND user2_id = ?) OR (user1_id = ? AND user2_id = ?)
    ");
    $stmt->bind_param("iiii", $userId1, $userId2, $userId2, $userId1);
    $stmt->execute();
    $result = $stmt->get_result();
    $conversationId = $result->fetch_assoc()['id'];
    $stmt->close();
    $conn->close();
    return $conversationId;
}