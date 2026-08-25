<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php');
    exit;
}
$first_name     = htmlspecialchars($_SESSION['first_name'] ?? '');
$last_name      = htmlspecialchars($_SESSION['last_name']  ?? '');
$email          = htmlspecialchars($_SESSION['email']      ?? '');
$username       = htmlspecialchars($_SESSION['username']   ?? '');
$role           = ucfirst(htmlspecialchars($_SESSION['role'] ?? 'student'));
$avatar_initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Account Settings – BCP Student Portal</title>
  <link rel="stylesheet" href="../css/account.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>

<div class="topbar">
  <a href="dashboard.php" class="topbar-back">
    <i class="fa-solid fa-chevron-left"></i>
    Back to Dashboard
  </a>
  <span class="topbar-title">Account Settings</span>
</div>

<div class="account-wrap">

  <!-- Profile card -->
  <div class="settings-card">
    <div class="settings-card-header">
      <i class="fa-solid fa-user"></i>
      <h2>Profile Information</h2>
    </div>
    <div class="settings-card-body">
      <div class="avatar-row">
        <div class="avatar-circle" id="avatarCircle"><?= $avatar_initial ?></div>
        <div class="avatar-info">
          <div class="avatar-name" id="displayName"><?= $first_name . ' ' . $last_name ?></div>
          <div class="avatar-role"><?= $role ?></div>
        </div>
      </div>
      <form id="profileForm">
        <div class="form-grid">
          <div class="form-field">
            <label>First Name</label>
            <input type="text" name="first_name" value="<?= $first_name ?>" placeholder="First name"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Last Name</label>
            <input type="text" name="last_name" value="<?= $last_name ?>" placeholder="Last name"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field full">
            <label>Email Address</label>
            <input type="email" name="email" value="<?= $email ?>" placeholder="email@example.com"/>
            <span class="field-error"></span>
          </div>
          <div class="form-field full">
            <label>Username</label>
            <input type="text" name="username" value="<?= $username ?>" placeholder="Username"/>
            <span class="field-error"></span>
          </div>
        </div>
      </form>
    </div>
    <div class="settings-card-footer">
      <button class="btn-save" id="btnSaveProfile">Save Changes</button>
    </div>
  </div>

  <!-- Change password card -->
  <div class="settings-card">
    <div class="settings-card-header">
      <i class="fa-solid fa-lock"></i>
      <h2>Change Password</h2>
    </div>
    <div class="settings-card-body">
      <form id="passwordForm">
        <div class="form-grid">
          <div class="form-field full">
            <label>Current Password</label>
            <div class="password-wrap">
              <input type="password" name="current_password" id="fCurrentPw" placeholder="Enter current password" autocomplete="current-password"/>
              <button type="button" class="toggle-pw" data-target="fCurrentPw">
                <i class="fa-solid fa-eye"></i>
              </button>
            </div>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>New Password</label>
            <div class="password-wrap">
              <input type="password" name="new_password" id="fNewPw" placeholder="New password (min 6)" autocomplete="new-password"/>
              <button type="button" class="toggle-pw" data-target="fNewPw">
                <i class="fa-solid fa-eye"></i>
              </button>
            </div>
            <span class="field-error"></span>
          </div>
          <div class="form-field">
            <label>Confirm New Password</label>
            <div class="password-wrap">
              <input type="password" name="confirm_password" id="fConfirmPw" placeholder="Repeat new password" autocomplete="new-password"/>
              <button type="button" class="toggle-pw" data-target="fConfirmPw">
                <i class="fa-solid fa-eye"></i>
              </button>
            </div>
            <span class="field-error"></span>
          </div>
        </div>
      </form>
    </div>
    <div class="settings-card-footer">
      <button class="btn-save" id="btnSavePassword">Update Password</button>
    </div>
  </div>

  <!-- Danger zone -->
  <div class="settings-card">
    <div class="settings-card-header">
      <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i>
      <h2 style="color:#dc2626;">Danger Zone</h2>
    </div>
    <div class="danger-zone">
      <div class="danger-info">
        <h3>Sign Out</h3>
        <p>End your current session and return to the sign-in page.</p>
      </div>
      <button class="btn-danger" id="btnSignOut">Sign Out</button>
    </div>
    <div class="danger-zone" style="border-top:1px solid #fef2f2;">
      <div class="danger-info">
        <h3>Delete Account</h3>
        <p>Permanently remove your account. This cannot be undone.</p>
      </div>
      <button class="btn-danger" id="btnDeleteAccount">Delete Account</button>
    </div>
  </div>

