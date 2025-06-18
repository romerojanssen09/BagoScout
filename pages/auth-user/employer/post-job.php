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
$employerId = $user['id'];

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

// Check if account is verified
if ($user['status'] !== 'active') {
    header("Location: dashboard.php?error=unverified");
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

// Get Mapbox token
$stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'mapbox_token'");
$stmt->execute();
$result = $stmt->get_result();
$mapbox_token = $result->fetch_assoc()['setting_value'];
$stmt->close();

// Check if we're editing an existing job
$isEditing = false;
$jobId = null;

if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $jobId = intval($_GET['edit']);
    $isEditing = true;
    
    // Fetch the job data
    $stmt = $conn->prepare("SELECT * FROM jobs WHERE id = ? AND employer_id = ?");
    $stmt->bind_param("ii", $jobId, $employer['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $jobData = $result->fetch_assoc();
        
        // Pre-fill form fields
        $title = $jobData['title'];
        $description = $jobData['description'];
        $requirements = $jobData['requirements'];
        $location = $jobData['location'];
        $job_type = $jobData['job_type'];
        $pay_type = $jobData['pay_type'];
        $salary = $jobData['salary_min'];
        $response_time = $jobData['response_time'];
        $deadline = $jobData['deadline'];
        
        // Location data
        $latitude = $jobData['latitude'];
        $longitude = $jobData['longitude'];
        $prk = $jobData['prk'];
        $barangay = $jobData['barangay'];
        $city = $jobData['city'];
        $province = $jobData['province'];
        
        // Required documents
        $required_documents = $jobData['required_documents'];
        $required_documents = explode(',', $required_documents);
        $required_documents = array_map('trim', $required_documents);
        $required_documents = array_filter($required_documents);
        $required_documents = implode(',', $required_documents);
    } else {
        // Job not found or doesn't belong to this employer
        header("Location: manage-jobs.php?error=job_not_found");
        exit();
    }
    $stmt->close();
}

// Process form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate form data
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $requirements = trim($_POST['requirements']);
    $location = trim($_POST['location']);
    $job_type = trim($_POST['job_type']);
    $pay_type = trim($_POST['pay_type']);
    $salary = !empty($_POST['salary']) ? floatval($_POST['salary']) : null;
    $response_time = trim($_POST['response_time']);
    $deadline = !empty($_POST['deadline']) ? trim($_POST['deadline']) : null;
    
    // Location data
    $latitude = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : null;
    $longitude = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null;
    $prk = !empty($_POST['prk']) ? trim($_POST['prk']) : null;
    $barangay = !empty($_POST['barangay']) ? trim($_POST['barangay']) : null;
    $city = !empty($_POST['city']) ? trim($_POST['city']) : null;
    $province = !empty($_POST['province']) ? trim($_POST['province']) : null;
    
    // Required documents
    $required_documents = isset($_POST['required_documents']) ? implode(',', $_POST['required_documents']) : 'none';
    
    // Basic validation
    if (empty($title) || empty($description) || empty($requirements) || empty($location)) {
        $_SESSION['job_error'] = "Please fill in all required fields.";
        $_SESSION['job_form_data'] = $_POST; // Store form data in session
        
        // Redirect back with error
        if ($isEditing) {
            header("Location: post-job.php?edit=" . $jobId);
        } else {
            header("Location: post-job.php");
        }
        exit();
    } else {
        if ($isEditing) {
            // Force types
            $latitude = (float)$latitude;
            $longitude = (float)$longitude;
            $salary = (float)$salary;
            $jobId = (int)$jobId;
            $user['id'] = (int)$user['id'];

            // Prepare and check
            $stmt = $conn->prepare("UPDATE jobs SET 
                title = ?, description = ?, requirements = ?, location = ?, 
                latitude = ?, longitude = ?, prk = ?, barangay = ?, city = ?, province = ?,
                job_type = ?, pay_type = ?, salary_min = ?, response_time = ?, required_documents = ?, 
                deadline = ?, updated_at = NOW() 
                WHERE id = ? AND employer_id = ?");

            if (!$stmt) {
                $_SESSION['job_error'] = "Prepare failed: " . $conn->error;
                $_SESSION['job_form_data'] = $_POST;
                header("Location: post-job.php?edit=" . $jobId);
                exit();
            }

            $stmt->bind_param(
                "ssssddsssssssdssii",
                $title, $description, $requirements, $location, 
                $latitude, $longitude, $prk, $barangay, $city, $province,
                $job_type, $pay_type, $salary, $response_time, $required_documents, 
                $deadline, $jobId, $user['id']
            );

            if ($stmt->execute()) {
                $_SESSION['job_success'] = "Job updated successfully!";
                header("Location: post-job.php?edit=" . $jobId);
            } else {
                $_SESSION['job_error'] = "Update failed: " . $stmt->error;
                $_SESSION['job_form_data'] = $_POST;
                header("Location: post-job.php?edit=" . $jobId);
            }
            $stmt->close();
            exit();

        } else {
            // Insert new job posting
            $stmt = $conn->prepare("INSERT INTO jobs (employer_id, title, description, requirements, location, 
                                latitude, longitude, prk, barangay, city, province,
                                job_type, pay_type, salary_min, response_time, required_documents, deadline, status, created_at) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
            $stmt->bind_param("issssddssssssdsss", 
                $user['id'], $title, $description, $requirements, $location, 
                $latitude, $longitude, $prk, $barangay, $city, $province,
                $job_type, $pay_type, $salary, $response_time, $required_documents, $deadline);
            
            if ($stmt->execute()) {
                $_SESSION['job_success'] = "Job posted successfully!";
                header("Location: post-job.php");
            } else {
                $_SESSION['job_error'] = "Error posting job: " . $conn->error;
                $_SESSION['job_form_data'] = $_POST;
                header("Location: post-job.php");
            }
            $stmt->close();
            exit();
        }
    }
}

// Check for success/error messages
if (isset($_SESSION['job_success'])) {
    $message = $_SESSION['job_success'];
    $messageType = "success";
    unset($_SESSION['job_success']);
}

if (isset($_SESSION['job_error'])) {
    $message = $_SESSION['job_error'];
    $messageType = "error";
    unset($_SESSION['job_error']);
}

// Get form data from session if available
if (isset($_SESSION['job_form_data'])) {
    $formData = $_SESSION['job_form_data'];
    
    // Populate variables from form data
    $title = $formData['title'] ?? '';
    $description = $formData['description'] ?? '';
    $requirements = $formData['requirements'] ?? '';
    $location = $formData['location'] ?? '';
    $job_type = $formData['job_type'] ?? '';
    $pay_type = $formData['pay_type'] ?? '';
    $salary = $formData['salary'] ?? '';
    $response_time = $formData['response_time'] ?? '';
    $deadline = $formData['deadline'] ?? '';
    
    // Location data
    $latitude = $formData['latitude'] ?? '';
    $longitude = $formData['longitude'] ?? '';
    $prk = $formData['prk'] ?? '';
    $barangay = $formData['barangay'] ?? '';
    $city = $formData['city'] ?? '';
    $province = $formData['province'] ?? '';
    
    // Required documents
    if (isset($formData['required_documents'])) {
        $required_documents = implode(',', $formData['required_documents']);
    }
    
    // Clear the session data
    unset($_SESSION['job_form_data']);
}

$conn->close();

// Set page title
$pageTitle = $isEditing ? "Edit Job" : "Post a Job";

// Add extra head content
$extraHeadContent = '
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://api.mapbox.com/mapbox-gl-js/v2.14.1/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v2.14.1/mapbox-gl.js"></script>
<link href="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v5.0.0/mapbox-gl-geocoder.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v5.0.0/mapbox-gl-geocoder.min.js"></script>
<style>
    .form-input:focus { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); }
    #map { width: 100%; height: 100%; border-radius: 0.375rem; }
    .mapboxgl-ctrl-geocoder { width: 100%; max-width: none; }
    .mapboxgl-marker { cursor: pointer; }
    .mapboxgl-marker svg { width: 40px; height: 40px; }
    .mapboxgl-marker-shadow { filter: drop-shadow(0px 4px 4px rgba(0, 0, 0, 0.25)); }
    .mapboxgl-ctrl-top-right { width: 240px; }
    .mapboxgl-ctrl-geocoder { width: 100% !important; max-width: 100% !important; }
    .mapboxgl-ctrl-geocoder--collapsed { min-width: 40px; width: 40px !important; }
    .mapboxgl-ctrl-geocoder--input { height: 36px; }
    
    /* Map modal styles */
    #mapModal { padding: 0; }
    #mapModal .bg-white { max-height: 100vh; height: 100vh; max-width: 100% !important; width: 100%; border-radius: 0; }
    #mapModal .map-container { height: calc(100vh - 180px); }
