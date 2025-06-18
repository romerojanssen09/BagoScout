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

// Check if job ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: jobs.php");
    exit();
}

$jobId = intval($_GET['id']);

// Get job details
$conn = getDbConnection();
$stmt = $conn->prepare("
    SELECT j.*, e.company_name, e.company_type, u.first_name, u.last_name, u.email, u.profile, u.id as user_id
                       FROM jobs j
                       JOIN employers e ON j.employer_id = e.id
                       JOIN users u ON e.user_id = u.id
    WHERE j.id = ?
");
$stmt->bind_param("i", $jobId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: jobs.php");
    exit();
}

$job = $result->fetch_assoc();
$stmt->close();

// Get jobseeker ID if user is a jobseeker
$seekerId = null;
$hasApplied = false;
$application = null;

if ($userRole === 'jobseeker') {
    $stmt = $conn->prepare("SELECT id FROM jobseekers WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $seeker = $result->fetch_assoc();
        $seekerId = $seeker['id'];
        
        // Check if user has already applied
        $application = hasAppliedToJob($jobId, $seekerId);
        $hasApplied = ($application !== false);
        }
        $stmt->close();
}

// Process comment submission
$commentSuccess = '';
$commentError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Handle comment submission
        if ($_POST['action'] === 'add_comment' && isset($_POST['comment']) && !empty($_POST['comment'])) {
            $comment = sanitizeInput($_POST['comment']);
            
            $commentId = addJobComment($jobId, $userId, $comment);
            
            if ($commentId) {
                $commentSuccess = "Comment added successfully.";
            } else {
                $commentError = "Failed to add comment. Please try again.";
            }
        }
        
        // Handle reply submission
        if ($_POST['action'] === 'add_reply' && isset($_POST['reply']) && !empty($_POST['reply']) && isset($_POST['comment_id'])) {
            $reply = sanitizeInput($_POST['reply']);
            $commentId = intval($_POST['comment_id']);
            
            $replyId = addCommentReply($commentId, $userId, $reply);
            
            if ($replyId) {
                $commentSuccess = "Reply added successfully.";
            } else {
                $commentError = "Failed to add reply. Please try again.";
            }
        }
        
        // Handle comment deletion
        if ($_POST['action'] === 'delete_comment' && isset($_POST['comment_id'])) {
            $commentId = intval($_POST['comment_id']);
            
            if (deleteJobComment($commentId, $userId)) {
                $commentSuccess = "Comment deleted successfully.";
            } else {
                $commentError = "Failed to delete comment. Please try again.";
            }
        }
        
        // Handle reply deletion
        if ($_POST['action'] === 'delete_reply' && isset($_POST['reply_id'])) {
            $replyId = intval($_POST['reply_id']);
            
            if (deleteCommentReply($replyId, $userId)) {
                $commentSuccess = "Reply deleted successfully.";
        } else {
                $commentError = "Failed to delete reply. Please try again.";
            }
        }
    }
}

// Get comments for this job
$comments = getJobComments($jobId);

// Format job fields
$jobFields = explode(', ', $job['fields']);

// Format salary range
$salaryMin = $job['salary_min'];
$salaryMax = $job['salary_max'];
$payType = $job['pay_type'];

$salaryDisplay = '';
if (!empty($salaryMin) && !empty($salaryMax)) {
    $salaryDisplay = '₱' . number_format($salaryMin) . ' - ₱' . number_format($salaryMax);
    
    if ($payType === 'hourly') {
        $salaryDisplay .= ' per hour';
    } elseif ($payType === 'monthly') {
        $salaryDisplay .= ' per month';
    } elseif ($payType === 'annual') {
        $salaryDisplay .= ' per year';
    }
} elseif (!empty($salaryMin)) {
    $salaryDisplay = 'From ₱' . number_format($salaryMin);
    
    if ($payType === 'hourly') {
        $salaryDisplay .= ' per hour';
    } elseif ($payType === 'monthly') {
        $salaryDisplay .= ' per month';
    } elseif ($payType === 'annual') {
        $salaryDisplay .= ' per year';
    }
} elseif (!empty($salaryMax)) {
    $salaryDisplay = 'Up to ₱' . number_format($salaryMax);
    
    if ($payType === 'hourly') {
        $salaryDisplay .= ' per hour';
    } elseif ($payType === 'monthly') {
        $salaryDisplay .= ' per month';
    } elseif ($payType === 'annual') {
        $salaryDisplay .= ' per year';
    }
} else {
    $salaryDisplay = 'Not specified';
}

