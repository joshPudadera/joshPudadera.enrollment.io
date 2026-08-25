<?php
session_start();
require_once __DIR__ . '/../shared/db.php';

$current_step = 2;
$msg = $err = '';

// Auto-load ref_number from the student's most recent pre-registration
$auto_ref = '';
$auto_pre_reg_id = 0;
if (!empty($_SESSION['user_id'])) {
    $uid_ref = (int)$_SESSION['user_id'];
    $r = $conn->query("SELECT id, ref_number FROM pre_registrations WHERE user_id=$uid_ref ORDER BY submitted_at DESC LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        $auto_ref        = $row['ref_number'] ?? '';
        $auto_pre_reg_id = (int)$row['id'];
    }
}

// ══════════════════════════════════════════════════════════════
//  ACTION: DELETE a specific uploaded file
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $idx = (int)($_POST['delete_index'] ?? -1);
    if (isset($_SESSION['uploaded_docs'][$idx])) {
        $file_path = __DIR__ . '/' . $_SESSION['uploaded_docs'][$idx]['file_path'];
        if (file_exists($file_path)) @unlink($file_path);
        array_splice($_SESSION['uploaded_docs'], $idx, 1);
        $msg = 'Document removed.';
    }
}

// ══════════════════════════════════════════════════════════════
//  ACTION: UPLOAD a new file
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['doc_file'])) {
    $doc_type = trim($_POST['document_type'] ?? '');
    $ref_num  = trim($_POST['ref_number']    ?? '');
    $file     = $_FILES['doc_file'];

    $allowed_ext   = ['pdf','jpg','jpeg','png'];
    $ext           = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_types = [
        'Form138','Form137','BirthCertificate',
        'Other'
    ];

    if (!$doc_type || !in_array($doc_type, $allowed_types)) {
        $err = 'Please select a valid document type.';
    } elseif (!in_array($ext, $allowed_ext)) {
        $err = 'Only PDF, JPG, and PNG files are allowed.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $err = 'File size must not exceed 5 MB.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $err = 'Upload error. Please try again.';
    } else {
        $upload_dir = __DIR__ . '/uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $new_name = uniqid('req_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
        $dest     = $upload_dir . $new_name;

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            $_SESSION['uploaded_docs'][] = [
                'type'        => $doc_type,
                'file_name'   => $file['name'],
                'file_path'   => 'uploads/' . $new_name,
                'file_size'   => $file['size'],
                'ref_number'  => $ref_num ?: $auto_ref,
                'pre_reg_id'  => $auto_pre_reg_id ?: 0,
                'uploaded'    => date('Y-m-d H:i:s'),
                'ai_result'   => null,
            ];
            $msg = htmlspecialchars($file['name']) . ' uploaded successfully.';
        } else {
            $err = 'Failed to save the file. Check folder permissions.';
        }
    }
}

$uploaded     = $_SESSION['uploaded_docs'] ?? [];
$uploaded_types = array_column($uploaded, 'type');

$doc_types = [
    'Form138'            => 'Form 138 (Report Card)',
    'Form137'            => 'Form 137',
    'BirthCertificate'   => 'PSA Birth Certificate',
    'Other'              => 'Other Document',
];

