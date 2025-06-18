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

// Get admin information
$conn = getDbConnection();
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->bind_param("i", $_SESSION['admin_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: login.php");
    exit();
}

$admin = $result->fetch_assoc();

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic info update form
    if (isset($_POST['update_profile'])) {
        $firstName = sanitizeInput($_POST['first_name']);
        $lastName = sanitizeInput($_POST['last_name']);
        $phone = sanitizeInput($_POST['phone']);
        
        if (empty($firstName) || empty($lastName) || empty($phone)) {
            $error = "All fields are required";
        } else {
            // Update admin information
            $updateStmt = $conn->prepare("UPDATE admins SET first_name = ?, last_name = ?, phone = ? WHERE id = ?");
            $updateStmt->bind_param("sssi", $firstName, $lastName, $phone, $_SESSION['admin_id']);
            
            if ($updateStmt->execute()) {
                $success = "Profile updated successfully";
                // Update session variables if needed
                $_SESSION['admin_name'] = $firstName . ' ' . $lastName;
                // Refresh admin data
                $admin['first_name'] = $firstName;
                $admin['last_name'] = $lastName;
                $admin['phone'] = $phone;
            } else {
                $error = "Failed to update profile";
            }
        }
    }
    
    // Password change form
    if (isset($_POST['change_password'])) {
        $currentPassword = $_POST['current_password'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        // Get current password from database
        $pwdStmt = $conn->prepare("SELECT password FROM admins WHERE id = ?");
        $pwdStmt->bind_param("i", $_SESSION['admin_id']);
        $pwdStmt->execute();
        $currentHash = $pwdStmt->get_result()->fetch_assoc()['password'];
        
        if (!password_verify($currentPassword, $currentHash)) {
            $error = "Current password is incorrect";
        } elseif ($newPassword !== $confirmPassword) {
            $error = "New passwords do not match";
        } elseif (strlen($newPassword) < 8) {
            $error = "Password must be at least 8 characters long";
        } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || 
                  !preg_match('/[0-9]/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            $error = "Password must include uppercase, lowercase, number, and special character";
        } else {
            // Hash new password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // Update password
            $pwdUpdateStmt = $conn->prepare("UPDATE admins SET password = ? WHERE id = ?");
            $pwdUpdateStmt->bind_param("si", $hashedPassword, $_SESSION['admin_id']);
            
            if ($pwdUpdateStmt->execute()) {
                $success = "Password changed successfully";
            } else {
                $error = "Failed to change password";
            }
        }
    }
}

$conn->close();

// Set page title
$pageTitle = "Admin Profile | BagoScout";

// Include admin header
include '../imports/admin-header.php';
?>

<!-- Main Content -->
<div class="p-4 md:p-6 lg:p-8 md:ml-64">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-4 md:mb-0">Admin Profile Settings</h1>
        <a href="dashboard.php" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 transition duration-200 flex items-center">
            <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
        </a>
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
    
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Basic Information -->
        <div class="bg-white rounded-lg shadow-sm p-6 col-span-1">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Admin Basic Information</h2>
            
            <form action="profile.php" method="post">
                <div class="mb-4">
                    <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                    <input type="text" id="first_name" name="first_name" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($admin['first_name']); ?>" required>
                </div>
                
                <div class="mb-4">
                    <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                    <input type="text" id="last_name" name="last_name" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($admin['last_name']); ?>" required>
                </div>
                
                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                    <input type="email" id="email" class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-md" value="<?php echo htmlspecialchars($admin['email']); ?>" disabled>
                    <p class="text-xs text-gray-500 mt-1">Email address cannot be changed</p>
                </div>
                
                <div class="mb-6">
                    <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($admin['phone']); ?>" required>
                </div>
                
                <button type="submit" name="update_profile" class="w-full px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-md transition duration-300">Update Profile</button>
            </form>
        </div>
        
        <!-- Change Password -->
        <div class="bg-white rounded-lg shadow-sm p-6 col-span-1">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Security: Change Password</h2>
            
            <form action="profile.php" method="post">
                <div class="mb-4">
                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                    <div class="relative">
                        <input type="password" id="current_password" name="current_password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        <button type="button" class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-500 focus:outline-none toggle-password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                    <div class="relative">
                        <input type="password" id="new_password" name="new_password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        <button type="button" class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-500 focus:outline-none toggle-password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Password must be at least 8 characters and include uppercase, lowercase, number, and special character</p>
                </div>
                
                <div class="mb-6">
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                    <div class="relative">
                        <input type="password" id="confirm_password" name="confirm_password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        <button type="button" class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-500 focus:outline-none toggle-password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" name="change_password" class="w-full px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-md transition duration-300">Change Password</button>
            </form>
        </div>
        
        <!-- Account Activity -->
        <div class="bg-white rounded-lg shadow-sm p-6 col-span-1 lg:col-span-2">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Login Activity History</h2>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Activity Type</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Browser/Device</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">Last Login</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('M d, Y H:i:s', strtotime($admin['last_login'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $_SERVER['REMOTE_ADDR']; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($_SERVER['HTTP_USER_AGENT']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle password visibility
        const toggleButtons = document.querySelectorAll('.toggle-password');
        
        toggleButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                const passwordInput = this.parentNode.querySelector('input');
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Toggle the eye icon
                const icon = this.querySelector('i');
                if (type === 'password') {
                    icon.className = 'fas fa-eye';
                } else {
                    icon.className = 'fas fa-eye-slash';
                }
            });
        });
    });
</script>