-- Migration: Add first_name, last_name, middle_name, level to users (for existing databases)
-- Run this once if you already have vault_fyp without these columns. If you get "Duplicate column", skip.

USE vault_fyp;

ALTER TABLE users ADD COLUMN first_name VARCHAR(100) DEFAULT NULL AFTER full_name;
ALTER TABLE users ADD COLUMN last_name VARCHAR(100) DEFAULT NULL AFTER first_name;
ALTER TABLE users ADD COLUMN middle_name VARCHAR(100) DEFAULT NULL COMMENT 'Middle or other name' AFTER last_name;
ALTER TABLE users ADD COLUMN level VARCHAR(50) DEFAULT NULL COMMENT 'Academic level' AFTER middle_name;
