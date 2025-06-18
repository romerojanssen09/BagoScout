<?php
session_start();
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../../index.php");
    exit();
}

// Get user data
$user = getCurrentUser();

// If user data couldn't be retrieved, log out
if (!$user) {
    session_destroy();
    header("Location: ../../../index.php?error=session_expired");
    exit();
}

// Check if user is an employer
if ($user['role'] !== 'employer') {
    header("Location: dashboard.php?error=unauthorized");
    exit();
}

// Check if job ID is provided
if (!isset($_GET['job']) || !is_numeric($_GET['job'])) {
    header("Location: manage-jobs.php?error=invalid_job");
    exit();
}

$job_id = $_GET['job'];

// Get employer data
$conn = getDbConnection();
$stmt = $conn->prepare("SELECT * FROM employers WHERE user_id = ?");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$result = $stmt->get_result();
$employer = $result->fetch_assoc();
$stmt->close();

// Check if job belongs to this employer
$stmt = $conn->prepare("SELECT * FROM jobs WHERE id = ? AND employer_id = ?");
$stmt->bind_param("ii", $job_id, $employer['id']);
$stmt->execute();
$result = $stmt->get_result();
$job = $result->fetch_assoc();
$stmt->close();

if (!$job) {
    header("Location: manage-jobs.php?error=unauthorized");
    exit();
}

// Process application actions
$message = '';
$messageType = '';

// Change application status
if (isset($_GET['action']) && isset($_GET['application']) && is_numeric($_GET['application'])) {
    $application_id = $_GET['application'];
    $action = $_GET['action'];
    $status = '';
    
    switch ($action) {
        case 'review':
            $status = 'reviewed';
            break;
        case 'shortlist':
            $status = 'shortlisted';
            break;
        case 'reject':
            $status = 'rejected';
            break;
        case 'hire':
            $status = 'hired';
            break;
    }
    
    if ($status) {
        // Check if application belongs to this job
        $stmt = $conn->prepare("SELECT id FROM applications WHERE id = ? AND job_id = ?");
        $stmt->bind_param("ii", $application_id, $job_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE applications SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $application_id);
            
            if ($stmt->execute()) {
                // Add log entry
                $stmt = $conn->prepare("INSERT INTO application_logs (application_id, log_type, message, created_at) VALUES (?, 'status_change', ?, NOW())");
                $logMessage = "Status changed to " . ucfirst($status);
                $stmt->bind_param("is", $application_id, $logMessage);
                $stmt->execute();
                
                $message = "Application status updated successfully!";
                $messageType = "success";
            } else {
                $message = "Error updating application status: " . $conn->error;
                $messageType = "error";
            }
        } else {
            $message = "Unauthorized action or application not found.";
            $messageType = "error";
        }
        $stmt->close();
    }
}

// Add employer notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['application_id']) && isset($_POST['employer_notes'])) {
    $application_id = $_POST['application_id'];
    $notes = trim($_POST['employer_notes']);
    
    // Check if application belongs to this job
    $stmt = $conn->prepare("SELECT id FROM applications WHERE id = ? AND job_id = ?");
    $stmt->bind_param("ii", $application_id, $job_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE applications SET employer_notes = ? WHERE id = ?");
        $stmt->bind_param("si", $notes, $application_id);
        
        if ($stmt->execute()) {
            // Add log entry
            $stmt = $conn->prepare("INSERT INTO application_logs (application_id, log_type, message, created_at) VALUES (?, 'employer_review', 'Employer added notes', NOW())");
            $stmt->bind_param("i", $application_id);
            $stmt->execute();
            
            $message = "Notes saved successfully!";
            $messageType = "success";
        } else {
            $message = "Error saving notes: " . $conn->error;
            $messageType = "error";
        }
    } else {
        $message = "Unauthorized action or application not found.";
        $messageType = "error";
    }
    $stmt->close();
}

// Get all applications for this job
$applications = [];
$stmt = $conn->prepare("
    SELECT a.*, 
           u.first_name, u.last_name, u.email, u.phone,
           j.title as job_title,
           js.skills,
           js.user_id
    FROM applications a
    JOIN jobseekers js ON a.jobseeker_id = js.id
    JOIN users u ON js.user_id = u.id
    JOIN jobs j ON a.job_id = j.id
    WHERE a.job_id = ?
    ORDER BY a.created_at DESC
");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();

while ($application = $result->fetch_assoc()) {
    $applications[] = $application;
}
$stmt->close();

$conn->close();

// Set page title
$pageTitle = "View Applications - " . $job['title'];

// Add extra head content
$extraHeadContent = '
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    .application-card { transition: all 0.2s ease; }
    .application-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }
</style>
';

// Set page content
$content = '
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">Applications for "' . htmlspecialchars($job['title']) . '"</h1>
        <p class="text-gray-600">Posted on ' . date('F j, Y', strtotime($job['created_at'])) . '</p>
    </div>
    <a href="manage-jobs.php" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 transition duration-300 flex items-center">
        <i class="fas fa-arrow-left mr-2"></i> Back to Jobs
    </a>