// Format job requirements
$requirements = nl2br($job['requirements']);

// Format job description
$description = nl2br($job['description']);

// Format response time
$responseTime = '';
switch ($job['response_time']) {
    case 'within_hour':
        $responseTime = 'Within an hour';
        break;
    case 'within_day':
        $responseTime = 'Within a day';
        break;
    case 'within_week':
        $responseTime = 'Within a week';
        break;
    default:
        $responseTime = 'Not specified';
}

// Format required documents
$requiredDocs = $job['required_documents'];
$requiredDocsArray = explode(',', str_replace(['none', '{', '}'], '', $requiredDocs));
$requiredDocsArray = array_filter($requiredDocsArray);

$requiredDocsDisplay = [];
foreach ($requiredDocsArray as $doc) {
    $doc = trim($doc);
    if ($doc === 'resume') {
        $requiredDocsDisplay[] = 'Resume/CV';
    } elseif ($doc === 'police_clearance') {
        $requiredDocsDisplay[] = 'Police Clearance';
    }
}

// Format job location
$latitude = $job['latitude'];
$longitude = $job['longitude'];

// Get Mapbox API key from config
require_once '../../../config/api_keys.php';
$mapboxApiKey = getApiKey('mapbox');

$pageTitle = "View Job: " . $job['title'];

