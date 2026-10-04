<?php
// ============================================================
//  DOCUMENT_REVIEW.PHP  (admin/)
//  Two tabs: Document Generation | AI Inspection
//  Each tab has its own search + filter.
// ============================================================
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))     { header('Location: ../auth/signin.php'); exit; }
if (!is_admin_or_staff())            { header('Location: ../auth/signin.php'); exit; }
if (!enrollment_tables_exist($conn)) { header('Location: ../shared/full_setup.php'); exit; }

$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));

@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_result JSON DEFAULT NULL");
@$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_inspected_at TIMESTAMP NULL DEFAULT NULL");

// ── Active tab ────────────────────────────────────────────────
$active_tab = in_array($_GET['tab'] ?? '', ['generation','inspection']) ? $_GET['tab'] : 'generation';

// ── Handle admin status update ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doc_id'])) {
    $doc_id     = (int)$_POST['doc_id'];
    $new_status = in_array($_POST['new_status'] ?? '', ['Approved','Rejected','Pending'])
                  ? $_POST['new_status'] : 'Pending';
    $stmt = $conn->prepare("UPDATE enrollment_documents SET status=? WHERE id=?");
    $stmt->bind_param('si', $new_status, $doc_id);
    $stmt->execute(); $stmt->close();
    header("Location: document_review.php?tab=$active_tab"); exit;
}

// ════════════════════════════════════════════════════
//  TAB 1: DOCUMENT GENERATION
// ════════════════════════════════════════════════════
$gen_search = trim($_GET['gen_q']      ?? '');
$gen_course = trim($_GET['gen_course'] ?? '');

$gen_where = "1=1";
if ($gen_search) {
    $esc = $conn->real_escape_string($gen_search);
    $gen_where .= " AND (p.first_name LIKE '%$esc%' OR p.last_name LIKE '%$esc%' OR p.ref_number LIKE '%$esc%')";
}
if ($gen_course) {
    $esc = $conn->real_escape_string($gen_course);
    $gen_where .= " AND p.course LIKE '%$esc%'";
}

// Fetch one row per applicant (use pre_reg_id grouping)
$gen_data = [];
$res = $conn->query(
    "SELECT p.id AS pre_reg_id,
            p.first_name, p.last_name, p.course, p.ref_number, p.status AS app_status,
            COUNT(d.id) AS doc_count,
            SUM(CASE WHEN d.status='Approved' THEN 1 ELSE 0 END) AS doc_approved,
            GROUP_CONCAT(d.document_type) AS doc_types
     FROM pre_registrations p
     LEFT JOIN enrollment_documents d ON d.pre_reg_id = p.id
     WHERE $gen_where
     GROUP BY p.id
     ORDER BY p.submitted_at DESC"
);
if ($res) while ($r = $res->fetch_assoc()) $gen_data[] = $r;

$required_doc_types = ['BirthCertificate','ReportCard','GoodMoral'];

// ════════════════════════════════════════════════════
//  TAB 2: AI INSPECTION — student-centric view
//  One row per applicant; clicking expands their docs
// ════════════════════════════════════════════════════
$ai_search  = trim($_GET['ai_q']      ?? '');
$ai_status  = trim($_GET['ai_status'] ?? '');
$ai_course  = trim($_GET['ai_course'] ?? '');
$rows_pp    = 15;
$ai_page    = max(1, (int)($_GET['ai_page'] ?? 1));

// Build WHERE against pre_registrations
$ai_where = "1=1";
if ($ai_search) {
    $esc = $conn->real_escape_string($ai_search);
    $ai_where .= " AND (p.first_name LIKE '%$esc%' OR p.last_name LIKE '%$esc%' OR p.ref_number LIKE '%$esc%' OR p.email LIKE '%$esc%')";
}
if ($ai_course) {
    $esc = $conn->real_escape_string($ai_course);
    $ai_where .= " AND p.course LIKE '%$esc%'";
}
if ($ai_status) {
    // filter by whether the student has at least one doc with this status
    $esc = $conn->real_escape_string($ai_status);
    $ai_where .= " AND EXISTS (SELECT 1 FROM enrollment_documents dx WHERE dx.pre_reg_id=p.id AND dx.status='$esc')";
}

// Count distinct applicants
$ai_total = (int)$conn->query(
    "SELECT COUNT(DISTINCT p.id) c FROM pre_registrations p WHERE $ai_where"
)->fetch_assoc()['c'];