</div>

' . ($message ? '
<div class="mb-6 p-4 rounded-md ' . ($messageType === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800') . '">
    <div class="flex">
        <div class="flex-shrink-0">
            <i class="fas ' . ($messageType === 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-circle text-red-400') . '"></i>
        </div>
        <div class="ml-3">
            <p class="text-sm font-medium">' . $message . '</p>
        </div>
    </div>
</div>
' : '') . '

<div class="bg-white rounded-lg shadow-sm p-6 mb-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-semibold text-gray-800">Job Details</h2>
        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ' . 
            ($job['status'] === 'active' ? 'bg-green-100 text-green-800' : 
             ($job['status'] === 'paused' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800')) . '">
            ' . ucfirst($job['status']) . '
        </span>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <h3 class="text-sm font-medium text-gray-500">Location</h3>
            <p class="mt-1 text-sm text-gray-900">' . htmlspecialchars($job['location']) . '</p>
            
            <h3 class="text-sm font-medium text-gray-500 mt-4">Job Type</h3>
            <p class="mt-1 text-sm text-gray-900">' . htmlspecialchars($job['job_type']) . '</p>
            
            <h3 class="text-sm font-medium text-gray-500 mt-4">Required Documents</h3>
            <p class="mt-1 text-sm text-gray-900">' . 
                str_replace(['resume', 'police_clearance', 'none'], ['Resume', 'Police Clearance', 'None'], $job['required_documents']) . 
            '</p>
        </div>
        
        <div>
            <h3 class="text-sm font-medium text-gray-500">Pay Type</h3>
            <p class="mt-1 text-sm text-gray-900">' . 
                ($job['pay_type'] === 'hourly' ? 'Hourly Rate' : 
                 ($job['pay_type'] === 'monthly' ? 'Monthly Salary' : 'Annual Salary')) . 
            '</p>
            
            <h3 class="text-sm font-medium text-gray-500 mt-4">Salary</h3>
            <p class="mt-1 text-sm text-gray-900">₱' . number_format($job['salary_min'], 2) . '</p>
            
            <h3 class="text-sm font-medium text-gray-500 mt-4">Response Time</h3>
            <p class="mt-1 text-sm text-gray-900">' . 
                ($job['response_time'] === 'within_hour' ? 'Within an hour' : 
                 ($job['response_time'] === 'within_day' ? 'Within a day' : 'Within a week')) . 
            '</p>
        </div>
    </div>
</div>

<div class="bg-white rounded-lg shadow-sm p-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">Applications (' . count($applications) . ')</h2>
    
    ' . (empty($applications) ? '
    <div class="text-center py-8">
        <div class="text-gray-400 mb-4">
            <i class="fas fa-file-alt text-5xl"></i>
        </div>
        <h3 class="text-lg font-medium text-gray-900 mb-1">No Applications Yet</h3>
        <p class="text-gray-500">You haven\'t received any applications for this job yet.</p>
    </div>
    ' : '
    <div class="space-y-6">
        ' . implode('', array_map(function($application) {
            $statusColor = [
                'pending' => 'bg-blue-100 text-blue-800',
                'reviewed' => 'bg-yellow-100 text-yellow-800',
                'shortlisted' => 'bg-purple-100 text-purple-800',
                'rejected' => 'bg-red-100 text-red-800',
                'hired' => 'bg-green-100 text-green-800'
            ][$application['status']];
            
            $statusLabel = ucfirst($application['status']);
            $appliedDate = date('M j, Y', strtotime($application['created_at']));
            
            return '
            <div class="application-card border border-gray-200 rounded-lg p-4">
                <div class="flex flex-col md:flex-row md:justify-between md:items-start">
                    <div>
                        <a href="../../auth-user/seeker-profile.php?id=' . $application['jobseeker_id'] . '" class="hover:text-blue-600">
                            <h3 class="text-lg font-medium text-gray-900">' . htmlspecialchars($application['first_name'] . ' ' . $application['last_name']) . '</h3>
                        </a>
                        <p class="text-sm text-gray-600 mb-2">Applied on ' . $appliedDate . '</p>
                        
                        <div class="flex flex-wrap gap-2 mb-3">
                            <span class="px-2 py-1 text-xs font-medium rounded-full ' . $statusColor . '">' . $statusLabel . '</span>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <p class="text-gray-500">Email:</p>
                                <p class="font-medium">' . htmlspecialchars($application['email']) . '</p>
                            </div>
                            <div>
                                <p class="text-gray-500">Phone:</p>
                                <p class="font-medium">' . htmlspecialchars($application['phone']) . '</p>
                            </div>
                            <div>
                                <p class="text-gray-500">Skills:</p>
                                <p class="font-medium">' . htmlspecialchars($application['skills']) . '</p>
                            </div>
                        </div>
                        
                        ' . ($application['cover_letter'] ? '
                        <div class="mt-4">
                            <h4 class="text-sm font-medium text-gray-700 mb-1">Cover Letter:</h4>
                            <div class="text-sm text-gray-600 bg-gray-50 p-3 rounded-md">
                                ' . nl2br(htmlspecialchars($application['cover_letter'])) . '
                            </div>
                        </div>
                        ' : '') . '
                        
                        ' . ($application['resume'] || $application['police_clearance'] ? '
                        <div class="mt-4">
                            <h4 class="text-sm font-medium text-gray-700 mb-1">Documents:</h4>
                            <div class="flex flex-wrap gap-2">
                                ' . ($application['resume'] ? '
                                <a href="../../uploads/resumes/' . $application['resume'] . '" target="_blank" class="inline-flex items-center px-3 py-1 bg-blue-50 text-blue-700 rounded-md text-sm hover:bg-blue-100">
                                    <i class="fas fa-file-pdf mr-1"></i> Resume
                                </a>
                                ' : '') . '
                                ' . ($application['police_clearance'] ? '
                                <a href="../../uploads/documents/' . $application['police_clearance'] . '" target="_blank" class="inline-flex items-center px-3 py-1 bg-blue-50 text-blue-700 rounded-md text-sm hover:bg-blue-100">
                                    <i class="fas fa-file-pdf mr-1"></i> Police Clearance
                                </a>
                                ' : '') . '
                            </div>
                        </div>
                        ' : '') . '
                    </div>
                    
                    <div class="mt-4 md:mt-0 md:ml-4 flex flex-col space-y-2">
                        <div class="dropdown relative">
                            <button class="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-300 flex items-center justify-center">
                                <i class="fas fa-cog mr-2"></i> Actions <i class="fas fa-chevron-down ml-2"></i>
                            </button>
                            <div class="dropdown-menu hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-10">
                                <a href="view-applications.php?job=' . $application['job_id'] . '&application=' . $application['id'] . '&action=review" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Mark as Reviewed</a>
                                <a href="view-applications.php?job=' . $application['job_id'] . '&application=' . $application['id'] . '&action=shortlist" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Shortlist</a>
                                <a href="view-applications.php?job=' . $application['job_id'] . '&application=' . $application['id'] . '&action=reject" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Reject</a>
                                <a href="view-applications.php?job=' . $application['job_id'] . '&application=' . $application['id'] . '&action=hire" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Hire</a>
                                <button onclick="showNotesModal(' . $application['id'] . ', `' . addslashes($application['employer_notes'] ?? '') . '`)" class="block w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Add Notes</button>
                            </div>
                        </div>
                        
                        <a href="message.php?to=' . $application['user_id'] . '" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 transition duration-300 text-center">
                            <i class="fas fa-envelope mr-2"></i> Message
                        </a>
                    </div>
                </div>
                
                ' . (!empty($application['employer_notes']) ? '
                <div class="mt-4 border-t border-gray-200 pt-4">
                    <h4 class="text-sm font-medium text-gray-700 mb-1">Your Notes:</h4>
                    <div class="text-sm text-gray-600 bg-gray-50 p-3 rounded-md">
                        ' . nl2br(htmlspecialchars($application['employer_notes'])) . '
                    </div>
                </div>
                ' : '') . '
            </div>
            ';
        }, $applications)) . '
    </div>
    ') . '
