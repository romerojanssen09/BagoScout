<?php
session_start();
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
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
    header("Location: dashboard.php");
    exit();
}

// Get employer-specific data
$conn = getDbConnection();
$stmt = $conn->prepare("SELECT * FROM employers WHERE user_id = ?");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$result = $stmt->get_result();
$employer = $result->fetch_assoc();
$stmt->close();

// Get job statistics
$activeJobs = 0;
$totalJobs = 0;
$totalApplications = 0;

if ($employer) {
    // Count active jobs
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM jobs WHERE employer_id = ? AND status = 'active'");
    $stmt->bind_param("i", $employer['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $activeJobs = $result->fetch_assoc()['count'];
    $stmt->close();
    
    // Count total jobs
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM jobs WHERE employer_id = ?");
    $stmt->bind_param("i", $employer['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $totalJobs = $result->fetch_assoc()['count'];
    $stmt->close();
    
    // Count total applications
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM applications a 
        JOIN jobs j ON a.job_id = j.id 
        WHERE j.employer_id = ?
    ");
    $stmt->bind_param("i", $employer['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $totalApplications = $result->fetch_assoc()['count'];
    $stmt->close();
    
    // Get recent applications
    $recentApplications = [];
    $stmt = $conn->prepare("
        SELECT a.*, 
               u.first_name, u.last_name, u.email,
               j.title as job_title, j.id as job_id
        FROM applications a
        JOIN jobseekers js ON a.jobseeker_id = js.id
        JOIN users u ON js.user_id = u.id
        JOIN jobs j ON a.job_id = j.id
        WHERE j.employer_id = ?
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $stmt->bind_param("i", $employer['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($application = $result->fetch_assoc()) {
        $recentApplications[] = $application;
    }
    $stmt->close();
}

$conn->close();

// Set role title
$roleTitle = 'Employer';

// Check if account is pending
$pendingNotice = '';
if ($user['status'] == 'unverified' || $user['status'] == 'rejected') {
    $pendingNotice = '
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <h3 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">Account Verification</h3>';
        
    if ($user['status'] == 'rejected') {
        $pendingNotice .= '
        <div class="bg-red-50 border-l-4 border-red-400 p-4 mb-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-circle text-red-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800">Account Rejected</h3>
                    <p class="text-sm text-red-700 mt-1">
                        Your account verification was rejected. Please check your email for more information about why your verification was rejected.
                    </p>
                    <p class="text-sm text-red-700 mt-2">
                        To reactivate your account, please upload new verification documents and submit for approval again.
                    </p>
                </div>
            </div>
        </div>';
    }
    
    $pendingNotice .= '
        <p class="text-gray-700 mb-4">To access all features, please complete your profile and upload the required documents:</p>
        <div class="space-y-4">';
        
    // Check if photo is uploaded
    if ($employer && !empty($employer['facephoto'])) {
        $pendingNotice .= '
            <div class="flex items-center">
                <div class="w-6 h-6 bg-green-200 rounded-full flex items-center justify-center mr-3">
                    <i class="fas fa-check text-sm text-green-500"></i>
                </div>
                <span class="text-green-700">Photo uploaded</span>
            </div>';
    } else {
        $pendingNotice .= '
            <div class="flex items-center">
                <div class="w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center mr-3">
                    <i class="fas fa-user text-sm text-gray-500"></i>
                </div>
                <span class="text-gray-700">Upload your photo (using webcam)</span>
            </div>';
    }
    
    // Check if ID is uploaded
    if ($employer && !empty($employer['valid_id'])) {
        $pendingNotice .= '
            <div class="flex items-center">
                <div class="w-6 h-6 bg-green-200 rounded-full flex items-center justify-center mr-3">
                    <i class="fas fa-check text-sm text-green-500"></i>
                </div>
                <span class="text-green-700">Valid ID uploaded</span>
            </div>';
    } else {
        $pendingNotice .= '
            <div class="flex items-center">
                <div class="w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center mr-3">
                    <i class="fas fa-id-card text-sm text-gray-500"></i>
                </div>
                <span class="text-gray-700">Upload a valid ID</span>
            </div>';
    }
    
    $pendingNotice .= '
        </div>';
    
    // Show appropriate button based on status
    if ($user['status'] == 'under_review') {
        $pendingNotice .= '
        <div class="mt-6">
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-clock text-yellow-400"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-yellow-800">Under Review</h3>
                        <p class="text-sm text-yellow-700">
                            Your account is currently under review. An administrator will verify your documents shortly.
                        </p>
                    </div>
                </div>
            </div>
        </div>';
    } else if ($employer && !empty($employer['facephoto']) && !empty($employer['valid_id'])) {
        $pendingNotice .= '
        <div class="mt-6">
            <form action="profile.php" method="post">
                <input type="hidden" name="request_approval" value="1">
                <button type="submit" class="px-4 py-2 bg-green-500 text-white font-medium rounded-md hover:bg-green-600 transition duration-300">
                    Submit for Approval
                </button>
            </form>
        </div>';
    } else {
        $pendingNotice .= '
        <div class="mt-6">
            <a href="profile.php" class="px-4 py-2 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300">
                Complete Profile
            </a>
        </div>';
    }
    
    $pendingNotice .= '
    </div>';
} else if ($user['status'] == 'under_review') {
    $pendingNotice = '
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <h3 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">Account Verification</h3>
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-clock text-yellow-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-yellow-800">Under Review</h3>
                    <p class="text-sm text-yellow-700">
                        Your account is currently under review. An administrator will verify your documents shortly.
                    </p>
                </div>
            </div>
        </div>
    </div>';
} else if ($user['status'] == 'active') {
    $pendingNotice = '
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <h3 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">Account Verification</h3>
        <div class="bg-green-50 border-l-4 border-green-400 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-green-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-green-800">Verified Account</h3>
                    <p class="text-sm text-green-700">
                        Your account has been verified and is active. You have full access to all features.
                    </p>
                </div>
            </div>
        </div>
    </div>';
} else if ($user['status'] == 'suspended') {
    $pendingNotice = '
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <h3 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">Account Verification</h3>
        <div class="bg-red-50 border-l-4 border-red-400 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-ban text-red-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800">Account Suspended</h3>
                    <p class="text-sm text-red-700 mt-1">
                        Your account has been suspended by an administrator. This action cannot be reversed through this interface.
                    </p>
                    <p class="text-sm text-red-700 mt-2">
                        If you believe this was done in error, please contact our support team for assistance.
                    </p>
                    <div class="mt-4">
                        <a href="../../../pages/contact.php" class="px-4 py-2 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300 inline-block">
                            Contact Support
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Show a notification about account suspension when the page loads
        document.addEventListener("DOMContentLoaded", function() {
            if (typeof Swal !== "undefined") {
                Swal.fire({
                    title: "Account Suspended",
                    text: "Your account has been suspended by an administrator. Please contact support for assistance.",
                    icon: "error",
                    confirmButtonText: "Contact Support",
                    showCancelButton: true,
                    cancelButtonText: "Close"
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = "../../../pages/contact.php";
                    }
                });
                
                // Automatically log out after showing the message
                setTimeout(function() {
                    window.location.href = "../../../pages/logout.php";
                }, 10000); // 10 seconds delay
            }
        });
    </script>';
}

// Set page title
$pageTitle = "Employer Dashboard";

// Extra head content
$extraHeadContent = '
<style>
    .dashboard-card {
        transition: all 0.3s ease;
    }
    .dashboard-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    }
</style>
';

// Set page content
$content = '
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">Welcome, ' . htmlspecialchars($user['first_name']) . '!</h1>
    <p class="text-gray-600">Here\'s an overview of your ' . $roleTitle . ' account.</p>
</div>

' . $pendingNotice . '

<!-- Statistics Cards -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
    <div class="bg-white rounded-lg shadow-sm p-6 dashboard-card">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Active Jobs</p>
                <p class="text-2xl font-bold text-gray-900">' . $activeJobs . '</p>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                <i class="fas fa-briefcase text-blue-600 text-xl"></i>
            </div>
        </div>
        <div class="mt-4">
            ' . ($activeJobs > 0 ? 
                '<a href="manage-jobs.php" class="text-sm text-blue-600 hover:underline">View active jobs</a>' : 
                '<span class="text-sm text-gray-500">No active job postings</span>') . '
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm p-6 dashboard-card">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Total Applications</p>
                <p class="text-2xl font-bold text-gray-900">' . $totalApplications . '</p>
            </div>
            <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                <i class="fas fa-file-alt text-green-600 text-xl"></i>
            </div>
        </div>
        <div class="mt-4">
            ' . ($totalApplications > 0 ? 
                '<a href="candidates.php" class="text-sm text-blue-600 hover:underline">View all applications</a>' : 
                '<span class="text-sm text-gray-500">No applications received</span>') . '
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm p-6 dashboard-card">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Total Jobs</p>
                <p class="text-2xl font-bold text-gray-900">' . $totalJobs . '</p>
            </div>
            <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                <i class="fas fa-clipboard-list text-purple-600 text-xl"></i>
            </div>
        </div>
        <div class="mt-4">
            ' . ($totalJobs > 0 ? 
                '<a href="manage-jobs.php" class="text-sm text-blue-600 hover:underline">Manage all jobs</a>' : 
                '<span class="text-sm text-gray-500">No jobs posted yet</span>') . '
        </div>
    </div>
</div>

<!-- Company Profile -->
<div class="bg-white rounded-lg shadow-sm p-6 mb-6">
    <div class="flex justify-between items-center mb-4 pb-2 border-b border-gray-200">
        <h3 class="text-xl font-semibold text-gray-800">Company Profile</h3>
        <a href="profile.php" class="text-blue-600 hover:text-blue-700 text-sm font-medium">Edit Profile</a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="space-y-4">
            <div>
                <p class="text-sm font-medium text-gray-500">Company Name</p>
                <p class="text-gray-900">' . ($employer ? htmlspecialchars($employer['company_name']) : 'Not set') . '</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500">Company Type</p>
                <p class="text-gray-900">' . ($employer ? htmlspecialchars($employer['company_type']) : 'Not set') . '</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500">Your Role</p>
                <p class="text-gray-900">' . ($employer ? htmlspecialchars($employer['role_in_company']) : 'Not set') . '</p>
            </div>
        </div>
        <div class="space-y-4">
            <div>
                <p class="text-sm font-medium text-gray-500">Website</p>
                <p class="text-gray-900">' . ($employer && $employer['company_url'] ? '<a href="' . htmlspecialchars($employer['company_url']) . '" class="text-blue-600 hover:underline" target="_blank">' . htmlspecialchars($employer['company_url']) . '</a>' : 'Not set') . '</p>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-500">Fields</p>
                <p class="text-gray-900">' . ($employer ? htmlspecialchars($employer['fields']) : 'Not set') . '</p>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="bg-white rounded-lg shadow-sm p-6 mb-6">
    <h3 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">Quick Actions</h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <a href="post-job.php" class="flex items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
            <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                <i class="fas fa-plus-circle text-blue-600"></i>
            </div>
            <div>
                <p class="font-medium text-gray-800">Post a Job</p>
                <p class="text-xs text-gray-500">Create a new job listing</p>
            </div>
        </a>
        <a href="manage-jobs.php" class="flex items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
            <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center mr-3">
                <i class="fas fa-briefcase text-green-600"></i>
            </div>
            <div>
                <p class="font-medium text-gray-800">Manage Jobs</p>
                <p class="text-xs text-gray-500">View and edit your job listings</p>
            </div>
        </a>
        <a href="candidates.php" class="flex items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
            <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center mr-3">
                <i class="fas fa-users text-purple-600"></i>
            </div>
            <div>
                <p class="font-medium text-gray-800">View Candidates</p>
                <p class="text-xs text-gray-500">Review job applications</p>
            </div>
        </a>
    </div>
</div>

<!-- Recent Applications -->
<div class="bg-white rounded-lg shadow-sm p-6 mb-6">
    <div class="flex justify-between items-center mb-4 pb-2 border-b border-gray-200">
        <h3 class="text-xl font-semibold text-gray-800">Recent Applications</h3>
        <a href="candidates.php" class="text-blue-600 hover:text-blue-700 text-sm font-medium">View All</a>
    </div>
    
    ' . (empty($recentApplications) ? '
    <p class="text-gray-600">No applications have been submitted to your job listings yet.</p>
    ' : '
    <div class="space-y-4">
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
            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 bg-gray-300 rounded-full flex items-center justify-center text-gray-600">
                            ' . strtoupper(substr($application['first_name'], 0, 1) . substr($application['last_name'], 0, 1)) . '
                        </div>
                    </div>
                    <div class="ml-4">
                        <h4 class="text-sm font-medium text-gray-900">' . htmlspecialchars($application['first_name'] . ' ' . $application['last_name']) . '</h4>
                        <p class="text-xs text-gray-500">Applied for <span class="font-medium">' . htmlspecialchars($application['job_title']) . '</span> on ' . $appliedDate . '</p>
                    </div>
                </div>
                <div class="flex items-center">
                    <span class="px-2 py-1 text-xs font-medium rounded-full ' . $statusColor . ' mr-3">' . $statusLabel . '</span>
                    <a href="view-applications.php?job=' . $application['job_id'] . '" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
            ';
        }, $recentApplications)) . '
    </div>
    ') . '
</div>
';

// Include layout
include 'nav/layout.php';
?> 