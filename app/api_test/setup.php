<?php
mysqli_report(MYSQLI_REPORT_OFF);

$host   = getenv('DB_HOST') ?: 'localhost';
$user   = getenv('DB_USER') ?: 'root';
$pass   = getenv('DB_PASS') ?: '';
$dbname = getenv('DB_NAME') ?: 'sms_db';

$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    die("Cannot connect to MySQL: " . $conn->connect_error);
}

$tables = [
    'api_test1_students' => 'System 1',
    'api_test2_students' => 'System 2',
];

foreach ($tables as $table => $label) {
    $conn->query("CREATE TABLE IF NOT EXISTS `$table` (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        section VARCHAR(50) NOT NULL,
        year_level VARCHAR(50) NOT NULL,
        source VARCHAR(20) DEFAULT 'local',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $check = $conn->query("SELECT COUNT(*) c FROM `$table`");
    $count = $check ? $check->fetch_assoc()['c'] : 'ERROR';
    echo "<strong>$label</strong> table <code>$table</code> ready ($count rows)<br>\n";
}

$conn->close();
echo '<br><a href="page1.php" style="font-size:1rem;color:#2563eb;">Go to Page 1 →</a>';
