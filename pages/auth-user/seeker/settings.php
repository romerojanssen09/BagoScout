<?php
session_start();
require_once '../../../config/database.php';
require_once '../../../includes/functions.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: ../../../index.php");
    exit();
}

$success = '';
$error = '';
$photoSuccess = '';
$photoError = '';
$idSuccess = '';
$idError = '';

// Get user details
$conn = getDbConnection();
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

$roleStmt = $conn->prepare("SELECT * FROM jobseekers WHERE user_id = ?");
$roleStmt->bind_param("i", $_SESSION['user_id']);
$roleStmt->execute();
$roleResult = $roleStmt->get_result();
$roleData = $roleResult->fetch_assoc();
$roleStmt->close();

// Process profile form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);

    if (empty($name)) {
        $error = "Name cannot be empty";
    } else {
        // Update user name and phone
        $updateStmt = $conn->prepare("UPDATE users SET name = ?, phone = ? WHERE id = ?");
        $updateStmt->bind_param("ssi", $name, $phone, $_SESSION['user_id']);

        if ($updateStmt->execute()) {
            $success = "Profile updated successfully";
            $user['name'] = $name;
            $user['phone'] = $phone;
        } else {
            $error = "Failed to update profile";
        }

        $updateStmt->close();
    }

    // Redirect to prevent form resubmission
    header("Location: settings.php?success=" . urlencode($success) . "&error=" . urlencode($error));
    exit();
}

// Process about, location, education update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_details'])) {
    $about = trim($_POST['about']);
    $location = trim($_POST['location']);
    $education = trim($_POST['education']);
    $headline = trim($_POST['headline']);

    // Update jobseeker details
    $updateStmt = $conn->prepare("UPDATE jobseekers SET about = ?, location = ?, education = ?, headline = ? WHERE user_id = ?");
    $updateStmt->bind_param("ssssi", $about, $location, $education, $headline, $_SESSION['user_id']);

    if ($updateStmt->execute()) {
        $detailsSuccess = "Profile details updated successfully";

        // Update the roleData array
        if ($roleData) {
            $roleData['about'] = $about;
            $roleData['location'] = $location;
            $roleData['education'] = $education;
            $roleData['headline'] = $headline;
        }
    } else {
        $detailsError = "Failed to update profile details";
    }

    $updateStmt->close();

    // Redirect to prevent form resubmission
    header("Location: settings.php?details_success=" . urlencode($detailsSuccess) . "&details_error=" . urlencode($detailsError));
    exit();
}

// Process webcam photo upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_photo'])) {
    if (isset($_POST['webcam_image']) && !empty($_POST['webcam_image'])) {
        $img = $_POST['webcam_image'];
        $img = str_replace('data:image/png;base64,', '', $img);
        $img = str_replace(' ', '+', $img);
        $data = base64_decode($img);

        // Create directory if it doesn't exist
        $uploadDir = '../../../uploads/jobseeker/' . $_SESSION['user_id'] . '/documents/profile_photos/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $filename = 'user_' . $_SESSION['user_id'] . '_photo_' . time() . '.png';
        $file = $uploadDir . $filename;

        if (file_put_contents($file, $data)) {
            $photoPath = '../../../uploads/jobseeker/' . $_SESSION['user_id'] . '/documents/profile_photos/' . $filename;

            $updateStmt = $conn->prepare("UPDATE jobseekers SET facephoto = ? WHERE user_id = ?");
            $updateStmt->bind_param("si", $photoPath, $_SESSION['user_id']);

            if ($updateStmt->execute()) {
                $photoSuccess = "Photo uploaded successfully!";
                if ($roleData) {
                    $roleData['facephoto'] = $photoPath;
                }
                $updateStmt->close();

                // Redirect to prevent form resubmission
                header("Location: settings.php?photo_success=" . urlencode($photoSuccess));
                exit();
            } else {
                $photoError = "Failed to update database with photo information";
                $updateStmt->close();

                // Redirect to prevent form resubmission
                header("Location: settings.php?photo_error=" . urlencode($photoError));
                exit();
            }
        } else {
            $photoError = "Failed to save photo";

            // Redirect to prevent form resubmission
            header("Location: settings.php?photo_error=" . urlencode($photoError));
            exit();
        }
    } else {
        $photoError = "No photo captured";

        // Redirect to prevent form resubmission
        header("Location: settings.php?photo_error=" . urlencode($photoError));
        exit();
    }
}

// Process ID upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_id'])) {
    if (isset($_FILES['id_file']) && $_FILES['id_file']['error'] === UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $fileType = $_FILES['id_file']['type'];

        if (in_array($fileType, $allowedTypes)) {
            // Create directory if it doesn't exist
            $uploadDir = '../../../uploads/jobseeker/' . $_SESSION['user_id'] . '/documents/valid_ids/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $extension = pathinfo($_FILES['id_file']['name'], PATHINFO_EXTENSION);
            $filename = 'user_' . $_SESSION['user_id'] . '_id_' . time() . '.' . $extension;
            $file = $uploadDir . $filename;

            if (move_uploaded_file($_FILES['id_file']['tmp_name'], $file)) {
                $idPath = '../../../uploads/jobseeker/' . $_SESSION['user_id'] . '/documents/valid_ids/' . $filename;

                $updateStmt = $conn->prepare("UPDATE jobseekers SET valid_id = ? WHERE user_id = ?");
                $updateStmt->bind_param("si", $idPath, $_SESSION['user_id']);

                if ($updateStmt->execute()) {
                    $idSuccess = "ID uploaded successfully!";
                    if ($roleData) {
                        $roleData['valid_id'] = $idPath;
                    }
                    $updateStmt->close();

                    // Redirect to prevent form resubmission
                    header("Location: settings.php?id_success=" . urlencode($idSuccess));
                    exit();
                } else {
                    $idError = "Failed to update database with ID information";
                    $updateStmt->close();

                    // Redirect to prevent form resubmission
                    header("Location: settings.php?id_error=" . urlencode($idError));
                    exit();
                }
            } else {
                $idError = "Failed to save ID";

                // Redirect to prevent form resubmission
                header("Location: settings.php?id_error=" . urlencode($idError));
                exit();
            }
        } else {
            $idError = "Invalid file type. Please upload JPG, PNG or PDF files only.";

            // Redirect to prevent form resubmission
            header("Location: settings.php?id_error=" . urlencode($idError));
            exit();
        }
    } else {
        $idError = "No ID file uploaded or an error occurred";

        // Redirect to prevent form resubmission
        header("Location: settings.php?id_error=" . urlencode($idError));
        exit();
    }
}

