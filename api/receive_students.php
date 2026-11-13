<?php
// ============================================================
//  receive_students.php
//  Receives student data pushed from the SMS into this system
//  (or any external system) and stores it in a local table.
//
//  The external system hits this endpoint via POST with JSON.
//  Creates the received_students table if it does not exist.
//
//  POST /api/receive_students.php
//  Body: JSON object (single student from send_students.php)
//
//  Response: { "status": "success", "message": "..." }
// ============================================================
header('Content-Type: application/json');

// DB connection
$db_path = __DIR__ . '/../app/shared/db.php';
if (file_exists($db_path)) {
    require_once $db_path;
} else {
    $conn = new mysqli(
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASS') ?: '',
        getenv('DB_NAME') ?: 'sms_db'
    );
}

// ── Ensure table exists ───────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS received_students (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id      INT UNSIGNED,
    id_number       VARCHAR(30),
    ref_number      VARCHAR(60),
    first_name      VARCHAR(100),
    last_name       VARCHAR(100),
    full_name       VARCHAR(210),
    birthday        DATE,
    email           VARCHAR(150),
    phone           VARCHAR(30),
    course          VARCHAR(200),
    year_level      VARCHAR(60),
    section         VARCHAR(60),
    grade_confirmed TINYINT(1) DEFAULT 0,
    school_year     VARCHAR(20),
    semester        VARCHAR(20),
    campus          VARCHAR(100),
    applicant_type  VARCHAR(50),
    status          VARCHAR(30),
    enrolled_at     DATETIME,
    sent_at         DATETIME,
    received_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_id_number (id_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Read incoming JSON ────────────────────────────────────────
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit;
}

// Accept either a single object or an array of objects
$students = isset($data[0]) ? $data : [$data];

$inserted = 0;
$updated  = 0;
$errors   = [];

foreach ($students as $row) {
    $id_number      = $conn->real_escape_string($row['id_number']      ?? '');
    $student_id     = (int)($row['student_id']     ?? 0);
    $ref_number     = $conn->real_escape_string($row['ref_number']     ?? '');
    $first_name     = $conn->real_escape_string($row['first_name']     ?? '');
    $last_name      = $conn->real_escape_string($row['last_name']      ?? '');
    $full_name      = $conn->real_escape_string($row['full_name']      ?? trim("$first_name $last_name"));
    $birthday       = $conn->real_escape_string($row['birthday']       ?? '');
    $email          = $conn->real_escape_string($row['email']          ?? '');
    $phone          = $conn->real_escape_string($row['phone']          ?? '');
    $course         = $conn->real_escape_string($row['course']         ?? '');
    $year_level     = $conn->real_escape_string($row['year_level']     ?? '');
    $section        = $conn->real_escape_string($row['section']        ?? '');
    $grade_confirmed= (int)($row['grade_confirmed'] ?? 0);
    $school_year    = $conn->real_escape_string($row['school_year']    ?? '');
    $semester       = $conn->real_escape_string($row['semester']       ?? '');
    $campus         = $conn->real_escape_string($row['campus']         ?? '');
    $applicant_type = $conn->real_escape_string($row['applicant_type'] ?? '');
    $status         = $conn->real_escape_string($row['status']         ?? 'Active');
    $enrolled_at    = $conn->real_escape_string($row['enrolled_at']    ?? '');
    $sent_at        = $conn->real_escape_string($row['sent_at']        ?? '');

    if (!$id_number) {
        $errors[] = "Skipped row — missing id_number.";
        continue;
    }

    // Upsert: update if id_number already exists, insert otherwise
    $sql = "INSERT INTO received_students
                (student_id, id_number, ref_number, first_name, last_name, full_name,
                 birthday, email, phone, course, year_level, section,
                 grade_confirmed, school_year, semester, campus,
                 applicant_type, status, enrolled_at, sent_at)
            VALUES
                ($student_id,'$id_number','$ref_number','$first_name','$last_name','$full_name',
                 '$birthday','$email','$phone','$course','$year_level','$section',
                 $grade_confirmed,'$school_year','$semester','$campus',
                 '$applicant_type','$status','$enrolled_at','$sent_at')
            ON DUPLICATE KEY UPDATE
                student_id     = VALUES(student_id),
                ref_number     = VALUES(ref_number),
                first_name     = VALUES(first_name),
                last_name      = VALUES(last_name),
                full_name      = VALUES(full_name),
                birthday       = VALUES(birthday),
                email          = VALUES(email),
                phone          = VALUES(phone),
                course         = VALUES(course),
                year_level     = VALUES(year_level),
                section        = VALUES(section),
                grade_confirmed= VALUES(grade_confirmed),
                school_year    = VALUES(school_year),
                semester       = VALUES(semester),
                campus         = VALUES(campus),
                applicant_type = VALUES(applicant_type),
                status         = VALUES(status),
                enrolled_at    = VALUES(enrolled_at),
                sent_at        = VALUES(sent_at)";

    if ($conn->query($sql)) {
        if ($conn->affected_rows === 1) $inserted++;
        else                            $updated++;
    } else {
        $errors[] = "DB error for id_number=$id_number: " . $conn->error;
    }
}

echo json_encode([
    'status'   => empty($errors) ? 'success' : 'partial',
    'message'  => "Received " . ($inserted + $updated) . " student(s). Inserted: $inserted, Updated: $updated.",
    'inserted' => $inserted,
    'updated'  => $updated,
    'errors'   => $errors,
]);
