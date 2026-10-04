<?php
session_start();                          // must be first
require_once __DIR__ . '/../shared/db.php';

if (empty($_SESSION['user_id']))          { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin')        { header('Location: ../dashboard/dashboard.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'users';
$PAGE_TITLE = 'Users & Permissions';
$PAGE_ICON  = 'fa-solid fa-shield-halved';

// Filters & pagination
$search        = trim($_GET['q'] ?? '');
$rows_per_page = 10;
$page          = max(1, (int)($_GET['page'] ?? 1));

$where = "1=1";
if ($search) {
    $esc = $conn->real_escape_string($search);
    $where .= " AND (first_name LIKE '%$esc%' OR last_name LIKE '%$esc%'
                     OR username LIKE '%$esc%' OR email LIKE '%$esc%')";
}

$total_users = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE $where")->fetch_assoc()['c'];
$total_pages = max(1, (int)ceil($total_users / $rows_per_page));
$page = min($page, $total_pages);

$users = [];
$res = $conn->query(
    "SELECT id, username, first_name, last_name, email, role, created_at
     FROM users WHERE $where
     ORDER BY role ASC, last_name ASC
     LIMIT $rows_per_page OFFSET " . (($page - 1) * $rows_per_page)
);
if ($res) while ($r = $res->fetch_assoc()) $users[] = $r;

$roles = [
    'admin'  => ['Full system access', 'Manage students, enrollment, users', 'View all reports', '#2563eb'],
    'staff'  => ['View all admin modules', 'Approve/validate students', 'Cannot add or delete data', '#7c3aed'],
    'student'=> ['View own profile', 'Submit pre-registration', 'Upload documents', '#22c55e'],
];

$role_icons = ['admin' => 'fa-crown', 'staff' => 'fa-user-tie', 'student' => 'fa-user'];

ob_start();
?>

<!-- Role definitions -->
<div class="tables-row">
  <?php foreach ($roles as $role => [$p1,$p2,$p3,$color]): ?>
  <div class="table-card">
    <h3 style="text-transform:capitalize;color:<?= $color ?>;">
      <i class="fa-solid <?= $role_icons[$role] ?? 'fa-user' ?>"></i> <?= ucfirst($role) ?>
    </h3>
    <ul style="margin:12px 0 0 18px;font-size:.82rem;color:#555;line-height:2;">
      <li><?= $p1 ?></li>
      <li><?= $p2 ?></li>
      <li><?= $p3 ?></li>
    </ul>
  </div>
  <?php endforeach; ?>
</div>

