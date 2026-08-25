<?php
session_start();
require_once __DIR__ . '/../shared/db.php';

// Must be logged in AND flagged for password reset
if (empty($_SESSION['user_id'])) {
    header('Location: signin.php'); exit;
}
if (empty($_SESSION['must_set_password'])) {
    // Already set password — send to the right dashboard
    $dest = ($_SESSION['role'] ?? 'student') === 'admin'
        ? '../admin_dashboard/dashboard.php'
        : '../student_dashboard/dashboard.php';
    header("Location: $dest"); exit;
}

$first_name = htmlspecialchars($_SESSION['first_name'] ?? 'Student');
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($new) < 6) {
        $err = 'Password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $err = 'Passwords do not match.';
    } else {
        $hash   = password_hash($new, PASSWORD_DEFAULT);
        $userId = (int)$_SESSION['user_id'];
        $stmt   = $conn->prepare('UPDATE users SET password_hash=? WHERE id=?');
        $stmt->bind_param('si', $hash, $userId);
        if ($stmt->execute()) {
            unset($_SESSION['must_set_password']);
            $conn->close();
            // Route to role-specific dashboard
            $dest = ($_SESSION['role'] ?? 'student') === 'admin'
                ? '../admin_dashboard/dashboard.php'
                : '../student_dashboard/dashboard.php';
            header("Location: $dest"); exit;
        }
        $err = 'Failed to update password. Please try again.';
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Set Your Password – BCP</title>
  <link rel="stylesheet" href="../css/auth.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <style>
    .pw-card { width:420px;max-width:95vw;background:#fff;border-radius:14px;box-shadow:0 6px 32px rgba(0,0,0,.12);padding:40px 36px; }
    .pw-logo { text-align:center;margin-bottom:8px; }
    .pw-logo img { width:64px; }
    .pw-title { font-size:1.25rem;font-weight:700;color:#1a3a8c;text-align:center;margin-bottom:6px; }
    .pw-sub { font-size:.82rem;color:#888;text-align:center;margin-bottom:24px;line-height:1.5; }
    .pw-field { margin-bottom:16px; }
    .pw-field label { display:block;font-size:.78rem;font-weight:600;color:#444;margin-bottom:5px; }
    .pw-wrap { position:relative; }
    .pw-wrap input { width:100%;height:46px;border:1.5px solid #d0d7e2;border-radius:8px;padding:0 42px 0 14px;font-size:.9rem;outline:none;transition:border-color .2s;font-family:inherit; }
    .pw-wrap input:focus { border-color:#1a3a8c;box-shadow:0 0 0 3px rgba(26,58,140,.1); }
    .toggle-pw { position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#aaa;font-size:.9rem; }
    .strength-bar { height:4px;border-radius:2px;background:#e2e8f0;margin-top:6px;overflow:hidden; }
    .strength-fill { height:4px;border-radius:2px;transition:width .3s,background .3s; }
    .err-msg { color:#dc2626;font-size:.78rem;margin-top:14px;text-align:center; }
    .btn-set { width:100%;height:48px;margin-top:8px;background:#1a3a8c;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:700;cursor:pointer;transition:background .2s;display:flex;align-items:center;justify-content:center;gap:8px; }
    .btn-set:hover { background:#142d6e; }
  </style>
</head>
<body>
<div style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;background:#f0f4f8;">
  <div class="pw-card">
    <div class="pw-logo"><img src="../images/BCP_LOGO.png" alt="BCP Logo"/></div>
    <div class="pw-title">Welcome, <?= $first_name ?>!</div>
    <p class="pw-sub">Set a secure password for your BCP Student Portal account.</p>

    <?php if ($err): ?>
    <div class="err-msg"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <form method="POST" style="margin-top:20px;">
      <div class="pw-field">
        <label>New Password <span style="color:#ef4444;">*</span></label>
        <div class="pw-wrap">
          <input type="password" name="new_password" id="newPw"
                 placeholder="At least 6 characters" autocomplete="new-password" required/>
          <button type="button" class="toggle-pw" onclick="togglePw('newPw',this)">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
        <div class="strength-bar"><div class="strength-fill" id="strengthFill" style="width:0%;"></div></div>
        <div id="strengthLabel" style="font-size:.7rem;color:#aaa;margin-top:3px;"></div>
      </div>

      <div class="pw-field">
        <label>Confirm Password <span style="color:#ef4444;">*</span></label>
        <div class="pw-wrap">
          <input type="password" name="confirm_password" id="confirmPw"
                 placeholder="Repeat your password" autocomplete="new-password" required/>
          <button type="button" class="toggle-pw" onclick="togglePw('confirmPw',this)">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
        <div id="matchMsg" style="font-size:.72rem;margin-top:4px;"></div>
      </div>

      <button type="submit" class="btn-set">
        <i class="fa-solid fa-lock-open"></i> Set Password & Continue
      </button>
    </form>
  </div>
</div>

<script>
function togglePw(id,btn){const i=document.getElementById(id);i.type=i.type==='password'?'text':'password';btn.querySelector('i').className=i.type==='password'?'fa-solid fa-eye':'fa-solid fa-eye-slash';}
document.getElementById('newPw').addEventListener('input',function(){const v=this.value;let s=0;if(v.length>=6)s++;if(v.length>=10)s++;if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;const c=['#ef4444','#f59e0b','#f59e0b','#22c55e','#16a34a'];const l=['Too short','Weak','Fair','Strong','Very Strong'];document.getElementById('strengthFill').style.width=Math.min(s*20,100)+'%';document.getElementById('strengthFill').style.background=c[s-1]??'#e2e8f0';document.getElementById('strengthLabel').textContent=s>0?l[s-1]:'';document.getElementById('strengthLabel').style.color=c[s-1]??'#aaa';});
document.getElementById('confirmPw').addEventListener('input',function(){const m=this.value===document.getElementById('newPw').value;const el=document.getElementById('matchMsg');el.textContent=this.value?(m?'✓ Passwords match':'✗ Passwords do not match'):'';el.style.color=m?'#16a34a':'#ef4444';});
</script>
</body>
</html>
