<?php
// ============================================================
//  DOCUMENT_REDACTION.PHP  (admin/)
//  Student-centric redaction review: one card per applicant,
//  expand to see their documents with redaction + AI results.
// ============================================================
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))     { header('Location: ../auth/signin.php'); exit; }
if (!is_admin_or_staff())            { header('Location: ../auth/signin.php'); exit; }
if (!enrollment_tables_exist($conn)) { header('Location: ../shared/full_setup.php'); exit; }

$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));

// Ensure columns exist
mysqli_report(MYSQLI_REPORT_OFF);
$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS redacted_path VARCHAR(500) DEFAULT NULL");
$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS redaction_status ENUM('pending','done','failed','skipped') NOT NULL DEFAULT 'pending'");
$conn->query("ALTER TABLE enrollment_documents MODIFY COLUMN redaction_status ENUM('pending','done','failed','skipped') NOT NULL DEFAULT 'pending'");
$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_result JSON DEFAULT NULL");
$conn->query("ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_inspected_at TIMESTAMP NULL DEFAULT NULL");

// ── Handle manual re-redact POST ──────────────────────────────
$action_msg = $action_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reredact') {
    $doc_id = (int)($_POST['doc_id'] ?? 0);
    if ($doc_id) {
        $row = $conn->query(
            "SELECT id, file_path, document_type FROM enrollment_documents WHERE id=$doc_id LIMIT 1"
        )->fetch_assoc();
        if ($row) {
            if ($row['document_type'] !== 'BirthCertificate') {
                $action_err = "Only PSA Birth Certificates are redacted and AI-inspected.";
            } else {
                require_once __DIR__ . '/../shared/redact_document.php';
                require_once __DIR__ . '/../shared/ai_inspect.php';
                $abs_original = realpath(__DIR__ . '/../requirements/' . $row['file_path']);
                $result = $abs_original ? redact_document($abs_original, $row['document_type']) : null;
                if ($result && $result['success']) {
                    $redacted_abs = $result['redacted_path'];
                    $redacted_rel = 'uploads/' . basename($redacted_abs);
                    $ai = ai_inspect_document($abs_original, $row['document_type'], $redacted_abs);
                    $ai_json = json_encode($ai);
                    $ai_time = $ai['inspected_at'] ?? null;
                    $stmt = $conn->prepare(
                        "UPDATE enrollment_documents SET redacted_path=?,redaction_status='done',ai_result=?,ai_inspected_at=? WHERE id=?"
                    );
                    $stmt->bind_param('sssi', $redacted_rel, $ai_json, $ai_time, $doc_id);
                    $stmt->execute(); $stmt->close();
                    $action_msg = "Document #$doc_id redacted and AI-inspected successfully.";
                } else {
                    $err = $result['error'] ?? 'Redaction failed.';
                    $stmt = $conn->prepare("UPDATE enrollment_documents SET redaction_status='failed' WHERE id=?");
                    $stmt->bind_param('i', $doc_id); $stmt->execute(); $stmt->close();
                    $action_err = "Redaction failed for #$doc_id: $err";
                }
            }
        }
    }
    // Preserve filters on redirect
    $qs = http_build_query(['q'=>$_POST['_q']??'','status'=>$_POST['_status']??'','course'=>$_POST['_course']??'']);
    header("Location: document_redaction.php?$qs&" . ($action_msg ? 'ok=1' : 'err=1'));
    exit;
}

// ── Filters ───────────────────────────────────────────────────
$search        = trim($_GET['q']      ?? '');
$filter_status = trim($_GET['status'] ?? '');   // redaction_status filter
$filter_course = trim($_GET['course'] ?? '');
$rows_pp       = 15;
$page          = max(1, (int)($_GET['page'] ?? 1));

$allowed_status = ['','done','pending','failed','skipped'];
if (!in_array($filter_status, $allowed_status)) $filter_status = '';

// Build WHERE against pre_registrations
$p_where = "1=1";
if ($search) {
    $esc = $conn->real_escape_string($search);
    $p_where .= " AND (p.first_name LIKE '%$esc%' OR p.last_name LIKE '%$esc%' OR p.ref_number LIKE '%$esc%' OR p.email LIKE '%$esc%')";
}
if ($filter_course) {
    $esc = $conn->real_escape_string($filter_course);
    $p_where .= " AND p.course LIKE '%$esc%'";
}
// If filtering by redaction status, only show applicants who have at least one doc with that status
if ($filter_status) {
    $esc = $conn->real_escape_string($filter_status);
    $p_where .= " AND EXISTS (SELECT 1 FROM enrollment_documents dx WHERE dx.pre_reg_id=p.id AND dx.redaction_status='$esc')";
}

