<?php
// ============================================================
//  DOCUMENT_REVIEW.PHP  (admin/)
//  Admin view: all uploaded documents with AI inspection results
//  shown in a structured template card.
// ============================================================
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))        { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin')      { header('Location: ../admin_dashboard/dashboard.php'); exit; }
if (!enrollment_tables_exist($conn))    { header('Location: ../shared/full_setup.php'); exit; }

$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));

// ── Fetch all documents with applicant info ───────────────────
$docs = [];
// Add ai_result column if it doesn't exist yet
$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_result JSON DEFAULT NULL");
$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_inspected_at TIMESTAMP NULL DEFAULT NULL");

$res  = $conn->query(
    "SELECT d.*,
            COALESCE(p.first_name, 'Unknown') AS first_name,
            COALESCE(p.last_name,  'Applicant') AS last_name,
            COALESCE(p.email,      '—') AS email,
            COALESCE(p.phone,      '—') AS phone,
            COALESCE(p.course,     '—') AS course,
            COALESCE(p.year_level, '—') AS year_level,
            COALESCE(p.status,     'Pending') AS app_status,
            p.submitted_at
     FROM   enrollment_documents d
     LEFT JOIN pre_registrations p ON d.pre_reg_id = p.id
     ORDER  BY d.uploaded_at DESC"
);
if ($res) while ($r = $res->fetch_assoc()) $docs[] = $r;

// Filter
$filter_status = $_GET['status'] ?? '';
$filter_type   = $_GET['type']   ?? '';
if ($filter_status) $docs = array_filter($docs, fn($d) => $d['status'] === $filter_status);
if ($filter_type)   $docs = array_filter($docs, fn($d) => $d['document_type'] === $filter_type);

// ── Handle admin status update ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doc_id'])) {
    $doc_id    = (int)$_POST['doc_id'];
    $new_status = in_array($_POST['new_status'] ?? '', ['Approved','Rejected','Pending'])
                  ? $_POST['new_status'] : 'Pending';
    $stmt = $conn->prepare("UPDATE enrollment_documents SET status=? WHERE id=?");
    $stmt->bind_param('si', $new_status, $doc_id);
    $stmt->execute(); $stmt->close();
    header('Location: document_review.php'); exit;
}

