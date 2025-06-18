<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard.php");
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['account_type'])) {
        $_SESSION['registration'] = [
            'account_type' => $_POST['account_type'],
            'step' => 1
        ];
        
        // Redirect to step 2
        header("Location: register-step2.php");
        exit();
    }
}

// Set page title
$pageTitle = "Register - Account Type";

// Custom styles for registration
$additionalStyles = '
/* Registration specific styles */
.account-type {
    cursor: pointer;
    transition: all 0.3s ease;
}
.account-type:hover {
    border-color: #3B82F6;
    background-color: #EFF6FF;
}
.account-type.selected {
    border-color: #3B82F6;
    background-color: #EFF6FF;
}
';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="../../assets/css/custom.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <title>BagoScout - <?php echo $pageTitle; ?></title>
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
            
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-blue-500 text-white font-semibold">
                1
            </div>
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-gray-200 text-gray-600 font-semibold">
                2
            </div>
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-gray-200 text-gray-600 font-semibold">
                3
            </div>
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-gray-200 text-gray-600 font-semibold">
                4
            </div>
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-gray-200 text-gray-600 font-semibold">
                5
            </div>
        </div>
        
        <h3 class="text-xl font-semibold text-gray-700 mb-6 text-center">Select Account Type</h3>
        
        <form action="register-step1.php" method="post" id="account-type-form">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <div class="account-type border-2 border-gray-200 rounded-lg p-6 text-center cursor-pointer transition duration-300 hover:border-blue-500 hover:bg-blue-50" data-type="employer">
                    <i class="fas fa-building text-5xl text-blue-500 mb-4"></i>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Company Account</h3>
                    <p class="text-gray-600 text-sm">Create an account for your business or organization</p>
                    <input type="radio" name="account_type" value="employer" class="hidden">
                </div>
                
                <div class="account-type border-2 border-gray-200 rounded-lg p-6 text-center cursor-pointer transition duration-300 hover:border-blue-500 hover:bg-blue-50" data-type="jobseeker">
                    <i class="fas fa-user text-5xl text-blue-500 mb-4"></i>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Individual Job Seeker</h3>
                    <p class="text-gray-600 text-sm">Create an account to find job opportunities</p>
                    <input type="radio" name="account_type" value="jobseeker" class="hidden">
                </div>
            </div>
            
            <div class="text-center">
                <button type="submit" id="next-btn" class="px-8 py-2 bg-blue-500 text-white font-medium rounded-md opacity-50 cursor-not-allowed" disabled>Next</button>
            </div>
        </form>
    </div>
</main>

<?php include '../imports/footer.php';?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const accountTypes = document.querySelectorAll('.account-type');
        const nextBtn = document.getElementById('next-btn');
        
        accountTypes.forEach(function(type) {
            type.addEventListener('click', function() {
                // Remove selected class from all account types
                accountTypes.forEach(function(el) {
                    el.classList.remove('selected');
                });
                
                // Add selected class to the clicked account type
                this.classList.add('selected');
                
                // Check the radio button
                const radio = this.querySelector('input[type="radio"]');
                radio.checked = true;
                
                // Enable the next button
                nextBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                nextBtn.classList.add('hover:bg-blue-600');
                nextBtn.disabled = false;
            });
        });
    });
</script>
</body>
</html>