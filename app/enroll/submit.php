<?php
session_start();
require_once __DIR__ . '/../shared/db.php';

$d = $_SESSION['enroll'] ?? [];
if (empty($d['first_name'])) { header('Location: index.php'); exit; }

$user_id = $_SESSION['user_id'] ?? 0;

if (!enrollment_tables_exist($conn)) { header('Location: ../shared/full_setup.php'); exit; }

// ── Generate reference number ─────────────────────────────────
$ref = 'BCP-' . strtoupper(substr($d['course'] ?? 'XX', 0, 2)) . '-' . date('Ymd') . '-' . rand(1000, 9999);
$_SESSION['enroll_ref'] = $ref;

// ── Collect all fields ────────────────────────────────────────
$first           = trim($d['first_name']          ?? '');
$last            = trim($d['last_name']            ?? '');
$middle          = trim($d['middle_name']          ?? '');
$suffix          = trim($d['suffix']               ?? '');
$email           = trim($d['email']                ?? '');
$phone           = trim($d['phone']                ?? '');
$bday            = trim($d['birthday']             ?? date('Y-m-d'));
$sex             = trim($d['sex']                  ?? '');
$civil_status    = trim($d['civil_status']         ?? '');
$nationality     = trim($d['nationality']          ?? 'Filipino');
$religion        = trim($d['religion']             ?? '');
$place_of_birth  = trim($d['place_of_birth']       ?? '');
$address         = trim($d['address']              ?? '');
$course          = trim($d['course']               ?? '');
$branch          = trim($d['branch']               ?? '');
$atype           = trim($d['applicant_type']       ?? 'Freshman');
$student_type    = trim($d['student_type']         ?? 'New Regular');
$prev            = trim($d['prev_school']          ?? '');
$prev_address    = trim($d['prev_school_address']  ?? '');
$last_yr         = trim($d['last_year_level']      ?? '');
$grad_year       = trim($d['grad_year']            ?? '');
$gen_avg         = trim($d['general_average']      ?? '');
$honors          = trim($d['honors']               ?? '');
// Parent info
$father_first    = trim($d['father_first_name']    ?? '');
$father_last     = trim($d['father_last_name']     ?? '');
$father_mid      = trim($d['father_middle_name']   ?? '');
$father_occ      = trim($d['father_occupation']    ?? '');
$father_phone    = trim($d['father_phone']         ?? '');
$mother_first    = trim($d['mother_first_name']    ?? '');
$mother_last     = trim($d['mother_last_name']     ?? '');
$mother_mid      = trim($d['mother_middle_name']   ?? '');
$mother_occ      = trim($d['mother_occupation']    ?? '');
$mother_phone    = trim($d['mother_phone']         ?? '');
$guardian_name   = trim($d['guardian_name']        ?? '');
$guardian_rel    = trim($d['guardian_relation']    ?? '');
$guardian_phone  = trim($d['guardian_phone']       ?? '');
// Emergency contact
$emerg_name      = trim($d['emergency_name']       ?? '');
$emerg_rel       = trim($d['emergency_relation']   ?? '');
$emerg_phone     = trim($d['emergency_phone']      ?? '');

$year = $last_yr ?: '1st Year';
if ($atype === 'Freshman') $year = '1st Year';

// ── Ensure all columns exist (idempotent) ─────────────────────
@$conn->query("ALTER TABLE pre_registrations MODIFY COLUMN user_id INT UNSIGNED NULL DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations MODIFY COLUMN applicant_type ENUM('Freshman','Senior High') DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS student_type VARCHAR(30) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS middle_name VARCHAR(100) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS suffix VARCHAR(20) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS sex VARCHAR(20) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS civil_status VARCHAR(30) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS nationality VARCHAR(80) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS religion VARCHAR(100) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS place_of_birth VARCHAR(200) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS address VARCHAR(300) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS branch VARCHAR(100) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS prev_school_address VARCHAR(200) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS grad_year VARCHAR(20) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS general_average VARCHAR(20) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS honors VARCHAR(150) DEFAULT NULL");
// Father
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS father_first_name VARCHAR(100) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS father_last_name VARCHAR(100) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS father_middle_name VARCHAR(100) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS father_occupation VARCHAR(150) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS father_phone VARCHAR(30) DEFAULT NULL");
// Mother
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS mother_first_name VARCHAR(100) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS mother_last_name VARCHAR(100) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS mother_middle_name VARCHAR(100) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS mother_occupation VARCHAR(150) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS mother_phone VARCHAR(30) DEFAULT NULL");
// Guardian
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS guardian_name VARCHAR(150) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS guardian_relation VARCHAR(80) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS guardian_phone VARCHAR(30) DEFAULT NULL");
// Emergency
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS emergency_name VARCHAR(150) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS emergency_relation VARCHAR(80) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS emergency_phone VARCHAR(30) DEFAULT NULL");

$uid = $user_id > 0 ? (int)$user_id : null;

// ── INSERT ────────────────────────────────────────────────────
$cols = 'first_name, last_name, middle_name, suffix,
         email, phone, birthday, sex, civil_status, nationality, religion, place_of_birth,
         address, course, year_level, branch,
         applicant_type, student_type,
         prev_school, prev_school_address, grad_year, general_average, honors,
         father_first_name, father_last_name, father_middle_name, father_occupation, father_phone,
         mother_first_name, mother_last_name, mother_middle_name, mother_occupation, mother_phone,
         guardian_name, guardian_relation, guardian_phone,
         emergency_name, emergency_relation, emergency_phone,
         ref_number, status';
$vals  = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending'";
$types = 'ssssssssssssssssssssssssssssssssssssssss';
$params = [
    $first, $last, $middle, $suffix,
    $email, $phone, $bday, $sex, $civil_status, $nationality, $religion, $place_of_birth,
    $address, $course, $year, $branch,
    $atype, $student_type,
    $prev, $prev_address, $grad_year, $gen_avg, $honors,
    $father_first, $father_last, $father_mid, $father_occ, $father_phone,
    $mother_first, $mother_last, $mother_mid, $mother_occ, $mother_phone,
    $guardian_name, $guardian_rel, $guardian_phone,
    $emerg_name, $emerg_rel, $emerg_phone,
    $ref,
];

if ($uid !== null) {
    $cols   = 'user_id, ' . $cols;
    $vals   = '?, ' . $vals;
    $types  = 'i' . $types;
    array_unshift($params, $uid);
}

$stmt = $conn->prepare("INSERT INTO pre_registrations ($cols) VALUES ($vals)");
$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) {
    error_log('pre_registrations insert failed: ' . $conn->error);
}
$stmt->close();
$conn->close();

$saved_ref = $_SESSION['enroll_ref'];
unset($_SESSION['enroll']);
$_SESSION['enroll_ref'] = $saved_ref;

header('Location: confirmation.php');
exit;
