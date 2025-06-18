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
if (!isset($_SESSION['registration']) || $_SESSION['registration']['step'] < 4 || $_SESSION['registration']['account_type'] !== 'jobseeker') {
    header("Location: register-step1.php");
    exit();
}

$error = '';
$success = '';

// Available work fields with associated skills
$workFieldsWithSkills = [
    'Technology' => [
        'Web Development', 'Mobile App Development', 'Software Engineering', 
        'Database Administration', 'Network Security', 'Cloud Computing', 
        'DevOps', 'UI/UX Design', 'Data Science', 'Machine Learning'
    ],
    'Engineering' => [
        'Civil Engineering', 'Mechanical Engineering', 'Electrical Engineering', 
        'Chemical Engineering', 'Aerospace Engineering', 'Industrial Engineering', 
        'Environmental Engineering', 'Biomedical Engineering'
    ],
    'Healthcare' => [
        'Nursing', 'Medical Doctor', 'Physical Therapy', 'Pharmacy', 
        'Medical Laboratory', 'Radiology', 'Dental Care', 'Mental Health'
    ],
    'Construction' => [
        'Architecture', 'Carpentry', 'Plumbing', 'Electrical', 
        'Masonry', 'Welding', 'Project Management', 'Heavy Equipment Operation'
    ],
    'Automotive' => [
        'Mechanical Repair', 'Electrical Systems', 'Body Work', 
        'Painting', 'Diagnostics', 'Sales', 'Parts Management'
    ],
    'Agriculture' => [
        'Crop Production', 'Livestock Management', 'Agricultural Engineering', 
        'Pest Management', 'Soil Science', 'Irrigation', 'Organic Farming'
    ],
    'Tourism' => [
        'Hotel Management', 'Tour Guide', 'Event Planning', 
        'Customer Service', 'Food Service', 'Travel Agency', 'Resort Management'
    ],
    'Sales and Marketing' => [
        'Digital Marketing', 'Content Creation', 'Social Media Management', 
        'SEO/SEM', 'Market Research', 'Public Relations', 'Sales Strategy'
    ],
    'Education' => [
        'Teaching', 'Curriculum Development', 'Educational Administration', 
        'Special Education', 'Early Childhood Education', 'Adult Education'
    ],
    'Manufacturing' => [
        'Production Management', 'Quality Control', 'Assembly', 
        'Machining', 'Fabrication', 'Supply Chain Management'
    ],
    'Finance' => [
        'Accounting', 'Financial Analysis', 'Investment Banking', 
        'Insurance', 'Tax Preparation', 'Auditing', 'Financial Planning'
    ],
    'Retail' => [
        'Store Management', 'Inventory Control', 'Visual Merchandising', 
        'Customer Service', 'Sales', 'Purchasing', 'E-commerce'
    ]
];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['work_fields']) && !empty($_POST['work_fields'])) {
        $selectedFields = $_POST['work_fields'];
        
        if (count($selectedFields) > 0) {
            // Store work fields in session
            $_SESSION['registration']['work_fields'] = $selectedFields;
            
            // Collect skills
            $skills = [];
            
            if (isset($_POST['skills']) && is_array($_POST['skills'])) {
                $skills = array_merge($skills, $_POST['skills']);
            }
            
            // Add custom skills
            if (isset($_POST['custom_skills']) && !empty($_POST['custom_skills'])) {
                $customSkills = explode(',', $_POST['custom_skills']);
                foreach ($customSkills as $skill) {
                    $skill = trim($skill);
                    if (!empty($skill)) {
                        $skills[] = $skill;
                    }
                }
            }
            
            if (count($skills) > 0) {
                $_SESSION['registration']['skills'] = $skills;
                
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
                    $role = 'jobseeker';
                    $status = 'unverified';
                    
                    $stmt->bind_param("ssssssssisss", $first_name, $last_name, $email, $password, $phone, $birthdate, $gender, $address, $token, $isVerified, $role, $status);
                    $stmt->execute();
                    
                    // Get the user ID
                    $userId = $conn->insert_id;
                    
                    // Insert jobseeker data
                    $stmt = $conn->prepare("INSERT INTO jobseekers (user_id, fields, skills, facephoto, valid_id) VALUES (?, ?, ?, null, null)");
                    
                    $fieldsString = implode(', ', $_SESSION['registration']['work_fields']);
                    $skillsString = implode(', ', $_SESSION['registration']['skills']);
                    
                    $stmt->bind_param("iss", $userId, $fieldsString, $skillsString);
                    $stmt->execute();
                    
                    // Commit transaction
                    $conn->commit();
                    
                    // Set success message
                    $success = "Your jobseeker account has been created successfully!";
                    
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
                $error = "Please select at least one skill";
            }
        } else {
            $error = "Please select at least one work field";
        }
    } else {
        $error = "Please select at least one work field";
    }
}

// Set page title
$pageTitle = "Register - Skills & Expertise";

