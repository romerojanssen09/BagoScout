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

// Initialize variables
$jobs = [];
$recommendedJobs = [];
$seekerId = null;
$seekerFields = [];
$seekerSkills = [];

// Get jobseeker data if user is a jobseeker
if ($userRole === 'jobseeker') {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT id, fields, skills FROM jobseekers WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $seeker = $result->fetch_assoc();
        $seekerId = $seeker['id'];
        $seekerFields = explode(', ', $seeker['fields']);
        $seekerSkills = explode(', ', $seeker['skills']);
    }
    $stmt->close();
}

// Search functionality
$searchTerm = '';
$locationTerm = '';
$fieldFilter = '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $searchTerm = sanitizeInput($_GET['search']);
    }
    
    if (isset($_GET['location']) && !empty($_GET['location'])) {
        $locationTerm = sanitizeInput($_GET['location']);
    }
    
    if (isset($_GET['field']) && !empty($_GET['field'])) {
        $fieldFilter = sanitizeInput($_GET['field']);
    }
}

// Get all active jobs with search filters
$conn = getDbConnection();

// Build the SQL query based on search parameters
$sql = "
    SELECT j.*, e.company_name, e.company_type, u.first_name, u.last_name
    FROM jobs j
    JOIN employers e ON j.employer_id = e.id
    JOIN users u ON e.user_id = u.id
    WHERE j.status = 'active'
";

$params = [];
$types = "";

if (!empty($searchTerm)) {
    $sql .= " AND (j.title LIKE ? OR j.description LIKE ?)";
    $searchParam = "%" . $searchTerm . "%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ss";
}

if (!empty($locationTerm)) {
    $sql .= " AND (j.location LIKE ? OR j.city LIKE ? OR j.province LIKE ?)";
    $locationParam = "%" . $locationTerm . "%";
    $params[] = $locationParam;
    $params[] = $locationParam;
    $params[] = $locationParam;
    $types .= "sss";
}

if (!empty($fieldFilter)) {
    $sql .= " AND j.fields LIKE ?";
    $fieldParam = "%" . $fieldFilter . "%";
    $params[] = $fieldParam;
    $types .= "s";
}

$sql .= " ORDER BY j.created_at DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

while ($job = $result->fetch_assoc()) {
    $jobs[] = $job;
}

$stmt->close();

// Get recommended jobs for jobseekers
if ($userRole === 'jobseeker' && $seekerId) {
    $recommendedJobs = getRecommendedJobs($seekerId, 5);
}

// Get all available fields for filter dropdown
$stmt = $conn->prepare("SELECT DISTINCT fields FROM jobs WHERE status = 'active'");
$stmt->execute();
$result = $stmt->get_result();

$allFields = [];
while ($row = $result->fetch_assoc()) {
    $fields = explode(', ', $row['fields']);
    foreach ($fields as $field) {
        $field = trim($field);
        if (!empty($field) && !in_array($field, $allFields)) {
            $allFields[] = $field;
        }
    }
}
sort($allFields);

$stmt->close();
$conn->close();

$pageTitle = "Browse Jobs";
ob_start();
?>

