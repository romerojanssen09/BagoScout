<?php
// Get current page for active link highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
$user = getCurrentUser();
?>

<div class="flex flex-col h-full bg-gray-800 text-white">
    <!-- Logo Section -->
    <div class="p-4 border-b border-gray-700">
        <a href="dashboard.php" class="flex items-center">
            <span class="text-xl font-bold text-white">BagoScout</span>
        </a>
    </div>
    
    <!-- User Profile -->
    <div class="p-4 border-b border-gray-700">
        <div class="flex items-center">
            <div class="w-10 h-10 rounded-full bg-blue-500 flex-shrink-0 mr-3 flex items-center justify-center text-white font-bold">
                <?php if (isset($user['profile']) && $user['profile']): ?>
                    <img src="<?php echo $user['profile']; ?>" alt="Profile" class="w-full h-full rounded-full object-cover">
                <?php else: ?>
                    <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-white"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h3>
                <p class="text-xs text-gray-400"><?php echo ucfirst($user['role']); ?></p>
            </div>
        </div>
    </div>

    <!-- Main Navigation -->
    <div class="flex-grow overflow-y-auto py-2">
        <nav class="px-2">
            <a href="dashboard.php" class="flex items-center py-2 px-4 rounded-md mb-1 <?php echo ($currentPage === 'dashboard.php') ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-700'; ?>">
                <i class="fas fa-tachometer-alt w-5 mr-3"></i>
                <span>Dashboard</span>
            </a>

            <a href="message.php" class="flex items-center py-2 px-4 rounded-md mb-1 <?php echo $currentPage === 'message.php' || $currentPage === 'message-updated.php' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-700'; ?>">
                <i class="fas fa-comments w-5 mr-3"></i>
                <span>Messages</span>
                <span id="message-badge" class="ml-auto"></span>
            </a>

            <a href="jobs.php" class="flex items-center py-2 px-4 rounded-md mb-1 <?php echo $currentPage === 'jobs.php' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-700'; ?>">
                <i class="fas fa-briefcase w-5 mr-3"></i>
                <span>Jobs</span>
            </a>

            <a href="map.php" class="flex items-center py-2 px-4 rounded-md mb-1 <?php echo $currentPage === 'map.php' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-700'; ?>">
                <i class="fas fa-map-marker-alt w-5 mr-3"></i>
                <span>Job Map</span>
            </a>

            <a href="my-applications.php" class="flex items-center py-2 px-4 rounded-md mb-1 <?php echo $currentPage === 'my-applications.php' ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-700'; ?>">
                <i class="fas fa-file-alt w-5 mr-3"></i>
                <span>My Applications</span>
            </a>
        </nav>
    </div>
    
    <!-- Footer Links -->
    <div class="p-4 border-t border-gray-700">        
        <a href="../../logout.php" class="flex items-center py-2 px-4 rounded-md text-gray-300 hover:bg-gray-700">
            <i class="fas fa-sign-out-alt w-5 mr-3"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

<!-- Notification Badge -->
<?php
// Get unread message count
$conn = getDbConnection();
$stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM messages 
    WHERE receiver_id = ? AND is_read = 0
");
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$result = $stmt->get_result();
$unreadMessages = $result->fetch_assoc()['count'];
$conn->close();
?>

<script>
// Add notification badge to messages link if there are unread messages
document.addEventListener('DOMContentLoaded', function() {
    const unreadCount = <?php echo $unreadMessages; ?>;
    if (unreadCount > 0) {
        const messageBadge = document.getElementById('message-badge');
        if (messageBadge) {
            messageBadge.className = 'inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-red-100 bg-red-600 rounded-full';
            messageBadge.textContent = unreadCount;
        }
    }
});
</script>