<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

$filter  = $_GET['status'] ?? 'Pending';
$allowed = ['Pending','Approved','Rejected','Enrolled'];
$filter  = in_array($filter, $allowed) ? $filter : 'Pending';
$apps    = [];
$res = $conn->query("
    SELECT p.*, COUNT(d.id) AS doc_count
    FROM pre_registrations p
    LEFT JOIN enrollment_documents d ON d.pre_reg_id = p.id
    WHERE p.status = '" . $conn->real_escape_string($filter) . "'
    GROUP BY p.id ORDER BY p.submitted_at ASC
");
if ($res) while ($r = $res->fetch_assoc()) $apps[] = $r;
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Enrollment Validation - BCP</title>
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
      <a href="../admin_dashboard/account.php" class="avatar"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title"><i class="fa-solid fa-clipboard-check"></i> Enrollment Validation</h2>
    </div>

    <div style="padding:0 24px;margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;">
      <?php foreach (['Pending','Approved','Rejected','Enrolled'] as $s): ?>
      <a href="?status=<?= $s ?>" class="pg-btn<?= $filter===$s?' active':'' ?>"><?= $s ?></a>
      <?php endforeach; ?>
    </div>

    <div class="crud-card">
      <div class="crud-header">
        <h3><?= htmlspecialchars($filter) ?> Applications (<?= count($apps) ?>)</h3>
      </div>
      <table class="crud-table">
        <thead>
          <tr><th>Name</th><th>Course</th><th>Year</th><th>Docs</th><th>Submitted</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php if ($apps): foreach ($apps as $app): ?>
          <tr>
            <td><?= htmlspecialchars($app['first_name'].' '.$app['last_name']) ?></td>
            <td style="font-size:.75rem;"><?= htmlspecialchars($app['course']) ?></td>
            <td><?= htmlspecialchars($app['year_level']) ?></td>
            <td><span class="<?= $app['doc_count']>0?'badge-active':'badge-inactive' ?>"><?= (int)$app['doc_count'] ?> file(s)</span></td>
            <td style="font-size:.75rem;color:#888;"><?= date('M d, Y', strtotime($app['submitted_at'])) ?></td>
            <td class="actions-cell">
              <?php if ($filter==='Pending'): ?>
              <button class="btn-icon val-approve-btn" title="Approve" data-id="<?= (int)$app['id'] ?>">
                <i class="fa-solid fa-circle-check" style="color:#22c55e;font-size:1rem;pointer-events:none;"></i>
              </button>
              <button class="btn-icon val-reject-btn" title="Reject" data-id="<?= (int)$app['id'] ?>">
                <i class="fa-solid fa-circle-xmark" style="color:#ef4444;font-size:1rem;pointer-events:none;"></i>
              </button>
              <?php else: ?>
              <span style="font-size:.75rem;color:#aaa;">-</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="6" style="text-align:center;padding:24px;color:#aaa;">No <?= strtolower(htmlspecialchars($filter)) ?> applications.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- VALIDATE MODAL -->
<div class="modal-overlay" id="validateModal">
  <div class="modal">
    <div class="modal-header">
      <span id="validateTitle">Validate Application</span>
      <button class="modal-close" data-close="validateModal">&times;</button>
    </div>
    <div class="modal-body" id="validateModalBody">
      <input type="hidden" id="valPreRegId"/>
      <input type="hidden" id="valStatus"/>
      <div style="margin-top:4px;">
        <label style="display:block;font-size:.78rem;font-weight:600;color:#444;margin-bottom:6px;">Remarks (optional)</label>
        <input type="text" id="valRemarks" placeholder="Add a note..."
               style="width:100%;height:40px;border:1.5px solid #d0d7e2;border-radius:8px;
                      padding:0 12px;font-size:.85rem;outline:none;font-family:inherit;box-sizing:border-box;"/>
      </div>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="validateModal">Cancel</button>
      <button class="btn-modal-confirm" id="btnConfirmValidate">Confirm</button>
    </div>
  </div>
</div>

<!-- LOGIN LINK MODAL -->
<div class="modal-overlay" id="loginLinkModal">
  <div class="modal">
    <div class="modal-header">
      <span><i class="fa-solid fa-circle-check" style="color:#86efac;margin-right:6px;"></i>Application Approved</span>
      <button class="modal-close" data-close="loginLinkModal">&times;</button>
    </div>
    <div class="modal-body">
      <p style="font-size:.85rem;color:#555;margin-bottom:14px;">
        A welcome email was sent. If email is not configured, share this one-time login link manually:
      </p>
      <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 16px;margin-bottom:14px;">
        <div style="font-size:.7rem;color:#aaa;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px;">Student Login Link</div>
        <a id="loginLinkUrl" href="#" target="_blank"
           style="font-size:.78rem;color:#2563eb;word-break:break-all;text-decoration:underline;"></a>
      </div>
      <div style="margin-bottom:12px;">
        <div style="font-size:.7rem;color:#aaa;margin-bottom:4px;">Username</div>
        <code id="loginLinkUsername" style="font-size:.88rem;font-weight:700;color:#1a1a2e;background:#f3f4f6;padding:4px 10px;border-radius:4px;"></code>
      </div>
      <p style="font-size:.72rem;color:#f59e0b;">
        <i class="fa-solid fa-triangle-exclamation"></i> This link expires in 72 hours and can only be used once.
      </p>
    </div>
    <div class="modal-footer modal-footer-split">
      <button id="btnCopyLink" class="btn-modal-submit">
        <i class="fa-solid fa-copy"></i> Copy Link
      </button>
      <button class="btn-modal-cancel" data-close="loginLinkModal">Close</button>
    </div>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script src="../js/validation.js?v=<?= filemtime(__DIR__.'/../js/validation.js') ?>"></script>
</body>
</html>
