<?php
session_start();
require_once __DIR__ . '/../shared/db.php';

$current_step = 2;

// ── Ensure extra columns exist ───────────────────────────────
if (enrollment_tables_exist($conn)) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn->query("ALTER TABLE enrollment_documents MODIFY COLUMN user_id INT UNSIGNED NULL DEFAULT NULL");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS redacted_path VARCHAR(500) DEFAULT NULL");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS redaction_status ENUM('pending','done','failed','skipped') NOT NULL DEFAULT 'skipped'");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_result JSON DEFAULT NULL");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_inspected_at TIMESTAMP NULL DEFAULT NULL");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_name VARCHAR(255) DEFAULT NULL");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS name_match_status ENUM('match','mismatch','unverified') NOT NULL DEFAULT 'unverified'");
    $conn->query("ALTER TABLE enrollment_documents MODIFY COLUMN document_type
        ENUM('Form137','BirthCertificate','ReportCard','GoodMoral','IDPhoto','Other') NOT NULL");
    mysqli_report(MYSQLI_REPORT_STRICT);
}

// ─────────────────────────────────────────────────────────────
// Reference number lookup (POST)
// ─────────────────────────────────────────────────────────────
$pre_reg       = null;
$pre_reg_id    = (int)($_SESSION['upload_pre_reg_id'] ?? 0);
$ref_err       = '';
$msg           = '';
$err           = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ref_number'])) {
    $ref_input = strtoupper(trim($_POST['ref_number']));
    $esc = $conn->real_escape_string($ref_input);
    $r = $conn->query(
        "SELECT id, ref_number, first_name, last_name, course, year_level, status
         FROM pre_registrations WHERE UPPER(ref_number)='$esc' LIMIT 1"
    );
    if ($r && $row = $r->fetch_assoc()) {
        $_SESSION['upload_pre_reg_id'] = (int)$row['id'];
        $_SESSION['upload_ref']        = $row['ref_number'];
        $_SESSION['upload_name']       = trim($row['first_name'].' '.$row['last_name']);
        $_SESSION['upload_course']     = $row['course'];
        header('Location: upload.php'); exit;
    } else {
        $ref_err = 'Reference number not found. Please check and try again.';
        unset($_SESSION['upload_pre_reg_id'], $_SESSION['upload_ref'],
              $_SESSION['upload_name'], $_SESSION['upload_course']);
        $pre_reg_id = 0;
    }
}

// Change / clear session
if (isset($_GET['change'])) {
    unset($_SESSION['upload_pre_reg_id'], $_SESSION['upload_ref'],
          $_SESSION['upload_name'], $_SESSION['upload_course']);
    header('Location: upload.php'); exit;
}

// Load pre-reg from session
if ($pre_reg_id) {
    $r = $conn->query(
        "SELECT id, ref_number, first_name, last_name, course, year_level, status
         FROM pre_registrations WHERE id=$pre_reg_id LIMIT 1"
    );
    if ($r) $pre_reg = $r->fetch_assoc();
    if (!$pre_reg) {
        unset($_SESSION['upload_pre_reg_id']);
        $pre_reg_id = 0;
    }
}

// ─────────────────────────────────────────────────────────────
// DELETE a document
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_doc' && $pre_reg_id) {
    $del_id = (int)($_POST['doc_id'] ?? 0);
    if ($del_id) {
        $dr = $conn->query(
            "SELECT file_path, redacted_path, status
             FROM enrollment_documents WHERE id=$del_id AND pre_reg_id=$pre_reg_id LIMIT 1"
        );
        if ($dr && $drow = $dr->fetch_assoc()) {
            if ($drow['status'] !== 'Approved') {
                foreach (['file_path','redacted_path'] as $col) {
                    if (!empty($drow[$col])) {
                        $abs = __DIR__ . '/' . $drow[$col];
                        if (file_exists($abs)) @unlink($abs);
                    }
                }
                $conn->query("DELETE FROM enrollment_documents WHERE id=$del_id AND pre_reg_id=$pre_reg_id");
            }
        }
    }
    header('Location: upload.php'); exit;
}