$total_applicants = (int)$conn->query(
    "SELECT COUNT(DISTINCT p.id) c FROM pre_registrations p
     LEFT JOIN enrollment_documents d ON d.pre_reg_id=p.id
     WHERE $p_where"
)->fetch_assoc()['c'];

$total_pages = max(1, (int)ceil($total_applicants / $rows_pp));
$page = min($page, $total_pages);

// Fetch applicants
$applicants = [];
$res = $conn->query(
    "SELECT DISTINCT p.id AS pre_reg_id, p.first_name, p.last_name,
            p.email, p.course, p.ref_number, p.status AS app_status,
            COUNT(d.id) AS doc_count,
            SUM(CASE WHEN d.redaction_status='done' THEN 1 ELSE 0 END) AS done_count,
            SUM(CASE WHEN d.redaction_status='failed' THEN 1 ELSE 0 END) AS fail_count,
            SUM(CASE WHEN d.ai_result IS NOT NULL THEN 1 ELSE 0 END) AS ai_count
     FROM pre_registrations p
     LEFT JOIN enrollment_documents d ON d.pre_reg_id=p.id
     WHERE $p_where
     GROUP BY p.id
     ORDER BY p.submitted_at DESC
     LIMIT $rows_pp OFFSET " . (($page - 1) * $rows_pp)
);
if ($res) while ($r = $res->fetch_assoc()) $applicants[] = $r;

// Pre-fetch documents for all displayed applicants
$docs_by_applicant = [];
if ($applicants) {
    $pids = implode(',', array_map(fn($a) => (int)$a['pre_reg_id'], $applicants));
    $dres = $conn->query(
        "SELECT d.id, d.pre_reg_id, d.document_type, d.file_name, d.file_path,
                d.redacted_path, d.redaction_status, d.ai_result, d.ai_inspected_at,
                d.uploaded_at, d.status AS doc_status
         FROM enrollment_documents d
         WHERE d.pre_reg_id IN ($pids)
         ORDER BY d.uploaded_at ASC"
    );
    if ($dres) while ($r = $dres->fetch_assoc()) {
        $docs_by_applicant[$r['pre_reg_id']][] = $r;
    }
}

