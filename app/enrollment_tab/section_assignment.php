<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// Enrollments without section
$unassigned = [];
$res = $conn->query(
    "SELECT e.*, p.first_name, p.last_name FROM enrollments e
     JOIN pre_registrations p ON e.pre_reg_id = p.id
     WHERE (e.section IS NULL OR e.section = '')
     ORDER BY p.last_name ASC"
);
if ($res) while ($r = $res->fetch_assoc()) $unassigned[] = $r;

// All sections
$sections = [];
$res2 = $conn->query("SELECT * FROM sections WHERE is_active=1 ORDER BY section_code ASC");
if ($res2) while ($r = $res2->fetch_assoc()) $sections[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Section Assignment – BCP</title>
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
      <h2 class="page-title"><i class="fa-solid fa-chalkboard"></i> Auto Section Assignment</h2>
    </div>

    <!-- Section capacity overview -->
    <div class="form-card">
      <h3>Section Capacity Overview</h3>
      <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:10px; margin-top:12px;">
        <?php foreach ($sections as $sec):
          $pct   = $sec['max_capacity'] > 0 ? round(($sec['current_count']/$sec['max_capacity'])*100) : 0;
          $color = $pct >= 100 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#22c55e');
        ?>
        <div style="background:#f9fafb; border-radius:8px; padding:12px 14px; border:1.5px solid #e8edf4;">
          <div style="font-size:0.8rem; font-weight:700; color:#1a1a2e;"><?= htmlspecialchars($sec['section_code']) ?></div>
          <div style="font-size:0.7rem; color:#888; margin:3px 0;"><?= htmlspecialchars($sec['year_level']) ?></div>
          <div style="background:#e8edf4; border-radius:20px; height:6px; margin:6px 0;">
            <div style="background:<?= $color ?>; height:6px; border-radius:20px; width:<?= min($pct,100) ?>%;"></div>
          </div>
          <div style="font-size:0.7rem; color:<?= $color ?>; font-weight:600;">
            <?= $sec['current_count'] ?>/<?= $sec['max_capacity'] ?> (<?= $pct ?>%)
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Unassigned students -->
    <div class="crud-card">
      <div class="crud-header">
        <h3>Unassigned Students (<?= count($unassigned) ?>)</h3>
        <?php if ($unassigned): ?>
        <button class="btn-add" id="btnAutoAssignAll">
          <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Assign All
        </button>
        <?php endif; ?>
      </div>
      <table class="crud-table">
        <thead><tr><th>ID Number</th><th>Name</th><th>Course</th><th>Year Level</th><th>Assign Section</th></tr></thead>
        <tbody id="unassignedTbody">
          <?php if ($unassigned): foreach ($unassigned as $e): ?>
          <tr data-id="<?= $e['id'] ?>" data-course="<?= htmlspecialchars($e['course']) ?>" data-year="<?= htmlspecialchars($e['year_level']) ?>">
            <td><strong style="color:#2563eb;font-size:0.78rem;"><?= htmlspecialchars($e['id_number']) ?></strong></td>
            <td><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></td>
            <td style="font-size:0.75rem;"><?= htmlspecialchars($e['course']) ?></td>
            <td><?= htmlspecialchars($e['year_level']) ?></td>
            <td>
              <div style="display:flex; gap:8px; align-items:center;">
                <select class="section-select" style="height:34px; border:1.5px solid #d0d7e2; border-radius:6px; padding:0 10px; font-size:0.78rem; max-width:160px;">
                  <option value="">Select section…</option>
                  <?php foreach ($sections as $sec):
                    $match = str_contains($sec['course'], substr($e['course'],0,4)) && $sec['year_level']===$e['year_level'];
                    $full  = $sec['current_count'] >= $sec['max_capacity'];
                  ?>
                  <option value="<?= htmlspecialchars($sec['section_code']) ?>"
                    <?= $match ? 'style="font-weight:700;"' : '' ?>
                    <?= $full  ? 'disabled' : '' ?>>
                    <?= htmlspecialchars($sec['section_code']) ?> (<?= $sec['current_count'] ?>/<?= $sec['max_capacity'] ?>)<?= $full?' [FULL]':'' ?>
                  </option>
                  <?php endforeach; ?>
                </select>
                <button class="btn-add btn-assign-sec" data-id="<?= $e['id'] ?>" style="padding:6px 12px;font-size:0.78rem;">
                  <i class="fa-solid fa-check"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="5" style="text-align:center;padding:24px;color:#aaa;">All students have been assigned to sections.</td></tr>
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

async function assignSection(enrollmentId, sectionCode) {
    const fd = new FormData();
    fd.set('action',        'assign_section');
    fd.set('enrollment_id', enrollmentId);
    fd.set('section_code',  sectionCode);
    return fetch(API, {method:'POST', body:fd}).then(r=>r.json());
}

document.querySelectorAll('.btn-assign-sec').forEach(btn => {
    btn.addEventListener('click', async () => {
        const row  = btn.closest('tr');
        const sel  = row.querySelector('.section-select');
        if (!sel.value) { showAlertModal('Please select a section.', 'warning'); return; }
        btn.disabled = true;
        const data = await assignSection(btn.dataset.id, sel.value);
        if (data.success) { row.remove(); }
        else { showAlertModal(data.message, 'error'); btn.disabled = false; }
    });
});

document.getElementById('btnAutoAssignAll')?.addEventListener('click', async (ev) => {
    ev.preventDefault();
    showConfirmModal('Auto-assign all unassigned students to the best available section?', async (confirmed) => {
        if (!confirmed) return;
        const rows = [...document.querySelectorAll('#unassignedTbody tr[data-id]')];
        for (const row of rows) {
            const id   = row.dataset.id;
            const sel  = row.querySelector('.section-select');
            const best = [...sel.options].find(o => o.value && !o.disabled);
            if (best) {
                const data = await assignSection(id, best.value);
                if (data.success) row.remove();
            }
        }
        location.reload();
    }, 'Auto-Assign Confirm');
};
</script>
</body></html>
<?php $conn->close(); ?>
