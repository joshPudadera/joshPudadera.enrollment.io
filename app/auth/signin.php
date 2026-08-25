<?php
session_start();
if (!empty($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: ../admin_dashboard/dashboard.php');
    } else {
        header('Location: ../student_dashboard/dashboard.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Sign In – BCP Student Management System</title>
  <link rel="stylesheet" href="../css/auth.css" />
  <link rel="stylesheet" href="../css/page-loader.css"/>
  <meta name="loader-logo" content="../images/BCP_LOGO.png"/>
  <script src="../js/page-loader.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<div class="outer">
  <div class="card">
    <!-- Left panel -->
    <div class="left">
      <div class="left-top">
        <img src="../images/BCP_LOGO.png" alt="BCP Logo" class="left-logo"/>
        <p class="left-school">Bestlink College of the Philippines</p>
      </div>
      <div class="left-body">
        <h1>Student Management System</h1>
        <p class="subtitle">Enrollment System</p>
        <p>
          <span>Enrollment</span> is the process of registering individuals into the
          system by collecting and verifying the required information.
          Once enrollment is complete, users can access the
          services and features available to them.
        </p>
      </div>
    </div>

    <!-- Right panel -->
    <div class="right">
      <img class="bcp-logo" src="../images/BCP_LOGO.png" alt="Bestlink College of the Philippines Logo" />

      <h2>Sign In Account</h2>

      <div class="auth-error" id="authError" style="display:none;"></div>

      <?php $token_err = $_GET['err'] ?? '';
      if ($token_err === 'token_expired'): ?>
      <div class="auth-error" style="display:block;margin-bottom:14px;">
        <i class="fa-solid fa-clock"></i>
        Your login link has expired or already been used. Please contact the registrar.
      </div>
      <?php elseif ($token_err === 'invalid_token'): ?>
      <div class="auth-error" style="display:block;margin-bottom:14px;">
        <i class="fa-solid fa-circle-xmark"></i>
        Invalid login link. Use the link from your email or sign in manually.
      </div>
      <?php endif; ?>

      <form id="signinForm" style="width:100%">
        <div class="form-group">
          <label>
            <i class="fa-solid fa-user"></i>
            Username
          </label>
          <input type="text" id="username" autocomplete="username" />
        </div>

        <div class="form-group">
          <label>
            <i class="fa-solid fa-lock"></i>
            Password
          </label>
          <input type="password" id="password" autocomplete="current-password" />
        </div>

        <button type="submit" class="btn-signin" id="btnSignin">
          Sign In
          <i class="fa-solid fa-arrow-right"></i>
        </button>
      </form>

      <p class="register-link">
        Don't have an account? <a href="register.php">Register here</a>
      </p>
      <p class="register-link" style="margin-top:8px;">
        <a href="../landing.php" style="color:#888;">
          <i class="fa-solid fa-arrow-left" style="font-size:.75rem;"></i> Back to main page
        </a>
      </p>
    </div>
  </div>
</div>

<script>
document.getElementById('signinForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;
    const errorBox = document.getElementById('authError');
    const btn      = document.getElementById('btnSignin');

    errorBox.style.display = 'none';
    if (!username || !password) return showError('Please enter your username and password.');

    btn.disabled    = true;
    btn.textContent = 'Signing in…';

    const fd = new FormData();
    fd.set('action', 'login');
    fd.set('username', username);
    fd.set('password', password);

    try {
        const data = await fetch('../shared/auth_actions.php', { method: 'POST', body: fd }).then(r => r.json());
        if (data.success) {
            const dest = data.role === 'admin'
                ? '../dashboard/loading.php'
                : '../dashboard/loading.php';
            window.location.href = dest;
        } else {
            showError(data.message);
            btn.disabled  = false;
            btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
        }
    } catch {
        showError('Request failed. Please try again.');
        btn.disabled  = false;
        btn.innerHTML = 'Sign In <i class="fa-solid fa-arrow-right"></i>';
    }

    function showError(msg) {
        errorBox.textContent   = msg;
        errorBox.style.display = 'block';
    }
});
</script>
</body>
</html>
