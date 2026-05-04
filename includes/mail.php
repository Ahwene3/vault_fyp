<?php
/**
 * Email helper functions - FYP Vault
 * Uses PHPMailer with MailerSend SMTP.
 */

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../config/mail.php';

$vendor_autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendor_autoload)) {
    require_once $vendor_autoload;
}

if (!is_dir(__DIR__ . '/../logs')) {
    @mkdir(__DIR__ . '/../logs', 0755, true);
}

/**
 * Send an HTML email with PHPMailer.
 */
function send_email(string $to, string $subject, string $body, string $from = SITE_EMAIL, ?string &$errorMessage = null): bool {
    $errorMessage = null;

    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Invalid recipient email address.';
        error_log($errorMessage . ' Recipient: ' . $to);
        return false;
    }

    if (!class_exists(PHPMailer::class)) {
        $errorMessage = 'PHPMailer is not installed. Run: composer require phpmailer/phpmailer';
        error_log($errorMessage);
        log_email($to, $subject, $body, $from, false, $errorMessage);
        return false;
    }

    if (MAILERSEND_SMTP_USERNAME === '' || MAILERSEND_SMTP_PASSWORD === '') {
        $errorMessage = 'MailerSend SMTP credentials are missing. Set MAILERSEND_SMTP_USERNAME and MAILERSEND_SMTP_PASSWORD.';
        error_log($errorMessage);
        log_email($to, $subject, $body, $from, false, $errorMessage);
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = MAILERSEND_SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAILERSEND_SMTP_USERNAME;
        $mail->Password = MAILERSEND_SMTP_PASSWORD;
        $mail->Port = MAILERSEND_SMTP_PORT;

        $encryption = strtolower(MAILERSEND_SMTP_ENCRYPTION);
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->setFrom($from, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body)));

        $mail->send();
        log_email($to, $subject, $body, $from, true);
        return true;
    } catch (PHPMailerException $e) {
        $errorMessage = 'SMTP mail error: ' . $e->getMessage();
        error_log($errorMessage);
        log_email($to, $subject, $body, $from, false, $errorMessage);
        return false;
    } catch (Throwable $e) {
        $errorMessage = 'Unexpected mail error: ' . $e->getMessage();
        error_log($errorMessage);
        log_email($to, $subject, $body, $from, false, $errorMessage);
        return false;
    }
}

/**
 * Log email delivery attempts for troubleshooting.
 */
function log_email(string $to, string $subject, string $body, string $from, bool $sent = true, string $details = ''): void {
    $timestamp = date('Y-m-d H:i:s');
    $status = $sent ? 'SENT' : 'FAILED';
    $log_entry = "================================================================================\n";
    $log_entry .= "Timestamp: $timestamp\n";
    $log_entry .= "Status: $status\n";
    $log_entry .= "From: $from\n";
    $log_entry .= "To: $to\n";
    $log_entry .= "Subject: $subject\n";
    if ($details !== '') {
        $log_entry .= "Details: $details\n";
    }
    $log_entry .= "Body:\n$body\n\n";

    @file_put_contents(EMAIL_LOG_FILE, $log_entry, FILE_APPEND);
}

/**
 * Generic template sender.
 */
function send_template_email(
    string $to,
    string $subject,
    string $title,
    string $content,
    ?string $cta_text = null,
    ?string $cta_url = null,
    ?string &$errorMessage = null
): bool {
    $html = get_email_template($title, $content, $cta_text, $cta_url);
    return send_email($to, $subject, $html, SITE_EMAIL, $errorMessage);
}

/**
 * Base HTML email template.
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
        $cta_button .= "<a href=\"$cta_url\" style=\"display:inline-block;padding:12px 24px;background-color:#6366f1;color:white;text-decoration:none;border-radius:8px;font-weight:600;\">";
        $cta_button .= "$cta_text</a></div>";
    }

    $html = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"><title>$title</title>";
    $html .= "<style>";
    $html .= "body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f3f4f6;margin:0;padding:0;}";
    $html .= ".container{max-width:600px;margin:0 auto;background:#fff;padding:40px 20px;}";
    $html .= ".header{text-align:center;margin-bottom:30px;border-bottom:2px solid #6366f1;padding-bottom:20px;}";
    $html .= ".header h1{color:#0f172a;margin:0;font-size:24px;}";
    $html .= ".logo{font-size:18px;font-weight:700;color:#6366f1;margin-bottom:10px;}";
    $html .= ".content{color:#1f2937;line-height:1.6;margin:20px 0;}";
    $html .= ".content p{margin:0 0 15px 0;}";
    $html .= ".footer{text-align:center;margin-top:40px;padding-top:20px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:12px;}";
    $html .= "</style></head><body>";
    $html .= "<div class=\"container\"><div class=\"header\"><div class=\"logo\">FYP Vault</div><h1>$title</h1></div>";
    $html .= "<div class=\"content\">$content</div>";
    $html .= $cta_button;
    $html .= "<div class=\"footer\"><p>© 2026 FYP Vault. All rights reserved.</p>";
    $html .= "<p>This is an automated email. Please do not reply directly to this message.</p></div></div></body></html>";

    return $html;
}

/**
 * OTP-specific branded email sender.
 */
