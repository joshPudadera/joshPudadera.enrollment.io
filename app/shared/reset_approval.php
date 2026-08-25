<?php
// TEMP TOOL — resets a student application back to Pending so you can re-approve
// Visit: http://localhost/sms/app/shared/reset_approval.php
// DELETE THIS FILE after use.
session_start();
require_once __DIR__ . '/db.php';

if (($_SESSION['role'] ?? '') !== 'admin') {
    die('<p style="font-family:sans-serif;padding:20px;color:red;">Admin only. Please <a href="../auth/signin.php">sign in</a> as admin first.</p>');
}

$msg = '';

// Handle reset action
if (isset($_POST['reset_id'])) {
    $id = (int)$_POST['reset_id'];
    // Delete existing tokens for this applicant's user
    $conn->query("DELETE lt FROM login_tokens lt
                  JOIN pre_registrations p ON lt.user_id = p.user_id
                  WHERE p.id = $id");
    // Unlink from any admin account
    $conn->query("UPDATE pre_registrations SET user_id=NULL, status='Pending' WHERE id=$id");
    $msg = "Application #$id reset to Pending and unlinked from admin. You can now re-approve it.";
}

// List all applications
$apps = [];
$res = $conn->query("SELECT p.*, u.email AS user_email, u.role AS user_role
                     FROM pre_registrations p
                     LEFT JOIN users u ON p.user_id = u.id
                     ORDER BY p.submitted_at DESC LIMIT 20");
if ($res) while ($r = $res->fetch_assoc()) $apps[] = $r;

// List recent tokens
$tokens = [];
$res2 = $conn->query("SELECT lt.*, u.username, u.email, u.role
                      FROM login_tokens lt JOIN users u ON lt.user_id = u.id
                      ORDER BY lt.id DESC LIMIT 10");
if ($res2) while ($r = $res2->fetch_assoc()) $tokens[] = $r;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>Reset Approval Tool</title>
  <style>
    body { font-family: 'Segoe UI', sans-serif; padding: 30px; background: #f0f4f8; }
    h2 { color: #1a3a8c; }
    table { border-collapse: collapse; width: 100%; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.1); margin-bottom: 30px; }
    th { background: #1a3a8c; color: #fff; padding: 10px 14px; text-align: left; font-size: .8rem; }
    td { padding: 10px 14px; font-size: .8rem; border-bottom: 1px solid #f0f2f5; }
    .badge { padding: 3px 10px; border-radius: 20px; font-size: .72rem; font-weight: 700; }
    .pending  { background: #fff7ed; color: #d97706; }
    .approved { background: #dcfce7; color: #16a34a; }
    .rejected { background: #fee2e2; color: #dc2626; }
    .enrolled { background: #eff6ff; color: #2563eb; }
    .btn { background: #2563eb; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: .78rem; }
    .btn:hover { background: #1d4ed8; }
    .msg { background: #dcfce7; color: #16a34a; border: 1px solid #86efac; border-radius: 8px; padding: 12px 18px; margin-bottom: 20px; }
    .warn { background: #fff7ed; color: #d97706; border: 1px solid #fcd34d; border-radius: 8px; padding: 12px 18px; margin-bottom: 20px; font-size: .82rem; }
    a.link { color: #2563eb; font-size: .72rem; word-break: break-all; }

    .modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px;}
    .modal-overlay.active{display:flex;}
    .modal{background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.25);width:100%;max-width:420px;overflow:hidden;animation:modPop .18s ease-out;}
    @keyframes modPop{from{transform:scale(.95);opacity:0;}to{transform:scale(1);opacity:1;}}
    .modal-header{padding:14px 20px;border-bottom:1px solid #f0f2f5;display:flex;align-items:center;justify-content:space-between;background:#fafbfc;}
    .modal-header span{font-weight:700;color:#1a3a8c;font-size:.92rem;}
    .modal-close{background:none;border:none;font-size:1.5rem;color:#94a3b8;cursor:pointer;line-height:1;padding:0;width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:6px;}
    .modal-close:hover{background:#f1f5f9;color:#475569;}
    .modal-body{padding:20px;}
    .modal-footer{padding:14px 20px;border-top:1px solid #f0f2f5;display:flex;gap:10px;justify-content:flex-end;background:#fafbfc;}
    .modal-footer.modal-footer-split{justify-content:space-between;}
    .btn-modal-submit,.btn-modal-confirm{background:#2563eb;color:#fff;border:none;padding:9px 22px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.82rem;}
    .btn-modal-submit:hover,.btn-modal-confirm:hover{background:#1d4ed8;}
    .btn-modal-cancel{background:#fff;color:#64748b;border:1.5px solid #e2e8f0;padding:9px 22px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.82rem;}
    .btn-modal-cancel:hover{background:#f8fafc;border-color:#cbd5e1;color:#475569;}
  </style>
</head>
<body>
<h2>🔧 Reset Approval Tool</h2>
<div class="warn">⚠ This is a temporary admin tool. Delete <code>reset_approval.php</code> after use.</div>

<?php if ($msg): ?>
<div class="msg">✓ <?= htmlspecialchars($msg) ?> <a href="../enrollment_tab/validation.php">Go to Validation →</a></div>
<?php endif; ?>

<h3 style="margin-bottom:10px;">Recent Applications</h3>
<table>
  <thead>
    <tr><th>#</th><th>Name</th><th>Email</th><th>Status</th><th>Linked User</th><th>Action</th></tr>
  </thead>
  <tbody>
  <?php foreach ($apps as $a): ?>
  <tr>
    <td><?= $a['id'] ?></td>
    <td><?= htmlspecialchars($a['first_name'].' '.$a['last_name']) ?></td>
    <td><?= htmlspecialchars($a['email']) ?></td>
    <td><span class="badge <?= strtolower($a['status']) ?>"><?= $a['status'] ?></span></td>
    <td>
      <?php if ($a['user_email']): ?>
        <?= htmlspecialchars($a['user_email']) ?> <span style="color:<?= $a['user_role']==='admin'?'#dc2626':'#16a34a' ?>;">(<?= $a['user_role'] ?>)</span>
      <?php else: ?>
        <span style="color:#aaa;">none</span>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($a['status'] !== 'Pending'): ?>
      <form method="POST" style="display:inline;" class="reset-frm">
        <input type="hidden" name="reset_id" value="<?= $a['id'] ?>"/>
        <button type="button" class="btn btn-reset-app" data-reset-id="<?= $a['id'] ?>">Reset to Pending</button>
      </form>
      <?php else: ?>
      <span style="color:#aaa;font-size:.72rem;">Already pending</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h3 style="margin-bottom:10px;">Recent Login Tokens</h3>
<table>
  <thead>
    <tr><th>ID</th><th>User</th><th>Role</th><th>Used</th><th>Expires</th><th>Link</th></tr>
  </thead>
  <tbody>
  <?php if (empty($tokens)): ?>
  <tr><td colspan="6" style="text-align:center;color:#aaa;">No tokens found</td></tr>
  <?php endif; ?>
  <?php foreach ($tokens as $t): ?>
  <tr>
    <td><?= $t['id'] ?></td>
    <td><?= htmlspecialchars($t['username']) ?> &lt;<?= htmlspecialchars($t['email']) ?>&gt;</td>
    <td style="color:<?= $t['role']==='admin'?'#dc2626':'#16a34a' ?>;font-weight:700;"><?= $t['role'] ?></td>
    <td><?= $t['used'] ? '<span style="color:#dc2626;">Used</span>' : '<span style="color:#16a34a;">Unused</span>' ?></td>
    <td style="font-size:.72rem;"><?= $t['expires_at'] ?></td>
    <td>
      <?php if (!$t['used']): ?>
      <a class="link" href="../auth/login_via_token.php?token=<?= $t['token'] ?>" target="_blank">Open link</a>
      <?php else: ?>
      <span style="color:#aaa;font-size:.72rem;">—</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<p><a href="../enrollment_tab/validation.php" style="color:#2563eb;">← Back to Validation</a></p>

<script>
function openModal(id){var el=document.getElementById(id);if(el)el.classList.add('active');}
function closeModal(id){var el=document.getElementById(id);if(el)el.classList.remove('active');}
document.addEventListener('click',function(e){
  var cb=e.target.closest('[data-close]');if(cb){closeModal(cb.dataset.close);return;}
  if(e.target.classList.contains('modal-overlay'))e.target.classList.remove('active');
});
document.addEventListener('keydown',function(e){
  if(e.key==='Escape'){document.querySelectorAll('.modal-overlay.active').forEach(function(o){o.classList.remove('active');});}
});
document.addEventListener('click',function(e){if(e.target.closest('.modal'))e.stopPropagation();},true);
function escapeHtml(str){var d=document.createElement('div');d.appendChild(document.createTextNode(str));return d.innerHTML;}
var _rstCb=null;
function ensureRstModals(){
  if(!document.getElementById('rstAlertOverlay')){
    var h=''
    +'<div class="modal-overlay" id="rstAlertOverlay">'
    +'  <div class="modal">'
    +'    <div class="modal-header"><span id="rstAlertTitle">Alert</span><button class="modal-close" data-close="rstAlertOverlay">&times;</button></div>'
    +'    <div class="modal-body" id="rstAlertBody" style="padding:24px 22px;"></div>'
    +'    <div class="modal-footer"><button class="btn-modal-submit" data-close="rstAlertOverlay" style="flex:0 0 auto;padding:10px 48px;">OK</button></div>'
    +'  </div></div>'
    +'<div class="modal-overlay" id="rstConfirmOverlay">'
    +'  <div class="modal">'
    +'    <div class="modal-header"><span id="rstConfirmTitle">Confirm</span><button class="modal-close" data-close="rstConfirmOverlay">&times;</button></div>'
    +'    <div class="modal-body" id="rstConfirmBody" style="padding:24px 22px 12px;"></div>'
    +'    <div class="modal-footer modal-footer-split">'
    +'      <button class="btn-modal-cancel" id="rstConfirmCancel">Cancel</button>'
    +'      <button class="btn-modal-confirm" id="rstConfirmOk">Confirm</button>'
    +'    </div></div></div>';
    var d=document.createElement('div');d.innerHTML=h;
    while(d.firstChild)document.body.appendChild(d.firstChild);
  }
}
function showAlertModal(msg,type,title){
  ensureRstModals();type=type||'info';
  var ic='';
  if(type==='success')ic='<div style="text-align:center;color:#16a34a;font-size:2rem;margin-bottom:12px;">✓</div>';
  else if(type==='error')ic='<div style="text-align:center;color:#dc2626;font-size:2rem;margin-bottom:12px;">✕</div>';
  else if(type==='warning')ic='<div style="text-align:center;color:#d97706;font-size:2rem;margin-bottom:12px;">⚠</div>';
  else ic='<div style="text-align:center;color:#2563eb;font-size:2rem;margin-bottom:12px;">ℹ</div>';
  document.getElementById('rstAlertTitle').textContent=title||'Alert';
  document.getElementById('rstAlertBody').innerHTML=ic
    +'<p style="font-size:.88rem;color:#333;line-height:1.55;text-align:center;">'+escapeHtml(msg)+'</p>';
  openModal('rstAlertOverlay');
}
function showConfirmModal(msg,onConfirm,title){
  ensureRstModals();
  document.getElementById('rstConfirmTitle').textContent=title||'Confirm Action';
  document.getElementById('rstConfirmBody').innerHTML='<div style="display:flex;gap:12px;align-items:flex-start;">'
    +'<div style="color:#2563eb;font-size:1.6rem;flex-shrink:0;margin-top:2px;">❓</div>'
    +'<div style="flex:1;font-size:.88rem;color:#333;line-height:1.55;">'+escapeHtml(msg)+'</div></div>';
  _rstCb=onConfirm||null;
  var ok=document.getElementById('rstConfirmOk'),cancel=document.getElementById('rstConfirmCancel');
  if(!ok._hasH){ok.addEventListener('click',function(){
    closeModal('rstConfirmOverlay');var cb=_rstCb;_rstCb=null;
    if(cb)setTimeout(function(){cb(true);},50);
  });ok._hasH=true;}
  if(!cancel._hasH){cancel.addEventListener('click',function(){
    closeModal('rstConfirmOverlay');var cb=_rstCb;_rstCb=null;
    if(cb)setTimeout(function(){cb(false);},50);
  });cancel._hasH=true;}
  openModal('rstConfirmOverlay');
}

document.querySelectorAll('.btn-reset-app').forEach(function(btn){
  btn.addEventListener('click',function(){
    var id=btn.getAttribute('data-reset-id');
    showConfirmModal('Reset #'+id+' to Pending?',function(confirmed){
      if(!confirmed)return;
      var f=document.createElement('form');f.method='POST';
      f.innerHTML='<input type="hidden" name="reset_id" value="'+id+'"/>';
      document.body.appendChild(f);f.submit();
    },'Confirm Reset');
  });
});
</script>
</body>
</html>