</div>

<script>
const API = '../shared/auth_actions.php';
const SIGNIN = '../auth/signin.php';

function showToast(message, type = 'success') {
    const labels = { success: 'Saved', error: 'Error' };
    let toast = document.querySelector('.toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.className = 'toast';
        toast.innerHTML = `<div class="toast-label"><span class="toast-dot"></span><span class="toast-title"></span></div><div class="toast-msg"></div>`;
        document.body.appendChild(toast);
    }
    toast.querySelector('.toast-title').textContent = labels[type] ?? type;
    toast.querySelector('.toast-msg').textContent   = message;
    toast.className = `toast ${type}`;
    void toast.offsetWidth;
    toast.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => toast.classList.remove('show'), 3500);
}

function validateFields(form, fields) {
    let valid = true;
    fields.forEach(({ name, label }) => {
        const input = form.querySelector(`[name="${name}"]`);
        if (!input) return;
        const field = input.closest('.form-field');
        const err   = field?.querySelector('.field-error');
        if (!input.value.trim()) {
            input.classList.add('input-error');
            field?.classList.add('has-error');
            if (err) err.textContent = `${label} is required.`;
            valid = false;
        } else { clearErr(input); }
    });
    return valid;
}

function clearErr(input) {
    input.classList.remove('input-error');
    input.closest('.form-field')?.classList.remove('has-error');
    const e = input.closest('.form-field')?.querySelector('.field-error');
    if (e) e.textContent = '';
}

document.querySelectorAll('.form-field input').forEach(i => {
    i.addEventListener('input', () => { if (i.value.trim()) clearErr(i); });
});

document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
        const inp = document.getElementById(btn.dataset.target);
        inp.type  = inp.type === 'password' ? 'text' : 'password';
    });
});

async function postAction(fd) {
    return fetch(API, { method: 'POST', body: fd }).then(r => r.json());
}

document.getElementById('btnSaveProfile').addEventListener('click', async () => {
    const form = document.getElementById('profileForm');
    if (!validateFields(form, [
        { name: 'first_name', label: 'First Name' },
        { name: 'last_name',  label: 'Last Name'  },
        { name: 'email',      label: 'Email'      },
        { name: 'username',   label: 'Username'   },
    ])) return;
    const fd = new FormData(form);
    fd.set('action', 'update_profile');
    try {
        const data = await postAction(fd);
        if (data.success) {
            const first = form.querySelector('[name="first_name"]').value.trim();
            const last  = form.querySelector('[name="last_name"]').value.trim();
            document.getElementById('displayName').textContent  = `${first} ${last}`;
            document.getElementById('avatarCircle').textContent = first.charAt(0).toUpperCase();
            showToast(data.message, 'success');
        } else { showToast(data.message, 'error'); }
    } catch { showToast('Request failed.', 'error'); }
});

document.getElementById('btnSavePassword').addEventListener('click', async () => {
    const form = document.getElementById('passwordForm');
    if (!validateFields(form, [
        { name: 'current_password', label: 'Current Password' },
        { name: 'new_password',     label: 'New Password'     },
        { name: 'confirm_password', label: 'Confirm Password' },
    ])) return;
    const newPw = form.querySelector('[name="new_password"]').value;
    const conf  = form.querySelector('[name="confirm_password"]').value;
    if (newPw !== conf) {
        const inp = form.querySelector('[name="confirm_password"]');
        inp.classList.add('input-error');
        inp.closest('.form-field')?.classList.add('has-error');
        const e = inp.closest('.form-field')?.querySelector('.field-error');
        if (e) e.textContent = 'Passwords do not match.';
        return;
    }
    const fd = new FormData(form);
    fd.set('action', 'change_password');
    try {
        const data = await postAction(fd);
        if (data.success) { form.reset(); showToast(data.message, 'success'); }
        else { showToast(data.message, 'error'); }
    } catch { showToast('Request failed.', 'error'); }
});

document.getElementById('btnSignOut').addEventListener('click', async () => {
    const fd = new FormData(); fd.set('action', 'logout');
    await postAction(fd).catch(() => {});
    window.location.href = SIGNIN;
});

document.getElementById('btnDeleteAccount').addEventListener('click', (ev) => {
    ev.preventDefault();
    showConfirmModal('Permanently delete your account? This cannot be undone.', async (confirmed) => {
        if (!confirmed) return;
        const fd = new FormData(); fd.set('action', 'delete_account');
        try {
            const data = await postAction(fd);
            if (data.success) {
                showToast('Account deleted. Redirecting…', 'error');
                setTimeout(() => { window.location.href = SIGNIN; }, 2000);
            } else { showToast(data.message, 'error'); }
        } catch { showToast('Request failed.', 'error'); }
    }, 'Delete Account');
});

