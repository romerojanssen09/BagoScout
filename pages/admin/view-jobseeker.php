<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/mail.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$success = '';
$error = '';

// Check if jobseeker ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: jobseekers.php");
    exit();
}

$jobseekerId = (int)$_GET['id'];

// Process approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['approve_user'])) {
        // Update user status to active
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->bind_param("i", $jobseekerId);
        
        if ($stmt->execute()) {
            // Get user email for notification
            $emailStmt = $conn->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
            $emailStmt->bind_param("i", $jobseekerId);
            $emailStmt->execute();
            $result = $emailStmt->get_result();
            $user = $result->fetch_assoc();
            
            // Send approval email
            $subject = "BagoScout - Account Approved";
            $message = "Dear " . $user['first_name'] . " " . $user['last_name'] . ",\n\n";
            $message .= "Your BagoScout job seeker account has been approved. You now have full access to all features.\n\n";
            $message .= "Thank you for joining BagoScout!\n\n";
            $message .= "Best regards,\nThe BagoScout Team";
            
            // Use the sendNotificationEmail function for HTML formatting
            sendNotificationEmail(
                $user['email'], 
                $user['first_name'] . ' ' . $user['last_name'], 
                $subject, 
                "Your BagoScout job seeker account has been approved! You now have full access to all features.",
                "Login Now",
                "https://" . $_SERVER['HTTP_HOST'] . "/bagoscout/pages/auth-user/seeker/dashboard.php"
            );
            
            // Create notification for the user
            createNotification(
                $jobseekerId, 
                'status_change', 
                'Your account has been approved. You now have full access to all features.'
            );
            
            $success = "Job seeker approved successfully";
            
            // Execute JavaScript to trigger realtime notification before redirect
            echo "<script src='/bagoscout/assets/js/admin-realtime.js'></script>";
            echo "<script>
                publishUserStatusUpdate(
                    $jobseekerId, 
                    'approve', 
                    'Your account has been approved!'
                );
                window.location.href = 'view-jobseeker.php?id=$jobseekerId&success=approved';
            </script>";
            exit();
        } else {
            $error = "Failed to approve job seeker";
        }
        
        $stmt->close();
        $conn->close();
    } elseif (isset($_POST['reject_user'])) {
        $rejectReason = $_POST['reject_reason'];
        
        // Update user status to rejected
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE users SET status = 'rejected' WHERE id = ?");
        $stmt->bind_param("i", $jobseekerId);
        
        if ($stmt->execute()) {
            // Get user email and details for notification
            $emailStmt = $conn->prepare("SELECT u.email, u.first_name, u.last_name, j.facephoto, j.valid_id 
                FROM users u 
                JOIN jobseekers j ON u.id = j.user_id 
                WHERE u.id = ?");
            $emailStmt->bind_param("i", $jobseekerId);
            $emailStmt->execute();
            $result = $emailStmt->get_result();
            $user = $result->fetch_assoc();
            
            // Store the file paths before removing them
            $facephoto = $user['facephoto'];
            $validId = $user['valid_id'];
            
            // Clear the facephoto and valid_id fields
            $clearDocsStmt = $conn->prepare("UPDATE jobseekers SET facephoto = NULL, valid_id = NULL WHERE user_id = ?");
            $clearDocsStmt->bind_param("i", $jobseekerId);
            $clearDocsStmt->execute();
            $clearDocsStmt->close();
            
            // Delete the actual files if they exist
            if (!empty($facephoto)) {
                $filePath = str_replace("../../../", "../../", $facephoto);
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
            
            if (!empty($validId)) {
                $idPath = str_replace("../../../", "../../", $validId);
                if (file_exists($idPath)) {
                    unlink($idPath);
                }
            }
            
            // Send rejection email with HTML formatting
            $subject = "BagoScout - Account Verification Issue";
            
            // Use the sendNotificationEmail function for HTML formatting
            sendNotificationEmail(
                $user['email'], 
                $user['first_name'] . ' ' . $user['last_name'], 
                $subject, 
                "We regret to inform you that your BagoScout job seeker account verification could not be completed. Please update your profile with the required information and submit for approval again.<br><br><strong>Reason:</strong> " . htmlspecialchars($rejectReason),
                "Update Profile",
                "https://" . $_SERVER['HTTP_HOST'] . "/bagoscout/pages/auth-user/seeker/settings.php?status=reject"
            );
            
            // Create notification for the user
            createNotification(
                $jobseekerId, 
                'status_change', 
                'Your account verification could not be completed. Please check your email for details.'
            );
            
            $success = "Job seeker rejected successfully";
            
            // Execute JavaScript to trigger realtime notification before redirect
            echo "<script src='/bagoscout/assets/js/admin-realtime.js'></script>";
            echo "<script>
                publishUserStatusUpdate(
                    $jobseekerId, 
                    'reject', 
                    'Your account verification could not be completed',
                    '" . addslashes($rejectReason) . "'
                );
                window.location.href = 'view-jobseeker.php?id=$jobseekerId&success=rejected';
            </script>";
            exit();
        } else {
            $error = "Failed to reject job seeker";
        }
        
        $stmt->close();
        $conn->close();
    }
}

// Get jobseeker details
$conn = getDbConnection();
$stmt = $conn->prepare("
    SELECT u.*, j.*
    FROM users u
    JOIN jobseekers j ON u.id = j.user_id
    WHERE u.id = ? AND u.role = 'jobseeker'
");
$stmt->bind_param("i", $jobseekerId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $error = "Job seeker not found";
    $jobseeker = null;
} else {
    $jobseeker = $result->fetch_assoc();
}

$stmt->close();
$conn->close();

// Check for success parameter in URL
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'approved') {
        $success = "Job seeker approved successfully";
    } elseif ($_GET['success'] === 'rejected') {
        $success = "Job seeker rejected successfully";
    }
}

