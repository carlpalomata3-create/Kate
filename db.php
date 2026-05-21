<?php
// TEMPORARY — remove these two lines before going live
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ... rest of file
// ============================================================
// db.php — Database Connection
// Include this file in every PHP page that needs the database.
// ============================================================

// --- Your database credentials ---
define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // Change to your MySQL username
define('DB_PASS', '');           // Change to your MySQL password
define('DB_NAME', 'library_db');

// Create a MySQLi connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check if the connection failed
if ($conn->connect_error) {
    // Stop the script and show an error message
    die("
        <div style='font-family:sans-serif;color:#c0392b;padding:2rem;'>
            <h2>Database Connection Failed</h2>
            <p>" . htmlspecialchars($conn->connect_error) . "</p>
            <p>Please check your credentials in <strong>db.php</strong>.</p>
        </div>
    ");
}

// Use UTF-8 so special characters display correctly
$conn->set_charset("utf8mb4");
?>