// ─────────────────────────────────────────────────────────────
// UPLOAD — single file per submit
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['doc_file']) && $pre_reg_id) {
    $doc_type = trim($_POST['document_type'] ?? '');
    $file     = $_FILES['doc_file'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_types = ['BirthCertificate','ReportCard','GoodMoral'];

    if (!$doc_type || !in_array($doc_type, $allowed_types)) {
        $err = 'Please select a document type.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $err = 'Upload error (code '.$file['error'].'). Please try again.';
    } elseif (!in_array($ext, ['jpg','jpeg','png'])) {
        $err = 'Only JPG and PNG images are allowed.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $err = 'File size must not exceed 5 MB.';
    } else {
        $upload_dir = __DIR__ . '/uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $new_name = uniqid('req_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
        $dest     = $upload_dir . $new_name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $err = 'Could not save the file. Check uploads directory permissions.';
        } else {
            // Redaction for birth certs (optional — skip gracefully if not available)
            $redacted_path    = '';
            $redaction_status = 'skipped';
            if ($doc_type === 'BirthCertificate'
                && file_exists(__DIR__ . '/../shared/redact_document.php')) {
                require_once __DIR__ . '/../shared/redact_document.php';
                $redact_result = redact_document($dest, $doc_type);
                if (!empty($redact_result['success'])) {
                    $redacted_path    = $redact_result['redacted_path'] ?? '';
                    $redaction_status = 'done';
                } else {
                    $redaction_status = 'failed';
                }
            }

            $rel_original = 'uploads/' . $new_name;
            $rel_redacted = $redacted_path ? 'uploads/' . basename($redacted_path) : '';
            $fsize        = (int)$file['size'];

            $stmt = $conn->prepare(
                "INSERT INTO enrollment_documents
                    (pre_reg_id, user_id, document_type, file_name, file_path,
                     file_size, status, redacted_path, redaction_status)
                 VALUES (?, NULL, ?, ?, ?, ?, 'Pending', ?, ?)"
            );
            $stmt->bind_param('issssss',
                $pre_reg_id, $doc_type, $file['name'], $rel_original,
                $fsize, $rel_redacted, $redaction_status
            );

            if ($stmt->execute()) {
                $new_doc_id = (int)$conn->insert_id;
                $msg = $file['name'] . ' uploaded successfully.';

                // Auto AI inspection for birth certs
                if ($doc_type === 'BirthCertificate' && $redaction_status === 'done' && $redacted_path
                    && file_exists(__DIR__ . '/../shared/ai_inspect.php')) {
                    require_once __DIR__ . '/../shared/ai_inspect.php';
                    $ai_result = ai_inspect_document(
                        $dest, 'BirthCertificate',
                        __DIR__ . '/uploads/' . basename($redacted_path)
                    );
                    if (!empty($ai_result['success'])) {
                        $ai_json = json_encode($ai_result);
                        $ai_ts   = $ai_result['inspected_at'];
                        $upd = $conn->prepare(
                            "UPDATE enrollment_documents SET ai_result=?, ai_inspected_at=? WHERE id=?"
                        );
                        $upd->bind_param('ssi', $ai_json, $ai_ts, $new_doc_id);
                        $upd->execute();
                        $upd->close();
                    }
                }
                header('Location: upload.php?ok='.urlencode($msg)); exit;
            } else {
                $err = 'Database error: '.$conn->error;
                @unlink($dest);
            }
            $stmt->close();
        }
    }
}

if (!empty($_GET['ok'])) $msg = htmlspecialchars($_GET['ok']);

// ─────────────────────────────────────────────────────────────
// Load current docs
// ─────────────────────────────────────────────────────────────
$db_docs = [];
if ($pre_reg_id) {
    $res = $conn->query(
        "SELECT id, document_type, file_name, file_path, file_size, status, uploaded_at
         FROM enrollment_documents WHERE pre_reg_id=$pre_reg_id ORDER BY uploaded_at ASC"
    );
    if ($res) while ($r = $res->fetch_assoc()) $db_docs[] = $r;
}

// Group docs by type
$docs_by_type = [];
foreach ($db_docs as $d) $docs_by_type[$d['document_type']][] = $d;

$slot_defs = [
    'BirthCertificate' => 'PSA Birth Certificate',
    'ReportCard'       => 'Report Card (Form 138)',
    'GoodMoral'        => 'Good Moral Character Certificate',
];

