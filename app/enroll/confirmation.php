<?php
session_start();
$ref = $_SESSION['enroll_ref'] ?? null;
if (!$ref) { header('Location: index.php'); exit; }
unset($_SESSION['enroll_ref']);

$current_step = 6;
include __DIR__ . '/header.php';
$logged_in = !empty($_SESSION['user_id']);
?>

<div class="enroll-body">
  <div class="enroll-card" style="text-align:center;">

    <div class="success-icon">
      <i class="fa-solid fa-circle-check"></i>
    </div>

    <div class="success-title">Application Submitted!</div>

    <p class="success-sub">
      Your enrollment application has been received and is now
      <strong>pending review</strong>. Please keep your reference number.
    </p>

    <div class="ref-number">
      Reference No: <?= htmlspecialchars($ref) ?>
    </div>

    <!-- ── Email notice ── -->
    <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px;
                padding:20px 24px;margin:0 auto 24px;max-width:440px;text-align:left;">
      <div style="display:flex;align-items:flex-start;gap:14px;">
        <i class="fa-solid fa-envelope"
           style="font-size:1.4rem;color:#2563eb;flex-shrink:0;margin-top:3px;"></i>
        <div>
          <div style="font-size:.9rem;font-weight:700;color:#1a3a8c;margin-bottom:6px;">
            Check your email
          </div>
          <div style="font-size:.82rem;color:#555;line-height:1.65;">
            Once an admin <strong>approves</strong> your application, you will receive an email
            with a <strong>one-time login link</strong> to set your password and access your
            student account.<br><br>
            <span style="color:#888;">
              <i class="fa-solid fa-clock"></i>
              Review typically takes <strong>1–3 business days</strong>.
            </span>
          </div>
        </div>
      </div>
    </div>

    <p style="font-size:.8rem;color:#888;margin-bottom:24px;">
      While waiting, prepare the required documents listed in Step 1
      and bring them to your chosen campus.
    </p>

    <div class="enroll-actions" style="justify-content:center;flex-wrap:wrap;gap:12px;">
      <a href="index.php" class="btn-back">
        <i class="fa-solid fa-rotate-left"></i> Apply Again
      </a>
      <?php if ($logged_in): ?>
      <a href="../requirements/index.php" class="btn-proceed">
        <i class="fa-solid fa-upload"></i> Submit Requirements
      </a>
      <?php else: ?>
      <a href="../requirements/index.php" class="btn-proceed">
        <i class="fa-solid fa-upload"></i> Submit Requirements
      </a>
      <?php endif; ?>
    </div>

  </div>
</div>

</body>
</html>
