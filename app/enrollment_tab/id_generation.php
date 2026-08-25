<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// Approved apps that don't yet have an enrollment record
$apps = [];
$res = $conn->query(
    "SELECT p.* FROM pre_registrations p
     LEFT JOIN enrollments e ON e.pre_reg_id = p.id
     WHERE p.status = 'Approved' AND e.id IS NULL
     ORDER BY p.submitted_at ASC"
);
if ($res) while ($r = $res->fetch_assoc()) $apps[] = $r;

// Already enrolled
$enrolled = [];
$res2 = $conn->query(
    "SELECT e.*, p.first_name, p.last_name FROM enrollments e
     JOIN pre_registrations p ON e.pre_reg_id = p.id
     ORDER BY e.enrolled_at DESC LIMIT 20"
);
if ($res2) while ($r = $res2->fetch_assoc()) $enrolled[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ID Generation – BCP</title>
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
      <h2 class="page-title"><i class="fa-solid fa-id-badge"></i> ID Number Generation</h2>
    </div>

    <!-- Pending ID generation -->
    <div class="crud-card">
      <div class="crud-header"><h3>Approved — Pending ID (<?= count($apps) ?>)</h3></div>
      <table class="crud-table">
        <thead><tr><th>Name</th><th>Course</th><th>Year Level</th><th>Submitted</th><th>Action</th></tr></thead>
        <tbody>
          <?php if ($apps): foreach ($apps as $app): ?>
          <tr>
            <td><?= htmlspecialchars($app['first_name'].' '.$app['last_name']) ?></td>
            <td style="font-size:0.75rem;"><?= htmlspecialchars($app['course']) ?></td>
            <td><?= htmlspecialchars($app['year_level']) ?></td>
            <td style="font-size:0.75rem;color:#888;"><?= date('M d, Y', strtotime($app['submitted_at'])) ?></td>
            <td>
              <button class="btn-add btn-gen-id"
                      data-id="<?= $app['id'] ?>"
                      data-course="<?= htmlspecialchars($app['course']) ?>"
                      data-year="<?= htmlspecialchars($app['year_level']) ?>"
                      style="padding:6px 14px; font-size:0.78rem;">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Generate ID
              </button>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="5" style="text-align:center;padding:24px;color:#aaa;">No pending ID generations.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Already enrolled with IDs -->
    <div class="crud-card">
      <div class="crud-header"><h3>Recently Generated IDs</h3></div>
      <table class="crud-table">
        <thead><tr><th>ID Number</th><th>Name</th><th>Course</th><th>Year</th><th>Section</th><th>Date</th></tr></thead>
        <tbody>
          <?php if ($enrolled): foreach ($enrolled as $e): ?>
          <tr>
            <td><strong style="color:#2563eb;"><?= htmlspecialchars($e['id_number']) ?></strong></td>
            <td><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></td>
            <td style="font-size:0.75rem;"><?= htmlspecialchars($e['course']) ?></td>
            <td><?= htmlspecialchars($e['year_level']) ?></td>
            <td><?= $e['section'] ? htmlspecialchars($e['section']) : '<span style="color:#aaa;">—</span>' ?></td>
            <td style="font-size:0.75rem;color:#888;"><?= date('M d, Y', strtotime($e['enrolled_at'])) ?></td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="6" style="text-align:center;padding:24px;color:#aaa;">No IDs generated yet.</td></tr>
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
document.querySelectorAll('.btn-gen-id').forEach(btn => {
    btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        showConfirmModal('Generate an ID number for this student?', async (confirmed) => {
            if (!confirmed) return;
            btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            const fd = new FormData();
            fd.set('action',     'generate_id');
            fd.set('pre_reg_id', btn.dataset.id);
            fd.set('course',     btn.dataset.course);
            fd.set('year_level', btn.dataset.year);
            const data = await fetch(API, {method:'POST', body:fd}).then(r=>r.json());
            if (data.success) {
                showAlertModal('ID Generated: ' + data.id_number, 'success', 'ID Generated');
                setTimeout(() => location.reload(), 1500);
            } else {
                showAlertModal(data.message, 'error');
                btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate ID';
            }
        }, 'Generate ID');
    });
});
</script>
</body></html>
<?php $conn->close(); ?>
