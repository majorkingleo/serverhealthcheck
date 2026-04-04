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
    INDEX idx_check_name (check_name)
);

-- Insert a default admin user (password: admin123, hashed)
INSERT INTO users (username, password_hash) VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi') ON DUPLICATE KEY UPDATE password_hash=password_hash;