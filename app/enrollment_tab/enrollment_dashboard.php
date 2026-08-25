<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }

$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$is_admin     = ($_SESSION['role'] ?? '') === 'admin';

// Stats
$total_prereg = $pending = $approved = $enrolled = $waiting = $total_sections = 0;
$r = $conn->query("SELECT COUNT(*) c FROM pre_registrations");            if($r) $total_prereg  = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Pending'");  if($r) $pending   = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Approved'"); if($r) $approved  = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c FROM pre_registrations WHERE status='Enrolled'"); if($r) $enrolled  = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c FROM waiting_list WHERE status='Waiting'");        if($r) $waiting   = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c FROM sections WHERE is_active=1");                if($r) $total_sections = (int)$r->fetch_assoc()['c'];

// Recent applications (last 5)
$recent = [];
$res = $conn->query("SELECT * FROM pre_registrations ORDER BY submitted_at DESC LIMIT 5");
if ($res) while ($row = $res->fetch_assoc()) $recent[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Enrollment Dashboard – BCP</title>
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
      <a href="../admin_dashboard/account.php" class="avatar" title="Account Settings"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-graduation-cap"></i> Enrollment Dashboard</h2>
    </div>

    <!-- ── ENROLLMENT PROGRESS STEPS ── -->
    <div class="enrollment-steps">
      <?php
      $steps = [
        ['fa-file-pen',       'Pre-Registration', $total_prereg > 0],
        ['fa-upload',         'Documents',        false],
        ['fa-clipboard-check','Validation',       $approved > 0],
        ['fa-id-badge',       'ID Generated',     $enrolled > 0],
        ['fa-layer-group',    'Grade Level',       $enrolled > 0],
        ['fa-chalkboard',     'Section Assigned',  false],
      ];
      $first_active = true;
      foreach ($steps as $i => [$icon, $label, $is_done]):
        $class = $is_done ? 'done' : ($first_active ? 'active' : '');
        if (!$is_done && $first_active) $first_active = false;
      ?>
      <div class="enr-step <?= $class ?>">
        <div class="enr-step-icon">
          <i class="fa-solid <?= $icon ?>"></i>
        </div>
        <span class="enr-step-label"><?= $label ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- ── STAT CARDS ── -->
    <div class="info-row">
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-file-pen"></i> Total Applications</div>
        <div class="card-amount"><?= $total_prereg ?></div>
        <div class="card-detail">All pre-registrations</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-clock"></i> Pending</div>
        <div class="card-amount" style="color:#f59e0b;"><?= $pending ?></div>
        <div class="card-detail">Awaiting validation</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-circle-check"></i> Approved</div>
        <div class="card-amount" style="color:#22c55e;"><?= $approved ?></div>
        <div class="card-detail">Ready for enrollment</div>
      </div>
    </div>

    <div class="info-row">
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-id-card"></i> Enrolled</div>
        <div class="card-amount" style="color:#2563eb;"><?= $enrolled ?></div>
        <div class="card-detail">ID numbers issued</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-list-ol"></i> Waiting List</div>
        <div class="card-amount" style="color:#ef4444;"><?= $waiting ?></div>
        <div class="card-detail">Queued for sections</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-chalkboard"></i> Active Sections</div>
        <div class="card-amount"><?= $total_sections ?></div>
        <div class="card-detail">Available sections</div>
      </div>
    </div>

    <!-- ── MODULE SHORTCUTS ── -->
    <div class="form-card">
      <h3>Enrollment Modules</h3>
      <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; margin-top:14px;">
        <?php
        $modules = [
          ['pre_registration.php',  'fa-file-pen',       '#2563eb', 'Pre-Registration',     'Submit a new application'],
          ['document_upload.php',   'fa-upload',         '#7c3aed', 'Document Upload',       'Upload required documents'],
          ['validation.php',        'fa-clipboard-check','#059669', 'Validation',            'Review & approve applications'],
          ['id_generation.php',     'fa-id-badge',       '#0891b2', 'ID Generation',         'Generate student ID numbers'],
          ['grade_assignment.php',  'fa-layer-group',    '#d97706', 'Grade Level',           'Assign grade levels'],
          ['waiting_list.php',      'fa-list-ol',        '#dc2626', 'Waiting List',          'Manage the queue'],
          ['cross_enrollment.php',  'fa-arrow-right-arrow-left','#7c3aed','Cross Enrollment','Mark cross-enrolled students'],
          ['section_assignment.php','fa-chalkboard',     '#16a34a', 'Section Assignment',    'Auto-assign sections'],
        ];
        foreach ($modules as [$href, $icon, $color, $title, $sub]):
        ?>
        <a href="<?= $href ?>" style="display:flex; flex-direction:column; gap:8px; background:#fff;
           border:1.5px solid #e8edf4; border-radius:10px; padding:16px 18px; text-decoration:none;
           transition:box-shadow 0.15s, border-color 0.15s;"
           onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,0.1)';this.style.borderColor='<?= $color ?>'"
           onmouseout="this.style.boxShadow='';this.style.borderColor='#e8edf4'">
          <i class="fa-solid <?= $icon ?>" style="font-size:1.4rem; color:<?= $color ?>;"></i>
          <span style="font-size:0.85rem; font-weight:700; color:#1a1a2e;"><?= $title ?></span>
          <span style="font-size:0.72rem; color:#888;"><?= $sub ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ── RECENT APPLICATIONS TABLE ── -->
    <div class="crud-card">
      <div class="crud-header">
        <h3>Recent Applications</h3>
        <a href="pre_registration.php" class="btn-add">
          <i class="fa-solid fa-plus"></i> New Application
        </a>
      </div>
      <table class="crud-table">
        <thead><tr>
          <th>Name</th><th>Course</th><th>Year Level</th><th>Status</th><th>Submitted</th>
        </tr></thead>
        <tbody>
          <?php if ($recent): foreach ($recent as $row):
            $badge = match($row['status']) {
              'Approved' => 'badge-active',
              'Rejected' => 'badge-inactive',
              'Enrolled' => 'badge-active',
              default    => ''
            };
            $style = $row['status']==='Pending' ? 'background:#fff7ed;color:#d97706;padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:600;' : '';
          ?>
          <tr>
            <td><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></td>
            <td><?= htmlspecialchars($row['course']) ?></td>
            <td><?= htmlspecialchars($row['year_level']) ?></td>
            <td>
              <?php if ($row['status']==='Pending'): ?>
                <span style="<?= $style ?>"><?= $row['status'] ?></span>
              <?php else: ?>
                <span class="<?= $badge ?>"><?= $row['status'] ?></span>
              <?php endif; ?>
            </td>
            <td style="font-size:0.75rem;color:#888;"><?= date('M d, Y', strtotime($row['submitted_at'])) ?></td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="5" style="text-align:center;padding:24px;color:#aaa;">No applications yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div><!-- end content -->
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
</body>
</html>
<?php $conn->close(); ?>