// Process resume upload
$resumeSuccess = '';
$resumeError = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_resume'])) {
    // Get jobseeker ID
    $jobseekerStmt = $conn->prepare("SELECT id FROM jobseekers WHERE user_id = ?");
    $jobseekerStmt->bind_param("i", $_SESSION['user_id']);
    $jobseekerStmt->execute();
    $jobseekerResult = $jobseekerStmt->get_result();
    $jobseeker = $jobseekerResult->fetch_assoc();
    $jobseekerId = $jobseeker['id'];
    $jobseekerStmt->close();

    $uploadSuccess = 0;
    $uploadErrors = 0;

    // Handle multiple file uploads
    $fileCount = count($_FILES['resume_file']['name']);

    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['resume_file']['error'][$i] === UPLOAD_ERR_OK) {
            $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $fileType = $_FILES['resume_file']['type'][$i];

            if (in_array($fileType, $allowedTypes)) {
                // Create directory if it doesn't exist
                $uploadDir = '../../../uploads/jobseeker/' . $_SESSION['user_id'] . '/documents/resumes/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $extension = pathinfo($_FILES['resume_file']['name'][$i], PATHINFO_EXTENSION);
                $filename = 'user_' . $_SESSION['user_id'] . '_resume_' . time() . '_' . $i . '.' . $extension;
                $file = $uploadDir . $filename;
                $originalName = $_FILES['resume_file']['name'][$i];
                $fileSize = $_FILES['resume_file']['size'][$i];

                if (move_uploaded_file($_FILES['resume_file']['tmp_name'][$i], $file)) {
                    // Check if this is the first resume (make it default)
                    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM jobseeker_resumes WHERE jobseeker_id = ?");
                    $checkStmt->bind_param("i", $jobseekerId);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    $count = $checkResult->fetch_assoc()['count'];
                    $isDefault = ($count == 0 && $i == 0) ? 1 : 0;
                    $checkStmt->close();

                    // Insert into jobseeker_resumes table
                    $insertStmt = $conn->prepare("INSERT INTO jobseeker_resumes (jobseeker_id, filename, original_name, file_type, file_size, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $insertStmt->bind_param("isssii", $jobseekerId, $filename, $originalName, $fileType, $fileSize, $isDefault);

                    if ($insertStmt->execute()) {
                        $uploadSuccess++;
                    } else {
                        $uploadErrors++;
                    }
                    $insertStmt->close();
                } else {
                    $uploadErrors++;
                }
            } else {
                $uploadErrors++;
            }
        } else if ($_FILES['resume_file']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
            $uploadErrors++;
        }
    }

    if ($uploadSuccess > 0) {
        if ($uploadErrors > 0) {
            $resumeSuccess = "$uploadSuccess resume(s) uploaded successfully. $uploadErrors failed.";
        } else {
            $resumeSuccess = "$uploadSuccess resume(s) uploaded successfully!";
        }

        // Redirect to prevent form resubmission
        header("Location: settings.php?resume_success=" . urlencode($resumeSuccess));
        exit();
    } else if ($uploadErrors > 0) {
        $resumeError = "Failed to upload resume(s). Please check file types (PDF, DOC, DOCX only).";

        // Redirect to prevent form resubmission
        header("Location: settings.php?resume_error=" . urlencode($resumeError));
        exit();
    }
}

// Set resume as default
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_default_resume'])) {
    $resumeId = intval($_POST['resume_id']);

    // Get jobseeker ID
    $jobseekerStmt = $conn->prepare("SELECT id FROM jobseekers WHERE user_id = ?");
    $jobseekerStmt->bind_param("i", $_SESSION['user_id']);
    $jobseekerStmt->execute();
    $jobseekerResult = $jobseekerStmt->get_result();
    $jobseeker = $jobseekerResult->fetch_assoc();
    $jobseekerId = $jobseeker['id'];
    $jobseekerStmt->close();

    // Reset all resumes to not default
    $resetStmt = $conn->prepare("UPDATE jobseeker_resumes SET is_default = 0 WHERE jobseeker_id = ?");
    $resetStmt->bind_param("i", $jobseekerId);
    $resetStmt->execute();
    $resetStmt->close();

    // Set selected resume as default
    $defaultStmt = $conn->prepare("UPDATE jobseeker_resumes SET is_default = 1 WHERE id = ? AND jobseeker_id = ?");
    $defaultStmt->bind_param("ii", $resumeId, $jobseekerId);

    if ($defaultStmt->execute()) {
        $defaultStmt->close();
        $resumeSuccess = "Default resume updated successfully!";
        // Redirect to prevent form resubmission
        header("Location: settings.php?resume_success=" . urlencode($resumeSuccess));
        exit();
    } else {
        $defaultStmt->close();
        $resumeError = "Failed to update default resume";
        // Redirect to prevent form resubmission
        header("Location: settings.php?resume_error=" . urlencode($resumeError));
        exit();
    }
}

