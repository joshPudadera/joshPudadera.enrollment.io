<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/ai_inspect.php';

$uploaded = $_SESSION['uploaded_docs'] ?? [];
if (empty($uploaded)) { header('Location: upload.php'); exit; }

$user_id = $_SESSION['user_id'] ?? null;
$ref_num = $uploaded[0]['ref_number'] ?? null;
$saved   = 0;

// ── Run AI inspection on every uploaded document ─────────────
$ai_inspected = 0;
foreach ($uploaded as &$doc) {
    if (!empty($doc['ai_result'])) continue; // already inspected
    $abs_path = __DIR__ . '/' . $doc['file_path'];
    $result   = ai_inspect_document($abs_path, $doc['type']);
    if ($result['success']) {
        $doc['ai_result'] = $result;
        $ai_inspected++;
    }
    // If AI fails (no key, PDF, etc.) we still proceed — just no result stored
}
unset($doc); // break reference

// ── Save to enrollment_documents if tables exist ─────────────
if (enrollment_tables_exist($conn)) {
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_result JSON DEFAULT NULL");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_inspected_at TIMESTAMP NULL DEFAULT NULL");

    $pre_reg_id = null;
    if ($user_id) {
        // First try to get pre_reg_id from the uploaded docs session (set during upload)
        foreach ($uploaded as $doc) {
            if (!empty($doc['pre_reg_id'])) {
                $pre_reg_id = (int)$doc['pre_reg_id'];
                break;
            }
        }
        // Fallback: look up by user_id
        if (!$pre_reg_id) {
            $r = $conn->prepare("SELECT id FROM pre_registrations WHERE user_id=? ORDER BY submitted_at DESC LIMIT 1");
            $r->bind_param('i', $user_id);
            $r->execute();
            $row = $r->get_result()->fetch_assoc();
            $r->close();
            if ($row) $pre_reg_id = $row['id'];
        }
    }
    // Fallback: match by ref_number in uploaded docs
    if (!$pre_reg_id) {
        $ref_in_docs = $uploaded[0]['ref_number'] ?? null;
        if ($ref_in_docs) {
            $r = $conn->prepare("SELECT id FROM pre_registrations WHERE ref_number=? LIMIT 1");
            $r->bind_param('s', $ref_in_docs);
            $r->execute();
            $row = $r->get_result()->fetch_assoc();
            $r->close();
            if ($row) $pre_reg_id = $row['id'];
        }
    }

    if ($pre_reg_id && $user_id) {
        $type_map = [
            'Form138'=>'Form137','Form137'=>'Form137','GoodMoral'=>'GoodMoral',
            'BirthCertificate'=>'BirthCertificate','IDPhoto'=>'IDPhoto',
            'BarangayClearance'=>'Other','TranscriptOfRecords'=>'Other',
            'HonorableDismissal'=>'Other','NCEEResult'=>'Other',
            'ESCCertificate'=>'Other','Diploma'=>'Other','Other'=>'Other',
        ];

        foreach ($uploaded as $doc) {
            $db_type     = $type_map[$doc['type']] ?? 'Other';
            $fname       = $doc['file_name'];
            $fpath       = $doc['file_path'];
            $fsize       = (int)$doc['file_size'];
            $ai_json     = !empty($doc['ai_result']) ? json_encode($doc['ai_result']) : null;
            $ai_time     = !empty($doc['ai_result']['inspected_at']) ? $doc['ai_result']['inspected_at'] : null;

            $stmt = $conn->prepare(
                "INSERT INTO enrollment_documents
                    (pre_reg_id, user_id, document_type, file_name, file_path, file_size,
                     status, ai_result, ai_inspected_at)
                 VALUES (?,?,?,?,?,?,'Pending',?,?)"
            );
            $stmt->bind_param('iisssiss', $pre_reg_id, $user_id, $db_type, $fname, $fpath, $fsize, $ai_json, $ai_time);
            if ($stmt->execute()) $saved++;
            $stmt->close();
        }
    }
}

$_SESSION['req_submitted'] = [
    'count'        => count($uploaded),
    'ref'          => $ref_num,
    'saved_db'     => $saved,
    'ai_inspected' => $ai_inspected,
    'time'         => date('F d, Y g:i A'),
];

unset($_SESSION['uploaded_docs']);
$conn->close();

header('Location: confirmation.php');
exit;
