<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))          { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'student')      { header('Location: ../admin_dashboard/dashboard.php'); exit; }
if (!empty($_SESSION['must_set_password'])){ header('Location: ../auth/set_password.php'); exit; }

$uid          = (int)$_SESSION['user_id'];
$sess_first   = htmlspecialchars($_SESSION['first_name'] ?? '');
$sess_last    = htmlspecialchars($_SESSION['last_name']  ?? '');
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// My application status
$my_app = null;
$my_docs_count = 0;
if (enrollment_tables_exist($conn)) {
    $r = $conn->prepare("SELECT * FROM pre_registrations WHERE user_id=? ORDER BY submitted_at DESC LIMIT 1");
    $r->bind_param('i', $uid); $r->execute();
    $my_app = $r->get_result()->fetch_assoc(); $r->close();
    if ($my_app) {
        $r2 = $conn->query("SELECT COUNT(*) c FROM enrollment_documents WHERE pre_reg_id={$my_app['id']}");
        if ($r2) $my_docs_count = (int)$r2->fetch_assoc()['c'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>My Dashboard – BCP Student Portal</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php $APP_ROOT='../'; $ACTIVE_NAV='home'; require_once __DIR__.'/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <a href="account.php" class="avatar" title="Account Settings"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-gauge"></i> My Dashboard</h2>
    </div>

    <!-- Welcome -->
    <div class="info-row">
      <div class="info-card" style="flex:2;">
        <div class="card-label"><i class="fa-solid fa-user-graduate"></i> Welcome</div>
        <div class="card-name"><?= $sess_first . ' ' . $sess_last ?></div>
        <div class="card-detail">
          Student · BCP Student Portal<br>
          <span style="font-size:.72rem;color:#aaa;">A.Y. 2025–2026</span>
        </div>
      </div>

      <!-- Application status card -->
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-graduation-cap"></i> Application</div>
        <?php if ($my_app):
          $sc = match($my_app['status']) {
            'Approved'=>'#22c55e','Rejected'=>'#ef4444','Enrolled'=>'#2563eb',default=>'#f59e0b'
          }; ?>
        <div class="card-amount" style="color:<?= $sc ?>;font-size:1.1rem;">
          <?= htmlspecialchars($my_app['status']) ?>
        </div>
        <div class="card-detail"><?= htmlspecialchars(str_replace('Bachelor of Science in ','BS ',$my_app['course'])) ?></div>
        <a href="enrollment.php" class="card-btn" style="margin-top:8px;">
          <i class="fa-solid fa-arrow-right"></i> View Details
        </a>
        <?php else: ?>
        <div class="card-detail" style="margin-top:8px;">No application yet.</div>
        <a href="../enroll/index.php" class="card-btn" style="margin-top:8px;">
          <i class="fa-solid fa-plus"></i> Apply Now
        </a>
        <?php endif; ?>
      </div>

      <!-- Documents card -->
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-file-lines"></i> Documents</div>
        <div class="card-amount"><?= $my_docs_count ?></div>
        <div class="card-detail">Filed with registrar</div>
        <a href="../requirements/index.php" class="card-btn" style="margin-top:8px;">
          <i class="fa-solid fa-upload"></i> Upload More
        </a>
      </div>
    </div>

    <!-- Quick links -->
    <div class="form-card">
      <h3>Quick Actions</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-top:14px;">
        <?php $links = [
          ['enrollment.php',             'fa-graduation-cap','#2563eb','My Enrollment',  'View application status'],
          ['requirements.php',           'fa-upload',        '#7c3aed','Submit Docs',    'Upload requirements'],
          ['../schedule_tab/class_schedule.php','fa-calendar-days','#059669','Schedule', 'Class & exam schedule'],
          ['account.php',                'fa-user-gear',     '#d97706','Account',        'Change password & profile'],
          ['../enroll/index.php',        'fa-file-pen',      '#0891b2','New Application','Start enrollment'],
          ['../auth/signin.php',         'fa-right-from-bracket','#dc2626','Sign Out',   'End your session'],
        ];
        foreach ($links as [$href,$icon,$color,$title,$sub]): ?>
        <a href="<?= $href ?>"
           style="display:flex;flex-direction:column;gap:6px;background:#fff;
                  border:1.5px solid #e8edf4;border-radius:10px;padding:16px;
                  text-decoration:none;transition:box-shadow .15s,border-color .15s;"
           onmouseover="this.style.borderColor='<?= $color ?>';this.style.boxShadow='0 4px 16px rgba(0,0,0,.1)'"
           onmouseout="this.style.borderColor='#e8edf4';this.style.boxShadow=''">
          <i class="fa-solid <?= $icon ?>" style="font-size:1.3rem;color:<?= $color ?>;"></i>
          <span style="font-size:.85rem;font-weight:700;color:#1a1a2e;"><?= $title ?></span>
          <span style="font-size:.72rem;color:#888;"><?= $sub ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
</body></html>
<?php $conn->close(); ?>