$ai_pages = max(1, (int)ceil($ai_total / $rows_pp));
$ai_page  = min($ai_page, $ai_pages);

// Fetch applicants + aggregate doc info
$ai_applicants = [];
$res2 = $conn->query(
    "SELECT p.id AS pre_reg_id,
            p.first_name, p.last_name, p.email, p.phone,
            p.course, p.year_level, p.ref_number,
            p.status AS app_status, p.submitted_at,
            COUNT(d.id) AS doc_count,
            SUM(CASE WHEN d.ai_result IS NOT NULL THEN 1 ELSE 0 END) AS ai_count,
            SUM(CASE WHEN d.status='Approved' THEN 1 ELSE 0 END) AS approved_count,
            GROUP_CONCAT(d.document_type ORDER BY d.uploaded_at SEPARATOR ',') AS doc_types
     FROM pre_registrations p
     LEFT JOIN enrollment_documents d ON d.pre_reg_id = p.id
     WHERE $ai_where
     GROUP BY p.id
     ORDER BY p.submitted_at DESC
     LIMIT $rows_pp OFFSET " . (($ai_page - 1) * $rows_pp)
);
if ($res2) while ($r = $res2->fetch_assoc()) $ai_applicants[] = $r;

// For each applicant, pre-fetch their documents
$ai_docs_by_student = [];
if ($ai_applicants) {
    $pids = implode(',', array_map(fn($a)=>(int)$a['pre_reg_id'], $ai_applicants));
    $res3 = $conn->query(
        "SELECT d.*, p.first_name, p.last_name, p.course, p.email, p.phone,
                p.status AS app_status, p.ref_number
         FROM enrollment_documents d
         LEFT JOIN pre_registrations p ON d.pre_reg_id = p.id
         WHERE d.pre_reg_id IN ($pids)
         ORDER BY d.pre_reg_id, d.uploaded_at ASC"
    );
    if ($res3) while ($r = $res3->fetch_assoc()) {
        $ai_docs_by_student[$r['pre_reg_id']][] = $r;
    }
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
    /* ── Page tabs ── */
    .page-tabs { display:flex; gap:0; border-bottom:2px solid #e5e7eb; margin:0 24px 20px; }
    .page-tab {
      padding:10px 22px; font-size:.88rem; font-weight:600; color:#888;
      border:none; background:none; cursor:pointer; border-bottom:3px solid transparent;
      margin-bottom:-2px; font-family:inherit; transition:color .15s,border-color .15s;
    }
    .page-tab.active { color:#1a3a8c; border-bottom-color:#1a3a8c; }
    .page-tab:hover:not(.active) { color:#555; }
    .tab-pane { display:none; }
    .tab-pane.active { display:block; }

    /* ── Doc cards ── */
    .doc-card {
      background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.08);
      overflow:hidden; margin-bottom:20px;
    }
    .doc-card-header { padding:14px 20px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .doc-card-header.authentic { background:#f0fdf4; border-left:5px solid #22c55e; }
    .doc-card-header.fake      { background:#fff1f2; border-left:5px solid #ef4444; }
    .doc-card-header.uncertain { background:#fffbeb; border-left:5px solid #f59e0b; }
    .doc-card-header.pending   { background:#f8fafc; border-left:5px solid #94a3b8; }
    .verdict-badge { display:inline-flex; align-items:center; gap:6px; padding:5px 14px; border-radius:20px; font-size:.78rem; font-weight:700; }
    .verdict-authentic { background:#dcfce7; color:#16a34a; }
    .verdict-fake      { background:#fee2e2; color:#dc2626; }
    .verdict-uncertain { background:#fff7ed; color:#d97706; }
    .verdict-pending   { background:#f1f5f9; color:#64748b; }
    .doc-body { display:grid; grid-template-columns:1fr 1fr 1fr; gap:0; }
    .doc-section { padding:18px 20px; border-right:1px solid #f0f2f5; }
    .doc-section:last-child { border-right:none; }
    .doc-section h4 { font-size:.72rem; font-weight:700; text-transform:uppercase; color:#1a3a8c; letter-spacing:.04em; margin-bottom:12px; padding-bottom:6px; border-bottom:1.5px solid #eff6ff; }
    .doc-field { margin-bottom:9px; }
    .doc-field-label { font-size:.68rem; color:#aaa; font-weight:600; text-transform:uppercase; margin-bottom:2px; }
    .doc-field-value { font-size:.82rem; color:#1a1a2e; font-weight:500; }
    .red-flag-item { background:#fff1f2; color:#dc2626; border-radius:6px; padding:5px 10px; font-size:.75rem; margin-bottom:5px; display:flex; align-items:flex-start; gap:6px; }
    .doc-footer { padding:12px 20px; border-top:1px solid #f0f2f5; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
    .confidence-bar  { height:6px; border-radius:3px; background:#e2e8f0; flex:1; min-width:80px; }
    .confidence-fill { height:6px; border-radius:3px; transition:width .3s; }
    @media (max-width:700px) { .doc-body { grid-template-columns:1fr; } .doc-section { border-right:none; border-bottom:1px solid #f0f2f5; } }

    /* ── Gen applicant card ── */
    .gen-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:10px; padding:14px 18px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
    .gen-card:hover { border-color:#bfdbfe; box-shadow:0 2px 12px rgba(37,99,235,.07); }

    /* ── AI inspection: student rows ── */
    .ai-student-row { background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; margin-bottom:12px; overflow:hidden; transition:border-color .15s, box-shadow .15s; }
    .ai-student-row:hover { border-color:#bfdbfe; }
    .ai-student-header { padding:14px 18px; display:flex; align-items:center; gap:12px; }
    .ai-student-header:hover { background:#f8fafc; }
    .ai-student-docs { border-top:1.5px solid #e8edf4; }
    .ai-doc-panel { padding:18px; border-bottom:1px solid #f0f2f5; }
    .ai-doc-panel:last-child { border-bottom:none; }

    /* ── Buttons ── */
    .btn-approve, .btn-reject, .btn-secondary, .btn-primary, .btn-view-file {
      font-family:'Segoe UI',sans-serif; font-size:.82rem; font-weight:700; cursor:pointer;
      border:none; border-radius:8px; padding:8px 18px;
      display:inline-flex; align-items:center; gap:6px; text-decoration:none; transition:background .15s,box-shadow .15s;
    }
    .btn-approve  { background:#16a34a; color:#fff; } .btn-approve:hover  { background:#15803d; }
    .btn-reject   { background:#ef4444; color:#fff; } .btn-reject:hover   { background:#dc2626; }
    .btn-primary  { background:#1a3a8c; color:#fff; } .btn-primary:hover  { background:#142d6e; }
    .btn-secondary{ background:none; color:#555; border:1.5px solid #d0d7e2; } .btn-secondary:hover { background:#f0f4f8; }
    .btn-view-file{ background:#eff6ff; color:#2563eb; border:1.5px solid #bfdbfe; } .btn-view-file:hover { background:#dbeafe; }
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
      <h2 class="page-title"><i class="fa-solid fa-file-shield"></i> Document Review</h2>
    </div>

    <?php if (isset($_GET['ai_done'])): ?>
    <div class="auth-success" style="margin:0 24px 16px;">
      <i class="fa-solid fa-robot"></i> AI inspection complete for document #<?= (int)$_GET['ai_done'] ?>.
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['ai_err'])): ?>
    <div class="auth-error" style="margin:0 24px 16px;">
      <i class="fa-solid fa-circle-xmark"></i> AI inspection failed: <?= htmlspecialchars(urldecode($_GET['ai_err'])) ?>
    </div>
    <?php endif; ?>

    <!-- ── Tab bar ── -->
    <div class="page-tabs">
      <button class="page-tab <?= $active_tab==='generation'?'active':'' ?>"
              onclick="switchTab('generation')">
        <i class="fa-solid fa-file-word"></i> Document Generation
      </button>
      <button class="page-tab <?= $active_tab==='inspection'?'active':'' ?>"
              onclick="switchTab('inspection')">
        <i class="fa-solid fa-robot"></i> AI Inspection
      </button>
    </div>

    <!-- ══════════════════════════════════════════════
         TAB 1: DOCUMENT GENERATION
    ══════════════════════════════════════════════ -->
    <div id="tab-generation" class="tab-pane <?= $active_tab==='generation'?'active':'' ?>">

      <!-- Search + filter -->
      <form method="GET" style="padding:0 24px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="tab" value="generation"/>
        <div class="search-wrap" style="flex:1;min-width:200px;max-width:340px;">
          <input type="text" name="gen_q" value="<?= htmlspecialchars($gen_search) ?>"
                 placeholder="Search applicant name or reference…"/>
          <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <select name="gen_course" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;">
          <option value="">All Courses</option>
          <?php foreach(['Information Technology','Computer Engineering','Psychology','Elementary Education','Secondary Education','Criminology','Entrepreneurship','Marketing Management','Human Resource Management','Financial Management','Office Administration','Tourism Management','Hospitality Management'] as $c): ?>
          <option value="<?= $c ?>" <?= str_contains($gen_course,$c)?'selected':'' ?>><?= $c ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-primary" style="padding:7px 16px;font-size:.82rem;">
          <i class="fa-solid fa-filter"></i> Filter
        </button>
        <?php if($gen_search||$gen_course): ?>
        <a href="?tab=generation" style="padding:7px 12px;font-size:.82rem;border:1.5px solid #d0d7e2;border-radius:8px;color:#555;text-decoration:none;">Clear</a>
        <?php endif; ?>
        <span style="font-size:.78rem;color:#aaa;"><?= count($gen_data) ?> applicant<?= count($gen_data)!==1?'s':'' ?></span>
      </form>

      <div style="padding:0 24px;">
        <?php if (empty($gen_data)): ?>
        <div class="crud-card" style="text-align:center;padding:40px;color:#aaa;">
          <i class="fa-solid fa-folder-open" style="font-size:2rem;display:block;margin-bottom:12px;"></i>
          No applicants found.
        </div>
        <?php endif; ?>

        <?php foreach ($gen_data as $appl):
          $appl_types = $appl['doc_types'] ? explode(',', $appl['doc_types']) : [];
          $has_all    = count(array_intersect($required_doc_types, $appl_types)) === 3;
          $all_apv    = (int)$appl['doc_approved'] >= (int)$appl['doc_count'] && $appl['doc_count'] > 0;
          $appl_name  = htmlspecialchars(trim($appl['first_name'].' '.$appl['last_name']));
          $sc = match($appl['app_status']) {
              'Approved'=>'#22c55e','Enrolled'=>'#2563eb','Rejected'=>'#ef4444',default=>'#f59e0b'
          };
        ?>
        <div class="gen-card">
          <div>
            <div style="font-weight:700;font-size:.92rem;color:#1a1a2e;margin-bottom:4px;">
              <?= $appl_name ?>
              <span style="font-size:.72rem;font-weight:400;color:#aaa;margin-left:6px;">
                <?= htmlspecialchars($appl['ref_number'] ?? '—') ?>
              </span>
              <span style="font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:20px;
                           background:<?= $sc ?>18;color:<?= $sc ?>;margin-left:6px;">
                <?= htmlspecialchars($appl['app_status']) ?>
              </span>
            </div>
            <div style="font-size:.78rem;color:#888;margin-bottom:6px;">
              <?= htmlspecialchars(preg_replace('/Bachelor of Science in /i','BS ',$appl['course'])) ?>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
              <?php foreach($required_doc_types as $rtype):
                $submitted = in_array($rtype, $appl_types);
                $labels = ['BirthCertificate'=>'Birth Cert','ReportCard'=>'Report Card','GoodMoral'=>'Good Moral'];
                $clr    = $submitted ? '#16a34a' : '#dc2626';
                $ico    = $submitted ? 'fa-circle-check' : 'fa-circle-xmark';
              ?>
              <span style="font-size:.75rem;color:<?= $clr ?>;">
                <i class="fa-solid <?= $ico ?>"></i> <?= $labels[$rtype] ?>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
            <?php if (!$has_all): ?>
            <span style="font-size:.78rem;color:#d97706;">
              Missing <?= 3 - count(array_intersect($required_doc_types,$appl_types)) ?> doc(s)
            </span>
            <?php endif; ?>
            <button class="btn-primary btn-generate-doc"
                    data-pre-reg-id="<?= $appl['pre_reg_id'] ?>"
                    data-name="<?= $appl_name ?>"
                    <?= !$has_all ? 'disabled style="opacity:.45;cursor:not-allowed;"' : '' ?>>
              <i class="fa-solid fa-file-word"></i> Generate
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div><!-- end tab-generation -->

    <!-- ══════════════════════════════════════════════
         TAB 2: AI INSPECTION
    ══════════════════════════════════════════════ -->
    <div id="tab-inspection" class="tab-pane <?= $active_tab==='inspection'?'active':'' ?>">

      <!-- Search + filters -->
      <form method="GET" style="padding:0 24px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="tab" value="inspection"/>
        <div class="search-wrap" style="flex:1;min-width:200px;max-width:300px;">
          <input type="text" name="ai_q" value="<?= htmlspecialchars($ai_search) ?>"
                 placeholder="Search applicant name or ref…"/>
          <i class="fa-solid fa-magnifying-glass"></i>
        </div>
        <select name="ai_status" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;">
          <option value="">All Doc Statuses</option>
          <?php foreach(['Pending','Approved','Rejected'] as $s): ?>
          <option value="<?= $s ?>" <?= $ai_status===$s?'selected':'' ?>><?= $s ?></option>
          <?php endforeach; ?>
        </select>
        <select name="ai_course" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;max-width:200px;">
          <option value="">All Courses</option>
          <?php foreach(['Information Technology','Computer Engineering','Psychology','Elementary Education','Secondary Education','Criminology','Entrepreneurship','Marketing Management','Human Resource Management','Financial Management','Office Administration','Tourism Management','Hospitality Management'] as $c): ?>
          <option value="<?= $c ?>" <?= str_contains($ai_course,$c)?'selected':'' ?>><?= $c ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-primary" style="padding:7px 16px;font-size:.82rem;">
          <i class="fa-solid fa-filter"></i> Filter
        </button>
        <?php if($ai_search||$ai_status||$ai_course): ?>
        <a href="?tab=inspection" style="padding:7px 12px;font-size:.82rem;border:1.5px solid #d0d7e2;border-radius:8px;color:#555;text-decoration:none;">Clear</a>
        <?php endif; ?>
        <span style="font-size:.78rem;color:#aaa;"><?= $ai_total ?> student<?= $ai_total!==1?'s':'' ?></span>
      </form>

      <div style="padding:0 24px;">
        <?php if (empty($ai_applicants)): ?>
        <div class="crud-card" style="text-align:center;padding:40px;color:#aaa;">
          <i class="fa-solid fa-users" style="font-size:2rem;display:block;margin-bottom:12px;"></i>
          No students found.
        </div>
        <?php endif; ?>

        <?php foreach ($ai_applicants as $appl):
          $pid       = (int)$appl['pre_reg_id'];
          $full_name = htmlspecialchars(trim($appl['first_name'].' '.$appl['last_name']));
          $course_short = htmlspecialchars(preg_replace('/Bachelor of Science in |Bachelor of |Bachelor in /i','BS ',$appl['course']));
          $docs_for  = $ai_docs_by_student[$pid] ?? [];
          $sc = match($appl['app_status']) {
              'Approved'=>'#22c55e','Enrolled'=>'#2563eb','Rejected'=>'#ef4444',default=>'#f59e0b'
          };
          $ai_done   = (int)$appl['ai_count'];
          $doc_total = (int)$appl['doc_count'];
        ?>
        <!-- ── Student row (click to expand) ── -->
        <div class="ai-student-row" id="airow-<?= $pid ?>">

          <!-- Summary header — always visible -->
          <div class="ai-student-header" onclick="toggleAiStudent(<?= $pid ?>)" style="cursor:pointer;">
            <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0;flex-wrap:wrap;">
              <div style="width:38px;height:38px;border-radius:50%;background:#eff6ff;
                          display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="fa-solid fa-user-graduate" style="color:#1a3a8c;font-size:1rem;"></i>
              </div>
              <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:.92rem;color:#1a1a2e;">
                  <?= $full_name ?>
                  <span style="font-size:.72rem;font-weight:400;color:#aaa;margin-left:8px;">
                    <?= htmlspecialchars($appl['ref_number'] ?? '—') ?>
                  </span>
                </div>
                <div style="font-size:.78rem;color:#888;margin-top:2px;"><?= $course_short ?></div>
              </div>
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex-shrink:0;">
                <span style="font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:20px;
                             background:<?= $sc ?>18;color:<?= $sc ?>;">
                  <?= htmlspecialchars($appl['app_status']) ?>
                </span>
                <span style="font-size:.75rem;color:#888;">
                  <?= $doc_total ?> doc<?= $doc_total!==1?'s':'' ?>
                  <?php if ($doc_total > 0): ?>
                  &nbsp;·&nbsp;
                  <?php if ($ai_done === $doc_total): ?>
                    <span style="color:#16a34a;font-weight:600;"><i class="fa-solid fa-robot"></i> All inspected</span>
                  <?php elseif ($ai_done > 0): ?>
                    <span style="color:#d97706;font-weight:600;"><i class="fa-solid fa-robot"></i> <?= $ai_done ?>/<?= $doc_total ?> inspected</span>
                  <?php else: ?>
                    <span style="color:#aaa;"><i class="fa-solid fa-robot"></i> Not inspected</span>
                  <?php endif; ?>
                  <?php endif; ?>
                </span>
                <i class="fa-solid fa-chevron-down ai-chevron" id="chev-<?= $pid ?>"
                   style="color:#aaa;font-size:.78rem;transition:transform .2s;"></i>
              </div>
            </div>
          </div>

          <!-- Expanded document panels -->
          <div class="ai-student-docs" id="aidocs-<?= $pid ?>" style="display:none;">
            <?php if (empty($docs_for)): ?>
            <div style="padding:20px;text-align:center;color:#aaa;font-size:.85rem;">
              No documents uploaded yet.
            </div>
            <?php else: foreach ($docs_for as $doc):
              $ai_raw  = $doc['ai_result'] ?? null;
              $ai      = $ai_raw ? json_decode($ai_raw, true) : null;
              $is_auth = $ai['is_authentic'] ?? null;
              $ai_conf = (int)($ai['confidence'] ?? 0);
              $ai_notes_txt = $ai['notes'] ?? '';
              $ai_flags     = $ai['red_flags'] ?? [];
              $ai_model     = $ai['model'] ?? 'gpt-4o';
              $ai_time      = $ai['inspected_at'] ?? '';
              $blur         = $ai['image_blur'] ?? null;
              $align_ok     = $ai['alignment_ok'] ?? null;
              $align_notes  = $ai['alignment_notes'] ?? '';
              $legit        = $ai['is_legitimate'] ?? null;
              $legit_notes  = $ai['legitimacy_notes'] ?? '';

              $conf_color = $ai_conf>=80?'#22c55e':($ai_conf>=50?'#f59e0b':'#ef4444');

              $doc_labels = ['BirthCertificate'=>'PSA Birth Certificate','ReportCard'=>'Report Card (Form 138)','GoodMoral'=>'Good Moral Certificate'];
              $dlabel = $doc_labels[$doc['document_type']] ?? $doc['document_type'];

              // Status badge colors
              $dsc = match($doc['status']) { 'Approved'=>'#16a34a','Rejected'=>'#dc2626',default=>'#f59e0b' };
            ?>
            <div class="ai-doc-panel">
              <!-- Doc header -->
              <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
                <div>
                  <div style="font-weight:700;font-size:.9rem;color:#1a1a2e;">
                    <i class="fa-solid fa-file-lines" style="color:#1a3a8c;margin-right:6px;"></i>
                    <?= htmlspecialchars($dlabel) ?>
                    <span style="font-size:.72rem;font-weight:400;color:#aaa;margin-left:6px;">#<?= $doc['id'] ?></span>
                  </div>
                  <div style="font-size:.75rem;color:#888;margin-top:3px;">
                    Uploaded: <?= date('M d, Y g:i A', strtotime($doc['uploaded_at'])) ?>
                    &nbsp;·&nbsp;
                    <span style="font-weight:700;color:<?= $dsc ?>;"><?= $doc['status'] ?></span>
                  </div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                  <a href="../requirements/file.php?path=<?= urlencode($doc['file_path']) ?>"
                     target="_blank" class="btn-view-file">
                    <i class="fa-solid fa-eye"></i> View
                  </a>
                  <!-- Approve / Reject inline -->
                  <?php if($doc['status']!=='Approved'): ?>
                  <button type="button" class="btn-approve btn-doc-apv" data-doc-id="<?= $doc['id'] ?>" style="padding:6px 12px;font-size:.78rem;">
                    <i class="fa-solid fa-circle-check"></i> Approve
                  </button>
                  <?php else: ?>
                  <span style="font-size:.78rem;font-weight:700;color:#16a34a;"><i class="fa-solid fa-circle-check"></i> Approved</span>
                  <?php endif; ?>
                  <?php if($doc['status']!=='Rejected'): ?>
                  <button type="button" class="btn-reject btn-doc-rej" data-doc-id="<?= $doc['id'] ?>" style="padding:6px 12px;font-size:.78rem;">
                    <i class="fa-solid fa-circle-xmark"></i> Reject
                  </button>
                  <?php endif; ?>
                  <!-- Re-inspect button -->
                  <?php if ($doc['document_type']==='BirthCertificate'): ?>
                  <form method="POST" action="ai_reinspect.php" style="display:inline;">
                    <input type="hidden" name="doc_id"    value="<?= $doc['id'] ?>"/>
                    <input type="hidden" name="file_path" value="<?= htmlspecialchars($doc['file_path']) ?>"/>
                    <input type="hidden" name="doc_type"  value="<?= htmlspecialchars($doc['document_type']) ?>"/>
                    <button type="submit" class="btn-primary" style="padding:6px 14px;font-size:.78rem;">
                      <i class="fa-solid fa-robot"></i> Re-Inspect
                    </button>
                  </form>
                  <?php else: ?>
                  <span style="font-size:.73rem;color:#aaa;font-style:italic;">AI: Birth Cert only</span>
                  <?php endif; ?>
                </div>
              </div>

              <!-- AI result -->
              <?php if ($ai): ?>
              <div style="background:#f8fafc;border-radius:10px;padding:14px 16px;">
                <!-- Verdict row -->
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                  <?php if ($is_auth===true): ?>
                  <span class="verdict-badge verdict-authentic"><i class="fa-solid fa-circle-check"></i> Authentic</span>
                  <?php elseif ($is_auth===false): ?>
                  <span class="verdict-badge verdict-fake"><i class="fa-solid fa-circle-xmark"></i> Fake / Altered</span>
                  <?php else: ?>
                  <span class="verdict-badge verdict-uncertain"><i class="fa-solid fa-circle-question"></i> Uncertain</span>
                  <?php endif; ?>
                  <div style="flex:1;min-width:120px;">
                    <div style="font-size:.7rem;color:#aaa;margin-bottom:4px;">
                      Confidence: <strong style="color:<?= $conf_color ?>"><?= $ai_conf ?>%</strong>
                    </div>
                    <div class="confidence-bar"><div class="confidence-fill" style="width:<?= $ai_conf ?>%;background:<?= $conf_color ?>;"></div></div>
                  </div>
                  <!-- Quality pills -->
                  <div style="display:flex;gap:5px;flex-wrap:wrap;">
                    <?php if ($blur!==null):
                      [$bc,$bl]=match($blur){'sharp'=>['#16a34a','Sharp'],'slightly_blurred'=>['#d97706','Slightly Blurred'],'too_blurred'=>['#dc2626','Too Blurred'],default=>['#6b7280','Unknown']}; ?>
                    <span style="background:<?= $bc ?>18;color:<?= $bc ?>;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;"><?= $bl ?></span>
                    <?php endif; ?>
                    <?php if ($align_ok!==null):[$ac,$al]=$align_ok?['#16a34a','Aligned']:['#d97706','Misaligned']; ?>
                    <span style="background:<?= $ac ?>18;color:<?= $ac ?>;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;"><?= $al ?></span>
                    <?php endif; ?>
                    <?php if ($legit!==null):[$lc,$ll]=match(true){$legit===true=>['#16a34a','Legitimate'],$legit===false=>['#dc2626','Not Legitimate'],default=>['#d97706','Uncertain']}; ?>
                    <span style="background:<?= $lc ?>18;color:<?= $lc ?>;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;"><?= $ll ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <?php if ($ai_notes_txt): ?>
                <div style="font-size:.8rem;color:#555;line-height:1.55;background:#fff;
                            border-radius:6px;padding:8px 12px;border-left:3px solid #2563eb;margin-bottom:8px;">
                  <?= htmlspecialchars($ai_notes_txt) ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($ai_flags)): ?>
                <div style="display:flex;flex-direction:column;gap:4px;">
                  <?php foreach($ai_flags as $flag): ?>
                  <div class="red-flag-item"><i class="fa-solid fa-xmark" style="flex-shrink:0;margin-top:1px;"></i><?= htmlspecialchars($flag) ?></div>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div style="font-size:.68rem;color:#ccc;margin-top:8px;">
                  <?= htmlspecialchars($ai_model) ?>
                  <?php if($ai_time): ?>&nbsp;·&nbsp;<?= date('M d, Y g:i A',strtotime($ai_time)) ?><?php endif; ?>
                </div>
              </div>
              <?php else: ?>
              <div style="background:#f8fafc;border-radius:8px;padding:12px 16px;
                          font-size:.82rem;color:#aaa;font-style:italic;">
                <?php if ($doc['document_type']==='BirthCertificate'): ?>
                Not AI-inspected yet — click <strong>Re-Inspect</strong> above to run.
                <?php else: ?>
                AI inspection is only available for the PSA Birth Certificate.
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </div><!-- end ai-doc-panel -->
            <?php endforeach; endif; ?>
          </div><!-- end ai-student-docs -->
        </div><!-- end ai-student-row -->
        <?php endforeach; ?>

        <!-- Pagination -->
        <?php if ($ai_pages > 1): ?>
        <div class="crud-pagination">
          <?php
          $qs = http_build_query(['tab'=>'inspection','ai_q'=>$ai_search,'ai_status'=>$ai_status,'ai_course'=>$ai_course]);
          if ($ai_page > 1) echo "<a href='?$qs&ai_page=".($ai_page-1)."' class='pg-btn pg-label'>&laquo;</a>";
          for ($p=max(1,$ai_page-2); $p<=min($ai_pages,$ai_page+2); $p++)
              echo "<a href='?$qs&ai_page=$p' class='pg-btn".($p===$ai_page?' active':'')."'>$p</a>";
          if ($ai_page < $ai_pages) echo "<a href='?$qs&ai_page=".($ai_page+1)."' class='pg-btn pg-label'>&raquo;</a>";
          ?>
        </div>
        <?php endif; ?>
      </div>
    </div><!-- end tab-inspection -->

  </div><!-- end content -->
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
// ── Tab switching (also updates URL without page reload) ──────
function switchTab(name) {
    document.querySelectorAll('.tab-pane').forEach(function(p){ p.classList.remove('active'); });
    document.querySelectorAll('.page-tab').forEach(function(b){ b.classList.remove('active'); });
    document.getElementById('tab-' + name).classList.add('active');
    document.querySelectorAll('.page-tab').forEach(function(b){
        if (b.getAttribute('onclick') === "switchTab('" + name + "')") b.classList.add('active');
    });
    var url = new URL(window.location.href);
    url.searchParams.set('tab', name);
    history.replaceState(null, '', url.toString());
}

// ── Expand/collapse AI student row ───────────────────────────
function toggleAiStudent(pid) {
    var docs  = document.getElementById('aidocs-' + pid);
    var chev  = document.getElementById('chev-' + pid);
    var row   = document.getElementById('airow-' + pid);
    if (!docs) return;
    var open = docs.style.display === 'none' || docs.style.display === '';
    docs.style.display = open ? 'block' : 'none';
    if (chev) chev.style.transform = open ? 'rotate(180deg)' : '';
    if (row)  row.style.borderColor = open ? '#93c5fd' : '';
}

// ── Document approve/reject ───────────────────────────────────
document.querySelectorAll('.btn-doc-apv').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var docId = btn.dataset.docId;
        showConfirm('Approve this document?', function() {
            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="doc_id" value="'+docId+'"/>'+
                             '<input type="hidden" name="new_status" value="Approved"/>';
            document.body.appendChild(form); form.submit();
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
            form.innerHTML = '<input type="hidden" name="doc_id" value="'+docId+'"/>'+
                             '<input type="hidden" name="new_status" value="Rejected"/>';
            document.body.appendChild(form); form.submit();
        });
    });
});

// ── Generate Document ─────────────────────────────────────────
document.querySelectorAll('.btn-generate-doc').forEach(function(btn) {
    btn.addEventListener('click', function() {
        if (btn.disabled) return;
        var pid  = btn.dataset.preRegId;
        var name = btn.dataset.name;
        showConfirm('Generate the admission document for ' + name + '?', function() {
            var orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            var fd = new FormData(); fd.set('pre_reg_id', pid);
            fetch('../shared/generate_docx.php', { method:'POST', body:fd })
                .then(function(r) {
                    var ct = r.headers.get('content-type') || '';
                    if (ct.indexOf('json') !== -1) return r.json().then(function(j){ throw new Error(j.error||'Failed'); });
                    return r.blob();
                })
                .then(function(blob) {
                    var url = URL.createObjectURL(blob);
                    var a   = document.createElement('a');
                    a.href  = url; a.download = 'admission_'+name.replace(/\s+/g,'_')+'_'+pid+'.docx';
                    document.body.appendChild(a); a.click(); document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    btn.disabled = false; btn.innerHTML = orig;
                })
                .catch(function(err) {
                    showAlertModal(err.message||'Generation failed.','error','Document Error');
                    btn.disabled = false; btn.innerHTML = orig;
                });
        }, 'Generate Document');
    });
});
</script>
</body>
</html>
