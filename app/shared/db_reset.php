<?php
// ONE-TIME USE — DELETE THIS FILE AFTER RUNNING
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli('localhost', 'root', '');
if ($conn->connect_error) die("Cannot connect: " . $conn->connect_error);

$conn->query("SET FOREIGN_KEY_CHECKS=0");
$conn->query("DROP DATABASE IF EXISTS sms_db");
$conn->query("SET FOREIGN_KEY_CHECKS=1");

if ($conn->errno) {
    die("Drop failed: " . $conn->error);
}
$conn->close();

// Redirect to full setup
header("Location: full_setup.php");
exit;
