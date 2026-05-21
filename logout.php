<?php
// ============================================================
// logout.php — Destroys the session and returns to login
// ============================================================

session_start();

// Remove all session variables
session_unset();

// Destroy the session itself
session_destroy();

// Send the user back to the login page
header('Location: login.php');
exit();
?>