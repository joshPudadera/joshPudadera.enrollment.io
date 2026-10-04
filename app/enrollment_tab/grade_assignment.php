<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if (!is_admin_or_staff()) { header('Location: ../auth/signin.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// Ensure grade_confirmed column exists (idempotent)
@$conn->query("ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS grade_confirmed TINYINT(1) NOT NULL DEFAULT 0");

// Ensure pre_registrations has applicant_type / transfer_year_level columns (idempotent)
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS applicant_type ENUM('Freshman','Senior High','Octoberian','Transferee') DEFAULT NULL");
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS transfer_year_level VARCHAR(50) DEFAULT NULL");

// ── Step 3 of pipeline: Grade Level Assignment ────────────────
// Gate:  Student must have an enrollment row (ID generated = step 3 done)
//        and grade_confirmed must still be 0 (not yet confirmed).
// After: Admin confirms/adjusts year level → grade_confirmed becomes 1
//        → student becomes visible in Cross Enrollment (step 5)
//        and Section Assignment (step 6).

$pending = [];   // grade_confirmed = 0
$done    = [];   // grade_confirmed = 1

$search = trim($_GET['q'] ?? '');
$filter_course = trim($_GET['course'] ?? '');
$filter_yr = trim($_GET['year'] ?? '');
$rows_per_page = 10;
$page_p = max(1, (int)($_GET['page_p'] ?? 1));
$page_d = max(1, (int)($_GET['page_d'] ?? 1));

$base_where = "1=1";
if ($search) {
    $esc = $conn->real_escape_string($search);
    $base_where .= " AND (p.first_name LIKE '%$esc%' OR p.last_name LIKE '%$esc%' OR e.id_number LIKE '%$esc%')";
}
if ($filter_course) {
    $esc = $conn->real_escape_string($filter_course);
    $base_where .= " AND e.course LIKE '%$esc%'";
}
if ($filter_yr) {
    $esc = $conn->real_escape_string($filter_yr);
    $base_where .= " AND e.year_level = '$esc'";
}

$pending_count = (int)$conn->query("SELECT COUNT(*) c FROM enrollments e JOIN pre_registrations p ON e.pre_reg_id=p.id WHERE e.grade_confirmed=0 AND $base_where")->fetch_assoc()['c'];
$done_count    = (int)$conn->query("SELECT COUNT(*) c FROM enrollments e JOIN pre_registrations p ON e.pre_reg_id=p.id WHERE e.grade_confirmed=1 AND $base_where")->fetch_assoc()['c'];
$pending_pages = max(1, (int)ceil($pending_count / $rows_per_page));
$done_pages    = max(1, (int)ceil($done_count    / $rows_per_page));
$page_p = min($page_p, $pending_pages);
$page_d = min($page_d, $done_pages);

$res = $conn->query(
    "SELECT e.*, p.first_name, p.last_name, p.ref_number,
            p.applicant_type, p.transfer_year_level
     FROM enrollments e
     JOIN pre_registrations p ON e.pre_reg_id = p.id
     WHERE e.grade_confirmed=0 AND $base_where
     ORDER BY p.last_name ASC
     LIMIT $rows_per_page OFFSET " . (($page_p-1)*$rows_per_page)
);
if ($res) while ($r = $res->fetch_assoc()) $pending[] = $r;

$res2 = $conn->query(
    "SELECT e.*, p.first_name, p.last_name, p.ref_number
     FROM enrollments e
     JOIN pre_registrations p ON e.pre_reg_id = p.id
     WHERE e.grade_confirmed=1 AND $base_where
     ORDER BY p.last_name ASC
     LIMIT $rows_per_page OFFSET " . (($page_d-1)*$rows_per_page)
);
if ($res2) while ($r = $res2->fetch_assoc()) $done[] = $r;

$year_levels = ['1st Year','2nd Year','3rd Year','4th Year'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Grade Level Assignment – BCP</title>
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
      <div class="search-wrap"><input type="text" placeholder="Search..."/><i class="fa-solid fa-magnifying-glass"></i></div>
      <a href="../admin_dashboard/account.php" class="avatar"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-layer-group"></i> Grade Level Assignment</h2>
    </div>

    <!-- Pipeline step indicator -->
    <div style="margin:0 24px 18px;background:#eff6ff;border:1.5px solid #bfdbfe;
                border-radius:10px;padding:12px 16px;font-size:.82rem;color:#1e40af;">
      <i class="fa-solid fa-circle-info"></i>
      <strong>Step 4 of 6</strong> — Confirm or adjust each student's year level, then click
      <strong>Confirm</strong>. Students only move to Cross Enrollment and Section Assignment
      after this step is complete.
    </div>

    <!-- Search/filter bar -->
    <form method="GET" style="margin:0 24px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <div class="search-wrap" style="flex:1;min-width:200px;max-width:300px;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or ID number…"/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <select name="course" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;">
        <option value="">All Courses</option>
        <?php foreach(['Information Technology','Computer Engineering','Library Information Science',
                        'Psychology','Elementary Education','Technology and Livelihood Education',
                        'Secondary Education','Physical Education','Criminology',
                        'Accounting Information System','Entrepreneurship',
                        'Marketing Management','Human Resource Management','Financial Management',
                        'Office Administration','Tourism Management','Hospitality Management'] as $c): ?>
        <option value="<?= $c ?>" <?= str_contains($filter_course,$c)?'selected':'' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
      <select name="year" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;">
        <option value="">All Years</option>
        <?php foreach(['1st Year','2nd Year','3rd Year','4th Year'] as $y): ?>
        <option value="<?= $y ?>" <?= $filter_yr===$y?'selected':'' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-add" style="padding:7px 14px;font-size:.8rem;"><i class="fa-solid fa-filter"></i> Filter</button>
      <?php if($search||$filter_course||$filter_yr): ?><a href="grade_assignment.php" class="btn-secondary" style="padding:7px 12px;font-size:.8rem;text-decoration:none;">Clear</a><?php endif; ?>
    </form>

    <!-- Pending confirmation -->
    <div class="crud-card">
      <div class="crud-header">
        <h3>Awaiting Confirmation <span style="font-size:.75rem;font-weight:400;color:#888;margin-left:6px;"><?= $pending_count ?> student<?= $pending_count!==1?'s':'' ?></span></h3>
      </div>
      <?php if ($pending): ?>
      <table class="crud-table">
        <thead><tr>
          <th>ID Number</th><th>Name</th><th>Course</th><th>Ref No.</th>
          <th>Applicant Type</th>
          <th>Current Year Level</th><th style="min-width:220px;">Confirm / Change</th>
        </tr></thead>
        <tbody>
          <?php foreach ($pending as $e):
            $atype    = $e['applicant_type']      ?? null;
            $xfer_yr  = $e['transfer_year_level'] ?? null;

            // Suggest a year level based on applicant type
            $suggested_yr = $e['year_level'];
            if ($atype === 'Transferee' && $xfer_yr) $suggested_yr = $xfer_yr;

            $type_color = match($atype) {
                'Freshman'    => ['#dcfce7','#16a34a'],
                'Senior High' => ['#eff6ff','#2563eb'],
                'Octoberian'  => ['#faf5ff','#7c3aed'],
                'Transferee'  => ['#fff7ed','#d97706'],
                default       => ['#f3f4f6','#6b7280'],
            };
          ?>
          <tr id="row-<?= $e['id'] ?>">
            <td><strong style="color:#2563eb;font-size:.78rem;"><?= htmlspecialchars($e['id_number']) ?></strong></td>
            <td><?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name']) ?></td>
            <td style="font-size:.78rem;"><?= htmlspecialchars($e['course']) ?></td>
            <td style="font-size:.72rem;color:#2563eb;"><?= htmlspecialchars($e['ref_number'] ?? '—') ?></td>
            <td>
              <?php if ($atype): ?>
              <span style="display:inline-flex;align-items:center;gap:4px;
                           background:<?= $type_color[0] ?>;color:<?= $type_color[1] ?>;
                           border-radius:20px;padding:3px 10px;font-size:.72rem;font-weight:700;">
                <?= htmlspecialchars($atype) ?>
              </span>
              <?php if ($atype === 'Transferee' && $xfer_yr): ?>
              <div style="font-size:.68rem;color:#d97706;margin-top:3px;">
                Transferring into: <strong><?= htmlspecialchars($xfer_yr) ?></strong>
              </div>
              <?php endif; ?>
              <?php else: ?>
              <span style="color:#aaa;font-size:.75rem;">—</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="cur-yr-lv" id="cur-<?= $e['id'] ?>"
                    style="font-size:.82rem;font-weight:600;color:#1a1a2e;">
                <?= htmlspecialchars($suggested_yr) ?>
              </span>
            </td>
            <td>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <select class="grade-select" data-id="<?= $e['id'] ?>"
                        style="height:34px;border:1.5px solid #d0d7e2;border-radius:6px;
                               padding:0 10px;font-size:.8rem;min-width:110px;">
                  <?php
                  // Include SHS grades in the dropdown
                  $all_levels = ['Grade 11','Grade 12','1st Year','2nd Year','3rd Year','4th Year'];
                  foreach ($all_levels as $y): ?>
                  <option <?= $suggested_yr === $y ? 'selected' : '' ?>><?= $y ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn-assign-grade"
                        data-id="<?= $e['id'] ?>"
                        style="background:#16a34a;color:#fff;border:none;border-radius:7px;
                               padding:7px 14px;font-size:.78rem;font-weight:600;cursor:pointer;
                               display:inline-flex;align-items:center;gap:5px;">
                  <i class="fa-solid fa-circle-check"></i> Confirm
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div style="padding:28px;text-align:center;color:#16a34a;font-size:.88rem;">
        <i class="fa-solid fa-circle-check" style="font-size:1.4rem;display:block;margin-bottom:8px;"></i>
        All enrolled students have been confirmed.
      </div>
      <?php endif; ?>
      <?php if ($pending_pages > 1): ?>
      <div class="crud-pagination">
        <?php $qs=http_build_query(['q'=>$search,'course'=>$filter_course,'year'=>$filter_yr]);
        if($page_p>1) echo "<a href='?$qs&page_p=".($page_p-1)."' class='pg-btn pg-label'>&laquo;</a>";
        for($p=max(1,$page_p-2);$p<=min($pending_pages,$page_p+2);$p++) echo "<a href='?$qs&page_p=$p' class='pg-btn".($p===$page_p?' active':'')."'>$p</a>";
        if($page_p<$pending_pages) echo "<a href='?$qs&page_p=".($page_p+1)."' class='pg-btn pg-label'>&raquo;</a>"; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Already confirmed -->
    <?php if ($done || $done_count > 0): ?>
    <div class="crud-card">
      <div class="crud-header">
        <h3>Confirmed <span style="font-size:.75rem;font-weight:400;color:#888;margin-left:6px;"><?= $done_count ?> student<?= $done_count!==1?'s':'' ?></span></h3>
      </div>
      <?php if ($done): ?>
      <table class="crud-table">
        <thead><tr>
          <th>ID Number</th><th>Name</th><th>Course</th><th>Year Level</th><th>Section</th>
        </tr></thead>
        <tbody>
          <?php foreach ($done as $e): ?>
          <tr>
            <td><strong style="color:#2563eb;font-size:.78rem;"><?= htmlspecialchars($e['id_number']) ?></strong></td>
            <td><?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name']) ?></td>
            <td style="font-size:.78rem;"><?= htmlspecialchars($e['course']) ?></td>
            <td><span class="badge-active"><?= htmlspecialchars($e['year_level']) ?></span></td>
            <td>
              <?php if ($e['section'] && $e['section'] !== 'TBA'): ?>
              <span class="badge-active"><?= htmlspecialchars($e['section']) ?></span>
              <?php else: ?>
              <span style="color:#f59e0b;font-size:.75rem;font-weight:600;">TBA</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($done_pages > 1): ?>
      <div class="crud-pagination">
        <?php
        $qs = http_build_query(['q'=>$search,'course'=>$filter_course,'year'=>$filter_yr,'page_p'=>$page_p]);
        if ($page_d > 1) echo "<a href='?$qs&page_d=".($page_d-1)."' class='pg-btn pg-label'>&laquo;</a>";
        for ($p = max(1,$page_d-2); $p <= min($done_pages,$page_d+2); $p++)
            echo "<a href='?$qs&page_d=$p' class='pg-btn".($p===$page_d?' active':'')."'>$p</a>";
        if ($page_d < $done_pages) echo "<a href='?$qs&page_d=".($page_d+1)."' class='pg-btn pg-label'>&raquo;</a>";
        ?>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div style="padding:24px;text-align:center;color:#aaa;font-size:.85rem;">No confirmed students on this page.</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
const API = '../shared/enrollment_actions.php';
document.querySelectorAll('.btn-assign-grade').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var row    = document.getElementById('row-' + btn.dataset.id);
        var select = row.querySelector('.grade-select');
        var fd     = new FormData();
        fd.set('action',        'assign_grade');
        fd.set('enrollment_id', btn.dataset.id);
        fd.set('year_level',    select.value);

        btn.disabled   = true;
        btn.innerHTML  = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

        fetch(API, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    // Visual feedback then remove row
                    btn.innerHTML = '<i class="fa-solid fa-check"></i> Done!';
                    btn.style.background = '#22c55e';
                    setTimeout(function() { row.remove(); }, 900);
                } else {
                    btn.disabled  = false;
                    btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Confirm';
                    showAlertModal(d.message, 'error');
                }
            })
            .catch(function() {
                btn.disabled  = false;
                btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Confirm';
                showAlertModal('Request failed. Please try again.', 'error');
            });
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