// Add Mapbox CSS in the head section
$extraHeadContent = '
<link href="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js"></script>
<style>
    /* Add Mapbox CSS */
    #job-location-map {
        border: 1px solid #e2e8f0;
        height: 400px;
        width: 100%;
        border-radius: 8px;
    }
    
    .mapboxgl-popup-content {
        padding: 12px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .map-controls {
        margin-top: 10px;
        display: flex;
        gap: 10px;
    }
    
    .map-button {
        flex: 1;
        background-color: #2563eb;
        color: white;
        font-weight: 500;
        padding: 10px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background-color 0.2s;
        text-decoration: none;
    }
    
    .map-button:hover {
        background-color: #1d4ed8;
    }
    
    .map-button i {
        margin-right: 8px;
    }
    
    /* Route display styles */
    .directions-control {
        position: absolute;
        top: 10px;
        left: 10px;
        z-index: 1;
        background: white;
        padding: 10px;
        border-radius: 4px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        width: 300px;
        max-width: calc(100% - 20px);
        display: none;
    }
    
    .directions-control.active {
        display: block;
    }
    
    .directions-close {
        position: absolute;
        top: 5px;
        right: 5px;
        cursor: pointer;
        font-size: 16px;
        color: #666;
    }
    
    .route-info {
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid #eee;
    }
    
    .route-distance, .route-duration {
        display: flex;
        align-items: center;
        margin-bottom: 5px;
        font-size: 14px;
    }
    
    .route-distance i, .route-duration i {
        margin-right: 8px;
        color: #2563eb;
    }
    
    /* Fixed position marker */
    .marker-pin {
        width: 30px;
        height: 30px;
        position: relative;
    }
    
    .marker-pin .shadow {
        width: 20px;
        height: 6px;
        background: rgba(0,0,0,0.2);
        border-radius: 50%;
        position: absolute;
        bottom: -3px;
        left: 5px;
        filter: blur(2px);
        animation: shadow 1s ease-in-out infinite alternate;
    }
    
    @keyframes shadow {
        from {
            transform: scale(1);
            opacity: 0.5;
        }
        to {
            transform: scale(0.8);
            opacity: 0.3;
        }
    }
    
    /* Custom popup style */
    .job-popup {
        text-align: center;
        max-width: 250px;
    }
    
    .job-popup h4 {
        margin: 0 0 5px 0;
        font-weight: 600;
        color: #111827;
        font-size: 16px;
    }
    
    .job-popup p {
        margin: 0;
        color: #6B7280;
    }
    
    .job-popup-pin {
        color: #10B981;
        font-size: 1.2rem;
        margin-bottom: 5px;
    }
    
    /* Custom popup container */
    .job-location-popup .mapboxgl-popup-content {
        padding: 15px;
        border-radius: 10px;
        border-top: 4px solid #10B981;
        box-shadow: 0 4px 15px rgba(0,0,0,0.15);
    }
    
    .job-location-popup .mapboxgl-popup-tip {
        border-top-color: #10B981;
        border-width: 10px;
    }
    
    /* Loading indicator */
    .loading-directions {
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 10px 0;
    }
    
    .loading-spinner {
        border: 3px solid #f3f3f3;
        border-top: 3px solid #2563eb;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        animation: spin 1s linear infinite;
        margin-right: 10px;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    /* User location marker */
    .user-location-marker {
        width: 20px;
        height: 20px;
        background-color: #2563eb;
        border-radius: 50%;
        border: 3px solid white;
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.3);
    }
</style>
';

// Start output buffering for content
ob_start();
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row gap-6">
        <!-- Main Content -->
        <div class="w-full md:w-2/3">
            <?php if (!empty($commentSuccess)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                    <p><?php echo $commentSuccess; ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($commentError)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                    <p><?php echo $commentError; ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Job Details -->
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <div class="flex justify-between items-start mb-4">
                    <h1 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($job['title']); ?></h1>
                    
                    <?php if ($job['status'] === 'active'): ?>
                        <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded">Active</span>
                    <?php elseif ($job['status'] === 'paused'): ?>
                        <span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-0.5 rounded">Paused</span>
                    <?php else: ?>
                        <span class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded">Closed</span>
                    <?php endif; ?>
                </div>

                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden mr-3">
                        <?php if (!empty($job['profile'])): ?>
                            <img src="../../uploads/profiles/<?php echo $job['profile']; ?>" alt="<?php echo htmlspecialchars($job['company_name']); ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <i class="fas fa-building text-gray-400 text-xl"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($job['company_name']); ?></h2>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($job['company_type']); ?></p>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <p class="text-sm text-gray-600 mb-1">Location:</p>
                        <p class="font-medium"><?php echo htmlspecialchars($job['location']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 mb-1">Job Type:</p>
                        <p class="font-medium"><?php echo htmlspecialchars($job['job_type']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 mb-1">Salary:</p>
                        <p class="font-medium"><?php echo $salaryDisplay; ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 mb-1">Application Deadline:</p>
                        <p class="font-medium">
                            <?php echo !empty($job['deadline']) ? date('F j, Y', strtotime($job['deadline'])) : 'Not specified'; ?>
                        </p>
                    </div>
                </div>
                
                <!-- Map Section -->
                <?php if (!empty($latitude) && !empty($longitude)): ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Location Map</h3>
                    <div id="job-location-map" style="width: 100%; height: 400px; border-radius: 8px;"></div>
                    <div class="map-controls">
                        <button id="get-directions-btn" class="map-button">
                            <i class="fas fa-route"></i> Get Directions
                        </button>
                        <button id="center-map-btn" class="map-button">
                            <i class="fas fa-crosshairs"></i> Center Map
                        </button>
                    </div>
                    
                    <!-- Directions control panel -->
                    <div id="directions-control" class="directions-control">
                        <div class="directions-close" id="close-directions"><i class="fas fa-times"></i></div>
                        <h4 class="font-medium mb-2">Directions to Job Location</h4>
                        <div id="directions-status" class="text-sm mb-2">Getting your location...</div>
                        <div id="loading-directions" class="loading-directions" style="display:none;">
                            <div class="loading-spinner"></div>
                            <span>Calculating route...</span>
                        </div>
                        <div id="route-info" class="route-info" style="display:none;">
                            <div class="route-distance"><i class="fas fa-road"></i> <span id="route-distance">--</span></div>
                            <div class="route-duration"><i class="fas fa-clock"></i> <span id="route-duration">--</span></div>
                        </div>
                    </div>
                    
                    <!-- Fallback map in case the JavaScript map fails -->
                    <div class="mt-4">
                        <p class="text-sm text-gray-500">If the map doesn't display, you can <a href="https://www.google.com/maps?q=<?php echo $latitude; ?>,<?php echo $longitude; ?>" target="_blank" class="text-blue-500 hover:underline">view the location on Google Maps</a>.</p>
                    </div>
                    
                    <!-- Direct map script -->
                    <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        try {
                            console.log("Initializing map directly...");
                            mapboxgl.accessToken = "<?php echo $mapboxApiKey; ?>";
                            
                            const jobLocation = [<?php echo $longitude; ?>, <?php echo $latitude; ?>];
                            console.log("Job location:", jobLocation);
                            
                            const map = new mapboxgl.Map({
                                container: "job-location-map",
                                style: "mapbox://styles/mapbox/streets-v12",
                                center: jobLocation,
                                zoom: 15
                            });
                            
                            map.addControl(new mapboxgl.NavigationControl());
                            
                            // Global variables for directions
                            let userLocation = null;
                            let directionsShown = false;
                            let userLocationMarker = null;
                            
                            // Wait for map to load
                            map.on('load', function() {
                                // Create a new marker element
                                const markerEl = document.createElement('div');
                                markerEl.className = 'marker-pin';
                                
                                // Create shadow elements
                                const shadow = document.createElement('div');
                                shadow.className = 'shadow';
                                
                                // Append pin and shadow to marker
                                // markerEl.appendChild(pin);
                                markerEl.appendChild(shadow);
                                
                                // Create and add the marker
                                const marker = new mapboxgl.Marker({
                                    element: markerEl,
                                    anchor: 'bottom',
                                    offset: [0, 0]  // No offset needed with proper anchor
                                })
                                .setLngLat(jobLocation)
                                .addTo(map);
                                
                                // Add popup
                                const popup = new mapboxgl.Popup({
                                    closeButton: false,
                                    closeOnClick: false,
                                    offset: [0, -15],
                                    className: 'job-location-popup',
                                    anchor: 'bottom'
                                })
                                .setLngLat(jobLocation)
                                .setHTML(`
                                    <div class="job-popup">
                                        <div class="job-popup-pin"><i class="fas fa-map-pin"></i></div>
                                        <h4><?php echo htmlspecialchars(addslashes($job['title'])); ?></h4>
                                        <p><?php echo htmlspecialchars(addslashes($job['location'])); ?></p>
                                        <p class="text-xs mt-1 text-green-600">Exact Location</p>
                                    </div>
                                `)
                                .addTo(map);
                                
                                // Add a fixed point marker that won't move with zoom
                                map.addSource('exact-point', {
                                    'type': 'geojson',
                                    'data': {
                                        'type': 'FeatureCollection',
                                        'features': [{
                                            'type': 'Feature',
                                            'geometry': {
                                                'type': 'Point',
                                                'coordinates': jobLocation
                                            },
                                            'properties': {
                                                'title': '<?php echo htmlspecialchars(addslashes($job['title'])); ?>'
                                            }
                                        }]
                                    }
                                });
                                
                                // Add a small dot at the exact location
                                map.addLayer({
                                    'id': 'exact-point',
                                    'type': 'circle',
                                    'source': 'exact-point',
                                    'paint': {
                                        'circle-radius': 4,
                                        'circle-color': '#10B981',
                                        'circle-stroke-width': 2,
                                        'circle-stroke-color': '#ffffff'
                                    }
                                });
                                
                                // Add source for route line
                                map.addSource('route', {
                                    'type': 'geojson',
                                    'data': {
                                        'type': 'Feature',
                                        'properties': {},
                                        'geometry': {
                                            'type': 'LineString',
                                            'coordinates': []
                                        }
                                    }
                                });
                                
                                // Add route line layer
                                map.addLayer({
                                    'id': 'route',
                                    'type': 'line',
                                    'source': 'route',
                                    'layout': {
                                        'line-join': 'round',
                                        'line-cap': 'round'
                                    },
                                    'paint': {
                                        'line-color': '#2563eb',
                                        'line-width': 5,
                                        'line-opacity': 0.75
                                    }
                                });
                                
                                // Function to get user's location
                                function getUserLocation() {
                                    return new Promise((resolve, reject) => {
                                        navigator.geolocation.getCurrentPosition(
                                            position => {
                                                const userCoords = [position.coords.longitude, position.coords.latitude];
                                                resolve(userCoords);
                                            },
                                            error => {
                                                reject(error);
                                            },
                                            {
                                                enableHighAccuracy: true,
                                                timeout: 10000
                                            }
                                        );
                                    });
                                }
                                
                                // Function to get directions
                                async function getDirections(start, end) {
                                    try {
                                        const response = await fetch(
                                            `https://api.mapbox.com/directions/v5/mapbox/driving/${start[0]},${start[1]};${end[0]},${end[1]}?steps=true&geometries=geojson&access_token=${mapboxgl.accessToken}`
                                        );
                                        
                                        if (!response.ok) throw new Error('Network response was not ok');
                                        
                                        const data = await response.json();
                                        if (data.code !== 'Ok') throw new Error(data.message || 'Could not get directions');
                                        
                                        return data;
                                    } catch (error) {
                                        console.error('Error getting directions:', error);
                                        throw error;
                                    }
                                }
                                
                                // Function to display route
                                function displayRoute(routeData) {
                                    const route = routeData.routes[0];
                                    const routeGeometry = route.geometry;
                                    
                                    // Update the route source with the new coordinates
                                    map.getSource('route').setData({
                                        'type': 'Feature',
                                        'properties': {},
                                        'geometry': routeGeometry
                                    });
                                    
                                    // Get route distance and duration
                                    const distance = (route.distance / 1000).toFixed(1); // km
                                    const duration = Math.round(route.duration / 60); // minutes
                                    
                                    // Update route info in the UI
                                    document.getElementById('route-distance').textContent = `${distance} km`;
                                    document.getElementById('route-duration').textContent = `${duration} min`;
                                    document.getElementById('route-info').style.display = 'block';
                                    
                                    // Fit map to show the entire route
                                    const bounds = new mapboxgl.LngLatBounds();
                                    routeGeometry.coordinates.forEach(coord => {
                                        bounds.extend(coord);
                                    });
                                    
                                    map.fitBounds(bounds, {
                                        padding: 50,
                                        maxZoom: 15,
                                        duration: 1000
                                    });
                                }
                                
                                // Get directions button click handler
                                document.getElementById('get-directions-btn').addEventListener('click', async function() {
                                    const directionsControl = document.getElementById('directions-control');
                                    const loadingDirections = document.getElementById('loading-directions');
                                    const directionsStatus = document.getElementById('directions-status');
                                    const routeInfo = document.getElementById('route-info');
                                    
                                    // Toggle directions panel
                                    if (directionsControl.classList.contains('active')) {
                                        directionsControl.classList.remove('active');
                                        
                                        // Remove route from map if it exists
                                        if (directionsShown) {
                                            map.getSource('route').setData({
                                                'type': 'Feature',
                                                'properties': {},
                                                'geometry': {
                                                    'type': 'LineString',
                                                    'coordinates': []
                                                }
                                            });
                                            
                                            // Remove user location marker
                                            if (userLocationMarker) {
                                                userLocationMarker.remove();
                                                userLocationMarker = null;
                                            }
                                            
                                            directionsShown = false;
                                        }
                                        
                                        return;
                                    }
                                    
                                    // Show directions panel
                                    directionsControl.classList.add('active');
                                    directionsStatus.textContent = 'Getting your location...';
                                    loadingDirections.style.display = 'flex';
                                    routeInfo.style.display = 'none';
                                    
                                    try {
                                        // Get user's location
                                        userLocation = await getUserLocation();
                                        directionsStatus.textContent = 'Calculating route...';
                                        
                                        // Add user location marker
                                        if (userLocationMarker) userLocationMarker.remove();
                                        
                                        const userMarkerEl = document.createElement('div');
                                        userMarkerEl.className = 'user-location-marker';
                                        
                                        userLocationMarker = new mapboxgl.Marker({
                                            element: userMarkerEl,
                                            anchor: 'center'
                                        })
                                        .setLngLat(userLocation)
                                        .addTo(map);
                                        
                                        // Get directions
                                        const directions = await getDirections(userLocation, jobLocation);
                                        
                                        // Display route
                                        displayRoute(directions);
                                        directionsShown = true;
                                        
                                        // Update UI
                                        directionsStatus.textContent = 'Route found:';
                                        loadingDirections.style.display = 'none';
                                        
                                    } catch (error) {
                                        console.error('Error:', error);
                                        directionsStatus.textContent = error.message === 'User denied Geolocation' 
                                            ? 'Location access denied. Please enable location services.' 
                                            : 'Could not get directions. Please try again.';
                                        loadingDirections.style.display = 'none';
                                    }
                                });
                                
                                // Close directions panel
                                document.getElementById('close-directions').addEventListener('click', function() {
                                    document.getElementById('directions-control').classList.remove('active');
                                    
                                    // Remove route from map if it exists
                                    if (directionsShown) {
                                        map.getSource('route').setData({
                                            'type': 'Feature',
                                            'properties': {},
                                            'geometry': {
                                                'type': 'LineString',
                                                'coordinates': []
                                            }
                                        });
                                        
                                        // Remove user location marker
                                        if (userLocationMarker) {
                                            userLocationMarker.remove();
                                            userLocationMarker = null;
                                        }
                                        
                                        directionsShown = false;
                                    }
                                });
                            });
                            
                            // Center map button
                            document.getElementById("center-map-btn").addEventListener("click", function() {
                                map.flyTo({
                                    center: jobLocation,
                                    zoom: 18,  // Zoom in closer to see the exact point
                                    pitch: 0,  // No tilt for better accuracy
                                    bearing: 0,
                                    essential: true,
                                    duration: 1000
                                });
                            });
                        } catch (error) {
                            console.error("Error initializing map:", error);
                            document.getElementById("job-location-map").innerHTML = "<div class='p-4 bg-red-50 text-red-700 rounded'>Could not load map. Please check your internet connection or try again later.</div>";
                        }
                    });
                    </script>
                </div>
                <?php endif; ?>
                
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Job Description</h3>
                    <div class="text-gray-700">
                        <?php echo $description; ?>
                    </div>
                </div>
                
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Requirements</h3>
                    <div class="text-gray-700">
                        <?php echo $requirements; ?>
                </div>
            </div>
            
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Fields</h3>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($jobFields as $field): ?>
                            <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded">
                                <?php echo htmlspecialchars(trim($field)); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Response Time</h3>
                        <p class="text-gray-700"><?php echo $responseTime; ?></p>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Required Documents</h3>
                        <?php if (empty($requiredDocsDisplay)): ?>
                            <p class="text-gray-700">None</p>
                        <?php else: ?>
                            <ul class="list-disc list-inside text-gray-700">
                                <?php foreach ($requiredDocsDisplay as $doc): ?>
                                    <li><?php echo $doc; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
            </div>
                </div>
                
                <?php if ($userRole === 'jobseeker' && $job['status'] === 'active'): ?>
                    <div class="border-t border-gray-200 pt-4">
                        <?php if ($hasApplied): ?>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-green-600 font-medium">You have already applied for this job</p>
                                    <p class="text-sm text-gray-600">Application status: <?php echo ucfirst($application['status']); ?></p>
                                </div>
                                <form action="apply-job.php" method="post">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="application_id" value="<?php echo $application['id']; ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800 font-medium">
                                        Cancel Application
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <a href="apply-job.php?job_id=<?php echo $jobId; ?>" class="inline-block bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded">
                                Apply for this job
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Comments Section -->
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Comments</h3>
                
                <!-- Comment Form -->
                <form action="view-job.php?id=<?php echo $jobId; ?>" method="post" class="mb-6">
                    <input type="hidden" name="action" value="add_comment">
                    <div class="mb-4">
                        <textarea name="comment" rows="3" class="w-full px-3 py-2 text-gray-700 border rounded-lg focus:outline-none focus:border-blue-500" placeholder="Add a comment..."></textarea>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded">
                        Post Comment
                    </button>
                    </div>
                </form>
                
                <!-- Comments List -->
                <div class="space-y-6">
                    <?php if (empty($comments)): ?>
                        <p class="text-gray-500 italic">No comments yet. Be the first to comment!</p>
                    <?php else: ?>
                        <?php foreach ($comments as $comment): ?>
                            <div class="border-b border-gray-200 pb-6 last:border-b-0 last:pb-0" id="comment-<?php echo $comment['id']; ?>">
                                <div class="flex items-start">
                                    <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden mr-3">
                                        <?php if (!empty($comment['profile'])): ?>
                                            <img src="../../uploads/profiles/<?php echo $comment['profile']; ?>" alt="<?php echo htmlspecialchars($comment['first_name']); ?>" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <i class="fas fa-user text-gray-400"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between">
                                            <h4 class="font-medium text-gray-800">
                                                <?php echo htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']); ?>
                                            </h4>
                                            <span class="text-sm text-gray-500">
                                                <?php echo time_elapsed_string($comment['created_at']); ?>
                                            </span>
                                        </div>
                                        <p class="text-gray-700 mt-1">
                                            <?php echo nl2br(htmlspecialchars($comment['comment'])); ?>
                                        </p>
                                        <div class="flex items-center mt-2">
                                            <button class="text-sm text-blue-600 hover:text-blue-800 mr-4 reply-button" data-comment-id="<?php echo $comment['id']; ?>">
                                                Reply
                                            </button>
                                            <?php if ($comment['user_id'] === $userId): ?>
                                                <form action="view-job.php?id=<?php echo $jobId; ?>" method="post" class="inline">
                                                    <input type="hidden" name="action" value="delete_comment">
                                                    <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                                                    <button type="submit" class="text-sm text-red-600 hover:text-red-800">
                                                        Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Reply Form (Hidden by default) -->
                                        <div class="reply-form mt-3 hidden" id="reply-form-<?php echo $comment['id']; ?>">
                                            <form action="view-job.php?id=<?php echo $jobId; ?>" method="post">
                                                <input type="hidden" name="action" value="add_reply">
                                                <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                                                <div class="mb-2">
                                                    <textarea name="reply" rows="2" class="w-full px-3 py-2 text-gray-700 border rounded-lg focus:outline-none focus:border-blue-500" placeholder="Add a reply..."></textarea>
                                                </div>
                                                <div class="flex justify-end">
                                                    <button type="button" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-1 px-3 rounded mr-2 cancel-reply" data-comment-id="<?php echo $comment['id']; ?>">
                                                        Cancel
                                                    </button>
                                                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-1 px-3 rounded">
                                                        Reply
                                                    </button>
                                                </div>
                                        </form>
                                    </div>
                                    
                                        <!-- Replies List -->
                                        <?php if (!empty($comment['replies'])): ?>
                                            <div class="mt-4 pl-4 border-l-2 border-gray-200 space-y-4">
                                                <?php foreach ($comment['replies'] as $reply): ?>
                                                    <div class="flex items-start" id="reply-<?php echo $reply['id']; ?>">
                                                        <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden mr-2">
                                                            <?php if (!empty($reply['profile'])): ?>
                                                                <img src="../../uploads/profiles/<?php echo $reply['profile']; ?>" alt="<?php echo htmlspecialchars($reply['first_name']); ?>" class="w-full h-full object-cover">
                                                            <?php else: ?>
                                                                <i class="fas fa-user text-gray-400 text-sm"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="flex-1">
                                                            <div class="flex items-center justify-between">
                                                                <h5 class="font-medium text-gray-800 text-sm">
                                                                    <?php echo htmlspecialchars($reply['first_name'] . ' ' . $reply['last_name']); ?>
                                                                </h5>
                                                                <span class="text-xs text-gray-500">
                                                                    <?php echo time_elapsed_string($reply['created_at']); ?>
                                                                </span>
                                                            </div>
                                                            <p class="text-gray-700 text-sm mt-1">
                                                                <?php echo nl2br(htmlspecialchars($reply['reply'])); ?>
                                                            </p>
                                                            <?php if ($reply['user_id'] === $userId): ?>
                                                                <div class="mt-1">
                                                                    <form action="view-job.php?id=<?php echo $jobId; ?>" method="post" class="inline">
                                                                        <input type="hidden" name="action" value="delete_reply">
                                                                        <input type="hidden" name="reply_id" value="<?php echo $reply['id']; ?>">
                                                                        <button type="submit" class="text-xs text-red-600 hover:text-red-800">
                                                                            Delete
                                                                        </button>
                                                                    </form>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Sidebar -->
        <div class="w-full md:w-1/3">
        <!-- Employer Info -->
        <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Employer Information</h3>
                
            <div class="flex items-center mb-4">
                    <div class="w-12 h-12 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden mr-3">
                        <?php if (!empty($job['profile'])): ?>
                            <img src="../../uploads/profiles/<?php echo $job['profile']; ?>" alt="<?php echo htmlspecialchars($job['company_name']); ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                            <i class="fas fa-building text-gray-400 text-xl"></i>
                        <?php endif; ?>
                    </div>
                <div>
                        <h4 class="font-medium text-gray-800"><?php echo htmlspecialchars($job['company_name']); ?></h4>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($job['company_type']); ?></p>
                    </div>
        </div>
        
                <div class="space-y-2">
                    <p class="text-sm">
                        <span class="font-medium">Contact Person:</span>
                        <?php echo htmlspecialchars($job['first_name'] . ' ' . $job['last_name']); ?>
                    </p>
                    <p class="text-sm">
                        <span class="font-medium">Email:</span>
                        <?php echo htmlspecialchars($job['email']); ?>
                    </p>
                </div>
                
                <div class="mt-4">
                    <a href="message-new.php?receiver=<?php echo $job['user_id']; ?>" class="inline-block w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded text-center">
                        <i class="fas fa-envelope mr-2"></i> Contact Employer
                    </a>
                </div>
            </div>
            
            <!-- Job Details Summary -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Job Details</h3>
                
                <ul class="space-y-3">
                    <li class="flex items-start">
                        <i class="fas fa-map-marker-alt text-blue-500 mt-1 mr-3"></i>
                        <div>
                            <p class="font-medium text-gray-700">Location</p>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($job['location']); ?></p>
                        </div>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-briefcase text-blue-500 mt-1 mr-3"></i>
                        <div>
                            <p class="font-medium text-gray-700">Job Type</p>
                            <p class="text-sm text-gray-600"><?php echo htmlspecialchars($job['job_type']); ?></p>
                        </div>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-money-bill-wave text-blue-500 mt-1 mr-3"></i>
                        <div>
                            <p class="font-medium text-gray-700">Salary</p>
                            <p class="text-sm text-gray-600"><?php echo $salaryDisplay; ?></p>
                        </div>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-calendar-alt text-blue-500 mt-1 mr-3"></i>
                        <div>
                            <p class="font-medium text-gray-700">Posted On</p>
                            <p class="text-sm text-gray-600"><?php echo date('F j, Y', strtotime($job['created_at'])); ?></p>
                    </div>
                    </li>
                    <?php if (!empty($job['deadline'])): ?>
                    <li class="flex items-start">
                        <i class="fas fa-hourglass-end text-blue-500 mt-1 mr-3"></i>
                        <div>
                            <p class="font-medium text-gray-700">Application Deadline</p>
                            <p class="text-sm text-gray-600"><?php echo date('F j, Y', strtotime($job['deadline'])); ?></p>
                </div>
                    </li>
                    <?php endif; ?>
                </ul>
                </div>
        </div>
    </div>
</div>

<?php
// Store the content buffer
$content = ob_get_clean();

// Define JavaScript for the page
$extraScript = '
<script>
document.addEventListener("DOMContentLoaded", function() {
    // Debug logging
    console.log("DOM loaded");
    
    // Reply button functionality
    document.querySelectorAll(".reply-button").forEach(button => {
        button.addEventListener("click", function() {
            const commentId = this.getAttribute("data-comment-id");
            document.getElementById(`reply-form-${commentId}`).classList.toggle("hidden");
        });
    });
    
    // Cancel reply button functionality
    document.querySelectorAll(".cancel-reply").forEach(button => {
        button.addEventListener("click", function() {
            const commentId = this.getAttribute("data-comment-id");
            document.getElementById(`reply-form-${commentId}`).classList.add("hidden");
        });
    });
});
</script>
';

// Include the layout
include_once 'nav/layout.php';
?>
