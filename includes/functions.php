<?php
/**
 * Utility functions for BagoScout application
 */

/**
 * Sanitize user input
 * 
 * @param string $data The data to sanitize
 * @return string Sanitized data
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Check if user is logged in
 * 
 * @return bool True if user is logged in, false otherwise
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Redirect to a URL
 * 
 * @param string $url The URL to redirect to
 * @return void
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Generate a random token
 * 
 * @param int $length The length of the token
 * @return string The generated token
 */
function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Check if a string is a valid email
 * 
 * @param string $email The email to validate
 * @return bool True if valid, false otherwise
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Get user by ID
 * 
 * @param int $user_id The user ID
 * @return array|null User data or null if not found
 */
function getUserById($user_id) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        return $user;
    }
    
    $stmt->close();
    $conn->close();
    return null;
}

/**
 * Get user by email
 * 
 * @param string $email The user email
 * @return array|null User data or null if not found
 */
function getUserByEmail($email) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        return $user;
    }
    
    $stmt->close();
    $conn->close();
    return null;
}

/**
 * Send verification email to user
 * 
 * @param string $email User's email address
 * @param string $firstName User's first name
 * @param string $lastName User's last name
 * @param string $token Verification token
 * @return bool True if sent successfully, false otherwise
 */
function initiateVerificationEmail($email, $firstName, $lastName, $token) {
    require_once 'mail.php';
    
    // Use the sendVerificationEmail function from mail.php
    return sendVerificationEmail($email, $firstName, $lastName, $token);
}

/**
 * Get the base URL of the application
 * 
 * @return string Base URL
 */
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['SCRIPT_NAME']);
    $path = $path === '\\' || $path === '/' ? '' : $path;
    
    return "$protocol://$host$path";
}

/**
 * Get current user data
 * 
 * @return array|null User data or null if not logged in
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    return $user;
}

/**
 * Check if user has a specific role
 * 
 * @param string $role Role to check
 * @return bool True if user has the role, false otherwise
 */
function hasRole($role) {
    $user = getCurrentUser();
    
    if (!$user) {
        return false;
    }
    
    return $user['role'] === $role;
}

/**
 * Format date for display
 * 
 * @param string $date Date string
 * @param string $format Format string
 * @return string Formatted date
 */
function formatDate($date, $format = 'M j, Y') {
    $dateObj = new DateTime($date);
    return $dateObj->format($format);
}

/**
 * Display alert message
 * 
 * @param string $message Message to display
 * @param string $type Type of alert (success, danger, warning, info)
 * @return string HTML for alert
 */
function alert($message, $type = 'info') {
    return "<div class=\"alert alert-$type\">$message</div>";
}

/**
 * Check if email exists in the database
 * 
 * @param string $email Email to check
 * @return bool True if exists, false otherwise
 */
function emailExists($email) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    
    $stmt->close();
    $conn->close();
    
    return $exists;
}

/**
 * Get user by verification token
 * 
 * @param string $token Verification token
 * @return array|null User data or null if not found
 */
function getUserByToken($token) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT * FROM users WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $user = null;
    } else {
        $user = $result->fetch_assoc();
    }
    
    $stmt->close();
    $conn->close();
    
    return $user;
}

/**
 * Verify user account
 * 
 * @param string $token Verification token
 * @return bool True if verified successfully, false otherwise
 */
function verifyUser($token) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE users SET is_verified = 1, status = 'active', token = NULL WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $success = $stmt->affected_rows > 0;
    
    $stmt->close();
    $conn->close();
    
    return $success;
}

function getAdminEmails() {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT email FROM admins");
    $stmt->execute();
    $result = $stmt->get_result();
    $adminEmails = [];
    while ($row = $result->fetch_assoc()) {
        $adminEmails[] = $row['email'];
    }
    $stmt->close();
    $conn->close();
    return $adminEmails;
}

