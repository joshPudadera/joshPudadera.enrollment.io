<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'admin') { header('Location: ../dashboard/dashboard.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'permissions';
$PAGE_TITLE = 'Permissions & Roles';
$PAGE_ICON  = 'fa-solid fa-shield-halved';

// Fetch all users
$users = [];
$res = $conn->query("SELECT id, username, first_name, last_name, email, role, created_at FROM users ORDER BY role ASC, last_name ASC");
if ($res) while ($r = $res->fetch_assoc()) $users[] = $r;

$roles = [
    'admin'   => ['Full system access', 'Manage students, enrollment, users', 'View all reports', '#2563eb'],
    'student' => ['View own profile', 'Submit pre-registration', 'Upload documents', '#22c55e'],
];

ob_start();
?>
<!-- Role definitions -->
<div class="tables-row">
  <?php foreach ($roles as $role => [$p1,$p2,$p3,$color]): ?>
  <div class="table-card">
    <h3 style="text-transform:capitalize; color:<?= $color ?>;">
      <i class="fa-solid fa-<?= $role==='admin'?'crown':'user' ?>"></i> <?= ucfirst($role) ?>
    </h3>
    <ul style="margin:12px 0 0 18px; font-size:0.82rem; color:#555; line-height:2;">
      <li><?= $p1 ?></li>
      <li><?= $p2 ?></li>
      <li><?= $p3 ?></li>
    </ul>
  </div>
  <?php endforeach; ?>
</div>

<!-- User list with role -->
<div class="crud-card">
  <div class="crud-header">
    <h3>All Users (<?= count($users) ?>)</h3>
    <a href="../auth/register.php" class="btn-add">
      <i class="fa-solid fa-plus"></i> Add User
    </a>
  </div>
  <table class="crud-table">
    <thead>
      <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Joined</th></tr>
    </thead>
    <tbody>
      <?php if ($users): foreach ($users as $u):
        $badge = $u['role']==='admin' ? 'badge-active' : 'badge-inactive';
      ?>
      <tr>
        <td><?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?></td>
        <td style="color:#2563eb;font-weight:600;"><?= htmlspecialchars($u['username']) ?></td>
        <td style="font-size:0.78rem;color:#666;"><?= htmlspecialchars($u['email']) ?></td>
        <td><span class="<?= $badge ?>"><?= ucfirst($u['role']) ?></span></td>
        <td style="font-size:0.75rem;color:#888;"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
      </tr>
      <?php endforeach; else: ?>
      <tr><td colspan="5" style="text-align:center;padding:24px;color:#aaa;">No users found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../shared/page_template.php';
