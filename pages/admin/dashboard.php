<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Get counts for dashboard
$conn = getDbConnection();

// Total users
$userStmt = $conn->prepare("SELECT COUNT(*) as count FROM users");
$userStmt->execute();
$totalUsers = $userStmt->get_result()->fetch_assoc()['count'];

// Pending users
$underReviewStmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE status = 'under_review'");
$underReviewStmt->execute();
$underReviewUsers = $underReviewStmt->get_result()->fetch_assoc()['count'];

// Job seekers
$seekerStmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'jobseeker'");
$seekerStmt->execute();
$totalJobseekers = $seekerStmt->get_result()->fetch_assoc()['count'];

// Employers
$employerStmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'employer'");
$employerStmt->execute();
$totalEmployers = $employerStmt->get_result()->fetch_assoc()['count'];

// Unread messages
$messageStmt = $conn->prepare("SELECT COUNT(*) as count FROM contact_messages WHERE status = 'unread'");
$messageStmt->execute();
$unreadMessages = $messageStmt->get_result()->fetch_assoc()['count'];

// Recent users
$recentStmt = $conn->prepare("
    SELECT id, first_name, last_name, email, role, status, created_at 
    FROM users 
    ORDER BY created_at DESC 
    LIMIT 5
");
$recentStmt->execute();
$recentUsers = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Recent messages
$recentMsgStmt = $conn->prepare("
    SELECT id, name, email, subject, status, created_at 
    FROM contact_messages 
    ORDER BY created_at DESC 
    LIMIT 5
");
$recentMsgStmt->execute();
$recentMessages = $recentMsgStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$conn->close();

// Set page title
$pageTitle = "Dashboard";
?>

<?php
// Include admin header
include '../imports/admin-header.php';
?>

<!-- Main Content Section -->
<div class="p-4 md:p-6 lg:p-8">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-4 md:mb-0">Dashboard Overview</h1>
        <div class="flex space-x-2">
            <button class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition duration-200 flex items-center">
                <i class="fas fa-download mr-2"></i> Export Report
            </button>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-6 md:mb-8">
        <div class="bg-white rounded-lg shadow-sm p-4 md:p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100 text-blue-500">
                    <i class="fas fa-users text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-gray-500 text-sm">Total Users</p>
                    <h3 class="text-2xl font-semibold text-gray-800"><?php echo $totalUsers; ?></h3>
                </div>
            </div>
            <div class="mt-4">
                <a href="users.php" class="text-blue-500 text-sm hover:text-blue-700">View all users <i class="fas fa-arrow-right ml-1"></i></a>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow-sm p-4 md:p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-yellow-100 text-yellow-500">
                    <i class="fas fa-clock text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-gray-500 text-sm">Under Review</p>
                    <h3 class="text-2xl font-semibold text-gray-800">
                        <?php echo $underReviewUsers; ?>
                    </h3>
                </div>
            </div>
            <div class="mt-4">
                <a href="users.php?status=under_review" class="text-yellow-500 text-sm hover:text-yellow-700">Review pending users <i class="fas fa-arrow-right ml-1"></i></a>
            </div>
        </div>
            
        <div class="bg-white rounded-lg shadow-sm p-4 md:p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100 text-green-500">
                    <i class="fas fa-building text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-gray-500 text-sm">Employers</p>
                    <h3 class="text-2xl font-semibold text-gray-800"><?php echo $totalEmployers; ?></h3>
                </div>
            </div>
            <div class="mt-4">
                <a href="employers.php" class="text-green-500 text-sm hover:text-green-700">View employers <i class="fas fa-arrow-right ml-1"></i></a>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow-sm p-4 md:p-6">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100 text-purple-500">
                    <i class="fas fa-user-tie text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-gray-500 text-sm">Job Seekers</p>
                    <h3 class="text-2xl font-semibold text-gray-800"><?php echo $totalJobseekers; ?></h3>
                </div>
            </div>
            <div class="mt-4">
                <a href="jobseekers.php" class="text-purple-500 text-sm hover:text-purple-700">View job seekers <i class="fas fa-arrow-right ml-1"></i></a>
            </div>
        </div>
    </div>
    
    <!-- Recent Activity Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Recent Users -->
        <div class="bg-white rounded-lg shadow-sm p-4 md:p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Recent Users</h2>
                <a href="users.php" class="text-blue-500 text-sm hover:text-blue-700">View all</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($recentUsers as $user): ?>
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center">
                                        <span class="text-xs font-medium"><?php echo strtoupper(substr($user['first_name'], 0, 1)); ?></span>
                                    </div>
                                    <div class="ml-3">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($user['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="text-sm text-gray-900"><?php echo ucfirst($user['role']); ?></span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <?php if ($user['status'] === 'active'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                                <?php elseif ($user['status'] === 'unverified'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Unverified</span>
                                <?php elseif ($user['status'] === 'under_review'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Under Review</span>
                                <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Recent Messages -->
        <div class="bg-white rounded-lg shadow-sm p-4 md:p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Recent Messages</h2>
                <a href="contacts.php" class="text-blue-500 text-sm hover:text-blue-700">View all</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sender</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subject</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($recentMessages as $message): ?>
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center">
                                        <span class="text-xs font-medium"><?php echo strtoupper(substr($message['first_name'], 0, 1)); ?></span>
                                    </div>
                                    <div class="ml-3">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($message['first_name'] . ' ' . $message['last_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($message['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="text-sm text-gray-900"><?php echo htmlspecialchars(substr($message['subject'], 0, 30)) . (strlen($message['subject']) > 30 ? '...' : ''); ?></span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <?php if ($message['status'] === 'read'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Read</span>
                                <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Unread</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?php echo date('M d, Y', strtotime($message['created_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>