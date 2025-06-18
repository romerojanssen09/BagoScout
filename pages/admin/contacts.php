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

// Process message actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['mark_read']) && isset($_POST['message_id'])) {
        $messageId = (int)$_POST['message_id'];
        
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE contact_messages SET status = 'read' WHERE id = ?");
        $stmt->bind_param("i", $messageId);
        
        if ($stmt->execute()) {
            $success = "Message marked as read";
        } else {
            $error = "Failed to update message status";
        }
        
        $stmt->close();
        $conn->close();
    } elseif (isset($_POST['delete_message']) && isset($_POST['message_id'])) {
        $messageId = (int)$_POST['message_id'];
        
        $conn = getDbConnection();
        $stmt = $conn->prepare("DELETE FROM contact_messages WHERE id = ?");
        $stmt->bind_param("i", $messageId);
        
        if ($stmt->execute()) {
            $success = "Message deleted successfully";
        } else {
            $error = "Failed to delete message";
        }
        
        $stmt->close();
        $conn->close();
    }
}

// Get contact messages with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$conn = getDbConnection();

// Get total messages count
$countStmt = $conn->prepare("SELECT COUNT(*) as total FROM contact_messages");
$countStmt->execute();
$totalMessages = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalMessages / $perPage);

// Get messages for current page
$stmt = $conn->prepare("
    SELECT * FROM contact_messages
    ORDER BY 
        CASE WHEN status = 'unread' THEN 0 ELSE 1 END,
        created_at DESC
    LIMIT ? OFFSET ?
");

$stmt->bind_param("ii", $perPage, $offset);
$stmt->execute();
$result = $stmt->get_result();
$messages = $result->fetch_all(MYSQLI_ASSOC);

// Get unread count
$unreadStmt = $conn->prepare("SELECT COUNT(*) as unread FROM contact_messages WHERE status = 'unread'");
$unreadStmt->execute();
$unreadCount = $unreadStmt->get_result()->fetch_assoc()['unread'];

$stmt->close();
$conn->close();

// Set page title
$pageTitle = "Messages";

// Include admin header
include '../imports/admin-header.php';
?>

<!-- Main Content -->
<div class="p-4 md:p-6 lg:p-8 md:ml-64">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-4 md:mb-0">Contact Messages</h1>
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
    
    <!-- Messages -->
    <div class="bg-white rounded-lg shadow-sm p-4 md:p-6">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
            <h2 class="text-xl font-semibold text-gray-800 mb-2 md:mb-0">Messages</h2>
            <div class="flex items-center space-x-2">
                <span class="text-sm text-gray-600">Filter:</span>
                <select id="status-filter" class="border border-gray-300 rounded-md px-3 py-1 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="all">All</option>
                    <option value="unread">Unread</option>
                    <option value="read">Read</option>
                </select>
            </div>
        </div>
        
        <?php if (empty($messages)): ?>
            <div class="text-center py-8">
                <i class="fas fa-inbox text-gray-400 text-5xl mb-4"></i>
                <p class="text-gray-500">No messages found</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sender</th>
                            <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subject</th>
                            <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Date</th>
                            <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th scope="col" class="px-4 md:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($messages as $message): ?>
                        <tr class="message-row <?php echo $message['status'] === 'unread' ? 'bg-blue-50' : ''; ?>" data-status="<?php echo $message['status']; ?>">
                            <td class="px-4 md:px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-600">
                                        <?php echo strtoupper(substr($message['first_name'], 0, 1)); ?>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($message['first_name'] . ' ' . $message['last_name']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($message['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 md:px-6 py-4">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($message['subject']); ?></div>
                                <div class="text-sm text-gray-500 truncate max-w-xs"><?php echo htmlspecialchars(substr($message['message'], 0, 100)) . (strlen($message['message']) > 100 ? '...' : ''); ?></div>
                            </td>
                            <td class="px-4 md:px-6 py-4 whitespace-nowrap hidden md:table-cell">
                                <div class="text-sm text-gray-500"><?php echo date('M d, Y', strtotime($message['created_at'])); ?></div>
                                <div class="text-xs text-gray-500"><?php echo date('h:i A', strtotime($message['created_at'])); ?></div>
                            </td>
                            <td class="px-4 md:px-6 py-4 whitespace-nowrap">
                                <?php if ($message['status'] === 'unread'): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Unread</span>
                                <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Read</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 md:px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <button type="button" class="text-blue-600 hover:text-blue-900 mr-3 view-message" data-id="<?php echo $message['id']; ?>">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if ($message['status'] === 'unread'): ?>
                                    <form method="post" class="inline-block">
                                        <input type="hidden" name="message_id" value="<?php echo $message['id']; ?>">
                                        <input type="hidden" name="mark_read" value="1">
                                        <button type="submit" class="text-green-600 hover:text-green-900 mr-3">
                                            <i class="fas fa-check"></i> Mark Read
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" class="inline-block delete-form">
                                    <input type="hidden" name="message_id" value="<?php echo $message['id']; ?>">
                                    <input type="hidden" name="delete_message" value="1">
                                    <button type="button" class="text-red-600 hover:text-red-900 delete-btn">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="flex flex-col md:flex-row justify-between items-center mt-6">
                <div class="text-sm text-gray-500 mb-4 md:mb-0">
                    Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $perPage, $totalMessages); ?> of <?php echo $totalMessages; ?> messages
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
        <?php endif; ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Status filter
        const statusFilter = document.getElementById('status-filter');
        const messageRows = document.querySelectorAll('.message-row');
        
        statusFilter.addEventListener('change', function() {
            const selectedStatus = this.value;
            
            messageRows.forEach(row => {
                const rowStatus = row.getAttribute('data-status');
                
                if (selectedStatus === 'all' || rowStatus === selectedStatus) {
                    row.classList.remove('hidden');
                } else {
                    row.classList.add('hidden');
                }
            });
        });
        
        // View message with SweetAlert2
        const viewButtons = document.querySelectorAll('.view-message');
        
        viewButtons.forEach(button => {
            button.addEventListener('click', function() {
                const messageId = this.getAttribute('data-id');
                const row = this.closest('tr');
                const name = row.querySelector('.text-gray-900').textContent;
                const email = row.querySelector('.text-gray-500').textContent;
                const subject = row.querySelectorAll('.text-gray-900')[1].textContent;
                const preview = row.querySelectorAll('.text-gray-500')[1].textContent;
                const status = row.getAttribute('data-status');
                const initial = name.charAt(0).toUpperCase();
                
                // Create the content for the modal
                const messageContent = `
                    <div class="text-left">
                        <div class="flex items-center mb-4">
                            <div class="h-12 w-12 rounded-full bg-gray-200 flex items-center justify-center text-gray-600 mr-4">
                                ${initial}
                            </div>
                            <div>
                                <h4 class="text-lg font-medium text-gray-900">${name}</h4>
                                <p class="text-sm text-gray-500">${email}</p>
                            </div>
                        </div>
                        <div class="mb-4">
                            <h5 class="text-md font-medium text-gray-800">Subject:</h5>
                            <p class="text-gray-700">${subject}</p>
                        </div>
                        <div class="mb-4">
                            <h5 class="text-md font-medium text-gray-800">Message:</h5>
                            <p class="text-gray-700 whitespace-pre-line">${preview.replace('...', '')}</p>
                        </div>
                    </div>
                `;
                
                // Show SweetAlert2 with message details
                Swal.fire({
                    title: 'Message Details',
                    html: messageContent,
                    showCloseButton: true,
                    showCancelButton: status === 'unread',
                    cancelButtonText: 'Mark as Read',
                    confirmButtonText: 'Close',
                    confirmButtonColor: '#9CA3AF', // Gray-400
                    cancelButtonColor: '#10B981', // Green-500
                    width: '32rem',
                    customClass: {
                        container: 'swal-large-height',
                        popup: 'swal-large-height'
                    }
                }).then((result) => {
                    if (result.dismiss === Swal.DismissReason.cancel && status === 'unread') {
                        // Create and submit form to mark as read
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = 'contacts.php';
                        
                        const messageIdInput = document.createElement('input');
                        messageIdInput.type = 'hidden';
                        messageIdInput.name = 'message_id';
                        messageIdInput.value = messageId;
                        
                        const markReadInput = document.createElement('input');
                        markReadInput.type = 'hidden';
                        markReadInput.name = 'mark_read';
                        markReadInput.value = '1';
                        
                        form.appendChild(messageIdInput);
                        form.appendChild(markReadInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });
        
        // Delete confirmation with SweetAlert2
        const deleteBtns = document.querySelectorAll('.delete-btn');
        
        deleteBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const deleteForm = this.closest('form');
                
                Swal.fire({
                    title: 'Confirm Deletion',
                    text: 'Are you sure you want to delete this message? This action cannot be undone.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#EF4444', // Red-500
                    cancelButtonColor: '#9CA3AF', // Gray-400
                    confirmButtonText: 'Delete',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        deleteForm.submit();
                    }
                });
            });
        });
    });
</script>