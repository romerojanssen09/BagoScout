<?php
session_start();
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../../../index.php");
    exit();
}

// Get seeker ID from URL or use current user's ID
$isOwnProfile = false;
if (isset($_GET['id'])) {
    $seekerId = intval($_GET['id']);
} else {
    // If no ID provided, show current user's profile
    $user = getCurrentUser();
    if ($user['role'] !== 'jobseeker') {
        header("Location: ../../../index.php");
        exit();
    }
    $isOwnProfile = true;
    
    // Get current user's jobseeker ID
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id FROM jobseekers WHERE user_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $seekerData = $result->fetch_assoc();
    $seekerId = $seekerData['id'];
    $stmt->close();
}

// Get database connection
$conn = getDbConnection();

// Get seeker data
$stmt = $conn->prepare("SELECT j.*, u.first_name, u.last_name, u.email, u.phone, u.status 
                        FROM jobseekers j 
                        JOIN users u ON j.user_id = u.id 
                        WHERE j.id = ? AND u.status = 'active'");
$stmt->bind_param("i", $seekerId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Seeker not found or not active
    $conn->close();
    header("Location: ../../../index.php?error=profile_not_found");
    exit();
}

$seeker = $result->fetch_assoc();
$stmt->close();

// Get seeker's default resume
$resumeStmt = $conn->prepare("SELECT * FROM jobseeker_resumes WHERE jobseeker_id = ? AND is_default = 1");
$resumeStmt->bind_param("i", $seekerId);
$resumeStmt->execute();
$resumeResult = $resumeStmt->get_result();
$defaultResume = $resumeResult->num_rows > 0 ? $resumeResult->fetch_assoc() : null;
$resumeStmt->close();

// Get seeker's default clearance
$clearanceStmt = $conn->prepare("SELECT * FROM jobseeker_clearances WHERE jobseeker_id = ? AND is_default = 1");
$clearanceStmt->bind_param("i", $seekerId);
$clearanceStmt->execute();
$clearanceResult = $clearanceStmt->get_result();
$defaultClearance = $clearanceResult->num_rows > 0 ? $clearanceResult->fetch_assoc() : null;
$clearanceStmt->close();

// Close database connection
$conn->close();

// Set page title
$pageTitle = "Job Seeker Profile: " . htmlspecialchars($seeker['first_name'] . ' ' . $seeker['last_name']);

// Add extra head content for styling
$extraHeadContent = '
<style>
    .profile-photo {
        width: 120px;
        height: 120px;
        object-fit: cover;
        border-radius: 50%;
        border: 3px solid #fff;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    .skill-tag {
        display: inline-block;
        background-color: #e0f2fe;
        color: #0369a1;
        font-size: 0.75rem;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        margin-right: 0.5rem;
        margin-bottom: 0.5rem;
    }
    .field-tag {
        display: inline-block;
        background-color: #dcfce7;
        color: #166534;
        font-size: 0.75rem;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        margin-right: 0.5rem;
        margin-bottom: 0.5rem;
    }
</style>
';

// Set page content
$content = '
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold text-gray-800">Job Seeker Profile</h1>
    <div class="flex space-x-2">
        ' . ($isOwnProfile ? '
        <a href="settings.php" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition duration-300 flex items-center">
            <i class="fas fa-cog mr-2"></i> Edit Profile
        </a>
        ' : '') . '
        <a href="javascript:history.back()" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 transition duration-300 flex items-center">
            <i class="fas fa-arrow-left mr-2"></i> Back
        </a>
    </div>
</div>

<div class="bg-white rounded-lg shadow-sm overflow-hidden">
    <!-- Profile Header -->
    <div class="bg-gradient-to-r from-blue-500 to-blue-600 p-6 text-white">
        <div class="flex flex-col md:flex-row items-center">
            <div class="mb-4 md:mb-0 md:mr-6">
                ' . (!empty($seeker['facephoto']) ? 
                    '<img src="' . htmlspecialchars($seeker['facephoto']) . '" alt="Profile Photo" class="profile-photo">' : 
                    '<div class="profile-photo bg-blue-400 flex items-center justify-center">
                        <i class="fas fa-user text-4xl text-white"></i>
                    </div>') . '
            </div>
            <div>
                <h2 class="text-2xl font-bold">' . htmlspecialchars($seeker['first_name'] . ' ' . $seeker['last_name']) . '</h2>
                <p class="text-blue-100 mt-1">' . (!empty($seeker['headline']) ? htmlspecialchars($seeker['headline']) : 'Job Seeker') . '</p>
                <div class="mt-2 flex items-center text-sm">
                    <i class="fas fa-map-marker-alt mr-1"></i>
                    <span>' . (!empty($seeker['location']) ? htmlspecialchars($seeker['location']) : 'Location not specified') . '</span>
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
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">About</h3>
                    <div class="text-gray-600">
                        ' . (!empty($seeker['about']) ? nl2br(htmlspecialchars($seeker['about'])) : 'No information provided.') . '
                    </div>
                </div>
                
                <!-- Skills Section -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Skills</h3>
                    <div>';
                    
if (!empty($seeker['skills'])) {
    $skills = explode(',', $seeker['skills']);
    foreach ($skills as $skill) {
        $content .= '<span class="skill-tag">' . htmlspecialchars(trim($skill)) . '</span>';
    }
} else {
    $content .= '<p class="text-gray-500">No skills listed.</p>';
}

$content .= '
                    </div>
                </div>
                
                <!-- Work Fields -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Work Fields</h3>
                    <div>';
                    
if (!empty($seeker['fields'])) {
    $fields = explode(',', $seeker['fields']);
    foreach ($fields as $field) {
        $content .= '<span class="field-tag">' . htmlspecialchars(trim($field)) . '</span>';
    }
} else {
    $content .= '<p class="text-gray-500">No work fields listed.</p>';
}

$content .= '
                    </div>
                </div>
                
                <!-- Education -->
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Education</h3>
                    <div class="text-gray-600">
                        ' . (!empty($seeker['education']) ? nl2br(htmlspecialchars($seeker['education'])) : 'No education information provided.') . '
                    </div>
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
                            <span>' . htmlspecialchars($seeker['email']) . '</span>
                        </li>';
                        
if (!empty($seeker['phone'])) {
    $content .= '
                        <li class="flex items-center text-gray-600">
                            <i class="fas fa-phone w-5 text-gray-400 mr-2"></i>
                            <span>' . htmlspecialchars($seeker['phone']) . '</span>
                        </li>';
}

$content .= '
                    </ul>
                </div>
                
                <!-- Documents -->
                <div class="bg-gray-50 p-4 rounded-lg">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Documents</h3>
                    <ul class="space-y-3">
                        <li>
                            <div class="font-medium text-gray-700">Resume</div>';
                            
if ($defaultResume) {
    $content .= '
                            <div class="flex items-center mt-1">
                                <i class="fas fa-file-pdf text-red-500 mr-2"></i>
                                <span class="text-sm text-gray-600">' . htmlspecialchars($defaultResume['original_name']) . '</span>
                            </div>';
} else {
    $content .= '
                            <div class="text-sm text-gray-500">No resume uploaded</div>';
}

$content .= '
                        </li>
                        <li>
                            <div class="font-medium text-gray-700">Clearance</div>';
                            
if ($defaultClearance) {
    $content .= '
                            <div class="flex items-center mt-1">
                                <i class="fas fa-file-alt text-blue-500 mr-2"></i>
                                <span class="text-sm text-gray-600">' . htmlspecialchars($defaultClearance['original_name']) . '</span>
                            </div>';
} else {
    $content .= '
                            <div class="text-sm text-gray-500">No clearance uploaded</div>';
}

$content .= '
                        </li>
                    </ul>
                </div>
                
                ' . (!$isOwnProfile ? '
                <!-- Contact Actions -->
                <div class="mt-6 flex flex-col space-y-2">
                    <a href="../../../pages/auth-user/message.php?to=' . $seeker['user_id'] . '" class="w-full px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition duration-300 text-center">
                        <i class="fas fa-envelope mr-2"></i> Send Message
                    </a>
                </div>
                ' : '') . '
            </div>
        </div>
    </div>
</div>
';

// Include layout
if ($isOwnProfile) {
    include_once 'nav/layout.php';
} else {
    include_once '../../../pages/imports/header.php';
    echo $content;
    include_once '../../../pages/imports/footer.php';
}
?> 