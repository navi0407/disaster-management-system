<?php
// define('ERR_DEBUG', true);   // remove the two slashes ONLY while you are fixing a bug
require_once __DIR__ . '/../includes/errors.php';

// Database connection settings
// Default XAMPP MySQL credentials — change these later once you set a real password
$host   = "localhost";
$dbUser = "root";
$dbPass = "";
$dbName = "dms_db";

$conn = mysqli_connect($host, $dbUser, $dbPass, $dbName);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

require_once __DIR__ . '/../includes/auth.php';
authGuard($conn);

?>

