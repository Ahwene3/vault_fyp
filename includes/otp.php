<?php
/**
 * OTP verification helpers.
 */

require_once __DIR__ . '/init.php';

function otp_verification_scope(): string {
    $scope = strtolower(trim((string) OTP_VERIFICATION_SCOPE));
    if (!in_array($scope, ['student', 'all', 'none'], true)) {
        return 'student';
    }
    return $scope;
}

function should_require_otp_for_role(?string $role): bool {
    $scope = otp_verification_scope();
    if ($scope === 'none') {
        return false;
    }
    if ($scope === 'all') {
        return true;
    }
    return strtolower(trim((string) $role)) === 'student';
}

function ensure_email_verification_columns(PDO $pdo): void {
    $required_columns = [
        'is_verified' => 'ALTER TABLE users ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active',
        'verified_at' => 'ALTER TABLE users ADD COLUMN verified_at TIMESTAMP NULL DEFAULT NULL AFTER is_verified',
    ];

    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = "users"
           AND COLUMN_NAME IN (' . sql_placeholders(count($required_columns)) . ')'
    );
    $stmt->execute(array_keys($required_columns));

    $existing_columns = array_map('strtolower', array_column($stmt->fetchAll(), 'COLUMN_NAME'));

    foreach ($required_columns as $column_name => $ddl) {
        if (in_array(strtolower($column_name), $existing_columns, true)) {
            continue;
        }

        try {
            $pdo->exec($ddl);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'duplicate column name') === false) {
                throw $e;
            }
        }
    }
}

function ensure_otp_verifications_table(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS otp_verifications (
        email VARCHAR(255) NOT NULL PRIMARY KEY,
        otp_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        resend_available_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_expires_at (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function ensure_otp_schema(PDO $pdo): void {
    ensure_email_verification_columns($pdo);
    ensure_otp_verifications_table($pdo);
}

function cleanup_expired_otps(PDO $pdo): void {
    $stmt = $pdo->prepare('DELETE FROM otp_verifications WHERE expires_at < NOW()');
    $stmt->execute();
}

function generate_otp_code(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function store_otp_for_email(PDO $pdo, string $email, string $otp): void {
    $otp_hash = password_hash($otp, PASSWORD_DEFAULT);
    $expires_at = date('Y-m-d H:i:s', time() + (max(1, (int) OTP_EXPIRY_MINUTES) * 60));
    $resend_available_at = date('Y-m-d H:i:s', time() + max(1, (int) OTP_RESEND_COOLDOWN_SECONDS));

    $stmt = $pdo->prepare(
        'INSERT INTO otp_verifications (email, otp_hash, expires_at, resend_available_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            otp_hash = VALUES(otp_hash),
            expires_at = VALUES(expires_at),
            resend_available_at = VALUES(resend_available_at),
            updated_at = NOW()'
    );
    $stmt->execute([$email, $otp_hash, $expires_at, $resend_available_at]);
}

function delete_otp_for_email(PDO $pdo, string $email): void {
    $stmt = $pdo->prepare('DELETE FROM otp_verifications WHERE email = ?');
    $stmt->execute([$email]);
}

function get_otp_record(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare('SELECT email, otp_hash, expires_at, resend_available_at, created_at FROM otp_verifications WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $record = $stmt->fetch();
    return $record ?: null;
}

function get_unverified_user_by_email(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare('SELECT id, email, full_name, first_name, role, is_verified FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    return $user;
}

function can_resend_otp(PDO $pdo, string $email, int &$secondsLeft = 0): bool {
    $secondsLeft = 0;
    $record = get_otp_record($pdo, $email);
    if (!$record) {
        return true;
    }

    $available_at = strtotime((string) $record['resend_available_at']);
    if ($available_at === false) {
        return true;
    }

    $now = time();
    if ($available_at <= $now) {
        return true;
    }

    $secondsLeft = max(1, $available_at - $now);
    return false;
}

function issue_and_send_otp(PDO $pdo, string $email, string $recipientName, ?string &$errorMessage = null): bool {
    $errorMessage = null;
    ensure_otp_schema($pdo);
    cleanup_expired_otps($pdo);

    $secondsLeft = 0;
    if (!can_resend_otp($pdo, $email, $secondsLeft)) {
        $errorMessage = 'Please wait ' . $secondsLeft . ' seconds before requesting another OTP.';
        return false;
    }

    $otp = generate_otp_code();
    store_otp_for_email($pdo, $email, $otp);

    $mailError = null;
    if (!sendOTPEmail($email, $recipientName, $otp, $mailError)) {
        delete_otp_for_email($pdo, $email);
        $errorMessage = $mailError ?: 'Unable to send OTP email at the moment.';
        return false;
    }

    return true;
}

function verify_otp_code(PDO $pdo, string $email, string $submittedOtp, ?string &$errorMessage = null): bool {
    $errorMessage = null;
    ensure_otp_schema($pdo);
    cleanup_expired_otps($pdo);

    $record = get_otp_record($pdo, $email);
    if (!$record) {
        $errorMessage = 'No OTP request found for this email. Please request a new OTP.';
        return false;
    }

    $expires_at = strtotime((string) $record['expires_at']);
    if ($expires_at !== false && $expires_at < time()) {
        delete_otp_for_email($pdo, $email);
        $errorMessage = 'OTP has expired. Please request a new one.';
        return false;
    }

    if (!password_verify($submittedOtp, (string) $record['otp_hash'])) {
        $errorMessage = 'Invalid OTP code.';
        return false;
    }

    return true;
}

function mark_email_verified(PDO $pdo, string $email): bool {
    ensure_email_verification_columns($pdo);
    $stmt = $pdo->prepare('UPDATE users SET is_verified = 1, verified_at = NOW() WHERE email = ?');
    $stmt->execute([$email]);
    return $stmt->rowCount() > 0;
}

function mask_email(string $email): string {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email;
    }

    $name = $parts[0];
    $domain = $parts[1];
    if (strlen($name) <= 2) {
        $masked = substr($name, 0, 1) . '*';
    } else {
        $masked = substr($name, 0, 2) . str_repeat('*', max(1, strlen($name) - 2));
    }

    return $masked . '@' . $domain;
}