// Delete resume
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_resume'])) {
    $resumeId = intval($_POST['resume_id']);

    // Get jobseeker ID
    $jobseekerStmt = $conn->prepare("SELECT id FROM jobseekers WHERE user_id = ?");
    $jobseekerStmt->bind_param("i", $_SESSION['user_id']);
    $jobseekerStmt->execute();
    $jobseekerResult = $jobseekerStmt->get_result();
    $jobseeker = $jobseekerResult->fetch_assoc();
    $jobseekerId = $jobseeker['id'];
    $jobseekerStmt->close();

    // Get the filename to delete
    $fileStmt = $conn->prepare("SELECT filename FROM jobseeker_resumes WHERE id = ? AND jobseeker_id = ?");
    $fileStmt->bind_param("ii", $resumeId, $jobseekerId);
    $fileStmt->execute();
    $fileResult = $fileStmt->get_result();

    if ($fileResult->num_rows > 0) {
        $file = $fileResult->fetch_assoc();
        $filePath = '../../../uploads/jobseeker/' . $_SESSION['user_id'] . '/documents/resumes/' . $file['filename'];

        // Delete from database
        $deleteStmt = $conn->prepare("DELETE FROM jobseeker_resumes WHERE id = ? AND jobseeker_id = ?");
        $deleteStmt->bind_param("ii", $resumeId, $jobseekerId);

        if ($deleteStmt->execute()) {
            // Delete file if exists
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $resumeSuccess = "Resume deleted successfully!";

            // If this was the default resume, set another one as default if available
            $checkStmt = $conn->prepare("SELECT id FROM jobseeker_resumes WHERE jobseeker_id = ? ORDER BY created_at DESC LIMIT 1");
            $checkStmt->bind_param("i", $jobseekerId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                $newDefault = $checkResult->fetch_assoc();
                $updateStmt = $conn->prepare("UPDATE jobseeker_resumes SET is_default = 1 WHERE id = ?");
                $updateStmt->bind_param("i", $newDefault['id']);
                $updateStmt->execute();
                $updateStmt->close();
            }
            $checkStmt->close();
            $deleteStmt->close();
            // Redirect to prevent form resubmission
            header("Location: settings.php?resume_success=" . urlencode($resumeSuccess));
            exit();
        } else {
            $resumeError = "Failed to delete resume";
            $deleteStmt->close();
            // Redirect to prevent form resubmission
            header("Location: settings.php?resume_error=" . urlencode($resumeError));
            exit();
        }
    }
    $fileStmt->close();
}

// Process barangay clearance upload
$clearanceSuccess = '';
$clearanceError = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_clearance'])) {
    // Get jobseeker ID
    $jobseekerStmt = $conn->prepare("SELECT id FROM jobseekers WHERE user_id = ?");
    $jobseekerStmt->bind_param("i", $_SESSION['user_id']);
    $jobseekerStmt->execute();
    $jobseekerResult = $jobseekerStmt->get_result();
    $jobseeker = $jobseekerResult->fetch_assoc();
    $jobseekerId = $jobseeker['id'];
    $jobseekerStmt->close();

    $uploadSuccess = 0;
    $uploadErrors = 0;

    // Handle multiple file uploads
    $fileCount = count($_FILES['clearance_file']['name']);

    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['clearance_file']['error'][$i] === UPLOAD_ERR_OK) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $fileType = $_FILES['clearance_file']['type'][$i];

            if (in_array($fileType, $allowedTypes)) {
                // Create directory if it doesn't exist
                $uploadDir = '../../../uploads/jobseeker/' . $_SESSION['user_id'] . '/documents/police_clearance/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $extension = pathinfo($_FILES['clearance_file']['name'][$i], PATHINFO_EXTENSION);
                $filename = 'user_' . $_SESSION['user_id'] . '_clearance_' . time() . '_' . $i . '.' . $extension;
                $file = $uploadDir . $filename;
                $originalName = $_FILES['clearance_file']['name'][$i];
                $fileSize = $_FILES['clearance_file']['size'][$i];
                $clearanceType = 'police'; // Default type

                if (move_uploaded_file($_FILES['clearance_file']['tmp_name'][$i], $file)) {
                    // Check if this is the first clearance (make it default)
                    $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM jobseeker_clearances WHERE jobseeker_id = ?");
                    $checkStmt->bind_param("i", $jobseekerId);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    $count = $checkResult->fetch_assoc()['count'];
                    $isDefault = ($count == 0 && $i == 0) ? 1 : 0;
                    $checkStmt->close();

                    // Insert into jobseeker_clearances table
                    $insertStmt = $conn->prepare("INSERT INTO jobseeker_clearances (jobseeker_id, filename, original_name, file_type, file_size, clearance_type, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                    $insertStmt->bind_param("issssii", $jobseekerId, $filename, $originalName, $fileType, $fileSize, $clearanceType, $isDefault);

                    if ($insertStmt->execute()) {
                        $uploadSuccess++;
                    } else {
                        $uploadErrors++;
                    }
                    $insertStmt->close();
                } else {
                    $uploadErrors++;
                }
            } else {
                $uploadErrors++;
            }
        } else if ($_FILES['clearance_file']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
            $uploadErrors++;
        }
    }

    if ($uploadSuccess > 0) {
        if ($uploadErrors > 0) {
            $clearanceSuccess = "$uploadSuccess clearance(s) uploaded successfully. $uploadErrors failed.";
        } else {
            $clearanceSuccess = "$uploadSuccess clearance(s) uploaded successfully!";
        }

        // Redirect to prevent form resubmission
        header("Location: settings.php?clearance_success=" . urlencode($clearanceSuccess));
        exit();
    } else if ($uploadErrors > 0) {
        $clearanceError = "Failed to upload clearance(s). Please check file types (PDF, JPG, PNG only).";

        // Redirect to prevent form resubmission
        header("Location: settings.php?clearance_error=" . urlencode($clearanceError));
        exit();
    }
}

// Set clearance as default
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['set_default_clearance'])) {
    $clearanceId = intval($_POST['clearance_id']);

    // Get jobseeker ID
    $jobseekerStmt = $conn->prepare("SELECT id FROM jobseekers WHERE user_id = ?");
    $jobseekerStmt->bind_param("i", $_SESSION['user_id']);
    $jobseekerStmt->execute();
    $jobseekerResult = $jobseekerStmt->get_result();
    $jobseeker = $jobseekerResult->fetch_assoc();
    $jobseekerId = $jobseeker['id'];
    $jobseekerStmt->close();

    // Reset all clearances to not default
    $resetStmt = $conn->prepare("UPDATE jobseeker_clearances SET is_default = 0 WHERE jobseeker_id = ?");
    $resetStmt->bind_param("i", $jobseekerId);
    $resetStmt->execute();
    $resetStmt->close();

    // Set selected clearance as default
    $defaultStmt = $conn->prepare("UPDATE jobseeker_clearances SET is_default = 1 WHERE id = ? AND jobseeker_id = ?");
    $defaultStmt->bind_param("ii", $clearanceId, $jobseekerId);

    if ($defaultStmt->execute()) {
        $clearanceSuccess = "Default clearance updated successfully!";
        $defaultStmt->close();
        // Redirect to prevent form resubmission
        header("Location: settings.php?clearance_success=" . urlencode($clearanceSuccess));
        exit();
    } else {
        $clearanceError = "Failed to update default clearance";
        // Redirect to prevent form resubmission
        $defaultStmt->close();
        header("Location: settings.php?clearance_error=" . urlencode($clearanceError));
        exit();
    }
}

