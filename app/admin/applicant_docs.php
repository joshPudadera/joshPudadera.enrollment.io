<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }

$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));
$pre_reg_id   = (int)($_GET['id'] ?? 0);
if (!$pre_reg_id) { header('Location: applicants.php'); exit; }

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doc_id'])) {
    $doc_id     = (int)$_POST['doc_id'];
    $new_status = in_array($_POST['new_status'] ?? '', ['Approved','Rejected','Pending'])
                  ? $_POST['new_status'] : 'Pending';
    $stmt = $conn->prepare("UPDATE enrollment_documents SET status=? WHERE id=?");
    $stmt->bind_param('si', $new_status, $doc_id);
    $stmt->execute(); $stmt->close();
    header("Location: applicant_docs.php?id=$pre_reg_id"); exit;
}

// Fetch applicant
$pr = $conn->prepare("SELECT * FROM pre_registrations WHERE id=? LIMIT 1");
$pr->bind_param('i', $pre_reg_id); $pr->execute();
$applicant = $pr->get_result()->fetch_assoc(); $pr->close();
if (!$applicant) { header('Location: applicants.php'); exit; }

// Fetch documents
$docs = [];
$res  = $conn->query(
    "SELECT * FROM enrollment_documents
     WHERE pre_reg_id = $pre_reg_id
     ORDER BY uploaded_at DESC"
);
if ($res) while ($r = $res->fetch_assoc()) $docs[] = $r;

$doc_type_labels = [
    'Form137'=>'Form 137','BirthCertificate'=>'PSA Birth Certificate',
    'GoodMoral'=>'Good Moral','MedicalCert'=>'Medical Certificate',
    'IDPhoto'=>'ID Photo','Form138'=>'Form 138','Other'=>'Other',
];

$sc = match($applicant['status']) {
    'Approved'=>'#22c55e','Rejected'=>'#ef4444','Enrolled'=>'#2563eb',default=>'#f59e0b'
};

