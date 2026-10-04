<?php
session_start();
// Must have a pending MFA session — otherwise redirect back to sign-in
if (empty($_SESSION['mfa_pending'])) {
    header('Location: signin.php'); exit;
}
// Already fully logged in — no need for MFA
if (!empty($_SESSION['user_id'])) {
    header('Location: ../dashboard/loading.php'); exit;
}
$pending = $_SESSION['mfa_pending'];
$expires_in = max(0, $pending['expires'] - time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Verify Login – BCP</title>
  <link rel="stylesheet" href="../css/auth.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
  <style>
    .mfa-wrap {
      display:flex; align-items:center; justify-content:center;
      min-height:100vh; background:#f0f4f8; padding:20px;
    }
    .mfa-card {
      background:#fff; border-radius:16px; box-shadow:0 4px 32px rgba(0,0,0,.12);
      padding:40px 44px; max-width:420px; width:100%; text-align:center;
    }
    .mfa-icon {
      width:64px; height:64px; border-radius:50%; background:#eff6ff;
      display:flex; align-items:center; justify-content:center;
      margin:0 auto 20px; font-size:1.6rem; color:#1a3a8c;
    }
    .mfa-card h2 { font-size:1.35rem; font-weight:700; color:#1a1a2e; margin-bottom:8px; }
    .mfa-card p  { font-size:.85rem; color:#888; line-height:1.6; margin-bottom:24px; }

    .code-inputs {
      display:flex; gap:10px; justify-content:center; margin-bottom:20px;
    }
    .code-inputs input {
      width:48px; height:56px; border:2px solid #d0d7e2; border-radius:10px;
      text-align:center; font-size:1.4rem; font-weight:700; color:#1a1a2e;
      outline:none; transition:border-color .2s; font-family:inherit;
    }
    .code-inputs input:focus { border-color:#1a3a8c; box-shadow:0 0 0 3px rgba(26,58,140,.1); }
    .code-inputs input.filled { border-color:#1a3a8c; background:#eff6ff; }
    .code-inputs input.error  { border-color:#ef4444; background:#fff1f2; }

    .btn-verify {
      width:100%; height:48px; background:#1a3a8c; color:#fff; border:none;
      border-radius:10px; font-size:.95rem; font-weight:600; cursor:pointer;
      font-family:inherit; transition:background .2s; margin-bottom:14px;
    }
    .btn-verify:hover    { background:#142d6e; }
    .btn-verify:disabled { background:#94a3b8; cursor:not-allowed; }

    .mfa-error   { background:#fee2e2; color:#dc2626; border-radius:8px; padding:10px 14px; font-size:.85rem; margin-bottom:14px; display:none; }
    .mfa-success { background:#dcfce7; color:#16a34a; border-radius:8px; padding:10px 14px; font-size:.85rem; margin-bottom:14px; display:none; }

    .mfa-timer { font-size:.8rem; color:#aaa; margin-bottom:16px; }
    .mfa-timer.expiring { color:#dc2626; font-weight:600; }

    .btn-resend {
      background:none; border:none; color:#2563eb; font-size:.82rem; font-weight:600;
      cursor:pointer; font-family:inherit; text-decoration:underline; padding:0;
    }
    .btn-resend:disabled { color:#aaa; cursor:not-allowed; text-decoration:none; }
    .back-link { display:block; margin-top:20px; font-size:.8rem; color:#888; text-decoration:none; }
    .back-link:hover { color:#1a3a8c; }
  </style>
</head>
<body>
<div class="mfa-wrap">
  <div class="mfa-card">
    <div class="mfa-icon"><i class="fa-solid fa-shield-halved"></i></div>
    <h2>Two-Step Verification</h2>
    <p>
      A 6-digit code has been sent to the system email address.<br>
      Enter the code below to complete sign-in as
      <strong><?= htmlspecialchars($pending['username']) ?></strong>.
    </p>

    <div class="mfa-error"   id="mfaError"></div>
    <div class="mfa-success" id="mfaSuccess"></div>

    <!-- 6 individual digit boxes -->
    <div class="code-inputs" id="codeInputs">
      <?php for ($i = 0; $i < 6; $i++): ?>
      <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]"
             id="d<?= $i ?>" autocomplete="<?= $i===0?'one-time-code':'off' ?>"/>
      <?php endfor; ?>
    </div>

    <div class="mfa-timer" id="mfaTimer">Code expires in <strong id="timerVal"><?= $expires_in ?></strong>s</div>

    <button class="btn-verify" id="btnVerify" disabled>
      <i class="fa-solid fa-circle-check"></i> Verify
    </button>

    <div>
      Didn't receive it?
      <button class="btn-resend" id="btnResend">Resend code</button>
    </div>

    <a href="signin.php" class="back-link">
      <i class="fa-solid fa-arrow-left" style="font-size:.75rem;"></i> Back to Sign In
    </a>
  </div>
</div>

<script>
(function() {
var API = '../shared/auth_actions.php';

// ── Digit input navigation ────────────────────────────────────
var digits = [];
for (var i = 0; i < 6; i++) digits.push(document.getElementById('d' + i));

digits.forEach(function(inp, idx) {
    inp.addEventListener('input', function() {
        inp.value = inp.value.replace(/\D/, '');
        if (inp.value) {
            inp.classList.add('filled');
            if (idx < 5) digits[idx + 1].focus();
        } else {
            inp.classList.remove('filled');
        }
        updateVerifyBtn();
    });
    inp.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace' && !inp.value && idx > 0) {
            digits[idx - 1].focus();
        }
    });
    inp.addEventListener('paste', function(e) {
        e.preventDefault();
        var pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
        for (var j = 0; j < pasted.length && j < 6; j++) {
            digits[j].value = pasted[j];
            digits[j].classList.add('filled');
        }
        if (pasted.length > 0) digits[Math.min(pasted.length, 5)].focus();
        updateVerifyBtn();
    });
});

function getCode() { return digits.map(function(d){ return d.value; }).join(''); }

function updateVerifyBtn() {
    document.getElementById('btnVerify').disabled = getCode().length !== 6;
}

function setError(msg) {
    var e = document.getElementById('mfaError');
    e.textContent = msg; e.style.display = '';
    document.getElementById('mfaSuccess').style.display = 'none';
    digits.forEach(function(d){ d.classList.add('error'); d.classList.remove('filled'); });
}
function clearError() {
    document.getElementById('mfaError').style.display = 'none';
    digits.forEach(function(d){ d.classList.remove('error'); });
}
function setSuccess(msg) {
    var s = document.getElementById('mfaSuccess');
    s.textContent = msg; s.style.display = '';
    document.getElementById('mfaError').style.display = 'none';
}

// ── Verify ────────────────────────────────────────────────────
document.getElementById('btnVerify').addEventListener('click', verify);
digits.forEach(function(d) {
    d.addEventListener('keydown', function(e){ if (e.key === 'Enter') verify(); });
});

function verify() {
    var code = getCode();
    if (code.length !== 6) return;
    clearError();
    var btn = document.getElementById('btnVerify');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verifying…';

    var fd = new FormData();
    fd.set('action', 'verify_mfa');
    fd.set('code',   code);

    fetch(API, { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (d.success) {
                setSuccess('Verified! Redirecting…');
                setTimeout(function(){ window.location.href = '../dashboard/loading.php'; }, 800);
            } else {
                setError(d.message);
                digits.forEach(function(di){ di.value=''; di.classList.remove('filled','error'); });
                digits[0].focus();
                btn.disabled  = false;
                btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Verify';
            }
        })
        .catch(function() {
            setError('Request failed. Please try again.');
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Verify';
        });
}

// ── Resend ────────────────────────────────────────────────────
document.getElementById('btnResend').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    var fd = new FormData(); fd.set('action','resend_mfa');
    fetch(API, { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (d.success) {
                setSuccess(d.message);
                remainingSecs = 300;
                digits.forEach(function(di){ di.value=''; di.classList.remove('filled','error'); });
                digits[0].focus();
                setTimeout(function(){ btn.disabled = false; }, 30000); // 30s cooldown
            } else {
                setError(d.message);
                btn.disabled = false;
            }
        })
        .catch(function(){ btn.disabled = false; });
});

// ── Countdown timer ───────────────────────────────────────────
var remainingSecs = <?= $expires_in ?>;
var timerEl  = document.getElementById('timerVal');
var timerRow = document.getElementById('mfaTimer');

var countdown = setInterval(function() {
    remainingSecs--;
    if (remainingSecs <= 0) {
        clearInterval(countdown);
        timerRow.innerHTML = '<span style="color:#dc2626;font-weight:600;">Code expired — click Resend for a new one.</span>';
        document.getElementById('btnVerify').disabled = true;
        return;
    }
    var m = Math.floor(remainingSecs / 60);
    var s = remainingSecs % 60;
    timerEl.textContent = m > 0 ? m + 'm ' + s + 's' : s + 's';
    if (remainingSecs <= 60) timerRow.classList.add('expiring');
}, 1000);

digits[0].focus();
}());
</script>
</body>
</html>