function sendOTPEmail(string $toEmail, string $toName, string $otp, ?string &$errorMessage = null): bool {
    $safeName = trim($toName) !== '' ? htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') : 'Student';
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $expiry = max(1, (int) OTP_EXPIRY_MINUTES);

    $subject = 'Your FYP Vault verification code';
    $html = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">";
    $html .= "<title>FYP Vault OTP Verification</title></head>";
    $html .= "<body style=\"margin:0;padding:0;background:#f1f5f9;font-family:Inter,Segoe UI,Arial,sans-serif;\">";
    $html .= "<div style=\"max-width:640px;margin:28px auto;background:#0b1120;border-radius:20px;overflow:hidden;border:1px solid rgba(99,102,241,0.35);\">";
    $html .= "<div style=\"padding:28px 28px 20px;background:linear-gradient(135deg,#1e293b 0%,#312e81 45%,#0e7490 100%);\">";
    $html .= "<div style=\"display:inline-block;padding:10px 14px;border-radius:14px;background:rgba(255,255,255,0.14);font-weight:700;letter-spacing:0.12em;color:#e2e8f0;\">FYP VAULT</div>";
    $html .= "<h1 style=\"margin:18px 0 6px;color:#f8fafc;font-size:24px;line-height:1.3;\">Email verification code</h1>";
    $html .= "<p style=\"margin:0;color:#cbd5e1;font-size:14px;line-height:1.6;\">Use this code to verify your account and activate access to your project workspace.</p>";
    $html .= "</div>";
    $html .= "<div style=\"padding:28px;\">";
    $html .= "<p style=\"margin:0 0 12px;color:#0f172a;font-size:15px;line-height:1.6;\">Hello <strong>$safeName</strong>,</p>";
    $html .= "<p style=\"margin:0 0 18px;color:#334155;font-size:15px;line-height:1.6;\">Enter the OTP below on the verification page:</p>";
    $html .= "<div style=\"margin:0 0 18px;padding:16px;border-radius:16px;background:#e2e8f0;text-align:center;\">";
    $html .= "<span style=\"font-size:34px;letter-spacing:0.32em;font-weight:800;color:#1e293b;\">$safeOtp</span>";
    $html .= "</div>";
    $html .= "<p style=\"margin:0 0 10px;color:#334155;font-size:14px;line-height:1.6;\">This code expires in <strong>$expiry minutes</strong>.</p>";
    $html .= "<p style=\"margin:0;color:#64748b;font-size:13px;line-height:1.6;\">If you did not initiate this request, you can safely ignore this email.</p>";
    $html .= "</div>";
    $html .= "</div>";
    $html .= "<p style=\"max-width:640px;margin:12px auto 28px;text-align:center;color:#94a3b8;font-size:12px;\">© 2026 FYP Vault — Final Year Project Vault & Collaboration Hub</p>";
    $html .= "</body></html>";

    return send_email($toEmail, $subject, $html, SITE_EMAIL, $errorMessage);
}

/**
 * Send registration confirmation email (legacy helper retained).
 */
function send_registration_email(string $email, string $name): bool {
    $title = 'Welcome to FYP Vault';
    $content = "<p>Hello $name,</p>";
    $content .= "<p>Your account has been successfully created! You can now log in to submit your final year project.</p>";
    $content .= "<p><strong>Your Email:</strong> $email</p>";
    $content .= "<p>Please keep your credentials secure and do not share your password with anyone.</p>";

    $cta_url = get_app_url('index.php');
    return send_template_email($email, 'Welcome to FYP Vault', $title, $content, 'Sign In Now', $cta_url);
}

function send_topic_approval_email(string $email, string $student_name, string $topic_title): bool {
    $title = 'Topic Approved!';
    $content = "<p>Hi $student_name,</p>";
    $content .= "<p>Great news! Your project topic has been approved:</p>";
    $content .= "<p><strong>$topic_title</strong></p>";
    $content .= "<p>A supervisor will be assigned to guide you through the project. You'll receive a notification soon.</p>";

    $cta_url = get_app_url('student/project.php');
    return send_template_email($email, 'Your Topic Has Been Approved', $title, $content, 'View Project', $cta_url);
}

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

function send_message_notification_email(string $email, string $recipient_name, string $sender_name, string $message_preview): bool {
    $title = 'New Message';
    $content = "<p>Hi $recipient_name,</p>";
    $content .= "<p>You have received a new message from <strong>$sender_name</strong>:</p>";
    $content .= "<blockquote style=\"border-left:4px solid #6366f1;padding-left:15px;margin:15px 0;color:#6b7280;\">";
    $content .= "<p>" . nl2br(htmlspecialchars(mb_substr($message_preview, 0, 200))) . "...</p>";
    $content .= "</blockquote>";
    $content .= "<p>Log in to the platform to read the full message and reply.</p>";

    $cta_url = get_app_url('messages.php');
    return send_template_email($email, 'New Message: ' . $sender_name, $title, $content, 'View Message', $cta_url);
}

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

function get_app_url(string $path = ''): string {
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/vault';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? null) == 443) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host . $base_path . '/' . ltrim($path, '/');
}
