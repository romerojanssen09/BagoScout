<?php
session_start();
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../../../index.php");
    exit();
}

// Get current user
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$userRole = $currentUser['role'];

// Redirect if not a jobseeker
if ($userRole !== 'jobseeker') {
    header("Location: jobs.php");
    exit();
}

// Get jobseeker ID
$conn = getDbConnection();
$stmt = $conn->prepare("SELECT id FROM jobseekers WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: jobs.php");
    exit();
}

$seeker = $result->fetch_assoc();
$seekerId = $seeker['id'];
$stmt->close();

// Handle application cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    if (isset($_POST['application_id']) && !empty($_POST['application_id'])) {
        $applicationId = intval($_POST['application_id']);

        if (cancelJobApplication($applicationId, $seekerId)) {
            header("Location: my-applications.php?success=application_cancelled");
        } else {
            header("Location: my-applications.php?error=cancel_failed");
        }
        exit();
    }
}

// Get job ID
if (!isset($_GET['job_id']) && !isset($_GET['job'])) {
    header("Location: jobs.php?error=invalid_job");
    exit();
}

// Support both job_id and job parameters for backward compatibility
$job_id = isset($_GET['job_id']) ? intval($_GET['job_id']) : intval($_GET['job']);

// Get job details
$stmt = $conn->prepare("SELECT j.*, e.company_name, u.first_name, u.last_name 
                       FROM jobs j
                       JOIN employers e ON j.employer_id = e.id
                       JOIN users u ON e.user_id = u.id
                       WHERE j.id = ? AND j.status = 'active'");
$stmt->bind_param("i", $job_id);
$stmt->execute();
$result = $stmt->get_result();
$job = $result->fetch_assoc();
$stmt->close();

if (!$job) {
    header("Location: jobs.php?error=job_not_found");
    exit();
}

// Check if already applied
$stmt = $conn->prepare("SELECT id FROM applications WHERE job_id = ? AND jobseeker_id = ?");
$stmt->bind_param("ii", $job_id, $seekerId);
$stmt->execute();
$result = $stmt->get_result();
$alreadyApplied = $result->num_rows > 0;
$stmt->close();

// Process form submission
$message = '';
$messageType = '';

// Get jobseeker documents from database
$stmt = $conn->prepare("SELECT j.id, j.resume, j.police_clearance FROM jobseekers j WHERE j.user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$seekerDocs = $result->fetch_assoc();
$jobseekerId = $seekerDocs['id'];
$stmt->close();

// Get all resumes
$resumeStmt = $conn->prepare("SELECT * FROM jobseeker_resumes WHERE jobseeker_id = ? ORDER BY is_default DESC, created_at DESC");
$resumeStmt->bind_param("i", $jobseekerId);
$resumeStmt->execute();
$resumesResult = $resumeStmt->get_result();
$hasResumes = $resumesResult->num_rows > 0;
$resumes = [];
while ($row = $resumesResult->fetch_assoc()) {
    $resumes[] = $row;
}
$resumeStmt->close();

// Get all clearances
$clearanceStmt = $conn->prepare("SELECT * FROM jobseeker_clearances WHERE jobseeker_id = ? ORDER BY is_default DESC, created_at DESC");
$clearanceStmt->bind_param("i", $jobseekerId);
$clearanceStmt->execute();
$clearancesResult = $clearanceStmt->get_result();
$hasClearances = $clearancesResult->num_rows > 0;
$clearances = [];
while ($row = $clearancesResult->fetch_assoc()) {
    $clearances[] = $row;
}
$clearanceStmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$alreadyApplied) {
    $cover_letter = trim($_POST['cover_letter']);
    $resume_path = null;
    $police_clearance_path = null;

    // Check required documents
    $required_documents = explode(',', $job['required_documents']);
    $documents_ok = true;

    // Handle resume upload if required
    if (in_array('resume', $required_documents) && !in_array('none', $required_documents)) {
        // Check if using pre-uploaded resume
        if (isset($_POST['use_existing_resume']) && !empty($_POST['selected_resume'])) {
            $selectedResumeId = intval($_POST['selected_resume']);
            
            // Verify the resume belongs to this jobseeker
            $verifyStmt = $conn->prepare("SELECT filename FROM jobseeker_resumes WHERE id = ? AND jobseeker_id = ?");
            $verifyStmt->bind_param("ii", $selectedResumeId, $jobseekerId);
            $verifyStmt->execute();
            $verifyResult = $verifyStmt->get_result();
            
            if ($verifyResult->num_rows > 0) {
                $selectedResume = $verifyResult->fetch_assoc();
                $resume_path = $selectedResume['filename'];
                $documents_ok = true;
            } else {
                $message = "Invalid resume selection.";
                $messageType = "error";
                $documents_ok = false;
            }
            $verifyStmt->close();
        } 
        // Check if new resume was uploaded
        else if (isset($_FILES['resume']) && $_FILES['resume']['error'] === 0) {
            $allowed = ['pdf', 'doc', 'docx'];
            $filename = $_FILES['resume']['name'];
            $filetype = pathinfo($filename, PATHINFO_EXTENSION);

            if (in_array(strtolower($filetype), $allowed)) {
                $new_filename = 'resume_' . $userId . '_' . time() . '.' . $filetype;
                $upload_dir = '../../../uploads/jobseeker/' . $_SESSION['user_id'] . '/documents/resumes/';
                $upload_path = $upload_dir . $new_filename;

                // Check if directory exists, create if not
                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0755, true)) {
                        $message = "Failed to create upload directory for resumes.";
                        $messageType = "error";
                        $documents_ok = false;
                    }
                }

                if (is_dir($upload_dir)) {
                    if (move_uploaded_file($_FILES['resume']['tmp_name'], $upload_path)) {
                        $resume_path = $new_filename;
                        
                        // Also save to jobseeker_resumes table
                        $originalName = $_FILES['resume']['name'];
                        $fileType = $_FILES['resume']['type'];
                        $fileSize = $_FILES['resume']['size'];
                        
                        // Check if this is the first resume (make it default if it is)
                        $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM jobseeker_resumes WHERE jobseeker_id = ?");
                        $checkStmt->bind_param("i", $jobseekerId);
                        $checkStmt->execute();
                        $checkResult = $checkStmt->get_result();
                        $count = $checkResult->fetch_assoc()['count'];
                        $isDefault = ($count == 0) ? 1 : 0;
                        $checkStmt->close();
                        
                        $insertStmt = $conn->prepare("INSERT INTO jobseeker_resumes (jobseeker_id, filename, original_name, file_type, file_size, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $insertStmt->bind_param("isssii", $jobseekerId, $new_filename, $originalName, $fileType, $fileSize, $isDefault);
                        $insertStmt->execute();
                        $insertStmt->close();
                    } else {
                        $message = "Error uploading resume.";
                        $messageType = "error";
                        $documents_ok = false;
                    }
                }
            } else {
                $message = "Invalid resume file format. Please upload PDF, DOC, or DOCX.";
                $messageType = "error";
                $documents_ok = false;
            }
        } else {
            $message = "Resume is required for this job.";
            $messageType = "error";
            $documents_ok = false;
        }
    }

    // Handle police clearance upload if required
    if (in_array('police_clearance', $required_documents) && !in_array('none', $required_documents)) {
        // Check if using pre-uploaded clearance
        if (isset($_POST['use_existing_clearance']) && !empty($_POST['selected_clearance'])) {
            $selectedClearanceId = intval($_POST['selected_clearance']);
            
            // Verify the clearance belongs to this jobseeker
            $verifyStmt = $conn->prepare("SELECT filename FROM jobseeker_clearances WHERE id = ? AND jobseeker_id = ?");
            $verifyStmt->bind_param("ii", $selectedClearanceId, $jobseekerId);
            $verifyStmt->execute();
            $verifyResult = $verifyStmt->get_result();
            
            if ($verifyResult->num_rows > 0) {
                $selectedClearance = $verifyResult->fetch_assoc();
                $police_clearance_path = $selectedClearance['filename'];
                $documents_ok = true;
            } else {
                $message = "Invalid clearance selection.";
                $messageType = "error";
                $documents_ok = false;
            }
            $verifyStmt->close();
        }
        // Check if new clearance was uploaded
        else if (isset($_FILES['police_clearance']) && $_FILES['police_clearance']['error'] === 0) {
            $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            $filename = $_FILES['police_clearance']['name'];
            $filetype = pathinfo($filename, PATHINFO_EXTENSION);

            if (in_array(strtolower($filetype), $allowed)) {
                $new_filename = 'clearance_' . $userId . '_' . time() . '.' . $filetype;
                $upload_dir = '../../../uploads/jobseeker/' . $_SESSION['user_id'] . '/documents/police_clearance/';
                $upload_path = $upload_dir . $new_filename;

                // Check if directory exists, if not create it with recursive = true
                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0755, true)) {
                        $message = "Failed to create upload directory.";
                        $messageType = "error";
                        $documents_ok = false;
                    }
                }

                // Proceed only if directory exists or was created successfully
                if (is_dir($upload_dir)) {
                    if (move_uploaded_file($_FILES['police_clearance']['tmp_name'], $upload_path)) {
                        $police_clearance_path = $new_filename;
                        
                        // Also save to jobseeker_clearances table
                        $originalName = $_FILES['police_clearance']['name'];
                        $fileType = $_FILES['police_clearance']['type'];
                        $fileSize = $_FILES['police_clearance']['size'];
                        $clearanceType = 'police'; // Default to police clearance
                        
                        // Check if this is the first clearance (make it default if it is)
                        $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM jobseeker_clearances WHERE jobseeker_id = ?");
                        $checkStmt->bind_param("i", $jobseekerId);
                        $checkStmt->execute();
                        $checkResult = $checkStmt->get_result();
                        $count = $checkResult->fetch_assoc()['count'];
                        $isDefault = ($count == 0) ? 1 : 0;
                        $checkStmt->close();
                        
                        $insertStmt = $conn->prepare("INSERT INTO jobseeker_clearances (jobseeker_id, filename, original_name, file_type, file_size, clearance_type, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                        $insertStmt->bind_param("issssii", $jobseekerId, $new_filename, $originalName, $fileType, $fileSize, $clearanceType, $isDefault);
                        $insertStmt->execute();
                        $insertStmt->close();
                    } else {
                        $message = "Error uploading police clearance.";
                        $messageType = "error";
                        $documents_ok = false;
                    }
                }
            } else {
                $message = "Invalid police clearance file format. Please upload PDF, JPG, JPEG, or PNG.";
                $messageType = "error";
                $documents_ok = false;
            }
        } else {
            $message = "Police clearance is required for this job.";
            $messageType = "error";
            $documents_ok = false;
        }
    }

    // Submit application if documents are OK
    if ($documents_ok) {
        // For resume path, store the filename only
        $resume_path_for_db = $resume_path;
        
        // For police clearance path, store the filename only
        $police_clearance_path_for_db = $police_clearance_path;
        
        $stmt = $conn->prepare("INSERT INTO applications (job_id, jobseeker_id, resume, police_clearance, cover_letter, status, created_at) 
                               VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->bind_param("iisss", $job_id, $seekerId, $resume_path_for_db, $police_clearance_path_for_db, $cover_letter);

        if ($stmt->execute()) {
            $stmt->close();
            // Redirect to prevent form resubmission
            header("Location: apply-job.php?job_id=" . $job_id . "&success=1");
            exit();
        } else {
            $stmt->close();
            // Redirect with error
            header("Location: apply-job.php?job_id=" . $job_id . "&error=" . urlencode("Error submitting application: " . $conn->error));
            exit();
        }
    } else {
        // Redirect with error
        header("Location: apply-job.php?job_id=" . $job_id . "&error=" . urlencode($message));
        exit();
    }
}