include __DIR__ . '/header.php';
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Upload Your Documents</h2>
    <p style="text-align:center;font-size:.82rem;color:#666;margin-bottom:20px;">
      Upload each required document one at a time. Accepted formats: PDF, JPG, PNG — max 5 MB each.
    </p>

    <?php if ($msg): ?>
    <div class="auth-success" style="margin-bottom:16px;">
      <i class="fa-solid fa-circle-check"></i> <?= $msg ?>
    </div>
    <?php endif; ?>
    <?php if ($err): ?>
    <div class="auth-error" style="margin-bottom:16px;">
      <i class="fa-solid fa-circle-xmark"></i> <?= $err ?>
    </div>
    <?php endif; ?>

    <!-- ── Upload form ── -->
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
      <div class="enroll-form-grid" style="grid-template-columns:1fr;">
        <div class="enroll-field">
          <label>Application Reference Number
            <?php if ($auto_ref): ?>
            <span style="color:#16a34a;font-weight:600;font-size:.72rem;">
              <i class="fa-solid fa-circle-check"></i> Auto-filled from your application
            </span>
            <?php else: ?>
            <span style="color:#aaa;font-weight:400;">(optional)</span>
            <?php endif; ?>
          </label>
          <input type="text" name="ref_number"
                 value="<?= htmlspecialchars($auto_ref ?: ($_POST['ref_number'] ?? '')) ?>"
                 placeholder="e.g. BCP-BA-20250101-1234"
                 <?= $auto_ref ? 'readonly style="background:#f0fdf4;border-color:#86efac;color:#16a34a;font-weight:700;"' : '' ?>/>
          <?php if ($auto_ref): ?>
          <div style="font-size:.7rem;color:#16a34a;margin-top:4px;">
            Reference: <strong><?= htmlspecialchars($auto_ref) ?></strong>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="enroll-form-grid" style="margin-top:16px;">
        <div class="enroll-field">
          <label>Document Type <span class="req">*</span></label>
          <select name="document_type" id="docType" required>
            <option value="">Select document type…</option>
            <?php foreach ($doc_types as $val => $label):
              $done = in_array($val, $uploaded_types);
            ?>
            <option value="<?= $val ?>" <?= $done ? 'style="color:#22c55e;"' : '' ?>>
              <?= $label ?><?= $done ? ' ✓' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="enroll-field">
          <label>Select File <span class="req">*</span></label>
          <div id="dropZone" class="upload-zone"
               onclick="document.getElementById('docFile').click()">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <p id="dropLabel">Drag & drop or click to browse</p>
            <p style="font-size:.7rem;color:#aaa;margin-top:4px;">PDF, JPG, PNG — max 5 MB</p>
          </div>
          <input type="file" id="docFile" name="doc_file"
                 accept=".pdf,.jpg,.jpeg,.png" required style="display:none;"/>
        </div>
      </div>

      <div class="enroll-actions" style="margin-top:20px;">
        <button type="submit" class="btn-proceed">
          <i class="fa-solid fa-upload"></i> Upload Document
        </button>
      </div>
    </form>

    <!-- ── Uploaded files table ── -->
    <?php if ($uploaded): ?>
    <div style="margin-top:32px;">
      <h3 class="enroll-section-heading">
        <i class="fa-solid fa-folder-open"></i>
        Uploaded This Session (<?= count($uploaded) ?>)
      </h3>

      <table style="width:100%;border-collapse:collapse;font-size:.82rem;margin-top:12px;">
        <thead style="background:#1a3a8c;color:#fff;">
          <tr>
            <th style="padding:10px 12px;text-align:left;">Document</th>
            <th style="padding:10px 12px;text-align:left;">File</th>
            <th style="padding:10px 12px;text-align:left;">Size</th>
            <th style="padding:10px 12px;text-align:left;">Status</th>
            <th style="padding:10px 12px;text-align:center;">Remove</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($uploaded as $i => $doc): ?>
          <tr style="border-bottom:1px solid #f0f2f5;">
            <td style="padding:10px 12px;font-weight:600;color:#1a1a2e;">
              <?= htmlspecialchars($doc_types[$doc['type']] ?? $doc['type']) ?>
            </td>
            <td style="padding:10px 12px;color:#555;font-size:.78rem;max-width:180px;
                       overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
              <?= htmlspecialchars($doc['file_name']) ?>
            </td>
            <td style="padding:10px 12px;color:#888;white-space:nowrap;">
              <?= round($doc['file_size'] / 1024, 1) ?> KB
            </td>
            <td style="padding:10px 12px;">
              <span style="font-size:.75rem;color:#16a34a;font-weight:600;">
                <i class="fa-solid fa-circle-check"></i> Uploaded
              </span>
              <div style="font-size:.7rem;color:#aaa;margin-top:2px;">
                AI will inspect on submission
              </div>
            </td>
            <td style="padding:10px 12px;text-align:center;">
              <!-- Delete button -->
              <button type="button" class="btn-del-doc-upload"
                      data-idx="<?= $i ?>"
                      title="Delete"
                      style="background:#fee2e2;color:#dc2626;border:1.5px solid #fca5a5;
                             border-radius:6px;padding:5px 10px;font-size:.75rem;
                             font-weight:600;cursor:pointer;display:inline-flex;
                             align-items:center;gap:5px;">
                <i class="fa-solid fa-trash"></i> Delete
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <div class="enroll-actions" style="margin-top:28px;">
      <a href="index.php" class="btn-back">
        <i class="fa-solid fa-arrow-left"></i> Back
      </a>
      <?php if (!empty($uploaded)): ?>
      <a href="status.php" class="btn-proceed">
        Review Uploads <i class="fa-solid fa-arrow-right"></i>
      </a>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
const zone  = document.getElementById('dropZone');
const input = document.getElementById('docFile');
const label = document.getElementById('dropLabel');

input.addEventListener('change', () => {
    if (input.files[0]) {
        label.textContent = input.files[0].name;
        zone.style.borderColor = '#1a3a8c';
        zone.style.background  = '#eff6ff';
    }
});

zone.addEventListener('dragover',  e  => { e.preventDefault(); zone.classList.add('dragover'); });
zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.classList.remove('dragover');
    const f = e.dataTransfer.files[0];
    if (f) {
        input.files = e.dataTransfer.files;
        label.textContent      = f.name;
        zone.style.borderColor = '#1a3a8c';
        zone.style.background  = '#eff6ff';
    }
});

