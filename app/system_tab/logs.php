<?php
session_start();
require_once __DIR__ . '/../shared/db.php';

if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if (!is_admin_or_staff())          { header('Location: ../auth/signin.php'); exit; }
$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$is_admin = ($_SESSION['role'] ?? '') === 'admin';

// ── Weekly ZIP archive ────────────────────────────────────────
// Check if it's Sunday and archive hasn't run this week yet.
// Stores logs older than 7 days as gzip JSON in /logs_archive/
$archive_dir = __DIR__ . '/../../logs_archive/';
if (!is_dir($archive_dir)) @mkdir($archive_dir, 0755, true);

if (date('N') == 7) { // Sunday
    $week_file = $archive_dir . 'logs_week_' . date('Y-W') . '.json.gz';
    if (!file_exists($week_file)) {
        $cutoff = date('Y-m-d H:i:s', strtotime('-7 days'));
        $ar = $conn->query("SELECT * FROM activity_logs WHERE created_at < '$cutoff' ORDER BY created_at ASC");
        if ($ar && $ar->num_rows > 0) {
            $rows = [];
            while ($row = $ar->fetch_assoc()) $rows[] = $row;
            $gz = gzencode(json_encode($rows), 6);
            file_put_contents($week_file, $gz);
            // Optionally delete archived rows to keep DB lean (keep last 30 days)
            $conn->query("DELETE FROM activity_logs WHERE created_at < '$cutoff'");
        }
    }
}

// ── Filters ───────────────────────────────────────────────────
$search      = trim($_GET['q']      ?? '');
$filter_action = trim($_GET['action'] ?? '');
$filter_role   = trim($_GET['role']   ?? '');
$filter_date   = trim($_GET['date']   ?? '');
$rows_per_page = 30;
$page          = max(1, (int)($_GET['page'] ?? 1));

$where_parts = ['1=1'];
if ($search) {
    $e = $conn->real_escape_string($search);
    $where_parts[] = "(actor_name LIKE '%$e%' OR target_name LIKE '%$e%' OR details LIKE '%$e%' OR action LIKE '%$e%')";
}
if ($filter_action) {
    $e = $conn->real_escape_string($filter_action);
    $where_parts[] = "action = '$e'";
}
if ($filter_role) {
    $e = $conn->real_escape_string($filter_role);
    $where_parts[] = "actor_role = '$e'";
}
if ($filter_date) {
    $e = $conn->real_escape_string($filter_date);
    $where_parts[] = "DATE(created_at) = '$e'";
}
$where = implode(' AND ', $where_parts);