// Check for success/error messages from redirects
if (isset($_GET['success'])) {
    $message = "Application submitted successfully!";
    $messageType = "success";
    $alreadyApplied = true;
} else if (isset($_GET['error'])) {
    $message = $_GET['error'];
    $messageType = "error";
}

$conn->close();

// Set page title
$pageTitle = "Apply for Job";

// Add extra head content
$extraHeadContent = '
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.4.120/web/pdf_viewer.min.css">
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.4.120/build/pdf.min.js"></script>
<style>
    .form-input:focus { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); }
    .document-preview { width: 100%; height: 400px; border: 1px solid #e5e7eb; margin-top: 10px; }
    .document-preview-container { 
        margin-top: 10px; 
        background-color: #f9fafb;
        padding: 15px;
        border-radius: 8px;
    }
    .document-preview img { max-width: 100%; max-height: 400px; display: block; margin: 0 auto; }
    .document-selector { margin-bottom: 10px; border: 1px solid #e5e7eb; padding: 10px; border-radius: 5px; cursor: pointer; }
    .document-selector.selected { border-color: #3b82f6; background-color: #eff6ff; }
    .document-selector-container { max-height: 300px; overflow-y: auto; }
    .preview-title {
        font-size: 16px;
        font-weight: 500;
        margin-bottom: 10px;
        color: #374151;
    }
    .preview-section {
        margin-bottom: 20px;
        padding: 15px;
        background-color: white;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    .main-preview-container {
        margin-top: 20px;
        padding: 20px;
        background-color: #f3f4f6;
        border-radius: 8px;
    }
</style>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        // Handle resume checkbox
        const resumeCheckbox = document.querySelector("input[name=\'use_existing_resume\']");
        const resumeFileInput = document.querySelector("#resume");
        const resumeSelector = document.querySelector("#resume-selector");
        
        if (resumeCheckbox) {
            resumeCheckbox.addEventListener("change", function() {
                resumeFileInput.disabled = this.checked;
                resumeFileInput.required = !this.checked;
                resumeSelector.style.display = this.checked ? "block" : "none";
                if (this.checked) {
                    resumeFileInput.classList.add("bg-gray-100");
                    document.getElementById("resume-preview-section").style.display = "block";
                } else {
                    resumeFileInput.classList.remove("bg-gray-100");
                    // Hide preview when switching to file upload
                    document.getElementById("resume-preview-section").style.display = "none";
                }
            });
            // Initialize on page load
            if (resumeCheckbox.checked) {
                resumeFileInput.disabled = true;
                resumeFileInput.required = false;
                resumeSelector.style.display = "block";
                resumeFileInput.classList.add("bg-gray-100");
                document.getElementById("resume-preview-section").style.display = "block";
            } else {
                resumeSelector.style.display = "none";
                document.getElementById("resume-preview-section").style.display = "none";
            }
        }
        
        // Handle clearance checkbox
        const clearanceCheckbox = document.querySelector("input[name=\'use_existing_clearance\']");
        const clearanceFileInput = document.querySelector("#police_clearance");
        const clearanceSelector = document.querySelector("#clearance-selector");
        
        if (clearanceCheckbox) {
            clearanceCheckbox.addEventListener("change", function() {
                clearanceFileInput.disabled = this.checked;
                clearanceFileInput.required = !this.checked;
                clearanceSelector.style.display = this.checked ? "block" : "none";
                if (this.checked) {
                    clearanceFileInput.classList.add("bg-gray-100");
                    document.getElementById("clearance-preview-section").style.display = "block";
                } else {
                    clearanceFileInput.classList.remove("bg-gray-100");
                    // Hide preview when switching to file upload
                    document.getElementById("clearance-preview-section").style.display = "none";
                }
            });
            // Initialize on page load
            if (clearanceCheckbox.checked) {
                clearanceFileInput.disabled = true;
                clearanceFileInput.required = false;
                clearanceSelector.style.display = "block";
                clearanceFileInput.classList.add("bg-gray-100");
                document.getElementById("clearance-preview-section").style.display = "block";
            } else {
                clearanceSelector.style.display = "none";
                document.getElementById("clearance-preview-section").style.display = "none";
            }
        }
        
        // Handle resume selection
        document.querySelectorAll(".resume-item").forEach(item => {
            item.addEventListener("click", function() {
                const resumeId = this.getAttribute("data-id");
                document.querySelector("input[name=\'selected_resume\']").value = resumeId;
                
                // Update selection UI
                document.querySelectorAll(".resume-item").forEach(el => {
                    el.classList.remove("selected");
                });
                this.classList.add("selected");
                
                // Show preview for this item
                document.querySelectorAll(".resume-preview").forEach(preview => {
                    preview.style.display = "none";
                });
                document.querySelector(`.resume-preview[data-id="${resumeId}"]`).style.display = "block";
            });
        });
        
        // Handle clearance selection
        document.querySelectorAll(".clearance-item").forEach(item => {
            item.addEventListener("click", function() {
                const clearanceId = this.getAttribute("data-id");
                document.querySelector("input[name=\'selected_clearance\']").value = clearanceId;
                
                // Update selection UI
                document.querySelectorAll(".clearance-item").forEach(el => {
                    el.classList.remove("selected");
                });
                this.classList.add("selected");
                
                // Show preview for this item
                document.querySelectorAll(".clearance-preview").forEach(preview => {
                    preview.style.display = "none";
                });
                document.querySelector(`.clearance-preview[data-id="${clearanceId}"]`).style.display = "block";
            });
        });
        
        // Initialize PDF.js
        pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdn.jsdelivr.net/npm/pdfjs-dist@3.4.120/build/pdf.worker.min.js";
        
        // Load PDFs if any
        document.querySelectorAll(".pdf-preview").forEach(container => {
            const pdfUrl = container.getAttribute("data-pdf-url");
            if (pdfUrl) {
                const loadingTask = pdfjsLib.getDocument(pdfUrl);
                loadingTask.promise.then(pdf => {
                    pdf.getPage(1).then(page => {
                        const scale = 1.5;
                        const viewport = page.getViewport({ scale });
                        
                        const canvas = document.createElement("canvas");
                        const context = canvas.getContext("2d");
                        canvas.height = viewport.height;
                        canvas.width = viewport.width;
                        
                        const renderContext = {
                            canvasContext: context,
                            viewport: viewport
                        };
                        
                        container.innerHTML = "";
                        container.appendChild(canvas);
                        
                        page.render(renderContext);
                    });
                }).catch(error => {
                    console.error("Error loading PDF:", error);
                    container.innerHTML = "<p class=\'text-red-500\'>Error loading PDF preview</p>";
                });
            }
        });
        
        // Select first items by default
        const firstResume = document.querySelector(".resume-item");
        if (firstResume) {
            firstResume.click();
        }
        
        const firstClearance = document.querySelector(".clearance-item");
        if (firstClearance) {
            firstClearance.click();
        }
    });
</script>
';

// Set page content
$content = '
<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">Apply for Job</h1>
        <p class="text-gray-600">' . htmlspecialchars($job['title']) . ' at ' . htmlspecialchars($job['company_name']) . '</p>
    </div>
    <a href="jobs.php" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-md hover:bg-gray-200 transition duration-300 flex items-center">
        <i class="fas fa-arrow-left mr-2"></i> Back to Jobs
    </a>
</div>';

if ($message) {
    $content .= '
<div class="mb-6 p-4 rounded-md ' . ($messageType === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800') . '">
    <div class="flex">
        <div class="flex-shrink-0">
            <i class="fas ' . ($messageType === 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-circle text-red-400') . '"></i>
        </div>
        <div class="ml-3">
            <p class="text-sm font-medium">' . $message . '</p>
        </div>
    </div>
</div>';
}

$content .= '
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <!-- Job Details -->
    <div class="md:col-span-1">
        <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">Job Details</h2>
            
            <div class="space-y-4">
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Company</h3>
                    <p class="mt-1 text-gray-900">' . htmlspecialchars($job['company_name']) . '</p>
                </div>
                
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Location</h3>
                    <p class="mt-1 text-gray-900">' . htmlspecialchars($job['location']) . '</p>
                </div>
                
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Job Type</h3>
                    <p class="mt-1 text-gray-900">' . htmlspecialchars($job['job_type']) . '</p>
                </div>
                
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Salary</h3>
                    <p class="mt-1 text-gray-900">₱' . number_format($job['salary_min'], 2) . ' (' .
    ($job['pay_type'] === 'hourly' ? 'Hourly Rate' : ($job['pay_type'] === 'monthly' ? 'Monthly Salary' : 'Annual Salary')) . ')</p>
                </div>
                
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Required Documents</h3>
                    <p class="mt-1 text-gray-900">' .
    str_replace(['resume', 'police_clearance', 'none'], ['Resume', 'Police Clearance', 'None'], $job['required_documents']) .
    '</p>
                </div>
                
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Posted By</h3>
                    <p class="mt-1 text-gray-900">' . htmlspecialchars($job['first_name'] . ' ' . $job['last_name']) . '</p>
                </div>
                
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Posted On</h3>
                    <p class="mt-1 text-gray-900">' . date('F j, Y', strtotime($job['created_at'])) . '</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Application Form -->
    <div class="md:col-span-2">
        <div class="bg-white rounded-lg shadow-sm p-6">';
            
if ($alreadyApplied) {
    $content .= '
            <div class="text-center py-8">
                <div class="text-green-500 mb-4">
                    <i class="fas fa-check-circle text-5xl"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-1">Application Submitted</h3>
                <p class="text-gray-500 mb-4">You have already applied for this job.</p>
                <a href="my-applications.php" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-300">
                    View My Applications
                </a>
            </div>';
} else {
    $content .= '
            <h2 class="text-lg font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">Application Form</h2>
            <form method="POST" action="" enctype="multipart/form-data" class="space-y-6">
                <!-- Cover Letter -->
                <div>
                    <label for="cover_letter" class="block text-sm font-medium text-gray-700 mb-1">Cover Letter</label>
                    <textarea id="cover_letter" name="cover_letter" rows="6"
                        class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 form-input"
                        placeholder="Tell the employer why you\'re a good fit for this position..."></textarea>
                    <p class="text-xs text-gray-500 mt-1">Introduce yourself and explain why you\'re interested in this role.</p>
                </div>';
                
    // Resume section
    if (strpos($job['required_documents'], 'resume') !== false && strpos($job['required_documents'], 'none') === false) {
        $content .= '
                <!-- Resume Upload -->
                <div>
                    <label for="resume" class="block text-sm font-medium text-gray-700 mb-1">Resume *</label>';
        
        if ($hasResumes) {
            $content .= '
                    <div class="mb-2">
                        <label class="inline-flex items-center text-sm">
                            <input type="checkbox" name="use_existing_resume" value="1" class="form-checkbox h-4 w-4 text-blue-600" checked>
                            <span class="ml-2">Use my pre-uploaded resume</span>
                        </label>
                    </div>
                    <div id="resume-selector" class="mb-4">
                        <input type="hidden" name="selected_resume" value="">
                        <div class="document-selector-container">';
            
            foreach ($resumes as $resume) {
                $content .= '
                            <div class="document-selector resume-item" data-id="' . $resume['id'] . '">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <span class="font-medium">' . htmlspecialchars($resume['original_name']) . '</span>
                                        ' . ($resume['is_default'] ? '<span class="ml-2 px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full">Default</span>' : '') . '
                                        <p class="text-xs text-gray-500">Uploaded on ' . date('F j, Y', strtotime($resume['created_at'])) . '</p>
                                    </div>
                                </div>
                            </div>';
            }
            
            $content .= '
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mb-2">Or upload a new resume:</p>';
        }
        
        $content .= '
                    <input type="file" id="resume" name="resume" accept=".pdf,.doc,.docx"
                        class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 form-input" ' . (empty($resumes) ? 'required' : '') . '>
                    <p class="text-xs text-gray-500 mt-1">Upload your resume (PDF, DOC, or DOCX format).</p>
                </div>';
    }
    
    // Police Clearance section
    if (strpos($job['required_documents'], 'police_clearance') !== false && strpos($job['required_documents'], 'none') === false) {
        $content .= '
                <!-- Police Clearance Upload -->
                <div>
                    <label for="police_clearance" class="block text-sm font-medium text-gray-700 mb-1">Police/Barangay Clearance *</label>';
        
        if ($hasClearances) {
            $content .= '
                    <div class="mb-2">
                        <label class="inline-flex items-center text-sm">
                            <input type="checkbox" name="use_existing_clearance" value="1" class="form-checkbox h-4 w-4 text-blue-600" checked>
                            <span class="ml-2">Use my pre-uploaded clearance</span>
                        </label>
                    </div>
                    <div id="clearance-selector" class="mb-4">
                        <input type="hidden" name="selected_clearance" value="">
                        <div class="document-selector-container">';
            
            foreach ($clearances as $clearance) {
                $content .= '
                            <div class="document-selector clearance-item" data-id="' . $clearance['id'] . '">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <span class="font-medium">' . htmlspecialchars($clearance['original_name']) . '</span>
                                        ' . ($clearance['is_default'] ? '<span class="ml-2 px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full">Default</span>' : '') . '
                                        <p class="text-xs text-gray-500">Uploaded on ' . date('F j, Y', strtotime($clearance['created_at'])) . '</p>
                                    </div>
                                </div>
                            </div>';
            }
            
            $content .= '
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mb-2">Or upload a new clearance:</p>';
        }
        
        $content .= '
                    <input type="file" id="police_clearance" name="police_clearance" accept=".pdf,.jpg,.jpeg,.png"
                        class="w-full px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 form-input" ' . (empty($clearances) ? 'required' : '') . '>
                    <p class="text-xs text-gray-500 mt-1">Upload your police/barangay clearance (PDF, JPG, JPEG, or PNG format).</p>
                </div>';
    }
    
    $content .= '
                <!-- Submit Button -->
                <div class="pt-4">
                    <button type="submit" class="w-full md:w-auto px-6 py-3 bg-blue-600 text-white font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition duration-300">
                        Submit Application
                    </button>
                </div>
            </form>';
}

$content .= '
        </div>
    </div>
</div>';

// Add Document Preview Section
if (!$alreadyApplied) {
    $content .= '
    <div class="main-preview-container mt-8" id="document-previews">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Document Previews</h2>';
    
    // Resume Preview Section
    if (strpos($job['required_documents'], 'resume') !== false && strpos($job['required_documents'], 'none') === false && $hasResumes) {
        $content .= '
        <div class="preview-section" id="resume-preview-section">
            <h3 class="preview-title">Resume Preview</h3>
            <div class="document-preview-container">';
        
        foreach ($resumes as $resume) {
            $fileExt = strtolower(pathinfo($resume['original_name'], PATHINFO_EXTENSION));
            $fileUrl = '../../../uploads/jobseeker/' . $_SESSION['user_id'] . '/documents/resumes/' . $resume['filename'];
            
            if ($fileExt === 'pdf') {
                $content .= '
                <div class="document-preview resume-preview pdf-preview" data-id="' . $resume['id'] . '" data-pdf-url="' . $fileUrl . '">
                    <div class="flex justify-center items-center h-full">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
                        <span class="ml-2">Loading PDF preview...</span>
                    </div>
                </div>';
            } else {
                $content .= '
                <div class="document-preview resume-preview" data-id="' . $resume['id'] . '">
                    <div class="flex justify-center items-center h-full">
                        <p>Document preview not available for this file type.</p>
                        <a href="' . $fileUrl . '" target="_blank" class="ml-2 text-blue-600 hover:underline">Download</a>
                    </div>
                </div>';
            }
        }
        
        $content .= '
            </div>
        </div>';
    }
    
    // Clearance Preview Section
    if (strpos($job['required_documents'], 'police_clearance') !== false && strpos($job['required_documents'], 'none') === false && $hasClearances) {
        $content .= '
        <div class="preview-section" id="clearance-preview-section">
            <h3 class="preview-title">Clearance Preview</h3>
            <div class="document-preview-container">';
        
        foreach ($clearances as $clearance) {
            $fileUrl = '../../../uploads/jobseeker/' . $_SESSION['user_id'] . '/documents/police_clearance/' . $clearance['filename'];
            
            if (strpos($clearance['file_type'], 'application/pdf') !== false) {
                $content .= '
                <div class="document-preview clearance-preview pdf-preview" data-id="' . $clearance['id'] . '" data-pdf-url="' . $fileUrl . '">
                    <div class="flex justify-center items-center h-full">
                        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
                        <span class="ml-2">Loading PDF preview...</span>
                    </div>
                </div>';
            } else if (strpos($clearance['file_type'], 'image/') !== false) {
                $content .= '
                <div class="document-preview clearance-preview" data-id="' . $clearance['id'] . '">
                    <img src="' . $fileUrl . '" alt="Clearance Preview">
                </div>';
            } else {
                $content .= '
                <div class="document-preview clearance-preview" data-id="' . $clearance['id'] . '">
                    <div class="flex justify-center items-center h-full">
                        <p>Document preview not available for this file type.</p>
                        <a href="' . $fileUrl . '" target="_blank" class="ml-2 text-blue-600 hover:underline">Download</a>
                    </div>
                </div>';
            }
        }
        
        $content .= '
            </div>
        </div>';
    }
    
    $content .= '
    </div>';
}

// Include layout
include_once 'nav/layout.php';
