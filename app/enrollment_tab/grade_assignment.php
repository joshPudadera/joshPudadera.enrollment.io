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
     ORDER BY p.last_name ASC"
);
if ($res) while ($r = $res->fetch_assoc()) $enrollments[] = $r;

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

    <div class="crud-card">
      <div class="crud-header"><h3>Enrolled Students (<?= count($enrollments) ?>)</h3></div>
      <table class="crud-table">
        <thead><tr><th>ID Number</th><th>Name</th><th>Course</th><th>Current Year Level</th><th>Change To</th></tr></thead>
        <tbody>
          <?php if ($enrollments): foreach ($enrollments as $e): ?>
          <tr>
            <td><strong style="color:#2563eb;font-size:0.78rem;"><?= htmlspecialchars($e['id_number']) ?></strong></td>
            <td><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></td>
            <td style="font-size:0.75rem;"><?= htmlspecialchars($e['course']) ?></td>
            <td><?= htmlspecialchars($e['year_level']) ?></td>
            <td>
              <div style="display:flex; gap:8px; align-items:center;">
                <select class="grade-select" data-id="<?= $e['id'] ?>" style="height:34px; border:1.5px solid #d0d7e2; border-radius:6px; padding:0 10px; font-size:0.8rem;">
                  <?php foreach ($year_levels as $y): ?>
                  <option <?= $e['year_level']===$y?'selected':'' ?>><?= $y ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn-add btn-assign-grade" data-id="<?= $e['id'] ?>" style="padding:6px 12px; font-size:0.78rem;">
                  <i class="fa-solid fa-check"></i> Save
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="5" style="text-align:center;padding:24px;color:#aaa;">No enrolled students.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
const API = '../shared/enrollment_actions.php';
document.querySelectorAll('.btn-assign-grade').forEach(btn => {
    btn.addEventListener('click', async () => {
        const row    = btn.closest('tr');
        const select = row.querySelector('.grade-select');
        const fd     = new FormData();
        fd.set('action',        'assign_grade');
        fd.set('enrollment_id', btn.dataset.id);
        fd.set('year_level',    select.value);
        const data = await fetch(API, {method:'POST', body:fd}).then(r=>r.json());
        if (data.success) {
            row.querySelector('td:nth-child(4)').textContent = select.value;
            btn.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#22c55e;"></i> Saved';
            setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-check"></i> Save'; }, 2000);
        } else { showAlertModal(data.message, 'error'); }
    });
});
</script>
</body></html>
<?php $conn->close(); ?>