<!-- User list -->
<div class="crud-card">
  <div class="crud-header">
    <h3>All Users
      <span style="font-size:.75rem;font-weight:400;color:#888;margin-left:6px;">
        <?= $total_users ?><?= $search?' (filtered)':'' ?>
      </span>
    </h3>
    <a href="../auth/register.php" class="btn-add">
      <i class="fa-solid fa-plus"></i> Add User
    </a>
  </div>

  <!-- Search bar -->
  <form method="GET" style="padding:0 0 14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <div class="search-wrap" style="flex:1;min-width:220px;max-width:360px;">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
             placeholder="Search by name, username or email…"/>
      <i class="fa-solid fa-magnifying-glass"></i>
    </div>
    <button type="submit" class="btn-add" style="padding:7px 14px;font-size:.8rem;">
      <i class="fa-solid fa-magnifying-glass"></i> Search
    </button>
    <?php if ($search): ?>
    <a href="permissions.php" class="btn-secondary" style="padding:7px 12px;font-size:.8rem;text-decoration:none;">Clear</a>
    <?php endif; ?>
  </form>

  <table class="crud-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Username</th>
        <th>Email</th>
        <th>Role</th>
        <th>Joined</th>
        <th style="text-align:center;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($users): foreach ($users as $u):
        $role_color = match($u['role']) {
            'admin'   => 'badge-active',
            'staff'   => '',
            default   => 'badge-inactive',
        };
        $role_style = $u['role'] === 'staff'
            ? 'background:#faf5ff;color:#7c3aed;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;'
            : '';
        $uid      = (int)$u['id'];
        $uname    = htmlspecialchars($u['username']);
        $fullname = htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name']));
        $uemail   = htmlspecialchars($u['email']);
      ?>
      <tr <?= $u['role'] === 'staff' ? 'style="background:#fdfbff;"' : '' ?>>
        <td>
          <div style="font-weight:600;"><?= $fullname ?></div>
          <?php if ($u['role'] === 'staff'): ?>
          <div style="font-size:.70rem;color:#7c3aed;margin-top:2px;">
            <i class="fa-solid fa-user-tie"></i> Staff Account
          </div>
          <?php endif; ?>
        </td>
        <td style="color:#2563eb;font-weight:600;"><?= $uname ?></td>
        <td style="font-size:.78rem;">
          <?= $uemail ?>
          <?php if ($u['role'] === 'staff'): ?>
          <div style="font-size:.68rem;color:#7c3aed;margin-top:2px;">
            <i class="fa-solid fa-key"></i> Use Reset Password to change
          </div>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($role_style): ?>
          <span style="<?= $role_style ?>"><?= ucfirst($u['role']) ?></span>
          <?php else: ?>
          <span class="<?= $role_color ?>"><?= ucfirst($u['role']) ?></span>
          <?php endif; ?>
        </td>
        <td style="font-size:.75rem;color:#888;"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
        <td style="text-align:center;">
          <button class="btn-reset-pw"
                  data-uid="<?= $uid ?>"
                  data-name="<?= $fullname ?>"
                  data-email="<?= $uemail ?>"
                  title="Generate password reset link"
                  style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:7px;
                         padding:6px 12px;color:#2563eb;font-size:.78rem;font-weight:600;
                         cursor:pointer;display:inline-flex;align-items:center;gap:5px;">
            <i class="fa-solid fa-key"></i> Reset Password
          </button>
        </td>
      </tr>
      <?php endforeach; else: ?>
      <tr><td colspan="6" style="text-align:center;padding:24px;color:#aaa;">No users found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  <?php if ($total_pages > 1): ?>
  <div class="crud-pagination">
    <?php
    $qs = http_build_query(['q' => $search]);
    if ($page > 1) echo "<a href='?$qs&page=".($page-1)."' class='pg-btn pg-label'>&laquo;</a>";
    for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++)
        echo "<a href='?$qs&page=$p' class='pg-btn".($p===$page?' active':'')."'>$p</a>";
    if ($page < $total_pages) echo "<a href='?$qs&page=".($page+1)."' class='pg-btn pg-label'>&raquo;</a>";
    ?>
  </div>
  <?php endif; ?>
</div>

<!-- ── Reset Password Link Modal ── -->
<div class="modal-overlay" id="resetLinkModal">
  <div class="modal modal-lg">
    <div class="modal-header">
      <span><i class="fa-solid fa-key" style="margin-right:6px;"></i> Password Reset Link</span>
      <button class="modal-close" data-close="resetLinkModal">&times;</button>
    </div>
    <div class="modal-body" style="padding:24px 22px;">

      <!-- Loading -->
      <div id="resetLinkLoading" style="text-align:center;padding:24px 0;">
        <i class="fa-solid fa-spinner fa-spin" style="font-size:1.8rem;color:#2563eb;"></i>
        <p style="margin-top:12px;font-size:.85rem;color:#888;">Generating reset link…</p>
      </div>

      <!-- Success -->
      <div id="resetLinkResult" style="display:none;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;
                    background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px 14px;">
          <i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:1.2rem;flex-shrink:0;"></i>
          <div>
            <div style="font-size:.82rem;font-weight:700;color:#16a34a;">Link generated successfully</div>
            <div style="font-size:.75rem;color:#555;margin-top:2px;">
              For: <strong id="resetForName"></strong>
              &nbsp;·&nbsp; <span id="resetForEmail" style="color:#2563eb;"></span>
            </div>
          </div>
        </div>

        <div style="font-size:.78rem;font-weight:700;color:#1a1a2e;margin-bottom:6px;">
          <i class="fa-solid fa-link" style="color:#2563eb;"></i>
          One-time reset link <span style="font-weight:400;color:#888;">(expires in 24 hours)</span>:
        </div>

        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
          <input type="text" id="resetLinkUrl" readonly
                 style="flex:1;height:40px;border:1.5px solid #bfdbfe;border-radius:8px;
                        padding:0 12px;font-size:.73rem;font-family:monospace;color:#1e40af;
                        background:#eff6ff;outline:none;"/>
          <button id="btnCopyLink"
                  style="height:40px;padding:0 16px;background:#2563eb;color:#fff;border:none;
                         border-radius:8px;font-size:.82rem;font-weight:600;cursor:pointer;
                         display:inline-flex;align-items:center;gap:6px;white-space:nowrap;
                         transition:background .15s;">
            <i class="fa-solid fa-copy"></i> Copy
          </button>
        </div>

        <div style="font-size:.75rem;color:#888;margin-bottom:16px;">
          <i class="fa-solid fa-clock" style="color:#f59e0b;"></i>
          Expires: <strong id="resetExpiry"></strong>
        </div>

        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;
                    padding:12px 14px;font-size:.78rem;color:#92400e;line-height:1.7;">
          <strong><i class="fa-solid fa-circle-info"></i> How to use:</strong><br>
          1. Copy the link and send it to the user via email, chat, or SMS.<br>
          2. When the user clicks the link they are logged in automatically and prompted to set a new password.<br>
          3. The link can only be used <strong>once</strong> and expires in <strong>24 hours</strong>.
        </div>
      </div>

      <!-- Error -->
      <div id="resetLinkError" style="display:none;text-align:center;padding:16px 0;">
        <i class="fa-solid fa-circle-xmark" style="color:#dc2626;font-size:1.8rem;"></i>
        <p id="resetLinkErrorMsg" style="margin-top:10px;font-size:.85rem;color:#dc2626;"></p>
      </div>

    </div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" data-close="resetLinkModal">Close</button>
      <button id="btnCopyLinkFooter" class="btn-modal-submit" style="display:none;">
        <i class="fa-solid fa-copy"></i> Copy Link
      </button>
    </div>
  </div>
