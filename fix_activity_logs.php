<?php
mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli("localhost","root","","sms_db");

// Force drop via dictionary (works even when .ibd is gone)
$conn->query("SET FOREIGN_KEY_CHECKS=0");
$conn->query("DROP TABLE IF EXISTS activity_logs");
$conn->query("SET FOREIGN_KEY_CHECKS=1");

$sql = "CREATE TABLE activity_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id    INT UNSIGNED NOT NULL,
    actor_name  VARCHAR(150) NOT NULL,
    actor_role  VARCHAR(30)  NOT NULL DEFAULT 'admin',
    action      VARCHAR(100) NOT NULL,
    target_type VARCHAR(60)  DEFAULT NULL,
    target_id   INT UNSIGNED DEFAULT NULL,
    target_name VARCHAR(200) DEFAULT NULL,
    details     TEXT         DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_actor_id   (actor_id),
    INDEX idx_action     (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo "activity_logs created OK\n";
} else {
    echo "FAILED: [{$conn->errno}] {$conn->error}\n";
    // Try workaround: create a dummy .ibd via a temp table
    echo "Trying workaround...\n";
    $conn->query("CREATE TABLE activity_logs_tmp (id INT PRIMARY KEY) ENGINE=InnoDB");
    $conn->query("DROP TABLE IF EXISTS activity_logs_tmp");
    if ($conn->query($sql)) {
        echo "activity_logs created OK (workaround)\n";
    } else {
        echo "Still failed: {$conn->error}\n";
        echo "\nNote: Restart MySQL in XAMPP and visit:\n";
        echo "http://localhost/sms/fix_activity_logs.php\n";
    }
}

// Final check
$r = $conn->query("SHOW TABLES LIKE 'activity_logs'");
echo "Visible in SHOW TABLES: " . ($r && $r->num_rows > 0 ? "YES" : "NO") . "\n";
$conn->close();
