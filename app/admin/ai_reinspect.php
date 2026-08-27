<?php
// ============================================================
//  AI_REINSPECT.PHP  (admin/)
//  Admin-triggered AI inspection of a stored document.
//  POSTed from document_review.php.
//  Stores results in a JSON column (ai_result) on the
//  enrollment_documents table (added automatically if missing).
// ============================================================
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/ai_inspect.php';
session_start();

if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../auth/signin.php'); exit;
}

$doc_id    = (int)($_POST['doc_id']    ?? 0);
$file_path = trim($_POST['file_path']  ?? '');
$doc_type  = trim($_POST['doc_type']   ?? '');

if (!$doc_id || !$file_path) {
    header('Location: document_review.php?err=missing_params'); exit;
}

// ── Ensure ai_result column exists ───────────────────────────
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_result JSON DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_inspected_at TIMESTAMP NULL DEFAULT NULL");

// ── Run AI inspection ─────────────────────────────────────────
$abs_path = realpath(__DIR__ . '/../requirements/' . $file_path);

if (!$abs_path || !file_exists($abs_path)) {
    header('Location: document_review.php?err=file_not_found'); exit;
}

$result = ai_inspect_document($abs_path, $doc_type);

if (!$result['success']) {
    $err = urlencode($result['error'] ?? 'AI inspection failed');
    header("Location: document_review.php?ai_err=$err"); exit;
}

// ── Save result to DB ─────────────────────────────────────────
$json_result = json_encode($result);
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
