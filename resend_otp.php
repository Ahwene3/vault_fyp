<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/otp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(base_url('verify_otp.php'));
}

if (!csrf_verify()) {
    flash('error', 'Invalid request. Please try again.');
    redirect(base_url('verify_otp.php'));
}

$pending_email = trim((string) ($_SESSION['pending_verification_email'] ?? ''));
if ($pending_email === '') {
    flash('error', 'No pending verification found. Please sign in again.');
    redirect(base_url('index.php'));
}

$pdo = getPDO();
ensure_otp_schema($pdo);

// Resolve recipient name from session (new flow) or DB (legacy flow).
$pending_reg = $_SESSION['pending_registration'] ?? null;
$session_flow = $pending_reg && (($pending_reg['email'] ?? '') === $pending_email);

if ($session_flow) {
    $recipient_name = trim((string) ($pending_reg['full_name'] ?: $pending_reg['first_name'] ?? 'Student'));
} else {
    $user = get_unverified_user_by_email($pdo, $pending_email);
    if (!$user) {
        unset($_SESSION['pending_verification_email']);
        flash('error', 'Account not found for OTP verification.');
        redirect(base_url('index.php'));
    }
    if ((int) ($user['is_verified'] ?? 1) === 1) {
        unset($_SESSION['pending_verification_email']);
        flash('success', 'Your email is already verified. Please sign in.');
        redirect(base_url('index.php'));
    }
    $recipient_name = trim((string) ($user['full_name'] ?? $user['first_name'] ?? 'Student'));
}
$otp_error = null;
if (issue_and_send_otp($pdo, $pending_email, $recipient_name, $otp_error)) {
    flash('success', 'A new OTP has been sent to your email address.');
} else {
    flash('error', $otp_error ?: 'Unable to resend OTP right now. Please try again.');
}

redirect(base_url('verify_otp.php'));
