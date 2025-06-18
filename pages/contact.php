<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/mail.php'; // Ensure mail.php is included

$success = '';
$error = '';

// Check for success message in session
if (isset($_SESSION['contact_success'])) {
    $success = $_SESSION['contact_success'];
    unset($_SESSION['contact_success']); // Clear the message after displaying it
}

// Check for error message in session
if (isset($_SESSION['contact_error'])) {
    $error = $_SESSION['contact_error'];
    unset($_SESSION['contact_error']); // Clear the message after displaying it
}

// Initialize form variables
$name = isset($_SESSION['contact_form']['name']) ? $_SESSION['contact_form']['name'] : '';
$email = isset($_SESSION['contact_form']['email']) ? $_SESSION['contact_form']['email'] : '';
$subject = isset($_SESSION['contact_form']['subject']) ? $_SESSION['contact_form']['subject'] : '';
$message = isset($_SESSION['contact_form']['message']) ? $_SESSION['contact_form']['message'] : '';

// Clear form data if not needed
if (isset($_SESSION['contact_form'])) {
    unset($_SESSION['contact_form']);
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = sanitizeInput($_POST['name']);
    $email = sanitizeInput($_POST['email']);
    $subject = sanitizeInput($_POST['subject']);
    $message = sanitizeInput($_POST['message']);
    
    // Validate inputs
    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
        $_SESSION['contact_error'] = "All fields are required";
        
        // Store form data in session to repopulate the form
        $_SESSION['contact_form'] = [
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message
        ];
    } elseif (!isValidEmail($email)) {
        $_SESSION['contact_error'] = "Please enter a valid email address";
        
        // Store form data in session to repopulate the form
        $_SESSION['contact_form'] = [
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message
        ];
    } else {
        // Save to database
        $conn = getDbConnection();
        $stmt = $conn->prepare("INSERT INTO contact_messages (name, email, subject, message, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssss", $name, $email, $subject, $message);
        
        if ($stmt->execute()) {
            $_SESSION['contact_success'] = "Your message has been sent successfully. We'll get back to you soon!";
            
            // Send notification email to admin
            $emailSubject = "New Contact Message: " . $subject;
            $emailBody = "Name: " . $name . "\n";
            $emailBody .= "Email: " . $email . "\n\n";
            $emailBody .= "Message:\n" . $message;

            // Send email directly to bagoscout@gmail.com
            sendEmail('bagoscout@gmail.com', $emailSubject, $emailBody);

            // Also send to any other admin emails from the database
            $adminEmails = getAdminEmails();
            foreach ($adminEmails as $adminEmail) {
                // Skip if it's already the main email
                if ($adminEmail != 'bagoscout@gmail.com') {
                    sendEmail($adminEmail, $emailSubject, $emailBody);
                }
            }

            // Send confirmation email to user
            $userSubject = "Thank you for contacting BagoScout";
            $userBody = "Dear " . $name . ",\n\nThank you for contacting us. We have received your message and will get back to you soon!\n\nBest regards,\nThe BagoScout Team";
            sendEmail($email, $userSubject, $userBody);
            
        } else {
            $_SESSION['contact_error'] = "Failed to send message. Please try again later.";
            
            // Store form data in session to repopulate the form
            $_SESSION['contact_form'] = [
                'name' => $name,
                'email' => $email,
                'subject' => $subject,
                'message' => $message
            ];
        }
        $stmt->close();
        $conn->close();
    }
    
    // Redirect to the same page to avoid form resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Set page title
$pageTitle = "Contact Us";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BagoScout - <?php echo $pageTitle; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="../assets/css/custom.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    
<?php require_once 'imports/header.php'; ?>

<!-- Main Content -->
<main class="mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold text-gray-800 mb-8 text-center">Contact Us</h1>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <!-- Contact Information -->
            <div class="md:col-span-1">
                <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4">Get in Touch</h2>
                    
                    <div class="space-y-4">
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-500">
                                    <i class="fas fa-map-marker-alt"></i>
                                </div>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-sm font-medium text-gray-900">Address</h3>
                                <p class="text-sm text-gray-600">123 Main Street, Bacolod City, Philippines</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-500">
                                    <i class="fas fa-phone"></i>
                                </div>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-sm font-medium text-gray-900">Phone</h3>
                                <p class="text-sm text-gray-600">+63 956 418 6361</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-500">
                                    <i class="fas fa-envelope"></i>
                                </div>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-sm font-medium text-gray-900">Email</h3>
                                <p class="text-sm text-gray-600">bagoscout@gmail.com</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-8">
                        <h3 class="text-sm font-medium text-gray-900 mb-3">Follow Us</h3>
                        <div class="flex space-x-4">
                            <a href="#" class="text-gray-400 hover:text-blue-500">
                                <i class="fab fa-facebook-f text-xl"></i>
                            </a>
                            <a href="#" class="text-gray-400 hover:text-blue-400">
                                <i class="fab fa-twitter text-xl"></i>
                            </a>
                            <a href="#" class="text-gray-400 hover:text-pink-500">
                                <i class="fab fa-instagram text-xl"></i>
                            </a>
                            <a href="#" class="text-gray-400 hover:text-blue-700">
                                <i class="fab fa-linkedin-in text-xl"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Contact Form -->
            <div class="md:col-span-2">
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4">Send Us a Message</h2>
                    
                    <?php if (!empty($success)): ?>
                        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                            <p><?php echo $success; ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($error)): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                            <p><?php echo $error; ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <form action="contact.php" method="post">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Your Name</label>
                                <input type="text" id="name" name="name" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required value="<?php echo htmlspecialchars($name); ?>">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                                <input type="email" id="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required value="<?php echo htmlspecialchars($email); ?>">
                            </div>
                        </div>
                        
                        <div class="mb-6">
                            <label for="subject" class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                            <input type="text" id="subject" name="subject" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required value="<?php echo htmlspecialchars($subject); ?>">
                        </div>
                        
                        <div class="mb-6">
                            <label for="message" class="block text-sm font-medium text-gray-700 mb-1">Message</label>
                            <textarea id="message" name="message" rows="6" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required><?php echo htmlspecialchars($message); ?></textarea>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="px-6 py-2 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-md transition duration-300">
                                <i class="fas fa-paper-plane mr-2"></i> Send Message
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Map Section -->
        <div class="mt-12">
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Our Location</h2>
                <div class="aspect-w-16 aspect-h-9">
                    <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d124927.37171475355!2d122.94581771640625!3d10.667602899999994!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x33aed1fb8c8f85a7%3A0x8a5793f3161c7ab4!2sBacolod%20City%20Hall!5e0!3m2!1sen!2sph!4v1655971475000!5m2!1sen!2sph" 
                        width="100%" height="450" style="border:0;" allowfullscreen="" loading="lazy" 
                        referrerpolicy="no-referrer-when-downgrade" class="rounded-md"></iframe>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include 'imports/footer.php';?>

</body>
</html> 