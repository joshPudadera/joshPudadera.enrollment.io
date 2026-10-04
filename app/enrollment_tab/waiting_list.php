<?php
session_start();
require_once __DIR__ . '/../shared/db.php';
require_enrollment_tables($conn);
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if (!is_admin_or_staff()) { header('Location: ../auth/signin.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));

// ── Filters & pagination ────────────────────────────────────────
$search        = trim($_GET['q']      ?? '');
$filter_course = trim($_GET['course'] ?? '');
$filter_yr     = trim($_GET['year']   ?? '');
$rows_per_page = 10;
$page_a        = max(1, (int)($_GET['page_a'] ?? 1)); // active queue page
$page_h        = max(1, (int)($_GET['page_h'] ?? 1)); // history page

$base_where = "1=1";
if ($search) {
    $esc = $conn->real_escape_string($search);
    $base_where .= " AND (p.first_name LIKE '%$esc%' OR p.last_name LIKE '%$esc%' OR e.id_number LIKE '%$esc%')";
}
if ($filter_course) {
    $esc = $conn->real_escape_string($filter_course);
    $base_where .= " AND w.course LIKE '%$esc%'";
}
if ($filter_yr) {
    $esc = $conn->real_escape_string($filter_yr);
    $base_where .= " AND w.year_level = '$esc'";
}

$active_count = (int)$conn->query(
    "SELECT COUNT(*) c FROM waiting_list w
     JOIN pre_registrations p ON w.pre_reg_id = p.id
     LEFT JOIN enrollments e ON e.pre_reg_id = w.pre_reg_id
     WHERE w.status='Waiting' AND $base_where"
)->fetch_assoc()['c'];

$history_count = (int)$conn->query(
    "SELECT COUNT(*) c FROM waiting_list w
     JOIN pre_registrations p ON w.pre_reg_id = p.id
     LEFT JOIN enrollments e ON e.pre_reg_id = w.pre_reg_id
     WHERE w.status<>'Waiting' AND $base_where"
)->fetch_assoc()['c'];

$active_pages  = max(1, (int)ceil($active_count  / $rows_per_page));
$history_pages = max(1, (int)ceil($history_count / $rows_per_page));
$page_a = min($page_a, $active_pages);
$page_h = min($page_h, $history_pages);

$active_sql = "SELECT w.id AS wl_id, w.queue_position, w.reason, w.status AS wl_status, w.queued_at,
                       w.pre_reg_id, w.course, w.year_level,
                       p.first_name, p.last_name, p.ref_number,
                       e.id AS enrollment_id, e.id_number, e.section, e.grade_confirmed
                FROM waiting_list w
                JOIN pre_registrations p ON w.pre_reg_id = p.id
                LEFT JOIN enrollments e ON e.pre_reg_id = w.pre_reg_id
                WHERE w.status='Waiting' AND $base_where
                ORDER BY w.queue_position ASC
                LIMIT $rows_per_page OFFSET " . (($page_a - 1) * $rows_per_page);

$history_sql = "SELECT w.id AS wl_id, w.queue_position, w.reason, w.status AS wl_status, w.queued_at,
                       w.pre_reg_id, w.course, w.year_level,
                       p.first_name, p.last_name, p.ref_number,
                       e.id AS enrollment_id, e.id_number, e.section, e.grade_confirmed
                FROM waiting_list w
                JOIN pre_registrations p ON w.pre_reg_id = p.id
                LEFT JOIN enrollments e ON e.pre_reg_id = w.pre_reg_id
                WHERE w.status<>'Waiting' AND $base_where
                ORDER BY w.queued_at DESC
                LIMIT $rows_per_page OFFSET " . (($page_h - 1) * $rows_per_page);

$active  = [];
$history = [];
$res = $conn->query($active_sql);
if ($res) while ($r = $res->fetch_assoc()) $active[] = $r;
$res2 = $conn->query($history_sql);
if ($res2) while ($r = $res2->fetch_assoc()) $history[] = $r;

// Total waiting (unfiltered) for stat cards
$waiting_total   = (int)$conn->query("SELECT COUNT(*) c FROM waiting_list WHERE status='Waiting'")->fetch_assoc()['c'];
$promoted_total  = (int)$conn->query("SELECT COUNT(*) c FROM waiting_list WHERE status='Promoted'")->fetch_assoc()['c'];

// Sections for the promote dropdown
$sections  = [];
$rs = $conn->query("SELECT section_code, course, year_level, max_capacity,
                    (SELECT COUNT(*) FROM enrollments WHERE section=s.section_code) AS actual_count
                    FROM sections s WHERE is_active=1 ORDER BY section_code ASC");
if ($rs) while ($r = $rs->fetch_assoc()) $sections[] = $r;
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

    <!-- Filter bar -->
    <form method="GET" style="margin:0 24px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <div class="search-wrap" style="flex:1;min-width:200px;max-width:300px;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name or ID…"/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <select name="course" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;">
        <option value="">All Courses</option>
        <?php foreach(['Information Technology','Computer Engineering','Library Information Science',
                      'Psychology','Elementary Education','Technology and Livelihood Education',
                      'Secondary Education','Physical Education','Criminology',
                      'Accounting Information System','Entrepreneurship',
                      'Marketing Management','Human Resource Management','Financial Management',
                      'Office Administration','Tourism Management','Hospitality Management'] as $c): ?>
        <option value="<?= $c ?>" <?= str_contains($filter_course,$c)?'selected':'' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
      <select name="year" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;">
        <option value="">All Years</option>
        <?php foreach(['1st Year','2nd Year','3rd Year','4th Year'] as $y): ?>
        <option value="<?= $y ?>" <?= $filter_yr===$y?'selected':'' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn-add" style="padding:7px 14px;font-size:.8rem;"><i class="fa-solid fa-filter"></i> Filter</button>
      <?php if($search||$filter_course||$filter_yr): ?>
      <a href="waiting_list.php" class="btn-secondary" style="padding:7px 12px;font-size:.8rem;text-decoration:none;">Clear</a>
      <?php endif; ?>
    </form>

    <!-- Active queue -->
    <div class="crud-card">
      <div class="crud-header">
        <h3>Active Queue
          <span style="font-size:.75rem;font-weight:400;color:#888;margin-left:6px;">
            <?= $active_count ?> student<?= $active_count!==1?'s':'' ?><?= ($search||$filter_course||$filter_yr)?' (filtered)':'' ?>
          </span>
        </h3>
        <?php if ($waiting_total > 0): ?>
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
      <?php if ($active_pages > 1): ?>
      <div class="crud-pagination">
        <?php
        $qs = http_build_query(['q'=>$search,'course'=>$filter_course,'year'=>$filter_yr,'page_h'=>$page_h]);
        if ($page_a > 1) echo "<a href='?$qs&page_a=".($page_a-1)."' class='pg-btn pg-label'>&laquo;</a>";
        for ($p = max(1,$page_a-2); $p <= min($active_pages,$page_a+2); $p++)
            echo "<a href='?$qs&page_a=$p' class='pg-btn".($p===$page_a?' active':'')."'>$p</a>";
        if ($page_a < $active_pages) echo "<a href='?$qs&page_a=".($page_a+1)."' class='pg-btn pg-label'>&raquo;</a>";
        ?>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div style="padding:32px;text-align:center;color:#aaa;font-size:.88rem;">
        <i class="fa-solid fa-circle-check" style="font-size:1.6rem;color:#16a34a;display:block;margin-bottom:10px;"></i>
        <?php if ($search||$filter_course||$filter_yr): ?>
          No active queue entries match the current filters.
        <?php else: ?>
          Waiting list is empty — all students have been assigned to a section.
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- History -->
    <?php if ($history || $history_count > 0): ?>
    <div class="crud-card">
      <div class="crud-header">
        <h3>History — Processed Entries
          <span style="font-size:.75rem;font-weight:400;color:#888;margin-left:6px;">
            <?= $history_count ?><?= ($search||$filter_course||$filter_yr)?' (filtered)':'' ?>
          </span>
        </h3>
      </div>
      <?php if ($history): ?>
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
      <?php if ($history_pages > 1): ?>
      <div class="crud-pagination">
        <?php
        $qs = http_build_query(['q'=>$search,'course'=>$filter_course,'year'=>$filter_yr,'page_a'=>$page_a]);
        if ($page_h > 1) echo "<a href='?$qs&page_h=".($page_h-1)."' class='pg-btn pg-label'>&laquo;</a>";
        for ($p = max(1,$page_h-2); $p <= min($history_pages,$page_h+2); $p++)
            echo "<a href='?$qs&page_h=$p' class='pg-btn".($p===$page_h?' active':'')."'>$p</a>";
        if ($page_h < $history_pages) echo "<a href='?$qs&page_h=".($page_h+1)."' class='pg-btn pg-label'>&raquo;</a>";
        ?>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div style="padding:24px;text-align:center;color:#aaa;font-size:.85rem;">No history entries match the current filters.</div>
      <?php endif; ?>
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
