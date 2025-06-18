<?php
session_start();
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';
require_once '../../../config/api_keys.php';

// Get current user
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Get employer info
$conn = getDbConnection();
$stmt = $conn->prepare("SELECT * FROM employers WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$employer = $result->fetch_assoc();
$employerId = $employer['id'];

// Set page title for layout
$pageTitle = "Job Postings Map";

// Get active applications with coordinates
$stmt = $conn->prepare("
    SELECT a.*, j.title as job_title, j.location,
           u.first_name, u.last_name, u.email, u.phone, j.latitude, j.longitude,
           DATE_FORMAT(a.created_at, '%M %d, %Y') as applied_date
    FROM applications a
    JOIN jobs j ON a.job_id = j.id
    JOIN jobseekers js ON a.jobseeker_id = js.id
    JOIN users u ON js.user_id = u.id
    WHERE j.employer_id = ? AND j.latitude IS NOT NULL AND j.longitude IS NOT NULL
    ORDER BY a.created_at DESC
");
$stmt->bind_param("i", $employerId);
$stmt->execute();
$result = $stmt->get_result();

$applications = [];
while ($app = $result->fetch_assoc()) {
    $applications[] = $app;
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
            transform: scale(1.2);
            opacity: 0.8;
        }
        100% {
            transform: scale(1);
            opacity: 1;
        }
    }
    
    .map-controls {
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 1;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    
    .map-control-btn {
        background-color: white;
        border: none;
        border-radius: 4px;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 0 0 2px rgba(0,0,0,0.1);
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .map-control-btn:hover {
        background-color: #f0f0f0;
    }
    
    .map-control-btn i {
        font-size: 16px;
        color: #404040;
    }
    
    .mapboxgl-ctrl-group {
        box-shadow: 0 0 0 2px rgba(0,0,0,0.1);
    }
    
    .map-legend {
        position: absolute;
        bottom: 2px;
        left: 2px;
        background: white;
        padding: 10px;
        border-radius: 4px;
        box-shadow: 0 0 0 2px rgba(0,0,0,0.1);
        z-index: 20;
        font-size: 12px;
    }
    
    .legend-item {
        display: flex;
        align-items: center;
        margin-bottom: 5px;
    }
    
    .legend-color {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        margin-right: 8px;
    }
    
    .legend-blue {
        background-color: #3B82F6;
    }
    
    .legend-red {
        background-color: #EF4444;
    }
    
    .legend-purple {
        background-color: #8B5CF6;
    }
    
    .legend-green {
        background-color: #10B981;
    }
    
    .mapboxgl-ctrl-directions {
        max-width: 350px !important;
        width: 350px !important;
        z-index: 999 !important;
    }
    
    /* Make the directions panel more visible */
    .mapboxgl-ctrl-directions .directions-control {
        /* box-shadow: 0 0 10px rgba(0,0,0,0.2) !important; */
        border-radius: 8px !important;
    }
    
    /* Style the cancel routing button */
    #cancel-routing-btn {
        position: absolute;
        top: 60px;
        left: 10px;
        background-color: #EF4444;
        color: white;
        border: none;
        border-radius: 4px;
        padding: 8px 12px;
        font-size: 14px;
        font-weight: 500;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        cursor: pointer;
        z-index: 1000;
        display: none;
        transition: all 0.2s ease;
    }
    
    #cancel-routing-btn:hover {
        background-color: #DC2626;
        transform: translateY(-2px);
    }
    
    #cancel-routing-btn i {
        margin-right: 6px;
    }
    
    /* Remove pointer-events restriction */
    .directions-active .mapboxgl-map {
        pointer-events: auto;
    }
    
    /* Ensure directions panel is visible */
    .mapboxgl-ctrl-directions {
        display: none;
    }
    
    /* Make directions text more readable */
    .mapbox-directions-step {
        color: #333 !important;
        font-weight: 500 !important;
    }
    
    .mapbox-directions-step-distance {
        color: #4B5563 !important;
        font-weight: 600 !important;
    }
    
    .mapbox-directions-step-text {
        color: #333 !important;
    }
    
    .mapbox-directions-route-summary {
        color: #1F2937 !important;
        font-weight: 600 !important;
        padding: 12px 15px !important;
        background-color: #f8fafc !important;
        border-radius: 8px !important;
        margin: 10px 0 !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
        border: 1px solid #e5e7eb !important;
    }
    
    .mapbox-directions-route-summary h1 {
        color: #1F2937 !important;
        font-weight: 700 !important;
        font-size: 16px !important;
        display: flex !important;
        align-items: center !important;
    }
    
    .mapbox-directions-route-summary h1:before {
        content: "\f1fa"; /* chart-area icon */
        font-family: "Font Awesome 5 Free" !important;
        font-weight: 900 !important;
        margin-right: 8px !important;
        color: #3b82f6 !important;
    }
    
    .mapbox-directions-route-summary span {
        color: #4B5563 !important;
        font-weight: 500 !important;
        display: flex !important;
        align-items: center !important;
        margin-top: 4px !important;
    }
    
    .mapbox-directions-route-summary span:before {
        content: "\f017"; /* clock icon */
        font-family: "Font Awesome 5 Free" !important;
        font-weight: 900 !important;
        margin-right: 8px !important;
        color: #10b981 !important;
    }
    
    /* Add icons to direction steps */
    .mapbox-directions-step:before {
        font-family: "Font Awesome 5 Free";
        font-weight: 900;
        margin-right: 8px;
        width: 20px;
        display: inline-block;
        text-align: center;
    }
    
    .mapbox-directions-step.depart:before {
        content: "\f3c5"; /* map-marker-alt icon */
        color: #3B82F6;
    }
    
    .mapbox-directions-step.turn:before {
        content: "\f362"; /* arrow-alt-circle-right icon */
        color: #10B981;
    }
    
    .mapbox-directions-step.turn.left:before {
        content: "\f359"; /* arrow-alt-circle-left icon */
        color: #10B981;
    }
    
    .mapbox-directions-step.turn.right:before {
        content: "\f35a"; /* arrow-alt-circle-right icon */
        color: #10B981;
    }
    
    .mapbox-directions-step.arrive:before {
        content: "\f041"; /* map-marker icon */
        color: #EF4444;
    }
    
    /* Hide all unnecessary directions UI elements */
    .mapbox-directions-component-keyline,
    .mapbox-directions-inputs,
    .mapbox-directions-alternatives {
        display: none !important;
    }
    
    /* Show and enhance the profile switcher (transportation modes) */
    .mapbox-directions-profile {
        display: flex !important;
        margin-bottom: 10px !important;
        padding: 10px !important;
        justify-content: center !important;
        gap: 10px !important;
    }
    
    /* Style the profile options */
    .mapbox-directions-profile label {
        background-color: #f3f4f6 !important;
        padding: 8px 12px !important;
        border-radius: 6px !important;
        transition: all 0.2s ease !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        cursor: pointer !important;
        border: 2px solid transparent !important;
    }
    
    .mapbox-directions-profile label:hover {
        background-color: #e5e7eb !important;
    }
    
    .mapbox-directions-profile input:checked + label {
        background-color: #dbeafe !important;
        border-color: #3b82f6 !important;
        color: #1e40af !important;
        font-weight: 600 !important;
    }
    
    /* Add icons to profile options */
    .mapbox-directions-profile label:before {
        font-family: "Font Awesome 5 Free" !important;
        font-weight: 900 !important;
        margin-right: 5px !important;
    }
    
    .mapbox-directions-profile label[for*="driving"]:before {
        content: "\f1b9" !important; /* car icon */
    }
    
    .mapbox-directions-profile label[for*="walking"]:before {
        content: "\f554" !important; /* walking icon */
    }
    
    .mapbox-directions-profile label[for*="cycling"]:before {
        content: "\f206" !important; /* bicycle icon */
    }
    
    .mapbox-directions-instructions {
        position: static !important;
        width: 100% !important;
        max-width: none !important;
        right: auto !important;
        top: auto !important;
        max-height: 70vh !important;
        overflow-y: auto !important;
        background: white !important;
        border-radius: 0 0 8px 8px !important;
        box-shadow: none !important;
        display: block !important;
        z-index: 10 !important;
    }
    
    /* Additional styling for the route summary container */
    .mapbox-directions-instructions .mapbox-directions-route-summary {
        padding: 15px !important;
        background-color: #f0f9ff !important;
        border-bottom: 1px solid #e0e7ff !important;
        margin-bottom: 10px !important;
    }
    
    .mapbox-directions-steps {
        overflow-y: auto !important;
        max-height: 50vh !important;
        overflow-x: hidden !important;
    }
    
    /* Enhance individual direction steps */
    .mapbox-directions-step {
        padding: 12px 15px !important;
        border-bottom: 1px solid #f3f4f6 !important;
        transition: background-color 0.2s ease !important;
    }
    
    .mapbox-directions-step:hover {
        background-color: #f9fafb !important;
    }
    
    .mapbox-directions-step:last-child {
        border-bottom: none !important;
    }
    
    .mapbox-directions-step-distance {
        background-color: #dbeafe !important;
        padding: 3px 8px !important;
        border-radius: 12px !important;
        font-size: 12px !important;
        color: #1e40af !important;
    }
    
    /* Make tooltips always visible */
    .mapboxgl-popup {
        z-index: 2;
    }

    /* Custom styles for directions container */
    .directions-container {
        position: absolute;
        top: 10px;
        right: 10px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        z-index: 10;
        max-width: 320px;
        display: none;
    }

    .directions-header {
        padding: 10px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .directions-header h3 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
    }

    .directions-header button {
        background: none;
        border: none;
        cursor: pointer;
        color: #6b7280;
    }

    .directions-header button:hover {
        color: #374151;
    }

    .directions-content {
        max-height: 60vh;
        overflow-y: auto;
    }

    /* Prevent user input in directions fields */
    .mapboxgl-ctrl-directions .mapbox-directions-component-keyline input {
        pointer-events: auto;
    }
    
    /* Custom distance and time info container */
    .directions-info-container {
        position: absolute;
        bottom: 30px;
        right: 10px;
        background-color: white;
        border-radius: 8px;
        padding: 12px 15px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.15);
        z-index: 100;
        max-width: 250px;
        display: none;
        border-left: 4px solid #3b82f6;
    }
    
    .directions-info-title {
        font-weight: 600;
        font-size: 14px;
        margin-bottom: 8px;
        color: #1f2937;
        display: flex;
        align-items: center;
    }
    
    .directions-info-title i {
        margin-right: 6px;
        color: #3b82f6;
    }
    
    .directions-info-detail {
        display: flex;
        align-items: center;
        margin-bottom: 6px;
        font-size: 13px;
    }
    
    .directions-info-detail i {
        width: 20px;
        margin-right: 8px;
        color: #4b5563;
        text-align: center;
    }
    
    .directions-info-value {
        font-weight: 600;
        color: #111827;
    }
    
    .directions-info-label {
        color: #6b7280;
        margin-left: 4px;
    }

    @media (max-width: 768px) {
        .map-legend {
            display: none;
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
                <span class="text-xs">Your Job Postings</span>
            </div>
            <div class="flex items-center">
                <div class="w-4 h-4 rounded-full bg-blue-500 mr-2"></div>
                <span class="text-xs">Other Job Postings</span>
            </div>
        </div>
        
        <!-- Directions Info Container -->
        <div id="directions-info-container" class="directions-info-container">
            <div class="directions-info-title">
                <i class="fas fa-route"></i> Trip Information
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
                <h3 class="text-lg font-medium text-gray-900">Job Postings</h3>
                <button id="close-sidebar" class="text-gray-400 hover:text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
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
    // Applicant data from PHP
    let applications = [];
    
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
        
        // Add geocoder (search) control
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
            controls: {
                inputs: true,
                instructions: true,
                profileSwitcher: true
            },
            flyTo: true,
            placeholderOrigin: "Your location",
            placeholderDestination: "Applicant location",
            interactive: false
        });
        
        map.addControl(directions, "top-left");
        
        // Initially hide the directions control
        const directionsElement = document.querySelector(".mapboxgl-ctrl-directions");
        if (directionsElement) {
            directionsElement.style.display = "none";
        }
        
        // Create cancel routing button
        const cancelBtn = document.createElement("button");
        cancelBtn.id = "cancel-routing-btn";
        cancelBtn.innerHTML = "<i class=\"fas fa-times-circle\"></i> Cancel Route";
        cancelBtn.style.display = "none";
        document.getElementById("map-container").appendChild(cancelBtn);
        
        // Add event listener to cancel button
        cancelBtn.addEventListener("click", function() {
            clearRoutes();
        });
        
        // Using the default Mapbox directions UI with our cancel button
        
        // Add user location marker
        addUserLocationMarker(location);
        
        // Add markers for each applicant when map is loaded
        map.on("load", function() {
            addApplicantMarkers();
            
            // Trigger geolocate on load
            setTimeout(function() {
                geolocate.trigger();
                
                // Listen for geolocate events
                geolocate.on("geolocate", function(e) {
                    userLocation = [e.coords.longitude, e.coords.latitude];
                    
                    // Update marker position
                    if (userLocationMarker) {
                        userLocationMarker.setLngLat(userLocation);
                    } else {
                        addUserLocationMarker(userLocation);
                    }
                });
            }, 1000);
        });
        
        // Route to applicant button
        document.getElementById("route-to-applicant-btn").addEventListener("click", function() {
            if (selectedApplicantPosition && userLocation) {
                showRoute(userLocation, selectedApplicantPosition);
            } else if (selectedApplicantPosition) {
                // Try to get user location first
                getUserLocation(function() {
                    if (userLocation) {
                        showRoute(userLocation, selectedApplicantPosition);
                    } else {
                        alert("Could not determine your location. Please enable location services.");
                    }
                });
            }
        });
        
        // Initialize Ably for real-time updates
        initAbly();
    }
    
    // Initialize Ably for real-time updates
    function initAbly() {
        try {
            // Get Ably token from API
            fetch("/bagoscout/api/ably-auth.php")
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error("Network response was not ok");
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (data.success && data.token) {
                        // Initialize Ably with token authentication
                        const ably = new Ably.Realtime({
                            token: data.token,
                            clientId: data.clientId
                        });
                        
                        // Subscribe to application notifications
                        const applicationsChannel = ably.channels.get("applications");
                        applicationsChannel.subscribe("new_application", function(message) {
                            // Handle new application notification
                            const app = JSON.parse(message.data);
                            
                            // Check if application is for this employer
                            if (app.employer_id === ' . $employerId . ') {
                                // Add application to applications array
                                applications.unshift(app);
                                
                                // Add marker with animation
                                addApplicantMarker(app, true);
                                
                                // Show notification
                                showNotification("New Application Received", app.first_name + " " + app.last_name + " applied for " + app.job_title);
                            }
                        });
                    } else {
                        console.error("Failed to get Ably token");
                    }
                })
                .catch(function(error) {
                    console.error("Error initializing Ably:", error);
                });
        } catch (error) {
            console.error("Error initializing Ably:", error);
        }
        
        // Load job postings for map display
        loadJobPostingsForMap();
    }
    
    // Load job postings for map display
    function loadJobPostingsForMap() {
        fetch("/bagoscout/api/jobs.php?action=get_all_jobs_employer")
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
        Object.values(jobMarkers).forEach(function(marker) {
            marker.remove();
        });
        jobMarkers = {};
        
        // Store current employer ID for comparison
        const currentEmployerId = parseInt("' . $employerId . '");
        
        jobs.forEach(function(job) {
            const position = [parseFloat(job.longitude), parseFloat(job.latitude)];
            
            // Skip if invalid coordinates
            if (isNaN(position[0]) || isNaN(position[1])) return;
            
            // Create marker element
            const el = document.createElement("div");
            el.className = "w-6 h-6 rounded-full flex items-center justify-center";
            
            // Check if this is the employer\'s own job posting
            const isOwnJob = parseInt(job.employer_id) === currentEmployerId;
            // console.log(isOwnJob);
            
            // Green for own job postings, blue for other job postings
            el.style.backgroundColor = isOwnJob ? "#10B981" : "#3B82F6";
            el.style.border = "2px solid white";
            el.style.boxShadow = "0 0 0 2px rgba(0,0,0,0.1)";
            
            // Create marker
            const marker = new mapboxgl.Marker(el)
                .setLngLat(position)
                .addTo(map);
            
            // Store marker reference
            jobMarkers[job.id] = marker;
            
            // Add click listener
            el.addEventListener("click", function() {
                // Show job details modal
                showJobDetailsModal(job);
            });
        });
    }
    
    // Show job details modal
    function showJobDetailsModal(job) {
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
                    
                    <div class="mb-4">
                        <p><i class="fas fa-building text-blue-500 mr-2"></i> <span id="job-company"></span></p>
                        <p><i class="fas fa-briefcase text-green-500 mr-2"></i> <span id="job-type"></span></p>
                        <p><i class="fas fa-money-bill-wave text-red-500 mr-2"></i> <span id="job-salary"></span></p>
                        <p><i class="fas fa-calendar-alt text-purple-500 mr-2"></i> Deadline: <span id="job-deadline"></span></p>
                        <p><i class="fas fa-users text-indigo-500 mr-2"></i> Applicants: <span id="job-applicants"></span></p>
                    </div>
                    
                    <div class="mb-4">
                        <h3 class="text-lg font-semibold mb-2">Description</h3>
                        <div id="job-description" class="text-sm text-gray-700 max-h-40 overflow-y-auto"></div>
                    </div>
                    
                    <div class="flex space-x-2 mt-6">
                        <button id="route-to-job-btn" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition flex-1">
                            <i class="fas fa-route mr-2"></i> Get Directions
                        </button>
                        <a id="view-job-details-btn" target="_blank" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition flex-1 text-center">
                            <i class="fas fa-eye mr-2"></i> View Details
                        </a>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Add close button event listener
            document.getElementById("close-job-modal").addEventListener("click", function() {
                document.getElementById("job-details-modal").classList.add("hidden");
            });
        }
        
        // Update modal content with job details
        document.getElementById("job-title").textContent = job.title;
        document.getElementById("job-location").textContent = job.location;
        document.getElementById("job-company").textContent = job.company_name || "Company information not available";
        document.getElementById("job-type").textContent = job.job_type || "Not specified";
        
        // Format salary
        let salaryDisplay = "";
        if (job.salary_min && job.salary_max) {
            salaryDisplay = "₱" + Number(job.salary_min).toLocaleString() + " - ₱" + Number(job.salary_max).toLocaleString();
        } else if (job.salary_min) {
            salaryDisplay = "From ₱" + Number(job.salary_min).toLocaleString();
        } else if (job.salary_max) {
            salaryDisplay = "Up to ₱" + Number(job.salary_max).toLocaleString();
        } else {
            salaryDisplay = "Not specified";
        }
        
        if (job.pay_type) {
            if (job.pay_type === "hourly") {
                salaryDisplay += " per hour";
            } else if (job.pay_type === "monthly") {
                salaryDisplay += " per month";
            } else if (job.pay_type === "annual") {
                salaryDisplay += " per year";
            }
        }
        
        document.getElementById("job-salary").textContent = salaryDisplay;
        
        // Format deadline
        if (job.deadline) {
            const deadline = new Date(job.deadline);
            document.getElementById("job-deadline").textContent = deadline.toLocaleDateString("en-US", { 
                year: "numeric", 
                month: "long", 
                day: "numeric" 
            });
        } else {
            document.getElementById("job-deadline").textContent = "Not specified";
        }
        
        // Show applicant count if available
        if (job.application_count) {
            document.getElementById("job-applicants").textContent = job.application_count;
        } else {
            document.getElementById("job-applicants").textContent = "0";
        }
        
        // Set description
        document.getElementById("job-description").innerHTML = job.description ? job.description.replace(/\n/g, "<br>") : "No description available";
        
        // Set view details link
        document.getElementById("view-job-details-btn").href = "view-job.php?id=" + job.id;
        
        // Add route button event listener
        document.getElementById("route-to-job-btn").onclick = function() {
            document.getElementById("job-details-modal").classList.add("hidden");
            
            if (userLocation) {
                showRoute(userLocation, [parseFloat(job.longitude), parseFloat(job.latitude)]);
            } else {
                getUserLocation(function() {
                    if (userLocation) {
                        showRoute(userLocation, [parseFloat(job.longitude), parseFloat(job.latitude)]);
                    } else {
                        alert("Could not determine your location. Please enable location services.");
                    }
                });
            }
        };
        
        // Show modal
        modal.classList.remove("hidden");
    }
    
    // Add jobs to the clustering system
    function addJobsToCluster(jobs) {
        if (!supercluster) return;
        
        // Current employer ID for comparison
        const currentEmployerId = parseInt("' . $employerId . '");
        
        // Convert jobs to GeoJSON for clustering
        const jobPoints = jobs.map(function(job) {
            const lng = parseFloat(job.longitude);
            const lat = parseFloat(job.latitude);
            
            // Skip if invalid coordinates
            if (isNaN(lng) || isNaN(lat)) return null;
            
            // Check if this is the employer\'s own job posting
            const isOwnJob = parseInt(job.employer_id) === currentEmployerId;
            
            return {
                type: "Feature",
                properties: {
                    id: job.id,
                    title: job.title,
                    location: job.location,
                    isJob: true, // Flag to identify job points
                    isOwnJob: isOwnJob // Flag to identify own job postings
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
                    if (document.querySelector(".mapboxgl-ctrl-directions").style.display !== "none" && selectedApplicantPosition) {
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
        
        // Set origin and destination
        if (directions) {
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
    
    // Add applicant markers to map
    function addApplicantMarkers() {
        // Convert applications to GeoJSON for clustering
        const points = applications.map(function(app) {
            const lng = parseFloat(app.longitude);
            const lat = parseFloat(app.latitude);
            
            // Skip if invalid coordinates
            if (isNaN(lng) || isNaN(lat)) return null;
            
            return {
                type: "Feature",
                properties: {
                    id: app.id,
                    job_id: app.job_id,
                    job_title: app.job_title,
                    first_name: app.first_name,
                    last_name: app.last_name,
                    email: app.email,
                    phone: app.phone,
                    location: app.location,
                    applied_date: app.applied_date,
                    isNew: false
                },
                geometry: {
                    type: "Point",
                    coordinates: [lng, lat]
                }
            };
        }).filter(Boolean); // Remove null entries
        
        // Initialize supercluster
        supercluster = new Supercluster({
            radius: 40,
            maxZoom: 16
        });
        
        // Load points into the cluster index
        supercluster.load(points);
        
        // Initial update of clusters based on current map view
        updateClusters();
        
        // Update clusters when map moves
        map.on("moveend", updateClusters);
        
        // Fit map to markers if any exist
        if (points.length > 0) {
            const bounds = new mapboxgl.LngLatBounds();
            
            // Add user location to bounds if available
            if (userLocation) {
                bounds.extend(userLocation);
            }
            
            // Add all points to bounds
            points.forEach(function(point) {
                bounds.extend(point.geometry.coordinates);
            });
            
            map.fitBounds(bounds, { padding: 50 });
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
                const hasOwnJobs = clusterLeaves.some(function(leaf) { return leaf.properties.isJob && leaf.properties.isOwnJob; });
                const hasOtherJobs = clusterLeaves.some(function(leaf) { return leaf.properties.isJob && !leaf.properties.isOwnJob; });
                
                // Style based on cluster content
                if (hasOwnJobs && hasOtherJobs) {
                    // Mixed cluster (own jobs and other jobs)
                    el.style.background = "linear-gradient(135deg, #3B82F6 50%, #10B981 50%)";
                } else if (hasOwnJobs) {
                    // Own jobs cluster
                    el.style.backgroundColor = "#10B981"; // Green for own job clusters
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
                    isNew: cluster.properties.isNew
                };
                
                // Add individual marker
                const marker = addSingleApplicantMarker(app);
                
                // Store marker for later removal
                clusterMarkers.push(marker);
            }
        });
        
        // Fit map to include both applicant markers and job markers
        if (map.loaded() && (Object.keys(markers).length > 0 || Object.keys(jobMarkers).length > 0)) {
            const bounds = new mapboxgl.LngLatBounds();
            let hasPoints = false;
            
            // Add user location to bounds if available
            if (userLocation) {
                bounds.extend(userLocation);
                hasPoints = true;
            }
            
            // Add applicant markers to bounds
            Object.values(markers).forEach(function(marker) {
                bounds.extend(marker.getLngLat());
                hasPoints = true;
            });
            
            // Add job markers to bounds
            Object.values(jobMarkers).forEach(function(marker) {
                bounds.extend(marker.getLngLat());
                hasPoints = true;
            });
            
            // Only fit bounds if we have points
            if (hasPoints) {
                map.fitBounds(bounds, { padding: 50 });
            }
        }
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
        if (app.isNew) {
            el.style.backgroundColor = "#EF4444"; // Red for new applications
            el.classList.add("pulse");
        } else {
            el.style.backgroundColor = "#3B82F6"; // Blue for regular applications
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
        if (app.isNew) {
            newApplicationMarkers[app.id] = marker;
            
            // After 30 seconds, convert to regular marker
            setTimeout(function() {
                if (newApplicationMarkers[app.id]) {
                    newApplicationMarkers[app.id].remove();
                    delete newApplicationMarkers[app.id];
                    app.isNew = false;
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
    function loadJobPostings() {
        // Show loading state
        const jobPostingList = document.getElementById("job-posting-list");
        jobPostingList.innerHTML = `<div class="text-center py-8 text-gray-500"><i class="fas fa-spinner fa-spin text-3xl mb-3"></i><p>Loading job postings...</p></div>`;
        
        fetch("/bagoscout/api/jobs.php?action=get_employer_jobs")
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                console.log("API response:", data); // Debug log
                if (data.success && data.jobs) {
                    renderJobPostings(data.jobs);
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
                    <p class="text-gray-500">You haven\'t created any job postings yet.</p>
                    <a href="post-job.php" class="mt-4 inline-block px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                        <i class="fas fa-plus mr-2"></i> Create Job
                    </a>
                </div>`;
            return;
        }
        
        let htmlContent = "";
        for (let i = 0; i < jobs.length; i++) {
            const job = jobs[i];
            const statusClass = job.status === "active" ? "bg-green-100 text-green-800" : 
                             (job.status === "paused" ? "bg-yellow-100 text-yellow-800" : "bg-gray-100 text-gray-800");
            const statusText = job.status.charAt(0).toUpperCase() + job.status.slice(1);
            
            htmlContent += `<div class="border border-gray-200 rounded-md p-3 hover:bg-gray-50 transition cursor-pointer job-posting-item" data-job-id="${job.id}" data-lat="${job.latitude}" data-lng="${job.longitude}">
                <div class="flex justify-between items-center">
                    <h4 class="font-medium text-gray-900">${job.title}</h4>
                    <button class="text-gray-500 hover:text-gray-700 expand-job-btn" title="Expand details">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                <p class="text-sm text-gray-500"><i class="fas fa-map-marker-alt mr-1"></i> ${job.location}</p>
                <div class="flex justify-between items-center mt-2">
                    <span class="text-xs px-2 py-1 rounded-full ${statusClass}">${statusText}</span>
                    <div class="flex space-x-2">
                        <button class="text-blue-600 hover:text-blue-800 get-directions-btn" data-lat="${job.latitude}" data-lng="${job.longitude}" data-title="${job.title}" title="Get Directions">
                            <i class="fas fa-route"></i>
                        </button>
                        <a href="view-applications.php?job=${job.id}" target="_blank" class="text-indigo-600 hover:text-indigo-800" title="View Applications">
                            <i class="fas fa-eye"></i>
                        </a>
                        <a href="post-job.php?edit=${job.id}" target="_blank" class="text-green-600 hover:text-green-800" title="Edit Job">
                            <i class="fas fa-edit"></i>
                        </a>
                    </div>
                </div>
                
                <!-- Expandable content (initially hidden) -->
                <div class="job-details mt-3 pt-3 border-t border-gray-200 hidden">
                    <div class="space-y-2 text-sm">
                        <p><span class="font-medium">Job Type:</span> ${job.job_type || "Not specified"}</p>
                        <p><span class="font-medium">Salary:</span> ${formatSalary(job.salary_min, job.salary_max, job.pay_type)}</p>
                        <p><span class="font-medium">Deadline:</span> ${job.deadline ? new Date(job.deadline).toLocaleDateString() : "Not specified"}</p>
                        
                        <div class="mt-2">
                            <p class="font-medium mb-1">Description:</p>
                            <p class="text-gray-600 text-xs line-clamp-3">${job.description || "No description available"}</p>
                        </div>
                        
                        <div class="mt-2">
                            <a href="view-job.php?id=${job.id}" class="text-blue-600 hover:text-blue-800 text-xs">
                                View full details <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>`;
        }
        
        jobPostingList.innerHTML = htmlContent;
        
        // Add event listeners after DOM is loaded
        document.addEventListener("DOMContentLoaded", function() {
            // Set up job posting list expand/collapse functionality
            const jobPostingsList = document.getElementById("job-postings-list");
            if (jobPostingsList) {
                jobPostingsList.addEventListener("click", function(e) {
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
                        }
                    }
                    
                    // Handle directions button
                    if (e.target.closest(".get-directions-btn")) {
                        const button = e.target.closest(".get-directions-btn");
                        const lat = parseFloat(button.getAttribute("data-lat"));
                        const lng = parseFloat(button.getAttribute("data-lng"));
                        
                        if (userLocation) {
                            showRoute(userLocation, [lng, lat]);
                        } else {
                            getUserLocation(function() {
                                if (userLocation) {
                                    showRoute(userLocation, [lng, lat]);
                                } else {
                                    alert("Could not determine your location. Please enable location services.");
                                }
                            });
                        }
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
                        
                        if (!isNaN(lat) && !isNaN(lng)) {
                            // Center map on job location
                            map.flyTo({
                                center: [lng, lat],
                                zoom: 15
                            });
                            
                            // Trigger click on the corresponding marker
                            if (jobMarkers[jobId]) {
                                jobMarkers[jobId].getElement().click();
                            }
                        }
                    }
                });
            }
        });
    }
    
    // Show job postings error
    function showJobPostingsError(message) {
        const jobPostingList = document.getElementById("job-posting-list");
        jobPostingList.innerHTML = 
            `<div class="text-center py-8">
                <div class="text-red-400 mb-4">
                    <i class="fas fa-exclamation-circle text-5xl"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-1">Error</h3>
                <p class="text-gray-500">${message}</p>
            </div>`;
    }
    
    // Show job posting sidebar for specific job
    function showJobPostingSidebar(jobId) {
        // Open sidebar
        jobPostingSidebar.classList.remove("translate-x-full");
        
        // Load job postings
        loadJobPostings();
        
        // Highlight the selected job
        setTimeout(function() {
            const selectedJobSelector = `.job-posting-item[data-job-id="${jobId}"]`;
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

    // Initialize map
    initMap();
});
</script>
';

// Include layout
include_once 'nav/layout.php';
?> 