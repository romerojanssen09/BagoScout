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

// Process user actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['approve_user']) && isset($_POST['user_id'])) {
        $userId = (int)$_POST['user_id'];
        
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $stmt->bind_param("i", $userId);
        
        if ($stmt->execute()) {
            $success = "User approved successfully";
            $user = getUserById($userId);
            $subject = "BagoScout - Account Approved";
            
            // Determine the correct redirect URL based on user role
            $redirectUrl = ($user['role'] == 'jobseeker') 
                ? "https://" . $_SERVER['HTTP_HOST'] . "/bagoscout/pages/auth-user/seeker/settings.php?status=approve" 
                : "https://" . $_SERVER['HTTP_HOST'] . "/bagoscout/pages/auth-user/employer/dashboard.php?status=approve";

            // Use the sendNotificationEmail function for HTML formatting
            sendNotificationEmail(
                $user['email'], 
                $user['first_name'] . ' ' . $user['last_name'], 
                $subject, 
                "Your BagoScout account has been approved. You can now login and start using the platform.",
                "Go to Dashboard",
                $redirectUrl
            );
        } else {
            $error = "Failed to approve user";
        }
        
        $stmt->close();
        $conn->close();
    } elseif (isset($_POST['reject_user']) && isset($_POST['user_id'])) {
        $userId = (int)$_POST['user_id'];
        $reason = isset($_POST['reject_reason']) ? sanitizeInput($_POST['reject_reason']) : '';
        
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE users SET status = 'rejected' WHERE id = ?");
        $stmt->bind_param("i", $userId);
        
        if ($stmt->execute()) {
            $success = "User rejected successfully";
            $user = getUserById($userId);
            $subject = "BagoScout - Account Verification Failed";
            
            // Determine the correct redirect URL based on user role
            $redirectUrl = ($user['role'] == 'jobseeker') 
                ? "https://" . $_SERVER['HTTP_HOST'] . "/bagoscout/pages/auth-user/seeker/settings.php?status=reject" 
                : "https://" . $_SERVER['HTTP_HOST'] . "/bagoscout/pages/auth-user/employer/dashboard.php?status=reject";

            // Use the sendNotificationEmail function for HTML formatting
            sendNotificationEmail(
                $user['email'], 
                $user['first_name'] . ' ' . $user['last_name'], 
                $subject, 
                "We regret to inform you that your BagoScout account verification could not be completed for the following reason: <br><br><strong>\"$reason\"</strong><br><br>Please update your profile with the required information and submit for approval again.",
                "Update Profile",
                $redirectUrl
            );
        } else {
            $error = "Failed to reject user";
        }
        
        $stmt->close();
        $conn->close();
    } elseif (isset($_POST['suspend_user']) && isset($_POST['user_id'])) {
        $userId = (int)$_POST['user_id'];
        $reason = isset($_POST['suspend_reason']) ? sanitizeInput($_POST['suspend_reason']) : '';
        
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE users SET status = 'suspended' WHERE id = ?");
        $stmt->bind_param("i", $userId);
        
        if ($stmt->execute()) {
            $success = "User suspended successfully";
            $user = getUserById($userId);
            $subject = "BagoScout - Account Suspended";
            
            // Determine the correct redirect URL based on user role
            $redirectUrl = "https://" . $_SERVER['HTTP_HOST'] . "/bagoscout/pages/contact.php";

            // Use the sendNotificationEmail function for HTML formatting
            sendNotificationEmail(
                $user['email'], 
                $user['first_name'] . ' ' . $user['last_name'], 
                $subject, 
                "We regret to inform you that your BagoScout account has been suspended for the following reason: <br><br><strong>\"$reason\"</strong><br><br>Please contact our support team for further assistance.",
                "Contact Support",
                $redirectUrl
            );
        } else {
            $error = "Failed to suspend user";
        }
        
        $stmt->close();
        $conn->close();
    } elseif (isset($_POST['delete_user']) && isset($_POST['user_id'])) {
        $userId = (int)$_POST['user_id'];
        $reason = isset($_POST['delete_reason']) ? sanitizeInput($_POST['delete_reason']) : '';
        
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE users SET status = 'deleted' WHERE id = ?");
        $stmt->bind_param("i", $userId);
        
        if ($stmt->execute()) {
            $success = "User deleted successfully";
            $user = getUserById($userId);
            $subject = "BagoScout - Account Deleted";

            // Use the sendNotificationEmail function for HTML formatting
            sendNotificationEmail(
                $user['email'], 
                $user['first_name'] . ' ' . $user['last_name'], 
                $subject, 
                "We regret to inform you that your BagoScout account has been deleted for the following reason: <br><br><strong>\"$reason\"</strong><br><br>If you believe this was done in error, please contact our support team.",
                "Contact Support",
                "https://" . $_SERVER['HTTP_HOST'] . "/bagoscout/pages/contact.php"
            );
        } else {
            $error = "Failed to delete user";
        }
        
        $stmt->close();
        $conn->close();
    }
}