$APP_ROOT   = '../';
$ACTIVE_NAV = 'redaction';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Document Redaction — Admin</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <style>
    /* ── Student card ── */
    .student-card { background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; margin-bottom:14px; overflow:hidden; transition:border-color .15s,box-shadow .15s; }
    .student-card:hover { border-color:#bfdbfe; box-shadow:0 2px 12px rgba(37,99,235,.07); }
    .student-card-header { padding:14px 18px; cursor:pointer; display:flex; align-items:center; gap:12px; }
    .student-card-header:hover { background:#f8fafc; }
    .student-card-body   { border-top:1.5px solid #e8edf4; padding:16px 18px; display:none; }
    .student-card-body.open { display:block; }

    /* ── Document panel inside card ── */
    .doc-panel { border:1.5px solid #e5e7eb; border-radius:10px; margin-bottom:14px; overflow:hidden; }
    .doc-panel.status-done    { border-color:#16a34a; }
    .doc-panel.status-failed  { border-color:#ef4444; }
    .doc-panel.status-skipped { border-color:#d1d5db; }
    .doc-panel-header { padding:11px 16px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
    .doc-panel.status-done    .doc-panel-header { background:#f0fdf4; }
    .doc-panel.status-failed  .doc-panel-header { background:#fff1f2; }
    .doc-panel.status-skipped .doc-panel-header { background:#f8fafc; }
    .doc-panel-body { padding:14px 16px; }

    /* ── Preview grid ── */
    .preview-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:12px; }
    .preview-pane { border-radius:8px; overflow:hidden; border:1.5px solid #e5e7eb; }
    .preview-pane-header { padding:7px 12px; font-size:.73rem; font-weight:700; display:flex; align-items:center; gap:5px; }
    .preview-pane img { width:100%; display:block; max-height:420px; object-fit:contain; background:#f8fafc; }
    @media(max-width:700px) { .preview-grid { grid-template-columns:1fr; } }

    /* ── Badges ── */
    .badge-redacted { background:#dcfce7;color:#16a34a;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700; }
    .badge-pending  { background:#fff7ed;color:#d97706;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700; }
    .badge-failed   { background:#fee2e2;color:#dc2626;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700; }
    .ai-verdict { display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700; }
    .ai-true     { background:#dcfce7;color:#16a34a; }
    .ai-false    { background:#fee2e2;color:#dc2626; }
    .ai-uncertain{ background:#fff7ed;color:#d97706; }
    .ai-none     { background:#f3f4f6;color:#9ca3af; }
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
      <h2 class="page-title"><i class="fa-solid fa-shield-halved"></i> Document Redaction Review</h2>
    </div>

    <?php if (!empty($_GET['ok'])): ?>
    <div class="auth-success" style="margin:0 24px 14px;">
      <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($action_msg ?: 'Operation successful.') ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($_GET['err'])): ?>
    <div class="auth-error" style="margin:0 24px 14px;">
      <i class="fa-solid fa-circle-xmark"></i> An error occurred during the last operation.
    </div>
    <?php endif; ?>

    <!-- ── Search & filters ── -->
    <form method="GET" style="padding:0 24px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <div class="search-wrap" style="flex:1;min-width:220px;max-width:340px;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
               placeholder="Search name, reference or email…"/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <select name="status" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;">
        <option value="">All Statuses</option>
        <option value="done"    <?= $filter_status==='done'   ?'selected':'' ?>>Redacted</option>
        <option value="pending" <?= $filter_status==='pending'?'selected':'' ?>>Pending</option>
        <option value="failed"  <?= $filter_status==='failed' ?'selected':'' ?>>Failed</option>
        <option value="skipped" <?= $filter_status==='skipped'?'selected':'' ?>>N/A (Skipped)</option>
      </select>
      <select name="course" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;max-width:220px;">
        <option value="">All Courses</option>
        <?php foreach([
            'Information Technology','Computer Engineering','Library Information Science',
            'Psychology','Elementary Education','Technology and Livelihood Education',
            'Secondary Education','Physical Education','Criminology',
            'Accounting Information System','Entrepreneurship',
            'Marketing Management','Human Resource Management','Financial Management',
            'Office Administration','Tourism Management','Hospitality Management',
        ] as $c): ?>
        <option value="<?= $c ?>" <?= str_contains($filter_course,$c)?'selected':'' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-primary" style="padding:7px 16px;font-size:.82rem;">
        <i class="fa-solid fa-filter"></i> Filter
      </button>
      <?php if ($search||$filter_status||$filter_course): ?>
      <a href="document_redaction.php" style="padding:7px 12px;font-size:.82rem;border:1.5px solid #d0d7e2;border-radius:8px;color:#555;text-decoration:none;">Clear</a>
      <?php endif; ?>
      <span style="font-size:.78rem;color:#aaa;white-space:nowrap;"><?= $total_applicants ?> student<?= $total_applicants!==1?'s':'' ?></span>
    </form>

    <!-- ── Student cards ── -->
    <div style="padding:0 24px;">

      <?php if (empty($applicants)): ?>
      <div class="crud-card" style="text-align:center;padding:48px;color:#aaa;">
        <i class="fa-solid fa-shield-halved" style="font-size:2.2rem;display:block;margin-bottom:14px;opacity:.3;"></i>
        No students found<?= ($search||$filter_status||$filter_course) ? ' matching the current filters.' : '.' ?>
      </div>
      <?php endif; ?>

      <?php foreach ($applicants as $appl):
        $pid       = (int)$appl['pre_reg_id'];
        $full_name = htmlspecialchars(trim($appl['first_name'].' '.$appl['last_name']));
        $docs      = $docs_by_applicant[$pid] ?? [];
        $d_total   = (int)$appl['doc_count'];
        $d_done    = (int)$appl['done_count'];
        $d_fail    = (int)$appl['fail_count'];
        $d_ai      = (int)$appl['ai_count'];
        $sc = match($appl['app_status']){
            'Approved'=>'#22c55e','Enrolled'=>'#2563eb','Rejected'=>'#ef4444',default=>'#f59e0b'
        };
        // Overall redaction badge for the card header
        if ($d_fail > 0)       { $hdr_clr='#dc2626'; $hdr_bg='#fee2e2'; $hdr_lbl='Has Failures'; }
        elseif ($d_done === $d_total && $d_total > 0) { $hdr_clr='#16a34a'; $hdr_bg='#dcfce7'; $hdr_lbl='All Redacted'; }
        elseif ($d_done > 0)   { $hdr_clr='#d97706'; $hdr_bg='#fff7ed'; $hdr_lbl='Partial'; }
        else                   { $hdr_clr='#9ca3af'; $hdr_bg='#f3f4f6'; $hdr_lbl='Pending'; }
      ?>
      <div class="student-card" id="sc-<?= $pid ?>">

        <!-- Student header — click to expand -->
        <div class="student-card-header" onclick="toggleStudent(<?= $pid ?>)">
          <div style="width:36px;height:36px;border-radius:50%;background:#eff6ff;
                      display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fa-solid fa-user-graduate" style="color:#1a3a8c;font-size:.9rem;"></i>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:.9rem;color:#1a1a2e;">
              <?= $full_name ?>
              <span style="font-size:.72rem;font-weight:400;color:#aaa;margin-left:8px;">
                <?= htmlspecialchars($appl['ref_number'] ?? '—') ?>
              </span>
            </div>
            <div style="font-size:.75rem;color:#888;margin-top:2px;">
              <?= htmlspecialchars(preg_replace('/Bachelor of Science in |Bachelor of |Bachelor in /i','BS ',$appl['course'])) ?>
              &nbsp;·&nbsp;
              <span style="font-weight:700;color:<?= $sc ?>;"><?= htmlspecialchars($appl['app_status']) ?></span>
              &nbsp;·&nbsp;
              <?= $d_total ?> doc<?= $d_total!==1?'s':'' ?>
              <?php if ($d_done > 0): ?>
              &nbsp;·&nbsp;<span style="color:#16a34a;"><?= $d_done ?> redacted</span>
              <?php endif; ?>
              <?php if ($d_ai > 0): ?>
              &nbsp;·&nbsp;<span style="color:#2563eb;"><?= $d_ai ?> AI inspected</span>
              <?php endif; ?>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
            <span style="background:<?= $hdr_bg ?>;color:<?= $hdr_clr ?>;padding:3px 10px;
                         border-radius:20px;font-size:.72rem;font-weight:700;">
              <?= $hdr_lbl ?>
            </span>
            <i class="fa-solid fa-chevron-down" id="chev-<?= $pid ?>"
               style="color:#aaa;font-size:.78rem;transition:transform .2s;"></i>
          </div>
        </div>

        <!-- Documents — hidden until expanded -->
        <div class="student-card-body" id="scb-<?= $pid ?>">
          <?php if (empty($docs)): ?>
          <div style="padding:20px;text-align:center;color:#aaa;font-size:.85rem;">
            No documents uploaded yet.
          </div>
          <?php else: foreach ($docs as $doc):
            $rs       = $doc['redaction_status'];
            $ai_raw   = $doc['ai_result'] ? json_decode($doc['ai_result'],true) : null;
            $ai_v     = $ai_raw['is_authentic'] ?? null;
            $ai_conf  = (int)($ai_raw['confidence'] ?? 0);
            $orig_url = '../requirements/file.php?path=' . urlencode($doc['file_path']);
            $red_url  = $doc['redacted_path'] ? '../requirements/file.php?path=' . urlencode($doc['redacted_path']) : '';
            $doc_label_map = ['BirthCertificate'=>'PSA Birth Certificate','ReportCard'=>'Report Card (Form 138)','GoodMoral'=>'Good Moral Certificate'];
            $dlabel = $doc_label_map[$doc['document_type']] ?? $doc['document_type'];
            $panel_cls = match($rs){ 'done'=>'status-done','failed'=>'status-failed','skipped'=>'status-skipped',default=>'' };
          ?>
          <div class="doc-panel <?= $panel_cls ?>">
            <!-- Doc panel header -->
            <div class="doc-panel-header">
              <div>
                <div style="font-weight:700;font-size:.85rem;color:#1a1a2e;">
                  <i class="fa-solid fa-file-lines" style="color:#1a3a8c;margin-right:6px;"></i>
                  <?= htmlspecialchars($dlabel) ?>
                  <span style="font-size:.7rem;font-weight:400;color:#aaa;margin-left:6px;">#<?= $doc['id'] ?></span>
                </div>
                <div style="font-size:.72rem;color:#888;margin-top:2px;">
                  <?= date('M d, Y g:i A', strtotime($doc['uploaded_at'])) ?>
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <!-- Redaction status -->
                <?php if ($rs==='done'): ?>
                <span class="badge-redacted"><i class="fa-solid fa-shield-halved"></i> Redacted</span>
                <?php elseif ($rs==='failed'): ?>
                <span class="badge-failed"><i class="fa-solid fa-triangle-exclamation"></i> Failed</span>
                <?php elseif ($rs==='skipped'): ?>
                <span style="background:#f3f4f6;color:#6b7280;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;">
                  <i class="fa-solid fa-minus"></i> N/A
                </span>
                <?php else: ?>
                <span class="badge-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                <?php endif; ?>
                <!-- AI verdict -->
                <?php if ($ai_raw): ?>
                  <?php if ($ai_v===true): ?>
                  <span class="ai-verdict ai-true"><i class="fa-solid fa-circle-check"></i> Structure OK (<?= $ai_conf ?>%)</span>
                  <?php elseif ($ai_v===false): ?>
                  <span class="ai-verdict ai-false"><i class="fa-solid fa-circle-xmark"></i> Suspicious (<?= $ai_conf ?>%)</span>
                  <?php else: ?>
                  <span class="ai-verdict ai-uncertain"><i class="fa-solid fa-circle-question"></i> Uncertain (<?= $ai_conf ?>%)</span>
                  <?php endif; ?>
                <?php else: ?>
                <span class="ai-verdict ai-none"><i class="fa-solid fa-robot"></i> No AI result</span>
                <?php endif; ?>
                <!-- Re-Redact button -->
                <?php if ($doc['document_type']==='BirthCertificate' && $rs!=='done'): ?>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="action"   value="reredact"/>
                  <input type="hidden" name="doc_id"   value="<?= $doc['id'] ?>"/>
                  <input type="hidden" name="_q"       value="<?= htmlspecialchars($search) ?>"/>
                  <input type="hidden" name="_status"  value="<?= htmlspecialchars($filter_status) ?>"/>
                  <input type="hidden" name="_course"  value="<?= htmlspecialchars($filter_course) ?>"/>
                  <button type="submit" class="btn-primary" style="padding:5px 12px;font-size:.75rem;">
                    <i class="fa-solid fa-rotate-right"></i> Re-Redact
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </div>

            <!-- Doc panel body -->
            <div class="doc-panel-body">
              <!-- AI details -->
              <?php if ($ai_raw): ?>
              <?php if (!empty($ai_raw['notes'])): ?>
              <div style="background:#f8fafc;border-left:3px solid #2563eb;border-radius:0 6px 6px 0;
                          padding:9px 13px;font-size:.8rem;color:#334155;margin-bottom:10px;line-height:1.55;">
                <strong><i class="fa-solid fa-robot" style="color:#2563eb;"></i> AI Notes:</strong>
                <?= htmlspecialchars($ai_raw['notes']) ?>
              </div>
              <?php endif; ?>
              <?php if (!empty($ai_raw['red_flags'])): ?>
              <div style="margin-bottom:10px;">
                <?php foreach ($ai_raw['red_flags'] as $flag): ?>
                <div style="background:#fff1f2;color:#dc2626;border-radius:6px;padding:5px 10px;
                            font-size:.75rem;margin-bottom:4px;display:flex;align-items:center;gap:6px;">
                  <i class="fa-solid fa-flag"></i> <?= htmlspecialchars($flag) ?>
                </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <!-- Blur / Alignment / Legitimacy pills -->
              <?php
              $blur=$ai_raw['image_blur']??null; $align_ok=$ai_raw['alignment_ok']??null; $legit=$ai_raw['is_legitimate']??null;
              if ($blur!==null||$align_ok!==null||$legit!==null): ?>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
                <?php if ($blur!==null):[$bc,$bl]=match($blur){'sharp'=>['#16a34a','Sharp'],'slightly_blurred'=>['#d97706','Slightly Blurred'],'too_blurred'=>['#dc2626','Too Blurred'],default=>['#6b7280','Unknown']}; ?>
                <span style="background:<?= $bc ?>18;color:<?= $bc ?>;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;"><?= $bl ?></span>
                <?php endif; if ($align_ok!==null):[$ac,$al]=$align_ok?['#16a34a','Aligned']:['#d97706','Misaligned']; ?>
                <span style="background:<?= $ac ?>18;color:<?= $ac ?>;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;"><?= $al ?></span>
                <?php endif; if ($legit!==null):[$lc,$ll]=match(true){$legit===true=>['#16a34a','Legitimate'],$legit===false=>['#dc2626','Not Legitimate'],default=>['#d97706','Uncertain']}; ?>
                <span style="background:<?= $lc ?>18;color:<?= $lc ?>;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;"><?= $ll ?></span>
                <?php endif; ?>
              </div>
              <?php endif; ?>
              <?php endif; // end if ai_raw ?>

              <!-- Side-by-side image preview -->
              <div class="preview-grid">
                <div class="preview-pane">
                  <div class="preview-pane-header" style="background:#fee2e2;color:#dc2626;">
                    <i class="fa-solid fa-eye-slash"></i> Original (PII visible — admin only)
                  </div>
                  <a href="<?= $orig_url ?>" target="_blank">
                    <img src="<?= $orig_url ?>" alt="Original" loading="lazy"
                         onerror="this.closest('.preview-pane').innerHTML='<div style=\'padding:20px;text-align:center;color:#aaa;font-size:.8rem;\'>Preview unavailable</div>'"/>
                  </a>
                </div>
                <div class="preview-pane">
                  <div class="preview-pane-header" style="background:#dcfce7;color:#16a34a;">
                    <i class="fa-solid fa-shield-halved"></i> Redacted (AI sees this)
                  </div>
                  <?php if ($red_url): ?>
                  <a href="<?= $red_url ?>" target="_blank">
                    <img src="<?= $red_url ?>" alt="Redacted" loading="lazy"
                         onerror="this.closest('.preview-pane').innerHTML='<div style=\'padding:20px;text-align:center;color:#aaa;font-size:.8rem;\'>Redacted preview unavailable</div>'"/>
                  </a>
                  <div style="padding:6px 12px;font-size:.7rem;color:#16a34a;background:#f0fdf4;">
                    <i class="fa-solid fa-robot"></i> AI analyzed this copy
                    <?php if ($doc['ai_inspected_at']): ?>— <?= date('M d, Y g:i A', strtotime($doc['ai_inspected_at'])) ?><?php endif; ?>
                  </div>
                  <?php elseif ($rs==='skipped'): ?>
                  <div style="padding:28px;text-align:center;color:#6b7280;font-size:.82rem;background:#f8fafc;">
                    <i class="fa-solid fa-minus" style="font-size:1.3rem;display:block;margin-bottom:8px;"></i>
                    Redaction only applies to<br><strong>PSA Birth Certificates</strong>.
                  </div>
                  <?php else: ?>
                  <div style="padding:28px;text-align:center;color:#aaa;font-size:.82rem;background:#f8fafc;">
                    <?php if ($rs==='failed'): ?>
                    <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;font-size:1.3rem;display:block;margin-bottom:8px;"></i>
                    Redaction failed — click Re-Redact above.
                    <?php else: ?>
                    <i class="fa-solid fa-clock" style="font-size:1.3rem;display:block;margin-bottom:8px;color:#f59e0b;"></i>
                    Not yet redacted.
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                </div>
              </div><!-- end preview-grid -->
            </div><!-- end doc-panel-body -->
          </div><!-- end doc-panel -->
          <?php endforeach; endif; ?>
        </div><!-- end student-card-body -->
      </div><!-- end student-card -->
      <?php endforeach; ?>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <div class="crud-pagination">
        <?php
        $qs = http_build_query(['q'=>$search,'status'=>$filter_status,'course'=>$filter_course]);
        if ($page > 1) echo "<a href='?$qs&page=".($page-1)."' class='pg-btn pg-label'>&laquo;</a>";
        for ($p=max(1,$page-2); $p<=min($total_pages,$page+2); $p++)
            echo "<a href='?$qs&page=$p' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
        if ($page < $total_pages) echo "<a href='?$qs&page=".($page+1)."' class='pg-btn pg-label'>&raquo;</a>";
        ?>
      </div>
      <?php endif; ?>

    </div><!-- end padding wrapper -->
  </div><!-- end content -->
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
function toggleStudent(pid) {
    var body = document.getElementById('scb-' + pid);
    var chev = document.getElementById('chev-' + pid);
    if (!body) return;
    var open = body.classList.toggle('open');
    if (chev) chev.style.transform = open ? 'rotate(180deg)' : '';
}
// Auto-open if only one student
(function(){
    var cards = document.querySelectorAll('.student-card');
    if (cards.length === 1) {
        var pid = cards[0].id.replace('sc-','');
        toggleStudent(pid);
    }
}());
</script>
</body>
</html>
<?php $conn->close(); ?>
