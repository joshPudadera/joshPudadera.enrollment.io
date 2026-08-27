<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))     { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'student') { header('Location: ../admin_dashboard/dashboard.php'); exit; }

$uid          = (int)$_SESSION['user_id'];
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$msg = $err   = '';

// ── Auto-load ref_number from student's latest pre-registration ──
$auto_ref        = '';
$auto_pre_reg_id = 0;
$r = $conn->query("SELECT id, ref_number FROM pre_registrations WHERE user_id=$uid ORDER BY submitted_at DESC LIMIT 1");
if ($r && $row = $r->fetch_assoc()) {
    $auto_ref        = $row['ref_number'] ?? '';
    $auto_pre_reg_id = (int)$row['id'];
}

// ── DELETE uploaded file from session ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $idx = (int)($_POST['delete_index'] ?? -1);
    if (isset($_SESSION['uploaded_docs'][$idx])) {
        $fp = __DIR__ . '/../requirements/' . $_SESSION['uploaded_docs'][$idx]['file_path'];
        if (file_exists($fp)) @unlink($fp);
        array_splice($_SESSION['uploaded_docs'], $idx, 1);
        $msg = 'Document removed.';
    }
}

// ── SUBMIT all docs (run AI + save to DB) ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_all') {
    require_once __DIR__ . '/../shared/ai_inspect.php';

    $uploaded_submit = $_SESSION['uploaded_docs'] ?? [];
    if (!empty($uploaded_submit)) {
        // Run AI inspection on every uploaded document
        $ai_inspected = 0;
        foreach ($uploaded_submit as &$doc) {
            if (!empty($doc['ai_result'])) continue;
            $abs_path = __DIR__ . '/../requirements/' . $doc['file_path'];
            $result   = ai_inspect_document($abs_path, $doc['type']);
            if ($result['success']) {
                $doc['ai_result'] = $result;
                $ai_inspected++;
            }
        }
        unset($doc);
        $_SESSION['uploaded_docs'] = $uploaded_submit;

        // Save to enrollment_documents
        $saved = 0;
        if (enrollment_tables_exist($conn)) {
            @$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_result JSON DEFAULT NULL");
            @$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_inspected_at TIMESTAMP NULL DEFAULT NULL");

            $pre_reg_id = null;
            foreach ($uploaded_submit as $doc) {
                if (!empty($doc['pre_reg_id'])) { $pre_reg_id = (int)$doc['pre_reg_id']; break; }
            }
            if (!$pre_reg_id) {
                $r = $conn->prepare("SELECT id FROM pre_registrations WHERE user_id=? ORDER BY submitted_at DESC LIMIT 1");
                $r->bind_param('i', $uid);
                $r->execute();
                $row = $r->get_result()->fetch_assoc();
                $r->close();
                if ($row) $pre_reg_id = $row['id'];
            }

            $type_map = [
                'Form138'=>'Form137','Form137'=>'Form137','GoodMoral'=>'GoodMoral',
                'BirthCertificate'=>'BirthCertificate','IDPhoto'=>'IDPhoto',
                'MedicalCert'=>'Other','BarangayClearance'=>'Other',
                'TranscriptOfRecords'=>'Other','HonorableDismissal'=>'Other',
                'NCEEResult'=>'Other','ESCCertificate'=>'Other',
                'Diploma'=>'Other','Other'=>'Other',
            ];

            if ($pre_reg_id && $uid) {
                foreach ($uploaded_submit as $doc) {
                    $db_type  = $type_map[$doc['type']] ?? 'Other';
                    $fname    = $doc['file_name'];
                    $fpath    = $doc['file_path'];
                    $fsize    = (int)$doc['file_size'];
                    $ai_json  = !empty($doc['ai_result']) ? json_encode($doc['ai_result']) : null;
                    $ai_time  = !empty($doc['ai_result']['inspected_at']) ? $doc['ai_result']['inspected_at'] : null;

                    // Skip if this exact file_path is already saved (prevents double-save)
                    $chk = $conn->prepare("SELECT id FROM enrollment_documents WHERE file_path=? AND user_id=? LIMIT 1");
                    $chk->bind_param('si', $fpath, $uid);
                    $chk->execute();
                    $already = $chk->get_result()->num_rows > 0;
                    $chk->close();
                    if ($already) continue;

                    $stmt = $conn->prepare(
                        "INSERT INTO enrollment_documents
                            (pre_reg_id, user_id, document_type, file_name, file_path, file_size,
                             status, ai_result, ai_inspected_at)
                         VALUES (?,?,?,?,?,?,'Pending',?,?)"
                    );
                    $stmt->bind_param('iisssiss', $pre_reg_id, $uid, $db_type, $fname, $fpath, $fsize, $ai_json, $ai_time);
                    if ($stmt->execute()) $saved++;
                    $stmt->close();
                }
            }
        }

        $msg = count($uploaded_submit) . ' document' . (count($uploaded_submit) !== 1 ? 's' : '') . ' submitted successfully!';
        if ($saved > 0) $msg .= " $saved record" . ($saved !== 1 ? 's' : '') . ' saved to your enrollment file.';
        unset($_SESSION['uploaded_docs']);
    }
}