<div class="container mx-auto px-4 py-8">
    <!-- Search Bar -->
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <form action="jobs.php" method="get" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Job Title or Keywords</label>
                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Search for jobs...">
            </div>
            <div>
                <label for="location" class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                <input type="text" id="location" name="location" value="<?php echo htmlspecialchars($locationTerm); ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="City, province...">
            </div>
            <div>
                <label for="field" class="block text-sm font-medium text-gray-700 mb-1">Field</label>
                <select id="field" name="field" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Fields</option>
                    <?php foreach ($allFields as $field): ?>
                        <option value="<?php echo htmlspecialchars($field); ?>" <?php echo ($fieldFilter === $field) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($field); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-3 flex justify-end">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-300">
                    <i class="fas fa-search mr-2"></i> Search Jobs
                </button>
            </div>
        </form>
    </div>
    
    <?php if ($userRole === 'jobseeker' && !empty($recommendedJobs)): ?>
    <!-- Recommended Jobs Section -->
    <div class="mb-8">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Recommended for You</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach ($recommendedJobs as $job): ?>
                <?php if ($job['match_score'] >= 0.5): ?>
                    <div class="bg-white rounded-lg shadow-sm p-4 border-l-4 border-green-500 relative">
                        <div class="absolute top-2 right-2">
                            <?php if ($job['match_score'] >= 0.8): ?>
                                <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                    Highly Recommended
                                </span>
                            <?php else: ?>
                                <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                    Recommended
                                </span>
                            <?php endif; ?>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 mb-2 pr-24">
                            <a href="view-job.php?id=<?php echo $job['id']; ?>" class="hover:text-blue-600">
                                <?php echo htmlspecialchars($job['title']); ?>
                            </a>
                        </h3>
                        <p class="text-gray-600 mb-2"><?php echo htmlspecialchars($job['company_name']); ?></p>
                        <div class="flex items-center text-sm text-gray-500 mb-3">
                            <i class="fas fa-map-marker-alt mr-2"></i>
                            <span><?php echo htmlspecialchars($job['location']); ?></span>
                            <span class="mx-2">•</span>
                            <span><?php echo htmlspecialchars($job['job_type']); ?></span>
                        </div>
                        <div class="flex flex-wrap gap-2 mb-3">
                            <?php 
                            $jobFields = explode(', ', $job['fields']);
                            foreach ($jobFields as $field): 
                                $fieldMatch = in_array(trim($field), $seekerFields);
                            ?>
                                <span class="bg-<?php echo $fieldMatch ? 'green' : 'blue'; ?>-100 text-<?php echo $fieldMatch ? 'green' : 'blue'; ?>-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                    <?php echo htmlspecialchars(trim($field)); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-500">
                                Posted <?php echo time_elapsed_string($job['created_at']); ?>
                            </span>
                            <a href="view-job.php?id=<?php echo $job['id']; ?>" class="text-blue-600 hover:text-blue-800 font-medium text-sm">
                                View Details
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- All Jobs Section -->
    <h2 class="text-xl font-bold text-gray-800 mb-4">
        <?php echo !empty($searchTerm) || !empty($locationTerm) || !empty($fieldFilter) ? 'Search Results' : 'All Jobs'; ?>
    </h2>
    
    <?php if (empty($jobs)): ?>
        <div class="bg-white rounded-lg shadow-sm p-8 text-center">
            <i class="fas fa-search text-gray-400 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-800 mb-2">No jobs found</h3>
            <p class="text-gray-600">Try adjusting your search criteria or browse all available jobs.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 gap-4">
            <?php foreach ($jobs as $job): ?>
                <?php 
                $isRecommended = false;
                $matchScore = 0;
                
                if ($userRole === 'jobseeker' && !empty($seekerFields) && !empty($seekerSkills)) {
                    $jobFields = explode(', ', $job['fields']);
                    $matchScore = calculateJobMatchScore($jobFields, $seekerFields, $seekerSkills);
                    $isRecommended = ($matchScore >= 0.5);
                }
                ?>
                <div class="bg-white rounded-lg shadow-sm p-6 <?php echo $isRecommended ? 'border-l-4 border-blue-500' : ''; ?>">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div class="mb-4 md:mb-0">
                            <h3 class="text-lg font-semibold text-gray-800 mb-1">
                                <a href="view-job.php?id=<?php echo $job['id']; ?>" class="hover:text-blue-600">
                                    <?php echo htmlspecialchars($job['title']); ?>
                                </a>
                                <?php if ($isRecommended): ?>
                                    <span class="ml-2 bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                        <?php echo $matchScore >= 0.8 ? 'Highly Recommended' : 'Might Interest You'; ?>
                                    </span>
                                <?php endif; ?>
                            </h3>
                            <p class="text-gray-600 mb-2"><?php echo htmlspecialchars($job['company_name']); ?></p>
                            <div class="flex items-center text-sm text-gray-500">
                                <i class="fas fa-map-marker-alt mr-2"></i>
                                <span><?php echo htmlspecialchars($job['location']); ?></span>
                                <span class="mx-2">•</span>
                                <span><?php echo htmlspecialchars($job['job_type']); ?></span>
                                <span class="mx-2">•</span>
                                <span>Posted <?php echo time_elapsed_string($job['created_at']); ?></span>
                            </div>
                        </div>
                        <div>
                            <a href="view-job.php?id=<?php echo $job['id']; ?>" class="inline-block px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition duration-300">
                                View Details
                            </a>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <p class="text-gray-700 line-clamp-2">
                            <?php echo htmlspecialchars(substr(strip_tags($job['description']), 0, 150)) . (strlen($job['description']) > 150 ? '...' : ''); ?>
                        </p>
                    </div>
                    
                    <div class="mt-4 flex flex-wrap gap-2">
                        <?php 
                        $jobFields = explode(', ', $job['fields']);
                        foreach ($jobFields as $field): 
                            $fieldMatch = $userRole === 'jobseeker' && in_array(trim($field), $seekerFields);
                        ?>
                            <span class="bg-<?php echo $fieldMatch ? 'green' : 'blue'; ?>-100 text-<?php echo $fieldMatch ? 'green' : 'blue'; ?>-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                <?php echo htmlspecialchars(trim($field)); ?>
                            </span>
                        <?php endforeach; ?>
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