</div>

<!-- Notes Modal -->
<div id="notesModal" class="fixed inset-0 z-50 hidden overflow-auto bg-black bg-opacity-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-medium text-gray-900">Add Notes</h3>
            <button id="closeNotesModal" class="text-gray-400 hover:text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="notesForm" method="POST" action="">
            <div class="p-4">
                <input type="hidden" id="application_id" name="application_id" value="">
                <label for="employer_notes" class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea id="employer_notes" name="employer_notes" rows="4" class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                <p class="text-xs text-gray-500 mt-1">Add private notes about this candidate. Only you can see these notes.</p>
            </div>
            <div class="p-4 border-t border-gray-200 flex justify-end">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-300">
                    Save Notes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Dropdown toggle
    const dropdownToggles = document.querySelectorAll(".dropdown button");
    
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener("click", function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const menu = this.parentElement.querySelector(".dropdown-menu");
            menu.classList.toggle("hidden");
            
            // Close other dropdowns
            document.querySelectorAll(".dropdown-menu").forEach(otherMenu => {
                if (otherMenu !== menu) {
                    otherMenu.classList.add("hidden");
                }
            });
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener("click", function() {
        document.querySelectorAll(".dropdown-menu").forEach(menu => {
            menu.classList.add("hidden");
        });
    });
    
    // Notes modal
    const notesModal = document.getElementById("notesModal");
    const closeNotesModal = document.getElementById("closeNotesModal");
    
    window.showNotesModal = function(applicationId, notes) {
        document.getElementById("application_id").value = applicationId;
        document.getElementById("employer_notes").value = notes;
        notesModal.classList.remove("hidden");
    };
    
    closeNotesModal.addEventListener("click", function() {
        notesModal.classList.add("hidden");
    });
    
    // Close modal when clicking outside
    window.addEventListener("click", function(event) {
        if (event.target === notesModal) {
            notesModal.classList.add("hidden");
        }
    });
});
</script>
';

// Include layout
include 'nav/layout.php';
?> 