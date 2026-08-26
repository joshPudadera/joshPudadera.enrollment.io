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
  <style>
    .btn-primary, .btn-approve, .btn-reject, .btn-secondary {
      font-family: 'Segoe UI', sans-serif; font-size: .82rem; font-weight: 700;
      cursor: pointer; border: none; border-radius: 8px; padding: 8px 18px;
      display: inline-flex; align-items: center; gap: 7px;
      text-decoration: none; transition: background .15s, box-shadow .15s;
    }
    .btn-primary  { background: #1a3a8c; color: #fff; }
    .btn-primary:hover  { background: #142d6e; box-shadow: 0 2px 8px rgba(26,58,140,.35); }
    .btn-approve  { background: #16a34a; color: #fff; }
    .btn-approve:hover  { background: #15803d; }
    .btn-reject   { background: #ef4444; color: #fff; }
    .btn-reject:hover   { background: #dc2626; }
    .btn-secondary { background: #f3f4f6; color: #444; border: 1.5px solid #d0d7e2; }
    .btn-secondary:hover { background: #e5e7eb; }
  </style>
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
        <thead>
          <tr>
            <th>Name</th>
            <th>Course</th>
            <th>Year Level</th>
            <th>Submitted</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($apps): foreach ($apps as $app): ?>
          <tr>
            <td><?= htmlspecialchars($app['first_name'].' '.$app['last_name']) ?></td>
            <td style="font-size:.75rem;"><?= htmlspecialchars(preg_replace('/Bachelor of Science in /i','BS ',$app['course'])) ?></td>
            <td><?= htmlspecialchars($app['year_level']) ?></td>
            <td style="font-size:.75rem;color:#888;"><?= date('M d, Y', strtotime($app['submitted_at'])) ?></td>
            <td>
              <button class="btn-primary btn-gen-id"
                      data-id="<?= $app['id'] ?>"
                      data-name="<?= htmlspecialchars($app['first_name'].' '.$app['last_name']) ?>"
                      data-course="<?= htmlspecialchars($app['course']) ?>"
                      data-year="<?= htmlspecialchars($app['year_level']) ?>">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Generate ID
              </button>
            </td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td colspan="5" style="text-align:center;padding:24px;color:#aaa;">
            No pending ID generations.
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Already enrolled with IDs -->
    <div class="crud-card">
      <div class="crud-header"><h3>Recently Generated IDs</h3></div>
      <table class="crud-table">
        <thead>
          <tr>
            <th>ID Number</th>
            <th>Name</th>
            <th>Course</th>
            <th>Year</th>
            <th>Section</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($enrolled): foreach ($enrolled as $e): ?>
          <tr>
            <td><strong style="color:#2563eb;"><?= htmlspecialchars($e['id_number']) ?></strong></td>
            <td><?= htmlspecialchars($e['first_name'].' '.$e['last_name']) ?></td>
            <td style="font-size:.75rem;"><?= htmlspecialchars(preg_replace('/Bachelor of Science in /i','BS ',$e['course'])) ?></td>
            <td><?= htmlspecialchars($e['year_level']) ?></td>
            <td><?= $e['section'] ? htmlspecialchars($e['section']) : '<span style="color:#aaa;">—</span>' ?></td>
            <td style="font-size:.75rem;color:#888;"><?= date('M d, Y', strtotime($e['enrolled_at'])) ?></td>
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

<!-- Confirm Modal -->
<div class="modal-overlay" id="genConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:28px 28px 20px;max-width:400px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.2);">
    <h3 style="font-size:1rem;font-weight:700;color:#1a1a2e;margin-bottom:8px;">Generate Student ID</h3>
    <p id="genConfirmMsg" style="font-size:.88rem;color:#555;margin-bottom:20px;line-height:1.5;"></p>
    <div style="display:flex;gap:10px;justify-content:flex-end;">
      <button id="genConfirmCancel" style="background:#f3f4f6;color:#444;border:1.5px solid #d0d7e2;border-radius:8px;padding:9px 20px;font-size:.85rem;font-weight:600;cursor:pointer;font-family:'Segoe UI',sans-serif;">Cancel</button>
      <button id="genConfirmOk"     style="background:#1a3a8c;color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:.85rem;font-weight:700;cursor:pointer;font-family:'Segoe UI',sans-serif;">Generate ID</button>
    </div>
  </div>
</div>

<!-- Result Modal -->
<div class="modal-overlay" id="genResultModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:28px;max-width:400px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.2);text-align:center;">
    <i id="genResultIcon" class="fa-solid fa-circle-check" style="font-size:2.5rem;color:#16a34a;display:block;margin-bottom:14px;"></i>
    <h3 id="genResultTitle" style="font-size:1rem;font-weight:700;color:#1a1a2e;margin-bottom:8px;"></h3>
    <p id="genResultMsg"   style="font-size:.88rem;color:#555;margin-bottom:20px;line-height:1.5;"></p>
    <button id="genResultClose" style="background:#1a3a8c;color:#fff;border:none;border-radius:8px;padding:10px 32px;font-size:.88rem;font-weight:700;cursor:pointer;font-family:'Segoe UI',sans-serif;">OK</button>
  </div>
</div>

<script src="../js/sidebar.js"></script>
<script src="../js/dashboard.js"></script>
<script>
(function() {
    var API            = '../shared/enrollment_actions.php';
    var confirmModal   = document.getElementById('genConfirmModal');
    var confirmMsg     = document.getElementById('genConfirmMsg');
    var confirmOk      = document.getElementById('genConfirmOk');
    var confirmCancel  = document.getElementById('genConfirmCancel');
    var resultModal    = document.getElementById('genResultModal');
    var resultIcon     = document.getElementById('genResultIcon');
    var resultTitle    = document.getElementById('genResultTitle');
    var resultMsg      = document.getElementById('genResultMsg');
    var resultClose    = document.getElementById('genResultClose');

    var _pendingBtn = null;

    function showConfirm(msg, btn) {
        confirmMsg.textContent = msg;
        confirmModal.style.display = 'flex';
        _pendingBtn = btn;
    }

    function hideConfirm() {
        confirmModal.style.display = 'none';
        _pendingBtn = null;
    }

    function showResult(success, title, msg) {
        resultIcon.className = success
            ? 'fa-solid fa-circle-check'
            : 'fa-solid fa-circle-xmark';
        resultIcon.style.color = success ? '#16a34a' : '#dc2626';
        resultTitle.textContent = title;
        resultMsg.textContent   = msg;
        resultModal.style.display = 'flex';
    }

    confirmCancel.addEventListener('click', hideConfirm);
    confirmModal.addEventListener('click', function(e) {
        if (e.target === confirmModal) hideConfirm();
    });

    confirmOk.addEventListener('click', function() {
        var btn = _pendingBtn;
        hideConfirm();
        if (!btn) return;

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating…';

        var fd = new FormData();
        fd.append('action',     'generate_id');
        fd.append('pre_reg_id', btn.getAttribute('data-id'));
        fd.append('course',     btn.getAttribute('data-course'));
        fd.append('year_level', btn.getAttribute('data-year'));

        fetch(API, { method: 'POST', body: fd })
            .then(function(r) { return r.text(); })
            .then(function(text) {
                var data;
                try { data = JSON.parse(text); }
                catch(e) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate ID';
                    showResult(false, 'Server Error', text.substring(0, 300));
                    return;
                }
                if (data.success) {
                    showResult(true, 'ID Generated!', 'Student ID: ' + data.id_number);
                    resultClose.onclick = function() {
                        resultModal.style.display = 'none';
                        location.reload();
                    };
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate ID';
                    showResult(false, 'Error', data.message);
                    resultClose.onclick = function() {
                        resultModal.style.display = 'none';
                    };
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Generate ID';
                showResult(false, 'Request Failed', 'Could not reach the server. Check your connection.');
                resultClose.onclick = function() { resultModal.style.display = 'none'; };
            });
    });

    resultClose.addEventListener('click', function() {
        resultModal.style.display = 'none';
    });
    resultModal.addEventListener('click', function(e) {
        if (e.target === resultModal) resultModal.style.display = 'none';
    });

    document.querySelectorAll('.btn-gen-id').forEach(function(btn) {
        btn.addEventListener('click', function() {
            showConfirm(
                'Generate a student ID number for ' + btn.getAttribute('data-name') + '?',
                btn
            );
        });
    });
}());
</script>
</body>
</html>
<?php $conn->close(); ?>