// Set page title
$pageTitle = "Job Seeker Details";

// Include the common admin header
include '../imports/admin-header.php';
?>

<!-- Main Content -->
<div class="flex-1 p-8 md:ml-64">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Job Seeker Details</h1>
        <div class="flex space-x-2">
            <a href="jobseekers.php" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 transition duration-200 flex items-center">
                <i class="fas fa-arrow-left mr-2"></i> Back to Job Seekers
            </a>
        </div>
    </div>
    
    <?php if (!empty($success)): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
            <p><?php echo $success; ?></p>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($error)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
            <p><?php echo $error; ?></p>
        </div>
    <?php endif; ?>
    
    <?php if ($jobseeker): ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column: Basic Info -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <div class="flex flex-col items-center text-center mb-6">
                    <?php if (!empty($jobseeker['facephoto']) && $jobseeker['facephoto'] !== 'default.jpg'): ?>
                        <?php 
                        // Remove the leading "../../../" from the path
                        $photoPath = str_replace("../../../", "../../", $jobseeker['facephoto']); 
                        ?>
                        <img class="h-32 w-32 rounded-full object-cover mb-4" src="<?php echo $photoPath; ?>" alt="">
                    <?php else: ?>
                        <div class="h-32 w-32 rounded-full bg-gray-300 flex items-center justify-center text-gray-500 text-4xl mb-4">
                            <?php echo strtoupper(substr($jobseeker['first_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <h2 class="text-xl font-semibold text-gray-800"><?php echo htmlspecialchars($jobseeker['first_name'] . ' ' . $jobseeker['last_name']); ?></h2>
                    <p class="text-gray-600">Job Seeker</p>
                    
                    <?php if ($jobseeker['status'] == 'under_review'): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 mt-2">
                            <svg class="-ml-0.5 mr-1.5 h-2 w-2 text-yellow-400" fill="currentColor" viewBox="0 0 8 8">
                                <circle cx="4" cy="4" r="3" />
                            </svg>
                            Under Review
                        </span>
                    <?php elseif ($jobseeker['status'] == 'active'): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 mt-2">
                            <svg class="-ml-0.5 mr-1.5 h-2 w-2 text-green-400" fill="currentColor" viewBox="0 0 8 8">
                                <circle cx="4" cy="4" r="3" />
                            </svg>
                            Account Verified
                        </span>
                    <?php elseif ($jobseeker['status'] == 'suspended'): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 mt-2">
                            <svg class="-ml-0.5 mr-1.5 h-2 w-2 text-red-400" fill="currentColor" viewBox="0 0 8 8">
                                <circle cx="4" cy="4" r="3" />
                            </svg>
                            Suspended
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="border-t border-gray-200 pt-4">
                    <div class="space-y-3">
                        <div class="flex items-center">
                            <i class="fas fa-envelope w-5 text-gray-500 mr-2"></i>
                            <span class="text-gray-800"><?php echo htmlspecialchars($jobseeker['email']); ?></span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-phone w-5 text-gray-500 mr-2"></i>
                            <span class="text-gray-800"><?php echo htmlspecialchars($jobseeker['phone']); ?></span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-calendar w-5 text-gray-500 mr-2"></i>
                            <span class="text-gray-800">Joined: <?php echo date('M d, Y', strtotime($jobseeker['created_at'])); ?></span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-clock w-5 text-gray-500 mr-2"></i>
                            <span class="text-gray-800">Last Login: 
                                <?php echo $jobseeker['last_login'] ? date('M d, Y H:i', strtotime($jobseeker['last_login'])) : 'Never'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($jobseeker['status'] == 'under_review'): ?>
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Account Approval</h3>
                
                <div class="space-y-4">
                    <button type="button" class="w-full px-4 py-2 bg-green-500 text-white font-medium rounded-md hover:bg-green-600 transition duration-300 approve-btn">
                        <i class="fas fa-check mr-2"></i> Approve Job Seeker
                    </button>
                    
                    <button type="button" class="w-full px-4 py-2 bg-red-500 text-white font-medium rounded-md hover:bg-red-600 transition duration-300 reject-btn">
                        <i class="fas fa-times mr-2"></i> Reject Job Seeker
                    </button>
                </div>
            </div>
            <?php elseif ($jobseeker['status'] == 'active'): ?>
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Account Actions</h3>
                
                <button type="button" class="w-full px-4 py-2 bg-red-500 text-white font-medium rounded-md hover:bg-red-600 transition duration-300 reject-btn">
                    <i class="fas fa-ban mr-2"></i> Suspend Account
                </button>
            </div>
            <?php elseif ($jobseeker['status'] == 'suspended'): ?>
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Account Actions</h3>
                
                <button type="button" class="w-full px-4 py-2 bg-green-500 text-white font-medium rounded-md hover:bg-green-600 transition duration-300 approve-btn">
                    <i class="fas fa-check mr-2"></i> Reactivate Account
                </button>
            </div>
            <?php endif; ?>
            
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Documents</h3>
                
                <div class="space-y-4">
                    <?php if (!empty($jobseeker['facephoto']) && $jobseeker['facephoto'] !== 'default.jpg'): ?>
                        <div>
                            <h4 class="text-sm font-medium text-gray-700 mb-2">Profile Photo</h4>
                            <?php 
                            // Remove the leading "../../../" from the path
                            $photoPath = str_replace("../../../", "../../", $jobseeker['facephoto']); 
                            ?>
                            <a href="<?php echo $photoPath; ?>" target="_blank" class="block">
                                <img src="<?php echo $photoPath; ?>" alt="Profile Photo" class="w-full h-auto rounded-md border border-gray-200">
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($jobseeker['valid_id'])): ?>
                        <div>
                            <h4 class="text-sm font-medium text-gray-700 mb-2">Valid ID</h4>
                            <?php if (strtolower(pathinfo($jobseeker['valid_id'], PATHINFO_EXTENSION)) === 'pdf' || strpos($jobseeker['valid_id'], '.pdf') !== false): ?>
                                <div class="w-full bg-gray-100 flex flex-col items-center justify-center rounded-md border border-gray-300 p-4">
                                    <i class="fas fa-file-pdf text-red-500 text-4xl mb-2"></i>
                                    <span class="text-gray-700 mb-2">PDF Document</span>
                                    <?php 
                                    // Remove the leading "../../../" from the path
                                    $idPath = str_replace("../../../", "../../", $jobseeker['valid_id']); 
                                    ?>
                                    <a href="<?php echo $idPath; ?>" target="_blank" class="inline-flex items-center text-blue-600 hover:text-blue-800 px-3 py-1 border border-blue-600 rounded">
                                        <i class="fas fa-external-link-alt mr-1"></i> View Document
                                    </a>
                                </div>
                            <?php else: ?>
                                <?php 
                                // Remove the leading "../../../" from the path
                                $idPath = str_replace("../../../", "../../", $jobseeker['valid_id']); 
                                ?>
                                <a href="<?php echo $idPath; ?>" target="_blank" class="block">
                                    <img src="<?php echo $idPath; ?>" alt="Valid ID" class="w-full h-auto rounded-md border border-gray-200">
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Detailed Info -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Skills & Expertise</h3>
                
                <div class="mb-6">
                    <h4 class="text-sm font-medium text-gray-500 mb-2">Work Fields</h4>
                    <div class="flex flex-wrap">
                        <?php 
                        if (!empty($jobseeker['fields'])) {
                            $fields = explode(',', $jobseeker['fields']);
                            foreach ($fields as $field): ?>
                                <span class="inline-block bg-blue-100 text-blue-800 text-sm px-3 py-1 rounded-full mr-2 mb-2"><?php echo htmlspecialchars(trim($field)); ?></span>
                            <?php endforeach;
                        } else {
                            echo '<p class="text-gray-500">No work fields specified</p>';
                        }
                        ?>
                    </div>
                </div>
                
                <div>
                    <h4 class="text-sm font-medium text-gray-500 mb-2">Skills</h4>
                    <div class="flex flex-wrap">
                        <?php 
                        if (!empty($jobseeker['skills'])) {
                            $skills = explode(',', $jobseeker['skills']);
                            foreach ($skills as $skill): ?>
                                <span class="inline-block bg-green-100 text-green-800 text-sm px-3 py-1 rounded-full mr-2 mb-2"><?php echo htmlspecialchars(trim($skill)); ?></span>
                            <?php endforeach;
                        } else {
                            echo '<p class="text-gray-500">No skills specified</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Applications</h3>
                
                <?php
                // Check if applications table exists
                $conn = getDbConnection();
                $result = $conn->query("SHOW TABLES LIKE 'applications'");
                $tableExists = $result->num_rows > 0;
                
                if ($tableExists) {
                    // Get job applications for this jobseeker
                    $stmt = $conn->prepare("
                        SELECT a.*, j.title as job_title, e.company_name
                        FROM applications a
                        JOIN jobs j ON a.job_id = j.id
                        JOIN employers e ON j.employer_id = e.user_id
                        WHERE a.jobseeker_id = ?
                        ORDER BY a.created_at DESC
                    ");
                    $stmt->bind_param("i", $jobseekerId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $applications = $result->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                } else {
                    $applications = [];
                }
                $conn->close();
                ?>
                
                <?php if (!$tableExists || empty($applications)): ?>
                    <p class="text-gray-600">This job seeker has not applied to any jobs yet.</p>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($applications as $application): ?>
                            <div class="border border-gray-200 rounded-md p-4">
                                <h4 class="text-lg font-medium text-gray-800"><?php echo htmlspecialchars($application['job_title']); ?></h4>
                                <p class="text-gray-600"><?php echo htmlspecialchars($application['company_name']); ?></p>
                                <div class="flex flex-wrap gap-2 mt-2">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        Status: <?php echo htmlspecialchars(ucfirst($application['status'])); ?>
                                    </span>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                        Applied: <?php echo date('M d, Y', strtotime($application['created_at'])); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Activity Log</h3>
                
                <div class="space-y-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                                <i class="fas fa-user-plus text-blue-500"></i>
                            </div>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-900">Account Created</p>
                            <p class="text-sm text-gray-500"><?php echo date('M d, Y H:i', strtotime($jobseeker['created_at'])); ?></p>
                        </div>
                    </div>
                    
                    <?php if ($jobseeker['status'] == 'active'): ?>
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <div class="h-8 w-8 rounded-full bg-green-100 flex items-center justify-center">
                                <i class="fas fa-check text-green-500"></i>
                            </div>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-900">Account Approved</p>
                            <p class="text-sm text-gray-500"><?php echo date('M d, Y H:i', strtotime($jobseeker['updated_at'])); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($jobseeker['last_login']): ?>
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <div class="h-8 w-8 rounded-full bg-purple-100 flex items-center justify-center">
                                <i class="fas fa-sign-in-alt text-purple-500"></i>
                            </div>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-900">Last Login</p>
                            <p class="text-sm text-gray-500"><?php echo date('M d, Y H:i', strtotime($jobseeker['last_login'])); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="/bagoscout/assets/js/admin-realtime.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Add event listener to approve button
        const approveBtn = document.querySelector('.approve-btn');
        if (approveBtn) {
            approveBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                Swal.fire({
                    title: 'Confirm Approval',
                    text: 'Are you sure you want to approve this job seeker account?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#10B981', // Green-500
                    cancelButtonColor: '#9CA3AF', // Gray-400
                    confirmButtonText: 'Approve',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Create and submit form
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'view-jobseeker.php?id=<?php echo $jobseekerId; ?>';
                        
                        const approveInput = document.createElement('input');
                        approveInput.type = 'hidden';
                        approveInput.name = 'approve_user';
                        approveInput.value = '1';
                        
                        form.appendChild(approveInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        }
        
        // Add event listener to reject button
        const rejectBtn = document.querySelector('.reject-btn');
        if (rejectBtn) {
            rejectBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                Swal.fire({
                    title: 'Confirm Rejection',
                    text: 'Are you sure you want to reject this job seeker account?',
                    input: 'textarea',
                    inputLabel: 'Reason for rejection',
                    inputPlaceholder: 'Enter your reason for rejecting this user...',
                    inputAttributes: {
                        'aria-label': 'Reason for rejection',
                        'required': 'required'
                    },
                    inputValidator: (value) => {
                        if (!value) {
                            return 'You need to provide a reason for rejection!';
                        }
                    },
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#EF4444', // Red-500
                    cancelButtonColor: '#9CA3AF', // Gray-400
                    confirmButtonText: 'Reject',
                    cancelButtonText: 'Cancel',
                    footer: '<small class="text-gray-500">This reason will be sent to the user.</small>'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Create and submit form
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'view-jobseeker.php?id=<?php echo $jobseekerId; ?>';
                        
                        const rejectInput = document.createElement('input');
                        rejectInput.type = 'hidden';
                        rejectInput.name = 'reject_user';
                        rejectInput.value = '1';
                        
                        const reasonInput = document.createElement('input');
                        reasonInput.type = 'hidden';
                        reasonInput.name = 'reject_reason';
                        reasonInput.value = result.value;
                        
                        form.appendChild(rejectInput);
                        form.appendChild(reasonInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        }
        
        // Handle form submissions with SweetAlert loading state
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                // Do not prevent default, let the form submit normally
                
                // Show loading state with SweetAlert if available
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'Processing...',
                        text: 'Please wait while we process your request.',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                }
            });
        });
    });
</script>