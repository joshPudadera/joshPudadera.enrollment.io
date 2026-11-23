<?php


$dbs = isset($_GET['db']) ? [$_GET['db']] : ['api_test1','api_test2'];

$conn = new mysqli('localhost','root','');
if ($conn->connect_error) { die("Cannot connect to MySQL: " . $conn->connect_error); }

foreach ($dbs as $db) {
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
    echo "DB <strong>$db</strong> ready with <code>students</code> table.<br>";
}
$conn->close();
echo '<br><a href="../page1.php" style="font-size:.9rem;color:#2563eb;">Go to Page 1 →</a>';