// Get filter parameters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'under_review';
$roleFilter = isset($_GET['role']) ? $_GET['role'] : 'all';
$searchQuery = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Get users list with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$conn = getDbConnection();

// Build the query based on filters
$whereConditions = [];
$queryParams = [];
$paramTypes = '';

if ($statusFilter !== 'all') {
    $whereConditions[] = "u.status = ?";
    $queryParams[] = $statusFilter;
    $paramTypes .= 's';
}

if ($roleFilter !== 'all') {
    $whereConditions[] = "u.role = ?";
    $queryParams[] = $roleFilter;
    $paramTypes .= 's';
}

if (!empty($searchQuery)) {
    $whereConditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $searchParam = "%$searchQuery%";
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $paramTypes .= 'sss';
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get total users count
$countQuery = "SELECT COUNT(*) as total FROM users u $whereClause";
$countStmt = $conn->prepare($countQuery);

if (!empty($queryParams)) {
    $countStmt->bind_param($paramTypes, ...$queryParams);
}

$countStmt->execute();
$totalUsers = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalUsers / $perPage);

// Get users for current page
$query = "
    SELECT u.*, 
        CASE 
            WHEN u.role = 'jobseeker' THEN j.facephoto
            WHEN u.role = 'employer' THEN e.facephoto
            ELSE NULL
        END as facephoto
    FROM users u
    LEFT JOIN jobseekers j ON u.id = j.user_id AND u.role = 'jobseeker'
    LEFT JOIN employers e ON u.id = e.user_id AND u.role = 'employer'
    $whereClause
    ORDER BY 
        CASE WHEN u.status = 'unverified' THEN 0 ELSE 1 END,
        CASE WHEN u.status = 'under_review' THEN 0 ELSE 1 END,
        CASE WHEN u.status = 'active' THEN 0 ELSE 1 END,
        CASE WHEN u.status = 'suspended' THEN 0 ELSE 1 END,
        CASE WHEN u.status = 'deleted' THEN 0 ELSE 1 END,
        u.created_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);

if (!empty($queryParams)) {
    $queryParams[] = $perPage;
    $queryParams[] = $offset;
    $paramTypes .= 'ii';
    $stmt->bind_param($paramTypes, ...$queryParams);
} else {
    $stmt->bind_param("ii", $perPage, $offset);
}

$stmt->execute();
$result = $stmt->get_result();
$users = $result->fetch_all(MYSQLI_ASSOC);

// Get counts for each status
$statusCounts = [];
$statuses = ['unverified', 'under_review', 'active', 'rejected', 'suspended', 'deleted'];

foreach ($statuses as $status) {
    $statusStmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE status = ?");
    $statusStmt->bind_param("s", $status);
    $statusStmt->execute();
    $statusCounts[$status] = $statusStmt->get_result()->fetch_assoc()['count'];
}

// Get counts for each role
$roleCounts = [];
$roles = ['jobseeker', 'employer'];

