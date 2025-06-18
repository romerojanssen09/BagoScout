<?php
session_start();
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../../../");
    exit();
}

// Get current user
$currentUser = getCurrentUser();
if (!$currentUser) {
    header("Location: ../../../");
    exit();
}

// Check for required parameters
if (!isset($_GET['type'])) {
    header("Location: dashboard.php");
    exit();
}

$callType = $_GET['type']; // 'audio' or 'video'
$isInitiator = isset($_GET['initiator']) && $_GET['initiator'] === 'true';
$otherUserId = isset($_GET['user']) ? $_GET['user'] : null;

// Validate call type and user
if (($callType !== 'audio' && $callType !== 'video') || !$otherUserId) {
    header("Location: dashboard.php");
    exit();
}

// Check for call ID or create a new call
if (isset($_GET['id'])) {
    $callId = $_GET['id'];
} else {
    // Generate a unique call ID using a consistent format
    $userId1 = min($currentUser['id'], $otherUserId);
    $userId2 = max($currentUser['id'], $otherUserId);
    $callId = "call-{$userId1}-{$userId2}-" . time();
    
    // Create call record in database
    if ($isInitiator) {
        $conn = getDbConnection();
        $stmt = $conn->prepare("
            INSERT INTO calls (call_id, initiator_id, recipient_id, call_type, status, created_at)
            VALUES (?, ?, ?, ?, 'initiated', NOW())
        ");
        $stmt->bind_param("siis", $callId, $currentUser['id'], $otherUserId, $callType);
        $stmt->execute();
        $stmt->close();
        $conn->close();
    }
}

// Get other user details if available
$otherUser = null;
if ($otherUserId) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id, first_name, last_name, profile FROM users WHERE id = ?");
    $stmt->bind_param("i", $otherUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $otherUser = $result->fetch_assoc();
    }
    $stmt->close();
    $conn->close();
}

// Set page title
$pageTitle = ($callType === 'video' ? "Video" : "Audio") . " Call";

$extraHeadContent = '
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
<script src="https://cdn.ably.io/lib/ably.min-1.js"></script>
<script src="/bagoscout/assets/js/webrtc.js"></script>
<style>
    body {
        margin: 0;
        padding: 0;
        font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        background-color: #1e1e1e;
        overflow: hidden;
        height: 100vh;
    }
    
    .call-container {
        position: relative;
        width: 100%;
        height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        background-color: #1e1e1e;
        overflow: hidden;
    }
    
    #remote-video {
        position: absolute;
        width: 100%;
        height: 100%;
        object-fit: cover;
        z-index: 1;
        background-color: #000;
    }
    
    #local-video {
        position: absolute;
        width: 20%;
        max-width: 200px;
        aspect-ratio: 16/9;
        right: 20px;
        bottom: 80px;
        border-radius: 8px;
        border: 2px solid white;
        z-index: 10;
        object-fit: cover;
        background-color: #333;
    }
    
    .calling-status {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        color: white;
        z-index: 5;
        background-color: rgba(0, 0, 0, 0.7);
        padding: 40px;
        border-radius: 16px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
        width: 300px;
    }
    
    .avatar-container {
        width: 100px;
        height: 100px;
        margin: 0 auto 20px;
        border-radius: 50%;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #0070f3;
        color: white;
        font-size: 36px;
    }
    
    .avatar-container img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .avatar-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
    }
    
    .controls {
        position: absolute;
        bottom: 20px;
        left: 0;
        right: 0;
        display: flex;
        justify-content: center;
        gap: 16px;
        z-index: 10;
        padding: 16px;
    }
    
    .control-button {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        border: none;
        background-color: rgba(255, 255, 255, 0.2);
        color: white;
        font-size: 20px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    
    .control-button:hover {
        background-color: rgba(255, 255, 255, 0.3);
    }
    
    .control-button.muted, .control-button.video-off {
        background-color: #f44336;
    }
    
    .end-call {
        background-color: #f44336;
    }
    
    .end-call:hover {
        background-color: #d32f2f;
    }
    
    .call-duration {
        position: absolute;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background-color: rgba(0, 0, 0, 0.5);
        color: white;
        padding: 5px 10px;
        border-radius: 4px;
        font-size: 16px;
        font-weight: bold;
        z-index: 100;
    }
</style>
';

// Set page content
$content = '
<div class="call-container">
    <video id="remote-video" autoplay playsinline></video>
    <video id="local-video" autoplay playsinline muted></video>
    
    <div id="calling-status" class="calling-status">
        <div class="avatar-container">
            ' . ($otherUser && $otherUser['profile'] ? 
                '<img src="' . htmlspecialchars($otherUser['profile']) . '" alt="User">' : 
                '<div class="avatar-placeholder">' . 
                    ($otherUser ? substr($otherUser['first_name'], 0, 1) . substr($otherUser['last_name'], 0, 1) : 'U') . 
                '</div>') . '
        </div>
        <h3 class="text-2xl font-bold mb-2">' . ($otherUser ? htmlspecialchars($otherUser['first_name'] . ' ' . $otherUser['last_name']) : 'Unknown User') . '</h3>
        <p class="text-lg" id="call-status-text">Connecting...</p>
    </div>
    
    <div class="controls">
        <button class="control-button" id="toggle-audio" title="Mute/Unmute">
            <i class="fas fa-microphone"></i>
        </button>
        ' . ($callType === 'video' ? '
        <button class="control-button" id="toggle-video" title="Turn Camera On/Off">
            <i class="fas fa-video"></i>
        </button>
        ' : '') . '
        <button class="control-button end-call" id="end-call" title="End Call">
            <i class="fas fa-phone-slash"></i>
        </button>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        // Initialize call with these parameters
        initializeCall({
            callId: "' . $callId . '",
            userId: ' . $currentUser['id'] . ',
            userName: "' . $currentUser['first_name'] . ' ' . $currentUser['last_name'] . '",
            otherUserId: ' . ($otherUserId ?? 'null') . ',
            otherUserName: "' . ($otherUser ? $otherUser['first_name'] . ' ' . $otherUser['last_name'] : 'Unknown User') . '",
            isInitiator: ' . ($isInitiator ? 'true' : 'false') . ',
            callType: "' . $callType . '"
        });
    });
</script>
';

// Ensure body attributes are properly set for call notification system
$bodyAttributes = 'data-user-id="'.$currentUser['id'].'" ' .
                  'data-user-name="'.htmlspecialchars($currentUser['first_name'].' '.$currentUser['last_name']).'" ' .
                  'data-user-role="employer" ' .
                  'data-first-name="'.htmlspecialchars($currentUser['first_name']).'" ' .
                  'data-last-name="'.htmlspecialchars($currentUser['last_name']).'"';

include_once('nav/layout.php');
?> 