// Delete clearance
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_clearance'])) {
    $clearanceId = intval($_POST['clearance_id']);

    // Get jobseeker ID
    $jobseekerStmt = $conn->prepare("SELECT id FROM jobseekers WHERE user_id = ?");
    $jobseekerStmt->bind_param("i", $_SESSION['user_id']);
    $jobseekerStmt->execute();
    $jobseekerResult = $jobseekerStmt->get_result();
    $jobseeker = $jobseekerResult->fetch_assoc();
    $jobseekerId = $jobseeker['id'];
    $jobseekerStmt->close();

    // Get the filename to delete
    $fileStmt = $conn->prepare("SELECT filename FROM jobseeker_clearances WHERE id = ? AND jobseeker_id = ?");
    $fileStmt->bind_param("ii", $clearanceId, $jobseekerId);
    $fileStmt->execute();
    $fileResult = $fileStmt->get_result();

    if ($fileResult->num_rows > 0) {
        $file = $fileResult->fetch_assoc();
        $filePath = '../../../uploads/jobseeker/' . $_SESSION['user_id'] . '/documents/police_clearance/' . $file['filename'];

        // Delete from database
        $deleteStmt = $conn->prepare("DELETE FROM jobseeker_clearances WHERE id = ? AND jobseeker_id = ?");
        $deleteStmt->bind_param("ii", $clearanceId, $jobseekerId);

        if ($deleteStmt->execute()) {
            // Delete file if exists
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $clearanceSuccess = "Clearance deleted successfully!";

            // If this was the default clearance, set another one as default if available
            $checkStmt = $conn->prepare("SELECT id FROM jobseeker_clearances WHERE jobseeker_id = ? ORDER BY created_at DESC LIMIT 1");
            $checkStmt->bind_param("i", $jobseekerId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();

            if ($checkResult->num_rows > 0) {
                $newDefault = $checkResult->fetch_assoc();
                $updateStmt = $conn->prepare("UPDATE jobseeker_clearances SET is_default = 1 WHERE id = ?");
                $updateStmt->bind_param("i", $newDefault['id']);
                $updateStmt->execute();
                $updateStmt->close();
            }
            $checkStmt->close();

            // Redirect to prevent form resubmission
            header("Location: settings.php?clearance_success=" . urlencode($clearanceSuccess));
            exit();
        } else {
            $clearanceError = "Failed to delete clearance";
            // Redirect to prevent form resubmission
            header("Location: settings.php?clearance_error=" . urlencode($clearanceError));
            exit();
        }
    }
    $fileStmt->close();
    $deleteStmt->close();
}

// Request account approval
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_approval'])) {
    // Check if both photo and ID are uploaded
    $canRequestApproval = false;

    if ($roleData && !empty($roleData['facephoto']) && !empty($roleData['valid_id'])) {
        // Update user status to 'under_review'
        $updateStmt = $conn->prepare("UPDATE users SET status = 'under_review' WHERE id = ?");
        $updateStmt->bind_param("i", $_SESSION['user_id']);

        if ($updateStmt->execute()) {
            $success = "Your account approval request has been submitted. An administrator will review your information.";
            $user['status'] = 'under_review';
            $updateStmt->close();

            // Redirect to prevent form resubmission
            header("Location: settings.php?success=" . urlencode($success));
            exit();
        } else {
            $error = "Failed to submit approval request";
            $updateStmt->close();

            // Redirect to prevent form resubmission
            header("Location: settings.php?error=" . urlencode($error));
            exit();
        }
    } else {
        $error = "Please upload both your photo and valid ID before requesting approval";

        // Redirect to prevent form resubmission
        header("Location: settings.php?error=" . urlencode($error));
        exit();
    }
}

// Process form submission for adding skills or fields
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_skills_fields'])) {
    $conn = getDbConnection();
    // Process skills update for jobseekers
    $skills = sanitizeInput($_POST['skills']);
    $fields = sanitizeInput($_POST['fields']);

    $stmt = $conn->prepare("UPDATE jobseekers SET skills = ?, fields = ? WHERE user_id = ?");
    $stmt->bind_param("ssi", $skills, $fields, $_SESSION['user_id']);

    if ($stmt->execute()) {
        $success = "Your skills and fields have been updated successfully.";

        // Refresh jobseeker data
        $stmt = $conn->prepare("SELECT * FROM jobseekers WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $jobseekerData = $stmt->get_result()->fetch_assoc();

        // Redirect to prevent form resubmission
        header("Location: settings.php?success=" . urlencode($success));
        exit();
    } else {
        $error = "Failed to update skills and fields. Please try again.";

        // Redirect to prevent form resubmission
        header("Location: settings.php?error=" . urlencode($error));
        exit();
    }
}
// Set page title
$pageTitle = "Skills & Expertise";

// Initialize variables for displaying resumes and clearances
$jobseekerId = null;
$hasResumes = false;
$hasClearances = false;
$resumeResult = null;
$clearanceResult = null;

// Get jobseeker ID and documents for display
$conn = getDbConnection();
if (isset($_SESSION['user_id'])) {
    $jobseekerStmt = $conn->prepare("SELECT id FROM jobseekers WHERE user_id = ?");
    $jobseekerStmt->bind_param("i", $_SESION['user_id']);
    $jobseekerStmt->execute();
    $jobseekerResult = $jobseekerStmt->get_result();

    if ($jobseekerResult->num_rows > 0) {
        $jobseeker = $jobseekerResult->fetch_assoc();
        $jobseekerId = $jobseeker['id'];

        // Get all resumes
        $resumeStmt = $conn->prepare("SELECT * FROM jobseeker_resumes WHERE jobseeker_id = ? ORDER BY is_default DESC, created_at DESC");
        $resumeStmt->bind_param("i", $jobseekerId);
        $resumeStmt->execute();
        $resumeResult = $resumeStmt->get_result();
        $hasResumes = $resumeResult->num_rows > 0;
        $resumeStmt->close();

        // Get all clearances
        $clearanceStmt = $conn->prepare("SELECT * FROM jobseeker_clearances WHERE jobseeker_id = ? ORDER BY is_default DESC, created_at DESC");
        $clearanceStmt->bind_param("i", $jobseekerId);
        $clearanceStmt->execute();
        $clearanceResult = $clearanceStmt->get_result();
        $hasClearances = $clearanceResult->num_rows > 0;
        $clearanceStmt->close();
    }
    $jobseekerStmt->close();
}

