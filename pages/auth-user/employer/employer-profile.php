<?php
session_start();
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../../../index.php");
    exit();
}

$currentUser = getCurrentUser();
$employerId = $currentUser['id'];

// Check if user is an employer
if ($currentUser['role'] !== 'employer') {
    header("Location: dashboard.php?error=unauthorized");
    exit();
}

// Get database connection
$conn = getDbConnection();

// Get employer data
$stmt = $conn->prepare("SELECT e.*, u.first_name, u.last_name, u.email, u.phone, u.status 
                        FROM employers e 
                        JOIN users u ON e.user_id = u.id 
                        WHERE e.user_id = ? AND u.status = 'active'");
$stmt->bind_param("i", $employerId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Employer not found or not active
    $conn->close();
    header("Location: ../../../index.php?error=profile_not_found");
    exit();
}

$employer = $result->fetch_assoc();
$stmt->close();
echo $employerId;
// Get employer's recent job postings (limit to 5)
$jobsStmt = $conn->prepare("SELECT *
                           FROM jobs 
                           WHERE employer_id = ? AND status = 'active' 
                           ORDER BY created_at DESC 
                           LIMIT 5");
$jobsStmt->bind_param("i", $employerId);
$jobsStmt->execute();
$jobsResult = $jobsStmt->get_result();
$recentJobs = [];
while ($job = $jobsResult->fetch_assoc()) {
    $recentJobs[] = $job;
}
echo json_encode($recentJobs);
$jobsStmt->close();

// Close database connection
$conn->close();

// Set page title
$pageTitle = "Employer Profile: " . htmlspecialchars($employer['first_name'] . ' ' . $employer['last_name']);

// Add extra head content for styling
$extraHeadContent = '
<style>
    .company-logo {
        width: 120px;
        height: 120px;
        object-fit: cover;
        border-radius: 50%;
        border: 3px solid #fff;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    .field-tag {
        display: inline-block;
        background-color: #f0fdf4;
        color: #166534;
        font-size: 0.75rem;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        margin-right: 0.5rem;
        margin-bottom: 0.5rem;
    }
    .job-card {
        transition: all 0.3s ease;
    }
    .job-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    }
</style>
';

// Set page content
$content = '
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold text-gray-800">Employer Profile</h1>
    <a href="javascript:history.back()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 transition duration-300 flex items-center">
        <i class="fas fa-arrow-left mr-2"></i> Back
    </a>
</div>

<div class="bg-white rounded-lg shadow-sm overflow-hidden">
    <!-- Profile Header -->
    <div class="bg-gradient-to-r from-green-500 to-green-600 p-6 text-white">
        <div class="flex flex-col md:flex-row items-center">
            <div class="mb-4 md:mb-0 md:mr-6">
                ' . (!empty($employer['logo']) ? 
                    '<img src="' . htmlspecialchars($employer['logo']) . '" alt="Company Logo" class="company-logo">' : 
                    '<div class="company-logo bg-green-400 flex items-center justify-center">
                        <i class="fas fa-building text-4xl text-white"></i>
                    </div>') . '
            </div>
            <div>
                <h2 class="text-2xl font-bold">' . htmlspecialchars($employer['company_name']) . '</h2>
                <p class="text-green-100 mt-1">' . (!empty($employer['industry']) ? htmlspecialchars($employer['industry']) : 'Company') . '</p>
                <div class="mt-2 flex items-center text-sm">
                    <i class="fas fa-map-marker-alt mr-1"></i>
                    <span>' . (!empty($employer['location']) ? htmlspecialchars($employer['location']) : 'Location not specified') . '</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Profile Content -->
    <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Left Column -->
            <div class="md:col-span-2">
                <!-- About Section -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">About the Company</h3>
                    <div class="text-gray-600">
                        ' . (!empty($employer['company_description']) ? nl2br(htmlspecialchars($employer['company_description'])) : 'No company description provided.') . '
                    </div>
                </div>
                
                <!-- Business Fields -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Business Fields</h3>
                    <div>';
                    
if (!empty($employer['fields'])) {
    $fields = explode(',', $employer['fields']);
    foreach ($fields as $field) {
        $content .= '<span class="field-tag">' . htmlspecialchars(trim($field)) . '</span>';
    }
} else {
    $content .= '<p class="text-gray-500">No business fields listed.</p>';
}

$content .= '
                    </div>
                </div>
                
                <!-- Recent Job Postings -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Recent Job Postings</h3>';
                    
if (count($recentJobs) > 0) {
    $content .= '<div class="space-y-4">';
    
    foreach ($recentJobs as $job) {
        $content .= '
        <a href="../auth-user/seeker/view-job.php?id=' . $job['id'] . '" class="block">
            <div class="job-card border border-gray-200 rounded-lg p-4 hover:bg-gray-50">
                <h4 class="font-medium text-blue-600">' . htmlspecialchars($job['title']) . '</h4>
                <div class="mt-2 flex flex-wrap text-sm text-gray-500">
                    <div class="mr-4 flex items-center">
                        <i class="fas fa-map-marker-alt mr-1 text-gray-400"></i>
                        <span>' . htmlspecialchars($job['location']) . '</span>
                    </div>
                    <div class="mr-4 flex items-center">
                        <i class="fas fa-briefcase mr-1 text-gray-400"></i>
                        <span>' . htmlspecialchars($job['job_type']) . '</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-money-bill-wave mr-1 text-gray-400"></i>
                        <span>₱' . number_format($job['salary_min'], 2) . ' (' . 
                            ($job['pay_type'] === 'hourly' ? 'Hourly' : 
                             ($job['pay_type'] === 'monthly' ? 'Monthly' : 'Annual')) . ')</span>
                    </div>
                </div>
                <div class="mt-2 text-xs text-gray-400">
                    Posted ' . time_elapsed_string2($job['created_at']) . '
                </div>
            </div>
        </a>';
    }
    
    $content .= '</div>';
    
    if (count($recentJobs) == 5) {
        $content .= '
        <div class="mt-4 text-center">
            <a href="../auth-user/seeker/jobs.php?employer=' . $employerId . '" class="text-blue-600 hover:text-blue-800 font-medium">
                View all job postings <i class="fas fa-arrow-right ml-1"></i>
            </a>
        </div>';
    }
} else {
    $content .= '<p class="text-gray-500">No active job postings available.</p>';
}

$content .= '
                </div>
            </div>
            
            <!-- Right Column -->
            <div>
                <!-- Contact Information -->
                <div class="bg-gray-50 p-4 rounded-lg mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Contact Information</h3>
                    <ul class="space-y-2">
                        <li class="flex items-center text-gray-600">
                            <i class="fas fa-envelope w-5 text-gray-400 mr-2"></i>
                            <span>' . htmlspecialchars($employer['email']) . '</span>
                        </li>';
                        
if (!empty($employer['phone'])) {
    $content .= '
                        <li class="flex items-center text-gray-600">
                            <i class="fas fa-phone w-5 text-gray-400 mr-2"></i>
                            <span>' . htmlspecialchars($employer['phone']) . '</span>
                        </li>';
}

if (!empty($employer['website'])) {
    $content .= '
                        <li class="flex items-center text-gray-600">
                            <i class="fas fa-globe w-5 text-gray-400 mr-2"></i>
                            <a href="' . htmlspecialchars($employer['website']) . '" target="_blank" class="text-blue-600 hover:underline">
                                ' . htmlspecialchars($employer['website']) . '
                            </a>
                        </li>';
}

$content .= '
                    </ul>
                </div>
                
                <!-- Company Details -->
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Company Details</h3>
                    <ul class="space-y-3">';
                    
if (!empty($employer['company_size'])) {
    $content .= '
                        <li>
                            <div class="font-medium text-gray-700">Company Size</div>
                            <div class="text-sm text-gray-600">' . htmlspecialchars($employer['company_size']) . '</div>
                        </li>';
}

if (!empty($employer['established'])) {
    $content .= '
                        <li>
                            <div class="font-medium text-gray-700">Established</div>
                            <div class="text-sm text-gray-600">' . htmlspecialchars($employer['established']) . '</div>
                        </li>';
}

if (!empty($employer['address'])) {
    $content .= '
                        <li>
                            <div class="font-medium text-gray-700">Address</div>
                            <div class="text-sm text-gray-600">' . nl2br(htmlspecialchars($employer['address'])) . '</div>
                        </li>';
}

$content .= '
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
';
/**
 * Helper function to format time elapsed
 */
function time_elapsed_string2($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    // Calculate weeks from days
    $weeks = floor($diff->d / 7);
    $days = $diff->d % 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    
    // Map the values to the array
    $values = [
        'y' => $diff->y,
        'm' => $diff->m,
        'w' => $weeks,
        'd' => $days,
        'h' => $diff->h,
        'i' => $diff->i,
        's' => $diff->s,
    ];
    
    foreach ($string as $k => &$v) {
        if ($values[$k]) {
            $v = $values[$k] . ' ' . $v . ($values[$k] > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
include 'nav/layout.php';
?> 