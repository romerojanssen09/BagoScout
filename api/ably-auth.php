<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../config/api_keys.php';

// Set content type header first to ensure proper JSON response
header('Content-Type: application/json');

// Check if user is logged in
session_start();
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get current user
$currentUser = getCurrentUser();
if (!$currentUser) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit();
}

// Get Ably API key from api_keys.php
$apiKey = getApiKey('ably');

if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'API key not found']);
    exit();
}

try {
    // Client ID for Ably
    $clientId = 'user-' . $currentUser['id'];
    
    // Create a response that works with all client files
    echo json_encode([
        'success' => true,
        'token' => $apiKey,
        'key' => $apiKey, // Add key for compatibility with different client implementations
        'clientId' => $clientId,
        'userId' => (string)$currentUser['id'],
        'user_id' => $currentUser['id'], // Add user_id for compatibility
        'name' => $currentUser['first_name'] . ' ' . $currentUser['last_name'] // Add name for compatibility
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error generating token: ' . $e->getMessage()]);
}
exit(); 