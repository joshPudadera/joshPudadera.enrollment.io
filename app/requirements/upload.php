<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/redact_document.php';

$current_step = 2;
$msg = $err = '';

// ── Ensure extra columns exist (safe no-ops) ─────────────────
if (enrollment_tables_exist($conn)) {
    // Disable strict exception mode so ALTER TABLE errors are silently ignored
    mysqli_report(MYSQLI_REPORT_OFF);

    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_result JSON DEFAULT NULL");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_inspected_at TIMESTAMP NULL DEFAULT NULL");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS redacted_path VARCHAR(500) DEFAULT NULL");
    // Add redaction_status if missing, THEN modify to ensure correct ENUM values
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS redaction_status ENUM('pending','done','failed','skipped') NOT NULL DEFAULT 'pending'");
    $conn->query("ALTER TABLE enrollment_documents MODIFY COLUMN redaction_status ENUM('pending','done','failed','skipped') NOT NULL DEFAULT 'pending'");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_name VARCHAR(255) DEFAULT NULL");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_dob DATE DEFAULT NULL");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_sex VARCHAR(20) DEFAULT NULL");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS extracted_citizenship VARCHAR(80) DEFAULT NULL");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS name_match_status ENUM('match','mismatch','unverified') NOT NULL DEFAULT 'unverified'");
    $conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS name_match_notes TEXT DEFAULT NULL");
    // Add document_type column if missing, then modify to correct ENUM
    $conn->query("ALTER TABLE enrollment_documents MODIFY COLUMN document_type ENUM('Form137','BirthCertificate','ReportCard','GoodMoral','IDPhoto','Other') NOT NULL");
}

// ── Resolve the student's pre-registration ───────────────────
$auto_ref        = '';
$auto_pre_reg_id = 0;
$pre_reg_name    = '';
$ref_err         = '';

// ── Manual reference number lookup (works logged-in or not) ───
// Must be checked BEFORE the user_id block so unauthenticated students
// can still enter their ref number and get redirected to sign in.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['manual_ref'])) {
    $manual_ref = strtoupper(trim($conn->real_escape_string($_POST['manual_ref'])));
    $r_manual = $conn->query(
        "SELECT id, ref_number, first_name, last_name, email, user_id
         FROM pre_registrations
         WHERE UPPER(ref_number)='$manual_ref'
         ORDER BY submitted_at DESC LIMIT 1"
    );
    if ($r_manual && $row_m = $r_manual->fetch_assoc()) {
        if (!empty($_SESSION['user_id'])) {
            // Logged in: link immediately and reload
            $uid_link = (int)$_SESSION['user_id'];
            $conn->query("UPDATE pre_registrations SET user_id=$uid_link WHERE id=" . (int)$row_m['id']);
            header('Location: upload.php?linked=1');
            exit;
        } else {
            // Not logged in: store ref in session and send to sign-in
            // After login the auto-lookup will find it via email or user_id
            $_SESSION['pending_ref']        = $row_m['ref_number'];
            $_SESSION['pending_pre_reg_id'] = (int)$row_m['id'];
            // Redirect to sign-in; after login they'll land on the dashboard
            // which links to requirements/upload.php
            header('Location: ../auth/signin.php?ref_pending=1');
            exit;
        }
    } else {
        $ref_err = 'Reference number not found. Please check and try again.';
    }
}

// ── Link pending ref to account after login ───────────────────
if (!empty($_SESSION['user_id']) && !empty($_SESSION['pending_pre_reg_id'])) {
    $uid_link  = (int)$_SESSION['user_id'];
    $pid_link  = (int)$_SESSION['pending_pre_reg_id'];
    $conn->query("UPDATE pre_registrations SET user_id=$uid_link WHERE id=$pid_link AND (user_id IS NULL OR user_id=0)");
    unset($_SESSION['pending_ref'], $_SESSION['pending_pre_reg_id']);
}

