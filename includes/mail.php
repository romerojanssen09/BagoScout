<?php
/**
 * Email functionality for BagoScout application
 * Using PHPMailer for sending emails
 */

// Include PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Email configuration
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', 'bagoscout@gmail.com');
define('MAIL_PASSWORD', 'llxk yqyi pdsc aruc'); // App password from Gmail
define('MAIL_FROM_EMAIL', 'bagoscout@gmail.com');
define('MAIL_FROM_NAME', 'BagoScout');
define('MAIL_ENCRYPTION', 'tls');

/**
 * Send email using PHPMailer
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body Email body (can be plain text or HTML)
 * @param bool $isHtml Whether the body is HTML
 * @param array $attachments Optional attachments
 * @return bool True if email was sent, false otherwise
 */
function sendEmail($to, $subject, $body, $isHtml = false, $attachments = []) {
    // Check if PHPMailer is installed
    if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
        return sendEmailFallback($to, $subject, $body, $isHtml);
    }
    
    // Include Composer's autoloader
    require_once __DIR__ . '/../vendor/autoload.php';
    
    // Create a new PHPMailer instance
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->SMTPDebug = SMTP::DEBUG_OFF; // Disable debug output
        $mail->isSMTP(); // Send using SMTP
        $mail->Host = MAIL_HOST; // SMTP server
        $mail->SMTPAuth = true; // Enable SMTP authentication
        $mail->Username = MAIL_USERNAME; // SMTP username
        $mail->Password = MAIL_PASSWORD; // SMTP password
        $mail->SMTPSecure = MAIL_ENCRYPTION; // Enable TLS encryption
        $mail->Port = MAIL_PORT; // TCP port to connect to
        
        // Recipients
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($to); // Add a recipient
        
        // Content
        $mail->isHTML($isHtml); // Set email format to HTML
        $mail->Subject = $subject;
        
        if ($isHtml) {
            $mail->Body = $body; // HTML body
            $mail->AltBody = strip_tags($body); // Plain text alternative
        } else {
            $mail->Body = nl2br($body); // Convert newlines to <br> for HTML
            $mail->AltBody = strip_tags($body); // Plain text version
        }
        
        // Attachments
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                $mail->addAttachment($attachment);
            }
        }
        
        // Send the email
        $mail->send();
        
        return true;
    } catch (Exception $e) {
        return sendEmailFallback($to, $subject, $body, $isHtml);
    }
}

/**
 * Fallback function to send email using PHP's mail() function
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body Email body (can be plain text or HTML)
 * @param bool $isHtml Whether the body is HTML
 * @return bool True if email was sent, false otherwise
 */
function sendEmailFallback($to, $subject, $body, $isHtml = false) {
    // Headers
    $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . MAIL_FROM_EMAIL . "\r\n";
    
    if ($isHtml) {
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    } else {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    }
    
    // Actually send the email
    return mail($to, $subject, $body, $headers);
}

/**
 * Send a verification email to a user with HTML formatting
 * 
 * @param string $email User email
 * @param string $name User name
 * @param string $token Verification token
 * @return bool True if email was sent, false otherwise
 */
function sendVerificationEmail($email, $firstName, $lastName, $token) {
    $subject = "Verify your email address";
    
    // HTML email template with CSS
    $htmlBody = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Email Verification</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333333;
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
            }
            .container {
                background-color: #f9f9f9;
                border-radius: 5px;
                padding: 20px;
                border: 1px solid #dddddd;
            }
            .header {
                text-align: center;
                padding-bottom: 15px;
                border-bottom: 2px solid #3498db;
                margin-bottom: 20px;
            }
            .header h1 {
                color: #2c3e50;
                margin: 0;
                font-size: 24px;
            }
            .content {
                padding: 20px 0;
            }
            .verification-code {
                text-align: center;
                font-size: 32px;
                letter-spacing: 5px;
                font-weight: bold;
                color: #3498db;
                margin: 30px 0;
                padding: 15px;
                background-color: #eef7ff;
                border-radius: 5px;
                border: 1px dashed #3498db;
            }
            .footer {
                margin-top: 20px;
                padding-top: 15px;
                border-top: 1px solid #dddddd;
                text-align: center;
                font-size: 12px;
                color: #777777;
            }
            .note {
                font-size: 14px;
                color: #666666;
                font-style: italic;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>BagoScout</h1>
            </div>
            <div class="content">
                <p>Hi ' . htmlspecialchars($firstName) . ' ' . htmlspecialchars($lastName) . ',</p>
                <p>Thank you for registering with BagoScout. Please use the verification code below to verify your email address:</p>
                <div class="verification-code">' . $token . '</div>
                <p>Enter this code on the verification page to continue with your registration.</p>
                <p class="note">If you did not register for an account, please ignore this email.</p>
            </div>
            <div class="footer">
                <p>&copy; ' . date('Y') . ' BagoScout. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmail($email, $subject, $htmlBody, true);
}

