<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../admin_dashboard/dashboard.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// ── Waiting list: all students with no section assigned ────────
// Includes both those explicitly in waiting_list table AND those
// who have an enrollment row but no waiting_list entry yet.

// Students in the formal waiting_list queue
$queue = [];
$res = $conn->query(
    "SELECT w.id AS wl_id, w.queue_position, w.reason, w.status AS wl_status, w.queued_at,
            w.pre_reg_id, w.course, w.year_level,
            p.first_name, p.last_name, p.ref_number,
            e.id AS enrollment_id, e.id_number, e.section, e.grade_confirmed
     FROM waiting_list w
     JOIN pre_registrations p ON w.pre_reg_id = p.id
     LEFT JOIN enrollments e ON e.pre_reg_id = w.pre_reg_id
     ORDER BY w.status ASC, w.queue_position ASC"
);
if ($res) while ($r = $res->fetch_assoc()) $queue[] = $r;

// Active (Waiting) vs history
$active    = array_filter($queue, fn($q) => $q['wl_status'] === 'Waiting');
$history   = array_filter($queue, fn($q) => $q['wl_status'] !== 'Waiting');
$active    = array_values($active);
$history   = array_values($history);

// Sections for the promote dropdown
$sections  = [];
$rs = $conn->query("SELECT section_code, course, year_level, max_capacity,
                    (SELECT COUNT(*) FROM enrollments WHERE section=s.section_code) AS actual_count
                    FROM sections s WHERE is_active=1 ORDER BY section_code ASC");
if ($rs) while ($r = $rs->fetch_assoc()) $sections[] = $r;

$waiting_count  = count($active);
$promoted_count = count(array_filter($history, fn($q) => $q['wl_status'] === 'Promoted'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Waiting List – BCP</title>
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
      <h2 class="page-title"><i class="fa-solid fa-list-ol"></i> Waiting List Queue</h2>
    </div>

    <!-- Stat cards -->
    <div class="info-row">
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-clock" style="color:#f59e0b;"></i> Waiting</div>
        <div class="card-amount" style="color:#f59e0b;"><?= $waiting_count ?></div>
        <div class="card-detail">Students awaiting section</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-circle-check" style="color:#16a34a;"></i> Promoted</div>
        <div class="card-amount" style="color:#16a34a;"><?= $promoted_count ?></div>
        <div class="card-detail">Assigned to a section</div>
      </div>
      <div class="info-card">
        <div class="card-label"><i class="fa-solid fa-chalkboard" style="color:#2563eb;"></i> Sections Available</div>
        <div class="card-amount" style="color:#2563eb;">
          <?= count(array_filter($sections, fn($s) => (int)$s['actual_count'] < (int)$s['max_capacity'])) ?>
        </div>
        <div class="card-detail">Sections with open slots</div>
      </div>
    </div>

    <!-- Active queue -->
    <div class="crud-card">
      <div class="crud-header">
        <h3>Active Queue (<?= $waiting_count ?>)</h3>
        <?php if ($waiting_count > 0): ?>
        <a href="section_assignment.php" class="btn-add">
          <i class="fa-solid fa-wand-magic-sparkles"></i> Go to Section Assignment
        </a>
        <?php endif; ?>
      </div>

      <?php if ($active): ?>
      <table class="crud-table">
        <thead><tr>
          <th style="width:42px;">#</th>
          <th>Name</th>
          <th>Course</th>
          <th>Year Level</th>
          <th>ID Number</th>
          <th>Step Reached</th>
          <th>Reason</th>
          <th>Queued</th>
          <th style="text-align:center;">Actions</th>
        </tr></thead>
        <tbody>
          <?php foreach ($active as $q): ?>
          <tr id="wl-row-<?= $q['wl_id'] ?>">
            <td><strong style="color:#2563eb;"><?= $q['queue_position'] ?></strong></td>
            <td><?= htmlspecialchars($q['first_name'] . ' ' . $q['last_name']) ?></td>
            <td style="font-size:.75rem;">
              <?= htmlspecialchars(preg_replace('/Bachelor of Science in /i', 'BS ', $q['course'])) ?>
            </td>
            <td><?= htmlspecialchars($q['year_level']) ?></td>
            <td>
              <?php if ($q['id_number']): ?>
              <code style="font-size:.72rem;background:#eff6ff;color:#2563eb;padding:2px 7px;border-radius:4px;">
                <?= htmlspecialchars($q['id_number']) ?>
              </code>
              <?php else: ?>
              <span style="color:#aaa;font-size:.75rem;">No ID yet</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!$q['enrollment_id']): ?>
              <span style="font-size:.72rem;color:#ef4444;font-weight:600;">
                <i class="fa-solid fa-xmark"></i> ID not generated
              </span>
              <?php elseif (!$q['grade_confirmed']): ?>
              <span style="font-size:.72rem;color:#f59e0b;font-weight:600;">
                <i class="fa-solid fa-clock"></i> Grade pending
              </span>
              <?php else: ?>
              <span style="font-size:.72rem;color:#16a34a;font-weight:600;">
                <i class="fa-solid fa-circle-check"></i> Ready
              </span>
              <?php endif; ?>
            </td>
            <td style="font-size:.75rem;color:#888;"><?= htmlspecialchars($q['reason']) ?></td>
            <td style="font-size:.75rem;color:#aaa;"><?= date('M d, Y', strtotime($q['queued_at'])) ?></td>
            <td style="text-align:center;">
              <div style="display:inline-flex;gap:6px;flex-wrap:wrap;justify-content:center;">
                <?php if ($q['grade_confirmed'] && $q['enrollment_id']): ?>
                <button class="btn-promote"
                        data-wl-id="<?= $q['wl_id'] ?>"
                        data-course="<?= htmlspecialchars($q['course']) ?>"
                        data-year="<?= htmlspecialchars($q['year_level']) ?>"
                        data-name="<?= htmlspecialchars($q['first_name'] . ' ' . $q['last_name']) ?>"
                        style="background:#16a34a;color:#fff;border:none;border-radius:7px;
                               padding:5px 10px;font-size:.72rem;font-weight:600;cursor:pointer;
                               display:inline-flex;align-items:center;gap:4px;">
                  <i class="fa-solid fa-arrow-up"></i> Promote
                </button>
                <?php else: ?>
                <span style="font-size:.72rem;color:#aaa;font-style:italic;">Not ready</span>
                <?php endif; ?>
                <button class="btn-cancel-wl"
                        data-wl-id="<?= $q['wl_id'] ?>"
                        data-name="<?= htmlspecialchars($q['first_name'] . ' ' . $q['last_name']) ?>"
                        style="background:#fee2e2;color:#dc2626;border:1.5px solid #fca5a5;
                               border-radius:7px;padding:5px 10px;font-size:.72rem;font-weight:600;
                               cursor:pointer;display:inline-flex;align-items:center;gap:4px;">
                  <i class="fa-solid fa-xmark"></i> Cancel
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div style="padding:32px;text-align:center;color:#aaa;font-size:.88rem;">
        <i class="fa-solid fa-circle-check" style="font-size:1.6rem;color:#16a34a;display:block;margin-bottom:10px;"></i>
        Waiting list is empty — all students have been assigned to a section.
      </div>
      <?php endif; ?>
    </div>

    <!-- History -->
    <?php if ($history): ?>
    <div class="crud-card">
      <div class="crud-header">
        <h3>History — Processed Entries (<?= count($history) ?>)</h3>
      </div>
      <table class="crud-table">
        <thead><tr>
          <th>Name</th><th>Course</th><th>Year Level</th><th>Status</th><th>Section</th><th>Queued</th>
        </tr></thead>
        <tbody>
          <?php foreach ($history as $q):
            $bc = $q['wl_status'] === 'Promoted' ? 'badge-active' : 'badge-inactive';
          ?>
          <tr>
            <td><?= htmlspecialchars($q['first_name'] . ' ' . $q['last_name']) ?></td>
            <td style="font-size:.75rem;"><?= htmlspecialchars(preg_replace('/Bachelor of Science in /i','BS ',$q['course'])) ?></td>
            <td><?= htmlspecialchars($q['year_level']) ?></td>
            <td><span class="<?= $bc ?>"><?= htmlspecialchars($q['wl_status']) ?></span></td>
            <td>
              <?php if ($q['section'] && $q['section'] !== 'TBA'): ?>
              <code style="font-size:.72rem;background:#eff6ff;color:#2563eb;padding:2px 7px;border-radius:4px;">
                <?= htmlspecialchars($q['section']) ?>
              </code>
              <?php else: ?>
              <span style="color:#aaa;font-size:.75rem;">—</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.75rem;color:#aaa;"><?= date('M d, Y', strtotime($q['queued_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<!-- Promote Modal -->
<div class="modal-overlay" id="promoteModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span><i class="fa-solid fa-arrow-up" style="margin-right:6px;color:#86efac;"></i> Promote Student</span>
      <button class="modal-close" data-close="promoteModal">&times;</button>
    </div>
    <div class="modal-body">
      <p style="font-size:.85rem;color:#555;margin-bottom:14px;">
        Assign <strong id="promoteStudentName"></strong> to a section:
      </p>
      <input type="hidden" id="promoteWlId"/>
      <div style="margin-bottom:12px;">
        <label style="font-size:.78rem;font-weight:600;color:#444;display:block;margin-bottom:5px;">
          Select Section <span style="color:#ef4444;">*</span>
        </label>
        <select id="promoteSectionSelect"
                style="width:100%;height:42px;border:1.5px solid #d0d7e2;border-radius:8px;
                       padding:0 12px;font-size:.88rem;outline:none;font-family:inherit;">
          <option value="">Choose a section…</option>
        </select>
      </div>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="promoteModal">Cancel</button>
      <button class="btn-modal-confirm" id="btnConfirmPromote">
        <i class="fa-solid fa-circle-check"></i> Assign & Promote
      </button>
    </div>
  </div>
</div>

<!-- Cancel Confirm Modal -->
<div class="modal-overlay" id="cancelWlModal">
  <div class="modal modal-sm">
    <div class="modal-body" style="padding:28px 24px 16px;">
      <h3 style="font-size:1.05rem;font-weight:700;margin-bottom:8px;">Cancel Queue Entry?</h3>
      <p style="font-size:.85rem;color:#555;">
        Remove <strong id="cancelStudentName" style="color:#2563eb;"></strong> from the waiting list?
        This will not delete their enrollment record.
      </p>
    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="cancelWlModal">Keep</button>
      <button class="btn-modal-confirm" id="btnConfirmCancelWl" style="background:#ef4444;">
        <i class="fa-solid fa-xmark"></i> Cancel Entry
      </button>
    </div>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
(function() {
var API       = '../shared/enrollment_actions.php';
var allSections = <?= json_encode($sections) ?>;

// ── Promote button ────────────────────────────────────────────
document.querySelectorAll('.btn-promote').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('promoteWlId').value       = btn.dataset.wlId;
        document.getElementById('promoteStudentName').textContent = btn.dataset.name;

        // Filter sections by course + year level
        var sel = document.getElementById('promoteSectionSelect');
        sel.innerHTML = '<option value="">Choose a section…</option>';
        allSections.forEach(function(s) {
            if (s.course !== btn.dataset.course) return;
            var actual = parseInt(s.actual_count, 10);
            var cap    = parseInt(s.max_capacity, 10);
            var full   = actual >= cap;
            var opt    = document.createElement('option');
            opt.value    = s.section_code;
            opt.disabled = full;
            opt.textContent = s.section_code + ' (' + actual + '/' + cap + ')' + (full ? ' — FULL' : '');
            sel.appendChild(opt);
        });
        openModal('promoteModal');
    });
});

