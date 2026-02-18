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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role (role),
    INDEX idx_email (email),
    INDEX idx_active (is_active)
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

-- Insert default admin (password: admin123 - CHANGE IN PRODUCTION)
INSERT INTO users (email, password_hash, full_name, role, is_active) VALUES
('admin@vault.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Admin', 'admin', 1);