/**
 * Send a password reset email to a user with HTML formatting
 * 
 * @param string $email User email
 * @param string $name User name
 * @param string $token Reset token
 * @return bool True if email was sent, false otherwise
 */
function sendPasswordResetEmail($email, $name, $token) {
    $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/reset-password.php?token=" . $token;
    $subject = "Reset your password";
    
    // HTML email template with CSS
    $htmlBody = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Password Reset</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333333;
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
            }
            .container {
                background-color: #f9f9f9;
                border-radius: 5px;
                padding: 20px;
                border: 1px solid #dddddd;
            }
            .header {
                text-align: center;
                padding-bottom: 15px;
                border-bottom: 2px solid #3498db;
                margin-bottom: 20px;
            }
            .header h1 {
                color: #2c3e50;
                margin: 0;
                font-size: 24px;
            }
            .content {
                padding: 20px 0;
            }
            .button {
                display: inline-block;
                background-color: #3498db;
                color: #ffffff !important;
                text-decoration: none;
                padding: 10px 20px;
                border-radius: 4px;
                margin: 20px 0;
                font-weight: bold;
            }
            .footer {
                margin-top: 20px;
                padding-top: 15px;
                border-top: 1px solid #dddddd;
                text-align: center;
                font-size: 12px;
                color: #777777;
            }
            .note {
                font-size: 14px;
                color: #666666;
                font-style: italic;
            }
            .expiry {
                background-color: #fff3cd;
                color: #856404;
                padding: 10px;
                border-radius: 4px;
                margin: 15px 0;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>BagoScout</h1>
            </div>
            <div class="content">
                <p>Hi ' . htmlspecialchars($name) . ',</p>
                <p>You have requested to reset your password. Please click the button below to set a new password:</p>
                <p style="text-align: center;">
                    <a href="' . $reset_link . '" class="button">Reset Password</a>
                </p>
                <div class="expiry">
                    <p><strong>Important:</strong> This link will expire in 1 hour.</p>
                </div>
                <p>If the button doesn\'t work, copy and paste the following link into your browser:</p>
                <p style="word-break: break-all;">' . $reset_link . '</p>
                <p class="note">If you did not request a password reset, please ignore this email.</p>
            </div>
            <div class="footer">
                <p>&copy; ' . date('Y') . ' BagoScout. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmail($email, $subject, $htmlBody, true);
}

/**
 * Send a general notification email with HTML formatting
 * 
 * @param string $email Recipient email
 * @param string $name Recipient name
 * @param string $subject Email subject
 * @param string $message Email message content
 * @param string $buttonText Optional button text
 * @param string $buttonUrl Optional button URL
 * @return bool True if email was sent, false otherwise
 */
function sendNotificationEmail($email, $name, $subject, $message, $buttonText = '', $buttonUrl = '') {
    // HTML email template with CSS
    $htmlBody = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($subject) . '</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333333;
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
            }
            .container {
                background-color: #f9f9f9;
                border-radius: 5px;
                padding: 20px;
                border: 1px solid #dddddd;
            }
            .header {
                text-align: center;
                padding-bottom: 15px;
                border-bottom: 2px solid #3498db;
                margin-bottom: 20px;
            }
            .header h1 {
                color: #2c3e50;
                margin: 0;
                font-size: 24px;
            }
            .content {
                padding: 20px 0;
            }
            .button {
                display: inline-block;
                background-color: #3498db;
                color: #ffffff !important;
                text-decoration: none;
                padding: 10px 20px;
                border-radius: 4px;
                margin: 20px 0;
                font-weight: bold;
            }
            .footer {
                margin-top: 20px;
                padding-top: 15px;
                border-top: 1px solid #dddddd;
                text-align: center;
                font-size: 12px;
                color: #777777;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>BagoScout</h1>
            </div>
            <div class="content">
                <p>Hi ' . htmlspecialchars($name) . ',</p>
                ' . $message . '
                ' . (!empty($buttonText) && !empty($buttonUrl) ? '
                <p style="text-align: center;">
                    <a href="' . $buttonUrl . '" class="button">' . htmlspecialchars($buttonText) . '</a>
                </p>' : '') . '
            </div>
            <div class="footer">
                <p>&copy; ' . date('Y') . ' BagoScout. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>';
    
    return sendEmail($email, $subject, $htmlBody, true);
} 