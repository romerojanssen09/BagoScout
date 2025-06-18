<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root'); // Change this to your MySQL username
define('DB_PASS', '');     // Change this to your MySQL password
define('DB_NAME', 'bagoscout');

// Create database connection function
function getDbConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}

// Create database and tables if they don't exist
function initDatabase() {
    // Connect to MySQL server without selecting a database
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Create database if it doesn't exist
    $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
    if ($conn->query($sql) !== TRUE) {
        die("Error creating database: " . $conn->error);
    }
    
    // Select the database
    $conn->select_db(DB_NAME);
    
    // Create users table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(255) NOT NULL,
        last_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        phone VARCHAR(255) NOT NULL,
        birthdate VARCHAR(255) NOT NULL,
        gender ENUM('male', 'female', 'prefer not to say') NOT NULL DEFAULT 'prefer not to say',
        address VARCHAR(255) NOT NULL,
        token VARCHAR(255) DEFAULT NULL,
        token_expires DATETIME DEFAULT NULL,
        is_verified TINYINT(1) NOT NULL DEFAULT 0,
        role ENUM('admin', 'jobseeker', 'employer') NOT NULL DEFAULT 'jobseeker',
        status ENUM('unverified', 'under_review', 'rejected', 'active', 'suspended', 'deleted') NOT NULL DEFAULT 'unverified',
        profile VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_login DATETIME NOT NULL
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating users table: " . $conn->error);
    }

    // Create table for jobseekers
    $sql = "CREATE TABLE IF NOT EXISTS jobseekers (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT(11) UNSIGNED NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id),
        fields VARCHAR(255) NOT NULL,
        skills VARCHAR(255) NOT NULL,
        facephoto VARCHAR(255) NULL,
        valid_id VARCHAR(255) NULL,
        resume VARCHAR(255) NULL,
        police_clearance VARCHAR(255) NULL,
        about TEXT NULL,
        location VARCHAR(255) NULL,
        education TEXT NULL,
        headline VARCHAR(100) NULL
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating jobseekers table: " . $conn->error);
    }

    // Create table for jobseeker resumes
    $sql = "CREATE TABLE IF NOT EXISTS jobseeker_resumes (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        jobseeker_id INT(11) UNSIGNED NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_type VARCHAR(100) NOT NULL,
        file_size INT UNSIGNED NOT NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (jobseeker_id) REFERENCES jobseekers(id) ON DELETE CASCADE
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating jobseeker_resumes table: " . $conn->error);
    }

    // Create table for jobseeker clearances
    $sql = "CREATE TABLE IF NOT EXISTS jobseeker_clearances (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        jobseeker_id INT(11) UNSIGNED NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_type VARCHAR(100) NOT NULL,
        file_size INT UNSIGNED NOT NULL,
        clearance_type VARCHAR(50) DEFAULT 'police',
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (jobseeker_id) REFERENCES jobseekers(id) ON DELETE CASCADE
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating jobseeker_clearances table: " . $conn->error);
    }

    // Create table for employers
    $sql = "CREATE TABLE IF NOT EXISTS employers (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT(11) UNSIGNED NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id),
        fields VARCHAR(255) NOT NULL,
        facephoto VARCHAR(255) NULL,
        valid_id VARCHAR(255) NULL,
        company_type VARCHAR(255) NOT NULL,
        company_name VARCHAR(255) NOT NULL,
        role_in_company VARCHAR(255) NOT NULL,
        company_url VARCHAR(255) NULL,
        location VARCHAR(255) NULL,
        company_description TEXT NULL,
        company_size VARCHAR(50) NULL,
        established VARCHAR(50) NULL,
        address TEXT NULL,
        logo VARCHAR(255) NULL,
        industry VARCHAR(100) NULL,
        website VARCHAR(255) NULL
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating employers table: " . $conn->error);
    }
    
    // Create table for jobs
    $sql = "CREATE TABLE IF NOT EXISTS jobs (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        employer_id INT(11) UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        requirements TEXT NOT NULL,
        location VARCHAR(255) NOT NULL,
        latitude DECIMAL(10, 8) NULL,
        longitude DECIMAL(11, 8) NULL,
        prk VARCHAR(255) NULL,
        barangay VARCHAR(255) NULL,
        city VARCHAR(255) NULL,
        province VARCHAR(255) NULL,
        job_type VARCHAR(50) NOT NULL,
        pay_type ENUM('hourly', 'monthly', 'annual') NOT NULL DEFAULT 'monthly',
        salary_min DECIMAL(12, 2) NULL,
        salary_max DECIMAL(12, 2) NULL,
        fields VARCHAR(255) NULL,
        deadline DATE NULL,
        response_time ENUM('within_hour', 'within_day', 'within_week') NOT NULL DEFAULT 'within_day',
        required_documents SET('resume', 'police_clearance', 'none') NOT NULL DEFAULT 'none',
        status ENUM('active', 'paused', 'closed') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employer_id) REFERENCES employers(id)
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating jobs table: " . $conn->error);
    }
    
    // Create table for job applications
    $sql = "CREATE TABLE IF NOT EXISTS applications (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_id INT(11) UNSIGNED NOT NULL,
        jobseeker_id INT(11) UNSIGNED NOT NULL,
        resume VARCHAR(255) NULL,
        police_clearance VARCHAR(255) NULL,
        cover_letter TEXT NULL,
        status ENUM('pending', 'reviewed', 'shortlisted', 'rejected', 'hired') NOT NULL DEFAULT 'pending',
        employer_notes TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (job_id) REFERENCES jobs(id),
        FOREIGN KEY (jobseeker_id) REFERENCES jobseekers(id)
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating applications table: " . $conn->error);
    }
    
    // Create table for application logs
    $sql = "CREATE TABLE IF NOT EXISTS application_logs (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        application_id INT(11) UNSIGNED NOT NULL,
        log_type ENUM('status_change', 'employer_review', 'message') NOT NULL,
        message TEXT NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (application_id) REFERENCES applications(id)
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating application_logs table: " . $conn->error);
    }
    
    // Create table for admins
    $sql = "CREATE TABLE IF NOT EXISTS admins (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(255) NOT NULL,
        last_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        phone VARCHAR(255) NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_login DATETIME NOT NULL
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating admins table: " . $conn->error);
    }

    // Create table for contact_messages
    $sql = "CREATE TABLE IF NOT EXISTS contact_messages (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        status ENUM('unread', 'read') NOT NULL DEFAULT 'unread',
        created_at DATETIME NOT NULL
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating contact_messages table: " . $conn->error);
    }

    // Create table for chat messages
    $sql = "CREATE TABLE IF NOT EXISTS messages (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversation_id VARCHAR(255) NOT NULL,
        sender_id INT(11) UNSIGNED NOT NULL,
        receiver_id INT(11) UNSIGNED NOT NULL,
        message TEXT,
        attachment VARCHAR(255) NULL,
        attachment_type VARCHAR(50) NULL,
        attachment_name VARCHAR(255) NULL,
        file_path VARCHAR(255) NULL,
        file_name VARCHAR(255) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        is_edited TINYINT(1) NOT NULL DEFAULT 0,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (sender_id) REFERENCES users(id),
        FOREIGN KEY (receiver_id) REFERENCES users(id),
        INDEX (sender_id, receiver_id),
        INDEX (is_system)
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating messages table: " . $conn->error);
    }

    // Create table for conversations
    $sql = "CREATE TABLE IF NOT EXISTS conversations (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user1_id INT(11) UNSIGNED NOT NULL,
        user2_id INT(11) UNSIGNED NOT NULL,
        last_message_id INT(11) UNSIGNED NULL,
        updated_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (user1_id) REFERENCES users(id),
        FOREIGN KEY (user2_id) REFERENCES users(id),
        INDEX (user1_id),
        INDEX (user2_id)
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating conversations table: " . $conn->error);
    }

    // Create table for calls
    $sql = "CREATE TABLE IF NOT EXISTS calls (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        call_id VARCHAR(64) NOT NULL UNIQUE,
        conversation_id INT(11) UNSIGNED NOT NULL,
        initiator_id INT(11) UNSIGNED NOT NULL,
        recipient_id INT(11) UNSIGNED NOT NULL,
        call_type ENUM('audio', 'video') NOT NULL,
        status ENUM('initiated', 'ringing', 'accepted', 'rejected', 'ended', 'missed') NOT NULL DEFAULT 'initiated',
        duration INT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME DEFAULT NULL,
        FOREIGN KEY (initiator_id) REFERENCES users(id),
        FOREIGN KEY (recipient_id) REFERENCES users(id),
        FOREIGN KEY (conversation_id) REFERENCES conversations(id),
        INDEX (initiator_id),
        INDEX (recipient_id),
        INDEX (created_at),
        INDEX (status)
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating calls table: " . $conn->error);
    }
    
    // Create table for job comments
    $sql = "CREATE TABLE IF NOT EXISTS job_comments (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_id INT(11) UNSIGNED NOT NULL,
        user_id INT(11) UNSIGNED NOT NULL,
        comment TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating job_comments table: " . $conn->error);
    }

    // Create table for comment replies
    $sql = "CREATE TABLE IF NOT EXISTS comment_replies (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        comment_id INT(11) UNSIGNED NOT NULL,
        user_id INT(11) UNSIGNED NOT NULL,
        reply TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (comment_id) REFERENCES job_comments(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating comment_replies table: " . $conn->error);
    }

    // Create table for notifications
    $sql = "CREATE TABLE IF NOT EXISTS notifications (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT(11) UNSIGNED NOT NULL,
        type ENUM('message', 'application', 'comment', 'job', 'status_change') NOT NULL,
        content TEXT NOT NULL,
        reference_id INT(11) UNSIGNED NULL,
        reference_type VARCHAR(50) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    
    if ($conn->query($sql) !== TRUE) {
        die("Error creating notifications table: " . $conn->error);
    }

    $conn->close();
}
// Initialize database
initDatabase(); 