/**
 * Convert a timestamp to a relative time string (e.g., "2 hours ago")
 * 
 * @param string $datetime Timestamp to convert
 * @param bool $full Whether to show full date
 * @return string Relative time string
 */
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    // Calculate weeks manually as DateInterval doesn't have weeks
    $weeks = floor($diff->d / 7);
    $days_remaining = $diff->d % 7;

    $string = array();
    
    if ($diff->y > 0) {
        $string['y'] = $diff->y . ' year' . ($diff->y > 1 ? 's' : '');
    }
    if ($diff->m > 0) {
        $string['m'] = $diff->m . ' month' . ($diff->m > 1 ? 's' : '');
    }
    if ($weeks > 0) {
        $string['w'] = $weeks . ' week' . ($weeks > 1 ? 's' : '');
    }
    if ($days_remaining > 0) {
        $string['d'] = $days_remaining . ' day' . ($days_remaining > 1 ? 's' : '');
    }
    if ($diff->h > 0) {
        $string['h'] = $diff->h . ' hour' . ($diff->h > 1 ? 's' : '');
    }
    if ($diff->i > 0) {
        $string['i'] = $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');
    }
    if ($diff->s > 0) {
        $string['s'] = $diff->s . ' second' . ($diff->s > 1 ? 's' : '');
    }

    if (!$full) {
        $string = array_slice($string, 0, 1);
    }
    
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

/**
 * Add a comment to a job post
 * 
 * @param int $jobId The job ID
 * @param int $userId The user ID
 * @param string $comment The comment text
 * @return int|bool Comment ID if successful, false otherwise
 */
function addJobComment($jobId, $userId, $comment) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO job_comments (job_id, user_id, comment, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iis", $jobId, $userId, $comment);
    
    if ($stmt->execute()) {
        $commentId = $stmt->insert_id;
        $stmt->close();
        $conn->close();
        return $commentId;
    }
    
    $stmt->close();
    $conn->close();
    return false;
}

/**
 * Add a reply to a comment
 * 
 * @param int $commentId The comment ID
 * @param int $userId The user ID
 * @param string $reply The reply text
 * @return int|bool Reply ID if successful, false otherwise
 */
function addCommentReply($commentId, $userId, $reply) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO comment_replies (comment_id, user_id, reply, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iis", $commentId, $userId, $reply);
    
    if ($stmt->execute()) {
        $replyId = $stmt->insert_id;
        $stmt->close();
        $conn->close();
        return $replyId;
    }
    
    $stmt->close();
    $conn->close();
    return false;
}

/**
 * Get comments for a job post
 * 
 * @param int $jobId The job ID
 * @return array Array of comments
 */
function getJobComments($jobId) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT c.*, u.first_name, u.last_name, u.profile 
        FROM job_comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.job_id = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->bind_param("i", $jobId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $comments = [];
    while ($row = $result->fetch_assoc()) {
        // Get replies for this comment
        $row['replies'] = getCommentReplies($row['id']);
        $comments[] = $row;
    }
    
    $stmt->close();
    $conn->close();
    
    return $comments;
}

/**
 * Get replies for a comment
 * 
 * @param int $commentId The comment ID
 * @return array Array of replies
 */
function getCommentReplies($commentId) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT r.*, u.first_name, u.last_name, u.profile 
        FROM comment_replies r
        JOIN users u ON r.user_id = u.id
        WHERE r.comment_id = ?
        ORDER BY r.created_at ASC
    ");
    $stmt->bind_param("i", $commentId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $replies = [];
    while ($row = $result->fetch_assoc()) {
        $replies[] = $row;
    }
    
    $stmt->close();
    $conn->close();
    
    return $replies;
}

/**
 * Delete a job comment
 * 
 * @param int $commentId The comment ID
 * @param int $userId The user ID (for verification)
 * @return bool True if deleted, false otherwise
 */
function deleteJobComment($commentId, $userId) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("DELETE FROM job_comments WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $commentId, $userId);
    $stmt->execute();
    
    $success = $stmt->affected_rows > 0;
    $stmt->close();
    $conn->close();
    
    return $success;
}

/**
 * Delete a comment reply
 * 
 * @param int $replyId The reply ID
 * @param int $userId The user ID (for verification)
 * @return bool True if deleted, false otherwise
 */
