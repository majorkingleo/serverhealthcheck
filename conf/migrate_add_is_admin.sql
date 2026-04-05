-- Migration: add is_admin column to users table
-- Safe to run multiple times (IF NOT EXISTS / ON DUPLICATE KEY)

USE serverhealthcheck;

ALTER TABLE users ADD COLUMN IF NOT EXISTS is_admin BOOLEAN NOT NULL DEFAULT FALSE;

-- Ensure the admin user is flagged as admin
UPDATE users SET is_admin = 1 WHERE username = 'admin';