document.getElementById('btnConfirmPromote').addEventListener('click', function() {
    var wlId    = document.getElementById('promoteWlId').value;
    var section = document.getElementById('promoteSectionSelect').value;
    if (!section) {
        showAlertModal('Please select a section.', 'warning');
        return;
    }
    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Assigning…';

    var fd = new FormData();
    fd.set('action',       'promote_waiting');
    fd.set('waiting_id',   wlId);
    fd.set('section_code', section);

    fetch(API, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            closeModal('promoteModal');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Assign & Promote';
            if (d.success) {
                var row = document.getElementById('wl-row-' + wlId);
                if (row) { row.style.opacity = '0'; setTimeout(function(){ row.remove(); }, 300); }
                showToast(d.message, 'success');
            } else {
                showAlertModal(d.message, 'error');
            }
        })
        .catch(function() {
            closeModal('promoteModal');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Assign & Promote';
            showAlertModal('Request failed.', 'error');
        });
});

// ── Cancel button ─────────────────────────────────────────────
var pendingCancelId = null;
document.querySelectorAll('.btn-cancel-wl').forEach(function(btn) {
    btn.addEventListener('click', function() {
        pendingCancelId = btn.dataset.wlId;
        document.getElementById('cancelStudentName').textContent = btn.dataset.name;
        openModal('cancelWlModal');
    });
});

document.getElementById('btnConfirmCancelWl').addEventListener('click', function() {
    if (!pendingCancelId) return;
    var btn = this;
    btn.disabled = true;

    var fd = new FormData();
    fd.set('action',     'cancel_waiting');
    fd.set('waiting_id', pendingCancelId);

    fetch(API, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            closeModal('cancelWlModal');
            btn.disabled = false;
            if (d.success) {
                var row = document.getElementById('wl-row-' + pendingCancelId);
                if (row) { row.style.opacity = '0'; setTimeout(function(){ row.remove(); }, 300); }
                showToast('Entry cancelled.', 'warning');
            } else {
                showAlertModal(d.message, 'error');
            }
            pendingCancelId = null;
        })
        .catch(function() {
            closeModal('cancelWlModal');
            btn.disabled = false;
            pendingCancelId = null;
            showAlertModal('Request failed.', 'error');
        });
});
}());
</script>
</body>
</html>
<?php $conn->close(); ?>
