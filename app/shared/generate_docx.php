<?php
// ============================================================
//  GENERATE_DOCX.PHP  (shared/)
//  Generates a Word document compiled from ALL admission form
//  data stored in pre_registrations for a given applicant.
//  Does NOT rely on AI-extracted data.
//
//  POST params:
//    pre_reg_id  — integer ID of the pre_registration row
// ============================================================
session_start();
require_once __DIR__ . '/db.php';

// Return errors as JSON so JS can display them
function fail(string $msg): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if (empty($_SESSION['user_id']) || !is_admin_or_staff()) {
    fail('Unauthorized');
}

$pre_reg_id = (int)($_POST['pre_reg_id'] ?? 0);
if (!$pre_reg_id) fail('Missing pre_reg_id');

// ── Ensure all extra columns exist before SELECT ─────────────
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS middle_name VARCHAR(100) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS suffix VARCHAR(20) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS sex VARCHAR(20) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS civil_status VARCHAR(30) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS nationality VARCHAR(80) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS religion VARCHAR(100) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS place_of_birth VARCHAR(200) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS address VARCHAR(300) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS grad_year VARCHAR(20) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS emergency_name VARCHAR(150) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS emergency_relation VARCHAR(80) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS emergency_phone VARCHAR(30) DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS branch VARCHAR(100) DEFAULT NULL");

// ── Fetch applicant ───────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM pre_registrations WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $pre_reg_id);
$stmt->execute();
$applicant = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$applicant) fail('Applicant not found (ID: ' . $pre_reg_id . ')');

// ── Fetch submitted documents (any type, for reference) ───────
$docs = [];
$dres = $conn->prepare(
    "SELECT document_type, file_name, status, ai_result, ai_inspected_at, uploaded_at
     FROM enrollment_documents WHERE pre_reg_id = ? ORDER BY uploaded_at ASC"
);
$dres->bind_param('i', $pre_reg_id);
$dres->execute();
$doc_rows = $dres->get_result()->fetch_all(MYSQLI_ASSOC);
$dres->close();
// Note: $conn stays open — we still need it to save the generated_documents record later

// ── Build payload ─────────────────────────────────────────────
$payload = [
    'applicant' => [
        // Core identity
        'ref_number'          => $applicant['ref_number']          ?? '',
        'first_name'          => $applicant['first_name']          ?? '',
        'last_name'           => $applicant['last_name']           ?? '',
        'middle_name'         => $applicant['middle_name']         ?? '',
        'suffix'              => $applicant['suffix']              ?? '',
        // Personal details
        'birthday'            => $applicant['birthday']            ?? '',
        'sex'                 => $applicant['sex']                 ?? '',
        'civil_status'        => $applicant['civil_status']        ?? '',
        'nationality'         => $applicant['nationality']         ?? 'Filipino',
        'religion'            => $applicant['religion']            ?? '',
        'place_of_birth'      => $applicant['place_of_birth']      ?? '',
        // Contact
        'email'               => $applicant['email']               ?? '',
        'phone'               => $applicant['phone']               ?? '',
        'address'             => $applicant['address']             ?? '',
        // Academic
        'course'              => $applicant['course']              ?? '',
        'year_level'          => $applicant['year_level']          ?? '',
        'branch'              => $applicant['branch']              ?? '',
        'applicant_type'      => $applicant['applicant_type']      ?? '',
        'transfer_year_level' => $applicant['transfer_year_level'] ?? '',
        'prev_school'         => $applicant['prev_school']         ?? '',
        'grad_year'           => $applicant['grad_year']           ?? '',
        // Emergency
        'emergency_name'      => $applicant['emergency_name']      ?? '',
        'emergency_relation'  => $applicant['emergency_relation']  ?? '',
        'emergency_phone'     => $applicant['emergency_phone']     ?? '',
        // Meta
        'submitted_at'        => $applicant['submitted_at']        ?? '',
        'status'              => $applicant['status']              ?? '',
    ],
    'documents'     => $doc_rows,   // all uploaded docs with their AI results
    'generated_at'  => date('Y-m-d H:i:s'),
    'generated_by'  => trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
];

// ── Locate Python + script ────────────────────────────────────
$py_script  = __DIR__ . '/generate_docx.py';
$output_dir = __DIR__ . '/../admin/generated_docs/';
if (!is_dir($output_dir)) mkdir($output_dir, 0755, true);

$safe_name   = preg_replace('/[^a-zA-Z0-9_-]/', '_',
                    trim(($applicant['last_name'] ?? 'Unknown') . '_' . ($applicant['first_name'] ?? '')));
$output_file = $output_dir . 'admission_' . $safe_name . '_' . $pre_reg_id . '.docx';

$tmp_json = tempnam(sys_get_temp_dir(), 'docx_') . '.json';
file_put_contents($tmp_json, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$python_candidates = [
    'C:\\Program Files\\Python312\\python.exe',
    'C:\\Program Files\\Python311\\python.exe',
    'C:\\Program Files\\Python310\\python.exe',
    'C:\\Python312\\python.exe',
    'python3', 'python',
];
$python_bin = 'python';
foreach ($python_candidates as $c) {
    if (str_contains($c, '\\')) {
        if (file_exists($c)) { $python_bin = $c; break; }
    } else {
        $ph = [['pipe','r'],['pipe','w'],['pipe','w']];
        $pr = proc_open([$c, '--version'], $ph, $pp);
        if (is_resource($pr)) { $rc = proc_close($pr); if ($rc === 0) { $python_bin = $c; break; } }
    }
}

$descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
$proc        = proc_open([$python_bin, $py_script, $tmp_json, $output_file], $descriptors, $pipes);

if (!is_resource($proc)) {
    @unlink($tmp_json);
    fail('Failed to start Python process.');
}

fclose($pipes[0]);
$py_out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$py_exit = proc_close($proc);
@unlink($tmp_json);   // delete AFTER Python has finished reading it

if ($py_exit !== 0 || !file_exists($output_file)) {
    fail('Document generation failed: ' . trim($py_out));
}

// ── Save record to generated_documents table ──────────────────
@$conn->query("CREATE TABLE IF NOT EXISTS generated_documents (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pre_reg_id   INT UNSIGNED NOT NULL,
    file_name    VARCHAR(300) NOT NULL,
    file_path    VARCHAR(500) NOT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    generated_by VARCHAR(150) DEFAULT NULL,
    INDEX idx_pre_reg (pre_reg_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
@$conn->query("ALTER TABLE generated_documents ADD UNIQUE INDEX IF NOT EXISTS uq_pre_reg (pre_reg_id)");

$rel_path    = 'admin/generated_docs/' . basename($output_file);
$gen_by      = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$fname_store = basename($output_file);

// Upsert — replace if re-generated for same applicant
$ins = $conn->prepare(
    "INSERT INTO generated_documents (pre_reg_id, file_name, file_path, generated_by)
     VALUES (?,?,?,?)
     ON DUPLICATE KEY UPDATE
       file_name=VALUES(file_name), file_path=VALUES(file_path),
       generated_at=NOW(), generated_by=VALUES(generated_by)"
);
// Add unique key if not present
@$conn->query("ALTER TABLE generated_documents ADD UNIQUE INDEX IF NOT EXISTS uq_pre_reg (pre_reg_id)");
$ins->bind_param('isss', $pre_reg_id, $fname_store, $rel_path, $gen_by);
$ins->execute();
$ins->close();
$conn->close();

// ── Stream as download ────────────────────────────────────────
$filename = basename($output_file);
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($output_file));
header('Cache-Control: no-cache, must-revalidate');
readfile($output_file);
exit;