// Ensure activity_logs table exists (it may have been lost during DB rebuild)
@$conn->query("CREATE TABLE IF NOT EXISTS activity_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id    INT UNSIGNED NOT NULL,
    actor_name  VARCHAR(150) NOT NULL,
    actor_role  VARCHAR(30)  NOT NULL DEFAULT 'admin',
    action      VARCHAR(100) NOT NULL,
    target_type VARCHAR(60)  DEFAULT NULL,
    target_id   INT UNSIGNED DEFAULT NULL,
    target_name VARCHAR(200) DEFAULT NULL,
    details     TEXT         DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_actor_id   (actor_id),
    INDEX idx_action     (action)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$count_res = $conn->query("SELECT COUNT(*) c FROM activity_logs WHERE $where");
$total = $count_res ? (int)$count_res->fetch_assoc()['c'] : 0;
$total_pages = max(1, (int)ceil($total / $rows_per_page));
$page = min($page, $total_pages);

$logs = [];
$res = $conn->query(
    "SELECT * FROM activity_logs WHERE $where
     ORDER BY created_at DESC
     LIMIT $rows_per_page OFFSET " . (($page - 1) * $rows_per_page)
);
if ($res) while ($r = $res->fetch_assoc()) $logs[] = $r;

// Distinct actions for filter dropdown
$distinct_actions = [];
$ra = $conn->query("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC");
if ($ra) while ($r = $ra->fetch_assoc()) $distinct_actions[] = $r['action'];

// Group logs by day
$grouped = [];
foreach ($logs as $log) {
    $day = date('Y-m-d', strtotime($log['created_at']));
    $grouped[$day][] = $log;
}

// Archive files list (for download)
$archive_files = glob($archive_dir . 'logs_week_*.json.gz') ?: [];
rsort($archive_files);

// Action icon + colour map
function action_meta(string $action): array {
    return match(true) {
        str_contains($action,'validate')     => ['fa-clipboard-check','#16a34a','#dcfce7'],
        str_contains($action,'approve')      => ['fa-circle-check',   '#16a34a','#dcfce7'],
        str_contains($action,'reject')       => ['fa-circle-xmark',   '#dc2626','#fee2e2'],
        str_contains($action,'grade')        => ['fa-layer-group',    '#7c3aed','#faf5ff'],
        str_contains($action,'id_generat')   => ['fa-id-badge',       '#2563eb','#eff6ff'],
        str_contains($action,'section')      => ['fa-chalkboard',     '#0891b2','#ecfeff'],
        str_contains($action,'flag')         => ['fa-flag',           '#dc2626','#fee2e2'],
        str_contains($action,'clear')        => ['fa-check-circle',   '#16a34a','#dcfce7'],
        str_contains($action,'staff_creat')  => ['fa-user-plus',      '#7c3aed','#faf5ff'],
        str_contains($action,'login')        => ['fa-right-to-bracket','#2563eb','#eff6ff'],
        str_contains($action,'logout')       => ['fa-right-from-bracket','#6b7280','#f3f4f6'],
        str_contains($action,'password')     => ['fa-key',            '#d97706','#fff7ed'],
        str_contains($action,'document')     => ['fa-file-shield',    '#0891b2','#ecfeff'],
        default                              => ['fa-bolt',           '#6b7280','#f3f4f6'],
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Activity Logs – BCP</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <style>
    .log-day-header {
      font-size:.78rem;font-weight:700;color:#6b7280;text-transform:uppercase;
      letter-spacing:.08em;padding:10px 0 6px;margin-top:16px;
      border-bottom:1.5px solid #f0f2f5;margin-bottom:0;
    }
    .log-day-header:first-child { margin-top:0; }
    .log-entry {
      display:flex;align-items:flex-start;gap:12px;
      padding:12px 0;border-bottom:1px solid #f9fafb;
    }
    .log-entry:last-child { border-bottom:none; }
    .log-icon {
      width:34px;height:34px;border-radius:50%;
      display:flex;align-items:center;justify-content:center;
      font-size:.8rem;flex-shrink:0;margin-top:1px;
    }
    .log-body { flex:1;min-width:0; }
    .log-action { font-size:.82rem;font-weight:700;color:#1a1a2e; }
    .log-details { font-size:.78rem;color:#555;margin-top:2px;line-height:1.5; }
    .log-actor {
      display:inline-flex;align-items:center;gap:4px;
      font-size:.7rem;color:#6b7280;margin-top:4px;
    }
    .log-actor strong { color:#1e40af; }
    .log-time { font-size:.7rem;color:#aaa;white-space:nowrap;flex-shrink:0;margin-top:3px; }
    .log-target {
      display:inline-block;background:#eff6ff;color:#2563eb;
      border-radius:4px;padding:1px 6px;font-size:.7rem;font-weight:600;margin-left:4px;
    }
    .archive-pill {
      display:inline-flex;align-items:center;gap:6px;
      background:#f3f4f6;border:1.5px solid #d0d7e2;border-radius:8px;
      padding:5px 12px;font-size:.75rem;font-weight:600;color:#444;
      text-decoration:none;transition:background .15s;
    }
    .archive-pill:hover { background:#e5e7eb; }
  </style>
</head>
<body>
<?php
$APP_ROOT   = '../';
$ACTIVE_NAV = 'logs';
require_once __DIR__ . '/../admin_dashboard/sidebar.php';
?>

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
      <h2 class="page-title"><i class="fa-solid fa-scroll"></i> Activity Logs</h2>
    </div>

    <!-- Search + filters -->
    <form method="GET" style="margin:0 24px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <div class="search-wrap" style="flex:1;min-width:200px;max-width:320px;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
               placeholder="Search action, name, details…"/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <select name="action" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;">
        <option value="">All Actions</option>
        <?php foreach ($distinct_actions as $a): ?>
        <option value="<?= htmlspecialchars($a) ?>" <?= $filter_action===$a?'selected':'' ?>>
          <?= htmlspecialchars(ucwords(str_replace('_',' ',$a))) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <select name="role" style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;">
        <option value="">All Roles</option>
        <option value="admin" <?= $filter_role==='admin'?'selected':'' ?>>Admin</option>
        <option value="staff" <?= $filter_role==='staff'?'selected':'' ?>>Staff</option>
      </select>
      <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>"
             style="height:36px;border:1px solid #ddd;border-radius:8px;padding:0 10px;font-size:.82rem;background:#fff;"/>
      <button type="submit" class="btn-add" style="padding:7px 14px;font-size:.8rem;">
        <i class="fa-solid fa-filter"></i> Filter
      </button>
      <?php if ($search||$filter_action||$filter_role||$filter_date): ?>
      <a href="logs.php" class="btn-secondary" style="padding:7px 12px;font-size:.8rem;text-decoration:none;">Clear</a>
      <?php endif; ?>
      <span style="font-size:.78rem;color:#aaa;margin-left:auto;">
        <?= $total ?> entr<?= $total!==1?'ies':'y' ?><?= ($search||$filter_action||$filter_role||$filter_date)?' (filtered)':'' ?>
      </span>
    </form>

    <!-- Archive downloads (admin only) -->
    <?php if ($is_admin && $archive_files): ?>
    <div style="margin:0 24px 16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
      <span style="font-size:.75rem;font-weight:600;color:#6b7280;">
        <i class="fa-solid fa-box-archive"></i> Weekly Archives:
      </span>
      <?php foreach (array_slice($archive_files, 0, 6) as $af):
        $aname = basename($af);
        $adate = preg_replace('/logs_week_(\d+)-(\d+)\.json\.gz/', 'Week $2, $1', $aname);
        $asize = round(filesize($af) / 1024, 1) . ' KB';
      ?>
      <a href="download_log.php?file=<?= urlencode($aname) ?>" class="archive-pill">
        <i class="fa-solid fa-file-zipper"></i>
        <?= $adate ?> <span style="color:#aaa;font-weight:400;">(<?= $asize ?>)</span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Log entries grouped by day -->
    <div class="crud-card" style="padding:0 24px 8px;">
      <?php if (empty($logs)): ?>
      <div style="padding:40px;text-align:center;color:#aaa;font-size:.88rem;">
        <i class="fa-solid fa-scroll" style="font-size:2rem;display:block;margin-bottom:10px;"></i>
        No log entries found<?= ($search||$filter_action||$filter_role||$filter_date)?' matching the current filters.':'.'; ?>
      </div>
      <?php else: ?>

      <?php foreach ($grouped as $day => $day_logs):
        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        if ($day === $today)          $day_label = 'Today — ' . date('F j, Y', strtotime($day));
        elseif ($day === $yesterday)  $day_label = 'Yesterday — ' . date('F j, Y', strtotime($day));
        else                          $day_label = date('l, F j, Y', strtotime($day));
      ?>
      <div class="log-day-header"><?= $day_label ?></div>

      <?php foreach ($day_logs as $log):
        [$icon, $color, $bg] = action_meta($log['action']);
        $action_label = ucwords(str_replace('_',' ',$log['action']));
        $time_str     = date('g:i A', strtotime($log['created_at']));
        $role_badge   = $log['actor_role'] === 'admin'
            ? '<span style="background:#dbeafe;color:#1d4ed8;font-size:.62rem;border-radius:4px;padding:1px 5px;font-weight:700;margin-left:4px;">Admin</span>'
            : '<span style="background:#faf5ff;color:#7c3aed;font-size:.62rem;border-radius:4px;padding:1px 5px;font-weight:700;margin-left:4px;">Staff</span>';
      ?>
      <div class="log-entry">
        <div class="log-icon" style="background:<?= $bg ?>;color:<?= $color ?>;">
          <i class="fa-solid <?= $icon ?>"></i>
        </div>
        <div class="log-body">
          <div class="log-action">
            <?= htmlspecialchars($action_label) ?>
            <?php if ($log['target_name']): ?>
            <span class="log-target"><?= htmlspecialchars($log['target_name']) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($log['details']): ?>
          <div class="log-details"><?= htmlspecialchars($log['details']) ?></div>
          <?php endif; ?>
          <div class="log-actor">
            <i class="fa-solid fa-user" style="font-size:.65rem;"></i>
            <strong><?= htmlspecialchars($log['actor_name']) ?></strong>
            <?= $role_badge ?>
            <?php if ($log['ip_address']): ?>
            <span style="margin-left:6px;color:#d1d5db;">·</span>
            <span style="color:#aaa;"><?= htmlspecialchars($log['ip_address']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="log-time"><?= $time_str ?></div>
      </div>
      <?php endforeach; ?>
      <?php endforeach; ?>

      <?php if ($total_pages > 1): ?>
      <div class="crud-pagination" style="margin-top:16px;">
        <?php
        $qs = http_build_query(['q'=>$search,'action'=>$filter_action,'role'=>$filter_role,'date'=>$filter_date]);
        if ($page > 1) echo "<a href='?$qs&page=".($page-1)."' class='pg-btn pg-label'>&laquo; Prev</a>";
        for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++)
            echo "<a href='?$qs&page=$p' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
        if ($page < $total_pages) echo "<a href='?$qs&page=".($page+1)."' class='pg-btn pg-label'>Next &raquo;</a>";
        ?>
      </div>
      <?php endif; ?>

      <?php endif; ?>
    </div>

  </div>
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
</body>
</html>
<?php $conn->close(); ?>