if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];

    // 1. By user_id
    $r = $conn->query(
        "SELECT id, ref_number, first_name, last_name
         FROM pre_registrations WHERE user_id=$uid
         ORDER BY submitted_at DESC LIMIT 1"
    );
    if ($r && $row = $r->fetch_assoc()) {
        $auto_ref        = $row['ref_number']  ?? '';
        $auto_pre_reg_id = (int)$row['id'];
        $pre_reg_name    = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    }

    // 2. By email fallback
    if (!$auto_pre_reg_id) {
        $email_r = $conn->query("SELECT email FROM users WHERE id=$uid LIMIT 1");
        if ($email_r && $email_row = $email_r->fetch_assoc()) {
            $esc_email = $conn->real_escape_string($email_row['email']);
            $r2 = $conn->query(
                "SELECT id, ref_number, first_name, last_name
                 FROM pre_registrations
                 WHERE email='$esc_email' AND (user_id IS NULL OR user_id=0)
                 ORDER BY submitted_at DESC LIMIT 1"
            );
            if ($r2 && $row2 = $r2->fetch_assoc()) {
                $auto_ref        = $row2['ref_number']  ?? '';
                $auto_pre_reg_id = (int)$row2['id'];
                $pre_reg_name    = trim(($row2['first_name'] ?? '') . ' ' . ($row2['last_name'] ?? ''));
                $conn->query("UPDATE pre_registrations SET user_id=$uid WHERE id=$auto_pre_reg_id");
            }
        }
    }
}

// ── Load docs from DB ─────────────────────────────────────────
$db_docs = [];
if ($auto_pre_reg_id && enrollment_tables_exist($conn)) {
    $res = $conn->query(
        "SELECT id, document_type, file_name, file_path, file_size,
                redacted_path, redaction_status, ai_result, status, uploaded_at
         FROM enrollment_documents
         WHERE pre_reg_id = $auto_pre_reg_id
         ORDER BY uploaded_at ASC"
    );
    if ($res) while ($r = $res->fetch_assoc()) $db_docs[] = $r;
}

// ── DELETE ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_db') {
    $del_id = (int)($_POST['doc_db_id'] ?? 0);
    if ($del_id && $auto_pre_reg_id) {
        $dr = $conn->query(
            "SELECT file_path, redacted_path, status FROM enrollment_documents
             WHERE id=$del_id AND pre_reg_id=$auto_pre_reg_id LIMIT 1"
        );
        if ($dr && $drow = $dr->fetch_assoc()) {
            // Only allow deleting non-approved documents
            if ($drow['status'] !== 'Approved') {
                foreach (['file_path','redacted_path'] as $col) {
                    if (!empty($drow[$col])) {
                        $abs = __DIR__ . '/' . $drow[$col];
                        if (file_exists($abs)) @unlink($abs);
                    }
                }
                $conn->query("DELETE FROM enrollment_documents WHERE id=$del_id AND pre_reg_id=$auto_pre_reg_id");
            }
        }
    }
    header('Location: upload.php'); exit;
}

// ── UPLOAD ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['doc_file'])) {
    $doc_type = trim($_POST['document_type'] ?? '');
    $file     = $_FILES['doc_file'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_types = ['BirthCertificate', 'ReportCard', 'GoodMoral'];

    if (!$doc_type || !in_array($doc_type, $allowed_types)) {
        $err = 'Please select a valid document type.';
    } elseif (!in_array($ext, ['jpg','jpeg','png'])) {
        $err = 'Only JPG and PNG images are allowed.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $err = 'File size must not exceed 5 MB.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $err = 'Upload error. Please try again.';
    } elseif (!$auto_pre_reg_id) {
        $err = 'No pre-registration found. Complete pre-registration first.';
    } else {
        $upload_dir = __DIR__ . '/uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $new_name = uniqid('req_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
        $dest     = $upload_dir . $new_name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $err = 'Failed to save the file.';
        } else {
            $redacted_path    = '';
            $redaction_status = 'skipped';
            $redaction_error  = '';

            if ($doc_type === 'BirthCertificate') {
                $redaction_status = 'failed';
                $redact_result    = redact_document($dest, $doc_type);
                if ($redact_result['success']) {
                    $redacted_path    = $redact_result['redacted_path'];
                    $redaction_status = 'done';
                } else {
                    $redaction_error = $redact_result['error'] ?? 'Unknown redaction error';
                }
            }

            $rel_original = 'uploads/' . $new_name;
            $rel_redacted = $redacted_path ? 'uploads/' . basename($redacted_path) : '';
            $user_id_val  = $_SESSION['user_id'] ?? 0;

            $stmt = $conn->prepare(
                "INSERT INTO enrollment_documents
                    (pre_reg_id, user_id, document_type, file_name, file_path, file_size,
                     status, redacted_path, redaction_status)
                 VALUES (?,?,?,?,?,?,'Pending',?,?)"
            );
            $fsize = (int)$file['size'];
            $stmt->bind_param('iissssss',
                $auto_pre_reg_id, $user_id_val,
                $doc_type, $file['name'], $rel_original, $fsize,
                $rel_redacted, $redaction_status
            );

            if ($stmt->execute()) {
                $new_doc_id = (int)$conn->insert_id;
                $msg = $file['name'] . ' uploaded successfully.';

                // ── Auto AI inspect Birth Certificate immediately ──
                if ($doc_type === 'BirthCertificate' && $redaction_status === 'done' && $redacted_path) {
                    require_once __DIR__ . '/../shared/ai_inspect.php';
                    $abs_original = $dest;
                    $abs_redacted = __DIR__ . '/uploads/' . basename($redacted_path);
                    $ai_result = ai_inspect_document($abs_original, 'BirthCertificate', $abs_redacted);
                    if ($ai_result['success']) {
                        $ai_json = json_encode($ai_result);
                        $ai_ts   = $ai_result['inspected_at'];
                        $upd = $conn->prepare(
                            "UPDATE enrollment_documents SET ai_result=?, ai_inspected_at=? WHERE id=?"
                        );
                        $upd->bind_param('ssi', $ai_json, $ai_ts, $new_doc_id);
                        $upd->execute();
                        $upd->close();
                        $msg .= ' AI inspection complete.';
                    }
                }
            } else {
                $err = 'Database save failed: ' . $conn->error;
                @unlink($dest);
                if ($redacted_path && file_exists($redacted_path)) @unlink($redacted_path);
            }
            $stmt->close();
            header('Location: upload.php?msg=' . urlencode($msg)); exit;
        }
    }
}

