<?php
/**
 * Mail configuration for PHPMailer + MailerSend SMTP.
 * Populate these values in your environment (recommended) or edit for local testing.
 */

if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'FYP Vault');
}

if (!defined('MAILERSEND_SMTP_HOST')) {
    define('MAILERSEND_SMTP_HOST', getenv('MAILERSEND_SMTP_HOST') ?: 'smtp.mailersend.net');
}

if (!defined('MAILERSEND_SMTP_PORT')) {
    define('MAILERSEND_SMTP_PORT', (int) (getenv('MAILERSEND_SMTP_PORT') ?: 587));
}

if (!defined('MAILERSEND_SMTP_ENCRYPTION')) {
    define('MAILERSEND_SMTP_ENCRYPTION', getenv('MAILERSEND_SMTP_ENCRYPTION') ?: 'tls'); // tls or ssl
}

if (!defined('MAILERSEND_SMTP_USERNAME')) {
    define('MAILERSEND_SMTP_USERNAME', getenv('MAILERSEND_SMTP_USERNAME') ?: '');
}

if (!defined('MAILERSEND_SMTP_PASSWORD')) {
    define('MAILERSEND_SMTP_PASSWORD', getenv('MAILERSEND_SMTP_PASSWORD') ?: '');
}

if (!defined('SITE_EMAIL')) {
    define('SITE_EMAIL', getenv('MAIL_FROM_EMAIL') ?: 'noreply@example.com');
}

if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: SITE_NAME);
}

if (!defined('EMAIL_LOG_FILE')) {
    define('EMAIL_LOG_FILE', __DIR__ . '/../logs/emails.log');
}

if (!defined('OTP_EXPIRY_MINUTES')) {
    define('OTP_EXPIRY_MINUTES', (int) (getenv('OTP_EXPIRY_MINUTES') ?: 10));
}

if (!defined('OTP_RESEND_COOLDOWN_SECONDS')) {
    define('OTP_RESEND_COOLDOWN_SECONDS', (int) (getenv('OTP_RESEND_COOLDOWN_SECONDS') ?: 60));
}

/**
 * OTP verification scope:
 * - student : only students require OTP verification (default)
 * - all     : all roles require OTP verification
 * - none    : disable OTP verification for all roles
 */
if (!defined('OTP_VERIFICATION_SCOPE')) {
    define('OTP_VERIFICATION_SCOPE', strtolower((string) (getenv('OTP_VERIFICATION_SCOPE') ?: 'student')));
}