// ═══════════════════════════════════════════════════
// MODAL HELPERS (standalone for account settings page)
// ═══════════════════════════════════════════════════
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('active');
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
}
document.addEventListener('click', (e) => {
    const cb = e.target.closest('[data-close]');
    if (cb) { closeModal(cb.dataset.close); return; }
    if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('active');
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(o => o.classList.remove('active'));
    }
});
document.addEventListener('click', (e) => {
    if (e.target.closest('.modal')) e.stopPropagation();
}, true);

function _accEscapeHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
}

let _accConfirmCb = null;
function ensureAccModals() {
    if (document.getElementById('accAlertModal')) return;
    const html = `
<div class="modal-overlay" id="accAlertModal">
  <div class="modal modal-sm">
    <div class="modal-header"><span id="accAlertTitle">Alert</span><button class="modal-close" data-close="accAlertModal">&times;</button></div>
    <div class="modal-body" id="accAlertBody"></div>
    <div class="modal-footer"><button class="btn-modal-submit" data-close="accAlertModal" style="flex:0 0 auto;padding:10px 48px;">OK</button></div>
  </div>
</div>
<div class="modal-overlay" id="accConfirmModal">
  <div class="modal modal-sm">
    <div class="modal-header"><span id="accConfirmTitle">Confirm</span><button class="modal-close" data-close="accConfirmModal">&times;</button></div>
    <div class="modal-body" id="accConfirmBody" style="padding:24px 22px 12px;"></div>
    <div class="modal-footer modal-footer-split">
      <button class="btn-modal-cancel" id="accConfirmCancel">Cancel</button>
      <button class="btn-modal-confirm" id="accConfirmOk">Confirm</button>
    </div>
  </div>
</div>`;
    const d = document.createElement('div');
    d.innerHTML = html;
    while (d.firstChild) document.body.appendChild(d.firstChild);
    const ok = document.getElementById('accConfirmOk');
    const canc = document.getElementById('accConfirmCancel');
    ok.addEventListener('click', () => {
        closeModal('accConfirmModal');
        const cb = _accConfirmCb; _accConfirmCb = null;
        if (cb) setTimeout(() => cb(true), 50);
    });
    canc.addEventListener('click', () => {
        closeModal('accConfirmModal');
        const cb = _accConfirmCb; _accConfirmCb = null;
        if (cb) setTimeout(() => cb(false), 50);
    });
}

function showAlertModal(message, type, title) {
    ensureAccModals();
    type = type || 'info';
    const icons = {
        success: '<i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:2rem;display:block;margin-bottom:12px;"></i>',
        error:   '<i class="fa-solid fa-circle-xmark" style="color:#dc2626;font-size:2rem;display:block;margin-bottom:12px;"></i>',
        warning: '<i class="fa-solid fa-triangle-exclamation" style="color:#d97706;font-size:2rem;display:block;margin-bottom:12px;"></i>',
        info:    '<i class="fa-solid fa-circle-info" style="color:#2563eb;font-size:2rem;display:block;margin-bottom:12px;"></i>'
    };
    const titles = { success:'Success', error:'Error', warning:'Warning', info:'Information' };
    document.getElementById('accAlertTitle').textContent = title || titles[type] || 'Alert';
    document.getElementById('accAlertBody').innerHTML = '<div style="text-align:center;">' + (icons[type] || icons.info) +
        '<p style="font-size:.88rem;color:#333;line-height:1.55;white-space:pre-wrap;word-break:break-word;">' + _accEscapeHtml(message) + '</p></div>';
    openModal('accAlertModal');
}

function showConfirmModal(message, onConfirm, title) {
    ensureAccModals();
    document.getElementById('accConfirmTitle').textContent = title || 'Confirm Action';
    document.getElementById('accConfirmBody').innerHTML =
        '<div style="display:flex;gap:12px;align-items:flex-start;">' +
        '<i class="fa-solid fa-circle-question" style="color:#2563eb;font-size:1.6rem;flex-shrink:0;margin-top:2px;"></i>' +
        '<div style="flex:1;font-size:.88rem;color:#333;line-height:1.55;">' + _accEscapeHtml(message) + '</div></div>';
    _accConfirmCb = onConfirm || null;
    openModal('accConfirmModal');
}
</script>
</body>
</html>
<?php $conn->close(); ?>
