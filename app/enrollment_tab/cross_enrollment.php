<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

$enrollments = [];
$res = $conn->query(
    "SELECT e.*, p.first_name, p.last_name FROM enrollments e
     JOIN pre_registrations p ON e.pre_reg_id = p.id
     ORDER BY e.enrolled_at DESC"
);
if ($res) while ($r = $res->fetch_assoc()) $enrollments[] = $r;
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
      <h2 class="page-title"><i class="fa-solid fa-arrow-right-arrow-left"></i> Cross Enrollment Checker</h2>
    </div>

    <div class="form-card">
      <h3>About Cross Enrollment</h3>
      <p style="font-size:0.82rem; color:#666; line-height:1.6;">
        Cross-enrolled students are those coming from other institutions taking specific subjects.
        Mark a student as cross-enrolled and specify their home school below.
      </p>
    </div>

    <div class="crud-card">
      <div class="crud-header"><h3>Enrollment Records</h3></div>
      <table class="crud-table">
        <thead><tr><th>ID Number</th><th>Name</th><th>Course</th><th>Cross Enrolled</th><th>From School</th><th>Action</th></tr></thead>
        <tbody>
          <?php if ($enrollments): foreach ($enrollments as $e): ?>
          <tr>
            <td><strong style="color:#2563eb;font-size:0.78rem;"><?= htmlspecialchars($e['id_number']) ?></strong></td>
            <td><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></td>
            <td style="font-size:0.75rem;"><?= htmlspecialchars($e['course']) ?></td>
            <td><?= $e['is_cross'] ? '<span class="badge-active">Yes</span>' : '<span class="badge-inactive">No</span>' ?></td>
            <td style="font-size:0.75rem;color:#666;"><?= $e['cross_from'] ? htmlspecialchars($e['cross_from']) : '—' ?></td>
            <td>
              <?php if (!$e['is_cross']): ?>
              <button class="btn-add btn-mark-cross" data-id="<?= $e['id'] ?>" style="padding:6px 12px;font-size:0.78rem;">
                <i class="fa-solid fa-plus"></i> Mark Cross
              </button>
              <?php else: ?>
              <span style="font-size:0.75rem;color:#aaa;">Already marked</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="6" style="text-align:center;padding:24px;color:#aaa;">No enrollment records.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- Cross enroll modal -->
<div class="modal-overlay" id="crossModal">
  <div class="modal modal-sm">
    <div class="modal-header"><span>Mark as Cross Enrolled</span><button class="modal-close" data-close="crossModal">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="crossEnrId"/>
      <div class="form-field full" style="margin-top:8px;">
        <label>Home School / Institution</label>
        <input type="text" id="crossFrom" placeholder="e.g. University of Santo Tomas"/>
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
document.querySelectorAll('.btn-mark-cross').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('crossEnrId').value = btn.dataset.id;
        document.getElementById('crossModal').classList.add('active');
    });
});
document.getElementById('btnConfirmCross').addEventListener('click', async () => {
    const fd = new FormData();
    fd.set('action',        'cross_enroll');
    fd.set('enrollment_id', document.getElementById('crossEnrId').value);
    fd.set('cross_from',    document.getElementById('crossFrom').value);
    const data = await fetch(API, {method:'POST', body:fd}).then(r=>r.json());
    if (data.success) location.reload();
    else showAlertModal(data.message, 'error');
});
</script>
</body></html>
<?php $conn->close(); ?>