// ── UPLOAD a new file ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['doc_file'])) {
    $doc_type = trim($_POST['document_type'] ?? '');
    $ref_num  = trim($_POST['ref_number']    ?? '');

    $allowed_ext   = ['pdf','jpg','jpeg','png'];
    $ext           = strtolower(pathinfo($_FILES['doc_file']['name'], PATHINFO_EXTENSION));
    $allowed_types = ['Form138','Form137','BirthCertificate','GoodMoral','MedicalCert','IDPhoto','Other'];

    if (!$doc_type || !in_array($doc_type, $allowed_types)) {
        $err = 'Please select a valid document type.';
    } elseif (!in_array($ext, $allowed_ext)) {
        $err = 'Only PDF, JPG, and PNG files are allowed.';
    } elseif ($_FILES['doc_file']['size'] > 5 * 1024 * 1024) {
        $err = 'File size must not exceed 5 MB.';
    } elseif ($_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Upload error. Please try again.';
    } else {
        // Save to requirements/uploads/ so the existing submit.php and AI can process it
        $upload_dir = __DIR__ . '/../requirements/uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $new_name = uniqid('req_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['doc_file']['name']);
        $dest     = $upload_dir . $new_name;

        if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $dest)) {
            $_SESSION['uploaded_docs'][] = [
                'type'       => $doc_type,
                'file_name'  => $_FILES['doc_file']['name'],
                'file_path'  => 'uploads/' . $new_name,
                'file_size'  => (int)$_FILES['doc_file']['size'],
                'ref_number' => $ref_num ?: $auto_ref,
                'pre_reg_id' => $auto_pre_reg_id,
                'uploaded'   => date('Y-m-d H:i:s'),
                'ai_result'  => null,
            ];
            $msg = htmlspecialchars($_FILES['doc_file']['name']) . ' uploaded successfully.';
        } else {
            $err = 'Failed to save the file. Check folder permissions.';
        }
    }
}

$uploaded       = $_SESSION['uploaded_docs'] ?? [];
$uploaded_types = array_column($uploaded, 'type');