</style>
';

// Set page content
$content = '
<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold text-gray-800">' . ($isEditing ? 'Edit Job' : 'Post a New Job') . '</h1>
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

<div class="bg-white rounded-lg shadow-sm p-6">
    <form method="POST" action="" class="space-y-6">
        <!-- Job Title -->
        <div>
            <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Job Title / Skill Set Needed *</label>
            <input type="text" id="title" name="title" required 
                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 form-input"
                value="' . (isset($title) ? htmlspecialchars($title) : '') . '" 
                placeholder="e.g., .NET Developer, Marketing Specialist">
            <p class="text-xs text-gray-500 mt-1">Specify the job title or skills needed for this position.</p>
        </div>

        <!-- Required Documents -->
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Required Documents</label>
            <div class="space-y-2">
                <div class="flex items-center">
                    <input type="checkbox" id="resume" name="required_documents[]" value="resume" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                    ' . (isset($required_documents) && strpos($required_documents, 'resume') !== false ? 'checked' : '') . '>
                    <label for="resume" class="ml-2 text-sm text-gray-700">Resume</label>
                </div>
                <div class="flex items-center">
                    <input type="checkbox" id="police_clearance" name="required_documents[]" value="police_clearance" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                    ' . (isset($required_documents) && strpos($required_documents, 'police_clearance') !== false ? 'checked' : '') . '>
                    <label for="police_clearance" class="ml-2 text-sm text-gray-700">Police Clearance</label>
                </div>
                <div class="flex items-center">
                    <input type="checkbox" id="none" name="required_documents[]" value="none" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                    ' . (isset($required_documents) && $required_documents === 'none' ? 'checked' : '') . '>
                    <label for="none" class="ml-2 text-sm text-gray-700">None</label>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-1">Select the documents required from applicants.</p>
        </div>

        <!-- Pay Type -->
        <div>
            <label for="pay_type" class="block text-sm font-medium text-gray-700 mb-1">Pay Type *</label>
            <select id="pay_type" name="pay_type" required
                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 form-input">
                <option value="hourly" ' . (isset($pay_type) && $pay_type === 'hourly' ? 'selected' : '') . '>Hourly Rate</option>
                <option value="monthly" ' . (!isset($pay_type) || $pay_type === 'monthly' ? 'selected' : '') . '>Monthly Salary</option>
                <option value="annual" ' . (isset($pay_type) && $pay_type === 'annual' ? 'selected' : '') . '>Annual Salary</option>
            </select>
        </div>

        <!-- Salary -->
        <div>
            <label for="salary" class="block text-sm font-medium text-gray-700 mb-1">Pay Amount (PHP) *</label>
            <div class="flex items-center">
                <span class="inline-flex items-center px-3 py-2 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-500">₱</span>
                <input type="number" id="salary" name="salary" min="0" step="1" required
                    class="w-full px-4 py-2 border border-gray-300 rounded-r-md focus:outline-none focus:ring-2 focus:ring-blue-500 form-input"
                    value="' . (isset($salary) ? htmlspecialchars($salary) : '') . '">
            </div>
            <p class="text-xs text-gray-500 mt-1">Enter the payment amount in Philippine Pesos.</p>
        </div>

        <!-- Job Location -->
        <div>
            <label for="location" class="block text-sm font-medium text-gray-700 mb-1">Job Location *</label>
            <input type="text" id="location" name="location" required
                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 form-input"
                value="' . (isset($location) ? htmlspecialchars($location) : '') . '" readonly placeholder="Click the button to set the job location">
            <p class="text-xs text-gray-500 mt-1">Specify the work location (e.g., Office-based, Remote, Hybrid)</p>
        </div>

        <!-- Set Location Button -->
        <div>
            <button type="button" id="setLocationBtn" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-300 flex items-center">
                <i class="fas fa-map-marker-alt mr-2"></i> Set Job Location on Map
            </button>
            
            <!-- Hidden location fields -->
            <input type="hidden" id="latitude" name="latitude" value="' . (isset($latitude) ? htmlspecialchars($latitude) : '') . '">
            <input type="hidden" id="longitude" name="longitude" value="' . (isset($longitude) ? htmlspecialchars($longitude) : '') . '">
            <input type="hidden" id="prk" name="prk" value="' . (isset($prk) ? htmlspecialchars($prk) : '') . '">
            <input type="hidden" id="barangay" name="barangay" value="' . (isset($barangay) ? htmlspecialchars($barangay) : '') . '">
            <input type="hidden" id="city" name="city" value="' . (isset($city) ? htmlspecialchars($city) : '') . '">
            <input type="hidden" id="province" name="province" value="' . (isset($province) ? htmlspecialchars($province) : '') . '">
            
            <div id="locationDetails" class="mt-2 p-3 bg-gray-50 rounded-md ' . (isset($latitude) && isset($longitude) ? '' : 'hidden') . '">
                <h4 class="font-medium text-gray-700 mb-1">Selected Location:</h4>
                <p id="locationText" class="text-sm text-gray-600">' . (isset($prk) ? "PRK: $prk, Barangay: $barangay, $city, $province" : '') . '</p>
            </div>
        </div>

        <!-- Job Type -->
        <div>
            <label for="job_type" class="block text-sm font-medium text-gray-700 mb-1">Job Type *</label>
            <select id="job_type" name="job_type" required
                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 form-input">
                <option value="" disabled ' . (!isset($job_type) ? 'selected' : '') . '>Select job type</option>
                <option value="Full-time" ' . (isset($job_type) && $job_type === 'Full-time' ? 'selected' : '') . '>Full-time</option>
                <option value="Part-time" ' . (isset($job_type) && $job_type === 'Part-time' ? 'selected' : '') . '>Part-time</option>
                <option value="Contract" ' . (isset($job_type) && $job_type === 'Contract' ? 'selected' : '') . '>Contract</option>
                <option value="Freelance" ' . (isset($job_type) && $job_type === 'Freelance' ? 'selected' : '') . '>Freelance</option>
                <option value="Internship" ' . (isset($job_type) && $job_type === 'Internship' ? 'selected' : '') . '>Internship</option>
            </select>
        </div>
        
        <!-- Response Time -->
        <div>
            <label for="response_time" class="block text-sm font-medium text-gray-700 mb-1">Response Time *</label>
            <select id="response_time" name="response_time" required
                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 form-input">
                <option value="within_hour" ' . (isset($response_time) && $response_time === 'within_hour' ? 'selected' : '') . '>Within an hour</option>
                <option value="within_day" ' . (!isset($response_time) || $response_time === 'within_day' ? 'selected' : '') . '>Within a day</option>
                <option value="within_week" ' . (isset($response_time) && $response_time === 'within_week' ? 'selected' : '') . '>Within a week</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">How quickly you typically respond to applications.</p>
        </div>

        <!-- Job Requirements -->
        <div>
            <label for="requirements" class="block text-sm font-medium text-gray-700 mb-1">Requirements *</label>
            <textarea id="requirements" name="requirements" rows="4" required
                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 form-input">' . (isset($requirements) ? htmlspecialchars($requirements) : '') . '</textarea>
            <p class="text-xs text-gray-500 mt-1">List the qualifications, skills, and experience required for this role.</p>
        </div>

        <!-- Job Description -->
        <div>
            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Job Description *</label>
            <textarea id="description" name="description" rows="6" required
                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 form-input">' . (isset($description) ? htmlspecialchars($description) : '') . '</textarea>
            <p class="text-xs text-gray-500 mt-1">Describe the role, responsibilities, and what a typical day looks like.</p>
        </div>

        <!-- Application Deadline -->
        <div>
            <label for="deadline" class="block text-sm font-medium text-gray-700 mb-1">Application Deadline</label>
            <input type="date" id="deadline" name="deadline"
                class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 form-input"
                value="' . (isset($deadline) ? htmlspecialchars($deadline) : '') . '">
        </div>

        <!-- Submit Button -->
        <div class="pt-4">
            <button type="submit" class="w-full md:w-auto px-6 py-3 bg-blue-600 text-white font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition duration-300">
                ' . ($isEditing ? 'Update Job' : 'Post Job') . '
            </button>
        </div>
    </form>
