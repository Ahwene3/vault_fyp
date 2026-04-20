-- Add departments table for registration dropdown
CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB;

-- Insert sample departments
INSERT IGNORE INTO departments (name, description) VALUES
('Marine Engineering', 'Study of marine and ocean engineering'),
('Computer Science', 'Computer science and software engineering'),
('Civil Engineering', 'Civil engineering and construction'),
('Mechanical Engineering', 'Mechanical engineering and design'),
('Electrical Engineering', 'Electrical engineering and power systems'),
('Accounting', 'Accounting and finance'),
('Business Administration', 'Business studies and management'),
('Information Technology', 'Information technology and systems'),
('Environmental Engineering', 'Environmental science and engineering'),
('Chemical Engineering', 'Chemical engineering processes');