function openModal(id){var el=document.getElementById(id);if(el)el.classList.add('active');}
function closeModal(id){var el=document.getElementById(id);if(el)el.classList.remove('active');}
document.addEventListener('click',function(e){
  var cb=e.target.closest('[data-close]');if(cb){closeModal(cb.dataset.close);return;}
  if(e.target.classList.contains('modal-overlay'))e.target.classList.remove('active');
});
document.addEventListener('keydown',function(e){
  if(e.key==='Escape'){document.querySelectorAll('.modal-overlay.active').forEach(function(o){o.classList.remove('active');});}
});
document.addEventListener('click',function(e){if(e.target.closest('.modal'))e.stopPropagation();},true);
function escapeHtml(str){var d=document.createElement('div');d.appendChild(document.createTextNode(str));return d.innerHTML;}
var _uplConfirmCb=null;
function ensureUplModals(){
  if(!document.getElementById('uplAlertModalOverlay')){
    var h=''
    +'<div class="modal-overlay" id="uplAlertModalOverlay">'
    +'  <div class="modal modal-sm">'
    +'    <div class="modal-header"><span id="uplAlertTitle">Alert</span><button class="modal-close" data-close="uplAlertModalOverlay">&times;</button></div>'
    +'    <div class="modal-body" id="uplAlertBody" style="padding:24px 22px;"></div>'
    +'    <div class="modal-footer"><button class="btn-modal-submit" data-close="uplAlertModalOverlay" style="flex:0 0 auto;padding:10px 48px;">OK</button></div>'
    +'  </div></div>'
    +'<div class="modal-overlay" id="uplConfirmModalOverlay">'
    +'  <div class="modal modal-sm">'
    +'    <div class="modal-header"><span id="uplConfirmTitle">Confirm</span><button class="modal-close" data-close="uplConfirmModalOverlay">&times;</button></div>'
    +'    <div class="modal-body" id="uplConfirmBody" style="padding:24px 22px 12px;"></div>'
    +'    <div class="modal-footer modal-footer-split">'
    +'      <button class="btn-modal-cancel" id="uplConfirmCancel">Cancel</button>'
    +'      <button class="btn-modal-confirm" id="uplConfirmOk">Confirm</button>'
    +'    </div></div></div>';
    var d=document.createElement('div');d.innerHTML=h;
    while(d.firstChild)document.body.appendChild(d.firstChild);
  }
}
function showAlertModal(msg,type,title){
  ensureUplModals();type=type||'info';
  var iconMap={
    success:'<i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:2rem;display:block;margin-bottom:12px;"></i>',
    error:'<i class="fa-solid fa-circle-xmark" style="color:#dc2626;font-size:2rem;display:block;margin-bottom:12px;"></i>',
    warning:'<i class="fa-solid fa-triangle-exclamation" style="color:#d97706;font-size:2rem;display:block;margin-bottom:12px;"></i>',
    info:'<i class="fa-solid fa-circle-info" style="color:#2563eb;font-size:2rem;display:block;margin-bottom:12px;"></i>'
  };
  document.getElementById('uplAlertTitle').textContent=title||({success:'Success',error:'Error',warning:'Warning',info:'Information'}[type]||'Alert');
  document.getElementById('uplAlertBody').innerHTML='<div style="text-align:center;">'+(iconMap[type]||iconMap.info)
    +'<p style="font-size:.88rem;color:#333;line-height:1.55;white-space:pre-wrap;word-break:break-word;">'+escapeHtml(msg)+'</p></div>';
  openModal('uplAlertModalOverlay');
}
function showConfirmModal(msg,onConfirm,title){
  ensureUplModals();
  document.getElementById('uplConfirmTitle').textContent=title||'Confirm Action';
  document.getElementById('uplConfirmBody').innerHTML='<div style="display:flex;gap:12px;align-items:flex-start;">'
    +'<i class="fa-solid fa-circle-question" style="color:#2563eb;font-size:1.6rem;flex-shrink:0;margin-top:2px;"></i>'
    +'<div style="flex:1;font-size:.88rem;color:#333;line-height:1.55;">'+escapeHtml(msg)+'</div></div>';
  _uplConfirmCb=onConfirm||null;
  var ok=document.getElementById('uplConfirmOk'),cancel=document.getElementById('uplConfirmCancel');
  if(!ok._hasH){ok.addEventListener('click',function(){
    closeModal('uplConfirmModalOverlay');var cb=_uplConfirmCb;_uplConfirmCb=null;
    if(cb)setTimeout(function(){cb(true);},50);
  });ok._hasH=true;}
  if(!cancel._hasH){cancel.addEventListener('click',function(){
    closeModal('uplConfirmModalOverlay');var cb=_uplConfirmCb;_uplConfirmCb=null;
    if(cb)setTimeout(function(){cb(false);},50);
  });cancel._hasH=true;}
  openModal('uplConfirmModalOverlay');
}

document.querySelectorAll('.btn-del-doc-upload').forEach(function(btn){
  btn.addEventListener('click',function(){
    var idx=btn.getAttribute('data-idx');
    showConfirmModal('Delete this uploaded document?',function(confirmed){
      if(!confirmed)return;
      var f=document.createElement('form');f.method='POST';
      f.innerHTML='<input type="hidden" name="action" value="delete"/>'
        +'<input type="hidden" name="delete_index" value="'+idx+'"/>';
      document.body.appendChild(f);f.submit();
    },'Delete Document');
  });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