$APP_ROOT   = '../';
$ACTIVE_NAV = 'documents';
require_once __DIR__ . '/../admin_dashboard/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Applicant Documents – Admin</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <style>
    .info-grid { display:grid;grid-template-columns:1fr 1fr;gap:10px 24px;font-size:.82rem; }
    .info-label { font-size:.68rem;color:#aaa;font-weight:600;text-transform:uppercase;margin-bottom:2px; }
    .info-val { color:#1a1a2e; }
    .doc-file-thumb { max-height:180px;max-width:100%;border-radius:6px;border:1px solid #e2e8f0;object-fit:contain;background:#f8fafc; }
    @media(max-width:600px){ .info-grid{grid-template-columns:1fr;} }

    .btn-approve, .btn-reject, .btn-secondary, .btn-primary, .btn-view-file {
      font-family: 'Segoe UI', sans-serif; font-size: .82rem; font-weight: 700;
      cursor: pointer; border: none; border-radius: 8px; padding: 8px 16px;
      display: inline-flex; align-items: center; gap: 6px;
      text-decoration: none; transition: background .15s;
    }
    .btn-approve  { background: #16a34a; color: #fff; }
    .btn-approve:hover  { background: #15803d; }
    .btn-reject   { background: #ef4444; color: #fff; }
    .btn-reject:hover   { background: #dc2626; }
    .btn-primary  { background: #1a3a8c; color: #fff; }
    .btn-primary:hover  { background: #142d6e; }
    .btn-secondary { background: none; color: #555; border: 1.5px solid #d0d7e2; }
    .btn-secondary:hover { background: #f0f4f8; }
    .btn-view-file { background: #eff6ff; color: #2563eb; border: 1.5px solid #bfdbfe; font-weight: 600; font-size: .75rem; }
    .btn-view-file:hover { background: #dbeafe; }
  </style>
</head>
<body>
<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap"><input type="text" placeholder="Search..."/><i class="fa-solid fa-magnifying-glass"></i></div>
      <a href="../admin_dashboard/account.php" class="avatar"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">

    <!-- Breadcrumb -->
    <div style="padding:16px 24px 0;font-size:.8rem;color:#aaa;">
      <a href="applicants.php" style="font-size:.82rem;">
        <i class="fa-solid fa-arrow-left"></i> Back to Applicants
      </a>
    </div>

    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-folder-open"></i>
        <?= htmlspecialchars($applicant['first_name'].' '.$applicant['last_name']) ?>
      </h2>
    </div>

    <!-- Applicant summary card -->
    <div class="form-card" style="margin-bottom:20px;">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;
                  flex-wrap:wrap;gap:12px;margin-bottom:16px;">
        <div>
          <div style="font-size:1rem;font-weight:700;color:#1a1a2e;">
            <?= htmlspecialchars($applicant['first_name'].' '.$applicant['last_name']) ?>
          </div>
          <div style="font-size:.78rem;color:#888;margin-top:3px;">
            <?= htmlspecialchars($applicant['email']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($applicant['phone']) ?>
          </div>
        </div>
        <span style="display:inline-flex;align-items:center;gap:7px;padding:6px 16px;
                     border-radius:20px;font-size:.8rem;font-weight:700;
                     background:<?= $sc ?>18;color:<?= $sc ?>;">
          <?= $applicant['status'] ?>
        </span>
      </div>
      <div class="info-grid">
        <?php $fields = [
          'Reference No.' => $applicant['ref_number'] ?? '—',
          'Course'        => preg_replace('/Bachelor of Science in /i','BS ',$applicant['course']),
          'Year Level'    => $applicant['year_level'],
          'Previous School' => $applicant['prev_school'] ?: '—',
          'Submitted'     => date('M d, Y g:i A', strtotime($applicant['submitted_at'])),
          'Remarks'       => $applicant['remarks'] ?: '—',
        ];
        foreach ($fields as $lbl => $val): ?>
        <div>
          <div class="info-label"><?= $lbl ?></div>
          <div class="info-val"><?= htmlspecialchars($val) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Documents -->
    <div class="crud-card">
      <div class="crud-header">
        <h3><i class="fa-solid fa-file-lines" style="color:#2563eb;margin-right:6px;"></i>
          Uploaded Documents (<?= count($docs) ?>)
        </h3>
        <a href="document_review.php?pre_reg=<?= $pre_reg_id ?>"
           class="btn-primary">
          <i class="fa-solid fa-robot"></i> Full AI Review
        </a>
      </div>

      <?php if (empty($docs)): ?>
      <div style="text-align:center;padding:32px;color:#aaa;font-size:.82rem;">
        <i class="fa-solid fa-folder-open" style="font-size:2rem;display:block;margin-bottom:10px;"></i>
        No documents uploaded yet.
      </div>
      <?php else: ?>

      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;padding:16px;">
        <?php foreach ($docs as $doc):
          $ai      = !empty($doc['ai_result']) ? json_decode($doc['ai_result'],true) : null;
          $verdict = $ai['is_authentic'] ?? null;
          if ($verdict===true)           { $avc='#16a34a'; $avi='fa-circle-check';    $avl='Authentic'; }
          elseif ($verdict===false)      { $avc='#dc2626'; $avi='fa-circle-xmark';    $avl='Fake/Altered'; }
          elseif ($verdict==='uncertain'){ $avc='#f59e0b'; $avi='fa-circle-question'; $avl='Uncertain'; }
          else                           { $avc='#aaa';    $avi='fa-robot';           $avl='Not Inspected'; }

          $is_img   = in_array(strtolower(pathinfo($doc['file_name'],PATHINFO_EXTENSION)),['jpg','jpeg','png']);
          $file_url = '../requirements/file.php?path=' . urlencode($doc['file_path']);

          $badge_sc = $doc['status']==='Approved'?'#22c55e':($doc['status']==='Rejected'?'#ef4444':'#f59e0b');
        ?>
        <div style="background:#fff;border:1.5px solid #e8edf4;border-radius:10px;overflow:hidden;">

          <!-- Document thumbnail -->
          <div style="background:#f8fafc;padding:14px;text-align:center;border-bottom:1px solid #f0f2f5;min-height:80px;display:flex;align-items:center;justify-content:center;">
            <?php if ($is_img): ?>
            <img src="../requirements/file.php?path=<?= urlencode($doc['file_path']) ?>"
                 alt="document" class="doc-file-thumb"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
            <div style="display:none;flex-direction:column;align-items:center;gap:6px;color:#aaa;">
              <i class="fa-solid fa-image" style="font-size:1.8rem;"></i>
              <span style="font-size:.72rem;">Preview unavailable</span>
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;align-items:center;gap:6px;color:#aaa;">
              <i class="fa-solid fa-file-pdf" style="font-size:2rem;color:#dc2626;"></i>
              <span style="font-size:.72rem;">PDF Document</span>
            </div>
            <?php endif; ?>
          </div>

          <!-- Document info -->
          <div style="padding:14px;">
            <div style="font-weight:700;font-size:.85rem;color:#1a1a2e;margin-bottom:4px;">
              <?= htmlspecialchars($doc_type_labels[$doc['document_type']] ?? $doc['document_type']) ?>
            </div>
            <div style="font-size:.72rem;color:#888;margin-bottom:10px;word-break:break-all;">
              <?= htmlspecialchars($doc['file_name']) ?>
            </div>

            <!-- Status + AI row -->
            <div style="display:flex;align-items:center;justify-content:space-between;
                        flex-wrap:wrap;gap:6px;margin-bottom:12px;">
              <span style="font-size:.72rem;font-weight:700;color:<?= $avc ?>;">
                <i class="fa-solid <?= $avi ?>"></i> <?= $avl ?>
                <?php if ($ai && isset($ai['confidence'])): ?>
                <span style="color:#aaa;font-weight:400;">(<?= $ai['confidence'] ?>%)</span>
                <?php endif; ?>
              </span>
              <span style="font-size:.72rem;font-weight:700;padding:2px 10px;
                           border-radius:20px;background:<?= $badge_sc ?>18;color:<?= $badge_sc ?>;">
                <?= $doc['status'] ?>
              </span>
            </div>

            <!-- Actions -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <a href="<?= $file_url ?>" target="_blank"
                 class="btn-view-file">
                <i class="fa-solid fa-eye"></i> View
              </a>
              <form method="POST" style="flex:1;display:flex;gap:4px;">
                <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>"/>
                <?php if ($doc['status'] !== 'Approved'): ?>
                <button type="button"
                        class="btn-approve btn-appdoc-apv"
                        data-doc-id="<?= $doc['id'] ?>" data-pre-reg="<?= $pre_reg_id ?>">
                  <i class="fa-solid fa-check"></i> Approve
                </button>
                <?php else: ?>
                <button type="submit" name="new_status" value="Pending"
                        class="btn-secondary">
                  Reset
                </button>
                <?php endif; ?>
                <?php if ($doc['status'] !== 'Rejected'): ?>
                <button type="button"
                        class="btn-reject btn-appdoc-rej"
                        data-doc-id="<?= $doc['id'] ?>" data-pre-reg="<?= $pre_reg_id ?>">
                  <i class="fa-solid fa-xmark"></i> Reject
                </button>
                <?php endif; ?>
              </form>
            </div>
          </div>

        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
document.querySelectorAll('.btn-appdoc-apv').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var docId = btn.dataset.docId;
        showConfirm('Approve this document?', function() {
            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="doc_id" value="' + docId + '"/>' +
                             '<input type="hidden" name="new_status" value="Approved"/>';
            document.body.appendChild(form);
            form.submit();
        });
    });
});
document.querySelectorAll('.btn-appdoc-rej').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var docId = btn.dataset.docId;
        showConfirm('Reject this document?', function() {
            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="doc_id" value="' + docId + '"/>' +
                             '<input type="hidden" name="new_status" value="Rejected"/>';
            document.body.appendChild(form);
            form.submit();
        });
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