</div>

<script>
(function () {
  // ── Reset password link generation ──────────────────────────
  document.querySelectorAll('.btn-reset-pw').forEach(function (btn) {
    btn.addEventListener('click', function () {
      // Reset modal to loading state
      document.getElementById('resetLinkLoading').style.display    = '';
      document.getElementById('resetLinkResult').style.display     = 'none';
      document.getElementById('resetLinkError').style.display      = 'none';
      document.getElementById('btnCopyLinkFooter').style.display   = 'none';

      openModal('resetLinkModal');

      var fd = new FormData();
      fd.set('user_id', btn.dataset.uid);

      fetch('pw_reset_action.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          document.getElementById('resetLinkLoading').style.display = 'none';
          if (!d.success) {
            document.getElementById('resetLinkError').style.display    = '';
            document.getElementById('resetLinkErrorMsg').textContent   = d.error || 'Unknown error.';
            return;
          }
          document.getElementById('resetForName').textContent          = d.name;
          document.getElementById('resetForEmail').textContent         = d.email;
          document.getElementById('resetLinkUrl').value                = d.link;
          document.getElementById('resetExpiry').textContent           = d.expires;
          document.getElementById('resetLinkResult').style.display     = '';
          document.getElementById('btnCopyLinkFooter').style.display   = '';
        })
        .catch(function () {
          document.getElementById('resetLinkLoading').style.display = 'none';
          document.getElementById('resetLinkError').style.display   = '';
          document.getElementById('resetLinkErrorMsg').textContent  = 'Network error. Please try again.';
        });
    });
  });

  // ── Copy link ────────────────────────────────────────────────
  function copyResetLink() {
    var val = document.getElementById('resetLinkUrl').value;
    if (!val) return;
    var finish = function () {
      ['btnCopyLink', 'btnCopyLinkFooter'].forEach(function (id) {
        var b = document.getElementById(id);
        if (!b) return;
        var orig = b.innerHTML;
        b.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
        b.style.background = '#16a34a';
        setTimeout(function () { b.innerHTML = orig; b.style.background = ''; }, 2000);
      });
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(val).then(finish).catch(function () {
        document.getElementById('resetLinkUrl').select();
        document.execCommand('copy');
        finish();
      });
    } else {
      document.getElementById('resetLinkUrl').select();
      document.execCommand('copy');
      finish();
    }
  }

  document.getElementById('btnCopyLink').addEventListener('click', copyResetLink);
  document.getElementById('btnCopyLinkFooter').addEventListener('click', copyResetLink);
}());
</script>

<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../shared/page_template.php';
