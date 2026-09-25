<?php
// ============================================================
//  GENERATE_DOCX.PHP  (shared/)
//  Admin-triggered endpoint that:
//    1. Pulls all 3 AI-inspected documents for a pre_reg_id
//       from the enrollment_documents table.
//    2. Merges the extracted AI data into a single payload.
//    3. Calls the Python script (generate_docx.py) to produce
//       a .docx file using python-docx.
//    4. Streams the generated file back to the browser as a
//       download, or saves it and redirects with a success flag.
//
//  CALLED FROM:  admin/document_review.php  (POST)
//  REQUIRES:     Python 3 + python-docx installed
//                  pip install python-docx
//
//  POST params:
//    pre_reg_id  — integer ID of the pre_registration row
//    save_only   — optional "1" to save to disk instead of download
// ============================================================
session_start();
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

$pre_reg_id = (int)($_POST['pre_reg_id'] ?? 0);
$save_only  = ($_POST['save_only'] ?? '') === '1';

if (!$pre_reg_id) {
    die(json_encode(['success' => false, 'error' => 'Missing pre_reg_id']));
}

// ── 1. Fetch applicant info ───────────────────────────────────
$stmt = $conn->prepare(
    "SELECT * FROM pre_registrations WHERE id = ? LIMIT 1"
);
$stmt->bind_param('i', $pre_reg_id);
$stmt->execute();
$applicant = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$applicant) {
    die(json_encode(['success' => false, 'error' => 'Applicant not found']));
}

// ── 2. Fetch all 3 required documents with AI results ────────
// Ensure ai_result column exists (safe no-op if already there)
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_result JSON DEFAULT NULL");

