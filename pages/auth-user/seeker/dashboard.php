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

// Check if user is a jobseeker
if ($user['role'] !== 'jobseeker') {
    header("Location: ../../../index.php");
    exit();
}

// Get jobseeker data
$conn = getDbConnection();
$stmt = $conn->prepare("SELECT id, fields, skills FROM jobseekers WHERE user_id = ?");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: profile.php");
    exit();
}

$seeker = $result->fetch_assoc();
$seekerId = $seeker['id'];
$seekerFields = explode(', ', $seeker['fields']);
$seekerSkills = explode(', ', $seeker['skills']);
$stmt->close();

// Get recent applications
$stmt = $conn->prepare("
    SELECT a.*, j.title, j.location, j.job_type, 
           e.company_name, e.company_type
    FROM applications a
    JOIN jobs j ON a.job_id = j.id
    JOIN employers e ON j.employer_id = e.id
    WHERE a.jobseeker_id = ?
    ORDER BY a.created_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $seekerId);
$stmt->execute();
$result = $stmt->get_result();

$applications = [];
while ($row = $result->fetch_assoc()) {
    $applications[] = $row;
}
$stmt->close();

// Get application statistics
$totalApplications = 0;
$pendingApplications = 0;
$shortlistedApplications = 0;

// Count applications by status
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM applications WHERE jobseeker_id = ?");
$stmt->bind_param("i", $seekerId);
$stmt->execute();
$result = $stmt->get_result();
$totalApplications = $result->fetch_assoc()['count'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as count FROM applications WHERE jobseeker_id = ? AND status = 'pending'");
$stmt->bind_param("i", $seekerId);
$stmt->execute();
$result = $stmt->get_result();
$pendingApplications = $result->fetch_assoc()['count'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as count FROM applications WHERE jobseeker_id = ? AND status = 'shortlisted'");
$stmt->bind_param("i", $seekerId);
$stmt->execute();
$result = $stmt->get_result();
$shortlistedApplications = $result->fetch_assoc()['count'];
$stmt->close();

// Get recommended jobs
$recommendedJobs = getRecommendedJobs($seekerId, 5);

// Get recent job postings
$stmt = $conn->prepare("
    SELECT j.*, e.company_name, e.company_type, u.first_name, u.last_name
    FROM jobs j
    JOIN employers e ON j.employer_id = e.id
    JOIN users u ON e.user_id = u.id
    WHERE j.status = 'active'
    ORDER BY j.created_at DESC
    LIMIT 5
");
$stmt->execute();
$result = $stmt->get_result();

$recentJobs = [];
while ($job = $result->fetch_assoc()) {
    $recentJobs[] = $job;
}
$stmt->close();
$conn->close();

// Set role title
$roleTitle = 'Jobseeker';

// Check if profile is complete
$pendingNotice = '';
if (!isset($seeker['fields']) || !isset($seeker['skills']) || empty($seeker['fields']) || empty($seeker['skills'])) {
    $pendingNotice = '
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <h3 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">Complete Your Profile</h3>
        <p class="text-gray-700 mb-4">To get better job recommendations, please complete your profile with your skills and fields of interest:</p>
        <div class="space-y-4">
            <div class="flex items-center">
                <div class="w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center mr-3">
                    <i class="fas fa-user-graduate text-sm text-gray-500"></i>
                </div>
                <span class="text-gray-700">Add your skills</span>
            </div>
            <div class="flex items-center">
                <div class="w-6 h-6 bg-gray-200 rounded-full flex items-center justify-center mr-3">
                    <i class="fas fa-briefcase text-sm text-gray-500"></i>
                </div>
                <span class="text-gray-700">Select your fields of interest</span>
            </div>
        </div>
        <div class="mt-6">
            <a href="profile.php" class="px-4 py-2 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300">
                Complete Profile
            </a>
        </div>
    </div>';
}

// Set page title
$pageTitle = "Jobseeker Dashboard";

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
                <p class="text-sm font-medium text-gray-600">Total Applications</p>
                <p class="text-2xl font-bold text-gray-900">' . $totalApplications . '</p>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                <i class="fas fa-file-alt text-blue-600 text-xl"></i>
            </div>
        </div>
        <div class="mt-4">
            ' . ($totalApplications > 0 ? 
                '<a href="my-applications.php" class="text-sm text-blue-600 hover:underline">View all applications</a>' : 
                '<span class="text-sm text-gray-500">No applications yet</span>') . '
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm p-6 dashboard-card">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Pending Applications</p>
                <p class="text-2xl font-bold text-gray-900">' . $pendingApplications . '</p>
            </div>
            <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center">
                <i class="fas fa-hourglass-half text-yellow-600 text-xl"></i>
            </div>
        </div>
        <div class="mt-4">
            ' . ($pendingApplications > 0 ? 
                '<a href="my-applications.php?status=pending" class="text-sm text-blue-600 hover:underline">View pending applications</a>' : 
                '<span class="text-sm text-gray-500">No pending applications</span>') . '
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm p-6 dashboard-card">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600">Shortlisted</p>
                <p class="text-2xl font-bold text-gray-900">' . $shortlistedApplications . '</p>
            </div>
            <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                <i class="fas fa-check-circle text-green-600 text-xl"></i>
            </div>
        </div>
        <div class="mt-4">
            ' . ($shortlistedApplications > 0 ? 
                '<a href="my-applications.php?status=shortlisted" class="text-sm text-blue-600 hover:underline">View shortlisted applications</a>' : 
                '<span class="text-sm text-gray-500">No shortlisted applications</span>') . '
        </div>
    </div>
