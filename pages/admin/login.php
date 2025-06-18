<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if admin is already logged in
if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];
    
    // Validate fields
    if (empty($email) || empty($password)) {
        $error = "All fields are required";
    } else {
        // Check if email exists in admins table
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT id, first_name, last_name, password FROM admins WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $admin = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $admin['password'])) {
                // Set session variables
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['first_name'] . ' ' . $admin['last_name'];
                $_SESSION['admin_role'] = 'admin';
                
                // Update last login time (optional)
                $stmt = $conn->prepare("UPDATE admins SET updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("i", $admin['id']);
                $stmt->execute();
                
                // Redirect to admin dashboard
                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Invalid email or password";
            }
        } else {
            $error = "Invalid email or password";
        }
        
        $conn->close();
    }
}

// Set page title
$pageTitle = "Admin Login";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BagoScout - <?php echo $pageTitle; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="../../assets/css/custom.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <!-- SweetAlert2 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.4.2/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.4.2/dist/sweetalert2.all.min.js"></script>
</head>
<body>
    
<?php require_once '../imports/header.php'; ?>

<!-- Main Content -->
<main class="mx-auto px-4 py-8">
    <div class="max-w-md mx-auto bg-white rounded-lg shadow-sm p-8">
        <div class="text-center text-6xl text-blue-500 mb-6">
            <i class="fas fa-user-shield"></i>
        </div>
        
        <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">Admin Login</h2>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                <p><?php echo $success; ?></p>
            </div>
        <?php endif; ?>
        
        <form action="login.php" method="post">
            <div class="mb-6">
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input type="email" id="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            </div>
            
            <div class="mb-6">
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <div class="relative">
                    <input type="password" id="password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                    <button type="button" class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-500 focus:outline-none toggle-password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="flex justify-between mt-8">
                <a href="../../index.php" class="px-6 py-2 bg-gray-500 hover:bg-gray-600 text-white font-medium rounded-md transition duration-300">Cancel</a>
                <button type="submit" class="px-8 py-2 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-md transition duration-300">Login</button>
            </div>
        </form>
    </div>
</main>

<?php include '../imports/footer.php';?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const togglePassword = document.querySelector('.toggle-password');
        const passwordInput = document.getElementById('password');
        
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle the eye icon
            const icon = this.querySelector('i');
            if (type === 'password') {
                icon.className = 'fas fa-eye';
            } else {
                icon.className = 'fas fa-eye-slash';
            }
        });
    });
</script>
</body>
</html> 
<?php
if (isset($_SESSION['success'])) {
    echo "<script>
        Swal.fire({
            title: 'Success',
            text: '" . $_SESSION['success'] . "',
            icon: 'success'
        });
    </script>";
    unset($_SESSION['success']);
}
?>