$stmt = $conn->prepare(
    "SELECT document_type, file_path, file_name, ai_result, ai_inspected_at, status
     FROM enrollment_documents
     WHERE pre_reg_id = ?
       AND document_type IN ('BirthCertificate','ReportCard','GoodMoral')
     ORDER BY uploaded_at ASC"
);
$stmt->bind_param('i', $pre_reg_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

if (empty($rows)) {
    die(json_encode(['success' => false, 'error' => 'No documents found for this applicant']));
}

// ── 3. Build merged data payload ─────────────────────────────
// Start with applicant fields from pre_registrations table.
// AI-extracted values fill in or confirm these fields per document.
$payload = [
    // ── APPLICANT (from pre_registrations) ──────────────────
    // TODO: add these columns to your pre_registrations table as needed:
    //   first_name, last_name, middle_name
    //   email, phone
    //   course, year_level, branch
    //   address (full home address)
    //   date_of_birth, place_of_birth, sex, nationality
    //   guardian_name, guardian_relationship, guardian_contact
    //   emergency_contact_name, emergency_contact_phone
    //   lrn (Learner Reference Number from DepEd)
    //   ref_number (BCP application reference)
    //   submitted_at (application date)
    'applicant' => [
        'ref_number'   => $applicant['ref_number']   ?? '',
        'first_name'   => $applicant['first_name']   ?? '',
        'last_name'    => $applicant['last_name']     ?? '',
        'middle_name'  => $applicant['middle_name']   ?? '',
        'email'        => $applicant['email']         ?? '',
        'phone'        => $applicant['phone']         ?? '',
        'course'       => $applicant['course']        ?? '',
        'year_level'   => $applicant['year_level']    ?? '',
        'branch'       => $applicant['branch']        ?? '',
        'address'      => $applicant['address']       ?? '',
        'submitted_at' => $applicant['submitted_at']  ?? '',
    ],

    // ── AI-EXTRACTED DATA per document type ─────────────────
    // Populated below from ai_result JSON stored in enrollment_documents
    'birth_certificate' => [],
    'report_card'       => [],
    'good_moral'        => [],

    // ── DOCUMENT STATUSES ────────────────────────────────────
    'statuses' => [],

    // ── GENERATION META ──────────────────────────────────────
    'generated_at' => date('Y-m-d H:i:s'),
    'generated_by' => $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
];

$type_map = [
    'BirthCertificate' => 'birth_certificate',
    'ReportCard'       => 'report_card',
    'GoodMoral'        => 'good_moral',
];

foreach ($rows as $row) {
    $key    = $type_map[$row['document_type']] ?? null;
    if (!$key) continue;

    $ai_raw = $row['ai_result'] ? json_decode($row['ai_result'], true) : null;
    $ext    = $ai_raw['extracted'] ?? [];

    $payload[$key] = array_merge([
        'is_authentic'   => $ai_raw['is_authentic']  ?? null,
        'confidence'     => $ai_raw['confidence']    ?? null,
        'notes'          => $ai_raw['notes']         ?? '',
        'red_flags'      => $ai_raw['red_flags']     ?? [],
        'inspected_at'   => $row['ai_inspected_at']  ?? '',
        'file_name'      => $row['file_name']        ?? '',
    ], $ext);

    $payload['statuses'][$row['document_type']] = $row['status'];
}

// ── 4. Locate the Python script and output directory ─────────
$py_script  = __DIR__ . '/generate_docx.py';
$output_dir = __DIR__ . '/../admin/generated_docs/';

if (!is_dir($output_dir)) {
    mkdir($output_dir, 0755, true);
}

// Sanitise filename using applicant name + pre_reg_id
$safe_name   = preg_replace('/[^a-zA-Z0-9_-]/', '_',
                    trim($applicant['last_name'] . '_' . $applicant['first_name']));
$output_file = $output_dir . 'admission_' . $safe_name . '_' . $pre_reg_id . '.docx';

// ── 5. Write payload JSON to a temp file ─────────────────────
$tmp_json = tempnam(sys_get_temp_dir(), 'docx_payload_') . '.json';
file_put_contents($tmp_json, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// ── 6. Invoke Python ──────────────────────────────────────────
// Try common install locations in order. The system PATH is often
// not inherited by Apache/PHP on Windows, so we check explicit paths.
$python_candidates = [
    'C:\\Program Files\\Python312\\python.exe',   // installed this session
    'C:\\Program Files\\Python311\\python.exe',
    'C:\\Program Files\\Python310\\python.exe',
    'C:\\Python312\\python.exe',
    'C:\\Python311\\python.exe',
    'python3',   // Linux / Mac
    'python',    // fallback
];
$python_bin = 'python';
foreach ($python_candidates as $candidate) {
    if (str_contains($candidate, '\\')) {
        // Absolute path — check file exists
        if (file_exists($candidate)) { $python_bin = $candidate; break; }
    } else {
        // Command name — test via exec
        exec($candidate . ' --version 2>&1', $out, $rc);
        if ($rc === 0) { $python_bin = $candidate; break; }
    }
}

$cmd    = escapeshellcmd($python_bin) . ' '
        . escapeshellarg($py_script)  . ' '
        . escapeshellarg($tmp_json)   . ' '
        . escapeshellarg($output_file);

exec($cmd . ' 2>&1', $py_output, $py_exit);
@unlink($tmp_json);   // clean up temp file

if ($py_exit !== 0 || !file_exists($output_file)) {
    $detail = implode("\n", $py_output);
    die(json_encode([
        'success' => false,
        'error'   => 'Python script failed. Make sure python-docx is installed: pip install python-docx',
        'detail'  => $detail,
    ]));
}

// ── 7. Stream to browser OR save and redirect ─────────────────
if ($save_only) {
    // Store relative path in session for the admin to download later
    $rel = 'generated_docs/' . basename($output_file);
    echo json_encode(['success' => true, 'file' => $rel]);
    exit;
}

// Direct download
$filename = basename($output_file);
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($output_file));
header('Cache-Control: no-cache, must-revalidate');
readfile($output_file);
exit;
