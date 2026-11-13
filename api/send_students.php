<?php
// ============================================================
//  send_students.php
//  Sends a single student's details (section, ID number,
//  grade level, course) to the external logistics system.
//
//  Called automatically by enrollment_actions.php whenever
//  a student is assigned an ID number (generate_id action).
//
//  Can also be called manually:
//    POST /api/send_students.php
//    Body (JSON): { "pre_reg_id": 42 }
// ============================================================

// Allow direct inclusion from enrollment_actions.php
// or standalone HTTP call
$is_direct_call = (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? ''));

// DB connection — use shared db.php relative path
$db_path = __DIR__ . '/../app/shared/db.php';
if (file_exists($db_path)) {
    require_once $db_path;
} else {
    // Fallback: inline minimal connection for standalone use
    $conn = new mysqli(
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASS') ?: '',
        getenv('DB_NAME') ?: 'sms_db'
    );
}

// ── Read the external system URL from .env ────────────────────
function get_external_url(): string {
    $env_file = __DIR__ . '/../app/.env';
    if (file_exists($env_file)) {
        foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (str_starts_with($line, 'EXTERNAL_API_URL=')) {
                return trim(substr($line, 17));
            }
        }
    }
    // Fall back to server env var
    return getenv('EXTERNAL_API_URL') ?: '';
}

// ── Main function — called with a pre_reg_id ─────────────────
function send_student_to_external(int $pre_reg_id, mysqli $conn): array {
    // Fetch the student's full pipeline data
    $res = $conn->query(
        "SELECT
            s.id              AS student_db_id,
            s.first_name,
            s.last_name,
            s.birthday,
            s.phone,
            s.status,
            e.id_number,
            e.course,
            e.year_level,
            e.section,
            e.grade_confirmed,
            e.school_year,
            e.semester,
            e.enrolled_at,
            p.ref_number,
            p.email,
            p.applicant_type,
            p.branch          AS campus
         FROM students s
         LEFT JOIN enrollments e       ON e.pre_reg_id = s.pre_reg_id
         LEFT JOIN pre_registrations p ON p.id = s.pre_reg_id
         WHERE s.pre_reg_id = $pre_reg_id
         LIMIT 1"
    );

    if (!$res || !($row = $res->fetch_assoc())) {
        return ['success' => false, 'error' => "Student with pre_reg_id $pre_reg_id not found."];
    }

    // Build the payload
    $payload = [
        'student_id'     => $row['student_db_id'],
        'id_number'      => $row['id_number'],
        'ref_number'     => $row['ref_number'],
        'first_name'     => $row['first_name'],
        'last_name'      => $row['last_name'],
        'full_name'      => trim($row['first_name'] . ' ' . $row['last_name']),
        'birthday'       => $row['birthday'],
        'email'          => $row['email'],
        'phone'          => $row['phone'],
        'course'         => $row['course'],
        'year_level'     => $row['year_level'],
        'section'        => $row['section'],
        'grade_confirmed'=> (bool)$row['grade_confirmed'],
        'school_year'    => $row['school_year'],
        'semester'       => $row['semester'],
        'campus'         => $row['campus'],
        'applicant_type' => $row['applicant_type'],
        'status'         => $row['status'],
        'enrolled_at'    => $row['enrolled_at'],
        'sent_at'        => date('Y-m-d H:i:s'),
    ];

    $json_data = json_encode($payload);

    // ── Send to external system ───────────────────────────────
    $url = get_external_url();

    if (!$url) {
        // URL not configured — log and return the payload anyway
        error_log('[BCP API] EXTERNAL_API_URL not set in .env — student data not sent.');
        return [
            'success' => false,
            'error'   => 'EXTERNAL_API_URL not configured in app/.env',
            'payload' => $payload,
        ];
    }

    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\nAccept: application/json",
            'method'  => 'POST',
            'content' => $json_data,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ];
    $context  = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        $err = error_get_last()['message'] ?? 'Unknown network error';
        error_log("[BCP API] Failed to reach external system at $url: $err");
        return ['success' => false, 'error' => "Could not reach external system: $err", 'payload' => $payload];
    }

    $decoded = json_decode($response, true);
    error_log("[BCP API] Sent student {$row['id_number']} to $url. Response: " . $response);

    return [
        'success'          => true,
        'payload'          => $payload,
        'external_response'=> $decoded ?? $response,
    ];
}

// ── Standalone HTTP call ──────────────────────────────────────
if ($is_direct_call) {
    header('Content-Type: application/json');

    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $pre_reg_id = (int)($body['pre_reg_id'] ?? $_GET['pre_reg_id'] ?? 0);

    if (!$pre_reg_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'pre_reg_id is required.']);
        exit;
    }

    $result = send_student_to_external($pre_reg_id, $conn);
    http_response_code($result['success'] ? 200 : 500);
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit;
}
