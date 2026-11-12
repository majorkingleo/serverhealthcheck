<?php
session_start();
require_once '../conf/db.php';

// Database connection
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw $e;
        }
    }
    return $pdo;
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Extract the human-readable reason from a stored check message.
 *
 * Checks print `STATUS: reason | perfdata` and may append a ` ||| ` detail dump
 * (per-unit states). The dashboard only wants the reason part.
 */
function checkReason(?string $message): string {
    $text = (string)$message;
    $text = preg_split('/\s+\|\|\|\s+/', $text)[0];
    $text = preg_split('/\s+\|\s+/', $text)[0];
    return trim($text);
}

/**
 * Return the perfdata part of a stored check message (empty string when absent).
 */
function checkPerfdata(?string $message): string {
    $text = (string)$message;
    $text = preg_split('/\s+\|\|\|\s+/', $text)[0];
    $parts = preg_split('/\s+\|\s+/', $text, 2);
    return isset($parts[1]) ? trim($parts[1]) : '';
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}
?>