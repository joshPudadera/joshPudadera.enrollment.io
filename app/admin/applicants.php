<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }

$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));

$search = trim($_GET['q'] ?? '');
$filter = $_GET['status'] ?? '';
$allowed = ['Pending','Approved','Rejected','Enrolled'];
$filter  = in_array($filter, $allowed) ? $filter : '';

$where = 'WHERE 1';
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (p.first_name LIKE '%$s%' OR p.last_name LIKE '%$s%'
                     OR p.email LIKE '%$s%' OR p.ref_number LIKE '%$s%')";
}
if ($filter) {
    $where .= " AND p.status = '" . $conn->real_escape_string($filter) . "'";
}

$applicants = [];
$res = $conn->query(
    "SELECT p.*,
            COUNT(d.id) AS doc_count,
            SUM(CASE WHEN d.status='Approved' THEN 1 ELSE 0 END) AS doc_approved
     FROM pre_registrations p
     LEFT JOIN enrollment_documents d ON d.pre_reg_id = p.id
     $where
     GROUP BY p.id
     ORDER BY p.submitted_at DESC"
);
if ($res) while ($r = $res->fetch_assoc()) $applicants[] = $r;

$APP_ROOT   = '../';
$ACTIVE_NAV = 'documents';
require_once __DIR__ . '/../admin_dashboard/sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Applicants – Admin</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap">
        <input type="text" placeholder="Search..." id="globalSearch"/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <a href="../admin_dashboard/account.php" class="avatar"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-users"></i> Applicants & Documents</h2>
    </div>

    <!-- Search + Filter bar -->
    <form method="GET" style="padding:0 24px 16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <div style="position:relative;flex:1;min-width:220px;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
               placeholder="Search by name, email or reference…"
               style="width:100%;height:42px;border:1.5px solid #d0d7e2;border-radius:8px;
                      padding:0 14px 0 38px;font-size:.85rem;outline:none;font-family:inherit;"/>
        <i class="fa-solid fa-magnifying-glass"
           style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#aaa;font-size:.82rem;"></i>
      </div>
      <select name="status"
              style="height:42px;border:1.5px solid #d0d7e2;border-radius:8px;
                     padding:0 14px;font-size:.85rem;outline:none;font-family:inherit;cursor:pointer;">
        <option value="">All Statuses</option>
        <?php foreach (['Pending','Approved','Rejected','Enrolled'] as $s): ?>
        <option value="<?= $s ?>" <?= $filter===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-primary" style="height:42px;">
        <i class="fa-solid fa-magnifying-glass"></i> Search
      </button>
      <?php if ($search || $filter): ?>
      <a href="applicants.php"
         style="height:42px;display:inline-flex;align-items:center;padding:0 16px;
                border:1.5px solid #d0d7e2;border-radius:8px;font-size:.85rem;
                color:#555;text-decoration:none;">
        <i class="fa-solid fa-xmark"></i>&nbsp; Clear
      </a>
      <?php endif; ?>
      <span style="font-size:.78rem;color:#aaa;white-space:nowrap;">
        <?= count($applicants) ?> result<?= count($applicants)!==1?'s':'' ?>
      </span>
    </form>

    <!-- Applicants table -->
    <div class="crud-card">
      <table class="crud-table">
        <thead>
          <tr>
            <th>Applicant</th>
            <th>Reference</th>
            <th>Course</th>
            <th>Status</th>
            <th>Documents</th>
            <th>Submitted</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($applicants)): ?>
          <tr><td colspan="7" style="text-align:center;padding:30px;color:#aaa;">
            No applicants found<?= $search ? " matching \"$search\"" : '' ?>.
          </td></tr>
          <?php endif; ?>
          <?php foreach ($applicants as $a):
            $sc = match($a['status']) {
              'Approved'=>'badge-active','Rejected'=>'badge-inactive',
              'Enrolled'=>'','Pending'=>''
            };
            $ps = in_array($a['status'],['Pending','Enrolled']) ?
              ($a['status']==='Enrolled'
                ? 'background:#eff6ff;color:#2563eb;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;'
                : 'background:#fff7ed;color:#d97706;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:600;')
              : '';
          ?>
          <tr style="cursor:pointer;" onclick="window.location='applicant_docs.php?id=<?= $a['id'] ?>'">
            <td>
              <div style="font-weight:700;color:#1a1a2e;">
                <?= htmlspecialchars($a['first_name'].' '.$a['last_name']) ?>
              </div>
              <div style="font-size:.72rem;color:#aaa;"><?= htmlspecialchars($a['email']) ?></div>
            </td>
            <td>
              <code style="font-size:.75rem;background:#f3f4f6;padding:2px 8px;border-radius:4px;color:#1a3a8c;">
                <?= htmlspecialchars($a['ref_number'] ?? '—') ?>
              </code>
            </td>
            <td style="font-size:.75rem;">
              <?= htmlspecialchars(preg_replace('/Bachelor of Science in /i','BS ',$a['course'])) ?>
            </td>
            <td>
              <?php if ($ps): ?>
              <span style="<?= $ps ?>"><?= $a['status'] ?></span>
              <?php else: ?>
              <span class="<?= $sc ?>"><?= $a['status'] ?></span>
              <?php endif; ?>
            </td>
            <td>
              <span style="font-size:.8rem;font-weight:600;color:<?= $a['doc_count']>0?'#2563eb':'#aaa' ?>;">
                <?= $a['doc_count'] ?> file<?= $a['doc_count']!=1?'s':'' ?>
              </span>
              <?php if ($a['doc_approved'] > 0): ?>
              <span style="font-size:.7rem;color:#16a34a;margin-left:4px;">
                (<?= $a['doc_approved'] ?> approved)
              </span>
              <?php endif; ?>
            </td>
            <td style="font-size:.75rem;color:#888;">
              <?= date('M d, Y', strtotime($a['submitted_at'])) ?>
            </td>
            <td onclick="event.stopPropagation();">
              <a href="applicant_docs.php?id=<?= $a['id'] ?>"
                 class="btn-view-file">
                <i class="fa-solid fa-folder-open"></i> View Docs
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
</body>
</html>
<?php $conn->close(); ?>
