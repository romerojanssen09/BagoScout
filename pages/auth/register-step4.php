<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard.php");
    exit();
}

// Check if registration session exists and user completed step 3
if (!isset($_SESSION['registration']) || $_SESSION['registration']['step'] < 3) {
    header("Location: register-step1.php");
    exit();
}

$error = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Validate password
    if (empty($password) || empty($confirmPassword)) {
        $error = "Please fill in all fields";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = "Password must contain at least one uppercase letter";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = "Password must contain at least one lowercase letter";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = "Password must contain at least one number";
    } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $error = "Password must contain at least one special character";
    } else {
        // Store password in session
        $_SESSION['registration']['password'] = password_hash($password, PASSWORD_DEFAULT);
        $_SESSION['registration']['step'] = 4;
        
        // Redirect to step 5 based on account type
        if ($_SESSION['registration']['account_type'] === 'employer') {
            header("Location: register-employer-step5.php");
        } else {
            header("Location: register-jobseeker-step5.php");
        }
        exit();
    }
}

// Set page title
$pageTitle = "Register - Account Security";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BagoScout - <?php echo $pageTitle; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="../../assets/css/custom.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    
<?php require_once '../imports/header.php'; ?>

<!-- Main Content -->
<main class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto bg-white rounded-lg shadow-sm p-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">Create an Account</h2>
        
        <!-- Registration Steps -->
        <div class="flex justify-between mb-8 relative">
            <div class="absolute top-1/2 left-0 right-0 h-0.5 bg-gray-200 -translate-y-1/2"></div>
            
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-green-500 text-white font-semibold">
                <i class="fas fa-check text-sm"></i>
            </div>
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-green-500 text-white font-semibold">
                <i class="fas fa-check text-sm"></i>
            </div>
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-green-500 text-white font-semibold">
                <i class="fas fa-check text-sm"></i>
            </div>
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-blue-500 text-white font-semibold">
                4
            </div>
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-gray-200 text-gray-600 font-semibold">
                5
            </div>
        </div>
        
        <h3 class="text-xl font-semibold text-gray-700 mb-6 text-center">Account Security</h3>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>
        
        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
            <h4 class="font-semibold mb-2">Password Requirements</h4>
            <ul class="pl-5">
                <li id="length-check" class="mb-1 flex items-center">
                    <i class="fas fa-times-circle text-red-500 mr-2"></i>
                    <span>At least 8 characters</span>
                </li>
                <li id="uppercase-check" class="mb-1 flex items-center">
                    <i class="fas fa-times-circle text-red-500 mr-2"></i>
                    <span>One uppercase letter</span>
                </li>
                <li id="lowercase-check" class="mb-1 flex items-center">
                    <i class="fas fa-times-circle text-red-500 mr-2"></i>
                    <span>One lowercase letter</span>
                </li>
                <li id="number-check" class="mb-1 flex items-center">
                    <i class="fas fa-times-circle text-red-500 mr-2"></i>
                    <span>One number</span>
                </li>
                <li id="special-check" class="mb-1 flex items-center">
                    <i class="fas fa-times-circle text-red-500 mr-2"></i>
                    <span>One special character</span>
                </li>
                <li id="match-check" class="mb-1 flex items-center">
                    <i class="fas fa-times-circle text-red-500 mr-2"></i>
                    <span>Passwords match</span>
                </li>
            </ul>
        </div>
        
        <form action="register-step4.php" method="post">
            <div class="form-group mb-6">
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <div class="relative">
                    <input type="password" id="password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                    <button type="button" class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-500 focus:outline-none" onclick="togglePasswordVisibility('password')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="form-group mb-6">
                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                <div class="relative">
                    <input type="password" id="confirm_password" name="confirm_password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                    <button type="button" class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-500 focus:outline-none" onclick="togglePasswordVisibility('confirm_password')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="flex justify-between mt-8">
                <a href="register-step3.php" class="px-6 py-2 bg-gray-500 hover:bg-gray-600 text-white font-medium rounded-md transition duration-300">Back</a>
                <button type="submit" id="submit-btn" class="px-8 py-2 bg-blue-500 text-white font-medium rounded-md opacity-50 cursor-not-allowed" disabled>Next</button>
            </div>
        </form>
    </div>
</main>

<?php include '../imports/footer.php';?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const submitBtn = document.getElementById('submit-btn');
        
        // Password requirement checks
        const lengthCheck = document.getElementById('length-check');
        const uppercaseCheck = document.getElementById('uppercase-check');
        const lowercaseCheck = document.getElementById('lowercase-check');
        const numberCheck = document.getElementById('number-check');
        const specialCheck = document.getElementById('special-check');
        const matchCheck = document.getElementById('match-check');
        
        function validatePassword() {
            const password = passwordInput.value;
            const confirmPassword = confirmPasswordInput.value;
            let isValid = true;
            
            // Check length
            if (password.length >= 8) {
                lengthCheck.querySelector('i').className = 'fas fa-check-circle text-green-500 mr-2';
            } else {
                lengthCheck.querySelector('i').className = 'fas fa-times-circle text-red-500 mr-2';
                isValid = false;
            }
            
            // Check uppercase
            if (/[A-Z]/.test(password)) {
                uppercaseCheck.querySelector('i').className = 'fas fa-check-circle text-green-500 mr-2';
            } else {
                uppercaseCheck.querySelector('i').className = 'fas fa-times-circle text-red-500 mr-2';
                isValid = false;
            }
            
            // Check lowercase
            if (/[a-z]/.test(password)) {
                lowercaseCheck.querySelector('i').className = 'fas fa-check-circle text-green-500 mr-2';
            } else {
                lowercaseCheck.querySelector('i').className = 'fas fa-times-circle text-red-500 mr-2';
                isValid = false;
            }
            
            // Check number
            if (/[0-9]/.test(password)) {
                numberCheck.querySelector('i').className = 'fas fa-check-circle text-green-500 mr-2';
            } else {
                numberCheck.querySelector('i').className = 'fas fa-times-circle text-red-500 mr-2';
                isValid = false;
            }
            
            // Check special character
            if (/[^A-Za-z0-9]/.test(password)) {
                specialCheck.querySelector('i').className = 'fas fa-check-circle text-green-500 mr-2';
            } else {
                specialCheck.querySelector('i').className = 'fas fa-times-circle text-red-500 mr-2';
                isValid = false;
            }
            
            // Check if passwords match
            if (password && confirmPassword && password === confirmPassword) {
                matchCheck.querySelector('i').className = 'fas fa-check-circle text-green-500 mr-2';
            } else {
                matchCheck.querySelector('i').className = 'fas fa-times-circle text-red-500 mr-2';
                isValid = false;
            }
            
            // Enable/disable submit button
            if (isValid && password && confirmPassword) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                submitBtn.classList.add('hover:bg-blue-600');
            } else {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                submitBtn.classList.remove('hover:bg-blue-600');
            }
        }
        
        passwordInput.addEventListener('input', validatePassword);
        confirmPasswordInput.addEventListener('input', validatePassword);
    });
    
    function togglePasswordVisibility(inputId) {
        const input = document.getElementById(inputId);
        const icon = input.nextElementSibling.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'fas fa-eye-slash';
        } else {
            input.type = 'password';
            icon.className = 'fas fa-eye';
        }
    }
</script>
</body>
</html> 