include __DIR__ . '/header.php';
?>
<style>
/* ── Ref lookup ─────────────────────────────── */
.ref-card {
  max-width:460px;margin:48px auto 24px;
  background:#fff;border-radius:16px;border:1.5px solid #e5e7eb;
  box-shadow:0 4px 24px rgba(0,0,0,.07);padding:40px 32px;text-align:center;
}
.ref-card-icon {
  width:60px;height:60px;border-radius:50%;background:#eff6ff;
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 20px;font-size:1.5rem;color:#2563eb;
}
.ref-card h2 { font-size:1.08rem;font-weight:700;color:#1a1a2e;margin-bottom:6px; }
.ref-card p  { font-size:.82rem;color:#6b7280;margin-bottom:22px;line-height:1.6; }
.ref-input {
  width:100%;height:48px;border:2px solid #d0d7e2;border-radius:10px;
  padding:0 16px;font-size:.95rem;font-family:inherit;outline:none;
  letter-spacing:.06em;text-align:center;text-transform:uppercase;
  transition:border-color .2s;box-sizing:border-box;
}
.ref-input:focus { border-color:#2563eb; }
.btn-find {
  width:100%;margin-top:12px;height:48px;background:#1a3a8c;color:#fff;
  border:none;border-radius:10px;font-size:.92rem;font-weight:700;
  cursor:pointer;font-family:inherit;
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  transition:background .15s;
}
.btn-find:hover { background:#142d6e; }
.ref-err {
  background:#fee2e2;color:#dc2626;border-radius:8px;
  padding:10px 14px;font-size:.82rem;margin-top:14px;text-align:left;
  display:flex;align-items:center;gap:8px;
}
/* ── Upload area ────────────────────────────── */
.who-banner {
  display:flex;align-items:center;gap:12px;
  background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;
  padding:12px 16px;margin-bottom:22px;
}
.who-icon {
  width:36px;height:36px;border-radius:50%;background:#dcfce7;color:#16a34a;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.who-name  { font-weight:700;font-size:.88rem;color:#166534; }
.who-meta  { font-size:.72rem;color:#6b7280; }
/* ── Doc rows ───────────────────────────────── */
.doc-row {
  border:1.5px solid #e5e7eb;border-radius:12px;overflow:hidden;
  margin-bottom:14px;background:#fff;
}
.doc-row.row-approved { border-color:#16a34a; }
.doc-row.row-rejected { border-color:#ef4444; }
.doc-row.row-pending  { border-color:#f59e0b; }
.doc-row-head {
  display:flex;align-items:center;justify-content:space-between;
  padding:10px 16px;
}
.row-approved .doc-row-head { background:#f0fdf4; }
.row-rejected .doc-row-head { background:#fff1f2; }
.row-pending  .doc-row-head { background:#fffbeb; }
.row-empty    .doc-row-head { background:#f8fafc; }
.doc-type-label { font-size:.82rem;font-weight:700;color:#1a1a2e; }
.doc-status-pill {
  font-size:.7rem;font-weight:700;border-radius:20px;padding:2px 10px;white-space:nowrap;
}
.pill-approved { background:#dcfce7;color:#15803d; }
.pill-rejected { background:#fee2e2;color:#dc2626; }
.pill-pending  { background:#fff7ed;color:#d97706; }
.pill-empty    { background:#f3f4f6;color:#9ca3af; }
.doc-row-body  { padding:10px 16px 14px; }
.doc-file-row  {
  display:flex;align-items:center;gap:10px;
  padding:7px 0;border-bottom:1px solid #f3f4f6;
  font-size:.78rem;color:#555;
}
.doc-file-row:last-child { border-bottom:none; }
.doc-thumb {
  width:52px;height:40px;object-fit:cover;border-radius:6px;
  border:1px solid #e5e7eb;cursor:pointer;flex-shrink:0;
}
/* ── Single upload select+file area ─────────── */
.upload-pick {
  display:flex;gap:10px;align-items:stretch;flex-wrap:wrap;margin-bottom:10px;margin-top:12px;
}
.upload-pick select {
  flex:1;min-width:180px;height:44px;border:1.5px solid #d0d7e2;border-radius:8px;
  padding:0 12px;font-size:.85rem;font-family:inherit;outline:none;
  background:#fff;transition:border-color .2s;
}
.upload-pick select:focus { border-color:#2563eb; }
.drop-zone {
  flex:2;min-width:200px;border:2px dashed #d0d7e2;border-radius:8px;
  background:#f8fafc;padding:12px 16px;text-align:center;cursor:pointer;
  transition:border-color .15s,background .15s;font-size:.78rem;color:#aaa;
  display:flex;align-items:center;justify-content:center;gap:8px;
}
.drop-zone:hover,.drop-zone.over { border-color:#2563eb;background:#eff6ff;color:#2563eb; }
.drop-zone.has-file { border-color:#1a3a8c;background:#eff6ff;color:#1a3a8c;font-weight:600; }
.drop-zone i { font-size:1.1rem;flex-shrink:0; }
.btn-upload-submit {
  display:flex;align-items:center;justify-content:center;gap:8px;
  width:100%;height:48px;background:#1a3a8c;color:#fff;border:none;
  border-radius:10px;font-size:.92rem;font-weight:700;cursor:pointer;
  font-family:inherit;transition:background .15s;margin-top:4px;
}
.btn-upload-submit:hover    { background:#142d6e; }
.btn-upload-submit:disabled { background:#94a3b8;cursor:not-allowed; }
.msg-ok  { background:#dcfce7;color:#16a34a;border-radius:8px;padding:12px 16px;font-size:.85rem;margin-bottom:16px;display:flex;align-items:center;gap:8px; }
.msg-err { background:#fee2e2;color:#dc2626;border-radius:8px;padding:12px 16px;font-size:.85rem;margin-bottom:16px;display:flex;align-items:center;gap:8px; }
.btn-remove {
  background:none;border:none;color:#dc2626;cursor:pointer;
  font-size:.72rem;font-family:inherit;padding:0;white-space:nowrap;
  display:inline-flex;align-items:center;gap:3px;
}
.btn-remove:hover { text-decoration:underline; }
</style>

<div class="enroll-body">

<?php if (!$pre_reg_id): ?>
<!-- ── Ref lookup ─────────────────────────── -->
<div class="ref-card">
  <div class="ref-card-icon"><i class="fa-solid fa-file-arrow-up"></i></div>
  <h2>Upload Your Documents</h2>
  <p>Enter your reference number from the admission confirmation to begin uploading.</p>

  <?php if ($ref_err): ?>
  <div class="ref-err"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($ref_err) ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="text" name="ref_number" class="ref-input"
           placeholder="e.g. BCP-IT-20261004-1234"
           oninput="this.value=this.value.toUpperCase()" autocomplete="off" required
           value="<?= htmlspecialchars($_POST['ref_number'] ?? '') ?>"/>
    <button type="submit" class="btn-find">
      <i class="fa-solid fa-magnifying-glass"></i> Find My Application
    </button>
  </form>
  <p style="font-size:.72rem;color:#aaa;margin-top:16px;margin-bottom:0;">
    Your reference number was shown on the confirmation page after completing the admission form.
  </p>
</div>

<?php else: ?>
<!-- ── Upload area ──────────────────────────── -->
<div class="enroll-card">

  <!-- Who this is for -->
  <div class="who-banner">
    <div class="who-icon"><i class="fa-solid fa-user-check"></i></div>
    <div style="flex:1;">
      <div class="who-name"><?= htmlspecialchars($_SESSION['upload_name'] ?? '') ?></div>
      <div class="who-meta">
        Ref: <strong><?= htmlspecialchars($_SESSION['upload_ref'] ?? '') ?></strong>
        &nbsp;·&nbsp;
        <?= htmlspecialchars(preg_replace('/Bachelor of Science in /i','BS ',$pre_reg['course'] ?? '')) ?>
      </div>
    </div>
    <a href="upload.php?change=1"
       style="font-size:.72rem;color:#6b7280;text-decoration:none;border:1px solid #d0d7e2;
              border-radius:6px;padding:5px 12px;white-space:nowrap;">
      <i class="fa-solid fa-rotate"></i> Change
    </a>
  </div>

  <h2 class="enroll-card-title" style="margin-top:0;">Upload Your Documents</h2>
  <p style="text-align:center;font-size:.82rem;color:#666;margin-bottom:20px;line-height:1.6;">
    Upload at least one required document to proceed.<br>
    JPG / PNG only — max 5 MB per file. You may re-upload if a document is <strong style="color:#dc2626;">Rejected</strong>.
  </p>

  <?php if ($msg): ?>
  <div class="msg-ok"><i class="fa-solid fa-circle-check"></i> <?= $msg ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
  <div class="msg-err"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <!-- Three separate upload sections — one per document type -->
  <?php foreach ($slot_defs as $dtype => $dlabel):
    $type_docs = $docs_by_type[$dtype] ?? [];
    $has_apv   = false;
    $latest_st = null;
    foreach ($type_docs as $d) {
        if ($d['status'] === 'Approved') { $has_apv = true; break; }
    }
    if (!empty($type_docs)) $latest_st = end($type_docs)['status'];

    // Border / header colour
    $border = $has_apv ? '#16a34a' : ($latest_st==='Rejected' ? '#ef4444' : ($latest_st==='Pending' ? '#f59e0b' : '#e5e7eb'));
    $hbg    = $has_apv ? '#f0fdf4' : ($latest_st==='Rejected' ? '#fff1f2' : ($latest_st==='Pending' ? '#fffbeb' : '#f8fafc'));
    $pill   = $has_apv ? 'Approved' : ($latest_st==='Rejected' ? 'Rejected' : ($latest_st==='Pending' ? 'Under Review' : 'Not uploaded'));
    $pill_c = $has_apv ? '#15803d'  : ($latest_st==='Rejected' ? '#dc2626' : ($latest_st==='Pending' ? '#d97706'       : '#9ca3af'));
    $pill_bg= $has_apv ? '#dcfce7'  : ($latest_st==='Rejected' ? '#fee2e2' : ($latest_st==='Pending' ? '#fff7ed'       : '#f3f4f6'));
    $form_id = 'form-' . $dtype;
    $file_id = 'file-' . $dtype;
    $drop_id = 'drop-' . $dtype;
    $btn_id  = 'btn-'  . $dtype;
    $prog_id = 'prog-' . $dtype;
  ?>
  <div style="border:1.5px solid <?= $border ?>;border-radius:12px;overflow:hidden;margin-bottom:16px;">
    <!-- Slot header -->
    <div style="display:flex;align-items:center;justify-content:space-between;
                padding:11px 16px;background:<?= $hbg ?>;">
      <span style="font-size:.85rem;font-weight:700;color:#1a1a2e;">
        <i class="fa-solid fa-file-image" style="color:#2563eb;margin-right:6px;font-size:.8rem;"></i>
        <?= htmlspecialchars($dlabel) ?>
      </span>
      <span style="font-size:.7rem;font-weight:700;padding:2px 10px;border-radius:20px;
                   background:<?= $pill_bg ?>;color:<?= $pill_c ?>;">
        <?= $pill ?>
      </span>
    </div>

    <?php if (!empty($type_docs)): ?>
    <!-- Existing uploads -->
    <div style="padding:10px 16px;">
      <?php foreach ($type_docs as $idx => $d):
        $is_img = in_array(strtolower(pathinfo($d['file_name'],PATHINFO_EXTENSION)),['jpg','jpeg','png']);
        $thumb  = 'file.php?path='.urlencode($d['file_path']);
        $st_clr = match($d['status']){ 'Approved'=>'#16a34a','Rejected'=>'#dc2626',default=>'#d97706' };
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:7px 0;
                  border-bottom:1px solid #f3f4f6;font-size:.78rem;color:#555;">
        <?php if ($is_img): ?>
        <a href="<?= $thumb ?>" target="_blank">
          <img src="<?= $thumb ?>" alt="doc"
               style="width:52px;height:40px;object-fit:cover;border-radius:6px;
                      border:1px solid #e5e7eb;cursor:pointer;flex-shrink:0;"
               onerror="this.style.display='none'"/>
        </a>
        <?php endif; ?>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?= htmlspecialchars($d['file_name']) ?>
          </div>
          <div style="font-size:.7rem;color:#aaa;">
            <?= round($d['file_size']/1024,1) ?> KB &middot; <?= date('M d, Y', strtotime($d['uploaded_at'])) ?>
            &middot; <span style="font-weight:700;color:<?= $st_clr ?>;"><?= $d['status'] ?></span>
            &middot; Attempt #<?= $idx+1 ?>
          </div>
        </div>
        <?php if ($d['status'] !== 'Approved'): ?>
        <form method="POST" style="margin:0;" onsubmit="return confirm('Remove this document?')">
          <input type="hidden" name="action" value="delete_doc"/>
          <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>"/>
          <button type="submit" style="background:none;border:none;color:#dc2626;cursor:pointer;
                  font-size:.72rem;font-family:inherit;padding:0;white-space:nowrap;
                  display:inline-flex;align-items:center;gap:3px;">
            <i class="fa-solid fa-trash"></i> Remove
          </button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Upload area for this slot -->
    <?php if (!$has_apv): ?>
    <div style="padding:12px 16px;<?= !empty($type_docs) ? 'border-top:1px solid #f0f2f5;' : '' ?>">
      <?php if ($latest_st === 'Pending'): ?>
      <div style="font-size:.8rem;color:#d97706;text-align:center;padding:6px 0;">
        <i class="fa-solid fa-clock"></i> Under review — you can re-upload once this is rejected.
      </div>
      <?php else: ?>
      <form method="POST" enctype="multipart/form-data" id="<?= $form_id ?>">
        <input type="hidden" name="document_type" value="<?= $dtype ?>"/>
        <!-- Drop zone -->
        <div id="<?= $drop_id ?>"
             onclick="document.getElementById('<?= $file_id ?>').click()"
             style="border:2px dashed #d0d7e2;border-radius:8px;background:#f8fafc;
                    padding:18px 12px;text-align:center;cursor:pointer;margin-bottom:8px;
                    transition:border-color .15s,background .15s;font-size:.78rem;color:#aaa;">
          <i class="fa-solid fa-cloud-arrow-up" id="dicon-<?= $dtype ?>"
             style="font-size:1.4rem;display:block;margin-bottom:5px;"></i>
          <span id="dlabel-<?= $dtype ?>">
            <?= $latest_st === 'Rejected' ? 'Upload a corrected copy' : 'Click or drag file here' ?>
          </span>
          <div style="font-size:.68rem;color:#aaa;margin-top:3px;">JPG, PNG · max 5 MB</div>
        </div>
        <input type="file" id="<?= $file_id ?>" name="doc_file"
               accept=".jpg,.jpeg,.png" style="display:none;"
               onchange="slotFileChosen('<?= $dtype ?>', this)"/>
        <button type="submit" id="<?= $btn_id ?>" disabled
                style="width:100%;height:42px;background:#1a3a8c;color:#fff;border:none;
                       border-radius:8px;font-size:.85rem;font-weight:700;cursor:pointer;
                       display:inline-flex;align-items:center;justify-content:center;gap:7px;
                       font-family:inherit;transition:background .15s;opacity:.5;">
          <i class="fa-solid fa-upload"></i> Upload
        </button>
        <div id="<?= $prog_id ?>" style="display:none;text-align:center;margin-top:8px;
             font-size:.8rem;color:#1e40af;background:#eff6ff;border-radius:7px;padding:9px;">
          <i class="fa-solid fa-spinner fa-spin"></i> Saving…
        </div>
      </form>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="padding:10px 16px;text-align:center;font-size:.8rem;color:#16a34a;font-weight:600;">
      <i class="fa-solid fa-lock"></i> Approved — no further uploads needed.
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <!-- Continue button -->
  <?php if (!empty($db_docs)): ?>
  <div class="enroll-actions" style="margin-top:20px;">
    <a href="status.php" class="btn-proceed">
      View Status &amp; Continue <i class="fa-solid fa-arrow-right"></i>
    </a>
  </div>
  <?php endif; ?>

</div>
<?php endif; ?>
</div><!-- .enroll-body -->

<script>
function slotFileChosen(dtype, input) {
  var dz   = document.getElementById('drop-' + dtype);
  var lbl  = document.getElementById('dlabel-' + dtype);
  var btn  = document.getElementById('btn-' + dtype);
  if (input.files[0]) {
    if (lbl) lbl.textContent = input.files[0].name;
    if (dz) {
      dz.style.borderColor = '#1a3a8c';
      dz.style.background  = '#eff6ff';
    }
    if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
  } else {
    if (dz) { dz.style.borderColor = ''; dz.style.background = ''; }
    if (btn) { btn.disabled = true; btn.style.opacity = '.5'; }
  }
}

// Drag-and-drop per slot
<?php foreach (array_keys($slot_defs) as $dtype): ?>
(function(){
  var dz  = document.getElementById('drop-<?= $dtype ?>');
  var inp = document.getElementById('file-<?= $dtype ?>');
  if (!dz || !inp) return;
  dz.addEventListener('dragover',  function(e){ e.preventDefault(); dz.style.borderColor='#2563eb'; dz.style.background='#eff6ff'; });
  dz.addEventListener('dragleave', function(){  dz.style.borderColor=''; dz.style.background=''; });
  dz.addEventListener('drop', function(e){
    e.preventDefault(); dz.style.borderColor=''; dz.style.background='';
    var f = e.dataTransfer.files[0]; if (!f) return;
    var dt = new DataTransfer(); dt.items.add(f); inp.files = dt.files;
    slotFileChosen('<?= $dtype ?>', inp);
  });
  document.getElementById('form-<?= $dtype ?>').addEventListener('submit', function(){
    var btn = document.getElementById('btn-<?= $dtype ?>');
    var prg = document.getElementById('prog-<?= $dtype ?>');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>'; }
    if (prg) prg.style.display = '';
  });
}());
<?php endforeach; ?>
</script>

</body>
</html>
<?php $conn->close(); ?>
