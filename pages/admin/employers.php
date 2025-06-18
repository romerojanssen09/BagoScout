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

// Get employers list with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$conn = getDbConnection();

// Get total employers count
$countStmt = $conn->prepare("SELECT COUNT(*) as total FROM users u JOIN employers e ON u.id = e.user_id WHERE u.role = 'employer'");
$countStmt->execute();
$totalEmployers = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalEmployers / $perPage);

// Get employers for current page
$stmt = $conn->prepare("
    SELECT u.*, e.company_name, e.company_type, e.role_in_company, e.fields, e.facephoto, e.valid_id
    FROM users u
    JOIN employers e ON u.id = e.user_id
    WHERE u.role = 'employer'
    ORDER BY 
        CASE WHEN u.status = 'under_review' THEN 0 ELSE 1 END,
        u.created_at DESC
    LIMIT ? OFFSET ?
");

$stmt->bind_param("ii", $perPage, $offset);
$stmt->execute();
$result = $stmt->get_result();
$employers = $result->fetch_all(MYSQLI_ASSOC);

$stmt->close();
$conn->close();

// Set page title
$pageTitle = "Manage Employers";

// Include admin header
include '../imports/admin-header.php';
?>

<!-- Main Content -->
<div class="p-4 md:p-6 lg:p-8 md:ml-64">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-4 md:mb-0">Manage Employers</h1>
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
    
    <!-- Employers Table -->
    <div class="bg-white rounded-lg shadow-sm p-4 md:p-6 overflow-x-auto">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
            <h2 class="text-xl font-semibold text-gray-800 mb-2 md:mb-0">Employers List</h2>
            <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-600">Filter:</span>
                <select id="status-filter" class="border border-gray-300 rounded-md px-3 py-1 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="all">All</option>
                    <option value="unverified">Unverified</option>
                    <option value="under_review">Under Review</option>
                    <option value="active">Active</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employer</th>
                        <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                        <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Fields</th>
                        <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Documents</th>
                        <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($employers as $employer): ?>
                    <tr class="employer-row" data-status="<?php echo $employer['status']; ?>">
                        <td class="px-4 md:px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 h-10 w-10">
                                    <?php if (!empty($employer['facephoto']) && file_exists('../../' . $employer['facephoto'])): ?>
                                        <img class="h-10 w-10 rounded-full object-cover" src="../../<?php echo htmlspecialchars($employer['facephoto']); ?>" alt="">
                                    <?php else: ?>
                                        <div class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center text-gray-500">
                                            <?php echo strtoupper(substr($employer['first_name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($employer['first_name'] . ' ' . $employer['last_name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($employer['email']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($employer['phone']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 md:px-6 py-4">
                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($employer['company_name']); ?></div>
                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($employer['company_type']); ?></div>
                            <div class="text-xs text-gray-500">Role: <?php echo htmlspecialchars($employer['role_in_company']); ?></div>
                        </td>
                        <td class="px-4 md:px-6 py-4 whitespace-nowrap">
                            <?php if ($employer['status'] == 'under_review'): ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Under Review</span>
                            <?php elseif ($employer['status'] == 'active'): ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                            <?php elseif ($employer['status'] == 'suspended'): ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Suspended</span>
                            <?php elseif ($employer['status'] == 'unverified'): ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Unverified</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 md:px-6 py-4 hidden md:table-cell">
                            <?php 
                            if (!empty($employer['fields'])) {
                                $fields = explode(',', $employer['fields']);
                                foreach ($fields as $field) {
                                    echo '<span class="inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full mr-1 mb-1">' . htmlspecialchars(trim($field)) . '</span>';
                                }
                            } else {
                                echo '<span class="text-gray-500 text-sm">No fields specified</span>';
                            }
                            ?>
                        </td>
                        <td class="px-4 md:px-6 py-4 hidden md:table-cell">
                            <?php if (!empty($employer['valid_id'])): ?>
                                <a href="../../<?php echo htmlspecialchars($employer['valid_id']); ?>" target="_blank" class="text-blue-600 hover:text-blue-800">
                                    <i class="fas fa-id-card mr-1"></i> View ID
                                </a>
                            <?php else: ?>
                                <span class="text-gray-500">No ID uploaded</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 md:px-6 py-4 md:table-cell text-sm font-medium">
                            <a href="view-employer.php?id=<?php echo $employer['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <?php if ($employer['status'] == 'under_review'): ?>
                            <a href="#" class="text-green-600 hover:text-green-900 mr-3 approve-btn" data-id="<?php echo $employer['id']; ?>">
                                <i class="fas fa-check"></i> Under Review
                            </a>
                            <a href="#" class="text-red-600 hover:text-red-900 reject-btn" data-id="<?php echo $employer['id']; ?>">
                                <i class="fas fa-times"></i> Reject
                            </a>
                            <?php elseif ($employer['status'] == 'active'): ?>
                            <a href="#" class="text-red-600 hover:text-red-900 suspend-btn" data-id="<?php echo $employer['id']; ?>">
                                <i class="fas fa-ban"></i> Suspend
                            </a>
                            <?php elseif ($employer['status'] == 'suspended'): ?>
                            <a href="#" class="text-green-600 hover:text-green-900 activate-btn" data-id="<?php echo $employer['id']; ?>">
                                <i class="fas fa-check"></i> Activate
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($employers)): ?>
                    <tr>
                        <td colspan="6" class="px-4 md:px-6 py-4 text-center text-gray-500">No employers found</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="flex justify-between items-center mt-6">
            <div class="text-sm text-gray-500">
                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $perPage, $totalEmployers); ?> of <?php echo $totalEmployers; ?> employers
            </div>
            <div class="flex space-x-1">
                <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>" class="px-3 py-1 rounded-md bg-gray-200 text-gray-700 hover:bg-gray-300">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php endif; ?>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1) {
                    echo '<a href="?page=1" class="px-3 py-1 rounded-md bg-gray-200 text-gray-700 hover:bg-gray-300">1</a>';
                    if ($startPage > 2) {
                        echo '<span class="px-3 py-1">...</span>';
                    }
                }
                
                for ($i = $startPage; $i <= $endPage; $i++) {
                    $activeClass = $i === $page ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300';
                    echo '<a href="?page=' . $i . '" class="px-3 py-1 rounded-md ' . $activeClass . '">' . $i . '</a>';
                }
                
                if ($endPage < $totalPages) {
                    if ($endPage < $totalPages - 1) {
                        echo '<span class="px-3 py-1">...</span>';
                    }
                    echo '<a href="?page=' . $totalPages . '" class="px-3 py-1 rounded-md bg-gray-200 text-gray-700 hover:bg-gray-300">' . $totalPages . '</a>';
                }
                ?>
                
                <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?>" class="px-3 py-1 rounded-md bg-gray-200 text-gray-700 hover:bg-gray-300">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Status filter
        const statusFilter = document.getElementById('status-filter');
        const employerRows = document.querySelectorAll('.employer-row');
        
        statusFilter.addEventListener('change', function() {
            const selectedStatus = this.value;
            
            employerRows.forEach(row => {
                const rowStatus = row.getAttribute('data-status');
                
                if (selectedStatus === 'all' || rowStatus === selectedStatus) {
                    row.classList.remove('hidden');
                } else {
                    row.classList.add('hidden');
                }
            });
        });
        
        // SweetAlert2 handling for approve/reject buttons
        const approveBtns = document.querySelectorAll('.approve-btn');
        const rejectBtns = document.querySelectorAll('.reject-btn');
        const suspendBtns = document.querySelectorAll('.suspend-btn');
        const activateBtns = document.querySelectorAll('.activate-btn');
        
        approveBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const employerId = this.getAttribute('data-id');
                
                Swal.fire({
                    title: 'Approve Employer',
                    text: 'Are you sure you want to approve this employer?',
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
                        form.action = 'employers.php';
                        
                        const employerIdInput = document.createElement('input');
                        employerIdInput.type = 'hidden';
                        employerIdInput.name = 'employer_id';
                        employerIdInput.value = employerId;
                        
                        const approveInput = document.createElement('input');
                        approveInput.type = 'hidden';
                        approveInput.name = 'approve_employer';
                        approveInput.value = '1';
                        
                        form.appendChild(employerIdInput);
                        form.appendChild(approveInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });
        
        rejectBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const employerId = this.getAttribute('data-id');
                
                Swal.fire({
                    title: 'Reject Employer',
                    text: 'Please provide a reason for rejection:',
                    input: 'textarea',
                    inputPlaceholder: 'Enter your reason for rejecting this employer...',
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
                        form.action = 'employers.php';
                        
                        const employerIdInput = document.createElement('input');
                        employerIdInput.type = 'hidden';
                        employerIdInput.name = 'employer_id';
                        employerIdInput.value = employerId;
                        
                        const rejectInput = document.createElement('input');
                        rejectInput.type = 'hidden';
                        rejectInput.name = 'reject_employer';
                        rejectInput.value = '1';
                        
                        const reasonInput = document.createElement('input');
                        reasonInput.type = 'hidden';
                        reasonInput.name = 'reject_reason';
                        reasonInput.value = result.value;
                        
                        form.appendChild(employerIdInput);
                        form.appendChild(rejectInput);
                        form.appendChild(reasonInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });
        
        suspendBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const employerId = this.getAttribute('data-id');
                
                Swal.fire({
                    title: 'Suspend Employer',
                    text: 'Please provide a reason for suspension:',
                    input: 'textarea',
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
                        form.action = 'employers.php';
                        
                        const employerIdInput = document.createElement('input');
                        employerIdInput.type = 'hidden';
                        employerIdInput.name = 'employer_id';
                        employerIdInput.value = employerId;
                        
                        const suspendInput = document.createElement('input');
                        suspendInput.type = 'hidden';
                        suspendInput.name = 'suspend_employer';
                        suspendInput.value = '1';
                        
                        const reasonInput = document.createElement('input');
                        reasonInput.type = 'hidden';
                        reasonInput.name = 'suspend_reason';
                        reasonInput.value = result.value;
                        
                        form.appendChild(employerIdInput);
                        form.appendChild(suspendInput);
                        form.appendChild(reasonInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });
        
        activateBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const employerId = this.getAttribute('data-id');
                
                Swal.fire({
                    title: 'Activate Employer',
                    text: 'Are you sure you want to activate this employer?',
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
                        form.action = 'employers.php';
                        
                        const employerIdInput = document.createElement('input');
                        employerIdInput.type = 'hidden';
                        employerIdInput.name = 'employer_id';
                        employerIdInput.value = employerId;
                        
                        const activateInput = document.createElement('input');
                        activateInput.type = 'hidden';
                        activateInput.name = 'activate_employer';
                        activateInput.value = '1';
                        
                        form.appendChild(employerIdInput);
                        form.appendChild(activateInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });
    });
</script>