<?php
// Admin-specific Ably authentication endpoint
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../config/api_keys.php';

// Set content type header first to ensure proper JSON response
header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get admin details
$conn = getDbConnection();
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->bind_param("i", $_SESSION['admin_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Admin not found']);
    exit();
}

$admin = $result->fetch_assoc();
$stmt->close();
$conn->close();

// Get Ably API key from api_keys.php
$apiKey = getApiKey('ably');

if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'API key not found']);
    exit();
}

try {
    // Client ID for Ably
    $clientId = 'admin-' . $admin['id'];
    
    // Create a response that works with all client files
    echo json_encode([
        'success' => true,
        'token' => $apiKey,
        'key' => $apiKey, // Add key for compatibility with different client implementations
        'clientId' => $clientId,
        'adminId' => (string)$admin['id'],
        'admin_id' => $admin['id'], // Add admin_id for compatibility
        'name' => $admin['first_name'] . ' ' . $admin['last_name'] // Add name for compatibility
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error generating token: ' . $e->getMessage()]);
}
exit(); 