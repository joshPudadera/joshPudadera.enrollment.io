<?php
// ============================================================
//  AI_REINSPECT.PHP  (admin/)
//  Admin-triggered AI inspection of a stored document.
//  POSTed from document_review.php and document_redaction.php.
//  Stores results in ai_result JSON on enrollment_documents.
//
//  Only BirthCertificate documents are processed — other types
//  are skipped with an informational redirect.
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/ai_inspect.php';
session_start();

if (empty($_SESSION['user_id']) || !is_admin_or_staff()) {
    header('Location: ../auth/signin.php'); exit;
}

$doc_id    = (int)($_POST['doc_id']    ?? 0);
$file_path = trim($_POST['file_path']  ?? '');
$doc_type  = trim($_POST['doc_type']   ?? '');

if (!$doc_id || !$file_path) {
    header('Location: document_review.php?err=missing_params'); exit;
}

// ── Gate: only Birth Certificates are AI-processed ───────────
if ($doc_type !== 'BirthCertificate') {
    header('Location: document_review.php?ai_skip=' . $doc_id); exit;
}

// ── Ensure ai_result columns exist ───────────────────────────
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_result JSON DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_inspected_at TIMESTAMP NULL DEFAULT NULL");

// ── Fetch the redacted path from DB ──────────────────────────
$row = $conn->query(
    "SELECT redacted_path, redaction_status FROM enrollment_documents WHERE id=$doc_id LIMIT 1"
)->fetch_assoc();

if (!$row) {
    header('Location: document_review.php?err=file_not_found'); exit;
}

if ($row['redaction_status'] !== 'done' || empty($row['redacted_path'])) {
    $err = urlencode('Document must be redacted before AI inspection. Run redaction first.');
    header("Location: document_review.php?ai_err=$err"); exit;
}

// ── Resolve absolute paths ────────────────────────────────────
$abs_path     = realpath(__DIR__ . '/../requirements/' . $file_path);
$abs_redacted = realpath(__DIR__ . '/../requirements/' . $row['redacted_path']);

if (!$abs_path || !file_exists($abs_path)) {
    header('Location: document_review.php?err=file_not_found'); exit;
}
if (!$abs_redacted || !file_exists($abs_redacted)) {
    $err = urlencode('Redacted copy not found on disk. Re-redact the document first.');
    header("Location: document_review.php?ai_err=$err"); exit;
}

// ── Run AI inspection ─────────────────────────────────────────
$result = ai_inspect_document($abs_path, $doc_type, $abs_redacted);

if (!$result['success']) {
    $err = urlencode($result['error'] ?? 'AI inspection failed');
    header("Location: document_review.php?ai_err=$err"); exit;
}

// ── Save result to DB ─────────────────────────────────────────
$json_result  = json_encode($result);
$inspected_at = $result['inspected_at'];

$stmt = $conn->prepare(
    "UPDATE enrollment_documents
     SET ai_result=?, ai_inspected_at=?
     WHERE id=?"
);
$stmt->bind_param('ssi', $json_result, $inspected_at, $doc_id);
$stmt->execute();
$stmt->close();
$conn->close();

header('Location: document_review.php?ai_done=' . $doc_id);
exit;