// Convert the PHP array to a JSON object for JavaScript
$skillsJson = json_encode($workFieldsWithSkills);
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
        
        <h3 class="text-xl font-semibold text-gray-700 mb-6 text-center">Skills & Expertise</h3>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p><?php echo $error; ?></p>
            </div>
        <?php endif; ?>
        
        <form action="register-jobseeker-step5.php" method="post">
            <div class="form-group mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-3">Select Work Fields</label>
                <p class="text-sm text-gray-500 mb-3">Choose the fields you're interested in working in</p>
                
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <?php foreach ($workFieldsWithSkills as $field => $skills): ?>
                        <div class="flex items-center">
                            <input type="checkbox" id="field_<?php echo strtolower(str_replace(' ', '_', $field)); ?>" 
                                   name="work_fields[]" 
                                   value="<?php echo $field; ?>" 
                                   class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 field-checkbox"
                                   data-field="<?php echo htmlspecialchars($field, ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="field_<?php echo strtolower(str_replace(' ', '_', $field)); ?>" class="ml-2 text-sm text-gray-700">
                                <?php echo $field; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div id="skills-container" class="form-group mb-6" style="display: none;">
                <label class="block text-sm font-medium text-gray-700 mb-3">Select Your Skills</label>
                <p class="text-sm text-gray-500 mb-3">Choose the skills you possess in your selected fields</p>
                
                <div id="skills-list">
                    <?php foreach ($workFieldsWithSkills as $field => $skills): ?>
                        <div id="skills-<?php echo strtolower(str_replace(' ', '_', $field)); ?>" class="mb-4 field-skills" style="display: none;">
                            <h4 class="font-medium text-gray-800 mb-2"><?php echo $field; ?></h4>
                            <div class="grid grid-cols-2 gap-2">
                                <?php foreach ($skills as $index => $skill): ?>
                                    <div class="flex items-center">
                                        <input type="checkbox" 
                                               id="skill_<?php echo strtolower(str_replace(' ', '_', $field)); ?>_<?php echo $index; ?>" 
                                               name="skills[]" 
                                               value="<?php echo htmlspecialchars($skill, ENT_QUOTES, 'UTF-8'); ?>" 
                                               class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                        <label for="skill_<?php echo strtolower(str_replace(' ', '_', $field)); ?>_<?php echo $index; ?>" 
                                               class="ml-2 text-sm text-gray-700">
                                            <?php echo $skill; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="form-group mb-6">
                <label for="custom_skills" class="block text-sm font-medium text-gray-700 mb-1">Additional Skills (Optional)</label>
                <p class="text-sm text-gray-500 mb-2">Enter any additional skills separated by commas</p>
                <input type="text" id="custom_skills" name="custom_skills" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="e.g. Project Management, Leadership, Communication">
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
            text: 'Your account has been created successfully. You can now log in.',
            icon: 'success',
            confirmButtonText: 'OK',
            confirmButtonColor: '#2563eb'
        }).then((result) => {
            <?php unset($_SESSION['registration_success']); ?>
        });
        <?php endif; ?>

        // Get all field checkboxes
        const fieldCheckboxes = document.querySelectorAll('.field-checkbox');
        const skillsContainer = document.getElementById('skills-container');
        
        // Add event listeners to field checkboxes
        fieldCheckboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                const field = this.getAttribute('data-field');
                const fieldId = 'skills-' + field.toLowerCase().replace(/\s+/g, '_');
                const fieldSkills = document.getElementById(fieldId);
                
                if (this.checked) {
                    fieldSkills.style.display = 'block';
                } else {
                    fieldSkills.style.display = 'none';
                    
                    // Uncheck all skills in this field
                    const skillCheckboxes = fieldSkills.querySelectorAll('input[type="checkbox"]');
                    skillCheckboxes.forEach(function(skillCheckbox) {
                        skillCheckbox.checked = false;
                    });
                }
                
                // Show skills container if any field is checked
                let anyFieldChecked = false;
                fieldCheckboxes.forEach(function(cb) {
                    if (cb.checked) {
                        anyFieldChecked = true;
                    }
                });
                
                skillsContainer.style.display = anyFieldChecked ? 'block' : 'none';
            });
        });
        
        // Form validation
        const form = document.querySelector('form');
        
        form.addEventListener('submit', function(event) {
            let isWorkFieldSelected = false;
            let isSkillSelected = false;
            
            // Check if at least one work field is selected
            fieldCheckboxes.forEach(function(checkbox) {
                if (checkbox.checked) {
                    isWorkFieldSelected = true;
                }
            });
            
            // If no work fields are selected, prevent form submission
            if (!isWorkFieldSelected) {
                event.preventDefault();
                alert('Please select at least one work field');
                return;
            }
            
            // Check if at least one skill is selected or custom skills are provided
            const skillCheckboxes = document.querySelectorAll('input[name="skills[]"]');
            const customSkills = document.getElementById('custom_skills').value.trim();
            
            skillCheckboxes.forEach(function(checkbox) {
                if (checkbox.checked) {
                    isSkillSelected = true;
                }
            });
            
            if (!isSkillSelected && customSkills === '') {
                event.preventDefault();
                alert('Please select at least one skill or enter custom skills');
            }
        });
    });
</script>
</body>
</html> 