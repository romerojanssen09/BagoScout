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

// Get jobseekers list with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$conn = getDbConnection();

// Get total jobseekers count
$countStmt = $conn->prepare("SELECT COUNT(*) as total FROM users u JOIN jobseekers j ON u.id = j.user_id WHERE u.role = 'jobseeker'");
$countStmt->execute();
$totalJobseekers = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalJobseekers / $perPage);

// Get jobseekers for current page
$stmt = $conn->prepare("
    SELECT u.*, j.fields, j.skills, j.facephoto, j.valid_id
    FROM users u
    JOIN jobseekers j ON u.id = j.user_id
    WHERE u.role = 'jobseeker'
    ORDER BY 
        CASE WHEN u.status = 'pending' THEN 0 ELSE 1 END,
        u.created_at DESC
    LIMIT ? OFFSET ?
");

$stmt->bind_param("ii", $perPage, $offset);
$stmt->execute();
$result = $stmt->get_result();
$jobseekers = $result->fetch_all(MYSQLI_ASSOC);

$stmt->close();
$conn->close();

// Set page title
$pageTitle = "Manage Job Seekers";

// Include the common admin header
include '../imports/admin-header.php';
?>

<!-- Main Content -->
<div class="flex-1 p-8 md:ml-64">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Manage Job Seekers</h1>
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
    
    <!-- Job Seekers Table -->
    <div class="bg-white rounded-lg shadow-sm p-6 overflow-x-auto">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-semibold text-gray-800">Job Seekers List</h2>
            <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-600">Filter:</span>
                <select id="status-filter" class="border border-gray-300 rounded-md px-3 py-1 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="all">All</option>
                    <option value="pending">Pending</option>
                    <option value="active">Active</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>
        </div>
        
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job Seeker</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Skills</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fields</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Documents</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($jobseekers as $jobseeker): ?>
                <tr class="jobseeker-row" data-status="<?php echo $jobseeker['status']; ?>">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 h-10 w-10">
                                <?php if (!empty($jobseeker['facephoto'])): ?>
                                    <?php 
                                    // Remove the leading "../../../" from the path
                                    $photoPath = str_replace("../../../", "../../", $jobseeker['facephoto']); 
                                    ?>
                                    <img class="h-10 w-10 rounded-full object-cover" src="<?php echo $photoPath; ?>" alt="">
                                <?php else: ?>
                                    <div class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center text-gray-500">
                                        <?php echo strtoupper(substr($jobseeker['first_name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($jobseeker['first_name'] . ' ' . $jobseeker['last_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($jobseeker['email']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($jobseeker['phone']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <?php if ($jobseeker['status'] == 'pending'): ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                        <?php elseif ($jobseeker['status'] == 'active'): ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                        <?php elseif ($jobseeker['status'] == 'suspended'): ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Suspended</span>
                        <?php else: ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800"><?php echo ucfirst($jobseeker['status']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm text-gray-900 max-w-xs overflow-hidden">
                            <?php if (!empty($jobseeker['skills'])): ?>
                                <?php 
                                $skills = explode(',', $jobseeker['skills']);
                                $displaySkills = array_slice($skills, 0, 3);
                                foreach ($displaySkills as $skill): ?>
                                    <span class="inline-block bg-green-100 text-green-800 text-xs px-2 py-1 rounded-full mr-1 mb-1"><?php echo htmlspecialchars(trim($skill)); ?></span>
                                <?php endforeach; ?>
                                
                                <?php if (count($skills) > 3): ?>
                                    <span class="text-xs text-gray-500">+<?php echo count($skills) - 3; ?> more</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-gray-500">No skills specified</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm text-gray-900 max-w-xs overflow-hidden">
                            <?php if (!empty($jobseeker['fields'])): ?>
                                <?php 
                                $fields = explode(',', $jobseeker['fields']);
                                $displayFields = array_slice($fields, 0, 2);
                                foreach ($displayFields as $field): ?>
                                    <span class="inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full mr-1 mb-1"><?php echo htmlspecialchars(trim($field)); ?></span>
                                <?php endforeach; ?>
                                
                                <?php if (count($fields) > 2): ?>
                                    <span class="text-xs text-gray-500">+<?php echo count($fields) - 2; ?> more</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-gray-500">No fields specified</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        <?php if (!empty($jobseeker['valid_id'])): ?>
                            <span class="text-green-600"><i class="fas fa-check-circle mr-1"></i> Provided</span>
                        <?php else: ?>
                            <span class="text-red-600"><i class="fas fa-times-circle mr-1"></i> Missing</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <a href="view-jobseeker.php?id=<?php echo $jobseeker['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($jobseekers)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">No job seekers found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="flex justify-between items-center mt-6">
            <div class="text-sm text-gray-700">
                Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to <span class="font-medium"><?php echo min($offset + $perPage, $totalJobseekers); ?></span> of <span class="font-medium"><?php echo $totalJobseekers; ?></span> job seekers
            </div>
            <div class="flex space-x-1">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a href="?page=<?php echo $i; ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium <?php echo $i === $page ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-50'; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const statusFilter = document.getElementById('status-filter');
        const jobseekerRows = document.querySelectorAll('.jobseeker-row');
        
        statusFilter.addEventListener('change', function() {
            const selectedStatus = this.value;
            
            jobseekerRows.forEach(row => {
                const rowStatus = row.getAttribute('data-status');
                
                if (selectedStatus === 'all' || selectedStatus === rowStatus) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });
</script>