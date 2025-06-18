<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../config/api_keys.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get current user
$currentUser = getCurrentUser();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['error' => 'User not found']);
    exit();
}

// Handle different operations
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'apply':
        // Apply for a job
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }
        
        // Check if user is a jobseeker
        if ($currentUser['role'] !== 'jobseeker') {
            http_response_code(403);
            echo json_encode(['error' => 'Only jobseekers can apply for jobs']);
            exit();
        }
        
        // Get jobseeker data
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT * FROM jobseekers WHERE user_id = ?");
        $stmt->bind_param("i", $currentUser['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $jobseeker = $result->fetch_assoc();
        $stmt->close();
        
        if (!$jobseeker) {
            http_response_code(404);
            echo json_encode(['error' => 'Jobseeker profile not found']);
            exit();
        }
        
        // Get required parameters
        if (!isset($_POST['job_id']) || !isset($_POST['cover_letter'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            exit();
        }
        
        $jobId = intval($_POST['job_id']);
        $coverLetter = $_POST['cover_letter'];
        $resumeFile = isset($_FILES['resume']) ? $_FILES['resume'] : null;
        
        // Check if job exists and is active
        $stmt = $conn->prepare("
            SELECT j.*, e.user_id as employer_user_id 
            FROM jobs j
            JOIN employers e ON j.employer_id = e.id
            WHERE j.id = ? AND j.status = 'active'
        ");
        $stmt->bind_param("i", $jobId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            $conn->close();
            http_response_code(404);
            echo json_encode(['error' => 'Job not found or not active']);
            exit();
        }
        
        $job = $result->fetch_assoc();
        $stmt->close();
        
        // Check if already applied
        if (hasAppliedToJob($jobId, $jobseeker['id'])) {
            $conn->close();
            http_response_code(400);
            echo json_encode(['error' => 'You have already applied for this job']);
            exit();
        }
        
        // Process resume upload if provided
        $resumePath = null;
        if ($resumeFile && $resumeFile['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/resumes/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $filename = uniqid('resume_') . '_' . basename($resumeFile['name']);
            $uploadFile = $uploadDir . $filename;
            
            if (move_uploaded_file($resumeFile['tmp_name'], $uploadFile)) {
                $resumePath = 'uploads/resumes/' . $filename;
            }
        }
        
        // Insert application
        $stmt = $conn->prepare("
            INSERT INTO applications (job_id, jobseeker_id, cover_letter, resume, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->bind_param("iiss", $jobId, $jobseeker['id'], $coverLetter, $resumePath);
        
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->close();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to submit application']);
            exit();
        }
        
        $applicationId = $stmt->insert_id;
        $stmt->close();
        
        // Create notification for employer
        $notificationContent = "New application received for job: " . $job['title'];
        createNotification($job['employer_user_id'], 'application', $notificationContent, $jobId, 'job');
        
        // Send real-time notification via Ably
        $ablyApiKey = getApiKey('ably');
        if ($ablyApiKey) {
            $ch = curl_init();
            $url = "https://rest.ably.io/channels/applications/messages";
            $headers = [
                'Authorization: Basic ' . base64_encode($ablyApiKey),
                'Content-Type: application/json'
            ];
            
            $data = [
                'name' => 'new-application',
                'data' => json_encode([
                    'job_id' => $jobId,
                    'application_id' => $applicationId,
                    'jobseeker_id' => $jobseeker['id'],
                    'jobseeker_name' => $currentUser['first_name'] . ' ' . $currentUser['last_name'],
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
        
        echo json_encode([
            'success' => true,
            'message' => 'Application submitted successfully',
            'application_id' => $applicationId
        ]);
        break;
        
    case 'cancel':
        // Cancel a job application
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit();
        }
        
        // Check if user is a jobseeker
        if ($currentUser['role'] !== 'jobseeker') {
            http_response_code(403);
            echo json_encode(['error' => 'Only jobseekers can cancel applications']);
            exit();
        }
        
        // Get jobseeker data
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT * FROM jobseekers WHERE user_id = ?");
        $stmt->bind_param("i", $currentUser['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $jobseeker = $result->fetch_assoc();
        $stmt->close();
        
        if (!$jobseeker) {
            $conn->close();
            http_response_code(404);
            echo json_encode(['error' => 'Jobseeker profile not found']);
            exit();
        }
        
        // Get required parameters
        if (!isset($_POST['application_id'])) {
            $conn->close();
            http_response_code(400);
            echo json_encode(['error' => 'Application ID is required']);
            exit();
        }
        
        $applicationId = intval($_POST['application_id']);
        
        // Get application data
        $stmt = $conn->prepare("
            SELECT a.*, j.title as job_title, e.user_id as employer_user_id
            FROM applications a
            JOIN jobs j ON a.job_id = j.id
            JOIN employers e ON j.employer_id = e.id
            WHERE a.id = ? AND a.jobseeker_id = ?
        ");
        $stmt->bind_param("ii", $applicationId, $jobseeker['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            $conn->close();
            http_response_code(404);
            echo json_encode(['error' => 'Application not found or not owned by you']);
            exit();
        }
        
        $application = $result->fetch_assoc();
        $stmt->close();
        
        // Delete application
        $success = cancelJobApplication($applicationId, $jobseeker['id']);
        
        if (!$success) {
            $conn->close();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to cancel application']);
            exit();
        }
        
        // Create notification for employer
        $notificationContent = "Application cancelled for job: " . $application['job_title'];
        createNotification($application['employer_user_id'], 'application', $notificationContent, $application['job_id'], 'job');
        
        // Send real-time notification via Ably
        $ablyApiKey = getApiKey('ably');
        if ($ablyApiKey) {
            $ch = curl_init();
            $url = "https://rest.ably.io/channels/applications/messages";
            $headers = [
                'Authorization: Basic ' . base64_encode($ablyApiKey),
                'Content-Type: application/json'
            ];
            
            $data = [
                'name' => 'application-cancelled',
                'data' => json_encode([
                    'job_id' => $application['job_id'],
                    'application_id' => $applicationId,
                    'jobseeker_id' => $jobseeker['id'],
                    'jobseeker_name' => $currentUser['first_name'] . ' ' . $currentUser['last_name'],
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
        
        echo json_encode([
            'success' => true,
            'message' => 'Application cancelled successfully'
        ]);
        break;
        
    case 'get_my_applications':
        // Get applications for the current jobseeker
        if ($currentUser['role'] !== 'jobseeker') {
            http_response_code(403);
            echo json_encode(['error' => 'Only jobseekers can view their applications']);
            exit();
        }
        
        // Get jobseeker data
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT * FROM jobseekers WHERE user_id = ?");
        $stmt->bind_param("i", $currentUser['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $jobseeker = $result->fetch_assoc();
        $stmt->close();
        
        if (!$jobseeker) {
            $conn->close();
            http_response_code(404);
            echo json_encode(['error' => 'Jobseeker profile not found']);
            exit();
        }
        
        // Get applications
        $stmt = $conn->prepare("
            SELECT a.*, 
                   j.title as job_title, j.location as job_location, j.job_type, j.salary_range,
                   e.company_name, e.company_logo,
                   u.first_name as employer_first_name, u.last_name as employer_last_name
            FROM applications a
            JOIN jobs j ON a.job_id = j.id
            JOIN employers e ON j.employer_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE a.jobseeker_id = ?
            ORDER BY a.created_at DESC
        ");
        $stmt->bind_param("i", $jobseeker['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $applications = [];
        while ($row = $result->fetch_assoc()) {
            $applications[] = $row;
        }
        
        $stmt->close();
        $conn->close();
        
        echo json_encode([
            'success' => true,
            'applications' => $applications
        ]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?> 