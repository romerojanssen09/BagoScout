<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Initialize response
$response = [
    'success' => false,
    'message' => '',
    'redirect' => ''
];

// Check if the request is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $email = isset($_POST['email']) ? sanitizeInput($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $remember = isset($_POST['remember']) ? true : false;
    
    // Validate input
    if (empty($email) || empty($password)) {
        $response['message'] = 'Email and password are required';
    } else {
        // Check if user exists
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT id, first_name, last_name, email, password, role, status FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                
                // Update last login time
                $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->bind_param("i", $user['id']);
                $stmt->execute();
                
                // Set remember me cookie if checked
                if ($remember) {
                    $token = generateToken();
                    $expiry = time() + (30 * 24 * 60 * 60); // 30 days
                    
                    // Store token in database
                    $stmt = $conn->prepare("UPDATE users SET remember_token = ?, token_expires = FROM_UNIXTIME(?) WHERE id = ?");
                    $stmt->bind_param("sii", $token, $expiry, $user['id']);
                    $stmt->execute();
                    
                    // Set cookie
                    setcookie('remember_token', $token, $expiry, '/', '', false, true);
                }
                
                $response['success'] = true;
                $response['message'] = 'Login successful';
                if ($user['role'] == 'employer') {
                    $response['redirect'] = '../auth-user/employer/dashboard.php';
                } else {
                    $response['redirect'] = '../auth-user/seeker/dashboard.php';
                }
            } else {
                $response['message'] = 'Invalid email or password';
            }
        } else {
            $response['message'] = 'Invalid email or password';
        }
        
        $stmt->close();
        $conn->close();
    }
}

// Return JSON response if AJAX request
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Otherwise, handle regular form submission
if ($response['success']) {
    header("Location: " . $response['redirect']);
    exit;
} else {
    $_SESSION['login_error'] = $response['message'];
    header("Location: ../../index.php");
    exit;
} 