</div>

<!-- Map Modal -->
<div id="mapModal" class="fixed inset-0 z-50 hidden overflow-hidden bg-white">
    <div class="w-full h-full flex flex-col">
        <!-- Header -->
        <div class="p-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-medium text-gray-900">Set Job Location</h3>
            <button id="closeMapModal" class="text-gray-400 hover:text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <!-- Content -->
        <div class="flex flex-grow h-full">
            <!-- Map (80%) -->
            <div class="w-4/5 h-full relative">
                <div id="map" class="absolute inset-0"></div>
            </div>
            
            <!-- Inputs (20%) -->
            <div class="w-1/5 p-4 border-l border-gray-200 overflow-y-auto">
                <p class="text-sm text-gray-600 mb-4">Click on the map to set the job location or edit the fields directly.</p>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">PRK</label>
                        <input type="text" id="modalPrk" class="w-full px-4 py-2 border border-gray-300 rounded-md">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Barangay</label>
                        <input type="text" id="modalBarangay" class="w-full px-4 py-2 border border-gray-300 rounded-md">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                        <input type="text" id="modalCity" class="w-full px-4 py-2 border border-gray-300 rounded-md">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Province</label>
                        <input type="text" id="modalProvince" class="w-full px-4 py-2 border border-gray-300 rounded-md">
                    </div>
                    
                    <!-- Confirm Button -->
                    <div class="pt-4">
                        <button id="confirmLocation" class="w-full px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-300">
                            Confirm Location
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Mapbox token
    mapboxgl.accessToken = "' . $mapbox_token . '";
    
    let map;
    let marker;
    
    // Handle document requirement checkboxes
    const noneCheckbox = document.getElementById("none");
    const resumeCheckbox = document.getElementById("resume");
    const policeCheckbox = document.getElementById("police_clearance");
    
    noneCheckbox.addEventListener("change", function() {
        if (this.checked) {
            resumeCheckbox.checked = false;
            policeCheckbox.checked = false;
        }
    });
    
    resumeCheckbox.addEventListener("change", function() {
        if (this.checked) {
            noneCheckbox.checked = false;
        }
    });
    
    policeCheckbox.addEventListener("change", function() {
        if (this.checked) {
            noneCheckbox.checked = false;
        }
    });
    
    // Map modal functionality
    const mapModal = document.getElementById("mapModal");
    const setLocationBtn = document.getElementById("setLocationBtn");
    const closeMapModal = document.getElementById("closeMapModal");
    const confirmLocationBtn = document.getElementById("confirmLocation");
    
    setLocationBtn.addEventListener("click", function() {
        mapModal.classList.remove("hidden");
        
        // Initialize map if not already initialized
        if (!map) {
            initializeMap();
        } else {
            // Trigger a resize event to fix the map display
            setTimeout(() => {
                map.resize();
            }, 10);
        }
    });
    
    closeMapModal.addEventListener("click", function() {
        mapModal.classList.add("hidden");
    });
    
    confirmLocationBtn.addEventListener("click", function() {
        if (marker) {
            const lngLat = marker.getLngLat();
            document.getElementById("latitude").value = lngLat.lat;
            document.getElementById("longitude").value = lngLat.lng;
            
            // Get the potentially edited values
            const prk = document.getElementById("modalPrk").value;
            const barangay = document.getElementById("modalBarangay").value;
            const city = document.getElementById("modalCity").value;
            const province = document.getElementById("modalProvince").value;
            
            document.getElementById("prk").value = prk;
            document.getElementById("barangay").value = barangay;
            document.getElementById("city").value = city;
            document.getElementById("province").value = province;
            
            // Update location field with address components
            const locationParts = [];
            if (prk) locationParts.push(prk);
            if (barangay) locationParts.push(barangay);
            if (city) locationParts.push(city);
            if (province) locationParts.push(province);
            
            if (locationParts.length > 0) {
                document.getElementById("location").value = locationParts.join(", ");
            } else {
                // If all fields are empty, set a default location name
                document.getElementById("location").value = "Custom Location";
            }
            
            // Show location details
            const locationDetails = document.getElementById("locationDetails");
            const locationText = document.getElementById("locationText");
            locationDetails.classList.remove("hidden");
            
            // Format the location text
            const locationPartsFormatted = [];
            if (prk) locationPartsFormatted.push(`PRK: ${prk}`);
            if (barangay) locationPartsFormatted.push(`Barangay: ${barangay}`);
            if (city) locationPartsFormatted.push(city);
            if (province) locationPartsFormatted.push(province);
            
            locationText.textContent = locationPartsFormatted.join(", ") || "Custom Location";
        }
        
        mapModal.classList.add("hidden");
    });
    
    function initializeMap() {
        // Initialize the map with default center
        map = new mapboxgl.Map({
            container: "map",
            style: "mapbox://styles/mapbox/streets-v12",
            center: [122.9888, 10.6713], // Default center on Bago City
            zoom: 12
        });
        
        // Add navigation controls
        map.addControl(new mapboxgl.NavigationControl());
        
        // Add geolocate control
        const geolocate = new mapboxgl.GeolocateControl({
            positionOptions: {
                enableHighAccuracy: true
            },
            trackUserLocation: true,
            showUserHeading: true
        });
        
        map.addControl(geolocate);
        
        // Try to get user location and center map
        map.on("load", function() {
            // Trigger the geolocate control as soon as the map loads
            setTimeout(() => {
                geolocate.trigger();
            }, 1000);
            
            // If we already have coordinates, show the marker
            const lat = document.getElementById("latitude").value;
            const lng = document.getElementById("longitude").value;
            
            if (lat && lng) {
                addMarker([parseFloat(lng), parseFloat(lat)]);
            }
        });
        
        // Add click event to map
        map.on("click", function(e) {
            const coordinates = [e.lngLat.lng, e.lngLat.lat];
            addMarker(coordinates);
            reverseGeocode(coordinates);
        });
        
        // Add geocoder if available
        if (typeof MapboxGeocoder !== "undefined") {
            const geocoder = new MapboxGeocoder({
                accessToken: mapboxgl.accessToken,
                mapboxgl: mapboxgl,
                placeholder: "Search for a location",
                collapsed: true,
                clearOnBlur: true
            });
            
            map.addControl(geocoder);
            
            geocoder.on("result", function(e) {
                if (e.result && e.result.geometry && e.result.geometry.coordinates) {
                    addMarker(e.result.geometry.coordinates);
                    reverseGeocode(e.result.geometry.coordinates);
                }
            });
        }
    }
    
    function addMarker(coordinates) {
        // Remove existing marker if any
        if (marker) {
            marker.remove();
        }
        
        // Add new marker with larger size
        marker = new mapboxgl.Marker({
            color: "#3B82F6",
            draggable: true,
            scale: 1.5 // Make marker larger
        })
        .setLngLat(coordinates)
        .addTo(map);
        
        // Add dragend event to marker
        marker.on("dragend", function() {
            const lngLat = marker.getLngLat();
            reverseGeocode([lngLat.lng, lngLat.lat]);
        });
        
        // Center map on marker
        map.flyTo({
            center: coordinates,
            zoom: 15
        });
        
        // Update the location field temporarily
        document.getElementById("location").value = "Location selected (retrieving address...)";
    }
    
    function reverseGeocode(coordinates) {
        // Use Mapbox Geocoding API to get address details
        fetch(`https://api.mapbox.com/geocoding/v5/mapbox.places/${coordinates[0]},${coordinates[1]}.json?access_token=${mapboxgl.accessToken}&types=address,neighborhood,locality,place,region,country`)
            .then(response => response.json())
            .then(data => {
                if (data.features && data.features.length > 0) {
                    // Extract address components
                    const features = data.features;
                    let prk = "";
                    let barangay = "";
                    let city = "";
                    let province = "";
                    
                    features.forEach(feature => {
                        if (feature.place_type.includes("neighborhood")) {
                            prk = feature.text;
                        }
                        if (feature.place_type.includes("locality")) {
                            barangay = feature.text;
                        }
                        if (feature.place_type.includes("place")) {
                            city = feature.text;
                        }
                        if (feature.place_type.includes("region")) {
                            province = feature.text;
                        }
                    });
                    
                    // Set the values in the form (use empty string instead of "Not available")
                    document.getElementById("modalPrk").value = prk || "";
                    document.getElementById("modalBarangay").value = barangay || "";
                    document.getElementById("modalCity").value = city || "";
                    document.getElementById("modalProvince").value = province || "";
                }
            })
            .catch(error => {
                console.error("Error fetching location data:", error);
            });
    }
    
    // Close modal when clicking outside
    window.addEventListener("click", function(event) {
        if (event.target === mapModal) {
            mapModal.classList.add("hidden");
        }
    });
});
</script>
';

// Include layout
include 'nav/layout.php';
?> 