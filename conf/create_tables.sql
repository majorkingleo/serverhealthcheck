-- Create database
-- CREATE DATABASE IF NOT EXISTS serverhealthcheck;

-- Create user
-- CREATE USER IF NOT EXISTS 'serverhealthcheck'@'localhost' IDENTIFIED BY 'serverhealthcheck';
-- GRANT ALL PRIVILEGES ON serverhealthcheck.* TO 'serverhealthcheck'@'localhost';
-- FLUSH PRIVILEGES;

USE serverhealthcheck;

-- Users table for authentication
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Health checks results table
CREATE TABLE IF NOT EXISTS health_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    check_name VARCHAR(100) NOT NULL,
    status ENUM('OK', 'WARN', 'ERROR', 'UNKNOWN') NOT NULL,
    message TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_timestamp (timestamp),
    UNIQUE KEY uq_health_check_name (check_name)
);

-- Checks configuration table
CREATE TABLE IF NOT EXISTS checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    script_name VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(150) NOT NULL,
    interval_minutes INT DEFAULT 5,
    parameters VARCHAR(255) DEFAULT '',
    target_table VARCHAR(100) DEFAULT 'health_checks',
    enabled BOOLEAN DEFAULT TRUE,
    sudo BOOLEAN DEFAULT FALSE,
    last_run TIMESTAMP NULL,
    next_run TIMESTAMP NULL
);

-- Insert a default admin user (password: admin123, hashed)
INSERT INTO users (username, password_hash) VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi') ON DUPLICATE KEY UPDATE password_hash=password_hash;

-- Insert default check configurations
INSERT INTO checks (script_name, title, interval_minutes, parameters, target_table, sudo) VALUES 
('check_disk.py', 'Disk Usage', 5, '80 90', 'health_checks', 0),
('check_fs_mirror.py', 'Filesystem Mirror', 5, '', 'health_checks', 1),
('check_smart.py', 'SMART Health', 5, '', 'health_checks', 1) 
ON DUPLICATE KEY UPDATE script_name=script_name;