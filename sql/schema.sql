-- Final Year Project Vault & Collaboration Hub - Database Schema
-- Run this script to create the database and tables

CREATE DATABASE IF NOT EXISTS vault_fyp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vault_fyp;

-- Users table (all roles)
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) DEFAULT NULL,
    middle_name VARCHAR(100) DEFAULT NULL COMMENT 'Middle or other name',
    level VARCHAR(50) DEFAULT NULL COMMENT 'Academic level e.g. 100, 200, 300, 400',
    role ENUM('student', 'supervisor', 'hod', 'admin') NOT NULL,
    department VARCHAR(255) DEFAULT NULL,
    reg_number VARCHAR(100) DEFAULT NULL COMMENT 'Student registration number',
    phone VARCHAR(50) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    is_verified TINYINT(1) NOT NULL DEFAULT 1,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role (role),
    INDEX idx_email (email),
    INDEX idx_active (is_active)
) ENGINE=InnoDB;

-- OTP verification records (hashed OTP + expiry + cooldown)
CREATE TABLE otp_verifications (
    email VARCHAR(255) NOT NULL PRIMARY KEY,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    resend_available_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB;

-- Projects (student project topics and status)
CREATE TABLE projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    supervisor_id INT UNSIGNED DEFAULT NULL,
    title VARCHAR(500) NOT NULL,
    description TEXT,
    status ENUM('draft', 'submitted', 'approved', 'rejected', 'in_progress', 'completed', 'archived') DEFAULT 'draft',
    submitted_at TIMESTAMP NULL,
    approved_at TIMESTAMP NULL,
    approved_by INT UNSIGNED NULL,
    rejection_reason TEXT NULL,
    academic_year VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_student (student_id),
    INDEX idx_supervisor (supervisor_id),
    INDEX idx_status (status),
    INDEX idx_year (academic_year)
) ENGINE=InnoDB;

-- Project document uploads
CREATE TABLE project_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    document_type ENUM('proposal', 'report', 'zip', 'other') NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT UNSIGNED DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    version INT UNSIGNED DEFAULT 1,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project (project_id)
) ENGINE=InnoDB;

-- Document feedback from supervisor
CREATE TABLE document_feedback (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    supervisor_id INT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES project_documents(id) ON DELETE CASCADE,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_document (document_id)
) ENGINE=InnoDB;

-- Assessments / grading forms
CREATE TABLE assessments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    supervisor_id INT UNSIGNED NOT NULL,
    assessment_type VARCHAR(100) NOT NULL COMMENT 'e.g. proposal_review, final_grade',
    score DECIMAL(5,2) DEFAULT NULL,
    max_score DECIMAL(5,2) DEFAULT 100.00,
    comments TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_assessment (project_id, assessment_type),
    INDEX idx_project (project_id)
) ENGINE=InnoDB;

-- Logbook entries
CREATE TABLE logbook_entries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    entry_date DATE NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    supervisor_approved TINYINT(1) DEFAULT NULL COMMENT 'NULL=pending, 1=approved, 0=flagged',
    approved_by INT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    supervisor_comment TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_project (project_id),
    INDEX idx_entry_date (entry_date),
    INDEX idx_approval (supervisor_approved)
) ENGINE=InnoDB;

-- Messages between student and supervisor
CREATE TABLE messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    subject VARCHAR(255) DEFAULT NULL,
    body TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_project (project_id),
    INDEX idx_recipient (recipient_id),
    INDEX idx_sender (sender_id)
) ENGINE=InnoDB;

-- Notifications
CREATE TABLE notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(100) NOT NULL COMMENT 'new_upload, feedback, approval, message, etc',
    title VARCHAR(255) NOT NULL,
    message TEXT,
    link VARCHAR(500) DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_read (is_read)
) ENGINE=InnoDB;

-- Archive metadata for completed projects (read-only view)
CREATE TABLE archive_metadata (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL UNIQUE,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    archived_by INT UNSIGNED NOT NULL,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Student Groups (for collaborative projects)
CREATE TABLE groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    created_by INT UNSIGNED NOT NULL,
    academic_year VARCHAR(20) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_academic_year (academic_year),
    INDEX idx_active (is_active)
) ENGINE=InnoDB;

-- Group Members
CREATE TABLE group_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    role ENUM('lead', 'member') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_group_member (group_id, student_id),
    INDEX idx_group (group_id),
    INDEX idx_student (student_id)
) ENGINE=InnoDB;

-- Link projects to groups (one group can have one project)
ALTER TABLE projects ADD COLUMN group_id INT UNSIGNED DEFAULT NULL AFTER student_id;
ALTER TABLE projects ADD FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE SET NULL;
ALTER TABLE projects ADD INDEX idx_group (group_id);

-- Enhanced Assessments with criteria scores
ALTER TABLE assessments 
ADD COLUMN research_quality DECIMAL(5,2) DEFAULT NULL,
ADD COLUMN methodology DECIMAL(5,2) DEFAULT NULL,
ADD COLUMN collaboration DECIMAL(5,2) DEFAULT NULL,
ADD COLUMN presentation DECIMAL(5,2) DEFAULT NULL,
ADD COLUMN originality DECIMAL(5,2) DEFAULT NULL,
ADD COLUMN remarks TEXT,
ADD COLUMN supervisor_confirmed TINYINT(1) DEFAULT 0,
ADD COLUMN confirmed_at TIMESTAMP NULL,
ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Supervisor Log Sheets (meeting records)
CREATE TABLE supervisor_logsheets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id INT UNSIGNED NOT NULL,
    supervisor_id INT UNSIGNED NOT NULL,
    meeting_date DATE NOT NULL,
    student_attendees TEXT NOT NULL COMMENT 'Comma-separated student IDs or names',
    topics_discussed TEXT NOT NULL,
    action_points TEXT,
    next_meeting_date DATE DEFAULT NULL,
    supervisor_notes TEXT,
    confirmed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_project (project_id),
    INDEX idx_supervisor (supervisor_id),
    INDEX idx_meeting_date (meeting_date)
) ENGINE=InnoDB;

-- Document Version Tracking (enhanced)
ALTER TABLE project_documents 
ADD COLUMN uploader_id INT UNSIGNED DEFAULT NULL AFTER project_id,
ADD COLUMN version_number INT UNSIGNED DEFAULT 1,
ADD COLUMN is_latest TINYINT(1) DEFAULT 1,
ADD FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE SET NULL;

-- CSV Bulk Import Log
CREATE TABLE bulk_import_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_type ENUM('users', 'pairings', 'topics') NOT NULL,
    imported_by INT UNSIGNED NOT NULL,
    file_name VARCHAR(255),
    total_rows INT UNSIGNED,
    successful_rows INT UNSIGNED,
    failed_rows INT UNSIGNED,
    error_details LONGTEXT,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_type (import_type),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Insert default admin (password: admin123 - CHANGE IN PRODUCTION)
INSERT INTO users (email, password_hash, full_name, role, is_active) VALUES
('admin@vault.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Admin', 'admin', 1);
