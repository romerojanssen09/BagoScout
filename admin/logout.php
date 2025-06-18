<?php
session_start();

// Clear admin session variables
unset($_SESSION['admin_id']);
unset($_SESSION['admin_name']);
unset($_SESSION['admin_role']);

// Redirect to admin login page
header("Location: ../admin-login.php");
exit();
?>