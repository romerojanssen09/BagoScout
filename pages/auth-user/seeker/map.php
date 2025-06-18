<?php
session_start();
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';
require_once '../../../config/api_keys.php';

// Get current user
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

if ($currentUser['status'] !== 'active') {
    if ($currentUser['status'] === 'under_review') {
        $_SESSION['warning'] = "Your account is under review. An administrator will review your information shortly.";
    } else if ($currentUser['status'] === 'suspended') {
        $_SESSION['warning'] = "Your account is suspended. Please contact the administrator.";
    } else if ($currentUser['status'] === 'deleted') {
        $_SESSION['warning'] = "Your account is deleted. Please contact the administrator.";
    } else {
        $_SESSION['warning'] = "Your account is not active. Please request for approval first.";
    }
    header("Location: settings.php#request-approval");
    exit();
}

// Set page title for layout
$pageTitle = "Job Postings Map";

// Get jobseeker's applications
$conn = getDbConnection();
$stmt = $conn->prepare("
    SELECT a.job_id
    FROM applications a
    WHERE a.jobseeker_id = ?
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$appliedJobIds = [];
while ($row = $result->fetch_assoc()) {
    $appliedJobIds[] = $row['job_id'];
}

$stmt->close();
$conn->close();

// Get API keys
$mapboxApiKey = getApiKey('mapbox');
$ablyApiKey = getApiKey('ably');

// Add custom styles and scripts for the layout
$extraHeadContent = '
<link href="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.css" rel="stylesheet">
<link rel="stylesheet" href="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v5.0.0/mapbox-gl-geocoder.css" type="text/css">
<link rel="stylesheet" href="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-directions/v4.1.1/mapbox-gl-directions.css" type="text/css">
<script src="https://unpkg.com/supercluster@7.1.5/dist/supercluster.min.js"></script>
<style>
    #map-container {
        position: relative;
        width: 100%;
        height: calc(100vh - 120px);
        border-radius: 0.5rem;
        overflow: hidden;
    }
    
    #map {
        width: 100%;
        height: 100%;
    }
    
    /* Marker cluster styling */
    .marker-cluster {
        background-color: #3B82F6;
        color: white;
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        border: 2px solid white;
        box-shadow: 0 0 0 2px rgba(0,0,0,0.1);
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .marker-cluster:hover {
        transform: scale(1.1);
        background-color: #2563EB;
    }
    
    .marker-cluster-large {
        background-color: #EF4444;
        width: 42px;
        height: 42px;
        font-size: 16px;
    }
    
    .marker-cluster-xlarge {
        background-color: #8B5CF6;
        width: 48px;
        height: 48px;
        font-size: 18px;
    }
    
    .mapboxgl-popup {
        max-width: 320px;
        pointer-events: auto;
    }
    
    .mapboxgl-popup-content {
        padding: 0;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        overflow: hidden;
    }
    
    .mapboxgl-popup-close-button {
        display: none;
    }
    
    .applicant-popup {
        background-color: white;
        width: 240px;
    }
    
    .popup-header {
        background-color: #4F46E5;
        color: white;
        padding: 12px 16px;
        border-top-left-radius: 8px;
        border-top-right-radius: 8px;
    }
    
    .popup-header h3 {
        color: white !important;
        font-size: 16px;
        margin: 0;
        font-weight: 600;
    }
    
    .popup-body {
        padding: 12px 16px;
    }
    
    .popup-body p {
        margin: 8px 0;
        font-size: 13px;
        color: #4B5563;
    }
    
    .popup-footer {
        padding: 8px 16px 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .popup-actions {
        display: flex;
        gap: 8px;
    }
    
    .popup-btn {
        background-color: #4F46E5;
        color: white;
        border: none;
        border-radius: 4px;
        padding: 6px 12px;
        font-size: 12px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .popup-btn:hover {
        background-color: #4338CA;
    }
    
    .popup-btn i {
        margin-right: 4px;
    }
    
    .route-btn {
        background-color: #3B82F6;
        color: white;
        padding: 0.25rem 0.5rem;
        border-radius: 0.25rem;
        font-size: 0.875rem;
        cursor: pointer;
        border: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        width: 100%;
        margin-top: 8px;
    }
    
    .route-btn:hover {
        background-color: #2563EB;
    }
    
    .pulse {
        animation: pulse 1.5s infinite;
    }
    
    @keyframes pulse {
        0% {
            transform: scale(1);
            opacity: 1;
        }
        50% {
            transform: scale(1.1);
            opacity: 0.7;
        }
        100% {
            transform: scale(1);
            opacity: 1;
        }
    }
    
    .directions-info-container {
        position: absolute;
        bottom: 5rem;
        left: 5px;
        background-color: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        width: 220px;
        display: none;
        z-index: 10;
        overflow: hidden;
    }
    
    .directions-info-title {
        background-color: #3B82F6;
        color: white;
        padding: 10px 16px;
        font-weight: 600;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    
    .directions-info-title i {
        margin-right: 8px;
    }
    
    #close-directions-btn {
        background: none;
        border: none;
        cursor: pointer;
        padding: 0;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: background-color 0.2s;
    }
    
    #close-directions-btn:hover {
        background-color: rgba(255, 255, 255, 0.2);
    }
    
    #close-directions-btn i {
        margin: 0;
        font-size: 14px;
    }
    
    .directions-info-detail {
        padding: 8px 16px;
        display: flex;
        align-items: center;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .directions-info-detail i {
        width: 20px;
        color: #4B5563;
        margin-right: 8px;
    }
    
    .directions-info-value {
        font-weight: 600;
        margin-right: 4px;
    }
    
    .directions-info-label {
        color: #6B7280;
        font-size: 12px;
    }
    
    .map-legend {
        background-color: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        padding: 12px;
        position: absolute;
        bottom: 2px;
        left: 2px;
        z-index: 10;
    }
    
    .job-posting-sidebar {
        position: fixed;
        right: 0;
        top: 0;
        height: 100%;
        width: 320px;
        background-color: white;
        box-shadow: -2px 0 10px rgba(0,0,0,0.1);
        transform: translateX(100%);
        transition: transform 0.3s ease;
        z-index: 20;
        overflow-y: auto;
    }
    
    .job-posting-sidebar.show {
        transform: translateX(0);
    }
    
    .toggle-sidebar-btn {
        position: absolute;
        top: 100px;
        right: 20px;
        width: 40px;
        height: 40px;
        background-color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        cursor: pointer;
        z-index: 10;
    }
    
    /* Toast notification animations */
    .animate-fade-in-up {
        animation: fadeInUp 0.3s ease-out forwards;
    }
    
    .animate-fade-out-down {
        animation: fadeOutDown 0.3s ease-in forwards;
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translate(-50%, 20px);
        }
        to {
            opacity: 1;
            transform: translate(-50%, 0);
        }
    }
    
    @keyframes fadeOutDown {
        from {
            opacity: 1;
            transform: translate(-50%, 0);
        }
        to {
            opacity: 0;
            transform: translate(-50%, 20px);
        }
    }
</style>
<script src="https://api.mapbox.com/mapbox-gl-js/v2.15.0/mapbox-gl.js"></script>
<script src="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-geocoder/v5.0.0/mapbox-gl-geocoder.min.js"></script>
<script src="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-directions/v4.1.1/mapbox-gl-directions.js"></script>
<script src="https://cdn.ably.io/lib/ably.min-1.js"></script>
';

// Main content for the layout
$content = '
<div class="">
    <div id="map-container">
        <div id="map"></div>
        
        <!-- Map Legend -->
        <div class="map-legend absolute bottom-0 left-0 bg-white p-3 rounded-md shadow-md z-10">
            <h4 class="text-sm font-medium mb-2">Map Legend</h4>
            <div class="flex items-center mb-2">
                <div class="w-4 h-4 rounded-full bg-green-500 mr-2"></div>
                <span class="text-xs">Applied Jobs</span>
            </div>
            <div class="flex items-center">
                <div class="w-4 h-4 rounded-full bg-blue-500 mr-2"></div>
                <span class="text-xs">Job Postings</span>
            </div>
        </div>
        
        <!-- Directions Info Container -->
        <div id="directions-info-container" class="directions-info-container">
            <div class="directions-info-title">
                <div>
                    <i class="fas fa-route"></i> Trip Information
                </div>
                <button id="close-directions-btn" class="text-white hover:text-gray-200">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="directions-info-detail">
                <i class="fas fa-road"></i>
                <span id="directions-distance" class="directions-info-value">0.0</span>
                <span class="directions-info-label">km</span>
            </div>
            <div class="directions-info-detail">
                <i class="fas fa-clock"></i>
                <span id="directions-duration" class="directions-info-value">0</span>
                <span class="directions-info-label">min</span>
            </div>
            <div class="directions-info-detail">
                <i class="fas fa-car"></i>
                <span id="directions-mode" class="directions-info-value">Driving</span>
            </div>
        </div>
        
        <!-- Job Posting Sidebar -->
        <div id="job-posting-sidebar" class="fixed right-0 top-0 h-full bg-white shadow-lg w-80 transform translate-x-full transition-transform duration-300 ease-in-out z-20 overflow-y-auto">
            <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-medium text-gray-900">Available Jobs</h3>
                <button id="close-sidebar" class="text-gray-400 hover:text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-4 border-b border-gray-200">
                <div class="flex items-center space-x-4">
                    <div class="flex items-center">
                        <div class="w-4 h-4 rounded-full bg-green-500 mr-2"></div>
                        <span class="text-xs">Applied Jobs</span>
                    </div>
                    <div class="flex items-center">
                        <div class="w-4 h-4 rounded-full bg-blue-500 mr-2"></div>
                        <span class="text-xs">Job Postings</span>
                    </div>
                </div>
            </div>
            <div class="p-3 border-b border-gray-200">
                <div class="relative">
                    <input type="text" id="job-search" placeholder="Search jobs..." class="w-full border border-gray-300 rounded-md pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400"></i>
                    </div>
                    <button id="clear-search" class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer hidden">
                        <i class="fas fa-times text-gray-400 hover:text-gray-600"></i>
                    </button>
                </div>
            </div>
            <div id="job-posting-list" class="p-4 space-y-4">
                <!-- Job postings will be loaded here -->
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-spinner fa-spin text-3xl mb-3"></i>
                    <p>Loading job postings...</p>
                </div>
            </div>
        </div>
        
        <!-- Toggle Sidebar Button -->
        <button id="toggle-sidebar-btn" class="fixed right-15 top-22 bg-white p-2 rounded-full shadow-md z-10">
            <i class="fas fa-list text-blue-600"></i>
        </button>
    </div>
    
    <!-- Applicant details modal -->
    <div id="applicant-modal" class="fixed inset-0 flex items-center justify-center z-50 hidden">
        <div class="absolute inset-0 bg-gray-800 opacity-30"></div>
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4 relative z-10">
            <button id="close-modal" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700">
                <i class="fas fa-times"></i>
            </button>
            
            <h2 id="applicant-name" class="text-2xl font-bold mb-2"></h2>
            <p id="applicant-job" class="text-lg text-gray-600 mb-4"></p>
            
            <div class="mb-4">
                <p><i class="fas fa-envelope text-blue-500 mr-2"></i> <span id="applicant-email"></span></p>
                <p><i class="fas fa-phone text-green-500 mr-2"></i> <span id="applicant-phone"></span></p>
                <p><i class="fas fa-map-marker-alt text-red-500 mr-2"></i> <span id="applicant-location"></span></p>
                <p><i class="fas fa-calendar-alt text-purple-500 mr-2"></i> Applied: <span id="applicant-applied"></span></p>
            </div>
            
            <div class="flex space-x-2 mt-6">
                <button id="route-to-applicant-btn" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition flex-1">
                    <i class="fas fa-route mr-2"></i> Get Directions
                </button>
                <button id="contact-applicant-btn" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition flex-1">
                    <i class="fas fa-envelope mr-2"></i> Contact
                </button>
            </div>
        </div>
    </div>
    
    <!-- Notification container -->
    <div id="notification-container" class="fixed top-4 right-4 z-50 w-80 space-y-2"></div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Applied job IDs from PHP
    let appliedJobIds = ' . json_encode($appliedJobIds) . ';
    
    // Mapbox access token
    mapboxgl.accessToken = "' . $mapboxApiKey . '";
    
    // Map variables
    let map;
    let markers = {};
    let newApplicationMarkers = {};
    let userLocation = null;
    let directions = null;
    let selectedApplicantPosition = null;
    let userLocationMarker = null;
    let userWatchId = null;
    let clusterMarkers = [];
    let supercluster = null;
    let jobMarkers = {}; // Store job markers separately
    
    // Function to set view to a specific location
    function setView(coordinates, zoom = 16) {
        if (map && coordinates && coordinates.length === 2) {
            map.flyTo({
                center: coordinates,
                zoom: zoom,
                essential: true
            });
        }
    }
    
    // Initialize map
    function initMap() {
        // Get user location first, then initialize map
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    userLocation = [position.coords.longitude, position.coords.latitude];
                    
                    // Now initialize the map centered on user location
                    initMapWithLocation(userLocation);
                    
                    // Start watching user position for real-time updates
                    startWatchingUserPosition();
                },
                function(error) {
                    console.error("Error getting location:", error);
                    // Fall back to default location
                    initMapWithLocation([120.9842, 14.5995]);
                },
                {
                    enableHighAccuracy: true,
                    timeout: 5000,
                    maximumAge: 0
                }
            );
        } else {
            // Fall back to default location if geolocation not supported
            initMapWithLocation([120.9842, 14.5995]);
        }
    }
    
    // Initialize map with a specific location
    function initMapWithLocation(location) {
        // Create map centered on the provided location
        map = new mapboxgl.Map({
            container: "map",
            style: "mapbox://styles/mapbox/streets-v12",
            center: location,
            zoom: 14
        });
        
        // Add navigation controls
        map.addControl(new mapboxgl.NavigationControl(), "top-right");
        
        // Add fullscreen control
        map.addControl(new mapboxgl.FullscreenControl(), "top-right");
        
        // Add geolocate control
        const geolocate = new mapboxgl.GeolocateControl({
            positionOptions: {
                enableHighAccuracy: true
            },
            trackUserLocation: true,
            showUserHeading: true
        });
        map.addControl(geolocate, "top-right");
        
        // Add geocoder control
        const geocoder = new MapboxGeocoder({
            accessToken: mapboxgl.accessToken,
            mapboxgl: mapboxgl,
            placeholder: "Search location...",
            marker: false
        });
        map.addControl(geocoder, "top-left");
        
        // Add directions control with default Mapbox UI
        directions = new MapboxDirections({
            accessToken: mapboxgl.accessToken,
            unit: "metric",
            profile: "mapbox/driving",
            alternatives: true,
            geometries: "geojson",
            controls: {
                instructions: false
            },
            flyTo: false, // Prevent automatic flying to route
            interactive: false // Prevent interactive route adjustment
        });
        
        // Add the directions control to the map
        map.addControl(directions, "top-right");
        
        // Hide directions panel initially
        setTimeout(function() {
            const directionsElement = document.querySelector(".mapboxgl-ctrl-directions");
            if (directionsElement) {
                directionsElement.style.display = "none";
            }
        }, 100);
        
        // Add event listener for close directions button
        document.getElementById("close-directions-btn").addEventListener("click", function() {
            clearRoutes();
        });
        
        // Wait for map to load before adding data
        map.on("load", function() {
            // Initialize supercluster for job markers
            supercluster = new Supercluster({
                radius: 80,
                maxZoom: 16
            });
            
            // Add event listeners for map
            map.on("moveend", updateClusters);
            map.on("zoomend", updateClusters);
            
            // Add user location marker if available
            if (userLocation) {
                addUserLocationMarker(userLocation);
            }
            
            // Load job postings for map display
            loadJobPostingsForMap();
            
            // Trigger geolocate on load to focus on user location
            setTimeout(function() {
                geolocate.trigger();
            }, 1000);
        });
    }
    
    // Load job postings for map display
    function loadJobPostingsForMap() {
        fetch("../../../api/jobs.php?action=get_all_jobs")
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.success && data.jobs) {
                    // Add job markers to map
                    addJobMarkersToMap(data.jobs);
                    
                    // Add job points to the clustering system
                    addJobsToCluster(data.jobs);
                }
            })
            .catch(function(error) {
                console.error("Error loading job postings for map:", error);
            });
    }
    
    // Add job markers to map
    function addJobMarkersToMap(jobs) {
        // Clear existing job markers
        Object.values(jobMarkers).forEach(function(markerObj) {
            markerObj.marker.remove();
        });
        jobMarkers = {};
        
        jobs.forEach(function(job) {
            const position = [parseFloat(job.longitude), parseFloat(job.latitude)];
            
            // Skip if invalid coordinates
            if (isNaN(position[0]) || isNaN(position[1])) return;
            
            // Create marker element
            const el = document.createElement("div");
            el.className = "w-6 h-6 rounded-full flex items-center justify-center";
            
            // Check if this is a job the user has applied to
            const hasApplied = appliedJobIds.includes(parseInt(job.id));
            
            // Green for applied jobs, blue for other job postings
            el.style.backgroundColor = hasApplied ? "#10B981" : "#3B82F6";
            el.style.border = "2px solid white";
            el.style.boxShadow = "0 0 0 2px rgba(0,0,0,0.1)";
            
            // Create marker
            const marker = new mapboxgl.Marker(el)
                .setLngLat(position)
                .addTo(map);
            
            // Store marker reference with job data
            jobMarkers[job.id] = {
                marker: marker,
                element: el,
                job: job,
                hasApplied: hasApplied
            };
            
            // Add click listener
            el.addEventListener("click", function() {
                // Show job details modal
                showJobDetailsModal(job, hasApplied);
                
                // Highlight the corresponding job in the sidebar if it\'s open
                highlightJobInSidebar(job.id);
            });
        });
    }
    
    // Show job details modal
    function showJobDetailsModal(job, hasApplied) {
        // Create modal if it does not exist
        let modal = document.getElementById("job-details-modal");
        if (!modal) {
            modal = document.createElement("div");
            modal.id = "job-details-modal";
            modal.className = "fixed inset-0 flex items-center justify-center z-50 hidden";
            modal.innerHTML = `
                <div class="absolute inset-0 bg-gray-800 opacity-30"></div>
                <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4 relative z-10">
                    <button id="close-job-modal" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                    
                    <h2 id="job-title" class="text-2xl font-bold mb-2"></h2>
                    <p id="job-location" class="text-lg text-gray-600 mb-4"></p>
                    
                    <div id="application-status" class="mb-4 hidden">
                        <div class="bg-green-100 text-green-800 px-4 py-2 rounded-md flex items-center">
                            <i class="fas fa-check-circle mr-2"></i>
                            <span>You have applied to this job</span>
                        </div>
                    </div>
                    
                    <div class="space-y-3 mb-6">
                        <p><i class="fas fa-building text-blue-500 mr-2"></i> <span id="job-company"></span></p>
                        <p><i class="fas fa-briefcase text-purple-500 mr-2"></i> <span id="job-type"></span></p>
                        <p><i class="fas fa-money-bill-wave text-green-500 mr-2"></i> <span id="job-salary"></span></p>
                        <p><i class="fas fa-calendar-alt text-red-500 mr-2"></i> Deadline: <span id="job-deadline"></span></p>
                    </div>
                    
                    <div class="mb-6">
                        <h3 class="font-medium text-gray-900 mb-2">Description</h3>
                        <div id="job-description" class="text-gray-600 text-sm max-h-40 overflow-y-auto"></div>
                    </div>
                    
                    <div class="flex space-x-3">
                        <button id="get-directions-btn" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex-1 flex items-center justify-center">
                            <i class="fas fa-route mr-2"></i> Get Directions
                        </button>
                        <a id="view-job-btn" target="_blank" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition flex-1 flex items-center justify-center">
                            <i class="fas fa-eye mr-2"></i> View Details
                        </a>
                        <a id="apply-job-btn" target="_blank" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition flex-1 flex items-center justify-center">
                            <i class="fas fa-paper-plane mr-2"></i> Apply
                        </a>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Add event listeners
            document.getElementById("close-job-modal").addEventListener("click", function() {
                modal.classList.add("hidden");
            });
            
            document.getElementById("get-directions-btn").addEventListener("click", function() {
                modal.classList.add("hidden");
                
            if (userLocation) {
                    const jobLat = parseFloat(modal.getAttribute("data-lat"));
                    const jobLng = parseFloat(modal.getAttribute("data-lng"));
                    
                    if (!isNaN(jobLat) && !isNaN(jobLng)) {
                        showRoute(userLocation, [jobLng, jobLat]);
                    }
                } else {
                    getUserLocation(function() {
                        if (userLocation) {
                            const jobLat = parseFloat(modal.getAttribute("data-lat"));
                            const jobLng = parseFloat(modal.getAttribute("data-lng"));
                            
                            if (!isNaN(jobLat) && !isNaN(jobLng)) {
                                showRoute(userLocation, [jobLng, jobLat]);
                            }
                        } else {
                            alert("Could not determine your location. Please enable location services.");
                        }
                    });
                }
            });
        }
        
        // Update modal content with job details
        document.getElementById("job-title").textContent = job.title;
        document.getElementById("job-location").textContent = job.location;
        document.getElementById("job-company").textContent = job.company_name || "Not specified";
        document.getElementById("job-type").textContent = job.job_type || "Not specified";
        
        // Show application status if applied
        const applicationStatus = document.getElementById("application-status");
        if (hasApplied) {
            applicationStatus.classList.remove("hidden");
        } else {
            applicationStatus.classList.add("hidden");
        }
        
        // Set salary
        if (job.salary_min || job.salary_max) {
            document.getElementById("job-salary").textContent = formatSalary(job.salary_min, job.salary_max, job.pay_type);
        } else {
            document.getElementById("job-salary").textContent = "Not specified";
        }
        
        // Set deadline
        if (job.deadline) {
            document.getElementById("job-deadline").textContent = new Date(job.deadline).toLocaleDateString();
        } else {
            document.getElementById("job-deadline").textContent = "Not specified";
        }
        
        // Set description
        if (job.description) {
            document.getElementById("job-description").innerHTML = job.description;
        } else {
            document.getElementById("job-description").textContent = "No description available";
        }
        
        // Set view job button link
        document.getElementById("view-job-btn").href = "view-job.php?id=" + job.id;
        
        // Set apply button link and visibility
        const applyBtn = document.getElementById("apply-job-btn");
        applyBtn.href = "apply-job.php?job_id=" + job.id;
        
        if (hasApplied) {
            applyBtn.classList.add("hidden");
        } else {
            applyBtn.classList.remove("hidden");
        }
        
        // Store job coordinates for directions
        modal.setAttribute("data-lat", job.latitude);
        modal.setAttribute("data-lng", job.longitude);
        
        // Show modal
        modal.classList.remove("hidden");
    }
    
    // Add jobs to the clustering system
    function addJobsToCluster(jobs) {
        if (!supercluster) return;
        
        // Convert jobs to GeoJSON for clustering
        const jobPoints = jobs.map(function(job) {
            const lng = parseFloat(job.longitude);
            const lat = parseFloat(job.latitude);
            
            // Skip if invalid coordinates
            if (isNaN(lng) || isNaN(lat)) return null;
            
            // Check if this is a job the user has applied to
            const hasApplied = appliedJobIds.includes(parseInt(job.id));
            
            return {
                type: "Feature",
                properties: {
                    id: job.id,
                    title: job.title,
                    location: job.location,
                    isJob: true, // Flag to identify job points
                    isApplied: hasApplied // Flag to identify applied jobs
                },
                geometry: {
                    type: "Point",
                    coordinates: [lng, lat]
                }
            };
        }).filter(Boolean); // Remove null entries
        
        // Add job points to the cluster
        if (jobPoints.length > 0) {
            supercluster.load(jobPoints);
            
            // Update clusters
            updateClusters();
        }
    }
    
    // Add user location marker
    function addUserLocationMarker(location) {
        // Remove existing marker if any
        if (userLocationMarker) {
            userLocationMarker.remove();
        }
        
        // Create marker element
        const el = document.createElement("div");
        el.className = "w-6 h-6 rounded-full bg-purple-600 border-2 border-white shadow-md";
        
        // Create marker
        userLocationMarker = new mapboxgl.Marker(el)
            .setLngLat(location)
            .addTo(map);
    }
    
    // Start watching user position for real-time updates
    function startWatchingUserPosition() {
        if (navigator.geolocation) {
            userWatchId = navigator.geolocation.watchPosition(
                function(position) {
                    var newLocation = [position.coords.longitude, position.coords.latitude];
                    
                    // Update user location
                    userLocation = newLocation;
                    
                    // Update marker position
                    if (userLocationMarker) {
                        userLocationMarker.setLngLat(newLocation);
                    } else {
                        addUserLocationMarker(newLocation);
                    }
                    
                    // Update route if directions are currently displayed
                    if (document.querySelector(".mapboxgl-ctrl-directions") && 
                        document.querySelector(".mapboxgl-ctrl-directions").style && 
                        document.querySelector(".mapboxgl-ctrl-directions").style.display !== "none" && 
                        selectedApplicantPosition) {
                        directions.setOrigin(newLocation);
                    }
                },
                function(error) {
                    console.error("Error watching position:", error);
                },
                {
                    enableHighAccuracy: true,
                    timeout: 30000, // Increase timeout to 30 seconds
                    maximumAge: 5000
                }
            );
        }
    }
    
    // Stop watching user position
    function stopWatchingUserPosition() {
        if (userWatchId !== null) {
            navigator.geolocation.clearWatch(userWatchId);
            userWatchId = null;
        }
    }
    
    // Get user location
    function getUserLocation(callback) {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    userLocation = [position.coords.longitude, position.coords.latitude];
                    
                    // Add or update user location marker
                    if (map) {
                        addUserLocationMarker(userLocation);
                        
                        // Center map on user location
                map.flyTo({
                    center: userLocation,
                    zoom: 14
                });
                    }
                    
                    if (callback && typeof callback === "function") {
                        callback();
                    }
                },
                function(error) {
                    console.error("Error getting location:", error);
                    alert("Error: " + error.message);
                    
                    if (callback && typeof callback === "function") {
                        callback();
                    }
                },
                {
                    enableHighAccuracy: true,
                    timeout: 5000,
                    maximumAge: 0
                }
            );
            } else {
            alert("Error: Your browser doesn\'t support geolocation.");
            
            if (callback && typeof callback === "function") {
                callback();
            }
        }
    }
    
    // Show route between two points
    function showRoute(from, to) {
        // Hide modal if open
        document.getElementById("applicant-modal").classList.add("hidden");
        
        // Check if directions is properly initialized
        if (!directions) {
            console.error("Directions object not properly initialized");
            alert("Unable to show directions. Please try again.");
            return;
        }
        
        // Show directions control
        const directionsElement = document.querySelector(".mapboxgl-ctrl-directions");
        if (directionsElement) {
            directionsElement.style.display = "block";
        }
        
        // Show cancel button
        const cancelBtn = document.getElementById("cancel-routing-btn");
        if (cancelBtn) {
            cancelBtn.style.display = "block";
        }
        
        try {
            // Set origin and destination directly
            directions.setOrigin(from);
            directions.setDestination(to);
            
            // Listen for route events to update the info container
            directions.on("route", function(e) {
                if (e && e.route && e.route[0]) {
                    const route = e.route[0];
                    
                    // Show directions info container
                    const infoContainer = document.getElementById("directions-info-container");
                    infoContainer.style.display = "block";
                    
                    // Update distance
                    const distance = (route.distance / 1000).toFixed(1); // Convert to km
                    document.getElementById("directions-distance").textContent = distance;
                    
                    // Update duration
                    const duration = Math.round(route.duration / 60); // Convert to minutes
                    document.getElementById("directions-duration").textContent = duration;
                    
                    // Update mode
                    const profile = directions.getProfile();
                    let mode = "Driving";
                    if (profile === "mapbox/walking") {
                        mode = "Walking";
                        document.getElementById("directions-mode").previousElementSibling.className = "fas fa-walking";
                    } else if (profile === "mapbox/cycling") {
                        mode = "Cycling";
                        document.getElementById("directions-mode").previousElementSibling.className = "fas fa-bicycle";
                    } else {
                        document.getElementById("directions-mode").previousElementSibling.className = "fas fa-car";
                    }
                    document.getElementById("directions-mode").textContent = mode;
                }
            });
        } catch (error) {
            console.error("Error showing route:", error);
            alert("Unable to show directions. Please try again.");
        }
    }
    
    // Clear routes
    function clearRoutes() {
        // Remove routes
        if (directions) {
            // Clear inputs first
            directions.setOrigin("");
            directions.setDestination("");
            
            // Then remove routes
            if (typeof directions.removeRoutes === "function") {
                directions.removeRoutes();
            }
        }
        
        // Hide directions panel
        const directionsElement = document.querySelector(".mapboxgl-ctrl-directions");
        if (directionsElement) {
            directionsElement.style.display = "none";
        }
        
        // Hide cancel button
        const cancelBtn = document.getElementById("cancel-routing-btn");
        if (cancelBtn) {
            cancelBtn.style.display = "none";
        }
        
        // Hide directions info container
        const infoContainer = document.getElementById("directions-info-container");
        if (infoContainer) {
            infoContainer.style.display = "none";
        }
    }
    
    // Update clusters based on current map view
    function updateClusters() {
        if (!supercluster || !map) return;
        
        // Remove existing markers
        clusterMarkers.forEach(function(marker) {
            marker.remove();
        });
        clusterMarkers = [];
        
        // Get map bounds
        const bounds = map.getBounds();
        const bbox = [
            bounds.getWest(),
            bounds.getSouth(),
            bounds.getEast(),
            bounds.getNorth()
        ];
        
        // Get clusters for current zoom level and bounds
        const zoom = Math.floor(map.getZoom());
        const clusters = supercluster.getClusters(bbox, zoom);
        
        // Add markers for each cluster
        clusters.forEach(function(cluster) {
            if (cluster.properties.cluster) {
                // This is a cluster
                const [lng, lat] = cluster.geometry.coordinates;
                const pointCount = cluster.properties.point_count;
                
                // Create cluster marker element
                const el = document.createElement("div");
                el.className = "marker-cluster";
                
                // Check if this cluster contains job points
                const clusterLeaves = supercluster.getLeaves(cluster.properties.cluster_id, 100);
                const hasAppliedJobs = clusterLeaves.some(function(leaf) { return leaf.properties.isJob && leaf.properties.isApplied; });
                const hasOtherJobs = clusterLeaves.some(function(leaf) { return leaf.properties.isJob && !leaf.properties.isApplied; });
                
                // Style based on cluster content
                if (hasAppliedJobs && hasOtherJobs) {
                    // Mixed cluster (applied jobs and other jobs)
                    el.style.background = "linear-gradient(135deg, #3B82F6 50%, #10B981 50%)";
                } else if (hasAppliedJobs) {
                    // Applied jobs cluster
                    el.style.backgroundColor = "#10B981"; // Green for applied job clusters
                } else {
                    // Other jobs cluster
                    el.style.backgroundColor = "#3B82F6"; // Blue for other job clusters
                }
                
                // Add size classes based on point count
                if (pointCount > 10) {
                    el.className += " marker-cluster-xlarge";
                } else if (pointCount > 5) {
                    el.className += " marker-cluster-large";
                }
                
                // Add count text
                el.textContent = pointCount;
                
                // Create marker
                const marker = new mapboxgl.Marker(el)
                    .setLngLat([lng, lat])
                    .addTo(map);
                
                // Add click handler to zoom in on cluster
                el.addEventListener("click", function() {
                    const expansionZoom = Math.min(
                        supercluster.getClusterExpansionZoom(cluster.properties.cluster_id),
                        20
                    );
                    
                    map.flyTo({
                        center: [lng, lat],
                        zoom: expansionZoom
                    });
                });
                
                // Store marker for later removal
                clusterMarkers.push(marker);
                    } else {
                // This is a single point
                if (cluster.properties.isJob) {
                    // It\'s a job posting - skip as well handle these separately
                    return;
                }
                
                // It\'s an applicant
                const app = {
                    id: cluster.properties.id,
                    job_id: cluster.properties.job_id,
                    job_title: cluster.properties.job_title,
                    first_name: cluster.properties.first_name,
                    last_name: cluster.properties.last_name,
                    email: cluster.properties.email,
                    phone: cluster.properties.phone,
                    location: cluster.properties.location,
                    applied_date: cluster.properties.applied_date,
                    longitude: cluster.geometry.coordinates[0],
                    latitude: cluster.geometry.coordinates[1],
                    isApplied: cluster.properties.isApplied
                };
                
                // Add individual marker
                const marker = addSingleApplicantMarker(app);
                
                // Store marker for later removal
                clusterMarkers.push(marker);
            }
        });
        
        // We\'re no longer fitting bounds to include all markers
        // Just keep the map focused on the user\'s location
    }
    
    // Add a single applicant marker (used by both clustering and individual markers)
    function addSingleApplicantMarker(app) {
        const position = [parseFloat(app.longitude), parseFloat(app.latitude)];
        
        // Skip if invalid coordinates
        if (isNaN(position[0]) || isNaN(position[1])) return null;
        
        // Create marker element
        const el = document.createElement("div");
        el.className = "w-6 h-6 rounded-full flex items-center justify-center";
        
        // Set marker color
        if (app.isApplied) {
            el.style.backgroundColor = "#10B981"; // Green for applied job
        } else {
            el.style.backgroundColor = "#3B82F6"; // Blue for other job
        }
        
        // Create marker without popup
        const marker = new mapboxgl.Marker(el)
            .setLngLat(position)
            .addTo(map);
        
        // Add click listener
        el.addEventListener("click", function() {
            // Store selected applicant position for directions
            selectedApplicantPosition = position;
            
            // Show applicant details
            showApplicantDetails(app);
        });
        
        // Store marker reference if this is a new application
        if (app.isApplied) {
            newApplicationMarkers[app.id] = marker;
            
            // After 30 seconds, convert to regular marker
            setTimeout(function() {
                if (newApplicationMarkers[app.id]) {
                    newApplicationMarkers[app.id].remove();
                    delete newApplicationMarkers[app.id];
                    app.isApplied = false;
                    addSingleApplicantMarker(app);
                }
            }, 30000);
        } else {
            markers[app.id] = marker;
        }
        
        return marker;
    }
    
    // Show notification
    function showNotification(title, message) {
        // Check if browser supports notifications
        if ("Notification" in window) {
            // Check if permission is granted
            if (Notification.permission === "granted") {
                // Create notification
                const notification = new Notification(title, {
                    body: message,
                    icon: "../../../assets/img/logo.png"
                });
                
                // Close notification after 5 seconds
                setTimeout(function() {
                    notification.close();
                }, 5000);
            } else if (Notification.permission !== "denied") {
                // Request permission
                Notification.requestPermission().then(function(permission) {
                    if (permission === "granted") {
                        showNotification(title, message);
                    }
                });
            }
        }
    }
    
    // Show applicant details
    function showApplicantDetails(app) {
        document.getElementById("applicant-name").textContent = app.first_name + " " + app.last_name;
        document.getElementById("applicant-job").textContent = app.job_title;
        document.getElementById("applicant-email").textContent = app.email;
        document.getElementById("applicant-phone").textContent = app.phone;
        document.getElementById("applicant-location").textContent = app.location;
        document.getElementById("applicant-applied").textContent = app.applied_date;
        
        // Add navigation button to view job details
        const jobDetailsBtn = document.createElement("button");
        jobDetailsBtn.className = "bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex-1";
        jobDetailsBtn.innerHTML = `<i class="fas fa-eye mr-2"></i> View Job Details`;
        jobDetailsBtn.addEventListener("click", function() {
            window.open("view-applications.php?job=" + app.job_id, "_blank");
        });
        
        // Replace the first button in the modal with the job details button
        const modalActions = document.querySelector("#applicant-modal .flex.space-x-2");
        if (modalActions && modalActions.firstChild) {
            modalActions.insertBefore(jobDetailsBtn, modalActions.firstChild);
            modalActions.removeChild(modalActions.firstChild);
        }
        
        // Replace contact button with "More Details" button
        const contactBtn = document.getElementById("contact-applicant-btn");
        contactBtn.innerHTML = `<i class="fas fa-info-circle mr-2"></i> More Details`;
        contactBtn.className = "bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition flex-1";
        contactBtn.onclick = function() {
            // Close modal
            document.getElementById("applicant-modal").classList.add("hidden");
            
            // Open job posting sidebar
            showJobPostingSidebar(app.job_id);
        };
        
        // Show modal
        document.getElementById("applicant-modal").classList.remove("hidden");
    }
    
    // Close modal
    document.getElementById("close-modal").addEventListener("click", function() {
        document.getElementById("applicant-modal").classList.add("hidden");
    });
    
    // This event listener is overridden in showApplicantDetails function
    
    // Clean up resources when page is unloaded
    window.addEventListener("beforeunload", function() {
        stopWatchingUserPosition();
    });
    
    // Helper function to get application by ID
    function getApplicationById(id) {
        return applications.find(function(app) {
            return app.id == id;
        }) || null;
    }
    
    // Job postings sidebar functionality
    const jobPostingSidebar = document.getElementById("job-posting-sidebar");
    const toggleSidebarBtn = document.getElementById("toggle-sidebar-btn");
    const closeSidebarBtn = document.getElementById("close-sidebar");
    
    // Toggle sidebar
    toggleSidebarBtn.addEventListener("click", function() {
        jobPostingSidebar.classList.toggle("translate-x-full");
        
        // Load job postings if sidebar is opened
        if (!jobPostingSidebar.classList.contains("translate-x-full")) {
            loadJobPostings();
        }
    });
    
    // Close sidebar
    closeSidebarBtn.addEventListener("click", function() {
        jobPostingSidebar.classList.add("translate-x-full");
    });
    
    // Load job postings
    function loadJobPostings(callback) {
        // Show loading state
        const jobPostingList = document.getElementById("job-posting-list");
        jobPostingList.innerHTML = "<div class=\"text-center py-8 text-gray-500\"><i class=\"fas fa-spinner fa-spin text-3xl mb-3\"></i><p>Loading job postings...</p></div>";
        
        fetch("../../../api/jobs.php?action=get_all_jobs")
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                console.log("API response:", data); // Debug log
                if (data.success && data.jobs) {
                    renderJobPostings(data.jobs);
                    
                    // Execute callback if provided
                    if (typeof callback === "function") {
                        setTimeout(callback, 100); // Small delay to ensure DOM is updated
                    }
                } else {
                    showJobPostingsError("No job postings found");
                }
            })
            .catch(function(error) {
                console.error("Error loading job postings:", error);
                showJobPostingsError("Failed to load job postings");
            });
    }
    
    // Render job postings
    function renderJobPostings(jobs) {
        const jobPostingList = document.getElementById("job-posting-list");
        
        if (jobs.length === 0) {
            jobPostingList.innerHTML = 
                `<div class="text-center py-8">
                    <div class="text-gray-400 mb-4">
                        <i class="fas fa-clipboard-list text-5xl"></i>
</div>
                    <h3 class="text-lg font-medium text-gray-900 mb-1">No Job Postings</h3>
                    <p class="text-gray-500">No job postings are currently available.</p>
                </div>`;
            return;
        }
        
        let htmlContent = "";
        for (let i = 0; i < jobs.length; i++) {
            const job = jobs[i];
            const statusClass = job.status === "active" ? "bg-green-100 text-green-800" : 
                             (job.status === "paused" ? "bg-yellow-100 text-yellow-800" : "bg-gray-100 text-gray-800");
            const statusText = job.status.charAt(0).toUpperCase() + job.status.slice(1);
            
            // Check if user has applied to this job
            const hasApplied = appliedJobIds.includes(parseInt(job.id));
            const appliedBadge = hasApplied ? 
                "<span class=\"text-xs px-2 py-1 rounded-full bg-green-100 text-green-800\">Applied</span>" : 
                "";
            
            const borderClass = hasApplied ? "border-green-300" : "";
            
            htmlContent += "<div class=\"border border-gray-200 rounded-md p-3 hover:bg-gray-50 transition cursor-pointer job-posting-item " + borderClass + "\" data-job-id=\"" + job.id + "\" data-lat=\"" + job.latitude + "\" data-lng=\"" + job.longitude + "\">" +
                "<div class=\"flex justify-between items-center\">" +
                    "<h4 class=\"font-medium text-gray-900 job-title-text\">" + job.title + "</h4>" +
                    "<button class=\"text-gray-500 hover:text-gray-700 expand-job-btn\" title=\"Expand details\">" +
                        "<i class=\"fas fa-chevron-down\"></i>" +
                    "</button>" +
                "</div>" +
                "<p class=\"text-sm text-gray-500\"><i class=\"fas fa-map-marker-alt mr-1\"></i> " + job.location + "</p>" +
                "<div class=\"flex justify-between items-center mt-2\">" +
                    appliedBadge +
                    "<div class=\"flex space-x-2\">" +
                        "<button class=\"text-blue-600 hover:text-blue-800 get-directions-btn\" data-lat=\"" + job.latitude + "\" data-lng=\"" + job.longitude + "\" data-title=\"" + job.title + "\" title=\"Get Directions\">" +
                            "<i class=\"fas fa-route\"></i>" +
                        "</button>" +
                        "<a href=\"view-job.php?id=" + job.id + "\" target=\"_blank\" class=\"text-indigo-600 hover:text-indigo-800\" title=\"View Job Details\">" +
                            "<i class=\"fas fa-eye\"></i>" +
                        "</a>";
            
            if (!hasApplied) {
                htmlContent += "<a href=\"apply-job.php?job_id=" + job.id + "\" target=\"_blank\" class=\"text-green-600 hover:text-green-800 \">" +
                                "<i class=\"fas fa-paper-plane\"></i>" +
                            "</a>";
            }
            
            htmlContent += "</div>" +
                "</div>" +
                
                "<!-- Expandable content (initially hidden) -->" +
                "<div class=\"job-details mt-3 pt-3 border-t border-gray-200 hidden\">" +
                    "<div class=\"space-y-2 text-sm\">" +
                        "<p><span class=\"font-medium\">Company:</span> " + (job.company_name || "Not specified") + "</p>" +
                        "<p><span class=\"font-medium\">Job Type:</span> " + (job.job_type || "Not specified") + "</p>" +
                        "<p><span class=\"font-medium\">Salary:</span> " + formatSalary(job.salary_min, job.salary_max, job.pay_type) + "</p>" +
                        "<p><span class=\"font-medium\">Deadline:</span> " + (job.deadline ? new Date(job.deadline).toLocaleDateString() : "Not specified") + "</p>" +
                        
                        "<div class=\"mt-2\">" +
                            "<p class=\"font-medium mb-1\">Description:</p>" +
                            "<p class=\"text-gray-600 text-xs line-clamp-3\">" + (job.description || "No description available") + "</p>" +
                        "</div>" +
                        
                        "<div class=\"mt-2 flex justify-between\">" +
                            "<a href=\"view-job.php?id=" + job.id + "\" class=\"text-blue-600 hover:text-blue-800 text-xs\">" +
                                "View full details <i class=\"fas fa-arrow-right ml-1\"></i>" +
                            "</a>";
                            
            if (hasApplied) {
                htmlContent += "<span class=\"text-green-600 text-xs flex items-center\">" +
                                "<i class=\"fas fa-check-circle mr-1\"></i> Already applied" +
                            "</span>";
            } else {
                htmlContent += "<a href=\"apply-job.php?job_id=" + job.id + "\" class=\"text-green-600 hover:text-green-800 text-xs\">" +
                                "Apply now <i class=\"fas fa-paper-plane ml-1\"></i>" +
                            "</a>";
            }
            
            htmlContent += "</div>" +
                    "</div>" +
                "</div>" +
            "</div>";
        }
        
        jobPostingList.innerHTML = htmlContent;
        
        // Add event listeners after DOM is loaded
        document.addEventListener("DOMContentLoaded", function() {
            addJobListingEventListeners();
        });
        
        // Add event listeners immediately as well (for when the sidebar is opened after DOM is loaded)
        addJobListingEventListeners();
    }
    
    // Add event listeners to job listings
    function addJobListingEventListeners() {
        // Set up job posting list expand/collapse functionality
        const jobPostingsList = document.getElementById("job-posting-list");
        if (jobPostingsList) {
            // Remove existing event listeners first to prevent duplicates
            jobPostingsList.removeEventListener("click", handleJobPostingClick);
            // Add new event listener
            jobPostingsList.addEventListener("click", handleJobPostingClick);
        }
    }
    
    // Handle job posting click events
    function handleJobPostingClick(e) {
        // Handle expand/collapse buttons
        if (e.target.closest(".expand-job-btn")) {
            const button = e.target.closest(".expand-job-btn");
            const jobItem = button.closest(".job-posting-item");
            const detailsSection = jobItem.querySelector(".job-details");
            const icon = button.querySelector("i");
            
            // Toggle details visibility
            detailsSection.classList.toggle("hidden");
            
            // Toggle icon
            if (detailsSection.classList.contains("hidden")) {
                icon.classList.remove("fa-chevron-up");
                icon.classList.add("fa-chevron-down");
            } else {
                icon.classList.remove("fa-chevron-down");
                icon.classList.add("fa-chevron-up");
                
                // Highlight this job item
                highlightJobItem(jobItem);
            }
            
            return; // Stop propagation
        }
        
        // Handle directions button
        if (e.target.closest(".get-directions-btn")) {
            const button = e.target.closest(".get-directions-btn");
            const jobItem = button.closest(".job-posting-item");
            const lat = parseFloat(button.getAttribute("data-lat") || jobItem.getAttribute("data-lat"));
            const lng = parseFloat(button.getAttribute("data-lng") || jobItem.getAttribute("data-lng"));
            const title = button.getAttribute("data-title") || jobItem.querySelector(".job-title-text").textContent;
            
            // Highlight this job item
            highlightJobItem(jobItem);
            
            if (userLocation) {
                showRoute(userLocation, [lng, lat]);
                
                // Show a toast notification
                showToast("Getting directions to " + title);
            } else {
                getUserLocation(function() {
                    if (userLocation) {
                        showRoute(userLocation, [lng, lat]);
                        
                        // Show a toast notification
                        showToast("Getting directions to " + title);
                    } else {
                        alert("Could not determine your location. Please enable location services.");
                    }
                });
            }
            
            return; // Stop propagation
        }
        
        // Handle job item click (center map on job)
        if (e.target.closest(".job-posting-item") && 
            !e.target.closest(".expand-job-btn") && 
            !e.target.closest(".get-directions-btn") && 
            !e.target.closest("a")) {
            
            const jobItem = e.target.closest(".job-posting-item");
            const lat = parseFloat(jobItem.getAttribute("data-lat"));
            const lng = parseFloat(jobItem.getAttribute("data-lng"));
            const jobId = jobItem.getAttribute("data-job-id");
            
            // Highlight this job item
            highlightJobItem(jobItem);
            
            if (!isNaN(lat) && !isNaN(lng)) {
                // Center map on job location
                map.flyTo({
                    center: [lng, lat],
                    zoom: 15,
                    essential: true
                });
                
                // If we already have the job data in jobMarkers, use it
                if (jobMarkers[jobId]) {
                    const markerObj = jobMarkers[jobId];
                    // showJobDetailsModal(markerObj.job, markerObj.hasApplied); // no need to show job details modal
                } else {
                    // Otherwise fetch the job data
                    fetch("/bagoscout/api/jobs.php?action=get_job&id=" + jobId)
                        .then(function(response) {
                            return response.json();
                        })
                        .then(function(data) {
                            if (data.success && data.job) {
                                // Check if this is a job the user has applied to
                                const hasApplied = appliedJobIds.includes(parseInt(data.job.id));
                                
                                // Show job details modal
                                showJobDetailsModal(data.job, hasApplied);
                            }
                        })
                        .catch(function(error) {
                            console.error("Error fetching job details:", error);
                        });
                }
            }
        }
    }
    
    // Highlight a job item and remove highlight from others
    function highlightJobItem(jobItem) {
        // Remove highlight from all job items
        const allJobItems = document.querySelectorAll(".job-posting-item");
        allJobItems.forEach(function(item) {
            item.classList.remove("bg-blue-50");
            if (!item.classList.contains("border-green-300")) { // Don\'t remove green border from applied jobs
                item.classList.remove("border-blue-300");
            }
        });
        
        // Add highlight to this job item
        jobItem.classList.add("bg-blue-50");
        if (!jobItem.classList.contains("border-green-300")) { // Don\'t override green border for applied jobs
            jobItem.classList.add("border-blue-300");
        }
        
        // Scroll this job item into view if it\'s not fully visible
        const container = document.getElementById("job-posting-list");
        const itemTop = jobItem.offsetTop;
        const itemBottom = itemTop + jobItem.offsetHeight;
        const containerTop = container.scrollTop;
        const containerBottom = containerTop + container.offsetHeight;
        
        if (itemTop < containerTop || itemBottom > containerBottom) {
            jobItem.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
    }
    
    // Show a toast notification
    function showToast(message) {
        const toast = document.createElement("div");
        toast.className = "fixed bottom-4 left-1/2 transform -translate-x-1/2 bg-gray-800 text-white px-4 py-2 rounded-md shadow-lg z-50 animate-fade-in-up";
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(function() {
            toast.classList.add("animate-fade-out-down");
            setTimeout(function() {
                document.body.removeChild(toast);
            }, 300);
        }, 3000);
    }
    
    // Show job postings error
    function showJobPostingsError(message) {
        const jobPostingList = document.getElementById("job-posting-list");
        jobPostingList.innerHTML = 
            "<div class=\"text-center py-8\">" +
                "<div class=\"text-red-400 mb-4\">" +
                    "<i class=\"fas fa-exclamation-circle text-5xl\"></i>" +
                "</div>" +
                "<h3 class=\"text-lg font-medium text-gray-900 mb-1\">Error</h3>" +
                "<p class=\"text-gray-500\">" + message + "</p>" +
            "</div>";
    }
    
    // Show job posting sidebar for specific job
    function showJobPostingSidebar(jobId) {
        // Open sidebar
        jobPostingSidebar.classList.remove("translate-x-full");
        
        // Load job postings
        loadJobPostings();
        
        // Highlight the selected job
        setTimeout(function() {
            const selectedJobSelector = ".job-posting-item[data-job-id=\"" + jobId + "\"]";
            const selectedJob = document.querySelector(selectedJobSelector);
            if (selectedJob) {
                selectedJob.classList.add("bg-blue-50", "border-blue-300");
                selectedJob.scrollIntoView({ behavior: "smooth", block: "center" });
            }
        }, 500);
    }

    // Helper function to format salary
    function formatSalary(min, max, payType) {
        let salaryDisplay = "";
        
        if (min && max) {
            salaryDisplay = "₱" + Number(min).toLocaleString() + " - ₱" + Number(max).toLocaleString();
        } else if (min) {
            salaryDisplay = "From ₱" + Number(min).toLocaleString();
        } else if (max) {
            salaryDisplay = "Up to ₱" + Number(max).toLocaleString();
        } else {
            salaryDisplay = "Not specified";
        }
        
        if (payType) {
            if (payType === "hourly") {
                salaryDisplay += " per hour";
            } else if (payType === "monthly") {
                salaryDisplay += " per month";
            } else if (payType === "annual") {
                salaryDisplay += " per year";
            }
        }
        
        return salaryDisplay;
    }

    // Highlight a job in the sidebar by its ID
    function highlightJobInSidebar(jobId) {
        const jobPostingSidebar = document.getElementById("job-posting-sidebar");
        if (jobPostingSidebar && !jobPostingSidebar.classList.contains("translate-x-full")) {
            const jobItem = document.querySelector(".job-posting-item[data-job-id=\"" + jobId + "\"]");
            if (jobItem) {
                highlightJobItem(jobItem);
                
                // Expand job details if they\'re collapsed
                const detailsSection = jobItem.querySelector(".job-details");
                const expandBtn = jobItem.querySelector(".expand-job-btn i");
                
                if (detailsSection && detailsSection.classList.contains("hidden")) {
                    detailsSection.classList.remove("hidden");
                    expandBtn.classList.remove("fa-chevron-down");
                    expandBtn.classList.add("fa-chevron-up");
                }
            } else {
                // If the job item isn\'t in the visible list, we might need to load more jobs
                // or scroll to find it
                console.log("Job item not found in sidebar");
            }
        } else {
            // Open the sidebar if it\'s closed
            jobPostingSidebar.classList.remove("translate-x-full");
            
            // Load job postings and then highlight this one
            loadJobPostings(function() {
                const jobItem = document.querySelector(".job-posting-item[data-job-id=\"" + jobId + "\"]");
                if (jobItem) {
                    highlightJobItem(jobItem);
                    
                    // Expand job details
                    const detailsSection = jobItem.querySelector(".job-details");
                    const expandBtn = jobItem.querySelector(".expand-job-btn i");
                    
                    if (detailsSection && detailsSection.classList.contains("hidden")) {
                        detailsSection.classList.remove("hidden");
                        expandBtn.classList.remove("fa-chevron-down");
                        expandBtn.classList.add("fa-chevron-up");
                    }
                }
            });
        }
    }

    // Add search functionality
    document.addEventListener("DOMContentLoaded", function() {
        const searchInput = document.getElementById("job-search");
        const clearSearchBtn = document.getElementById("clear-search");
        
        if (searchInput && clearSearchBtn) {
            // Add input event listener with debounce
            let debounceTimeout;
            searchInput.addEventListener("input", function() {
                const searchTerm = searchInput.value.toLowerCase().trim();
                console.log(searchTerm);
                // Show/hide clear button
                if (searchTerm) {
                    clearSearchBtn.classList.remove("hidden");
                } else {
                    clearSearchBtn.classList.add("hidden");
                }
                
                // Clear previous timeout
                clearTimeout(debounceTimeout);
                
                // Set new timeout to avoid too many requests
                debounceTimeout = setTimeout(function() {
                    if (searchTerm.length > 0) {
                        // Search through database
                        searchJobs(searchTerm);
                    } else {
                        // Load all jobs if search is cleared
                        loadJobPostings();
                    }
                }, 500); // 500ms debounce
            });
            
            // Add clear button event listener
            clearSearchBtn.addEventListener("click", function() {
                searchInput.value = "";
                clearSearchBtn.classList.add("hidden");
                loadJobPostings(); // Load all jobs
                searchInput.focus();
            });
        }
    });
    
    // Search jobs through API
    function searchJobs(searchTerm) {
        // Show loading state
        const jobPostingList = document.getElementById("job-posting-list");
        jobPostingList.innerHTML = "<div class=\"text-center py-4 text-gray-500\"><i class=\"fas fa-spinner fa-spin text-2xl mb-2\"></i><p>Searching jobs...</p></div>";
        
        // Call the API with search parameter
        fetch("../../../api/jobs.php?action=search_jobs&search=" + encodeURIComponent(searchTerm))
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                console.log("Search response:", data); // Debug log
                if (data.success && data.jobs && data.jobs.length > 0) {
                    renderJobPostings(data.jobs);
                } else {
                    // No results found
                    jobPostingList.innerHTML = 
                        "<div class=\"text-center py-8 text-gray-500\">" +
                            "<div class=\"text-gray-400 mb-4\">" +
                                "<i class=\"fas fa-search text-4xl\"></i>" +
                            "</div>" +
                            "<h3 class=\"text-lg font-medium text-gray-900 mb-1\">No Results</h3>" +
                            "<p class=\"text-gray-500\">No jobs match \"" + searchTerm + "\"</p>" +
                        "</div>";
                }
            })
            .catch(function(error) {
                console.error("Error searching jobs:", error);
                showJobPostingsError("Failed to search jobs");
            });
    }

    // Initialize map
    initMap();
});
</script>
';

// Include layout
include_once 'nav/layout.php';
