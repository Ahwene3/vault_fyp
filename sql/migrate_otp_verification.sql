-- OTP email verification schema migration
-- Run against vault_fyp database

ALTER TABLE users
    ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active,
    ADD COLUMN verified_at TIMESTAMP NULL DEFAULT NULL AFTER is_verified;

CREATE TABLE IF NOT EXISTS otp_verifications (
    email VARCHAR(255) NOT NULL PRIMARY KEY,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    resend_available_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
