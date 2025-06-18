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

// Get employer data
$conn = getDbConnection();
$stmt = $conn->prepare("SELECT * FROM employers WHERE user_id = ?");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$result = $stmt->get_result();
$employer = $result->fetch_assoc();
$stmt->close();

// Process job actions
$message = '';
$messageType = '';

// Delete job
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $job_id = $_GET['delete'];
    
    // Check if job belongs to this employer
    $stmt = $conn->prepare("SELECT id FROM jobs WHERE id = ? AND employer_id = ?");
    $stmt->bind_param("ii", $job_id, $employer['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt = $conn->prepare("DELETE FROM jobs WHERE id = ?");
        $stmt->bind_param("i", $job_id);
        
        if ($stmt->execute()) {
            $message = "Job deleted successfully!";
            $messageType = "success";
        } else {
            $message = "Error deleting job: " . $conn->error;
            $messageType = "error";
        }
    } else {
        $message = "Unauthorized action or job not found.";
        $messageType = "error";
    }
    $stmt->close();
}

// Change job status
if (isset($_GET['status']) && is_numeric($_GET['job']) && in_array($_GET['status'], ['active', 'paused', 'closed'])) {
    $job_id = $_GET['job'];
    $status = $_GET['status'];
    
    // Check if job belongs to this employer
    $stmt = $conn->prepare("SELECT id FROM jobs WHERE id = ? AND employer_id = ?");
    $stmt->bind_param("ii", $job_id, $employer['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE jobs SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $job_id);
        
        if ($stmt->execute()) {
            $message = "Job status updated successfully!";
            $messageType = "success";
        } else {
            $message = "Error updating job status: " . $conn->error;
            $messageType = "error";
        }
    } else {
        $message = "Unauthorized action or job not found.";
        $messageType = "error";
    }
    $stmt->close();
}

// Get all jobs for this employer
$jobs = [];
$stmt = $conn->prepare("
    SELECT j.*, 
           COUNT(a.id) as application_count 
    FROM jobs j
    LEFT JOIN applications a ON j.id = a.job_id
    WHERE j.employer_id = ?
    GROUP BY j.id
    ORDER BY j.created_at DESC
");
$stmt->bind_param("i", $employer['id']);
$stmt->execute();
$result = $stmt->get_result();

while ($job = $result->fetch_assoc()) {
    $jobs[] = $job;
}
$stmt->close();

$conn->close();

// Set page title
$pageTitle = "Manage Jobs";

// Add extra head content
$extraHeadContent = '
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    .job-card { transition: all 0.2s ease; }
    .job-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }
</style>
';

// Set page content
$content = '
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold text-gray-800">Manage Jobs</h1>
    <a href="post-job.php" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-300 flex items-center">
        <i class="fas fa-plus-circle mr-2"></i> Post New Job
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

<div class="bg-white rounded-lg shadow-sm p-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">Job Statistics</h2>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-blue-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-blue-100 rounded-full p-3">
                    <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-medium text-gray-900">Active Jobs</h3>
                    <p class="text-3xl font-bold text-blue-600">' . count(array_filter($jobs, function($job) { return $job['status'] === 'active'; })) . '</p>
                </div>
            </div>
        </div>
        
        <div class="bg-yellow-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-yellow-100 rounded-full p-3">
                    <svg class="h-6 w-6 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-medium text-gray-900">Paused Jobs</h3>
                    <p class="text-3xl font-bold text-yellow-600">' . count(array_filter($jobs, function($job) { return $job['status'] === 'paused'; })) . '</p>
                </div>
            </div>
        </div>
        
        <div class="bg-green-50 rounded-lg p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-green-100 rounded-full p-3">
                    <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-medium text-gray-900">Total Applications</h3>
                    <p class="text-3xl font-bold text-green-600">' . array_sum(array_column($jobs, 'application_count')) . '</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="bg-white rounded-lg shadow-sm p-6 mt-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">Job Listings</h2>
    
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job Title</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pay</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Posted</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Applications</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                ' . (empty($jobs) ? '
                <tr>
                    <td colspan="8" class="px-6 py-4 text-center text-gray-500">
                        <p class="mb-2">You haven\'t posted any jobs yet.</p>
                        <a href="post-job.php" class="text-blue-600 hover:underline">Post your first job</a>
                    </td>
                </tr>
                ' : '') . '
                
                ' . implode('', array_map(function($job) {
                    $statusColor = [
                        'active' => 'bg-green-100 text-green-800',
                        'paused' => 'bg-yellow-100 text-yellow-800',
                        'closed' => 'bg-gray-100 text-gray-800'
                    ][$job['status']];
                    
                    $statusLabel = [
                        'active' => 'Active',
                        'paused' => 'Paused',
                        'closed' => 'Closed'
                    ][$job['status']];
                    
                    $postedDate = date('M j, Y', strtotime($job['created_at']));
                    $applicationCount = $job['application_count'];
                    
                    $payType = isset($job['pay_type']) ? [
                        'hourly' => 'Hourly',
                        'monthly' => 'Monthly',
                        'annual' => 'Annual'
                    ][$job['pay_type']] : 'Monthly';
                    
                    $salary = isset($job['salary_min']) ? number_format($job['salary_min'], 2) : '0.00';
                    
                    return '
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">' . htmlspecialchars($job['title']) . '</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-500">' . htmlspecialchars($job['location']) . '</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-500">' . htmlspecialchars($job['job_type']) . '</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-500">₱' . $salary . ' (' . $payType . ')</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ' . $statusColor . '">
                                ' . $statusLabel . '
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            ' . $postedDate . '
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <a href="view-applications.php?job=' . $job['id'] . '" class="text-blue-600 hover:underline">
                                ' . $applicationCount . ' ' . ($applicationCount == 1 ? 'application' : 'applications') . '
                            </a>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <div class="flex justify-end space-x-2">
                                <a href="view-job.php?id=' . $job['id'] . '" class="text-blue-600 hover:text-blue-900" title="View Job">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="edit-job.php?id=' . $job['id'] . '" class="text-green-600 hover:text-green-900" title="Edit Job">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="#" onclick="confirmDelete(' . $job['id'] . ')" class="text-red-600 hover:text-red-900" title="Delete Job">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                                <div class="dropdown relative">
                                    <button class="text-gray-600 hover:text-gray-900 dropdown-toggle" title="Change Status">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <div class="dropdown-menu hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-10">
                                        <a href="manage-jobs.php?job=' . $job['id'] . '&status=active" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Mark as Active</a>
                                        <a href="manage-jobs.php?job=' . $job['id'] . '&status=paused" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Pause Job</a>
                                        <a href="manage-jobs.php?job=' . $job['id'] . '&status=closed" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Close Job</a>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    ';
                }, $jobs)) . '
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Dropdown toggle
    const dropdownToggles = document.querySelectorAll(".dropdown-toggle");
    
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener("click", function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const menu = this.nextElementSibling;
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
    
    // Confirm delete
    window.confirmDelete = function(jobId) {
        Swal.fire({
            title: "Are you sure?",
            text: "Do you want to delete this job posting? This action cannot be undone.",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "Yes, delete it!"
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = "manage-jobs.php?delete=" + jobId;
            }
        });
    };
});
</script>
';

// Include layout
include 'nav/layout.php';
?> 