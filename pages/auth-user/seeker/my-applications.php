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

// Process application cancellation
$cancelSuccess = '';
$cancelError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    if (isset($_POST['application_id']) && !empty($_POST['application_id'])) {
        $applicationId = intval($_POST['application_id']);
        
        if (cancelJobApplication($applicationId, $seekerId)) {
            $cancelSuccess = "Application cancelled successfully.";
        } else {
            $cancelError = "Failed to cancel application. Please try again.";
        }
    }
}

// Get all applications for this jobseeker
$stmt = $conn->prepare("
    SELECT a.*, j.title, j.location, j.job_type, j.deadline, 
           e.company_name, e.company_type, u.first_name, u.last_name, u.profile
    FROM applications a
    JOIN jobs j ON a.job_id = j.id
    JOIN employers e ON j.employer_id = e.id
    JOIN users u ON e.user_id = u.id
    WHERE a.jobseeker_id = ?
    ORDER BY a.created_at DESC
");
$stmt->bind_param("i", $seekerId);
$stmt->execute();
$result = $stmt->get_result();

$applications = [];
while ($row = $result->fetch_assoc()) {
    $applications[] = $row;
}

$stmt->close();
$conn->close();

$pageTitle = "My Applications";
ob_start();
?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">My Applications</h1>
    
    <?php if (!empty($cancelSuccess)): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
            <p><?php echo $cancelSuccess; ?></p>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($cancelError)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
            <p><?php echo $cancelError; ?></p>
        </div>
    <?php endif; ?>
    
    <?php if (empty($applications)): ?>
        <div class="bg-white rounded-lg shadow-sm p-8 text-center">
            <i class="fas fa-clipboard-list text-gray-400 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-800 mb-2">No applications found</h3>
            <p class="text-gray-600 mb-4">You haven't applied for any jobs yet.</p>
            <a href="jobs.php" class="inline-block px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-300">
                Browse Jobs
            </a>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 gap-4">
            <?php foreach ($applications as $application): ?>
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div class="mb-4 md:mb-0">
                            <h3 class="text-lg font-semibold text-gray-800 mb-1">
                                <a href="view-job.php?id=<?php echo $application['job_id']; ?>" class="hover:text-blue-600">
                                    <?php echo htmlspecialchars($application['title']); ?>
                                </a>
                            </h3>
                            <p class="text-gray-600 mb-2"><?php echo htmlspecialchars($application['company_name']); ?></p>
                            <div class="flex items-center text-sm text-gray-500">
                                <i class="fas fa-map-marker-alt mr-2"></i>
                                <span><?php echo htmlspecialchars($application['location']); ?></span>
                                <span class="mx-2">•</span>
                                <span><?php echo htmlspecialchars($application['job_type']); ?></span>
                            </div>
                        </div>
                        <div>
                            <?php 
                            $statusClass = '';
                            switch ($application['status']) {
                                case 'pending':
                                    $statusClass = 'bg-yellow-100 text-yellow-800';
                                    break;
                                case 'reviewed':
                                    $statusClass = 'bg-blue-100 text-blue-800';
                                    break;
                                case 'shortlisted':
                                    $statusClass = 'bg-green-100 text-green-800';
                                    break;
                                case 'rejected':
                                    $statusClass = 'bg-red-100 text-red-800';
                                    break;
                                case 'hired':
                                    $statusClass = 'bg-purple-100 text-purple-800';
                                    break;
                            }
                            ?>
                            <span class="<?php echo $statusClass; ?> text-xs font-medium px-2.5 py-0.5 rounded">
                                <?php echo ucfirst($application['status']); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                            <div class="mb-4 md:mb-0">
                                <p class="text-sm text-gray-500">
                                    <i class="fas fa-calendar-alt mr-1"></i> Applied: <?php echo date('F j, Y', strtotime($application['created_at'])); ?>
                                </p>
                                <?php if (!empty($application['deadline'])): ?>
                                    <p class="text-sm text-gray-500">
                                        <i class="fas fa-hourglass-end mr-1"></i> Deadline: <?php echo date('F j, Y', strtotime($application['deadline'])); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="flex space-x-2">
                                <a href="view-job.php?id=<?php echo $application['job_id']; ?>" class="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700 transition duration-300">
                                    View Job
                                </a>
                                <?php if ($application['status'] === 'pending' || $application['status'] === 'reviewed'): ?>
                                    <form action="my-applications.php" method="post" onsubmit="return confirm('Are you sure you want to cancel this application?');">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="application_id" value="<?php echo $application['id']; ?>">
                                        <button type="submit" class="px-4 py-2 bg-red-600 text-white text-sm rounded-md hover:bg-red-700 transition duration-300">
                                            Cancel
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include_once 'nav/layout.php';
?>