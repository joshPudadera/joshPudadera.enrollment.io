<?php
// ============================================================
//  SUBMIT.PHP  (requirements/)
//  Final submission step.
//
//  Since upload.php now saves each document to enrollment_documents
//  immediately on upload, there is nothing left to INSERT here.
//
//  This script:
//   1. Verifies documents exist in the DB for this student.
//   2. Runs a final name-match summary across all BirthCertificate docs.
//   3. Stores a submission summary in the session.
//   4. Redirects to confirmation.php.
// ============================================================
session_start();
require_once __DIR__ . '/../shared/db.php';

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) { header('Location: ../auth/signin.php'); exit; }

if (!enrollment_tables_exist($conn)) {
    header('Location: ../shared/full_setup.php'); exit;
}

// ── Ensure extra columns exist (safe, idempotent) ─────────────
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_name VARCHAR(255) DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_dob DATE DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_sex VARCHAR(20) DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_citizenship VARCHAR(80) DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS name_match_status ENUM('match','mismatch','unverified') NOT NULL DEFAULT 'unverified'");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS name_match_notes TEXT DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS redacted_path VARCHAR(500) DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS redaction_status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending'");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_result JSON DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_inspected_at TIMESTAMP NULL DEFAULT NULL");

// ── Resolve pre_registration ──────────────────────────────────
$pre_reg = null;
$r = $conn->prepare(
    "SELECT id, ref_number, first_name, last_name, status
     FROM pre_registrations
     WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1"
);
$r->bind_param('i', $user_id);
$r->execute();
$pre_reg = $r->get_result()->fetch_assoc();
$r->close();

if (!$pre_reg) {
    // No pre-registration — redirect back
    header('Location: upload.php');
    exit;
}

$pre_reg_id = (int)$pre_reg['id'];
$ref_num    = $pre_reg['ref_number'] ?? '';

// ── Count submitted documents ─────────────────────────────────
$docs = [];
$res  = $conn->query(
    "SELECT id, document_type, file_name, file_path, file_size,
            redaction_status, ai_result, ai_inspected_at,
            extracted_name, extracted_dob, extracted_sex, extracted_citizenship,
            name_match_status, name_match_notes, status, uploaded_at
     FROM enrollment_documents
     WHERE pre_reg_id = $pre_reg_id
     ORDER BY uploaded_at ASC"
);
if ($res) while ($row = $res->fetch_assoc()) $docs[] = $row;

if (empty($docs)) {
    // Nothing uploaded yet — bounce back
    header('Location: upload.php');
    exit;
}

// ── Final name-match summary ──────────────────────────────────
// Re-run name comparison for any Birth Certificate docs that are
// still 'unverified' (e.g. uploaded before the extraction feature existed).
$pre_reg_full_name = trim(($pre_reg['first_name'] ?? '') . ' ' . ($pre_reg['last_name'] ?? ''));

foreach ($docs as &$doc) {
    if ($doc['document_type'] === 'BirthCertificate'
        && ($doc['name_match_status'] ?? 'unverified') === 'unverified'
        && !empty($doc['extracted_name'])
        && $pre_reg_full_name
    ) {
        $norm = fn(string $s): string =>
            strtolower(preg_replace('/[^a-z\s]/i', '', $s));
        $doc_words = array_filter(explode(' ', $norm($doc['extracted_name'])));
        $reg_words = array_filter(explode(' ', $norm($pre_reg_full_name)));
        $matches   = count(array_intersect($doc_words, $reg_words));
        $total     = max(count($reg_words), 1);
        $status    = $matches >= ceil($total * 0.6) ? 'match' : 'mismatch';
        $notes     = "Document: \"{$doc['extracted_name']}\" vs Registration: \"$pre_reg_full_name\"";
        if ($status === 'mismatch') $notes = 'MISMATCH — ' . $notes;

        $upd = $conn->prepare(
            "UPDATE enrollment_documents
             SET name_match_status=?, name_match_notes=?
             WHERE id=?"
        );
        $upd->bind_param('ssi', $status, $notes, $doc['id']);
        $upd->execute();
        $upd->close();

        $doc['name_match_status'] = $status;
        $doc['name_match_notes']  = $notes;
    }
}
unset($doc);

// ── Build submission summary ──────────────────────────────────
$doc_count    = count($docs);
$ai_ok        = count(array_filter($docs, fn($d) => !empty($d['ai_result'])));
$redacted_ok  = count(array_filter($docs, fn($d) => ($d['redaction_status'] ?? '') === 'done'));
$mismatches   = array_filter($docs, fn($d) => ($d['name_match_status'] ?? '') === 'mismatch');
$mismatch_cnt = count($mismatches);

// ── Mark all docs as 'Submitted' (vs Pending) ────────────────
$conn->query(
    "UPDATE enrollment_documents SET status='Pending'
     WHERE pre_reg_id=$pre_reg_id AND status='Pending'"
    // status stays 'Pending' — admin review workflow unchanged
);

$conn->close();

// ── Store summary in session for confirmation page ────────────
$_SESSION['req_submitted'] = [
    'count'         => $doc_count,
    'ref'           => $ref_num,
    'ai_inspected'  => $ai_ok,
    'redacted'      => $redacted_ok,
    'mismatch_count'=> $mismatch_cnt,
    'mismatch_names'=> array_values(array_map(
        fn($d) => $d['name_match_notes'],
        $mismatches
    )),
    'time'          => date('F d, Y g:i A'),
    'pre_reg_id'    => $pre_reg_id,
];

// Clear session upload list (already in DB)
unset($_SESSION['uploaded_docs']);

header('Location: confirmation.php');
exit;
