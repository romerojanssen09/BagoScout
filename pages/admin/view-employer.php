<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$success = '';
$error = '';

// Check if employer ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: employers.php");
    exit();
}

$employerId = (int)$_GET['id'];

// Process approval/rejection
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['approve_user'])) {
        // Update user status to active
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->bind_param("i", $employerId);
        
        if ($stmt->execute()) {
            // Get user email for notification
            $emailStmt = $conn->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
            $emailStmt->bind_param("i", $employerId);
            $emailStmt->execute();
            $result = $emailStmt->get_result();
            $user = $result->fetch_assoc();
            
            // Send approval email with HTML formatting
                $subject = "BagoScout - Account Approved";
            
            // Use the sendNotificationEmail function for HTML formatting
            sendNotificationEmail(
                $user['email'], 
                $user['first_name'] . ' ' . $user['last_name'], 
                $subject, 
                "Your BagoScout employer account has been approved! You now have full access to all features.",
                "Login Now",
                "https://" . $_SERVER['HTTP_HOST'] . "/bagoscout/pages/auth-user/employer/dashboard.php"
            );
            
            // Create notification for the user
            createNotification(
                $employerId, 
                'status_change', 
                'Your account has been approved. You now have full access to all features.'
            );
            
            $success = "Employer approved successfully";
            
            // Execute JavaScript to trigger realtime notification before redirect
            echo "<script src='/bagoscout/assets/js/admin-realtime.js'></script>";
            echo "<script>
                publishUserStatusUpdate(
                    $employerId, 
                    'approve', 
                    'Your account has been approved!'
                );
                window.location.href = 'view-employer.php?id=$employerId&success=approved';
            </script>";
            exit();
        } else {
            $error = "Failed to approve employer";
        }
        
        $stmt->close();
        $conn->close();
    } elseif (isset($_POST['reject_user'])) {
        $rejectReason = $_POST['reject_reason'];
        
        // Update user status to rejected
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE users SET status = 'rejected' WHERE id = ?");
        $stmt->bind_param("i", $employerId);
        
        if ($stmt->execute()) {
            // Get user email and details for notification
            $emailStmt = $conn->prepare("SELECT u.email, u.first_name, u.last_name, e.facephoto, e.valid_id 
                FROM users u 
                JOIN employers e ON u.id = e.user_id 
                WHERE u.id = ?");
            $emailStmt->bind_param("i", $employerId);
            $emailStmt->execute();
            $result = $emailStmt->get_result();
            $user = $result->fetch_assoc();
            
            // Store the file paths before removing them
            $facephoto = $user['facephoto'];
            $validId = $user['valid_id'];
            
            // Clear the facephoto and valid_id fields
            $clearDocsStmt = $conn->prepare("UPDATE employers SET facephoto = NULL, valid_id = NULL WHERE user_id = ?");
            $clearDocsStmt->bind_param("i", $employerId);
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
                "We regret to inform you that your BagoScout employer account verification could not be completed. Please update your profile with the required information and submit for approval again.<br><br><strong>Reason:</strong> " . htmlspecialchars($rejectReason),
                "Update Profile",
                "https://" . $_SERVER['HTTP_HOST'] . "/bagoscout/pages/auth-user/employer/settings.php?status=reject"
            );
            
            // Create notification for the user
            createNotification(
                $employerId, 
                'status_change', 
                'Your account verification could not be completed. Please check your email for details.'
            );
            
            $success = "Employer rejected successfully";
            
            // Execute JavaScript to trigger realtime notification before redirect
            echo "<script src='/bagoscout/assets/js/admin-realtime.js'></script>";
            echo "<script>
                publishUserStatusUpdate(
                    $employerId, 
                    'reject', 
                    'Your account verification could not be completed',
                    '" . addslashes($rejectReason) . "'
                );
                window.location.href = 'view-employer.php?id=$employerId&success=rejected';
            </script>";
            exit();
        } else {
            $error = "Failed to reject employer";
        }
        
        $stmt->close();
        $conn->close();
    }
}

// Get employer details
$conn = getDbConnection();
$stmt = $conn->prepare("
    SELECT u.*, e.*
    FROM users u
    JOIN employers e ON u.id = e.user_id
    WHERE u.id = ? AND u.role = 'employer'
");
$stmt->bind_param("i", $employerId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $error = "Employer not found";
    $employer = null;
} else {
    $employer = $result->fetch_assoc();
}

$stmt->close();
$conn->close();

// Check for success parameter in URL
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'approved') {
        $success = "Employer approved successfully";
    } elseif ($_GET['success'] === 'rejected') {
        $success = "Employer rejected successfully";
    }
}

