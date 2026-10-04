<?php
// ============================================================
//  GENERATED_DOCUMENTS.PHP  (admin/)
//  Shows all admission summary documents that have been
//  generated, with a search bar by student name.
//  Each row has a Download button.
// ============================================================
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']))   { header('Location: ../auth/signin.php'); exit; }
if (!is_admin_or_staff()) { header('Location: ../auth/signin.php'); exit; }

$sess_initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));

// Ensure table exists
@$conn->query("CREATE TABLE IF NOT EXISTS generated_documents (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pre_reg_id   INT UNSIGNED NOT NULL,
    file_name    VARCHAR(300) NOT NULL,
    file_path    VARCHAR(500) NOT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    generated_by VARCHAR(150) DEFAULT NULL,
    INDEX idx_pre_reg (pre_reg_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
@$conn->query("ALTER TABLE generated_documents ADD UNIQUE INDEX IF NOT EXISTS uq_pre_reg (pre_reg_id)");

// ── Handle delete ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $del_id = (int)($_POST['doc_id'] ?? 0);
    if ($del_id) {
        $dr = $conn->prepare("SELECT file_path FROM generated_documents WHERE id=? LIMIT 1");
        $dr->bind_param('i', $del_id);
        $dr->execute();
        $drow = $dr->get_result()->fetch_assoc();
        $dr->close();
        if ($drow) {
            $abs = realpath(__DIR__ . '/../') . '/' . ltrim($drow['file_path'], '/\\');
            if (file_exists($abs)) @unlink($abs);
            $conn->query("DELETE FROM generated_documents WHERE id=$del_id");
        }
    }
    header('Location: generated_documents.php');
    exit;
}

// ── Search ────────────────────────────────────────────────────
$search = trim($_GET['q']      ?? '');
$filter_course = trim($_GET['course'] ?? '');

$rows_per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$where_parts = ['1=1'];
if ($search !== '') {
    $esc    = $conn->real_escape_string($search);
    $where_parts[] = "(p.first_name LIKE '%$esc%' OR p.last_name LIKE '%$esc%'
                      OR p.ref_number LIKE '%$esc%' OR g.file_name LIKE '%$esc%')";
}
if ($filter_course) {
    $esc = $conn->real_escape_string($filter_course);
    $where_parts[] = "p.course LIKE '%$esc%'";
}
$where = 'WHERE ' . implode(' AND ', $where_parts);

