-- Canonical departments for FYP Vault (HOD/registration selections)
CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upsert canonical department list (keep descriptions updated)
INSERT INTO departments (name, description, is_active) VALUES
(
    'Nautical Science Department',
    'Programmes: BSc. Nautical Science; Diploma in Nautical Science; General Purpose Rating (PreSea Vocational); Class One Deck Certificates of Competency; Class Two Deck Certificates of Competency; Class Three Deck Certificates of Competency.',
    1
),
(
    'Marine Engineering Department',
    'Programmes: B.Sc. Marine Engineering; B.Sc. Mechanical Engineering; Diploma in Marine Engineering; B.Sc. Naval Architecture (Small Craft or Ocean Engineering Options). Postgraduate: MSc. Environmental Engineering; MSc. Bio-Processing Engineering; MSc. Subsea Engineering; MSc. Electrical Power Engineering; MSc. Coastal Environmental Management. Vocational: Marine Engine Mechanic; Naval Architecture Applications; Ship Construction for Carpenters; Refrigeration and Air Conditioning; Welding and Fabrication Levels I, II and III; Piping and Instrumentation Diagram (PI&D); MaxSURF; AutoCAD Levels I, II and III.',
    1
),
(
    'Department of Business Studies',
    'Programmes: B.Sc. Accounting; B.Sc. Procurement and Operations Management.',
    1
),
(
    'ICT Department',
    'Programmes: B.Sc. or Diploma in Information Technology; B.Sc. or Diploma in Computer Science; B.Sc. or Diploma in Computer Engineering.',
    1
),
(
    'Department of Transport',
    'Programmes: B.Sc. Logistics Management; B.Sc. or Diploma in Port and Shipping Administration.',
    1
)
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    is_active = 1;

-- Keep active selections restricted to the canonical list above.
UPDATE departments
SET is_active = CASE
    WHEN name IN (
        'Nautical Science Department',
        'Marine Engineering Department',
        'Department of Business Studies',
        'ICT Department',
        'Department of Transport'
    ) THEN 1
    ELSE 0
END;
