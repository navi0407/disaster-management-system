<?php
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
?>
