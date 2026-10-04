<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if (!is_admin_or_staff()) { header('Location: ../auth/signin.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

$filter  = $_GET['status'] ?? 'Pending';
$allowed = ['Pending','Approved','Rejected','Enrolled'];
$filter  = in_array($filter, $allowed) ? $filter : 'Pending';

$search      = trim($_GET['q']       ?? '');
$filter_type = trim($_GET['type']    ?? '');   // applicant_type filter
$filter_course = trim($_GET['course'] ?? '');

$rows_per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$where_parts = ["p.status = '" . $conn->real_escape_string($filter) . "'"];
if ($search !== '') {
    $esc = $conn->real_escape_string($search);
    $where_parts[] = "(p.first_name LIKE '%$esc%' OR p.last_name LIKE '%$esc%'
                      OR p.ref_number LIKE '%$esc%' OR p.email LIKE '%$esc%')";
}
if ($filter_type) {
    $esc = $conn->real_escape_string($filter_type);
    $where_parts[] = "p.applicant_type = '$esc'";
}
if ($filter_course) {
    $esc = $conn->real_escape_string($filter_course);
    $where_parts[] = "p.course LIKE '%$esc%'";
}
$where_sql = 'WHERE ' . implode(' AND ', $where_parts);

$count_res = $conn->query(
    "SELECT COUNT(DISTINCT p.id) c FROM pre_registrations p $where_sql"
);
$total_rows  = $count_res ? (int)$count_res->fetch_assoc()['c'] : 0;
$total_pages = max(1, (int)ceil($total_rows / $rows_per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $rows_per_page;

$apps = [];
$res = $conn->query(
    "SELECT p.*,
            COUNT(d.id) AS doc_count,
            -- Approved count (any submission approved = doc is done)
            SUM(CASE WHEN d.document_type='BirthCertificate' AND d.status='Approved' THEN 1 ELSE 0 END) AS bc_approved,
            SUM(CASE WHEN d.document_type='ReportCard'       AND d.status='Approved' THEN 1 ELSE 0 END) AS rc_approved,
            SUM(CASE WHEN d.document_type='GoodMoral'        AND d.status='Approved' THEN 1 ELSE 0 END) AS gm_approved,
            -- Total submissions per type (attempts)
            SUM(CASE WHEN d.document_type='BirthCertificate' THEN 1 ELSE 0 END) AS bc_count,
            SUM(CASE WHEN d.document_type='ReportCard'       THEN 1 ELSE 0 END) AS rc_count,
            SUM(CASE WHEN d.document_type='GoodMoral'        THEN 1 ELSE 0 END) AS gm_count,
            -- Rejected count (latest submission rejected, none approved)
            SUM(CASE WHEN d.document_type='BirthCertificate' AND d.status='Rejected' THEN 1 ELSE 0 END) AS bc_rejected,
            SUM(CASE WHEN d.document_type='ReportCard'       AND d.status='Rejected' THEN 1 ELSE 0 END) AS rc_rejected,
            SUM(CASE WHEN d.document_type='GoodMoral'        AND d.status='Rejected' THEN 1 ELSE 0 END) AS gm_rejected
     FROM pre_registrations p
     LEFT JOIN enrollment_documents d ON d.pre_reg_id = p.id
     $where_sql
     GROUP BY p.id ORDER BY p.submitted_at ASC
     LIMIT $rows_per_page OFFSET $offset"
);
if ($res) while ($r = $res->fetch_assoc()) $apps[] = $r;
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Enrollment Validation - BCP</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php $APP_ROOT = '../'; $ACTIVE_NAV = 'enrollment'; require_once __DIR__ . '/../admin_dashboard/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap">
        <input type="text" placeholder="Search..."/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <a href="../admin_dashboard/account.php" class="avatar"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-clipboard-check"></i> Enrollment Validation</h2>
    </div>

    <div style="padding:0 24px;margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;">
      <?php foreach (['Pending','Approved','Rejected','Enrolled'] as $s): ?>
      <a href="?status=<?= $s ?>&q=<?= urlencode($search) ?>&type=<?= urlencode($filter_type) ?>&course=<?= urlencode($filter_course) ?>" class="pg-btn<?= $filter===$s?' active':'' ?>"><?= $s ?></a>
      <?php endforeach; ?>
    </div>

    <!-- Search + filters -->
    <form method="GET" style="padding:0 24px;margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>"/>
      <div class="search-wrap" style="flex:1;min-width:200px;max-width:320px;">
        <input type="text" name="q" placeholder="Search name, ref no., email…" value="<?= htmlspecialchars($search) ?>"/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <select name="type" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;">
        <option value="">All Types</option>
        <?php foreach (['Freshman','Senior High'] as $t): ?>
        <option value="<?= $t ?>" <?= $filter_type===$t?'selected':'' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
      <select name="course" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;max-width:180px;">
        <option value="">All Courses</option>
        <?php foreach (['Information Technology','Computer Engineering','Library Information Science',
                         'Psychology','Elementary Education','Technology and Livelihood Education',
                         'Secondary Education','Physical Education','Criminology',
                         'Accounting Information System','Entrepreneurship',
                         'Marketing Management','Human Resource Management','Financial Management',
                         'Office Administration','Tourism Management','Hospitality Management'] as $c): ?>
        <option value="<?= $c ?>" <?= str_contains($filter_course,$c)?'selected':'' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="card-btn" style="padding:7px 16px;"><i class="fa-solid fa-filter"></i> Filter</button>
      <?php if ($search||$filter_type||$filter_course): ?>
      <a href="?status=<?= $filter ?>" class="card-btn" style="background:#f3f4f6;color:#555;">
        <i class="fa-solid fa-xmark"></i> Clear
      </a>
      <?php endif; ?>
    </form>

    <div class="crud-card">
      <div class="crud-header">
        <h3><?= htmlspecialchars($filter) ?> Applications (<?= $total_rows ?>)</h3>
      </div>
      <table class="crud-table">
        <thead>
          <tr><th>Name</th><th>Course</th><th>Year</th><th>Type</th><th style="min-width:170px;">Documents</th><th>Submitted</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if ($apps): foreach ($apps as $app):
            $atype   = $app['applicant_type'] ?? null;
            $xfer_yr = $app['transfer_year_level'] ?? null;
            $type_colors = [
                'Freshman'    => ['#dcfce7','#16a34a'],
                'Senior High' => ['#eff6ff','#2563eb'],
            ];
            [$tbg,$tclr] = $type_colors[$atype] ?? ['#f3f4f6','#6b7280'];
          ?>
          <tr>
            <td><?= htmlspecialchars($app['first_name'].' '.$app['last_name']) ?></td>
            <td style="font-size:.75rem;"><?= htmlspecialchars($app['course']) ?></td>
            <td><?= htmlspecialchars($xfer_yr ?: $app['year_level']) ?></td>
            <td>
              <?php if ($atype): ?>
              <span style="background:<?= $tbg ?>;color:<?= $tclr ?>;border-radius:20px;
                           padding:2px 8px;font-size:.7rem;font-weight:700;">
                <?= htmlspecialchars($atype) ?>
              </span>
              <?php else: ?>
              <span style="color:#aaa;font-size:.75rem;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php
              $docs_info = [
                  ['BC', 'Birth Cert',  (int)$app['bc_count'], (int)$app['bc_approved'], (int)$app['bc_rejected']],
                  ['RC', 'Report Card', (int)$app['rc_count'], (int)$app['rc_approved'], (int)$app['rc_rejected']],
                  ['GM', 'Good Moral',  (int)$app['gm_count'], (int)$app['gm_approved'], (int)$app['gm_rejected']],
              ];
              $all_approved = $app['bc_approved'] && $app['rc_approved'] && $app['gm_approved'];
              ?>
              <div style="display:flex;flex-direction:column;gap:4px;">
                <?php foreach ($docs_info as [$code, $label, $count, $approved, $rejected]):
                  if ($approved) {
                      $icon='fa-circle-check'; $clr='#16a34a'; $lbl='Approved';
                  } elseif ($count === 0) {
                      $icon='fa-circle-xmark'; $clr='#d1d5db'; $lbl='Not uploaded';
                  } elseif ($rejected === $count) {
                      $icon='fa-circle-xmark'; $clr='#dc2626'; $lbl='Rejected';
                  } else {
                      $icon='fa-clock'; $clr='#d97706'; $lbl='Pending';
                  }
                ?>
                <div style="display:flex;align-items:center;gap:5px;font-size:.72rem;white-space:nowrap;">
                  <i class="fa-solid <?= $icon ?>" style="color:<?= $clr ?>;font-size:.7rem;flex-shrink:0;"></i>
                  <span style="color:#555;font-weight:600;min-width:68px;"><?= $label ?></span>
                  <span style="color:<?= $clr ?>;font-weight:700;"><?= $lbl ?></span>
                  <?php if ($count > 0): ?>
                  <span style="color:#aaa;font-size:.65rem;">(<?= $count ?>×)</span>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if ($all_approved): ?>
                <div style="margin-top:3px;">
                  <span style="background:#dcfce7;color:#16a34a;padding:1px 7px;border-radius:20px;font-size:.68rem;font-weight:700;">
                    ✓ All Approved
                  </span>
                </div>
                <?php elseif ($app['doc_count'] == 0): ?>
                <div style="margin-top:3px;">
                  <span style="background:#fee2e2;color:#dc2626;padding:1px 7px;border-radius:20px;font-size:.68rem;font-weight:700;">
                    No Documents
                  </span>
                </div>
                <?php endif; ?>
              </div>
            </td>
            <td style="font-size:.75rem;color:#888;"><?= date('M d, Y', strtotime($app['submitted_at'])) ?></td>
            <td class="actions-cell">
              <?php if ($filter==='Pending'): ?>
              <button class="btn-icon val-approve-btn" title="Approve" data-id="<?= (int)$app['id'] ?>">
                <i class="fa-solid fa-circle-check" style="color:#22c55e;font-size:1rem;pointer-events:none;"></i>
              </button>
              <button class="btn-icon val-reject-btn" title="Reject" data-id="<?= (int)$app['id'] ?>">
                <i class="fa-solid fa-circle-xmark" style="color:#ef4444;font-size:1rem;pointer-events:none;"></i>
              </button>
              <?php else: ?>
              <span style="font-size:.75rem;color:#aaa;">-</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="6" style="text-align:center;padding:24px;color:#aaa;">No <?= strtolower(htmlspecialchars($filter)) ?> applications.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <div class="crud-pagination">
        <?php
        $qs = http_build_query(['status'=>$filter,'q'=>$search,'type'=>$filter_type,'course'=>$filter_course]);
        if ($page > 1) echo "<a href='?$qs&page=".($page-1)."#' class='pg-btn pg-label'>&laquo; Prev</a>";
        for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++) {
            echo "<a href='?$qs&page=$p#' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
        }
        if ($page < $total_pages) echo "<a href='?$qs&page=".($page+1)."#' class='pg-btn pg-label'>Next &raquo;</a>";
        ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- VALIDATE MODAL -->
<div class="modal-overlay" id="validateModal">
  <div class="modal">
    <div class="modal-header">
      <span id="validateTitle">Validate Application</span>
      <button class="modal-close" data-close="validateModal">&times;</button>
    </div>
    <div class="modal-body" id="validateModalBody">
      <input type="hidden" id="valPreRegId"/>
      <input type="hidden" id="valStatus"/>
      <div style="margin-top:4px;">
        <label style="display:block;font-size:.78rem;font-weight:600;color:#444;margin-bottom:6px;">Remarks (optional)</label>
        <input type="text" id="valRemarks" placeholder="Add a note..."
               style="width:100%;height:40px;border:1.5px solid #d0d7e2;border-radius:8px;
                      padding:0 12px;font-size:.85rem;outline:none;font-family:inherit;box-sizing:border-box;"/>
      </div>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="validateModal">Cancel</button>
      <button class="btn-modal-confirm" id="btnConfirmValidate">Confirm</button>
    </div>
  </div>
</div>

<!-- LOGIN LINK MODAL -->
<div class="modal-overlay" id="loginLinkModal">
  <div class="modal">
    <div class="modal-header">
      <span><i class="fa-solid fa-circle-check" style="color:#86efac;margin-right:6px;"></i>Application Approved</span>
      <button class="modal-close" data-close="loginLinkModal">&times;</button>
    </div>
    <div class="modal-body">
      <!-- Email delivery status banner — filled by JS -->
      <div id="emailStatusBanner" style="margin-bottom:14px;border-radius:8px;padding:10px 14px;font-size:.82rem;display:none;"></div>

      <p style="font-size:.85rem;color:#555;margin-bottom:14px;">
        Share this one-time login link with the student. It expires in <strong>72 hours</strong> and can only be used once.
      </p>
      <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 16px;margin-bottom:14px;">
        <div style="font-size:.7rem;color:#aaa;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px;">Student Login Link</div>
        <a id="loginLinkUrl" href="#" target="_blank"
           style="font-size:.78rem;color:#2563eb;word-break:break-all;text-decoration:underline;"></a>
      </div>
      <div style="margin-bottom:12px;">
        <div style="font-size:.7rem;color:#aaa;margin-bottom:4px;">Username</div>
        <code id="loginLinkUsername" style="font-size:.88rem;font-weight:700;color:#1a1a2e;background:#f3f4f6;padding:4px 10px;border-radius:4px;"></code>
      </div>
      <p style="font-size:.72rem;color:#f59e0b;">
        <i class="fa-solid fa-triangle-exclamation"></i> This link can only be used once.
      </p>
    </div>
    <div class="modal-footer modal-footer-split">
      <button id="btnCopyLink" class="btn-modal-submit">
        <i class="fa-solid fa-copy"></i> Copy Link
      </button>
      <button class="btn-modal-cancel" data-close="loginLinkModal">Close</button>
    </div>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script src="../js/validation.js?v=<?= filemtime(__DIR__.'/../js/validation.js') ?>"></script>
</body>
</html>