// Check for redirected messages
if (isset($_GET['resume_success'])) {
    $resumeSuccess = $_GET['resume_success'];
}
if (isset($_GET['resume_error'])) {
    $resumeError = $_GET['resume_error'];
}
if (isset($_GET['clearance_success'])) {
    $clearanceSuccess = $_GET['clearance_success'];
}
if (isset($_GET['clearance_error'])) {
    $clearanceError = $_GET['clearance_error'];
}
if (isset($_GET['photo_success'])) {
    $photoSuccess = $_GET['photo_success'];
}
if (isset($_GET['photo_error'])) {
    $photoError = $_GET['photo_error'];
}
if (isset($_GET['id_success'])) {
    $idSuccess = $_GET['id_success'];
}
if (isset($_GET['id_error'])) {
    $idError = $_GET['id_error'];
}
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}
if (isset($_GET['details_success'])) {
    $detailsSuccess = $_GET['details_success'];
}
if (isset($_GET['details_error'])) {
    $detailsError = $_GET['details_error'];
}
?>

<?php $extraHeadContent = "
    <style>
        #webcam-container {
            width: 320px;
            height: 240px;
            margin-bottom: 10px;
            background-color: #f0f0f0;
            border: 1px solid #ddd;
        }

        #webcam {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        #canvas {
            display: none;
        }
    </style>
        "; ?>

