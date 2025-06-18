<?php
// Database check and initialization script
require_once 'config/database.php';

// Function to check if a table exists
function tableExists($tableName) {
    $conn = getDbConnection();
    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
    $exists = $result->num_rows > 0;
    $conn->close();
    return $exists;
}

// Check if tables exist
$tables = ['users', 'employers', 'jobseekers'];
$missingTables = [];

foreach ($tables as $table) {
    if (!tableExists($table)) {
        $missingTables[] = $table;
    }
}

// Output results
echo "<h1>Database Table Check</h1>";

if (empty($missingTables)) {
    echo "<p style='color: green;'>All required tables exist in the database.</p>";
} else {
    echo "<p style='color: red;'>The following tables are missing: " . implode(', ', $missingTables) . "</p>";
    echo "<p>Attempting to create missing tables...</p>";
    
    // Reinitialize the database
    initDatabase();
    
    // Check again
    $stillMissing = [];
    foreach ($missingTables as $table) {
        if (!tableExists($table)) {
            $stillMissing[] = $table;
        }
    }
    
    if (empty($stillMissing)) {
        echo "<p style='color: green;'>All tables have been created successfully!</p>";
    } else {
        echo "<p style='color: red;'>Failed to create the following tables: " . implode(', ', $stillMissing) . "</p>";
        echo "<p>Please check your database configuration and permissions.</p>";
    }
}

// Show database connection info
echo "<h2>Database Connection Information</h2>";
echo "<ul>";
echo "<li>Host: " . DB_HOST . "</li>";
echo "<li>Database: " . DB_NAME . "</li>";
echo "<li>User: " . DB_USER . "</li>";
echo "</ul>";

// Show PHP and server info
echo "<h2>Server Information</h2>";
echo "<ul>";
echo "<li>PHP Version: " . phpversion() . "</li>";
echo "<li>Server: " . $_SERVER['SERVER_SOFTWARE'] . "</li>";
echo "</ul>";

// Link to go back to registration
echo "<p><a href='register-step1.php'>Return to Registration</a></p>";
?> 