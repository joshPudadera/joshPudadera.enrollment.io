<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id']))     { header('Location: ../auth/signin.php'); exit; }
if ($_SESSION['role'] !== 'student') { header('Location: ../admin_dashboard/dashboard.php'); exit; }

$first_name     = htmlspecialchars($_SESSION['first_name'] ?? '');
$last_name      = htmlspecialchars($_SESSION['last_name']  ?? '');
$email          = htmlspecialchars($_SESSION['email']      ?? '');
$username       = htmlspecialchars($_SESSION['username']   ?? '');
$avatar_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Account Settings – BCP</title>
  <link rel="stylesheet" href="../css/account.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<div class="topbar">
  <a href="dashboard.php" class="topbar-back">
    <i class="fa-solid fa-chevron-left"></i> Back to Dashboard
  </a>
  <span class="topbar-title">Account Settings</span>
</div>

<div class="account-wrap">

  <div class="settings-card">
    <div class="settings-card-header">
      <i class="fa-solid fa-user"></i><h2>Profile Information</h2>
    </div>
    <div class="settings-card-body">
      <div class="avatar-row">
        <div class="avatar-circle" id="avatarCircle"><?= $avatar_initial ?></div>
        <div class="avatar-info">
          <div class="avatar-name" id="displayName"><?= $first_name.' '.$last_name ?></div>
          <div class="avatar-role">Student</div>
        </div>
      </div>
      <form id="profileForm">
        <div class="form-grid">
          <div class="form-field"><label>First Name</label><input type="text" name="first_name" value="<?= $first_name ?>"/><span class="field-error"></span></div>
          <div class="form-field"><label>Last Name</label><input type="text" name="last_name"  value="<?= $last_name ?>"/><span class="field-error"></span></div>
          <div class="form-field full"><label>Email</label><input type="email" name="email" value="<?= $email ?>"/><span class="field-error"></span></div>
          <div class="form-field full"><label>Username</label><input type="text" name="username" value="<?= $username ?>"/><span class="field-error"></span></div>
        </div>
      </form>
    </div>
    <div class="settings-card-footer"><button class="btn-save" id="btnSaveProfile">Save Changes</button></div>
  </div>

  <div class="settings-card">
    <div class="settings-card-header"><i class="fa-solid fa-lock"></i><h2>Change Password</h2></div>
    <div class="settings-card-body">
      <form id="passwordForm">
        <div class="form-grid">
          <div class="form-field full"><label>Current Password</label>
            <div class="password-wrap">
              <input type="password" name="current_password" id="fCurrentPw" placeholder="Enter current password" autocomplete="current-password"/>
              <button type="button" class="toggle-pw" data-target="fCurrentPw"><i class="fa-solid fa-eye"></i></button>
            </div><span class="field-error"></span></div>
          <div class="form-field"><label>New Password</label>
            <div class="password-wrap">
              <input type="password" name="new_password" id="fNewPw" placeholder="Min 6 characters" autocomplete="new-password"/>
              <button type="button" class="toggle-pw" data-target="fNewPw"><i class="fa-solid fa-eye"></i></button>
            </div><span class="field-error"></span></div>
          <div class="form-field"><label>Confirm New Password</label>
            <div class="password-wrap">
              <input type="password" name="confirm_password" id="fConfirmPw" placeholder="Repeat new password" autocomplete="new-password"/>
              <button type="button" class="toggle-pw" data-target="fConfirmPw"><i class="fa-solid fa-eye"></i></button>
            </div><span class="field-error"></span></div>
        </div>
      </form>
    </div>
    <div class="settings-card-footer"><button class="btn-save" id="btnSavePassword">Update Password</button></div>
  </div>

  <div class="settings-card">
    <div class="settings-card-header"><i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i><h2 style="color:#dc2626;">Danger Zone</h2></div>
    <div class="danger-zone">
      <div class="danger-info"><h3>Sign Out</h3><p>End your current session.</p></div>
      <button class="btn-danger" id="btnSignOut">Sign Out</button>
    </div>
  </div>