foreach ($roles as $role) {
    $roleStmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = ?");
    $roleStmt->bind_param("s", $role);
    $roleStmt->execute();
    $roleCounts[$role] = $roleStmt->get_result()->fetch_assoc()['count'];
}

$stmt->close();
$conn->close();

// Set page title
$pageTitle = "User Requests";

// Include admin header
include '../imports/admin-header.php';
?>

<!-- Main Content -->
<div class="flex-1 p-8 md:ml-64">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">User Requests</h1>
        <div class="flex space-x-2">
            <a href="dashboard.php" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 transition duration-200 flex items-center">
                <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
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
    
    <!-- Filters -->
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Search & Filters</h2>
        
        <form action="users.php" method="get" class="space-y-4 md:space-y-0 md:flex md:items-end md:space-x-4">
            <div class="flex-1">
                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <div class="relative">
                    <input type="text" id="search" name="search" class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Search by name, email or phone..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400"></i>
                    </div>
                </div>
            </div>
            
            <div>
                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select id="status" name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="under_review" <?php echo $statusFilter === 'under_review' ? 'selected' : ''; ?>>Under Review (<?php echo $statusCounts['under_review']; ?>)</option>
                    <option value="unverified" <?php echo $statusFilter === 'unverified' ? 'selected' : ''; ?>>Unverified (<?php echo $statusCounts['unverified']; ?>)</option>
                    <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected (<?php echo $statusCounts['rejected']; ?>)</option>
                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active (<?php echo $statusCounts['active']; ?>)</option>
                    <option value="suspended" <?php echo $statusFilter === 'suspended' ? 'selected' : ''; ?>>Suspended (<?php echo $statusCounts['suspended']; ?>)</option>
                    <option value="deleted" <?php echo $statusFilter === 'deleted' ? 'selected' : ''; ?>>Deleted (<?php echo $statusCounts['deleted']; ?>)</option>
                </select>
            </div>
            
            <div>
                <label for="role" class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                <select id="role" name="role" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="all" <?php echo $roleFilter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                    <option value="jobseeker" <?php echo $roleFilter === 'jobseeker' ? 'selected' : ''; ?>>Job Seeker (<?php echo $roleCounts['jobseeker']; ?>)</option>
                    <option value="employer" <?php echo $roleFilter === 'employer' ? 'selected' : ''; ?>>Employer (<?php echo $roleCounts['employer']; ?>)</option>
                </select>
            </div>
            
            <div class="flex space-x-2">
                <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition duration-200">
                    <i class="fas fa-filter mr-2"></i> Apply Filters
                </button>
                
                <a href="users.php" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 transition duration-200">
                    <i class="fas fa-times mr-2"></i> Clear
                </a>
            </div>
        </form>
    </div>
    
    <!-- Users Table -->
    <div class="bg-white rounded-lg shadow-sm p-6 overflow-x-auto">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-semibold text-gray-800">
                <?php if ($statusFilter === 'under_review'): ?>
                    Users Pending Approval
                <?php elseif ($statusFilter === 'unverified'): ?>
                    Unverified Users
                <?php elseif ($statusFilter === 'active'): ?>
                    Active Users
                <?php elseif ($statusFilter === 'suspended'): ?>
                    Suspended Users
                <?php elseif ($statusFilter === 'rejected'): ?>
                    Rejected Users
                <?php elseif ($statusFilter === 'deleted'): ?>
                    Deleted Users
                <?php else: ?>
                    All Users
                <?php endif; ?>
            </h2>
            
            <?php if ($statusFilter === 'under_review' || $statusFilter === 'all'): ?>
            <div class="text-sm text-gray-600">
                <span class="font-medium"><?php echo $statusCounts['under_review']; ?></span> user<?php echo $statusCounts['under_review'] !== 1 ? 's' : ''; ?> pending approval
            </div>
            <?php endif; ?>
        </div>
        
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Joined</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($users as $user): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 h-10 w-10">
                                <?php if (!empty($user['facephoto'])): ?>
                                    <?php 
                                    // Remove the leading "../../../" from the path
                                    $photoPath = str_replace("../../../", "../../", $user['facephoto']); 
                                    ?>
                                    <img class="h-10 w-10 rounded-full object-cover" src="<?php echo $photoPath; ?>" alt="">
                                <?php else: ?>
                                    <div class="h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-600 text-sm font-medium">
                                        <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['email']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['phone']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($user['role'] == 'jobseeker'): ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800">Job Seeker</span>
                        <?php elseif ($user['role'] == 'employer'): ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Employer</span>
                        <?php else: ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800"><?php echo ucfirst($user['role']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($user['status'] == 'under_review'): ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Under Review</span>
                        <?php elseif ($user['status'] == 'active'): ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                        <?php elseif ($user['status'] == 'suspended'): ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Suspended</span>
                        <?php elseif ($user['status'] == 'rejected'): ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-orange-100 text-orange-800">Rejected</span>
                        <?php elseif ($user['status'] == 'deleted'): ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Deleted</span>
                        <?php else: ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800"><?php echo ucfirst($user['status']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                    </td>
                    <td class="flex flex-col justify-center items-center">
                        <?php if ($user['role'] == 'jobseeker'): ?>
                            <a href="view-jobseeker.php?id=<?php echo $user['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                <i class="fas fa-eye"></i> View
                            </a>
                        <?php elseif ($user['role'] == 'employer'): ?>
                            <a href="view-employer.php?id=<?php echo $user['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                <i class="fas fa-eye"></i> View
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($user['status'] == 'under_review'): ?>
                            <button type="button" class="text-green-600 hover:text-green-900 mr-3 approve-btn" data-user-id="<?php echo $user['id']; ?>">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button type="button" class="text-red-600 hover:text-red-900 reject-btn" data-user-id="<?php echo $user['id']; ?>">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        <?php elseif ($user['status'] == 'active'): ?>
                            <button type="button" class="text-red-600 hover:text-red-900 suspend-btn" data-user-id="<?php echo $user['id']; ?>">
                                <i class="fas fa-ban"></i> Suspend
                            </button>
                        <?php elseif ($user['status'] == 'suspended' || $user['status'] == 'rejected'): ?>
                            <button type="button" class="text-green-600 hover:text-green-900 approve-btn" data-user-id="<?php echo $user['id']; ?>">
                                <i class="fas fa-check"></i> Reactivate
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($user['status'] != 'deleted'): ?>
                            <button type="button" class="text-red-600 hover:text-red-900 delete-btn mt-2" data-user-id="<?php echo $user['id']; ?>">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">No users found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="flex justify-between items-center mt-6">
            <div class="text-sm text-gray-700">
                Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to <span class="font-medium"><?php echo min($offset + $perPage, $totalUsers); ?></span> of <span class="font-medium"><?php echo $totalUsers; ?></span> users
            </div>
            <div class="flex space-x-1">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&status=<?php echo $statusFilter; ?>&role=<?php echo $roleFilter; ?>&search=<?php echo urlencode($searchQuery); ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a href="?page=<?php echo $i; ?>&status=<?php echo $statusFilter; ?>&role=<?php echo $roleFilter; ?>&search=<?php echo urlencode($searchQuery); ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium <?php echo $i === $page ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-50'; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&status=<?php echo $statusFilter; ?>&role=<?php echo $roleFilter; ?>&search=<?php echo urlencode($searchQuery); ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Approve user with SweetAlert2
        const approveBtns = document.querySelectorAll('.approve-btn');
        
        approveBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const userId = this.getAttribute('data-user-id');
                
                Swal.fire({
                    title: 'Confirm Approval',
                    text: 'Are you sure you want to approve this user?',
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
                        form.action = 'users.php';
                        
                        const userIdInput = document.createElement('input');
                        userIdInput.type = 'hidden';
                        userIdInput.name = 'user_id';
                        userIdInput.value = userId;
                        
                        const approveInput = document.createElement('input');
                        approveInput.type = 'hidden';
                        approveInput.name = 'approve_user';
                        approveInput.value = '1';
                        
                        form.appendChild(userIdInput);
                        form.appendChild(approveInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });
        
        // Reject user with SweetAlert2
        const rejectBtns = document.querySelectorAll('.reject-btn');
        
        rejectBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const userId = this.getAttribute('data-user-id');
                
                Swal.fire({
                    title: 'Confirm Rejection',
                    text: 'Are you sure you want to reject this user?',
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
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Create and submit form
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'users.php';
                        
                        const userIdInput = document.createElement('input');
                        userIdInput.type = 'hidden';
                        userIdInput.name = 'user_id';
                        userIdInput.value = userId;
                        
                        const rejectInput = document.createElement('input');
                        rejectInput.type = 'hidden';
                        rejectInput.name = 'reject_user';
                        rejectInput.value = '1';
                        
                        const reasonInput = document.createElement('input');
                        reasonInput.type = 'hidden';
                        reasonInput.name = 'reject_reason';
                        reasonInput.value = result.value;
                        
                        form.appendChild(userIdInput);
                        form.appendChild(rejectInput);
                        form.appendChild(reasonInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });
        
        // Suspend user with SweetAlert2
        const suspendBtns = document.querySelectorAll('.suspend-btn');
        
        suspendBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const userId = this.getAttribute('data-user-id');
                
                Swal.fire({
                    title: 'Confirm Suspension',
                    text: 'Are you sure you want to suspend this user?',
                    input: 'textarea',
                    inputLabel: 'Reason for suspension',
                    inputPlaceholder: 'Enter your reason for suspending this user...',
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
                        form.action = 'users.php';
                        
                        const userIdInput = document.createElement('input');
                        userIdInput.type = 'hidden';
                        userIdInput.name = 'user_id';
                        userIdInput.value = userId;
                        
                        const suspendInput = document.createElement('input');
                        suspendInput.type = 'hidden';
                        suspendInput.name = 'suspend_user';
                        suspendInput.value = '1';
                        
                        const reasonInput = document.createElement('input');
                        reasonInput.type = 'hidden';
                        reasonInput.name = 'suspend_reason';
                        reasonInput.value = result.value;
                        
                        form.appendChild(userIdInput);
                        form.appendChild(suspendInput);
                        form.appendChild(reasonInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });
        
        // Delete user with SweetAlert2
        const deleteBtns = document.querySelectorAll('.delete-btn');
        
        deleteBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const userId = this.getAttribute('data-user-id');
                
                Swal.fire({
                    title: 'Confirm Deletion',
                    text: 'Are you sure you want to delete this user? This action cannot be undone.',
                    input: 'textarea',
                    inputLabel: 'Reason for deletion',
                    inputPlaceholder: 'Enter your reason for deleting this user...',
                    inputAttributes: {
                        'aria-label': 'Reason for deletion',
                        'required': 'required'
                    },
                    inputValidator: (value) => {
                        if (!value) {
                            return 'You need to provide a reason for deletion!';
                        }
                    },
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#EF4444', // Red-500
                    cancelButtonColor: '#9CA3AF', // Gray-400
                    confirmButtonText: 'Delete',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Create and submit form
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'users.php';
                        
                        const userIdInput = document.createElement('input');
                        userIdInput.type = 'hidden';
                        userIdInput.name = 'user_id';
                        userIdInput.value = userId;
                        
                        const deleteInput = document.createElement('input');
                        deleteInput.type = 'hidden';
                        deleteInput.name = 'delete_user';
                        deleteInput.value = '1';
                        
                        const reasonInput = document.createElement('input');
                        reasonInput.type = 'hidden';
                        reasonInput.name = 'delete_reason';
                        reasonInput.value = result.value;
                        
                        form.appendChild(userIdInput);
                        form.appendChild(deleteInput);
                        form.appendChild(reasonInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });
    });
</script>