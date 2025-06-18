<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/mail.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard.php");
    exit();
}

// Check if registration session exists and user completed step 2
if (!isset($_SESSION['registration']) || $_SESSION['registration']['step'] < 2) {
    header("Location: register-step1.php");
    exit();
}

$error = '';
$success = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['verification_code'])) {
        $verificationCode = trim($_POST['verification_code']);
        
        // Allow "12345" as a test code in local environment
        if ($verificationCode === "12345" && ($_SERVER['SERVER_NAME'] == 'localhost' || $_SERVER['HTTP_HOST'] == 'localhost')) {
            // Mark email as verified for testing
            $_SESSION['registration']['email_verified'] = true;
            $_SESSION['registration']['step'] = 3;
            
            // Redirect to step 4
            header("Location: register-step4.php");
            exit();
        }
        
        // Verify the code against the token in session
        if ($verificationCode === $_SESSION['registration']['token']) {
            // Mark email as verified
            $_SESSION['registration']['email_verified'] = true;
            $_SESSION['registration']['step'] = 3;
            
            // Redirect to step 4
            header("Location: register-step4.php");
            exit();
        } else {
            $error = "Invalid verification code. Please try again.";
        }
    } elseif (isset($_POST['resend_code'])) {
        // Resend verification email
        $first_name = $_SESSION['registration']['first_name'];
        $last_name = $_SESSION['registration']['last_name'];
        $email = $_SESSION['registration']['email'];
        $token = $_SESSION['registration']['token'];
        
        if (initiateVerificationEmail($email, $first_name, $last_name, $token)) {
            $success = "Verification code has been resent to your email.";
        } else {
            $error = "Failed to resend verification code. Please try again.";
        }
    } elseif (isset($_GET['test_verification']) && ($_SERVER['SERVER_NAME'] == 'localhost' || $_SERVER['HTTP_HOST'] == 'localhost')) {
        // Development environment bypass
        $_SESSION['registration']['email_verified'] = true;
        $_SESSION['registration']['step'] = 3;
        
        // Redirect to step 4
        header("Location: register-step4.php");
        exit();
    }
}

// Set page title
$pageTitle = "Register - Email Verification";
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
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-blue-500 text-white font-semibold">
                3
            </div>
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-gray-200 text-gray-600 font-semibold">
                4
            </div>
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-gray-200 text-gray-600 font-semibold">
                5
            </div>
        </div>
        
        <h3 class="text-xl font-semibold text-gray-700 mb-6 text-center">Email Verification</h3>
        
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
        
        <div class="text-center">
            <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 text-left">
                <p class="mb-2">We've sent a verification code to:</p>
                <p class="font-semibold mb-2"><?php echo htmlspecialchars($_SESSION['registration']['email']); ?></p>
                <p>Please check your email and enter the code below to continue.</p>
            </div>
            
            <form action="register-step3.php" method="post">
                <div class="form-group mb-6">
                    <label for="verification_code" class="block text-sm font-medium text-gray-700 mb-1">Verification Code</label>
                    <input type="text" id="verification_code" name="verification_code" class="w-full max-w-xs mx-auto px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-center text-lg" required>
                </div>
                
                <div class="flex justify-between mt-8">
                    <a href="register-step2.php" class="px-6 py-2 bg-gray-500 hover:bg-gray-600 text-white font-medium rounded-md transition duration-300">Back</a>
                    <button type="submit" class="px-8 py-2 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-md transition duration-300">Verify</button>
                </div>
            </form>
            
            <form action="register-step3.php" method="post" class="mt-6">
                <input type="hidden" name="resend_code" value="1">
                <p class="text-gray-600">Didn't receive the code? 
                    <button type="submit" class="text-blue-500 hover:text-blue-700 font-medium focus:outline-none">
                        Resend code
                    </button>
                </p>
            </form>
        </div>
    </div>
</main>

<?php include '../imports/footer.php';?>
</body>
</html> 