$doc_types = [
    'Form138'          => 'Form 138 (Report Card)',
    'Form137'          => 'Form 137',
    'BirthCertificate' => 'PSA Birth Certificate',
    'GoodMoral'        => 'Good Moral Certificate',
    'MedicalCert'      => 'Medical Certificate',
    'IDPhoto'          => 'ID Photo',
    'Other'            => 'Other Document',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Submit Requirements – BCP</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <style>
    .upload-zone {
      border: 2px dashed #d0d7e2;
      border-radius: 10px;
      padding: 28px 20px;
      text-align: center;
      cursor: pointer;
      transition: border-color .2s, background .2s;
      color: #aaa;
      font-size: .82rem;
    }
    .upload-zone:hover, .upload-zone.dragover {
      border-color: #1a3a8c;
      background: #eff6ff;
      color: #1a3a8c;
    }
    .upload-zone i { font-size: 1.8rem; display: block; margin-bottom: 8px; }
    .req-field { margin-bottom: 16px; }
    .req-field label { display: block; font-size: .78rem; font-weight: 600; color: #444; margin-bottom: 6px; }
    .req-field input, .req-field select {
      width: 100%; height: 44px; border: 1.5px solid #d0d7e2; border-radius: 8px;
      padding: 0 14px; font-size: .88rem; outline: none; font-family: inherit;
      transition: border-color .2s;
    }
    .req-field input:focus, .req-field select:focus { border-color: #1a3a8c; }
    .req-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 600px) { .req-grid { grid-template-columns: 1fr; } }
    .badge-uploaded { background: #dcfce7; color: #16a34a; padding: 3px 10px; border-radius: 20px; font-size: .72rem; font-weight: 700; }
  </style>
</head>
<body>
<?php $APP_ROOT='../'; $ACTIVE_NAV='requirements'; require_once __DIR__.'/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <a href="account.php" class="avatar" title="Account"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-upload"></i> Submit Requirements</h2>
    </div>

    <!-- Reference number banner -->
    <?php if ($auto_ref): ?>
    <div style="margin:0 24px 16px;background:#f0fdf4;border:1.5px solid #86efac;
                border-radius:10px;padding:14px 20px;display:flex;align-items:center;gap:12px;">
      <i class="fa-solid fa-hashtag" style="color:#16a34a;font-size:1rem;"></i>
      <div>
        <div style="font-size:.72rem;color:#aaa;font-weight:600;text-transform:uppercase;">Application Reference</div>
        <code style="font-size:.95rem;font-weight:700;color:#15803d;letter-spacing:.04em;"><?= htmlspecialchars($auto_ref) ?></code>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($msg): ?>
    <div style="margin:0 24px 16px;background:#dcfce7;color:#16a34a;border:1px solid #86efac;
                border-radius:8px;padding:12px 18px;font-size:.85rem;">
      <i class="fa-solid fa-circle-check"></i> <?= $msg ?>
    </div>
    <?php endif; ?>
    <?php if ($err): ?>
    <div style="margin:0 24px 16px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;
                border-radius:8px;padding:12px 18px;font-size:.85rem;">
      <i class="fa-solid fa-circle-xmark"></i> <?= $err ?>
    </div>
    <?php endif; ?>

    <!-- Upload form -->
    <div class="form-card">
      <h3 style="margin-bottom:18px;"><i class="fa-solid fa-cloud-arrow-up" style="color:#2563eb;margin-right:8px;"></i>Upload a Document</h3>
      <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <input type="hidden" name="ref_number" value="<?= htmlspecialchars($auto_ref) ?>"/>

        <div class="req-grid">
          <div class="req-field">
            <label>Document Type <span style="color:#ef4444;">*</span></label>
            <select name="document_type" required>
              <option value="">Select type…</option>
              <?php foreach ($doc_types as $val => $lbl):
                $done = in_array($val, $uploaded_types);
              ?>
              <option value="<?= $val ?>" <?= $done ? 'style="color:#16a34a;"' : '' ?>>
                <?= $lbl ?><?= $done ? ' ✓' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="req-field">
            <label>File <span style="color:#ef4444;">*</span></label>
            <div id="dropZone" class="upload-zone"
                 onclick="document.getElementById('docFile').click()">
              <i class="fa-solid fa-cloud-arrow-up"></i>
              <p id="dropLabel">Drag & drop or click to browse</p>
              <p style="font-size:.7rem;color:#aaa;margin-top:4px;">PDF, JPG, PNG · max 5 MB</p>
            </div>
            <input type="file" id="docFile" name="doc_file"
                   accept=".pdf,.jpg,.jpeg,.png" required style="display:none;"/>
          </div>
        </div>

        <div style="margin-top:16px;">
          <button type="submit" class="btn-submit">
            <i class="fa-solid fa-upload"></i> Upload Document
          </button>
        </div>
      </form>
    </div>

    <!-- Uploaded files -->
    <?php if (!empty($uploaded)): ?>
    <div class="crud-card" style="margin-top:0;">
      <div class="crud-header">
        <h3><i class="fa-solid fa-folder-open" style="color:#2563eb;margin-right:6px;"></i>
          Uploaded This Session (<?= count($uploaded) ?>)
        </h3>
        <form method="POST" style="display:inline;">
          <input type="hidden" name="action" value="submit_all"/>
          <button type="submit" class="btn-approve">
            <i class="fa-solid fa-paper-plane"></i> Submit All Documents
          </button>
        </form>
      </div>
      <table class="crud-table">
        <thead>
          <tr>
            <th>Document Type</th>
            <th>File Name</th>
            <th>Size</th>
            <th>Status</th>
            <th>Remove</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($uploaded as $i => $doc): ?>
          <tr>
            <td style="font-weight:600;"><?= htmlspecialchars($doc_types[$doc['type']] ?? $doc['type']) ?></td>
            <td style="font-size:.78rem;color:#555;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?= htmlspecialchars($doc['file_name']) ?>
            </td>
            <td style="font-size:.75rem;color:#888;"><?= round($doc['file_size'] / 1024, 1) ?> KB</td>
            <td><span class="badge-uploaded"><i class="fa-solid fa-circle-check"></i> Ready</span></td>
            <td>
              <button type="button" class="btn-icon btn-del-doc" title="Remove" data-idx="<?= $i ?>"
                      style="color:#dc2626;">
                <i class="fa-solid fa-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (empty($uploaded)): ?>
    <div style="margin:0 24px;padding:32px;text-align:center;color:#aaa;font-size:.82rem;
                background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
      <i class="fa-solid fa-file-arrow-up" style="font-size:2rem;display:block;margin-bottom:12px;"></i>
      No documents uploaded yet. Use the form above to start.
    </div>
    <?php endif; ?>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script>
const zone  = document.getElementById('dropZone');
const input = document.getElementById('docFile');
const label = document.getElementById('dropLabel');

input.addEventListener('change', () => {
    if (input.files[0]) {
        label.textContent      = input.files[0].name;
        zone.style.borderColor = '#1a3a8c';
        zone.style.background  = '#eff6ff';
        zone.style.color       = '#1a3a8c';
    }
});
zone.addEventListener('dragover',  e  => { e.preventDefault(); zone.classList.add('dragover'); });
zone.addEventListener('dragleave', ()  => zone.classList.remove('dragover'));
zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragover');
    const f = e.dataTransfer.files[0];
    if (f) {
        input.files            = e.dataTransfer.files;
        label.textContent      = f.name;
        zone.style.borderColor = '#1a3a8c';
        zone.style.background  = '#eff6ff';
    }
});
</script>
<script src="../js/dashboard.js"></script>
<script>
document.querySelectorAll('.btn-del-doc').forEach(btn => {
    btn.addEventListener('click', () => {
        const idx = btn.dataset.idx;
        showConfirmModal('Remove this document?', (confirmed) => {
            if (!confirmed) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            form.innerHTML = '<input type="hidden" name="action" value="delete"/>' +
                             '<input type="hidden" name="delete_index" value="' + idx + '"/>';
            document.body.appendChild(form);
            form.submit();
        }, 'Remove Document');
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
