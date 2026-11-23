<?php
mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

$conn = new mysqli($host, $user, $pass);
if ($conn->connect_error) {
    die("Cannot connect to MySQL: " . $conn->connect_error);
}

foreach (['api_test1', 'api_test2'] as $db) {
    $conn->query("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->select_db($db);
    $conn->query("CREATE TABLE IF NOT EXISTS students (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        section VARCHAR(50) NOT NULL,
        year_level VARCHAR(50) NOT NULL,
        source VARCHAR(20) DEFAULT 'local',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $check = $conn->query("SELECT COUNT(*) c FROM students");
    $count = $check ? $check->fetch_assoc()['c'] : 'ERROR';
    echo "DB <strong>$db</strong> — students table ready ($count rows)<br>\n";
}
$conn->close();
echo '<br><a href="page1.php" style="font-size:1rem;color:#2563eb;">Go to Page 1 →</a>';