function deleteCommentReply($replyId, $userId) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("DELETE FROM comment_replies WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $replyId, $userId);
    $stmt->execute();
    
    $success = $stmt->affected_rows > 0;
    $stmt->close();
    $conn->close();
    
    return $success;
}

/**
 * Calculate job recommendation score for a jobseeker
 * 
 * @param array $jobFields Array of job fields
 * @param array $seekerFields Array of seeker fields
 * @param array $seekerSkills Array of seeker skills
 * @return float Score between 0 and 1
 */
function calculateJobMatchScore($jobFields, $seekerFields, $seekerSkills) {
    // Convert strings to arrays if needed
    if (is_string($jobFields)) {
        $jobFields = array_map('trim', explode(',', $jobFields));
    }
    
    if (is_string($seekerFields)) {
        $seekerFields = array_map('trim', explode(',', $seekerFields));
    }
    
    if (is_string($seekerSkills)) {
        $seekerSkills = array_map('trim', explode(',', $seekerSkills));
    }
    
    // Calculate field match score (50% weight)
    $fieldMatchCount = count(array_intersect($jobFields, $seekerFields));
    $fieldScore = $fieldMatchCount > 0 ? $fieldMatchCount / count($jobFields) : 0;
    
    // Calculate skill relevance (50% weight)
    // This is a simplified approach - in a real system you might want to use more sophisticated matching
    $skillScore = 0;
    foreach ($seekerSkills as $skill) {
        // Check if skill is directly mentioned in job fields
        foreach ($jobFields as $field) {
            if (stripos($field, $skill) !== false || stripos($skill, $field) !== false) {
                $skillScore += 0.5; // Partial match
            }
        }
    }
    
    // Normalize skill score to 0-1 range
    $skillScore = min($skillScore, 1);
    
    // Calculate final score (weighted average)
    $finalScore = ($fieldScore * 0.5) + ($skillScore * 0.5);
    
    return $finalScore;
}

/**
 * Get recommended jobs for a jobseeker
 * 
 * @param int $seekerId The jobseeker ID
 * @param int $limit Maximum number of recommendations
 * @return array Array of recommended jobs with match scores
 */
function getRecommendedJobs($seekerId, $limit = 10) {
    $conn = getDbConnection();
    
    // Get jobseeker fields and skills
    $stmt = $conn->prepare("SELECT fields, skills FROM jobseekers WHERE id = ?");
    $stmt->bind_param("i", $seekerId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        return [];
    }
    
    $seeker = $result->fetch_assoc();
    $seekerFields = explode(', ', $seeker['fields']);
    $seekerSkills = explode(', ', $seeker['skills']);
    
    // Get active jobs
    $stmt = $conn->prepare("
        SELECT j.*, e.company_name, e.company_type, u.first_name, u.last_name
        FROM jobs j
        JOIN employers e ON j.employer_id = e.id
        JOIN users u ON e.user_id = u.id
        WHERE j.status = 'active'
        ORDER BY j.created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $recommendedJobs = [];
    while ($job = $result->fetch_assoc()) {
        $jobFields = explode(', ', $job['fields']);
        
        // Calculate match score
        $matchScore = calculateJobMatchScore($jobFields, $seekerFields, $seekerSkills);
        
        // Add job with match score
        $job['match_score'] = $matchScore;
        $recommendedJobs[] = $job;
    }
    
    // Sort by match score (descending)
    usort($recommendedJobs, function($a, $b) {
        return $b['match_score'] <=> $a['match_score'];
    });
    
    // Return top recommendations
    return array_slice($recommendedJobs, 0, $limit);
}

/**
 * Check if a jobseeker has applied to a job
 * 
 * @param int $jobId The job ID
 * @param int $seekerId The jobseeker ID
 * @return bool|array False if not applied, application data if applied
 */
function hasAppliedToJob($jobId, $seekerId) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT * FROM applications 
        WHERE job_id = ? AND jobseeker_id = ?
    ");
    $stmt->bind_param("ii", $jobId, $seekerId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $application = $result->fetch_assoc();
        $stmt->close();
        $conn->close();
        return $application;
    }
    
    $stmt->close();
    $conn->close();
    return false;
}

