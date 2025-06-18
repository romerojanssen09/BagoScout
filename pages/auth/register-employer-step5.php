<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard.php");
    exit();
}

// Check if registration session exists and user completed step 4
if (!isset($_SESSION['registration']) || $_SESSION['registration']['step'] < 4 || $_SESSION['registration']['account_type'] !== 'employer') {
    header("Location: register-step1.php");
    exit();
}

$error = '';
$success = '';

// Available work fields
$workFields = [
    'Technology',
    'Engineering',
    'Healthcare',
    'Construction',
    'Automotive',
    'Agriculture',
    'Tourism',
    'Sales and Marketing',
    'Education',
    'Manufacturing',
    'Finance',
    'Retail'
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ((isset($_POST['work_fields']) && !empty($_POST['work_fields'])) || !empty($_POST['custom_field'])) {
        $selectedFields = isset($_POST['work_fields']) ? $_POST['work_fields'] : [];
        
        // Add custom field if provided
        if (!empty($_POST['custom_field'])) {
            $customField = trim($_POST['custom_field']);
            $selectedFields[] = $customField;
        }
        
        if (count($selectedFields) > 0) {
            // Store work fields in session
            $_SESSION['registration']['work_fields'] = $selectedFields;
            
            // Additional employer fields
            $_SESSION['registration']['company_name'] = trim($_POST['company_name']);
            $_SESSION['registration']['company_type'] = trim($_POST['company_type']);
            $_SESSION['registration']['role_in_company'] = trim($_POST['role_in_company']);
            $_SESSION['registration']['company_url'] = trim($_POST['company_url']);
            
            // Create user account
            $conn = getDbConnection();
            
            try {
                // Start transaction
                $conn->begin_transaction();
                
                // Insert user data
                $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, phone, birthdate, gender, address, token, is_verified, role, status, created_at, last_login) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                
                $first_name = $_SESSION['registration']['first_name'];
                $last_name = $_SESSION['registration']['last_name'];
                $email = $_SESSION['registration']['email'];
                $password = $_SESSION['registration']['password'];
                $phone = $_SESSION['registration']['phone'];
                $birthdate = $_SESSION['registration']['birthdate'];
                $gender = $_SESSION['registration']['gender'];
                $address = $_SESSION['registration']['address'];
                $token = $_SESSION['registration']['token'];
                $isVerified = isset($_SESSION['registration']['email_verified']) && $_SESSION['registration']['email_verified'] ? 1 : 0;
                $role = 'employer';
                $status = 'unverified';
                
                $stmt->bind_param("ssssssssisss", $first_name, $last_name, $email, $password, $phone, $birthdate, $gender, $address, $token, $isVerified, $role, $status);
                $stmt->execute();
                
                // Get the user ID
                $userId = $conn->insert_id;
                
                // Insert employer data
                $stmt = $conn->prepare("INSERT INTO employers (user_id, company_name, company_type, role_in_company, company_url, fields, facephoto, valid_id) VALUES (?, ?, ?, ?, ?, ?, null, null)");
                
                $fieldsString = implode(', ', $_SESSION['registration']['work_fields']);
                
                $stmt->bind_param("isssss", $userId, $_SESSION['registration']['company_name'], $_SESSION['registration']['company_type'], $_SESSION['registration']['role_in_company'], $_SESSION['registration']['company_url'], $fieldsString);
                $stmt->execute();
                
                // Commit transaction
                $conn->commit();
                
                // Set success message
                $success = "Your employer account has been created successfully!";
                
                // Clear registration session
                unset($_SESSION['registration']);
                
                // Set success session variable
                $_SESSION['registration_success'] = true;
                
                // Redirect to login page
                header("Location: ../../index.php");
                exit();
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $error = "Registration failed: " . $e->getMessage();
            }
            
            $conn->close();
        } else {
            $error = "Please select at least one work field";
        }
    } else {
        $error = "Please select at least one work field";
    }
}

// Set page title
$pageTitle = "Register - Company Information";
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-green-500 text-white font-semibold">
                <i class="fas fa-check text-sm"></i>
            </div>
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-blue-500 text-white font-semibold">
                5
            </div>
        </div>
        
        <h3 class="text-xl font-semibold text-gray-700 mb-6 text-center">Company Information</h3>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>
        
        <form action="register-employer-step5.php" method="post">
            <div class="form-group mb-6">
                <label for="company_name" class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                <input type="text" id="company_name" name="company_name" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div class="form-group">
                    <label for="company_type" class="block text-sm font-medium text-gray-700 mb-1">Company Type</label>
                    <select id="company_type" name="company_type" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        <option value="" disabled selected>Select company type</option>
                        <option value="Corporation">Corporation</option>
                        <option value="Partnership">Partnership</option>
                        <option value="Sole Proprietorship">Sole Proprietorship</option>
                        <option value="LLC">LLC</option>
                        <option value="Non-Profit">Non-Profit</option>
                        <option value="Government">Government</option>
                        <option value="Educational">Educational</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="role_in_company" class="block text-sm font-medium text-gray-700 mb-1">Your Role in Company</label>
                    <input type="text" id="role_in_company" name="role_in_company" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                </div>
            </div>
            
            <div class="form-group mb-6">
                <label for="company_url" class="block text-sm font-medium text-gray-700 mb-1">Company Website (Optional)</label>
                <input type="url" id="company_url" name="company_url" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="https://example.com">
            </div>
            
            <div class="form-group mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-3">Select Work Fields</label>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <?php foreach ($workFields as $field): ?>
                        <div class="flex items-center">
                            <input type="checkbox" id="field_<?php echo strtolower(str_replace(' ', '_', $field)); ?>" name="work_fields[]" value="<?php echo $field; ?>" class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <label for="field_<?php echo strtolower(str_replace(' ', '_', $field)); ?>" class="ml-2 text-sm text-gray-700"><?php echo $field; ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="form-group mb-6">
                <label for="custom_field" class="block text-sm font-medium text-gray-700 mb-1">Other Work Field (Optional)</label>
                <input type="text" id="custom_field" name="custom_field" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Enter custom field">
            </div>
            
            <div class="flex justify-between mt-8">
                <a href="register-step4.php" class="px-6 py-2 bg-gray-500 hover:bg-gray-600 text-white font-medium rounded-md transition duration-300">Back</a>
                <button type="submit" class="px-8 py-2 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-md transition duration-300">Complete Registration</button>
            </div>
        </form>
    </div>
</main>

<?php include '../imports/footer.php';?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Check for registration success
        <?php if (isset($_SESSION['registration_success'])): ?>
        Swal.fire({
            title: 'Registration Successful!',
            text: 'Your employer account has been created successfully. You can now log in.',
            icon: 'success',
            confirmButtonText: 'OK',
            confirmButtonColor: '#2563eb'
        }).then((result) => {
            <?php unset($_SESSION['registration_success']); ?>
        });
        <?php endif; ?>

        // Ensure at least one work field is selected
        const form = document.querySelector('form');
        const checkboxes = document.querySelectorAll('input[type="checkbox"]');
        const customField = document.getElementById('custom_field');
        
        form.addEventListener('submit', function(event) {
            let isChecked = false;
            
            checkboxes.forEach(function(checkbox) {
                if (checkbox.checked) {
                    isChecked = true;
                }
            });
            
            if (!isChecked && customField.value.trim() === '') {
                event.preventDefault();
                alert('Please select at least one work field or enter a custom field');
            }
        });
    });
</script>
</body>
</html> 