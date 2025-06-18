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

// add to conversation table if not exists
$to = $_GET['to'] ?? null; // 1
if ($to) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id FROM conversations WHERE user1_id = ? AND user2_id = ?");
    $stmt->bind_param("ii", $currentUser['id'], $to);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO conversations (user1_id, user2_id, updated_at, created_at) VALUES (?, ?, NOW(), NOW())");
        $stmt->bind_param("ii", $currentUser['id'], $to);
        $stmt->execute();
    }
}

// Set page title
$pageTitle = "Messages";

// Add Ably script and custom messaging JS to extraHeadContent
$extraHeadContent = '
<script src="https://cdn.ably.io/lib/ably.min-1.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="/bagoscout/assets/js/seeker-messaging.js"></script>
<style>
    @media (max-width: 768px) {
        .flex.flex-col.md\\:flex-row.h-full {
            height: calc(100vh - 64px);
        }
        
        .flex.flex-col.md\\:flex-row.h-full > div:first-child {
            position: absolute;
            top: 0;
            left: 0;
            width: 80%;
            height: 100%;
            z-index: 10;
            background: white;
            border-right: 1px solid #e2e8f0;
        }
        
        .hidden-mobile {
            display: none !important;
        }
    }
</style>
';

// Set page content
$content = '
<div class="flex flex-col md:flex-row h-full">
    <!-- Left sidebar with conversations -->
    <div class="w-full md:w-1/3 lg:w-1/3 bg-white rounded-lg shadow-md overflow-hidden md:mr-4 mb-4 md:mb-0" id="left-sidebar">
        <div class="p-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <h2 class="text-lg font-semibold text-gray-800">Conversations</h2>
                <button id="close-conversation-btn" class="block md:hidden p-2 rounded-full bg-blue-500 text-white hover:bg-blue-600 focus:outline-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="mt-2">
                <input type="text" id="conversation-search" placeholder="Search conversations..." class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </div>
        <div class="h-[calc(80vh-180px)] overflow-y-auto" id="conversation-list">
            <div class="p-4 text-center text-gray-500">
                <p>Loading conversations...</p>
            </div>
        </div>
    </div>

    <!-- Right side with messages -->
    <div class="w-full md:w-2/3 lg:w-2/3 bg-white relative rounded-lg shadow-md overflow-hidden flex flex-col">
        <!-- Initial state when no conversation is selected -->
        <div id="no-conversation-selected" class="flex-1 flex items-center justify-center p-4">
            <button id="mobile-menu-toggle" class="mr-2 p-1 rounded-full hover:bg-gray-200 text-gray-600 md:hidden absolute top-2 left-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7" />
                </svg>
            </button>
            <div class="text-center">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                </svg>
                <h3 class="text-lg font-medium text-gray-900">No conversation selected</h3>
                <p class="text-gray-500 mt-1">Select a conversation or start a new one</p>
            </div>
        </div>

        <!-- Conversation view -->
        <div id="conversation-view" class="hidden flex-1 flex flex-col h-[calc(80vh)]">
            <!-- Conversation header -->
            <div class="p-4 border-b border-gray-200 flex items-center justify-between">
                <div class="flex items-center">
                    <button id="mobile-menu-toggle" class="mr-2 p-1 rounded-full hover:bg-gray-200 text-gray-600 md:hidden">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7" />
                        </svg>
                    </button>
                    <div class="w-10 h-10 rounded-full bg-gray-300 flex-shrink-0 mr-3" id="conversation-avatar">
                        <img id="conversation-avatar-img" src="" alt="" class="w-full h-full rounded-full object-cover hidden">
                        <div id="conversation-avatar-placeholder" class="w-full h-full rounded-full flex items-center justify-center text-white font-semibold"></div>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-lg font-semibold text-gray-800" id="conversation-name"></h3>
                        <p class="text-sm text-gray-500" id="conversation-status">Offline</p>
                    </div>
                </div>
                <div class="flex">
                    <button id="video-call-btn" class="p-2 rounded-full hover:bg-gray-200 text-gray-600" title="Video Call">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Messages area -->
            <div class="flex-1 p-4 overflow-y-auto" id="messages-container">
                <div class="flex flex-col space-y-4" id="messages-list"></div>
            </div>

            <!-- Message input -->
            <div class="p-4 border-t mb-5 border-gray-200">
                <form id="message-form" class="flex flex-col">
                    <div class="flex-1 mb-2">
                        <textarea id="message-input" placeholder="Type a message..." class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none" rows="2"></textarea>
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <label for="file-upload" class="cursor-pointer flex items-center text-blue-500 hover:text-blue-600">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                </svg>
                                <span class="text-sm">Attach File</span>
                            </label>
                            <input id="file-upload" type="file" class="hidden" />
                            <div id="file-upload-container" class="ml-2 hidden">
                                <span id="file-name" class="text-sm text-gray-500"></span>
                                <button type="button" id="clear-file" class="ml-1 text-red-500 hover:text-red-600">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z" />
                            </svg>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
';

// Include layout
$bodyAttributes = 'data-user-id="' . $currentUser['id'] . '" ' .
                 'data-user-name="' . htmlspecialchars($currentUser['first_name'] . ' ' . $currentUser['last_name']) . '" ' .
                 'data-user-role="seeker" ' .
                 'data-first-name="' . htmlspecialchars($currentUser['first_name']) . '" ' .
                 'data-last-name="' . htmlspecialchars($currentUser['last_name']) . '"';

$extraScripts = '
    // Add user data to the body tag
    document.body.dataset.userId = "' . $currentUser['id'] . '";
    document.body.dataset.userName = "' . addslashes($currentUser['first_name'] . ' ' . $currentUser['last_name']) . '";
    document.body.dataset.firstName = "' . addslashes($currentUser['first_name']) . '";
    document.body.dataset.lastName = "' . addslashes($currentUser['last_name']) . '";
';

include_once 'nav/layout.php';
?>