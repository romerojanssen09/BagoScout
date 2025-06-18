<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard.php");
    exit();
}

// Check if registration session exists and user completed step 1
if (!isset($_SESSION['registration']) || $_SESSION['registration']['step'] < 1) {
    header("Location: register-step1.php");
    exit();
}

$error = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $firstName = trim($_POST['first_name']);
    $lastName = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $birthdate = $_POST['birthdate'];
    $gender = $_POST['gender'];
    $address = trim($_POST['address']);
    
    // Validate inputs
    if (empty($firstName) || empty($lastName) || empty($email) || empty($phone) || empty($birthdate) || empty($gender) || empty($address)) {
        $error = "Please fill in all fields";
    } elseif (!isValidEmail($email)) {
        $error = "Please enter a valid email address";
    } elseif (!preg_match('/^[0-9]{11}$/', $phone)) {
        $error = "Phone number must be 11 digits";
    } else {
        // Check if email already exists
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = "Email already exists. Please use a different email address.";
        } else {
            // Calculate age from birthdate
            $birthDate = new DateTime($birthdate);
            $today = new DateTime();
            $age = $today->diff($birthDate)->y;
            
            if ($age < 18) {
                $error = "You must be at least 18 years old to register";
            } else {
                // Store data in session
                $_SESSION['registration']['first_name'] = $firstName;
                $_SESSION['registration']['last_name'] = $lastName;
                $_SESSION['registration']['email'] = $email;
                $_SESSION['registration']['phone'] = $phone;
                $_SESSION['registration']['birthdate'] = $birthdate;
                $_SESSION['registration']['gender'] = $gender;
                $_SESSION['registration']['address'] = $address;
                $_SESSION['registration']['step'] = 2;
                
                // Generate verification token
                $token = bin2hex(random_bytes(32));
                $_SESSION['registration']['token'] = $token;
                
                if (initiateVerificationEmail($email, $firstName, $lastName, $token)) {
                    // Redirect to step 3
                    header("Location: register-step3.php");
                    exit();
                } else {
                    // Log the error for debugging
                    $logDir = __DIR__ . '/logs';
                    if (!file_exists($logDir)) {
                        mkdir($logDir, 0777, true);
                    }
                    $logFile = $logDir . '/email_error.log';
                    $errorMsg = date('Y-m-d H:i:s') . " - Failed to send email to: $email\n";
                    file_put_contents($logFile, $errorMsg, FILE_APPEND);
                    
                    $error = "Failed to send verification email. Please try again.";
                }
            }
        }
        
        $stmt->close();
        $conn->close();
    }
}

// Set page title
$pageTitle = "Register - Personal Information";
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
            <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-blue-500 text-white font-semibold">
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
        
        <h3 class="text-xl font-semibold text-gray-700 mb-6 text-center">Personal Information</h3>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>
        
        <form action="register-step2.php" method="post">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div class="form-group">
                    <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                    <input type="text" id="first_name" name="first_name" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : (isset($_SESSION['registration']['first_name']) ? htmlspecialchars($_SESSION['registration']['first_name']) : ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                    <input type="text" id="last_name" name="last_name" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : (isset($_SESSION['registration']['last_name']) ? htmlspecialchars($_SESSION['registration']['last_name']) : ''); ?>" required>
                </div>
            </div>
            
            <div class="form-group mb-6">
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" id="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : (isset($_SESSION['registration']['email']) ? htmlspecialchars($_SESSION['registration']['email']) : ''); ?>" required>
            </div>
            
            <div class="form-group mb-6">
                <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                <input type="tel" id="phone" name="phone" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : (isset($_SESSION['registration']['phone']) ? htmlspecialchars($_SESSION['registration']['phone']) : ''); ?>" maxlength="11" pattern="[0-9]{11}" required>
                <small class="text-gray-500 text-xs">Enter 11 digits phone number</small>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div class="form-group">
                    <label for="birthdate" class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                    <input type="date" id="birthdate" name="birthdate" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo isset($_POST['birthdate']) ? htmlspecialchars($_POST['birthdate']) : (isset($_SESSION['registration']['birthdate']) ? htmlspecialchars($_SESSION['registration']['birthdate']) : ''); ?>" required>
                    <small class="text-gray-500 text-xs">You must be at least 18 years old</small>
                </div>
                
                <div class="form-group">
                    <label for="gender" class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
                    <select id="gender" name="gender" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                        <option value="" disabled selected>Select gender</option>
                        <option value="male" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'male') ? 'selected' : ((isset($_SESSION['registration']['gender']) && $_SESSION['registration']['gender'] == 'male') ? 'selected' : ''); ?>>Male</option>
                        <option value="female" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'female') ? 'selected' : ((isset($_SESSION['registration']['gender']) && $_SESSION['registration']['gender'] == 'female') ? 'selected' : ''); ?>>Female</option>
                        <option value="prefer not to say" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'prefer not to say') ? 'selected' : ((isset($_SESSION['registration']['gender']) && $_SESSION['registration']['gender'] == 'prefer not to say') ? 'selected' : ''); ?>>Prefer not to say</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group mb-6">
                <label for="address" class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <textarea id="address" name="address" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : (isset($_SESSION['registration']['address']) ? htmlspecialchars($_SESSION['registration']['address']) : ''); ?></textarea>
            </div>
            
            <div class="flex justify-between mt-8">
                <a href="register-step1.php" class="px-6 py-2 bg-gray-500 hover:bg-gray-600 text-white font-medium rounded-md transition duration-300">Back</a>
                <button type="submit" class="px-8 py-2 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-md transition duration-300">Next</button>
            </div>
        </form>
    </div>
</main>

<?php include '../imports/footer.php';?>
</body>
</html> 