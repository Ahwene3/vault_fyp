<?php
/**
 * Email helper functions - FYP Vault
 * Uses PHP's mail() function, with file logging as fallback for development
 */

// Email configuration
define('SITE_EMAIL', 'noreply@vault.edu'); // Sender email
define('SITE_NAME', 'FYP Vault');
define('EMAIL_LOG_FILE', __DIR__ . '/../logs/emails.log'); // Log file for development

// Create logs directory if it doesn't exist
if (!is_dir(__DIR__ . '/../logs')) {
    @mkdir(__DIR__ . '/../logs', 0755, true);
}

/**
 * Send email using PHP's mail() function
 * Falls back to file logging if mail() is not available
 */
function send_email(string $to, string $subject, string $body, string $from = SITE_EMAIL): bool {
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid email recipient: $to");
        return false;
    }

    // Email headers
    $headers = [
        'From: ' . SITE_NAME . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'Content-Type: text/html; charset=UTF-8',
        'X-Mailer: FYP Vault/1.0',
    ];

    $headers_str = implode("\r\n", $headers);

    // Try to send email
    $result = @mail($to, $subject, $body, $headers_str);

    if ($result) {
        error_log("Email sent to: $to, Subject: $subject");
        log_email($to, $subject, $body, $from, true);
    } else {
        error_log("Failed to send email to: $to, Subject: $subject (using fallback log)");
        // Log to file for development/debugging
        log_email($to, $subject, $body, $from, false);
        $result = true; // Consider it successful for logging purposes
    }

    return $result;
}

/**
 * Log email to file for development/debugging
 */
function log_email(string $to, string $subject, string $body, string $from, bool $sent = true): void {
    $timestamp = date('Y-m-d H:i:s');
    $status = $sent ? 'SENT' : 'LOGGED (no mail service)';
    $log_entry = "================================================================================\n";
    $log_entry .= "Timestamp: $timestamp\n";
    $log_entry .= "Status: $status\n";
    $log_entry .= "From: $from\n";
    $log_entry .= "To: $to\n";
    $log_entry .= "Subject: $subject\n";
    $log_entry .= "Body:\n$body\n\n";

    @file_put_contents(EMAIL_LOG_FILE, $log_entry, FILE_APPEND);
}

/**
 * Send HTML email with template
 */
function send_template_email(
    string $to,
    string $subject,
    string $title,
    string $content,
    ?string $cta_text = null,
    ?string $cta_url = null
): bool {
    $html = get_email_template($title, $content, $cta_text, $cta_url);
    return send_email($to, $subject, $html);
}

/**
 * Get HTML email template
 */
function get_email_template(
    string $title,
    string $content,
    ?string $cta_text = null,
    ?string $cta_url = null
): string {
    $cta_button = '';
    if ($cta_text && $cta_url) {
        $cta_button = "<div style=\"margin-top: 30px; margin-bottom: 20px;\">";
        $cta_button .= "<a href=\"$cta_url\" style=\"display: inline-block; padding: 12px 24px; background-color: #6366f1; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;\">";
        $cta_button .= "$cta_text</a></div>";
    }

    $html = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"><title>$title</title>";
    $html .= "<style>";
    $html .= "body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f4f6; margin: 0; padding: 0; }";
    $html .= ".container { max-width: 600px; margin: 0 auto; background: white; padding: 40px 20px; }";
    $html .= ".header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #6366f1; padding-bottom: 20px; }";
    $html .= ".header h1 { color: #0f172a; margin: 0; font-size: 24px; }";
    $html .= ".logo { font-size: 18px; font-weight: 700; color: #6366f1; margin-bottom: 10px; }";
    $html .= ".content { color: #1f2937; line-height: 1.6; margin: 20px 0; }";
    $html .= ".content p { margin: 0 0 15px 0; }";
    $html .= ".footer { text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 12px; }";
    $html .= ".footer a { color: #6366f1; text-decoration: none; }";
    $html .= "</style></head><body>";
    $html .= "<div class=\"container\"><div class=\"header\"><div class=\"logo\">FYP Vault</div><h1>$title</h1></div>";
    $html .= "<div class=\"content\">$content</div>";
    $html .= $cta_button;
    $html .= "<div class=\"footer\"><p>© 2026 FYP Vault. All rights reserved.</p>";
    $html .= "<p>This is an automated email. Please do not reply directly to this message.</p>";
    $html .= "<p><a href=\"mailto:support@vault.edu\">Contact Support</a></p></div></div></body></html>";

    return $html;
}

/**
 * Send registration confirmation email
 */