</div>

<script>
const API    = '../shared/auth_actions.php';
const SIGNIN = '../auth/signin.php';

function showToast(msg,type='success'){const l={success:'Saved',error:'Error'};let t=document.querySelector('.toast');if(!t){t=document.createElement('div');t.className='toast';t.innerHTML=`<div class="toast-label"><span class="toast-dot"></span><span class="toast-title"></span></div><div class="toast-msg"></div>`;document.body.appendChild(t);}t.querySelector('.toast-title').textContent=l[type]??type;t.querySelector('.toast-msg').textContent=msg;t.className=`toast ${type}`;void t.offsetWidth;t.classList.add('show');clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove('show'),3500);}
function clearErr(i){i.classList.remove('input-error');i.closest('.form-field')?.classList.remove('has-error');const e=i.closest('.form-field')?.querySelector('.field-error');if(e)e.textContent='';}
function validateFields(form,fields){let v=true;fields.forEach(({name,label})=>{const i=form.querySelector(`[name="${name}"]`);if(!i)return;const f=i.closest('.form-field');const e=f?.querySelector('.field-error');if(!i.value.trim()){i.classList.add('input-error');f?.classList.add('has-error');if(e)e.textContent=`${label} is required.`;v=false;}else clearErr(i);});return v;}
document.querySelectorAll('.form-field input').forEach(i=>i.addEventListener('input',()=>{if(i.value.trim())clearErr(i);}));
document.querySelectorAll('.toggle-pw').forEach(b=>b.addEventListener('click',()=>{const i=document.getElementById(b.dataset.target);i.type=i.type==='password'?'text':'password';}));
async function postAction(fd){return fetch(API,{method:'POST',body:fd}).then(r=>r.json());}

document.getElementById('btnSaveProfile').addEventListener('click',async()=>{
  const form=document.getElementById('profileForm');
  if(!validateFields(form,[{name:'first_name',label:'First Name'},{name:'last_name',label:'Last Name'},{name:'email',label:'Email'},{name:'username',label:'Username'}]))return;
  const fd=new FormData(form);fd.set('action','update_profile');
  try{const d=await postAction(fd);if(d.success){const f=form.querySelector('[name="first_name"]').value.trim();const l=form.querySelector('[name="last_name"]').value.trim();document.getElementById('displayName').textContent=`${f} ${l}`;document.getElementById('avatarCircle').textContent=f.charAt(0).toUpperCase();showToast(d.message,'success');}else showToast(d.message,'error');}catch{showToast('Request failed.','error');}
});

document.getElementById('btnSavePassword').addEventListener('click',async()=>{
  const form=document.getElementById('passwordForm');
  if(!validateFields(form,[{name:'current_password',label:'Current Password'},{name:'new_password',label:'New Password'},{name:'confirm_password',label:'Confirm Password'}]))return;
  const np=form.querySelector('[name="new_password"]').value;const cp=form.querySelector('[name="confirm_password"]').value;
  if(np!==cp){const i=form.querySelector('[name="confirm_password"]');i.classList.add('input-error');i.closest('.form-field')?.classList.add('has-error');const e=i.closest('.form-field')?.querySelector('.field-error');if(e)e.textContent='Passwords do not match.';return;}
  const fd=new FormData(form);fd.set('action','change_password');
  try{const d=await postAction(fd);if(d.success){form.reset();showToast(d.message,'success');}else showToast(d.message,'error');}catch{showToast('Request failed.','error');}
});

document.getElementById('btnSignOut').addEventListener('click',async()=>{
  const fd=new FormData();fd.set('action','logout');await postAction(fd).catch(()=>{});window.location.href=SIGNIN;
});
</script>
</body></html>
<?php $conn->close(); ?>