// Set page title
$pageTitle = "Employer Details";

// Include admin header
include '../imports/admin-header.php';
?>

<!-- Main Content -->
<div class="p-4 md:p-6 lg:p-8 md:ml-64">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-4 md:mb-0">Employer Details</h1>
        <div class="flex space-x-2">
            <a href="employers.php" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 transition duration-200 flex items-center">
                <i class="fas fa-arrow-left mr-2"></i> Back to Employers
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
    
    <?php if ($employer): ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left Column - Profile Info -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow-sm p-4 md:p-6">
                    <div class="flex flex-col items-center mb-6">
                        <?php if (!empty($employer['facephoto']) && file_exists('../../' . $employer['facephoto'])): ?>
                            <?php 
                            // Remove the leading "../../../" from the path
                            $photoPath = str_replace("../../../", "../../", $employer['facephoto']); 
                            ?>
                            <img src="<?php echo $photoPath; ?>" alt="Profile Photo" class="h-32 w-32 rounded-full object-cover border-4 border-blue-100 mb-4">
                        <?php else: ?>
                            <div class="h-32 w-32 rounded-full bg-blue-100 flex items-center justify-center text-blue-500 text-4xl font-bold mb-4">
                                <?php echo strtoupper(substr($employer['first_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        
                        <h2 class="text-xl font-semibold text-gray-800"><?php echo htmlspecialchars($employer['first_name'] . ' ' . $employer['last_name']); ?></h2>
                        <p class="text-gray-600 mb-2"><?php echo htmlspecialchars($employer['email']); ?></p>
                        <p class="text-gray-600"><?php echo htmlspecialchars($employer['phone']); ?></p>
                        
                        <div class="mt-4">
                            <?php if ($employer['status'] == 'pending'): ?>
                                <span class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded-full text-sm font-medium">Pending Approval</span>
                            <?php elseif ($employer['status'] == 'active'): ?>
                                <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-medium">Active</span>
                            <?php elseif ($employer['status'] == 'suspended'): ?>
                                <span class="px-3 py-1 bg-red-100 text-red-800 rounded-full text-sm font-medium">Suspended</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="border-t border-gray-200 pt-4">
                        <h3 class="text-lg font-medium text-gray-800 mb-3">Account Information</h3>
                        
                        <div class="space-y-3">
                            <div>
                                <p class="text-sm text-gray-500">Account Type</p>
                                <p class="font-medium">Employer</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Member Since</p>
                                <p class="font-medium"><?php echo date('F j, Y', strtotime($employer['created_at'])); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Last Login</p>
                                <p class="font-medium"><?php echo !empty($employer['last_login']) ? date('F j, Y g:i A', strtotime($employer['last_login'])) : 'Never'; ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($employer['status'] == 'under_review'): ?>
                    <div class="border-t border-gray-200 pt-4 mt-4">
                        <h3 class="text-lg font-medium text-gray-800 mb-3">Actions</h3>
                        
                        <div class="flex flex-col space-y-2">
                            <button type="button" class="px-4 py-2 bg-green-500 text-white rounded-md hover:bg-green-600 transition duration-200 flex items-center justify-center" data-target="approve-modal">
                                <i class="fas fa-check mr-2"></i> Approve Employer
                            </button>
                            <button type="button" class="px-4 py-2 bg-red-500 text-white rounded-md hover:bg-red-600 transition duration-200 flex items-center justify-center" data-target="reject-modal">
                                <i class="fas fa-times mr-2"></i> Reject Employer
                            </button>
                        </div>
                    </div>
                    <?php elseif ($employer['status'] == 'active'): ?>
                    <div class="border-t border-gray-200 pt-4 mt-4">
                        <h3 class="text-lg font-medium text-gray-800 mb-3">Actions</h3>
                        
                        <div class="flex flex-col space-y-2">
                            <button type="button" class="px-4 py-2 bg-red-500 text-white rounded-md hover:bg-red-600 transition duration-200 flex items-center justify-center" data-target="suspend-modal">
                                <i class="fas fa-ban mr-2"></i> Suspend Employer
                            </button>
                        </div>
                    </div>
                    <?php elseif ($employer['status'] == 'suspended'): ?>
                    <div class="border-t border-gray-200 pt-4 mt-4">
                        <h3 class="text-lg font-medium text-gray-800 mb-3">Actions</h3>
                        
                        <div class="flex flex-col space-y-2">
                            <button type="button" class="px-4 py-2 bg-green-500 text-white rounded-md hover:bg-green-600 transition duration-200 flex items-center justify-center" data-target="activate-modal">
                                <i class="fas fa-check mr-2"></i> Activate Employer
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Right Column - Detailed Info -->
            <div class="lg:col-span-2">
                <!-- Company Information -->
                <div class="bg-white rounded-lg shadow-sm p-4 md:p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Company Information</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <p class="text-sm text-gray-500">Company Name</p>
                            <p class="font-medium"><?php echo htmlspecialchars($employer['company_name']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Company Type</p>
                            <p class="font-medium"><?php echo htmlspecialchars($employer['company_type']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Role in Company</p>
                            <p class="font-medium"><?php echo htmlspecialchars($employer['role_in_company']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Company Url</p>
                            <p class="font-medium"><a class="text-blue-600 hover:underline" href="<?php echo htmlspecialchars($employer['company_url']); ?>" target="_blank">Employer's Company Website</a></p>
                        </div>
                    </div>
                    <div class="mt-6">
                        <p class="text-sm text-gray-500">Industry Fields</p>
                        <div class="flex flex-wrap mt-1">
                            <?php 
                            if (!empty($employer['fields'])) {
                                $fields = explode(',', $employer['fields']);
                                foreach ($fields as $field) {
                                    echo '<span class="inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full mr-2 mb-2">' . htmlspecialchars(trim($field)) . '</span>';
                                }
                            } else {
                                echo '<p>No fields specified</p>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <!-- Documents -->
                <div class="bg-white rounded-lg shadow-sm p-4 md:p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Verification Documents</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <p class="text-sm text-gray-500 mb-2">Profile Photo</p>
                            <?php if (!empty($employer['facephoto']) && file_exists('../' . $employer['facephoto'])): ?>
                                <div class="relative group">
                                    <?php 
                                    // Remove the leading "../../../" from the path
                                    $photoPath = str_replace("../../../", "../../", $employer['facephoto']); 
                                    ?>
                                    <img src="<?php echo $photoPath; ?>" alt="Profile Photo" class="w-full h-48 object-cover rounded-md">
                                    <a href="<?php echo $photoPath; ?>" target="_blank" class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-50 opacity-0 group-hover:opacity-100 transition duration-200 rounded-md">
                                        <span class="text-white"><i class="fas fa-eye mr-2"></i> View Full Size</span>
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="w-full h-48 bg-gray-200 flex items-center justify-center rounded-md">
                                    <span class="text-gray-500">No profile photo uploaded</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <p class="text-sm text-gray-500 mb-2">Valid ID</p>
                            <?php if (!empty($employer['valid_id']) && file_exists('../' . $employer['valid_id'])): ?>
                                <?php if (strtolower(pathinfo($employer['valid_id'], PATHINFO_EXTENSION)) === 'pdf' || strpos($employer['valid_id'], '.pdf') !== false): ?>
                                <div class="w-full h-48 bg-gray-100 flex flex-col items-center justify-center rounded-md border border-gray-300">
                                    <i class="fas fa-file-pdf text-red-500 text-4xl mb-2"></i>
                                    <span class="text-gray-700">PDF Document</span>
                                    <a href="../<?php echo htmlspecialchars($employer['valid_id']); ?>" target="_blank" class="mt-3 inline-flex items-center text-blue-600 hover:text-blue-800 px-3 py-1 border border-blue-600 rounded">
                                        <i class="fas fa-external-link-alt mr-1"></i> View Document
                                    </a>
                                </div>
                                <?php else: ?>
                                <div class="relative group">
                                    <img src="../<?php echo htmlspecialchars($employer['valid_id']); ?>" alt="Valid ID" class="w-full h-48 object-cover rounded-md">
                                    <a href="../<?php echo htmlspecialchars($employer['valid_id']); ?>" target="_blank" class="absolute inset-0 flex items-center justify-center bg-black bg-opacity-50 opacity-0 group-hover:opacity-100 transition duration-200 rounded-md">
                                        <span class="text-white"><i class="fas fa-eye mr-2"></i> View Full Size</span>
                                    </a>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="w-full h-48 bg-gray-200 flex items-center justify-center rounded-md">
                                    <span class="text-gray-500">No valid ID uploaded</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Additional Information -->
                <div class="bg-white rounded-lg shadow-sm p-4 md:p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Additional Information</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <p class="text-sm text-gray-500">Address</p>
                            <p class="font-medium"><?php echo !empty($employer['address']) ? htmlspecialchars($employer['address']) : 'Not provided'; ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Website</p>
                            <?php if (!empty($employer['company_url'])): ?>
                                <a href="<?php echo htmlspecialchars($employer['company_url']); ?>" target="_blank" class="text-blue-600 hover:underline"><?php echo htmlspecialchars($employer['company_url']); ?></a>
                            <?php else: ?>
                                <p>Not provided</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-lg shadow-sm p-6 text-center">
            <i class="fas fa-exclamation-triangle text-yellow-500 text-5xl mb-4"></i>
            <h2 class="text-xl font-semibold text-gray-800 mb-2">Employer Not Found</h2>
            <p class="text-gray-600 mb-4">The employer you are looking for does not exist or has been removed.</p>
            <a href="employers.php" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition duration-200">
                Return to Employers List
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Approve Modal -->
<div id="approve-modal" class="fixed inset-0 bg-gray-900 bg-opacity-50 items-center justify-center z-50 hidden">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-auto mt-20">
        <div class="px-6 py-4 border-b">
            <h3 class="text-lg font-semibold text-gray-800">Approve Employer</h3>
        </div>
        <div class="p-6">
            <p class="mb-4">Are you sure you want to approve this employer? This will grant them full access to the platform.</p>
            <form method="post">
                <input type="hidden" name="approve_user" value="1">
                
                <div class="flex justify-end space-x-3">
                    <button type="button" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 cancel-modal">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-green-500 text-white rounded-md hover:bg-green-600">Approve</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="reject-modal" class="fixed inset-0 bg-gray-900 bg-opacity-50 items-center justify-center z-50 hidden">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-auto mt-20">
        <div class="px-6 py-4 border-b">
            <h3 class="text-lg font-semibold text-gray-800">Reject Employer</h3>
        </div>
        <div class="p-6">
            <p class="mb-4">Please provide a reason for rejecting this employer application.</p>
            <form method="post">
                <div class="mb-4">
                    <label for="reject-reason" class="block text-sm font-medium text-gray-700 mb-1">Reason for rejection</label>
                    <textarea id="reject-reason" name="reject_reason" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500" required></textarea>
                    <p class="text-xs text-gray-500 mt-1">This reason will be sent to the user.</p>
                </div>
                
                <input type="hidden" name="reject_user" value="1">
                
                <div class="flex justify-end space-x-3">
                    <button type="button" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 cancel-modal">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-red-500 text-white rounded-md hover:bg-red-600">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="/bagoscout/assets/js/admin-realtime.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Image preview modal
        const previewModal = document.getElementById('image-preview-modal');
        const previewImage = document.getElementById('preview-image');
        const previewButtons = document.querySelectorAll('.preview-image');
        const closeButtons = document.querySelectorAll('.close-modal');
        
        previewButtons.forEach(button => {
            button.addEventListener('click', function() {
                const imageSrc = this.getAttribute('data-src');
                previewImage.src = imageSrc;
                previewModal.classList.remove('hidden');
                previewModal.classList.add('flex');
            });
        });
        
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                previewModal.classList.add('hidden');
                previewModal.classList.remove('flex');
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === previewModal) {
                previewModal.classList.add('hidden');
                previewModal.classList.remove('flex');
            }
        });
        
        // Add event listener to suspend button
        const suspendBtn = document.getElementById('suspend-btn');
        if (suspendBtn) {
            suspendBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                Swal.fire({
                    title: 'Suspend Employer',
                    text: 'Are you sure you want to suspend this employer? They will no longer be able to access the platform.',
                    input: 'textarea',
                    inputLabel: 'Reason for suspension',
                    inputPlaceholder: 'Enter your reason for suspending this employer...',
                    inputAttributes: {
                        'aria-label': 'Reason for suspension',
                        'required': 'required'
                    },
                    inputValidator: (value) => {
                        if (!value) {
                            return 'You need to provide a reason for suspension!';
                        }
                    },
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#EF4444', // Red-500
                    cancelButtonColor: '#9CA3AF', // Gray-400
                    confirmButtonText: 'Suspend',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Create and submit form
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'view-employer.php?id=<?php echo $employerId; ?>';
                        
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
        
        // Add event listener to activate button
        const activateBtn = document.getElementById('activate-btn');
        if (activateBtn) {
            activateBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                Swal.fire({
                    title: 'Activate Employer',
                    text: 'Are you sure you want to activate this employer? This will restore their access to the platform.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#10B981', // Green-500
                    cancelButtonColor: '#9CA3AF', // Gray-400
                    confirmButtonText: 'Activate',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Create and submit form
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'view-employer.php?id=<?php echo $employerId; ?>';
                        
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
    });
</script>