// Re-load after POST
if ($auto_pre_reg_id && enrollment_tables_exist($conn)) {
    $res = $conn->query(
        "SELECT id, document_type, file_name, file_path, file_size,
                redacted_path, redaction_status, ai_result, status, uploaded_at
         FROM enrollment_documents
         WHERE pre_reg_id = $auto_pre_reg_id ORDER BY uploaded_at ASC"
    );
    $db_docs = [];
    if ($res) while ($r = $res->fetch_assoc()) $db_docs[] = $r;
}

if (empty($msg) && !empty($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

$doc_type_labels = [
    'BirthCertificate' => 'PSA Birth Certificate',
    'ReportCard'       => 'Report Card (Form 138)',
    'GoodMoral'        => 'Certificate of Good Moral Character',
];

include __DIR__ . '/header.php';
?>

<style>
.doc-slots  { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:18px; margin-bottom:28px; }
.doc-slot   { border:2px solid #e5e7eb; border-radius:12px; background:#fff; overflow:hidden; }
.doc-slot.has-approved { border-color:#16a34a; }
.doc-slot.has-rejected { border-color:#ef4444; }
.doc-slot.has-pending  { border-color:#f59e0b; }
.slot-head  { padding:14px 16px 10px; display:flex; align-items:flex-start; gap:10px; }
.slot-num   { width:28px; height:28px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:700; background:#e8edf4; color:#6b7280; }
.has-approved .slot-num { background:#dcfce7; color:#16a34a; }
.has-rejected .slot-num { background:#fee2e2; color:#dc2626; }
.has-pending  .slot-num { background:#fff7ed; color:#d97706; }
.slot-label { font-size:.88rem; font-weight:700; color:#1a1a2e; }
.slot-sub   { font-size:.72rem; color:#888; margin-top:2px; }
.slot-body  { padding:0 16px 16px; }

.upload-item { border:1.5px solid #e5e7eb; border-radius:8px; overflow:hidden; margin-bottom:10px; }
.upload-item.status-approved { border-color:#16a34a; }
.upload-item.status-rejected { border-color:#ef4444; }
.upload-item.status-pending  { border-color:#f59e0b; }
.upload-item-head { display:flex; align-items:center; justify-content:space-between; padding:8px 12px; font-size:.75rem; font-weight:700; }
.status-approved .upload-item-head { background:#f0fdf4; color:#15803d; }
.status-rejected .upload-item-head { background:#fff1f2; color:#dc2626; }
.status-pending  .upload-item-head { background:#fffbeb; color:#d97706; }
.upload-item-thumb { width:100%; max-height:90px; object-fit:cover; display:block; cursor:pointer; }
.upload-item-meta  { padding:6px 12px 8px; font-size:.7rem; color:#888; display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; }

.slot-drop {
  border:2px dashed #d0d7e2; border-radius:8px; background:#f8fafc;
  padding:16px 12px; text-align:center; cursor:pointer;
  transition:border-color .15s,background .15s; font-size:.78rem; color:#aaa; margin-top:10px;
}
.slot-drop:hover, .slot-drop.dragover { border-color:#2563eb; background:#eff6ff; color:#2563eb; }
.slot-drop.has-file { border-color:#1a3a8c; background:#eff6ff; color:#1a3a8c; }
.slot-drop i { font-size:1.3rem; display:block; margin-bottom:5px; }
.btn-slot-upload {
  width:100%; margin-top:8px; height:38px; background:#1a3a8c; color:#fff; border:none;
  border-radius:8px; font-size:.82rem; font-weight:600; cursor:pointer; font-family:inherit;
  display:inline-flex; align-items:center; justify-content:center; gap:6px; transition:background .15s;
}
.btn-slot-upload:hover    { background:#142d6e; }
.btn-slot-upload:disabled { background:#94a3b8; cursor:not-allowed; }
.btn-slot-delete {
  background:none; border:none; color:#dc2626; cursor:pointer;
  font-size:.75rem; padding:0; font-family:inherit; display:inline-flex; align-items:center; gap:3px;
}
.btn-slot-delete:hover { text-decoration:underline; }
</style>

<div class="enroll-body">
<div class="enroll-card">

  <h2 class="enroll-card-title">Upload Your Documents</h2>
  <p style="text-align:center;font-size:.82rem;color:#666;margin-bottom:6px;">
    Upload each required document. JPG and PNG only — max 5 MB each.<br>
    Once submitted, wait for the registrar's review. You may only re-upload if a document is <strong style="color:#dc2626;">Rejected</strong>.
  </p>

  <?php if ($auto_ref): ?>
  <div style="text-align:center;font-size:.78rem;color:#16a34a;font-weight:600;margin-bottom:18px;">
    Reference: <strong><?= htmlspecialchars($auto_ref) ?></strong>
  </div>
  <?php elseif (!$auto_pre_reg_id): ?>
  <!-- Reference number lookup -->
  <div style="background:#fff7ed;border:1.5px solid #fcd34d;border-radius:10px;
              padding:20px;margin-bottom:22px;">
    <div style="font-size:.9rem;font-weight:700;color:#92400e;margin-bottom:6px;">
      <i class="fa-solid fa-triangle-exclamation"></i> Pre-registration not found
    </div>
    <p style="font-size:.82rem;color:#78350f;margin-bottom:14px;line-height:1.6;">
      We couldn't automatically find your application. Enter your reference number from the admission confirmation page to link your account.
    </p>
    <?php if (!empty($ref_err)): ?>
    <div style="background:#fee2e2;color:#dc2626;border-radius:8px;padding:9px 14px;font-size:.82rem;margin-bottom:12px;">
      <i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($ref_err) ?>
    </div>
    <?php endif; ?>
    <form method="POST" style="display:flex;gap:10px;align-items:stretch;flex-wrap:wrap;">
      <input type="text" name="manual_ref"
             placeholder="e.g. BCP-IT-20261004-1234"
             style="flex:1;min-width:220px;height:42px;border:1.5px solid #d0d7e2;border-radius:8px;
                    padding:0 14px;font-size:.88rem;font-family:inherit;outline:none;
                    text-transform:uppercase;"
             oninput="this.value=this.value.toUpperCase()"
             required/>
      <button type="submit" style="height:42px;padding:0 20px;background:#1a3a8c;color:#fff;
              border:none;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;
              font-family:inherit;white-space:nowrap;">
        <i class="fa-solid fa-link"></i> Link Application
      </button>
    </form>
    <p style="font-size:.74rem;color:#aaa;margin-top:10px;">
      Your reference number was shown on the confirmation page after completing the admission form.
    </p>
  </div>
  <?php endif; ?>

  <?php if (!empty($_GET['linked'])): ?>
  <div class="auth-success" style="margin-bottom:16px;">
    <i class="fa-solid fa-circle-check"></i> Application linked! You can now upload your documents.
  </div>
  <?php endif; ?>

  <?php if (!empty($msg)): ?>
  <div class="auth-success" style="margin-bottom:16px;"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
  <div class="auth-error" style="margin-bottom:16px;"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <div class="doc-slots">
  <?php if (!$auto_pre_reg_id): ?>
  <!-- Nothing to show until reference is linked -->
  <?php else: ?>
  <?php
  $slot_defs = [
    'BirthCertificate' => ['PSA Birth Certificate',          'PSA-authenticated copy. JPG/PNG only.'],
    'ReportCard'       => ['Report Card (Form 138)',          'Most recent from previous school.'],
    'GoodMoral'        => ['Good Moral Character Certificate','Issued by previous school or LGU.'],
  ];
  $slot_num = 0;

  foreach ($slot_defs as $dtype => [$dlabel, $dsub]):
    $slot_num++;
    $type_docs  = array_values(array_filter($db_docs, fn($d) => $d['document_type'] === $dtype));
    $latest     = !empty($type_docs) ? $type_docs[count($type_docs)-1] : null;
    $has_apv    = (bool)array_filter($type_docs, fn($d) => $d['status'] === 'Approved');
    $has_rej    = !$has_apv && $latest && $latest['status'] === 'Rejected';
    $has_pend   = !$has_apv && $latest && $latest['status'] === 'Pending';
    $slot_class = $has_apv ? 'has-approved' : ($has_rej ? 'has-rejected' : ($has_pend ? 'has-pending' : ''));
    $num_disp   = $has_apv ? '✓' : (count($type_docs) > 0 ? count($type_docs) : $slot_num);
  ?>
  <div class="doc-slot <?= $slot_class ?>">
    <div class="slot-head">
      <div class="slot-num"><?= $num_disp ?></div>
      <div style="flex:1;min-width:0;">
        <div class="slot-label"><?= htmlspecialchars($dlabel) ?></div>
        <div class="slot-sub"><?= htmlspecialchars($dsub) ?></div>
        <?php if (count($type_docs) > 0): ?>
        <div style="margin-top:6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
          <?php if ($has_apv): ?>
          <span style="background:#dcfce7;color:#16a34a;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:20px;">
            ✓ Approved
          </span>
          <?php elseif ($has_rej): ?>
          <span style="background:#fee2e2;color:#dc2626;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:20px;">
            ✗ Rejected — please upload a new copy
          </span>
          <?php else: ?>
          <span style="background:#fff7ed;color:#d97706;font-size:.7rem;font-weight:700;padding:2px 8px;border-radius:20px;">
            ⏳ Pending review
          </span>
          <?php endif; ?>
          <?php if (count($type_docs) > 1): ?>
          <span style="font-size:.7rem;color:#888;"><?= count($type_docs) ?> attempt<?= count($type_docs)!==1?'s':'' ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="slot-body">
      <?php foreach ($type_docs as $idx => $udoc):
        $thumb_url = 'file.php?path=' . urlencode($udoc['file_path']);
        $ext_up    = strtolower(pathinfo($udoc['file_name'], PATHINFO_EXTENSION));
        $is_img    = in_array($ext_up, ['jpg','jpeg','png']);
        $doc_st    = $udoc['status'];
        $st_cls    = match($doc_st){ 'Approved'=>'status-approved','Rejected'=>'status-rejected',default=>'status-pending' };
        $st_icon   = match($doc_st){ 'Approved'=>'✓','Rejected'=>'✗',default=>'⏳' };
      ?>
      <div class="upload-item <?= $st_cls ?>">
        <div class="upload-item-head">
          <span><?= $st_icon ?> <?= htmlspecialchars($doc_st) ?></span>
          <span style="font-weight:400;opacity:.75;">Attempt #<?= $idx+1 ?></span>
        </div>
        <?php if ($is_img): ?>
        <a href="<?= $thumb_url ?>" target="_blank">
          <img src="<?= $thumb_url ?>" alt="document" class="upload-item-thumb"
               onerror="this.style.display='none'"/>
        </a>
        <?php endif; ?>
        <div class="upload-item-meta">
          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px;">
            <?= htmlspecialchars($udoc['file_name']) ?>
          </span>
          <span><?= round($udoc['file_size']/1024,1) ?> KB · <?= date('M d, Y', strtotime($udoc['uploaded_at'])) ?></span>
          <?php if ($doc_st !== 'Approved'): ?>
          <form method="POST" class="del-form" data-name="<?= htmlspecialchars($udoc['file_name']) ?>" style="margin:0;">
            <input type="hidden" name="action"    value="delete_db"/>
            <input type="hidden" name="doc_db_id" value="<?= $udoc['id'] ?>"/>
            <button type="submit" class="btn-slot-delete">
              <i class="fa-solid fa-trash"></i> Remove
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if (!$has_apv): ?>
      <?php if ($has_rej || empty($type_docs)): ?>
      <form method="POST" enctype="multipart/form-data" class="slot-upload-form" id="form-<?= $dtype ?>">
        <input type="hidden" name="document_type" value="<?= $dtype ?>"/>
        <div class="slot-drop" id="drop-<?= $dtype ?>"
             onclick="document.getElementById('file-<?= $dtype ?>').click()">
          <i class="fa-solid fa-cloud-arrow-up"></i>
          <span id="droplabel-<?= $dtype ?>">
            <?= $has_rej ? 'Upload a corrected copy' : 'Click or drag file here' ?>
          </span>
          <div style="font-size:.68rem;color:#aaa;margin-top:3px;">JPG, PNG · max 5 MB</div>
        </div>
        <input type="file" id="file-<?= $dtype ?>" name="doc_file"
               accept=".jpg,.jpeg,.png" style="display:none;"
               onchange="previewFile('<?= $dtype ?>', this)"/>
        <button type="submit" class="btn-slot-upload" id="btn-<?= $dtype ?>" disabled>
          <i class="fa-solid fa-upload"></i> Upload
        </button>
        <div id="prog-<?= $dtype ?>" style="display:none;margin-top:8px;background:#eff6ff;
             border-radius:6px;padding:8px 12px;font-size:.75rem;color:#1e40af;">
          <i class="fa-solid fa-spinner fa-spin"></i> Saving…
        </div>
      </form>
      <?php elseif ($has_pend): ?>
      <!-- Pending — locked, waiting for admin review -->
      <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:8px;
                  padding:12px 14px;margin-top:10px;text-align:center;font-size:.82rem;color:#92400e;">
        <i class="fa-solid fa-clock"></i>
        Your document is <strong>under review</strong> by the registrar.<br>
        <span style="font-size:.75rem;opacity:.8;">You can upload a new copy only if this is rejected.</span>
      </div>
      <?php else: ?>
      <div style="text-align:center;padding:10px 0 2px;font-size:.8rem;color:#16a34a;font-weight:600;">
        <i class="fa-solid fa-lock"></i> Approved — no changes needed.
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; // end if $auto_pre_reg_id ?>
  </div>

  <?php if (count($db_docs) > 0): ?>
  <div class="enroll-actions" style="margin-top:8px;">
    <a href="status.php" class="btn-proceed">
      Continue
      <span style="font-size:.85em;opacity:.8;">(<?= count($db_docs) ?> file<?= count($db_docs)!==1?'s':'' ?> uploaded)</span>
    </a>
  </div>
  <?php endif; ?>

</div>
</div>

<script>
function previewFile(dtype, input) {
  var drop = document.getElementById('drop-' + dtype);
  var lbl  = document.getElementById('droplabel-' + dtype);
  var btn  = document.getElementById('btn-' + dtype);
  if (input.files[0]) {
    if (lbl) lbl.textContent = input.files[0].name;
    if (drop) drop.classList.add('has-file');
    if (btn)  btn.disabled = false;
  }
}
document.querySelectorAll('.slot-drop').forEach(function(zone) {
  var dtype = zone.id.replace('drop-', '');
  zone.addEventListener('dragover',  function(e){ e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', function(){ zone.classList.remove('dragover'); });
  zone.addEventListener('drop', function(e){
    e.preventDefault(); zone.classList.remove('dragover');
    var f = e.dataTransfer.files[0]; if (!f) return;
    var inp = document.getElementById('file-' + dtype);
    var dt = new DataTransfer(); dt.items.add(f); inp.files = dt.files;
    previewFile(dtype, inp);
  });
});
document.querySelectorAll('.slot-upload-form').forEach(function(form) {
  form.addEventListener('submit', function() {
    var dtype = form.id.replace('form-', '');
    var btn  = document.getElementById('btn-' + dtype);
    var prog = document.getElementById('prog-' + dtype);
    if (btn)  { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>'; }
    if (prog) prog.style.display = '';
  });
});
document.querySelectorAll('.del-form').forEach(function(form) {
  form.addEventListener('submit', function(e) {
    if (!confirm('Remove "' + (form.dataset.name||'this document') + '"?')) e.preventDefault();
  });
});
</script>
