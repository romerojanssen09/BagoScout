<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'admin') {
    header("Location: ../admin-login.php");
    exit();
}

// Get admin information
$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'];

// Get counts from database
$conn = getDbConnection();

// Count total users
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM users");
$stmt->execute();
$result = $stmt->get_result();
$totalUsers = $result->fetch_assoc()['total'];

// Count job seekers
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE role = 'jobseeker'");
$stmt->execute();
$result = $stmt->get_result();
$totalJobSeekers = $result->fetch_assoc()['total'];

// Count employers
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE role = 'employer'");
$stmt->execute();
$result = $stmt->get_result();
$totalEmployers = $result->fetch_assoc()['total'];

// Count admins
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE role = 'admin'");
$stmt->execute();
$result = $stmt->get_result();
$totalAdmins = $result->fetch_assoc()['total'];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - BagoScout</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .dashboard-container {
            display: flex;
            min-height: calc(100vh - 150px);
        }
        
        .sidebar {
            width: 250px;
            background-color: #2c3e50;
            color: #fff;
            padding: 20px 0;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 5px;
        }
        
        .sidebar-menu a {
            display: block;
            padding: 10px 20px;
            color: #fff;
            text-decoration: none;
            transition: background-color 0.3s;
        }
        
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background-color: #3498db;
        }
        
        .sidebar-menu i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        
        .main-content {
            flex: 1;
            padding: 20px;
            background-color: #f5f5f5;
        }
        
        .page-title {
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background-color: #fff;
            border-radius: 5px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .stat-card i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #3498db;
        }
        
        .stat-card h3 {
            margin: 0;
            font-size: 36px;
            color: #2c3e50;
        }
        
        .stat-card p {
            margin: 10px 0 0;
            color: #7f8c8d;
            font-size: 14px;
            text-transform: uppercase;
        }
        
        .welcome-message {
            background-color: #fff;
            border-radius: 5px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .admin-header h2 {
            margin: 0;
        }
        
        .admin-actions {
            display: flex;
            gap: 10px;
        }
        
        .admin-actions a {
            padding: 8px 15px;
            background-color: #3498db;
            color: #fff;
            border-radius: 4px;
            text-decoration: none;
            font-size: 14px;
            transition: background-color 0.3s;
        }
        
        .admin-actions a:hover {
            background-color: #2980b9;
        }
        
        .admin-actions a.logout {
            background-color: #e74c3c;
        }
        
        .admin-actions a.logout:hover {
            background-color: #c0392b;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>BagoScout</h1>
            <nav>
                <ul>
                    <li><a href="../index.php" target="_blank">View Site</a></li>
                    <li><a href="logout.php" class="logout-btn">Logout</a></li>
                </ul>
            </nav>
        </header>
        
        <div class="dashboard-container">
            <div class="sidebar">
                <ul class="sidebar-menu">
                    <li><a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="users.php"><i class="fas fa-users"></i> Users</a></li>
                    <li><a href="employers.php"><i class="fas fa-building"></i> Employers</a></li>
                    <li><a href="jobseekers.php"><i class="fas fa-user-tie"></i> Job Seekers</a></li>
                    <li><a href="settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                </ul>
            </div>
            
            <div class="main-content">
                <div class="admin-header">
                    <h2>Admin Dashboard</h2>
                    <div class="admin-actions">
                        <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                        <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
                
                <div class="welcome-message">
                    <h3>Welcome, <?php echo htmlspecialchars($admin_name); ?>!</h3>
                    <p>This is your admin dashboard where you can manage the BagoScout platform.</p>
                </div>
                
                <h3 class="page-title">System Statistics</h3>
                
                <div class="stats-container">
                    <div class="stat-card">
                        <i class="fas fa-users"></i>
                        <h3><?php echo $totalUsers; ?></h3>
                        <p>Total Users</p>
                    </div>
                    
                    <div class="stat-card">
                        <i class="fas fa-user-tie"></i>
                        <h3><?php echo $totalJobSeekers; ?></h3>
                        <p>Job Seekers</p>
                    </div>
                    
                    <div class="stat-card">
                        <i class="fas fa-building"></i>
                        <h3><?php echo $totalEmployers; ?></h3>
                        <p>Employers</p>
                    </div>
                    
                    <div class="stat-card">
                        <i class="fas fa-user-shield"></i>
                        <h3><?php echo $totalAdmins; ?></h3>
                        <p>Administrators</p>
                    </div>
                </div>
                
                <!-- More dashboard content can be added here -->
            </div>
        </div>
        
        <footer>
            <p>&copy; <?php echo date('Y'); ?> BagoScout</p>
        </footer>
    </div>
</body>
</html> 