<?php ob_start(); ?>
<!-- Main Content -->
<div class="w-full">
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

    <!-- Profile Details Section -->
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">Profile Details</h2>

        <?php if (!empty($detailsError)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p><?php echo $detailsError; ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($detailsSuccess)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                <p><?php echo $detailsSuccess; ?></p>
            </div>
        <?php endif; ?>

        <form action="settings.php" method="post">
            <div class="mb-4">
                <label for="headline" class="block text-sm font-medium text-gray-700 mb-1">Professional Headline</label>
                <input type="text" id="headline" name="headline" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo isset($roleData['headline']) ? htmlspecialchars($roleData['headline']) : ''; ?>" maxlength="100">
                <p class="text-xs text-gray-500 mt-1">A brief headline that appears under your name (e.g., "Experienced Web Developer" or "Marketing Professional")</p>
            </div>

            <div class="mb-4">
                <label for="location" class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                <input type="text" id="location" name="location" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo isset($roleData['location']) ? htmlspecialchars($roleData['location']) : ''; ?>">
                <p class="text-xs text-gray-500 mt-1">Your city, region, or preferred work location</p>
            </div>

            <div class="mb-4">
                <label for="about" class="block text-sm font-medium text-gray-700 mb-1">About Me</label>
                <textarea id="about" name="about" rows="5" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo isset($roleData['about']) ? htmlspecialchars($roleData['about']) : ''; ?></textarea>
                <p class="text-xs text-gray-500 mt-1">Write a brief summary about yourself, your experience, and your career goals</p>
            </div>

            <div class="mb-4">
                <label for="education" class="block text-sm font-medium text-gray-700 mb-1">Education</label>
                <textarea id="education" name="education" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo isset($roleData['education']) ? htmlspecialchars($roleData['education']) : ''; ?></textarea>
                <p class="text-xs text-gray-500 mt-1">List your educational background, degrees, certifications, etc.</p>
            </div>

            <div class="flex justify-end">
                <button type="submit" name="update_details" class="px-4 py-2 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300">Update Profile Details</button>
            </div>
        </form>
    </div>

    <!-- Skills and Fields Section -->
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">
            <?php if ($user['role'] === 'jobseeker'): ?>
                Skills & Expertise
            <?php elseif ($user['role'] === 'employer'): ?>
                Business Fields
            <?php endif; ?>
        </h2>

        <form action="settings.php" method="post">
            <?php if ($user['role'] === 'jobseeker'): ?>
                <div class="mb-4">
                    <label for="skills" class="block text-sm font-medium text-gray-700 mb-1">Your Skills</label>
                    <p class="text-xs text-gray-500 mb-2">Add your skills separated by commas (e.g., Web Development, Graphic Design, Marketing)</p>
                    <input type="text" id="skills" name="skills" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo isset($roleData['skills']) ? htmlspecialchars($roleData['skills']) : ''; ?>">
                </div>

                <div class="mb-4">
                    <label for="fields" class="block text-sm font-medium text-gray-700 mb-1">Work Fields</label>
                    <p class="text-xs text-gray-500 mb-2">Add your work fields separated by commas (e.g., IT, Healthcare, Education)</p>
                    <input type="text" id="fields" name="fields" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo isset($roleData['fields']) ? htmlspecialchars($roleData['fields']) : ''; ?>">
                </div>

                <div class="mt-6">
                    <button type="submit" name="update_skills_fields" class="px-4 py-2 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300">
                        Update Skills & Fields
                    </button>
                </div>

                <?php if (!empty($roleData['skills']) || !empty($roleData['fields'])): ?>
                    <div class="mt-6 pt-6 border-t border-gray-200">
                        <?php if (!empty($roleData['skills'])): ?>
                            <div class="mb-4">
                                <h3 class="text-sm font-medium text-gray-700 mb-2">Your Skills</h3>
                                <div class="flex flex-wrap">
                                    <?php
                                    $skills = explode(',', $roleData['skills']);
                                    foreach ($skills as $skill): ?>
                                        <span class="inline-block bg-green-100 text-green-800 text-xs px-2 py-1 rounded-full mr-1 mb-1"><?php echo htmlspecialchars(trim($skill)); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($roleData['fields'])): ?>
                            <div>
                                <h3 class="text-sm font-medium text-gray-700 mb-2">Your Work Fields</h3>
                                <div class="flex flex-wrap">
                                    <?php
                                    $fields = explode(',', $roleData['fields']);
                                    foreach ($fields as $field): ?>
                                        <span class="inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full mr-1 mb-1"><?php echo htmlspecialchars(trim($field)); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php elseif ($user['role'] === 'employer'): ?>
                <div class="mb-4">
                    <label for="fields" class="block text-sm font-medium text-gray-700 mb-1">Business Fields</label>
                    <p class="text-xs text-gray-500 mb-2">Add your business fields separated by commas (e.g., Technology, Finance, Retail)</p>
                    <input type="text" id="fields" name="fields" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo isset($roleData['fields']) ? htmlspecialchars($roleData['fields']) : ''; ?>">
                </div>

                <div class="mt-6">
                    <button type="submit" name="update_skills_fields" class="px-4 py-2 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300">
                        Update Business Fields
                    </button>
                </div>

                <?php if (!empty($roleData['fields'])): ?>
                    <div class="mt-6 pt-6 border-t border-gray-200">
                        <h3 class="text-sm font-medium text-gray-700 mb-2">Your Business Fields</h3>
                        <div class="flex flex-wrap">
                            <?php
                            $fields = explode(',', $roleData['fields']);
                            foreach ($fields as $field): ?>
                                <span class="inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full mr-1 mb-1"><?php echo htmlspecialchars(trim($field)); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </form>
    </div>

    <!-- Resume Management Section -->
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">Resume Management</h2>

        <?php if (!empty($resumeError)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                <p><?php echo $resumeError; ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($resumeSuccess)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
                <p><?php echo $resumeSuccess; ?></p>
            </div>
        <?php endif; ?>

        <form action="settings.php" method="post" enctype="multipart/form-data" class="mb-6">
            <div class="mb-4">
                <label for="resume_file" class="block text-sm font-medium text-gray-700 mb-1">Select your resume(s) (PDF, DOC, or DOCX)</label>
                <input type="file" id="resume_file" name="resume_file[]" class="w-full px-3 py-2 border border-gray-300 rounded-md" accept=".pdf,.doc,.docx" required multiple>
                <p class="text-xs text-gray-500 mt-1">You can select multiple files by holding Ctrl (or Cmd on Mac) while selecting</p>
            </div>

            <button type="submit" name="upload_resume" class="px-3 py-1 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300">Upload Resumes</button>
        </form>

        <?php if ($hasResumes): ?>
            <h4 class="text-md font-medium text-gray-700 mb-2">Your Resumes</h4>
            <div class="space-y-4">
                <?php while ($resume = $resumeResult->fetch_assoc()): ?>
                    <div class="border rounded-md p-4 <?php echo $resume['is_default'] ? 'border-blue-300 bg-blue-50' : 'border-gray-200'; ?>">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="flex items-center">
                                    <span class="font-medium text-gray-800"><?php echo htmlspecialchars($resume['original_name']); ?></span>
                                    <?php if ($resume['is_default']): ?>
                                        <span class="ml-2 px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full">Default</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Uploaded on <?php echo date('F j, Y', strtotime($resume['created_at'])); ?></p>
                                <div class="mt-2">
                                    <a href="../../../uploads/jobseeker/<?php echo $_SESSION['user_id']; ?>/documents/resumes/<?php echo $resume['filename']; ?>" target="_blank" class="text-blue-600 hover:underline text-sm">
                                        <i class="fas fa-external-link-alt mr-1"></i> View
                                    </a>
                                </div>
                            </div>
                            <div class="flex space-x-2">
                                <?php if (!$resume['is_default']): ?>
                                    <form action="settings.php" method="post">
                                        <input type="hidden" name="resume_id" value="<?php echo $resume['id']; ?>">
                                        <button type="submit" name="set_default_resume" class="px-2 py-1 bg-green-500 text-white text-xs rounded hover:bg-green-600 transition duration-300">
                                            Set as Default
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form action="settings.php" method="post" onsubmit="return confirm('Are you sure you want to delete this resume?');">
                                    <input type="hidden" name="resume_id" value="<?php echo $resume['id']; ?>">
                                    <button type="submit" name="delete_resume" class="px-2 py-1 bg-red-500 text-white text-xs rounded hover:bg-red-600 transition duration-300">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Police/Barangay Clearance Management Section -->
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">Police/Barangay Clearance Management</h2>

        <?php if (!empty($clearanceError)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                <p><?php echo $clearanceError; ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($clearanceSuccess)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
                <p><?php echo $clearanceSuccess; ?></p>
            </div>
        <?php endif; ?>

        <form action="settings.php" method="post" enctype="multipart/form-data" class="mb-6">
            <div class="mb-4">
                <label for="clearance_file" class="block text-sm font-medium text-gray-700 mb-1">Select your police/barangay clearance(s) (JPG, PNG, or PDF)</label>
                <input type="file" id="clearance_file" name="clearance_file[]" class="w-full px-3 py-2 border border-gray-300 rounded-md" accept=".jpg,.jpeg,.png,.pdf" required multiple>
                <p class="text-xs text-gray-500 mt-1">You can select multiple files by holding Ctrl (or Cmd on Mac) while selecting</p>
            </div>

            <button type="submit" name="upload_clearance" class="px-3 py-1 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300">Upload Clearances</button>
        </form>

        <?php if ($hasClearances): ?>
            <h4 class="text-md font-medium text-gray-700 mb-2">Your Clearances</h4>
            <div class="space-y-4">
                <?php while ($clearance = $clearanceResult->fetch_assoc()): ?>
                    <div class="border rounded-md p-4 <?php echo $clearance['is_default'] ? 'border-blue-300 bg-blue-50' : 'border-gray-200'; ?>">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="flex items-center">
                                    <span class="font-medium text-gray-800">
                                        <?php echo htmlspecialchars($clearance['original_name']); ?>
                                    </span>
                                    <?php if ($clearance['is_default']): ?>
                                        <span class="ml-2 px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full">Default</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Uploaded on <?php echo date('F j, Y', strtotime($clearance['created_at'])); ?></p>
                                <div class="mt-2">
                                    <?php if (strpos($clearance['file_type'], 'image/') !== false): ?>
                                        <a href="../../../uploads/jobseeker/<?php echo $_SESSION['user_id']; ?>/documents/police_clearance/<?php echo $clearance['filename']; ?>" target="_blank" class="text-blue-600 hover:underline text-sm">
                                            <i class="fas fa-external-link-alt mr-1"></i> View Image
                                        </a>
                                    <?php else: ?>
                                        <a href="../../../uploads/jobseeker/<?php echo $_SESSION['user_id']; ?>/documents/police_clearance/<?php echo $clearance['filename']; ?>" target="_blank" class="text-blue-600 hover:underline text-sm">
                                            <i class="fas fa-external-link-alt mr-1"></i> View PDF
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex space-x-2">
                                <?php if (!$clearance['is_default']): ?>
                                    <form action="settings.php" method="post">
                                        <input type="hidden" name="clearance_id" value="<?php echo $clearance['id']; ?>">
                                        <button type="submit" name="set_default_clearance" class="px-2 py-1 bg-green-500 text-white text-xs rounded hover:bg-green-600 transition duration-300">
                                            Set as Default
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form action="settings.php" method="post" onsubmit="return confirm('Are you sure you want to delete this clearance?');">
                                    <input type="hidden" name="clearance_id" value="<?php echo $clearance['id']; ?>">
                                    <button type="submit" name="delete_clearance" class="px-2 py-1 bg-red-500 text-white text-xs rounded hover:bg-red-600 transition duration-300">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Account Verification Section -->
    <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">Account Verification</h2>

        <div class="space-y-6">
            <!-- Photo Upload -->
            <div>
                <h3 class="text-lg font-medium text-gray-800 mb-2">Upload Your Photo</h3>

                <?php if (!empty($photoError)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                        <p><?php echo $photoError; ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($photoSuccess)): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
                        <p><?php echo $photoSuccess; ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($roleData && !empty($roleData['facephoto'])): ?>
                    <div class="mb-4">
                        <p class="text-green-600 font-medium mb-2"><i class="fas fa-check-circle mr-1"></i> Photo uploaded</p>
                        <img src="<?php echo htmlspecialchars($roleData['facephoto']); ?>" alt="Your photo" class="w-32 h-32 object-cover rounded-md border border-gray-300">
                    </div>
                    
                    <?php if ($user['status'] === 'suspended'): ?>
                    <div class="mt-4">
                        <p class="text-red-600 mb-2"><i class="fas fa-exclamation-triangle mr-1"></i> Your account has been suspended. Please upload a new photo.</p>
                        <form action="settings.php" method="post" id="webcam-form">
                            <div class="mb-4">
                                <div id="webcam-container" class="rounded-md overflow-hidden">
                                    <video id="webcam" autoplay playsinline></video>
                                    <canvas id="canvas"></canvas>
                                </div>
                                <input type="hidden" name="webcam_image" id="webcam_image">
                            </div>

                            <div class="flex space-x-2">
                                <button type="button" id="start-camera" class="px-3 py-1 bg-gray-500 text-white font-medium rounded-md hover:bg-gray-600 transition duration-300">Start Camera</button>
                                <button type="button" id="capture-photo" class="px-3 py-1 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300" disabled>Capture Photo</button>
                                <button type="submit" name="upload_photo" id="upload-photo" class="px-3 py-1 bg-green-500 text-white font-medium rounded-md hover:bg-green-600 transition duration-300" disabled>Upload Photo</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <form action="settings.php" method="post" id="webcam-form">
                        <div class="mb-4">
                            <div id="webcam-container" class="rounded-md overflow-hidden">
                                <video id="webcam" autoplay playsinline></video>
                                <canvas id="canvas"></canvas>
                            </div>
                            <input type="hidden" name="webcam_image" id="webcam_image">
                        </div>

                        <div class="flex space-x-2">
                            <button type="button" id="start-camera" class="px-3 py-1 bg-gray-500 text-white font-medium rounded-md hover:bg-gray-600 transition duration-300">Start Camera</button>
                            <button type="button" id="capture-photo" class="px-3 py-1 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300" disabled>Capture Photo</button>
                            <button type="submit" name="upload_photo" id="upload-photo" class="px-3 py-1 bg-green-500 text-white font-medium rounded-md hover:bg-green-600 transition duration-300" disabled>Upload Photo</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- ID Upload -->
            <div>
                <h3 class="text-lg font-medium text-gray-800 mb-2">Upload Valid ID</h3>

                <?php if (!empty($idError)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                        <p><?php echo $idError; ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($idSuccess)): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
                        <p><?php echo $idSuccess; ?></p>
                    </div>
                <?php endif; ?>

                <?php if ($roleData && !empty($roleData['valid_id'])): ?>
                    <div class="mb-4">
                        <p class="text-green-600 font-medium mb-2"><i class="fas fa-check-circle mr-1"></i> ID uploaded</p>
                        <?php if (pathinfo($roleData['valid_id'], PATHINFO_EXTENSION) === 'pdf'): ?>
                            <a href="<?php echo htmlspecialchars($roleData['valid_id']); ?>" target="_blank" class="text-blue-600 hover:underline">View uploaded PDF</a>
                        <?php else: ?>
                            <img src="<?php echo htmlspecialchars($roleData['valid_id']); ?>" alt="Your ID" class="w-64 object-cover rounded-md border border-gray-300">
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($user['status'] === 'suspended'): ?>
                    <div class="mt-4">
                        <p class="text-red-600 mb-2"><i class="fas fa-exclamation-triangle mr-1"></i> Your account has been suspended. Please upload a new valid ID.</p>
                        <form action="settings.php" method="post" enctype="multipart/form-data">
                            <div class="mb-4">
                                <label for="id_file" class="block text-sm font-medium text-gray-700 mb-1">Select a valid ID (JPG, PNG, or PDF)</label>
                                <input type="file" id="id_file" name="id_file" class="w-full px-3 py-2 border border-gray-300 rounded-md" accept=".jpg,.jpeg,.png,.pdf" required>
                                <p class="text-xs text-gray-500 mt-1">Upload a clear image of your government-issued ID</p>
                            </div>

                            <button type="submit" name="upload_id" class="px-3 py-1 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300">Upload ID</button>
                        </form>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <form action="settings.php" method="post" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label for="id_file" class="block text-sm font-medium text-gray-700 mb-1">Select a valid ID (JPG, PNG, or PDF)</label>
                            <input type="file" id="id_file" name="id_file" class="w-full px-3 py-2 border border-gray-300 rounded-md" accept=".jpg,.jpeg,.png,.pdf" required>
                            <p class="text-xs text-gray-500 mt-1">Upload a clear image of your government-issued ID</p>
                        </div>

                        <button type="submit" name="upload_id" class="px-3 py-1 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300">Upload ID</button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Account Status Section -->
            <?php
            if ($user['status'] !== 'active' && $roleData && !empty($roleData['facephoto']) && !empty($roleData['valid_id']) && $user['status'] !== 'under_review' && $user['status'] !== 'suspended' && $user['status'] !== 'rejected'): ?>
                <div class="mt-6 pt-6 border-t border-gray-200" id="request-approval">
                    <h3 class="text-lg font-medium text-gray-800 mb-2">Request Account Approval</h3>
                    <p class="text-gray-600 mb-4">You have uploaded all required documents. Submit your account for approval.</p>

                    <form action="settings.php" method="post">
                        <button type="submit" name="request_approval" class="px-4 py-2 bg-green-500 text-white font-medium rounded-md hover:bg-green-600 transition duration-300">Submit for Approval</button>
                    </form>
                </div>
            <?php elseif ($user['status'] === 'under_review'): ?>
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-clock text-yellow-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800">Under Review</h3>
                                <p class="text-sm text-yellow-700 mt-1">
                                    Your account is currently under review. An administrator will verify your documents shortly.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($user['status'] === 'active'): ?>
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="bg-green-50 border-l-4 border-green-400 p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-check-circle text-green-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-green-800">Verified Account</h3>
                                <p class="text-sm text-green-700 mt-1">
                                    Your account has been verified and is active. You have full access to all features.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($user['status'] === 'rejected'): ?>
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="bg-red-50 border-l-4 border-red-400 p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-circle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">Account Rejected</h3>
                                <p class="text-sm text-red-700 mt-1">
                                    Your account verification was rejected. Please check your email for more information about why your verification was rejected.
                                </p>
                                <p class="text-sm text-red-700 mt-2">
                                    To reactivate your account, please upload new verification documents above and submit for approval again.
                                </p>
                                <?php if ($roleData && !empty($roleData['facephoto']) && !empty($roleData['valid_id'])): ?>
                                <div class="mt-4">
                                    <form action="settings.php" method="post">
                                        <button type="submit" name="request_approval" class="px-4 py-2 bg-red-500 text-white font-medium rounded-md hover:bg-red-600 transition duration-300">Resubmit for Approval</button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($user['status'] === 'suspended'): ?>
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="bg-red-50 border-l-4 border-red-400 p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-ban text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">Account Suspended</h3>
                                <p class="text-sm text-red-700 mt-1">
                                    Your account has been suspended by an administrator. This action cannot be reversed through this interface.
                                </p>
                                <p class="text-sm text-red-700 mt-2">
                                    If you believe this was done in error, please contact our support team for assistance.
                                </p>
                                <div class="mt-4">
                                    <a href="../../../pages/contact.php" class="px-4 py-2 bg-blue-500 text-white font-medium rounded-md hover:bg-blue-600 transition duration-300 inline-block">
                                        Contact Support
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <script>
                    // Show a notification about account suspension when the page loads
                    document.addEventListener('DOMContentLoaded', function() {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                title: 'Account Suspended',
                                text: 'Your account has been suspended by an administrator. Please contact support for assistance.',
                                icon: 'error',
                                confirmButtonText: 'Contact Support',
                                showCancelButton: true,
                                cancelButtonText: 'Close'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    window.location.href = '../../../pages/contact.php';
                                }
                            });
                            
                            // Automatically log out after showing the message
                            setTimeout(function() {
                                window.location.href = '../../../pages/logout.php';
                            }, 10000); // 10 seconds delay
                        }
                    });
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
$extraScripts = "
        // Webcam functionality
        document.addEventListener('DOMContentLoaded', function() {
            const webcamElement = document.getElementById('webcam');
            const canvasElement = document.getElementById('canvas');
            const startCameraButton = document.getElementById('start-camera');
            const capturePhotoButton = document.getElementById('capture-photo');
            const uploadPhotoButton = document.getElementById('upload-photo');
            const webcamForm = document.getElementById('webcam-form');
            const webcamImageInput = document.getElementById('webcam_image');

            let stream = null;

            // Start camera
            startCameraButton.addEventListener('click', function() {
                if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                    navigator.mediaDevices.getUserMedia({
                            video: true
                        })
                        .then(function(mediaStream) {
                            stream = mediaStream;
                            webcamElement.srcObject = mediaStream;
                            webcamElement.play();
                            startCameraButton.disabled = true;
                            capturePhotoButton.disabled = false;
                        })
                        .catch(function(error) {
                            console.error('Error accessing webcam:', error);
                            alert('Unable to access webcam. Please make sure you have a webcam connected and have granted permission to use it.');
                        });
                } else {
                    alert('Your browser does not support webcam access.');
                }
            });

            // Capture photo
            capturePhotoButton.addEventListener('click', function() {
                const context = canvasElement.getContext('2d');
                canvasElement.width = webcamElement.videoWidth;
                canvasElement.height = webcamElement.videoHeight;
                context.drawImage(webcamElement, 0, 0, webcamElement.videoWidth, webcamElement.videoHeight);

                // Convert to base64
                const imageDataURL = canvasElement.toDataURL('image/png');
                webcamImageInput.value = imageDataURL;

                // Stop camera
                if (stream) {
                    stream.getTracks().forEach(track => track.stop());
                    webcamElement.srcObject = null;
                }

                // Show captured image
                webcamElement.style.display = 'none';
                canvasElement.style.display = 'block';

                // Enable upload button
                uploadPhotoButton.disabled = false;
                capturePhotoButton.disabled = true;
                startCameraButton.disabled = false;
                startCameraButton.textContent = 'Retake Photo';
            });
        });
    "; ?>
<?php
$conn->close();
include 'nav/layout.php';
if (isset($_SESSION['warning'])) {
    echo "<script>
        Swal.fire({
            title: 'Warning',
            text: '" . $_SESSION['warning'] . "',
            icon: 'warning'
        });
    </script>";
    unset($_SESSION['warning']);
}
?>