/**
 * Cancel a job application
 * 
 * @param int $applicationId The application ID
 * @param int $seekerId The jobseeker ID (for verification)
 * @return bool True if canceled, false otherwise
 */
function cancelJobApplication($applicationId, $seekerId) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("DELETE FROM applications WHERE id = ? AND jobseeker_id = ?");
    $stmt->bind_param("ii", $applicationId, $seekerId);
    $stmt->execute();
    
    $success = $stmt->affected_rows > 0;
    $stmt->close();
    $conn->close();
    
    return $success;
}

/**
 * Create a notification
 * 
 * @param int $userId The user ID to notify
 * @param string $type The notification type
 * @param string $content The notification content
 * @param int|null $referenceId The reference ID (optional)
 * @param string|null $referenceType The reference type (optional)
 * @return int|bool Notification ID if successful, false otherwise
 */
function createNotification($userId, $type, $content, $referenceId = null, $referenceType = null) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, type, content, reference_id, reference_type, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
    $stmt->bind_param("issss", $userId, $type, $content, $referenceId, $referenceType);
    
    if ($stmt->execute()) {
        $notificationId = $stmt->insert_id;
        $stmt->close();
        
        // Send real-time notification via Ably if available
        $ablyApiKey = "wAsqVg.n1Cj3Q:ljbmcQu_KaVT-VCdxg5Oxg17fwf-7vZVwWCGUEk_Ei4";
        if ($ablyApiKey) {
            $ch = curl_init();
            $url = "https://rest.ably.io/channels/private:user-" . $userId . "/messages";
            $headers = [
                'Authorization: Basic ' . base64_encode($ablyApiKey),
                'Content-Type: application/json'
            ];
            
            $data = [
                'name' => 'notification',
                'data' => json_encode([
                    'id' => $notificationId,
                    'type' => $type,
                    'content' => $content,
                    'reference_id' => $referenceId,
                    'reference_type' => $referenceType,
                    'created_at' => date('Y-m-d H:i:s')
                ])
            ];
            
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            $response = curl_exec($ch);
            curl_close($ch);
        }
        
        $conn->close();
        return $notificationId;
    }
    
    $stmt->close();
    $conn->close();
    return false;
}

/**
 * Get notifications for a user
 * 
 * @param int $userId The user ID
 * @param int $limit Maximum number of notifications
 * @param bool $unreadOnly Get only unread notifications
 * @return array Array of notifications
 */
function getUserNotifications($userId, $limit = 20, $unreadOnly = false) {
    $conn = getDbConnection();
    
    $sql = "SELECT * FROM notifications WHERE user_id = ?";
    if ($unreadOnly) {
        $sql .= " AND is_read = 0";
    }
    $sql .= " ORDER BY created_at DESC LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $userId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    
    $stmt->close();
    $conn->close();
    
    return $notifications;
}

/**
 * Mark notification as read
 * 
 * @param int $notificationId The notification ID
 * @param int $userId The user ID (for verification)
 * @return bool True if marked as read, false otherwise
 */
function markNotificationAsRead($notificationId, $userId) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notificationId, $userId);
    $stmt->execute();
    
    $success = $stmt->affected_rows > 0;
    $stmt->close();
    $conn->close();
    
    return $success;
}

/**
 * Mark all notifications as read for a user
 * 
 * @param int $userId The user ID
 * @return bool True if successful, false otherwise
 */
function markAllNotificationsAsRead($userId) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    
    $success = $stmt->affected_rows > 0;
    $stmt->close();
    $conn->close();
    
    return $success;
}

/**
 * Get unread notification count for a user
 * 
 * @param int $userId The user ID
 * @return int Number of unread notifications
 */
function getUnreadNotificationCount($userId) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $count = $result->fetch_assoc()['count'];
    $stmt->close();
    $conn->close();
    
    return $count;
}