</div>

<!-- Main Content Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Main Content (2/3) -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Recommended Jobs -->
        <div class="bg-white rounded-lg shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Recommended Jobs</h2>
                <a href="jobs.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">View All Jobs</a>
            </div>

            ' . (empty($recommendedJobs) ? '
            <div class="text-center py-6">
                <i class="fas fa-lightbulb text-gray-400 text-4xl mb-3"></i>
                <p class="text-gray-500">No recommended jobs yet. Complete your profile to get personalized recommendations.</p>
            </div>
            ' : '
            <div class="space-y-4">
                ' . implode('', array_map(function($job) {
                    $matchScore = isset($job['match_score']) ? $job['match_score'] : 0;
                    $isHighMatch = $matchScore >= 0.8;
                    return '
                    <div class="border-l-4 ' . ($isHighMatch ? 'border-green-500' : 'border-blue-500') . ' bg-white p-4 rounded-lg shadow-sm hover:shadow-md transition duration-300">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-md font-semibold text-gray-800 mb-1">
                                    <a href="view-job.php?id=' . $job['id'] . '" class="hover:text-blue-600">
                                        ' . htmlspecialchars($job['title']) . '
                                    </a>
                                </h3>
                                <p class="text-sm text-gray-600 mb-1">' . htmlspecialchars($job['company_name']) . '</p>
                                <div class="flex items-center text-xs text-gray-500">
                                    <i class="fas fa-map-marker-alt mr-1"></i>
                                    <span>' . htmlspecialchars($job['location']) . '</span>
                                    <span class="mx-1">•</span>
                                    <span>' . htmlspecialchars($job['job_type']) . '</span>
                                </div>
                            </div>
                            <span class="bg-' . ($isHighMatch ? 'green' : 'blue') . '-100 text-' . ($isHighMatch ? 'green' : 'blue') . '-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                ' . ($isHighMatch ? 'Highly Recommended' : 'Recommended') . '
                            </span>
                        </div>
                    </div>';
                }, array_filter($recommendedJobs, function($job) {
                    return isset($job['match_score']) && $job['match_score'] >= 0.5;
                }))) . '
            </div>
            ') . '
        </div>

        <!-- Recent Job Postings -->
        <div class="bg-white rounded-lg shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Recent Job Postings</h2>
                <a href="jobs.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">View All</a>
            </div>

            ' . (empty($recentJobs) ? '
            <div class="text-center py-6">
                <i class="fas fa-briefcase text-gray-400 text-4xl mb-3"></i>
                <p class="text-gray-500">No recent job postings available.</p>
            </div>
            ' : '
            <div class="space-y-4">
                ' . implode('', array_map(function($job) use ($seekerFields) {
                    $jobFields = explode(', ', $job['fields']);
                    $fieldMatches = array_map(function($field) use ($seekerFields) {
                        $field = trim($field);
                        return [
                            'name' => $field,
                            'match' => in_array($field, $seekerFields)
                        ];
                    }, $jobFields);
                    
                    return '
                    <div class="border border-gray-200 p-4 rounded-lg hover:shadow-md transition duration-300">
                        <h3 class="text-md font-semibold text-gray-800 mb-1">
                            <a href="view-job.php?id=' . $job['id'] . '" class="hover:text-blue-600">
                                ' . htmlspecialchars($job['title']) . '
                            </a>
                        </h3>
                        <p class="text-sm text-gray-600 mb-1">' . htmlspecialchars($job['company_name']) . '</p>
                        <div class="flex items-center text-xs text-gray-500 mb-2">
                            <i class="fas fa-map-marker-alt mr-1"></i>
                            <span>' . htmlspecialchars($job['location']) . '</span>
                            <span class="mx-1">•</span>
                            <span>' . htmlspecialchars($job['job_type']) . '</span>
                        </div>
                        <div class="flex flex-wrap gap-2 mt-2">
                            ' . implode('', array_map(function($field) {
                                return '
                                <span class="bg-' . ($field['match'] ? 'green' : 'blue') . '-100 text-' . ($field['match'] ? 'green' : 'blue') . '-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                    ' . htmlspecialchars($field['name']) . '
                                </span>';
                            }, $fieldMatches)) . '
                        </div>
                    </div>';
                }, $recentJobs)) . '
            </div>
            ') . '
        </div>
    </div>

    <!-- Sidebar (1/3) -->
    <div class="space-y-6">
        <!-- Recent Applications -->
        <div class="bg-white rounded-lg shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Recent Applications</h2>
                <a href="my-applications.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">View All</a>
            </div>

            ' . (empty($applications) ? '
            <div class="text-center py-6">
                <i class="fas fa-file-alt text-gray-400 text-4xl mb-3"></i>
                <p class="text-gray-500">You haven\'t applied to any jobs yet.</p>
                <a href="jobs.php" class="mt-2 inline-block text-blue-600 hover:text-blue-800 text-sm font-medium">
                    Find Jobs to Apply
                </a>
            </div>
            ' : '
            <div class="space-y-3">
                ' . implode('', array_map(function($application) {
                    $statusColors = [
                        'pending' => 'yellow',
                        'reviewed' => 'blue',
                        'shortlisted' => 'green',
                        'rejected' => 'red',
                        'hired' => 'purple',
                        'default' => 'gray'
                    ];
                    $statusColor = isset($statusColors[$application['status']]) ? $statusColors[$application['status']] : $statusColors['default'];
                    
                    return '
                    <div class="border-l-4 border-' . $statusColor . '-500 p-3 rounded-lg bg-white">
                        <h3 class="text-sm font-semibold text-gray-800 mb-1">
                            <a href="view-job.php?id=' . $application['job_id'] . '" class="hover:text-blue-600">
                                ' . htmlspecialchars($application['title']) . '
                            </a>
                        </h3>
                        <p class="text-xs text-gray-600">' . htmlspecialchars($application['company_name']) . '</p>
                        <div class="flex justify-between items-center mt-2">
                            <span class="text-xs text-gray-500">
                                Applied ' . time_elapsed_string($application['created_at']) . '
                            </span>
                            <span class="text-xs font-medium text-' . $statusColor . '-700">
                                ' . ucfirst($application['status']) . '
                            </span>
                        </div>
                    </div>';
                }, $applications)) . '
            </div>
            ') . '
        </div>

        <!-- Skills & Fields -->
        <div class="bg-white rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Your Skills & Fields</h2>

            <div class="mb-4">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Fields</h3>
                <div class="flex flex-wrap gap-2">
                    ' . (empty($seekerFields) || (count($seekerFields) === 1 && empty($seekerFields[0])) ? '
                    <span class="text-sm text-gray-500">No fields added yet</span>
                    ' : implode('', array_map(function($field) {
                        return '
                        <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">
                            ' . htmlspecialchars(trim($field)) . '
                        </span>';
                    }, $seekerFields))) . '
                </div>
            </div>

            <div>
                <h3 class="text-sm font-medium text-gray-700 mb-2">Skills</h3>
                <div class="flex flex-wrap gap-2">
                    ' . (empty($seekerSkills) || (count($seekerSkills) === 1 && empty($seekerSkills[0])) ? '
                    <span class="text-sm text-gray-500">No skills added yet</span>
                    ' : implode('', array_map(function($skill) {
                        return '
                        <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded">
                            ' . htmlspecialchars(trim($skill)) . '
                        </span>';
                    }, $seekerSkills))) . '
                </div>
            </div>

            <div class="mt-4 pt-4 border-t border-gray-200">
                <a href="profile.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                    <i class="fas fa-edit mr-1"></i> Update Profile
                </a>
            </div>
        </div>
    </div>
</div>
';

// Include layout
include_once 'nav/layout.php';
?>