$count_res   = $conn->query("SELECT COUNT(*) c FROM generated_documents g LEFT JOIN pre_registrations p ON g.pre_reg_id = p.id $where");
$total_rows  = $count_res ? (int)$count_res->fetch_assoc()['c'] : 0;
$total_pages = max(1, (int)ceil($total_rows / $rows_per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $rows_per_page;

$rows = [];
$res  = $conn->query(
    "SELECT g.id, g.pre_reg_id, g.file_name, g.file_path,
            g.generated_at, g.generated_by,
            COALESCE(p.first_name,'Unknown')   AS first_name,
            COALESCE(p.last_name,'Applicant')  AS last_name,
            COALESCE(p.ref_number,'—')         AS ref_number,
            COALESCE(p.course,'—')             AS course,
            COALESCE(p.email,'—')              AS email
     FROM generated_documents g
     LEFT JOIN pre_registrations p ON g.pre_reg_id = p.id
     $where
     ORDER BY g.generated_at DESC
     LIMIT $rows_per_page OFFSET $offset"
);
if ($res) while ($r = $res->fetch_assoc()) $rows[] = $r;

$conn->close();

$APP_ROOT   = '../';
$ACTIVE_NAV = 'gen_docs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Generated Documents — Admin</title>
  <link rel="stylesheet" href="../css/dashboard.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php require_once __DIR__ . '/../admin_dashboard/sidebar.php'; ?>

<div class="main">
  <div class="topbar">
    <button class="hamburger" id="hamburgerBtn"><i class="fa-solid fa-bars"></i></button>
    <span class="topbar-spacer"></span>
    <div class="topbar-right">
      <div class="search-wrap">
        <input type="text" placeholder="Search..."/>
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <a href="../admin_dashboard/account.php" class="avatar" title="Account"><?= $sess_initial ?></a>
    </div>
  </div>

  <div class="content">
    <div class="page-title-bar">
      <h2 class="page-title">
        <i class="fa-solid fa-file-word"></i> Generated Documents
      </h2>
    </div>

    <!-- ── Info ── -->
    <div style="margin:0 24px 18px;background:#eff6ff;border:1.5px solid #bfdbfe;
                border-radius:10px;padding:12px 16px;font-size:.82rem;color:#1e40af;
                display:flex;align-items:center;gap:10px;">
      <i class="fa-solid fa-circle-info" style="flex-shrink:0;"></i>
      Admission summary documents generated from student form data.
      Re-generate any document from the <a href="document_review.php" style="color:#2563eb;font-weight:700;">AI Document Review</a> page.
    </div>

    <!-- ── Search bar ── -->
    <form method="GET" style="margin:0 24px 18px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <div style="position:relative;flex:1;min-width:240px;max-width:440px;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
               placeholder="Search by student name or reference number…"
               style="width:100%;height:42px;border:1.5px solid #d0d7e2;border-radius:8px;
                      padding:0 14px 0 38px;font-size:.88rem;outline:none;font-family:inherit;box-sizing:border-box;"/>
        <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#aaa;"></i>
      </div>
      <select name="course" style="height:42px;border:1.5px solid #d0d7e2;border-radius:8px;padding:0 12px;font-size:.82rem;background:#fff;font-family:inherit;">
        <option value="">All Courses</option>
        <?php foreach (['Information Technology','Computer Engineering','Library Information Science',
                         'Psychology','Elementary Education','Technology and Livelihood Education',
                         'Secondary Education','Physical Education','Criminology',
                         'Accounting Information System','Entrepreneurship',
                         'Marketing Management','Human Resource Management','Financial Management',
                         'Office Administration','Tourism Management','Hospitality Management'] as $c): ?>
        <option value="<?= $c ?>" <?= str_contains($filter_course,$c)?'selected':'' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" style="height:42px;padding:0 20px;background:#1a3a8c;color:#fff;border:none;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
        <i class="fa-solid fa-magnifying-glass"></i> Search
      </button>
      <?php if ($search !== '' || $filter_course): ?>
      <a href="generated_documents.php" style="height:42px;padding:0 16px;background:#f3f4f6;color:#555;border:1.5px solid #e5e7eb;border-radius:8px;font-size:.88rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
        <i class="fa-solid fa-xmark"></i> Clear
      </a>
      <?php endif; ?>
      <span style="font-size:.75rem;color:#888;margin-left:4px;">
        <?= $total_rows ?> document<?= $total_rows !== 1 ? 's' : '' ?>
      </span>
    </form>

    <!-- ── Documents table ── -->
    <div class="crud-card" style="margin:0 24px 24px;">
      <?php if (empty($rows)): ?>
      <div style="text-align:center;padding:48px;color:#aaa;">
        <i class="fa-solid fa-file-word" style="font-size:2.2rem;display:block;margin-bottom:14px;opacity:.3;color:#2563eb;"></i>
        <?= $search
            ? 'No documents match "' . htmlspecialchars($search) . '".'
            : 'No documents have been generated yet. Use the Generate Document button in AI Document Review.' ?>
      </div>
      <?php else: ?>
      <table class="crud-table">
        <thead>
          <tr>
            <th>Student</th>
            <th>Reference No.</th>
            <th>Course</th>
            <th>Document</th>
            <th>Generated</th>
            <th>Generated By</th>
            <th style="text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
            $full_name = htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name']));
            // Build the download URL — serve through a simple proxy
            $dl_url = 'download_doc.php?id=' . $row['id'];
          ?>
          <tr>
            <!-- Student -->
            <td>
              <div style="font-weight:600;color:#1a1a2e;"><?= $full_name ?></div>
              <div style="font-size:.72rem;color:#888;"><?= htmlspecialchars($row['email']) ?></div>
            </td>

            <!-- Ref No. -->
            <td style="font-size:.78rem;color:#2563eb;font-weight:600;">
              <?= htmlspecialchars($row['ref_number']) ?>
            </td>

            <!-- Course -->
            <td style="font-size:.78rem;color:#555;">
              <?= htmlspecialchars($row['course']) ?>
            </td>

            <!-- Document name -->
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-file-word" style="color:#2563eb;font-size:1.1rem;flex-shrink:0;"></i>
                <div>
                  <div style="font-size:.80rem;font-weight:600;color:#1a1a2e;word-break:break-word;max-width:220px;">
                    <?= htmlspecialchars($row['file_name']) ?>
                  </div>
                </div>
              </div>
            </td>

            <!-- Generated at -->
            <td style="font-size:.78rem;color:#555;white-space:nowrap;">
              <?= date('M d, Y', strtotime($row['generated_at'])) ?><br>
              <span style="color:#aaa;"><?= date('g:i A', strtotime($row['generated_at'])) ?></span>
            </td>

            <!-- Generated by -->
            <td style="font-size:.78rem;color:#555;">
              <?= htmlspecialchars($row['generated_by'] ?? '—') ?>
            </td>

            <!-- Actions -->
            <td style="text-align:center;">
              <div style="display:inline-flex;gap:8px;align-items:center;justify-content:center;">
                <!-- Download -->
                <a href="<?= $dl_url ?>"
                   style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:7px;
                          padding:6px 12px;color:#2563eb;font-size:.75rem;font-weight:600;
                          text-decoration:none;display:inline-flex;align-items:center;gap:5px;
                          transition:background .15s;"
                   onmouseover="this.style.background='#dbeafe'"
                   onmouseout="this.style.background='#eff6ff'">
                  <i class="fa-solid fa-download"></i> Download
                </a>
                <!-- Re-generate -->
                <a href="document_review.php"
                   title="Go to Document Review to re-generate"
                   style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:7px;
                          padding:6px 12px;color:#555;font-size:.75rem;font-weight:600;
                          text-decoration:none;display:inline-flex;align-items:center;gap:5px;">
                  <i class="fa-solid fa-rotate-right"></i> Re-gen
                </a>
                <!-- Delete record -->
                <form method="POST" class="del-doc-form"
                      data-name="<?= $full_name ?>" style="display:inline;">
                  <input type="hidden" name="action" value="delete"/>
                  <input type="hidden" name="doc_id" value="<?= $row['id'] ?>"/>
                  <button type="submit"
                          style="background:#fee2e2;color:#dc2626;border:1.5px solid #fca5a5;
                                 border-radius:7px;padding:6px 10px;font-size:.75rem;font-weight:600;
                                 cursor:pointer;display:inline-flex;align-items:center;gap:5px;">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <div class="crud-pagination" style="margin-top:14px;">
        <?php
        $qs = http_build_query(['q'=>$search,'course'=>$filter_course]);
        if ($page > 1) echo "<a href='?$qs&page=".($page-1)."' class='pg-btn pg-label'>&laquo; Prev</a>";
        for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++) {
            echo "<a href='?$qs&page=$p' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
        }
        if ($page < $total_pages) echo "<a href='?$qs&page=".($page+1)."' class='pg-btn pg-label'>Next &raquo;</a>";
        ?>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- end content -->
  <div class="footer">eLearning Commons &copy; 2026</div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../js/dashboard.js"></script>
<script>
document.querySelectorAll('.del-doc-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        var name = form.dataset.name || 'this document';
        if (!confirm('Delete the generated document for ' + name + '?\nThe file will also be removed from the server.')) {
            e.preventDefault();
        }
    });
});
</script>
</body>
</html>
