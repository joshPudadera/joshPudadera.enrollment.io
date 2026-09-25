<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// ── Step 5 of pipeline: Cross Enrollment ─────────────────────
// Gate: grade_confirmed = 1 (grade level must be confirmed first)
// This step is optional for regular students — admin reviews each
// enrollment and marks cross-enrolled ones with their home school.
// Students who are NOT cross-enrolled are simply left as-is and
// will proceed to section assignment.

// Students pending cross-enrollment review (grade confirmed, no section yet)
$pending = [];
$res = $conn->query(
    "SELECT e.*, p.first_name, p.last_name, p.ref_number
     FROM enrollments e
     JOIN pre_registrations p ON e.pre_reg_id = p.id
     WHERE e.grade_confirmed = 1
       AND (e.section IS NULL OR e.section = '' OR e.section = 'TBA')
     ORDER BY e.is_cross DESC, p.last_name ASC"
);
if ($res) while ($r = $res->fetch_assoc()) $pending[] = $r;

// Students blocked (grade not yet confirmed)
$blocked_count = 0;
$bc = $conn->query("SELECT COUNT(*) c FROM enrollments WHERE grade_confirmed = 0");
if ($bc) $blocked_count = (int)$bc->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Cross Enrollment – BCP</title>
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
      <h2 class="page-title"><i class="fa-solid fa-arrow-right-arrow-left"></i> Cross Enrollment</h2>
    </div>

    <!-- Pipeline step indicator -->
    <div style="margin:0 24px 18px;background:#eff6ff;border:1.5px solid #bfdbfe;
                border-radius:10px;padding:12px 16px;font-size:.82rem;color:#1e40af;">
      <i class="fa-solid fa-circle-info"></i>
      <strong>Step 5 of 6</strong> — Review students coming from other institutions.
      Mark them as cross-enrolled and record their home school.
      Regular students can be left unmarked — they will proceed to section assignment automatically.
    </div>

    <?php if ($blocked_count > 0): ?>
    <div style="margin:0 24px 18px;background:#fff7ed;border:1.5px solid #fcd34d;
                border-radius:10px;padding:12px 16px;font-size:.82rem;color:#92400e;
                display:flex;align-items:center;gap:10px;">
      <i class="fa-solid fa-triangle-exclamation" style="flex-shrink:0;"></i>
      <span>
        <strong><?= $blocked_count ?> student<?= $blocked_count !== 1 ? 's' : '' ?></strong>
        still need<?= $blocked_count === 1 ? 's' : '' ?> grade level confirmation before appearing here.
        <a href="grade_assignment.php" style="color:#d97706;font-weight:700;">
          Go to Grade Assignment →
        </a>
      </span>
    </div>
    <?php endif; ?>

    <div class="crud-card">
      <div class="crud-header">
        <h3>Enrollment Records
          <span style="font-size:.75rem;font-weight:400;color:#888;margin-left:6px;">
            <?= count($pending) ?> awaiting section assignment
          </span>
        </h3>
      </div>
      <?php if ($pending): ?>
      <table class="crud-table">
        <thead><tr>
          <th>ID Number</th><th>Name</th><th>Course</th><th>Year Level</th>
          <th>Cross Enrolled</th><th>From School</th><th>Action</th>
        </tr></thead>
        <tbody>
          <?php foreach ($pending as $e): ?>
          <tr>
            <td><strong style="color:#2563eb;font-size:.78rem;"><?= htmlspecialchars($e['id_number']) ?></strong></td>
            <td><?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name']) ?></td>
            <td style="font-size:.78rem;"><?= htmlspecialchars($e['course']) ?></td>
            <td><?= htmlspecialchars($e['year_level']) ?></td>
            <td>
              <?= $e['is_cross']
                  ? '<span class="badge-active">Yes</span>'
                  : '<span style="font-size:.75rem;color:#888;">No</span>' ?>
            </td>
            <td style="font-size:.78rem;color:#555;">
              <?= $e['cross_from'] ? htmlspecialchars($e['cross_from']) : '—' ?>
            </td>
            <td>
              <?php if (!$e['is_cross']): ?>
              <button class="btn-mark-cross"
                      data-id="<?= $e['id'] ?>"
                      style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:7px;
                             padding:6px 12px;color:#2563eb;font-size:.75rem;font-weight:600;
                             cursor:pointer;display:inline-flex;align-items:center;gap:5px;">
                <i class="fa-solid fa-school"></i> Mark Cross
              </button>
              <?php else: ?>
              <span style="font-size:.75rem;color:#aaa;font-style:italic;">Marked</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div style="padding:28px;text-align:center;color:#aaa;font-size:.88rem;">
        <?php if ($blocked_count > 0): ?>
          No students ready yet — complete grade level assignment first.
        <?php else: ?>
          No students awaiting section assignment.
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- Cross enroll modal -->
<div class="modal-overlay" id="crossModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span>Mark as Cross Enrolled</span>
      <button class="modal-close" data-close="crossModal">&times;</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="crossEnrId"/>
      <div class="form-field full" style="margin-top:8px;">
        <label style="font-size:.78rem;font-weight:600;color:#444;display:block;margin-bottom:5px;">
          Home School / Institution
        </label>
        <input type="text" id="crossFrom" placeholder="e.g. University of Santo Tomas"
               style="width:100%;height:40px;border:1.5px solid #d0d7e2;border-radius:8px;
                      padding:0 12px;font-size:.88rem;outline:none;box-sizing:border-box;"/>
      </div>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="crossModal">Cancel</button>
      <button class="btn-modal-confirm" id="btnConfirmCross">Confirm</button>
    </div>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
const API = '../shared/enrollment_actions.php';

document.querySelectorAll('.btn-mark-cross').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('crossEnrId').value = btn.dataset.id;
        document.getElementById('crossFrom').value  = '';
        openModal('crossModal');
    });
});

document.getElementById('btnConfirmCross').addEventListener('click', function() {
    var from = document.getElementById('crossFrom').value.trim();
    if (!from) { showAlertModal('Please enter the home school name.', 'warning'); return; }
    var fd = new FormData();
    fd.set('action',        'cross_enroll');
    fd.set('enrollment_id', document.getElementById('crossEnrId').value);
    fd.set('cross_from',    from);
    fetch(API, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) location.reload();
            else showAlertModal(d.message, 'error');
        })
        .catch(function() { showAlertModal('Request failed.', 'error'); });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
