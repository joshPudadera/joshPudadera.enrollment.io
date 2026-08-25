<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

$filter = $_GET['status'] ?? 'Pending';
$allowed = ['Pending','Approved','Rejected','Enrolled'];
$filter  = in_array($filter, $allowed) ? $filter : 'Pending';
$apps    = [];
$res = $conn->query("SELECT p.*, COUNT(d.id) AS doc_count
    FROM pre_registrations p
    LEFT JOIN enrollment_documents d ON d.pre_reg_id = p.id
    WHERE p.status = '$filter'
    GROUP BY p.id ORDER BY p.submitted_at ASC");
if ($res) while ($r = $res->fetch_assoc()) $apps[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Enrollment Validation – BCP</title>
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
      <h2 class="page-title"><i class="fa-solid fa-clipboard-check"></i> Enrollment Validation</h2>
    </div>

    <!-- Filter tabs -->
    <div style="padding: 0 24px; margin-bottom:16px; display:flex; gap:8px; flex-wrap:wrap;">
      <?php foreach (['Pending','Approved','Rejected','Enrolled'] as $s):
        $active = $filter===$s ? 'background:#2563eb;color:#fff;border-color:#2563eb;' : '';
      ?>
      <a href="?status=<?= $s ?>" class="pg-btn" style="<?= $active ?>"><?= $s ?></a>
      <?php endforeach; ?>
    </div>

    <div class="crud-card">
      <div class="crud-header"><h3><?= $filter ?> Applications (<?= count($apps) ?>)</h3></div>
      <table class="crud-table">
        <thead><tr><th>Name</th><th>Course</th><th>Year</th><th>Docs</th><th>Submitted</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if ($apps): foreach ($apps as $app): ?>
          <tr data-id="<?= $app['id'] ?>">
            <td><?= htmlspecialchars($app['first_name'].' '.$app['last_name']) ?></td>
            <td style="font-size:0.75rem;"><?= htmlspecialchars($app['course']) ?></td>
            <td><?= htmlspecialchars($app['year_level']) ?></td>
            <td><span class="<?= $app['doc_count']>0?'badge-active':'badge-inactive' ?>"><?= $app['doc_count'] ?> file(s)</span></td>
            <td style="font-size:0.75rem;color:#888;"><?= date('M d, Y', strtotime($app['submitted_at'])) ?></td>
            <td class="actions-cell">
              <?php if ($filter==='Pending'): ?>
              <button class="btn-icon btn-view btn-approve" title="Approve" data-id="<?= $app['id'] ?>">
                <i class="fa-solid fa-circle-check" style="color:#22c55e;font-size:1rem;"></i>
              </button>
              <button class="btn-icon btn-delete btn-reject" title="Reject" data-id="<?= $app['id'] ?>">
                <i class="fa-solid fa-circle-xmark" style="color:#ef4444;font-size:1rem;"></i>
              </button>
              <?php else: ?>
              <span style="font-size:0.75rem;color:#aaa;">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="6" style="text-align:center;padding:24px;color:#aaa;">No <?= strtolower($filter) ?> applications.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- Validate modal -->
<div class="modal-overlay" id="validateModal">
  <div class="modal modal-sm">
    <div class="modal-header"><span id="validateTitle">Validate Application</span><button class="modal-close" data-close="validateModal">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="valPreRegId"/>
      <input type="hidden" id="valStatus"/>
      <div class="form-field full" style="margin-top:8px;">
        <label>Remarks (optional)</label>
        <input type="text" id="valRemarks" placeholder="Add a note…"/>
      </div>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="validateModal">Cancel</button>
      <button class="btn-modal-confirm" id="btnConfirmValidate">Confirm</button>
    </div>
  </div>
</div>

<!-- Login link modal (shown after approval when email may not work) -->
<div class="modal-overlay" id="loginLinkModal">
  <div class="modal">
    <div class="modal-header">
      <span><i class="fa-solid fa-circle-check" style="color:#22c55e;margin-right:6px;"></i> Application Approved</span>
      <button class="modal-close" data-close="loginLinkModal">&times;</button>
    </div>
    <div class="modal-body">
      <p style="font-size:.85rem;color:#555;margin-bottom:14px;">
        A welcome email was sent to the student. If email is not configured on this server,
        share this one-time login link manually:
      </p>
      <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 16px;margin-bottom:12px;">
        <div style="font-size:.72rem;color:#aaa;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em;">Student Login Link</div>
        <a id="loginLinkUrl" href="#" target="_blank"
           style="font-size:.78rem;color:#2563eb;word-break:break-all;text-decoration:underline;"></a>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <div>
          <div style="font-size:.72rem;color:#aaa;">Username</div>
          <code id="loginLinkUsername" style="font-size:.85rem;font-weight:700;color:#1a1a2e;background:#f3f4f6;padding:3px 8px;border-radius:4px;"></code>
        </div>
      </div>
      <p style="font-size:.72rem;color:#f59e0b;margin-top:12px;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        This link expires in 72 hours and can only be used once.
      </p>
    </div>
    <div class="modal-footer" style="justify-content:space-between;gap:10px;">
      <button id="btnCopyLink" class="btn-primary">
        <i class="fa-solid fa-copy"></i> Copy Link
      </button>
      <button class="btn-modal-cancel" data-close="loginLinkModal">Close</button>
    </div>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
const API = '../shared/enrollment_actions.php';

document.querySelectorAll('.btn-approve').forEach(btn => {
    btn.addEventListener('click', () => openValidate(btn.dataset.id, 'Approved'));
});
document.querySelectorAll('.btn-reject').forEach(btn => {
    btn.addEventListener('click', () => openValidate(btn.dataset.id, 'Rejected'));
});

function openValidate(id, status) {
    document.getElementById('valPreRegId').value = id;
    document.getElementById('valStatus').value   = status;
    document.getElementById('validateTitle').textContent = status === 'Approved' ? 'Approve Application' : 'Reject Application';
    document.getElementById('validateModal').classList.add('active');
}

document.getElementById('btnConfirmValidate').addEventListener('click', async () => {
    const btn = document.getElementById('btnConfirmValidate');
    btn.disabled = true;
    btn.textContent = 'Processing…';

    const fd = new FormData();
    fd.set('action',     'validate_application');
    fd.set('pre_reg_id', document.getElementById('valPreRegId').value);
    fd.set('status',     document.getElementById('valStatus').value);
    fd.set('remarks',    document.getElementById('valRemarks').value);

    try {
        const res  = await fetch(API, {method:'POST', body:fd});
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch(e) {
            // PHP returned non-JSON (warning/error) — show it
            console.error('Non-JSON response:', text);
            showAlertModal('Server error. Check console for details.\n\n' + text.substring(0, 300), 'error');
            btn.disabled = false;
            btn.textContent = 'Confirm';
            return;
        }

        // Close the validate modal
        document.getElementById('validateModal').classList.remove('active');
        btn.disabled = false;
        btn.textContent = 'Confirm';

        if (data.success) {
            // If this was an approval and we got a login link back, show it
            if (data.login_url) {
                document.getElementById('loginLinkUrl').href        = data.login_url;
                document.getElementById('loginLinkUrl').textContent = data.login_url;
                document.getElementById('loginLinkUsername').textContent = data.username || '—';
                document.getElementById('loginLinkModal').classList.add('active');
            } else {
                location.reload();
            }
        } else {
            showAlertModal(data.message, 'error');
        }
    } catch (e) {
        btn.disabled = false;
        btn.textContent = 'Confirm';
        showAlertModal('Request failed. Please try again.', 'error');
    }
});

// Copy link button
document.getElementById('btnCopyLink').addEventListener('click', () => {
    const url = document.getElementById('loginLinkUrl').href;
    navigator.clipboard.writeText(url).then(() => {
        const btn = document.getElementById('btnCopyLink');
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
        setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy Link'; }, 2000);
    }).catch(() => {
        // Fallback for older browsers
        const el = document.createElement('textarea');
        el.value = url;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    });
});

// After closing the login link modal, reload to update the table
document.querySelectorAll('[data-close="loginLinkModal"]').forEach(btn => {
    btn.addEventListener('click', () => location.reload());
});
</script>
</body></html>
<?php $conn->close(); ?>
