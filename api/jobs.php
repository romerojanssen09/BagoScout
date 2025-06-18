<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Get current user
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Handle different actions
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'get_employer_jobs':
        // Get employer info
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT * FROM employers WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employer = $result->fetch_assoc();
        $employerId = $employer['id'];
        $stmt->close();
        
        // Get jobs
        $stmt = $conn->prepare("
            SELECT *
            FROM jobs 
            WHERE employer_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->bind_param("i", $employerId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $jobs = [];
        while ($job = $result->fetch_assoc()) {
            $jobs[] = $job;
        }
        
        $stmt->close();
        $conn->close();
        
        echo json_encode(['success' => true, 'jobs' => $jobs]);
        break;
    case 'get_all_jobs_employer':
        $conn = getDbConnection();
        $stmt = $conn->prepare("
        SELECT jobs.*, employers.*,  COUNT(applications.id) AS application_count
        FROM jobs
        JOIN employers ON jobs.employer_id = employers.id
        LEFT JOIN applications ON jobs.id = applications.job_id
        GROUP BY jobs.id
        ORDER BY jobs.created_at DESC
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $jobs = [];
        while ($job = $result->fetch_assoc()) {
            $jobs[] = $job;
        }
        
        $stmt->close();
        $conn->close();
        
        echo json_encode(['success' => true, 'jobs' => $jobs]);
        break;
    case 'get_all_jobs':
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT * FROM jobs ");
        $stmt->execute();
        $result = $stmt->get_result();
        $jobs = [];
        while ($job = $result->fetch_assoc()) {
            $jobs[] = $job;
        }
        
        $stmt->close();
        $conn->close();
        
        echo json_encode(['success' => true, 'jobs' => $jobs]);
        break;
    case 'search_jobs':
        $search = isset($_GET['search']) ? $_GET['search'] : '';
        
        if (empty($search)) {
            echo json_encode(['success' => false, 'message' => 'Search term is required']);
            break;
        }
        
        $conn = getDbConnection();
        
        // Search in title, company_name, location, and description
        $searchTerm = "%{$search}%";
        $stmt = $conn->prepare("
            SELECT * FROM jobs 
            WHERE title LIKE ? 
            OR company_name LIKE ? 
            OR location LIKE ? 
            OR description LIKE ?
            OR job_type LIKE ?
        ");
        $stmt->bind_param("sssss", $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $jobs = [];
        while ($job = $result->fetch_assoc()) {
            $jobs[] = $job;
        }
        
        $stmt->close();
        $conn->close();
        
        echo json_encode(['success' => true, 'jobs' => $jobs]);
        break;
    case 'get_job':
        $jobId = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if ($jobId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid job ID']);
            break;
        }
        
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->bind_param("i", $jobId);
        $stmt->execute();
        $result = $stmt->get_result();
        $job = $result->fetch_assoc();
        
        $stmt->close();
        $conn->close();
        
        if ($job) {
            echo json_encode(['success' => true, 'job' => $job]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Job not found']);
        }
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
?> 