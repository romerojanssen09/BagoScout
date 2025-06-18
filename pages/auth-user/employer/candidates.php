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
        // Check if application belongs to one of this employer's jobs
        $stmt = $conn->prepare("
            SELECT a.id 
            FROM applications a
            JOIN jobs j ON a.job_id = j.id
            WHERE a.id = ? AND j.employer_id = ?
        ");
        $stmt->bind_param("ii", $application_id, $employer['id']);
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
    
    // Check if application belongs to one of this employer's jobs
    $stmt = $conn->prepare("
        SELECT a.id 
        FROM applications a
        JOIN jobs j ON a.job_id = j.id
        WHERE a.id = ? AND j.employer_id = ?
    ");
    $stmt->bind_param("ii", $application_id, $employer['id']);
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

// Filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$job_filter = isset($_GET['job']) ? (int)$_GET['job'] : 0;

// Get all jobs for this employer (for filter dropdown)
$jobs = [];
$stmt = $conn->prepare("SELECT id, title FROM jobs WHERE employer_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $employer['id']);
$stmt->execute();
$result = $stmt->get_result();

while ($job = $result->fetch_assoc()) {
    $jobs[] = $job;
}
$stmt->close();

// Build the query with filters
$query = "
    SELECT a.*, 
           u.first_name, u.last_name, u.email, u.phone, u.id as user_id,
           j.title as job_title, j.id as job_id,
           js.skills
    FROM applications a
    JOIN jobseekers js ON a.jobseeker_id = js.id
    JOIN users u ON js.user_id = u.id
    JOIN jobs j ON a.job_id = j.id
    WHERE j.employer_id = ?
";

$params = [$employer['id']];
$types = "i";

if ($status_filter) {
    $query .= " AND a.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($job_filter) {
    $query .= " AND j.id = ?";
    $params[] = $job_filter;
    $types .= "i";
}

$query .= " ORDER BY a.created_at DESC";

// Get all applications for this employer's jobs with filters
$applications = [];
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($application = $result->fetch_assoc()) {
    $applications[] = $application;
}
$stmt->close();

// Get application statistics
$stats = [
    'total' => 0,
    'pending' => 0,
    'reviewed' => 0,
    'shortlisted' => 0,
    'rejected' => 0,
    'hired' => 0
];

// Get statistics
$stmt = $conn->prepare("
    SELECT a.status, COUNT(*) as count
    FROM applications a
    JOIN jobs j ON a.job_id = j.id
    WHERE j.employer_id = ?
    GROUP BY a.status
");
$stmt->bind_param("i", $employer['id']);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $stats[$row['status']] = $row['count'];
    $stats['total'] += $row['count'];
}
$stmt->close();

// Handle candidate comparison
$compareIds = [];
if (isset($_GET['compare']) && is_array($_GET['compare'])) {
    $compareIds = array_map('intval', $_GET['compare']);
    
    // Limit to max 3 candidates
    if (count($compareIds) > 3) {
        $compareIds = array_slice($compareIds, 0, 3);
    }
    
    // Get comparison data
    $compareData = [];
    $placeholders = implode(',', array_fill(0, count($compareIds), '?'));
    
    $stmt = $conn->prepare("
        SELECT a.*, 
               u.first_name, u.last_name, u.email, u.phone, u.profile, u.id as user_id,
               j.title as job_title, j.fields as job_fields,
               js.skills, js.education, js.experience, js.fields as seeker_fields
        FROM applications a
        JOIN jobseekers js ON a.jobseeker_id = js.id
        JOIN users u ON js.user_id = u.id
        JOIN jobs j ON a.job_id = j.id
        WHERE a.id IN ($placeholders) AND j.employer_id = ?
    ");
    
    $bindTypes = str_repeat('i', count($compareIds)) . 'i';
    $bindParams = array_merge($compareIds, [$employer['id']]);
    $stmt->bind_param($bindTypes, ...$bindParams);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($candidate = $result->fetch_assoc()) {
        // Calculate match score
        $jobFields = explode(', ', $candidate['job_fields']);
        $seekerFields = explode(', ', $candidate['seeker_fields']);
        $seekerSkills = explode(', ', $candidate['skills']);
        
        $candidate['match_score'] = calculateJobMatchScore($jobFields, $seekerFields, $seekerSkills);
        $compareData[] = $candidate;
    }
    $stmt->close();
}

$conn->close();

// Set page title
$pageTitle = "Candidates";

// Add extra head content
$extraHeadContent = '
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    .application-card { transition: all 0.2s ease; }
    .application-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }
    
    .compare-checkbox:checked + label {
        background-color: #3B82F6;
        color: white;
    }
    
    .compare-checkbox:checked + label svg {
        display: block;
    }
    
    .compare-checkbox:not(:checked) + label svg {
        display: none;
    }
    
    .comparison-table th, .comparison-table td {
        padding: 0.75rem;
        text-align: left;
    }
    
    .comparison-table tr:nth-child(even) {
        background-color: #f9fafb;
    }
</style>
';

// Set page content
$content = '
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">Candidates</h1>
        <p class="text-gray-600">Manage job applications from candidates</p>
    </div>
    <div class="flex space-x-2">
        <button id="compare-btn" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition duration-300 flex items-center disabled:bg-blue-300 disabled:cursor-not-allowed" disabled>
            <i class="fas fa-balance-scale mr-2"></i> Compare Selected
        </button>
    </div>
</div>

<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <div class="flex flex-wrap gap-4 mb-6">
        <div class="bg-blue-50 rounded-lg p-4 flex-1 min-w-[200px]">
            <div class="text-blue-800 text-lg font-semibold">' . $stats['total'] . '</div>
            <div class="text-blue-600 text-sm">Total Applications</div>
        </div>
        <div class="bg-yellow-50 rounded-lg p-4 flex-1 min-w-[200px]">
            <div class="text-yellow-800 text-lg font-semibold">' . $stats['pending'] . '</div>
            <div class="text-yellow-600 text-sm">Pending</div>
        </div>
        <div class="bg-green-50 rounded-lg p-4 flex-1 min-w-[200px]">
            <div class="text-green-800 text-lg font-semibold">' . $stats['reviewed'] . '</div>
            <div class="text-green-600 text-sm">Reviewed</div>
        </div>
        <div class="bg-purple-50 rounded-lg p-4 flex-1 min-w-[200px]">
            <div class="text-purple-800 text-lg font-semibold">' . $stats['shortlisted'] . '</div>
            <div class="text-purple-600 text-sm">Shortlisted</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="mb-6">
        <form action="" method="get" class="flex flex-wrap gap-4 items-end">
            <div>
                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" id="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Statuses</option>
                    <option value="pending" ' . ($status_filter === 'pending' ? 'selected' : '') . '>Pending</option>
                    <option value="reviewed" ' . ($status_filter === 'reviewed' ? 'selected' : '') . '>Reviewed</option>
                    <option value="shortlisted" ' . ($status_filter === 'shortlisted' ? 'selected' : '') . '>Shortlisted</option>
                    <option value="rejected" ' . ($status_filter === 'rejected' ? 'selected' : '') . '>Rejected</option>
                    <option value="hired" ' . ($status_filter === 'hired' ? 'selected' : '') . '>Hired</option>
                </select>
            </div>
            <div>
                <label for="job" class="block text-sm font-medium text-gray-700 mb-1">Job</label>
                <select name="job" id="job" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Jobs</option>';

foreach ($jobs as $job) {
    $content .= '<option value="' . $job['id'] . '" ' . ($job_filter === $job['id'] ? 'selected' : '') . '>' . htmlspecialchars($job['title']) . '</option>';
}

$content .= '
                </select>
            </div>
            <div>
                <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition duration-300">
                    <i class="fas fa-filter mr-2"></i> Filter
                </button>
                <a href="candidates.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 transition duration-300 ml-2">
                    <i class="fas fa-times mr-2"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Applications List -->
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr>
                    <th class="px-4 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-8">
                        Compare
                    </th>
                    <th class="px-4 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Candidate
                    </th>
                    <th class="px-4 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Job
                    </th>
                    <th class="px-4 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Skills
                    </th>
                    <th class="px-4 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Applied
                    </th>
                    <th class="px-4 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Status
                    </th>
                    <th class="px-4 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">';

if (count($applications) > 0) {
    foreach ($applications as $application) {
        $statusClass = '';
        $statusText = ucfirst($application['status']);
        
        switch ($application['status']) {
            case 'pending':
                $statusClass = 'bg-yellow-100 text-yellow-800';
                break;
            case 'reviewed':
                $statusClass = 'bg-blue-100 text-blue-800';
                break;
            case 'shortlisted':
                $statusClass = 'bg-green-100 text-green-800';
                break;
            case 'rejected':
                $statusClass = 'bg-red-100 text-red-800';
                break;
            case 'hired':
                $statusClass = 'bg-purple-100 text-purple-800';
                break;
        }
        
        $content .= '
        <tr>
            <td class="px-4 py-4 whitespace-nowrap">
                <input type="checkbox" id="compare-' . $application['id'] . '" class="hidden compare-checkbox" data-id="' . $application['id'] . '">
                <label for="compare-' . $application['id'] . '" class="w-6 h-6 border border-gray-300 rounded flex items-center justify-center cursor-pointer hover:bg-gray-100">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-white" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                </label>
            </td>
            <td class="px-4 py-4 whitespace-nowrap">
                <a href="../../auth-user/seeker-profile.php?id=' . $application['jobseeker_id'] . '" class="flex items-center hover:bg-gray-50 rounded-md p-1">
                    <div class="flex-shrink-0 h-10 w-10">
                        <div class="h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-600 uppercase font-semibold">
                            ' . substr($application['first_name'], 0, 1) . '
                        </div>
                    </div>
                    <div class="ml-4">
                        <div class="text-sm font-medium text-gray-900 hover:text-blue-600">' . htmlspecialchars($application['first_name'] . ' ' . $application['last_name']) . '</div>
                        <div class="text-sm text-gray-500">' . htmlspecialchars($application['email']) . '</div>
                    </div>
                </a>
            </td>
            <td class="px-4 py-4">
                <div class="text-sm text-gray-900">' . htmlspecialchars($application['job_title']) . '</div>
            </td>
            <td class="px-4 py-4">
                <div class="text-sm text-gray-900 max-w-xs truncate">' . htmlspecialchars($application['skills']) . '</div>
            </td>
            <td class="px-4 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900">' . date('M j, Y', strtotime($application['created_at'])) . '</div>
                <div class="text-sm text-gray-500">' . time_elapsed_string($application['created_at']) . '</div>
            </td>
            <td class="px-4 py-4 whitespace-nowrap">
                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ' . $statusClass . '">
                    ' . $statusText . '
                </span>
            </td>
            <td class="px-4 py-4 whitespace-nowrap text-sm font-medium">
                <div class="flex space-x-2">
                    <a href="view-applications.php?job=' . $application['job_id'] . '&application=' . $application['id'] . '" class="text-blue-600 hover:text-blue-900">
                        <i class="fas fa-eye"></i>
                    </a>';
                    
        if ($application['status'] === 'pending') {
            $content .= '
                    <a href="?action=review&application=' . $application['id'] . '&status=' . $status_filter . '&job=' . $job_filter . '" class="text-green-600 hover:text-green-900" title="Mark as Reviewed">
                        <i class="fas fa-check"></i>
                    </a>';
        }
                    
        $content .= '
                    <a href="message.php?receiver=' . $application['user_id'] . '" class="text-purple-600 hover:text-purple-900" title="Message">
                        <i class="fas fa-comment"></i>
                    </a>
                </div>
            </td>
        </tr>';
    }
} else {
    $content .= '
        <tr>
            <td colspan="7" class="px-4 py-4 text-center text-gray-500">
                No applications found.
            </td>
        </tr>';
}

$content .= '
            </tbody>
        </table>
    </div>
</div>';

// Add comparison modal if there are candidates to compare
if (!empty($compareIds)) {
    $content .= '
    <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" id="comparison-modal">
        <div class="bg-white rounded-lg shadow-xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-800">Candidate Comparison</h3>
                <a href="candidates.php' . ($status_filter ? '?status=' . $status_filter : '') . ($job_filter ? ($status_filter ? '&' : '?') . 'job=' . $job_filter : '') . '" class="text-gray-400 hover:text-gray-600">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </a>
            </div>
            <div class="p-4">
                <div class="overflow-x-auto">
                    <table class="min-w-full comparison-table">
                        <thead>
                            <tr>
                                <th class="border-b-2 border-gray-200 bg-gray-50">Criteria</th>';
                                
    foreach ($compareData as $candidate) {
        $content .= '<th class="border-b-2 border-gray-200 bg-gray-50">' . htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']) . '</th>';
    }
                                
    $content .= '
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="font-semibold">Photo</td>';
                                
    foreach ($compareData as $candidate) {
        $content .= '
                                <td>
                                    <div class="h-16 w-16 rounded-full bg-gray-200 flex items-center justify-center text-gray-600 uppercase font-semibold">
                                        ' . substr($candidate['first_name'], 0, 1) . '
                                    </div>
                                </td>';
    }
                                
    $content .= '
                            </tr>
                            <tr>
                                <td class="font-semibold">Contact</td>';
                                
    foreach ($compareData as $candidate) {
        $content .= '
                                <td>
                                    <div>' . htmlspecialchars($candidate['email']) . '</div>
                                    <div>' . htmlspecialchars($candidate['phone']) . '</div>
                                </td>';
    }
                                
    $content .= '
                            </tr>
                            <tr>
                                <td class="font-semibold">Skills</td>';
                                
    foreach ($compareData as $candidate) {
        $content .= '
                                <td>
                                    <div class="flex flex-wrap gap-1">';
                                    
        $skills = explode(', ', $candidate['skills']);
        foreach ($skills as $skill) {
            $content .= '<span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full">' . htmlspecialchars($skill) . '</span>';
        }
                                    
        $content .= '
                                    </div>
                                </td>';
    }
                                
    $content .= '
                            </tr>
                            <tr>
                                <td class="font-semibold">Education</td>';
                                
    foreach ($compareData as $candidate) {
        $content .= '<td>' . nl2br(htmlspecialchars($candidate['education'])) . '</td>';
    }
                                
    $content .= '
                            </tr>
                            <tr>
                                <td class="font-semibold">Experience</td>';
                                
    foreach ($compareData as $candidate) {
        $content .= '<td>' . nl2br(htmlspecialchars($candidate['experience'])) . '</td>';
    }
                                
    $content .= '
                            </tr>
                            <tr>
                                <td class="font-semibold">Job Match Score</td>';
                                
    foreach ($compareData as $candidate) {
        $matchPercentage = round($candidate['match_score'] * 100);
        $matchClass = $matchPercentage > 70 ? 'text-green-600' : ($matchPercentage > 40 ? 'text-yellow-600' : 'text-red-600');
        $content .= '<td class="' . $matchClass . ' font-semibold">' . $matchPercentage . '%</td>';
    }
                                
    $content .= '
                            </tr>
                            <tr>
                                <td class="font-semibold">Status</td>';
                                
    foreach ($compareData as $candidate) {
        $statusClass = '';
        $statusText = ucfirst($candidate['status']);
        
        switch ($candidate['status']) {
            case 'pending':
                $statusClass = 'bg-yellow-100 text-yellow-800';
                break;
            case 'reviewed':
                $statusClass = 'bg-blue-100 text-blue-800';
                break;
            case 'shortlisted':
                $statusClass = 'bg-green-100 text-green-800';
                break;
            case 'rejected':
                $statusClass = 'bg-red-100 text-red-800';
                break;
            case 'hired':
                $statusClass = 'bg-purple-100 text-purple-800';
                break;
        }
        
        $content .= '
                                <td>
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ' . $statusClass . '">
                                        ' . $statusText . '
                                    </span>
                                </td>';
    }
                                
    $content .= '
                            </tr>
                            <tr>
                                <td class="font-semibold">Actions</td>';
                                
    foreach ($compareData as $candidate) {
        $content .= '
                                <td>
                                    <div class="flex flex-col space-y-2">
                                        <a href="view-applications.php?job=' . $candidate['job_id'] . '&application=' . $candidate['id'] . '" class="px-3 py-1 bg-blue-500 text-white text-center rounded-md hover:bg-blue-600 text-sm">
                                            View Details
                                        </a>
                                        <a href="message.php?user=' . $candidate['user_id'] . '" class="px-3 py-1 bg-purple-500 text-white text-center rounded-md hover:bg-purple-600 text-sm">
                                            Message
                                        </a>';
                                        
        if ($candidate['status'] === 'pending') {
            $content .= '
                                        <a href="?action=review&application=' . $candidate['id'] . '&status=' . $status_filter . '&job=' . $job_filter . '" class="px-3 py-1 bg-green-500 text-white text-center rounded-md hover:bg-green-600 text-sm">
                                            Mark as Reviewed
                                        </a>';
        }
                                        
        $content .= '
                                    </div>
                                </td>';
    }
                                
    $content .= '
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="p-4 border-t border-gray-200 flex justify-end">
                <a href="candidates.php' . ($status_filter ? '?status=' . $status_filter : '') . ($job_filter ? ($status_filter ? '&' : '?') . 'job=' . $job_filter : '') . '" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none">
                    Close
                </a>
            </div>
        </div>
    </div>';
}

// Add extra scripts
$extraScripts = '
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Show success/error message
    ' . ($message ? '
    Swal.fire({
        title: "' . ($messageType === 'success' ? 'Success!' : 'Error!') . '",
        text: "' . $message . '",
        icon: "' . ($messageType === 'success' ? 'success' : 'error') . '",
        confirmButtonColor: "#3B82F6"
    });
    ' : '') . '
    
    // Handle compare checkboxes
    const compareCheckboxes = document.querySelectorAll(".compare-checkbox");
    const compareBtn = document.getElementById("compare-btn");
    let selectedCount = 0;
    
    compareCheckboxes.forEach(checkbox => {
        checkbox.addEventListener("change", function() {
            if (this.checked) {
                selectedCount++;
            } else {
                selectedCount--;
            }
            
            // Update compare button state
            if (selectedCount >= 2 && selectedCount <= 3) {
                compareBtn.disabled = false;
            } else {
                compareBtn.disabled = true;
            }
        });
    });
    
    // Handle compare button click
    compareBtn.addEventListener("click", function() {
        const selectedIds = [];
        
        compareCheckboxes.forEach(checkbox => {
            if (checkbox.checked) {
                selectedIds.push(checkbox.getAttribute("data-id"));
            }
        });
        
        if (selectedIds.length >= 2 && selectedIds.length <= 3) {
            let url = "candidates.php?compare[]=" + selectedIds.join("&compare[]=");
            
            // Add any existing filters
            if ("' . $status_filter . '") {
                url += "&status=' . $status_filter . '";
            }
            
            if ("' . $job_filter . '") {
                url += "&job=' . $job_filter . '";
            }
            
            window.location.href = url;
        }
    });
    
    // Auto-review functionality
    const viewLinks = document.querySelectorAll("a[href^=\'view-applications.php\']");
    viewLinks.forEach(link => {
        link.addEventListener("click", function(e) {
            const href = this.getAttribute("href");
            const applicationId = href.match(/application=(\d+)/)[1];
            
            // Check if application is pending
            const row = this.closest("tr");
            const statusCell = row.querySelector("td:nth-child(6) span");
            
            if (statusCell && statusCell.textContent.trim().toLowerCase() === "pending") {
                // Send AJAX request to mark as reviewed
                fetch("?action=review&application=" + applicationId + "&ajax=1")
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update status cell
                            statusCell.textContent = "Reviewed";
                            statusCell.className = "px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800";
                            
                            // Remove review action button
                            const actionCell = row.querySelector("td:nth-child(7) div");
                            const reviewLink = actionCell.querySelector("a[href*=\'action=review\']");
                            if (reviewLink) {
                                reviewLink.remove();
                            }
                            
                            // Create notification that will show after page load
                            localStorage.setItem("application_reviewed", "Application automatically marked as reviewed");
                        }
                    })
                    .catch(error => console.error("Error:", error));
            }
        });
    });
    
    // Show notification if exists
    const notification = localStorage.getItem("application_reviewed");
    if (notification) {
        Swal.fire({
            title: "Success!",
            text: notification,
            icon: "success",
            confirmButtonColor: "#3B82F6"
        });
        localStorage.removeItem("application_reviewed");
    }
});
</script>
';

// Include layout
include 'nav/layout.php';
?> 