$APP_ROOT   = '../';
$ACTIVE_NAV = 'documents';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Document Review – Admin</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <style>
    .doc-card {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 2px 12px rgba(0,0,0,.08);
      overflow: hidden;
      margin-bottom: 20px;
    }
    .doc-card-header {
      padding: 14px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    .doc-card-header.authentic  { background: #f0fdf4; border-left: 5px solid #22c55e; }
    .doc-card-header.fake       { background: #fff1f2; border-left: 5px solid #ef4444; }
    .doc-card-header.uncertain  { background: #fffbeb; border-left: 5px solid #f59e0b; }
    .doc-card-header.pending    { background: #f8fafc; border-left: 5px solid #94a3b8; }
    .verdict-badge {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 5px 14px; border-radius: 20px;
      font-size: .78rem; font-weight: 700;
    }
    .verdict-authentic { background:#dcfce7; color:#16a34a; }
    .verdict-fake      { background:#fee2e2; color:#dc2626; }
    .verdict-uncertain { background:#fff7ed; color:#d97706; }
    .verdict-pending   { background:#f1f5f9; color:#64748b; }
    .doc-body   { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0; }
    .doc-section { padding: 18px 20px; border-right: 1px solid #f0f2f5; }
    .doc-section:last-child { border-right: none; }
    .doc-section h4 {
      font-size: .72rem; font-weight: 700; text-transform: uppercase;
      color: #1a3a8c; letter-spacing: .04em; margin-bottom: 12px;
      padding-bottom: 6px; border-bottom: 1.5px solid #eff6ff;
    }
    .doc-field { margin-bottom: 9px; }
    .doc-field-label { font-size: .68rem; color: #aaa; font-weight: 600; text-transform: uppercase; margin-bottom: 2px; }
    .doc-field-value { font-size: .82rem; color: #1a1a2e; font-weight: 500; }
    .doc-field-value.empty { color: #ccc; font-style: italic; }
    .red-flag-item {
      background: #fff1f2; color: #dc2626; border-radius: 6px;
      padding: 5px 10px; font-size: .75rem; margin-bottom: 5px;
      display: flex; align-items: flex-start; gap: 6px;
    }
    .doc-footer { padding: 12px 20px; border-top: 1px solid #f0f2f5;
                  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
    .confidence-bar { height: 6px; border-radius: 3px; background: #e2e8f0; flex: 1; min-width: 80px; }
    .confidence-fill { height: 6px; border-radius: 3px; transition: width .3s; }
    @media (max-width: 700px) { .doc-body { grid-template-columns: 1fr; } .doc-section { border-right: none; border-bottom: 1px solid #f0f2f5; } }

    /* Ensure button classes render correctly on this page */
    .btn-approve, .btn-reject, .btn-secondary, .btn-primary, .btn-view-file {
      font-family: 'Segoe UI', sans-serif;
      font-size: .82rem;
      font-weight: 700;
      cursor: pointer;
      border: none;
      border-radius: 8px;
      padding: 8px 18px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      transition: background .15s, box-shadow .15s;
    }
    .btn-approve  { background: #16a34a; color: #fff; }
    .btn-approve:hover  { background: #15803d; }
    .btn-reject   { background: #ef4444; color: #fff; }
    .btn-reject:hover   { background: #dc2626; }
    .btn-primary  { background: #1a3a8c; color: #fff; }
    .btn-primary:hover  { background: #142d6e; }
    .btn-secondary { background: none; color: #555; border: 1.5px solid #d0d7e2; }
    .btn-secondary:hover { background: #f0f4f8; }
    .btn-view-file { background: #eff6ff; color: #2563eb; border: 1.5px solid #bfdbfe; font-weight: 600; }
    .btn-view-file:hover { background: #dbeafe; }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/../admin_dashboard/sidebar.php'; ?>
<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap"><input type="text" placeholder="Search..."/><i class="fa-solid fa-magnifying-glass"></i></div>
      <a href="../admin_dashboard/account.php" class="avatar" title="Account"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-file-shield"></i> Document Review — AI Inspection</h2>
    </div>

    <!-- Feedback from re-inspection -->
    <?php if (isset($_GET['ai_done'])): ?>
    <div class="auth-success" style="margin:0 24px 16px;">
      <i class="fa-solid fa-robot"></i>
      AI inspection complete for document #<?= (int)$_GET['ai_done'] ?>.
      Results are shown in the card below.
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['ai_err'])): ?>
    <div class="auth-error" style="margin:0 24px 16px;">
      <i class="fa-solid fa-circle-xmark"></i>
      AI inspection failed: <?= htmlspecialchars(urldecode($_GET['ai_err'])) ?>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div style="padding:0 24px 16px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <a href="?status=&type="    class="pg-btn <?= !$filter_status&&!$filter_type?'active':'' ?>">All</a>
      <a href="?status=Pending"   class="pg-btn <?= $filter_status==='Pending'?'active':'' ?>">Pending</a>
      <a href="?status=Approved"  class="pg-btn <?= $filter_status==='Approved'?'active':'' ?>">Approved</a>
      <a href="?status=Rejected"  class="pg-btn <?= $filter_status==='Rejected'?'active':'' ?>">Rejected</a>
      <span style="color:#aaa;font-size:.8rem;"><?= count($docs) ?> document<?= count($docs)!==1?'s':'' ?></span>
    </div>

    <div style="padding:0 24px;">

      <?php if (empty($docs)): ?>
      <div class="crud-card" style="text-align:center;padding:40px;color:#aaa;">
        <i class="fa-solid fa-folder-open" style="font-size:2rem;margin-bottom:12px;display:block;"></i>
        No documents found<?= $filter_status ? " with status \"$filter_status\"" : '' ?>.
      </div>
      <?php endif; ?>

      <?php foreach ($docs as $doc):
        // Parse stored AI result from notes field (we store JSON there)
        $ai   = null;
        $notes_raw = $doc['status'] === 'Pending' ? null : null; // fetched below
        // AI result is stored in the session during upload — for DB-persisted docs
        // we store the JSON in a dedicated column (add via ALTER or use notes as fallback)
        // Here we show what's in the DB + parse any JSON stored in file_name notes col

        $verdict      = 'pending';   // default
        $conf         = 0;
        $extracted    = [];
        $red_flags    = [];
        $ai_notes     = '';
        $doc_detected = $doc['document_type'];

        // Header color class
        $header_cls   = 'pending';
        $verdict_cls  = 'verdict-pending';
        $verdict_text = 'Pending Review';
        $verdict_icon = 'fa-clock';

        if ($doc['status'] === 'Approved') {
            $header_cls   = 'authentic';
            $verdict_cls  = 'verdict-authentic';
            $verdict_text = 'Approved';
            $verdict_icon = 'fa-circle-check';
        } elseif ($doc['status'] === 'Rejected') {
            $header_cls   = 'fake';
            $verdict_cls  = 'verdict-fake';
            $verdict_text = 'Rejected';
            $verdict_icon = 'fa-circle-xmark';
        }

        $full_name = htmlspecialchars(trim($doc['first_name'] . ' ' . $doc['last_name']));
      ?>

      <!-- ── Document Card ── -->
      <div class="doc-card">

        <!-- Card header -->
        <div class="doc-card-header <?= $header_cls ?>">
          <div>
            <div style="font-weight:700;font-size:.92rem;color:#1a1a2e;margin-bottom:3px;">
              <i class="fa-solid fa-file-lines" style="color:#1a3a8c;margin-right:6px;"></i>
              <?= htmlspecialchars($doc['document_type']) ?>
              <span style="font-size:.75rem;color:#888;margin-left:8px;">
                #<?= $doc['id'] ?>
              </span>
            </div>
            <div style="font-size:.78rem;color:#666;">
              Applicant: <strong><?= $full_name ?></strong>
              &nbsp;·&nbsp;
              <?= htmlspecialchars($doc['course'] ?? '—') ?>
              &nbsp;·&nbsp;
              Uploaded: <?= date('M d, Y g:i A', strtotime($doc['uploaded_at'])) ?>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span class="verdict-badge <?= $verdict_cls ?>">
              <i class="fa-solid <?= $verdict_icon ?>"></i>
              <?= $verdict_text ?>
            </span>
            <!-- Admin approval actions -->
            <form method="POST" class="doc-status-form" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>"/>
              <input type="hidden" name="new_status" value=""/>
              <?php if ($doc['status'] !== 'Approved'): ?>
              <button type="button"
                      class="btn-approve btn-doc-apv"
                      data-doc-id="<?= $doc['id'] ?>" data-status="Approved">
                <i class="fa-solid fa-circle-check"></i> Approve
              </button>
              <?php else: ?>
              <span class="btn-approve" style="opacity:.7;cursor:default;">
                <i class="fa-solid fa-circle-check"></i> Approved
              </span>
              <?php endif; ?>

              <?php if ($doc['status'] !== 'Rejected'): ?>
              <button type="button"
                      class="btn-reject btn-doc-rej"
                      data-doc-id="<?= $doc['id'] ?>" data-status="Rejected">
                <i class="fa-solid fa-circle-xmark"></i> Reject
              </button>
              <?php else: ?>
              <span class="btn-reject" style="opacity:.7;cursor:default;">
                <i class="fa-solid fa-circle-xmark"></i> Rejected
              </span>
              <?php endif; ?>

              <?php if ($doc['status'] !== 'Pending'): ?>
              <button type="submit" name="new_status" value="Pending"
                      class="btn-secondary">
                Reset
              </button>
              <?php endif; ?>
            </form>
          </div>
        </div>

        <!-- Card body — three columns -->
        <div class="doc-body">

          <!-- Column 1: Applicant Info -->
          <div class="doc-section">
            <h4><i class="fa-solid fa-user"></i> Applicant</h4>
            <div class="doc-field">
              <div class="doc-field-label">Full Name</div>
              <div class="doc-field-value"><?= $full_name ?></div>
            </div>
            <div class="doc-field">
              <div class="doc-field-label">Email</div>
              <div class="doc-field-value"><?= htmlspecialchars($doc['email'] ?? '—') ?></div>
            </div>
            <div class="doc-field">
              <div class="doc-field-label">Phone</div>
              <div class="doc-field-value"><?= htmlspecialchars($doc['phone'] ?? '—') ?></div>
            </div>
            <div class="doc-field">
              <div class="doc-field-label">Course</div>
              <div class="doc-field-value" style="font-size:.75rem;">
                <?= htmlspecialchars($doc['course'] ?? '—') ?>
              </div>
            </div>
            <div class="doc-field">
              <div class="doc-field-label">Application Status</div>
              <div class="doc-field-value">
                <span class="badge-<?= strtolower($doc['app_status']) ?>">
                  <?= htmlspecialchars($doc['app_status']) ?>
                </span>
              </div>
            </div>
          </div>

          <!-- Column 2: Document Info -->
          <div class="doc-section">
            <h4><i class="fa-solid fa-file-lines"></i> Document</h4>
            <div class="doc-field">
              <div class="doc-field-label">Type</div>
              <div class="doc-field-value"><?= htmlspecialchars($doc['document_type']) ?></div>
            </div>
            <div class="doc-field">
              <div class="doc-field-label">File Name</div>
              <div class="doc-field-value" style="font-size:.75rem;word-break:break-all;">
                <?= htmlspecialchars($doc['file_name']) ?>
              </div>
            </div>
            <div class="doc-field">
              <div class="doc-field-label">File Size</div>
              <div class="doc-field-value"><?= round($doc['file_size'] / 1024, 1) ?> KB</div>
            </div>
            <div class="doc-field">
              <div class="doc-field-label">Uploaded</div>
              <div class="doc-field-value" style="font-size:.75rem;">
                <?= date('M d, Y g:i A', strtotime($doc['uploaded_at'])) ?>
              </div>
            </div>
            <div class="doc-field" style="margin-top:12px;">
              <a href="../requirements/file.php?path=<?= urlencode($doc['file_path']) ?>"
                 target="_blank" class="btn-view-file">
                <i class="fa-solid fa-eye"></i> View File
              </a>
            </div>
          </div>

          <!-- Column 3: AI Inspection Results -->
          <div class="doc-section">
            <h4><i class="fa-solid fa-robot"></i> AI Inspection</h4>
            <?php
            // Parse ai_result JSON stored in DB
            $ai_raw = $doc['ai_result'] ?? null;
            $ai     = $ai_raw ? json_decode($ai_raw, true) : null;
            $ext    = &$extracted; // reuse extracted var name
            $ext    = $ai['extracted'] ?? [];

            if ($ai):
              $is_auth  = $ai['is_authentic'] ?? 'uncertain';
              $ai_conf  = (int)($ai['confidence'] ?? 0);
              $ai_notes = $ai['notes']     ?? '';
              $ai_flags = $ai['red_flags'] ?? [];
              $ai_model = $ai['model']     ?? 'gpt-4o';
              $ai_time  = $ai['inspected_at'] ?? '';

              $conf_color = $ai_conf >= 80 ? '#22c55e' : ($ai_conf >= 50 ? '#f59e0b' : '#ef4444');
            ?>
            <!-- Verdict + Confidence -->
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
              <?php if ($is_auth === true): ?>
                <span class="verdict-badge verdict-authentic">
                  <i class="fa-solid fa-circle-check"></i> Authentic
                </span>
              <?php elseif ($is_auth === false): ?>
                <span class="verdict-badge verdict-fake">
                  <i class="fa-solid fa-circle-xmark"></i> Fake / Altered
                </span>
              <?php else: ?>
                <span class="verdict-badge verdict-uncertain">
                  <i class="fa-solid fa-circle-question"></i> Uncertain
                </span>
              <?php endif; ?>
              <div style="flex:1;min-width:80px;">
                <div style="font-size:.68rem;color:#aaa;margin-bottom:3px;">
                  Confidence: <strong style="color:<?= $conf_color ?>"><?= $ai_conf ?>%</strong>
                </div>
                <div class="confidence-bar">
                  <div class="confidence-fill"
                       style="width:<?= $ai_conf ?>%;background:<?= $conf_color ?>;"></div>
                </div>
              </div>
            </div>

            <!-- Notes -->
            <?php if ($ai_notes): ?>
            <div class="doc-field">
              <div class="doc-field-label">AI Notes</div>
              <div style="font-size:.78rem;color:#555;line-height:1.55;background:#f8fafc;
                          border-radius:6px;padding:8px 10px;border-left:3px solid #2563eb;">
                <?= htmlspecialchars($ai_notes) ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- Red flags -->
            <?php if (!empty($ai_flags)): ?>
            <div class="doc-field" style="margin-top:10px;">
              <div class="doc-field-label" style="color:#dc2626;">
                <i class="fa-solid fa-triangle-exclamation"></i> Red Flags
              </div>
              <?php foreach ($ai_flags as $flag): ?>
              <div class="red-flag-item">
                <i class="fa-solid fa-xmark" style="margin-top:1px;flex-shrink:0;"></i>
                <?= htmlspecialchars($flag) ?>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Extracted data -->
            <?php if (!empty($ext)): ?>
            <div style="margin-top:12px;border-top:1px solid #f0f2f5;padding-top:10px;">
              <div class="doc-field-label" style="margin-bottom:8px;">
                <i class="fa-solid fa-id-card"></i> Extracted Data
              </div>
              <?php
              $fields_map = [
                'full_name'           => 'Full Name',
                'last_name'           => 'Last Name',
                'first_name'          => 'First Name',
                'middle_name'         => 'Middle Name',
                'date_of_birth'       => 'Date of Birth',
                'place_of_birth'      => 'Place of Birth',
                'sex'                 => 'Sex / Gender',
                'nationality'         => 'Nationality',
                'registration_number' => 'Registration No.',
                'date_issued'         => 'Date Issued',
                'issuing_authority'   => 'Issuing Authority',
                'other_details'       => 'Other Details',
              ];
              foreach ($fields_map as $key => $label):
                $val = $ext[$key] ?? null;
                if (!$val) continue;
              ?>
              <div class="doc-field">
                <div class="doc-field-label"><?= $label ?></div>
                <div class="doc-field-value"><?= htmlspecialchars($val) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div style="font-size:.68rem;color:#ccc;margin-top:10px;">
              Model: <?= htmlspecialchars($ai_model) ?>
              <?php if ($ai_time): ?>
                &nbsp;·&nbsp; <?= date('M d, Y g:i A', strtotime($ai_time)) ?>
              <?php endif; ?>
            </div>

            <?php else: ?>
            <div style="font-size:.8rem;color:#aaa;font-style:italic;line-height:1.7;">
              <i class="fa-solid fa-robot"></i>
              This document has not been AI-inspected yet.<br>
              Click <strong>Re-Inspect with AI</strong> below to analyze it.
            </div>
            <?php endif; ?>
          </div>

        </div><!-- end doc-body -->

        <!-- Card footer -->
        <div class="doc-footer">
          <div style="font-size:.75rem;color:#aaa;">
            Document ID: <?= $doc['id'] ?>
            &nbsp;·&nbsp; Pre-Reg ID: <?= $doc['pre_reg_id'] ?>
          </div>
          <form method="POST" action="ai_reinspect.php"
                style="display:inline-flex;gap:8px;align-items:center;">
            <input type="hidden" name="doc_id"    value="<?= $doc['id'] ?>"/>
            <input type="hidden" name="file_path" value="<?= htmlspecialchars($doc['file_path']) ?>"/>
            <input type="hidden" name="doc_type"  value="<?= htmlspecialchars($doc['document_type']) ?>"/>
            <button type="submit" class="btn-primary">
              <i class="fa-solid fa-robot"></i> Re-Inspect with AI
            </button>
          </form>
        </div>

      </div><!-- end doc-card -->
      <?php endforeach; ?>

    </div>
  </div><!-- end content -->
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
document.querySelectorAll('.btn-doc-apv').forEach(function(btn) {
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
document.querySelectorAll('.btn-doc-rej').forEach(function(btn) {
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
