-- Create database
CREATE DATABASE IF NOT EXISTS serverhealthcheck;

-- Create user
CREATE USER IF NOT EXISTS 'serverhealthcheck'@'localhost' IDENTIFIED BY 'serverhealthcheck';
GRANT ALL PRIVILEGES ON serverhealthcheck.* TO 'serverhealthcheck'@'localhost';
FLUSH PRIVILEGES;

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
    enabled BOOLEAN DEFAULT TRUE,
    sudo BOOLEAN DEFAULT FALSE,
    last_run TIMESTAMP NULL,
    next_run TIMESTAMP NULL
);

-- Insert a default admin user (password: admin, hashed)
INSERT INTO users (username, password_hash) VALUES ('admin', '$2y$12$UTuDn.Ayfs5nBBP636rXdOAR6HDNe4NzVYDlUE8lRGHv1.AyR16Ou') ON DUPLICATE KEY UPDATE password_hash=password_hash;

-- Insert default check configurations
INSERT INTO checks (script_name, title, interval_minutes, parameters, sudo) VALUES 
('check_disk.py', 'Disk Usage', 5, '80 90', 0),
('check_fs_mirror.py', 'Filesystem Mirror', 5, '', 1),
('check_smart.py', 'SMART Health', 5, '', 1) 
ON DUPLICATE KEY UPDATE script_name=script_name;

-- Health check stats history table (one row per run)
CREATE TABLE IF NOT EXISTS health_checks_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    check_name VARCHAR(100) NOT NULL,
    status ENUM('OK', 'WARN', 'ERROR', 'UNKNOWN') NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stats_check_name (check_name),
    INDEX idx_stats_timestamp (timestamp)
);

-- Per-disk SMART health result, one row per disk per run
CREATE TABLE IF NOT EXISTS smart_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    device VARCHAR(20) NOT NULL,
    health ENUM('PASSED', 'FAILED', 'UNKNOWN') NOT NULL,
    INDEX idx_smart_results_run (run_at),
    INDEX idx_smart_results_dev (device, run_at)
);

-- Per-disk SMART numeric metrics, one row per metric per disk per run
CREATE TABLE IF NOT EXISTS smart_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    device VARCHAR(20) NOT NULL,
    metric VARCHAR(50) NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    unit VARCHAR(10) NULL,
    INDEX idx_smart_metrics_dev (device, metric, run_at)
);

-- Disk usage history, one row per mount point per run
CREATE TABLE IF NOT EXISTS disk_usage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    mountpoint VARCHAR(255) NOT NULL,
    used_mb BIGINT NOT NULL,
    total_mb BIGINT NOT NULL,
    warn_mb BIGINT NOT NULL,
    crit_mb BIGINT NOT NULL,
    INDEX idx_disk_usage_mp (mountpoint, run_at)
);

-- CPU load average history, one row per run
CREATE TABLE IF NOT EXISTS cpu_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    load1 FLOAT NOT NULL,
    load5 FLOAT NOT NULL,
    load15 FLOAT NOT NULL,
    INDEX idx_cpu_stats_run (run_at)
);

-- RAM usage history, one row per run
CREATE TABLE IF NOT EXISTS ram_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_mb BIGINT NOT NULL,
    total_mb BIGINT NOT NULL,
    INDEX idx_ram_stats_run (run_at)
);

-- Process count history, one row per run
CREATE TABLE IF NOT EXISTS process_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    process_count INT NOT NULL,
    INDEX idx_process_stats_run (run_at)
);

-- MariaDB table row count snapshots, one row per table per run
CREATE TABLE IF NOT EXISTS mariadb_table_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    table_schema VARCHAR(64) NOT NULL,
    table_name VARCHAR(64) NOT NULL,
    row_count BIGINT NOT NULL,
    INDEX idx_mts_run (run_at),
    INDEX idx_mts_table (table_schema, table_name, run_at)
);

-- Default check configuration for MariaDB
INSERT INTO checks (script_name, title, interval_minutes, parameters, sudo) VALUES
('check_mariadb.py', 'MariaDB Health', 5, '1000 5000', 0)
ON DUPLICATE KEY UPDATE script_name=script_name;

-- Default check configurations for CPU and RAM
INSERT INTO checks (script_name, title, interval_minutes, parameters, sudo) VALUES
('check_cpu.py', 'CPU Load', 5, '2.0 4.0', 0),
('check_ram.py', 'RAM Usage', 5, '80 90', 0),
('check_processes.py', 'Process Count', 5, '500 800', 0)
ON DUPLICATE KEY UPDATE script_name=script_name;

-- Service status history, one row per run
CREATE TABLE IF NOT EXISTS service_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    failed_count INT NOT NULL,
    active_count INT NOT NULL,
    inactive_count INT NOT NULL,
    INDEX idx_service_stats_run (run_at)
);

-- Default check configuration for service monitoring
INSERT INTO checks (script_name, title, interval_minutes, parameters, sudo) VALUES
('check_services.py', 'Service Status', 5, '1 1', 0)
ON DUPLICATE KEY UPDATE script_name=script_name;

-- Latest state per systemd service unit (upsert-only, no history)
CREATE TABLE IF NOT EXISTS service_unit_states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unit_name VARCHAR(255) NOT NULL,
    state VARCHAR(20) NOT NULL,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_unit_name (unit_name)
);

-- TLS certificate expiry history, one row per run
CREATE TABLE IF NOT EXISTS cert_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    host VARCHAR(253) NOT NULL,
    port SMALLINT UNSIGNED NOT NULL,
    days_left INT NOT NULL,
    INDEX idx_cert_stats_run (run_at),
    INDEX idx_cert_stats_host (host, port, run_at)
);

-- Pending package update count history, one row per run
CREATE TABLE IF NOT EXISTS update_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    pending_count INT NOT NULL,
    INDEX idx_update_stats_run (run_at)
);

-- Zombie process count history, one row per run
CREATE TABLE IF NOT EXISTS zombie_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    zombie_count INT NOT NULL,
    INDEX idx_zombie_stats_run (run_at)
);

-- Default check configurations for cert, updates, reboot, and zombie checks
INSERT INTO checks (script_name, title, interval_minutes, parameters, sudo) VALUES
('check_cert.py',    'TLS Certificate',  60, 'localhost:443 14 7', 0),
('check_updates.py', 'Pending Updates',  60, '10 50',              0),
('check_reboot.py',  'Reboot Required',  60, '',                   0),
('check_zombies.py', 'Zombie Processes',  5, '5 10',               0)
ON DUPLICATE KEY UPDATE script_name=script_name;
