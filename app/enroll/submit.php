<?php
session_start();
require_once __DIR__ . '/../shared/db.php';

$d = $_SESSION['enroll'] ?? [];
if (empty($d['first_name'])) { header('Location: index.php'); exit; }

// ── Check if user is logged in; use their user_id or 0 for guests ──
$user_id = $_SESSION['user_id'] ?? 0;

// ── Check enrollment table exists ───────────────────────────
if (!enrollment_tables_exist($conn)) { header('Location: ../shared/full_setup.php'); exit; }

// ── Generate reference number ────────────────────────────────
$ref = 'BCP-' . strtoupper(substr($d['course'] ?? 'XX', 0, 2)) . '-' . date('Ymd') . '-' . rand(1000, 9999);
$_SESSION['enroll_ref'] = $ref;

// ── Save to pre_registrations ────────────────────────────────
$first   = trim($d['first_name']      ?? '');
$last    = trim($d['last_name']       ?? '');
$email   = trim($d['email']           ?? '');
$phone   = trim($d['phone']           ?? '');
$bday    = trim($d['birthday']        ?? date('Y-m-d'));
$course  = trim($d['course']          ?? '');
$year    = trim($d['last_year_level'] ?? '1st Year');
$prev    = trim($d['prev_school']     ?? '');

// Ensure the column allows NULL in case the DB was created before the schema fix
$conn->query("ALTER TABLE pre_registrations MODIFY COLUMN user_id INT UNSIGNED NULL DEFAULT NULL");

$uid = $user_id > 0 ? (int)$user_id : null;

if ($uid !== null) {
    $stmt = $conn->prepare(
        "INSERT INTO pre_registrations
            (user_id, first_name, last_name, email, phone, birthday, course, year_level, prev_school, ref_number, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,'Pending')"
    );
    $stmt->bind_param('isssssssss', $uid, $first, $last, $email, $phone, $bday, $course, $year, $prev, $ref);
} else {
    $stmt = $conn->prepare(
        "INSERT INTO pre_registrations
            (first_name, last_name, email, phone, birthday, course, year_level, prev_school, ref_number, status)
         VALUES (?,?,?,?,?,?,?,?,?,'Pending')"
    );
    $stmt->bind_param('sssssssss', $first, $last, $email, $phone, $bday, $course, $year, $prev, $ref);
}

if (!$stmt->execute()) {
    // Log error and still redirect — don't crash the user
    error_log('pre_registrations insert failed: ' . $conn->error);
}
$stmt->close();

$conn->close();

// Clear session data but keep ref
$saved_ref = $_SESSION['enroll_ref'];
unset($_SESSION['enroll']);
$_SESSION['enroll_ref'] = $saved_ref;

header('Location: confirmation.php');
exit;
