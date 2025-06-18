<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../vendor/autoload.php';

use Ably\AblyRest;

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

// Get request body
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['channel']) || !isset($data['event']) || !isset($data['message'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$channel = $data['channel'];
$event = $data['event'];
$message = $data['message'];

// Get Ably API key from config
$config = include '../config/config.php';
$ablyApiKey = $config['ably_api_key'];

if (!$ablyApiKey) {
    echo json_encode(['success' => false, 'message' => 'Ably API key not configured']);
    exit();
}

try {
    // Initialize Ably
    $ably = new AblyRest($ablyApiKey);
    
    // Get channel
    $ablyChannel = $ably->channels->get($channel);
    
    // Publish message
    $ablyChannel->publish($event, $message);
    
    echo json_encode([
        'success' => true,
        'message' => 'Message published successfully'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to publish message: ' . $e->getMessage()
    ]);
} 