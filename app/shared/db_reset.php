<?php
// ONE-TIME USE — DELETE THIS FILE AFTER RUNNING
// Drops and recreates the database, then redirects to full_setup.php
mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'sms_db';
$port = (int)(getenv('DB_PORT') ?: 3306);

$conn = new mysqli($host, $user, $pass, null, $port);
if ($conn->connect_error) die("Cannot connect: " . $conn->connect_error);

// On hosted platforms DROP DATABASE is usually not allowed.
// Instead we drop all tables within the existing database.
$conn->select_db($db);
$conn->query("SET FOREIGN_KEY_CHECKS=0");
$tables = $conn->query("SHOW TABLES");
if ($tables) {
    while ($row = $tables->fetch_row()) {
        $conn->query("DROP TABLE IF EXISTS `{$row[0]}`");
    }
}
$conn->query("SET FOREIGN_KEY_CHECKS=1");
$conn->close();

header("Location: full_setup.php");
exit;