function send_registration_email(string $email, string $name): bool {
    $title = 'Welcome to FYP Vault';
    $content = "<p>Hello $name,</p>";
    $content .= "<p>Your account has been successfully created! You can now log in to submit your final year project.</p>";
    $content .= "<p><strong>Your Email:</strong> $email</p>";
    $content .= "<p>Please keep your credentials secure and do not share your password with anyone.</p>";
    $content .= "<p>If you did not create this account, please contact support immediately.</p>";

    $cta_url = get_app_url('index.php');
    return send_template_email($email, 'Welcome to FYP Vault', $title, $content, 'Sign In Now', $cta_url);
}

/**
 * Send topic approval notification
 */
function send_topic_approval_email(string $email, string $student_name, string $topic_title): bool {
    $title = 'Topic Approved!';
    $content = "<p>Hi $student_name,</p>";
    $content .= "<p>Great news! Your project topic has been approved:</p>";
    $content .= "<p><strong>$topic_title</strong></p>";
    $content .= "<p>A supervisor will be assigned to guide you through the project. You'll receive a notification soon.</p>";

    $cta_url = get_app_url('student/project.php');
    return send_template_email($email, 'Your Topic Has Been Approved', $title, $content, 'View Project', $cta_url);
}

/**
 * Send topic rejection notification
 */
function send_topic_rejection_email(string $email, string $student_name, string $topic_title, string $reason): bool {
    $title = 'Topic Requires Revision';
    $content = "<p>Hi $student_name,</p>";
    $content .= "<p>Your project topic submission needs revision:</p>";
    $content .= "<p><strong>$topic_title</strong></p>";
    $content .= "<p><strong>Feedback:</strong></p>";
    $content .= "<p>" . nl2br(htmlspecialchars($reason)) . "</p>";
    $content .= "<p>Please update your topic and resubmit.</p>";

    $cta_url = get_app_url('student/project.php');
    return send_template_email($email, 'Topic Needs Revision', $title, $content, 'Update Topic', $cta_url);
}

/**
 * Send supervisor assignment notification
 */
function send_supervisor_assignment_email(string $student_email, string $student_name, string $supervisor_name, string $supervisor_email): bool {
    $title = 'Supervisor Assigned';
    $content = "<p>Hi $student_name,</p>";
    $content .= "<p>A supervisor has been assigned to your project:</p>";
    $content .= "<p><strong>Supervisor:</strong> $supervisor_name</p>";
    $content .= "<p><strong>Email:</strong> $supervisor_email</p>";
    $content .= "<p>You can now start uploading documents and communicating with your supervisor through the platform.</p>";

    $cta_url = get_app_url('student/project.php');
    return send_template_email($student_email, 'Supervisor Assigned', $title, $content, 'View Project', $cta_url);
}

/**
 * Send message notification
 */
function send_message_notification_email(string $email, string $recipient_name, string $sender_name, string $message_preview): bool {
    $title = 'New Message';
    $content = "<p>Hi $recipient_name,</p>";
    $content .= "<p>You have received a new message from <strong>$sender_name</strong>:</p>";
    $content .= "<blockquote style=\"border-left: 4px solid #6366f1; padding-left: 15px; margin: 15px 0; color: #6b7280;\">";
    $content .= "<p>" . nl2br(htmlspecialchars(mb_substr($message_preview, 0, 200))) . "...</p>";
    $content .= "</blockquote>";
    $content .= "<p>Log in to the platform to read the full message and reply.</p>";

    $cta_url = get_app_url('messages.php');
    return send_template_email($email, 'New Message: ' . $sender_name, $title, $content, 'View Message', $cta_url);
}

/**
 * Send assessment notification
 */
function send_assessment_notification_email(string $email, string $student_name, string $assessment_type, int $score): bool {
    $title = 'New Assessment';
    $content = "<p>Hi $student_name,</p>";
    $content .= "<p>Your supervisor has submitted a new assessment:</p>";
    $content .= "<p><strong>Assessment Type:</strong> " . str_replace('_', ' ', ucfirst($assessment_type)) . "</p>";
    $content .= "<p><strong>Score:</strong> $score/100</p>";
    $content .= "<p>Log in to view detailed feedback and comments.</p>";

    $cta_url = get_app_url('student/project.php');
    return send_template_email($email, 'New Assessment Received', $title, $content, 'View Assessment', $cta_url);
}

/**
 * Helper to build app URL
 */
function get_app_url(string $path = ''): string {
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/vault';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host . $base_path . '/' . ltrim($path, '/');
}
