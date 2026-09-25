<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// Ensure grade_confirmed column exists (idempotent)
@$conn->query("ALTER TABLE enrollments ADD COLUMN IF NOT EXISTS grade_confirmed TINYINT(1) NOT NULL DEFAULT 0");

// ── Step 3 of pipeline: Grade Level Assignment ────────────────
// Gate:  Student must have an enrollment row (ID generated = step 3 done)
//        and grade_confirmed must still be 0 (not yet confirmed).
// After: Admin confirms/adjusts year level → grade_confirmed becomes 1
//        → student becomes visible in Cross Enrollment (step 5)
//        and Section Assignment (step 6).

$pending = [];   // grade_confirmed = 0
$done    = [];   // grade_confirmed = 1

$res = $conn->query(
    "SELECT e.*, p.first_name, p.last_name, p.ref_number,
            p.applicant_type, p.transfer_year_level
     FROM enrollments e
     JOIN pre_registrations p ON e.pre_reg_id = p.id
     ORDER BY e.grade_confirmed ASC, p.last_name ASC"
);
if ($res) while ($r = $res->fetch_assoc()) {
    if ($r['grade_confirmed']) $done[]    = $r;
    else                       $pending[] = $r;
}

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

    <!-- Pending confirmation -->
    <div class="crud-card">
      <div class="crud-header">
        <h3>
          Awaiting Confirmation
          <span style="font-size:.75rem;font-weight:400;color:#888;margin-left:6px;">
            <?= count($pending) ?> student<?= count($pending) !== 1 ? 's' : '' ?>
          </span>
        </h3>
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
    </div>

    <!-- Already confirmed -->
    <?php if ($done): ?>
    <div class="crud-card">
      <div class="crud-header">
        <h3>
          Confirmed
          <span style="font-size:.75rem;font-weight:400;color:#888;margin-left:6px;">
            <?= count($done) ?> student<?= count($done) !== 1 ? 's' : '' ?>
          </span>
        </h3>
      </div>
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
