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

// Check if user_id parameter is provided
if (!isset($_GET['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit();
}

$userId = $_GET['user_id'];

try {
    // Get database connection
    $conn = getDbConnection();
    
    // Get user information
    $stmt = $conn->prepare("
        SELECT id, CONCAT(first_name, ' ', last_name) AS name, email, profile AS profile_image, role AS user_type
        FROM users
        WHERE id = ?
    ");
    
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'User not found'
        ]);
        exit();
    }
    
    $user = $result->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'user' => $user
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to get user information: ' . $e->getMessage()
    ]);
}
?> 