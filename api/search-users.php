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

// Check if query parameter is provided
if (!isset($_GET['query'])) {
    echo json_encode(['success' => false, 'message' => 'Search query is required']);
    exit();
}

$query = $_GET['query'];

try {
    // Get database connection
    $conn = getDbConnection();
    
    // Search for users based on name or email
    $stmt = $conn->prepare("
        SELECT id, CONCAT(first_name, ' ', last_name) AS name, email, profile AS profile_image, role AS user_type
        FROM users
        WHERE (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)
        AND id != ?
        LIMIT 10
    ");
    
    $searchParam = "%$query%";
    $stmt->bind_param("sssi", $searchParam, $searchParam, $searchParam, $currentUser['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    // Filter users based on user type if needed
    // For example, employers can only message job seekers and vice versa
    $filteredUsers = [];
    foreach ($users as $user) {
        // Add your filtering logic here if needed
        // For example:
        if ($currentUser['role'] === 'employer' && $user['user_type'] === 'jobseeker') {
            $filteredUsers[] = $user;
        } elseif ($currentUser['role'] === 'jobseeker' && $user['user_type'] === 'employer') {
            $filteredUsers[] = $user;
        }
        
        // If no filtering is needed, uncomment this line instead
        // $filteredUsers[] = $user;
    }
    
    echo json_encode([
        'success' => true,
        'users' => $filteredUsers
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to search users: ' . $e->getMessage()
    ]);
}
?> 