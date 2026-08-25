<?php
session_start();
$data = $_SESSION['req_submitted'] ?? null;
if (!$data) { header('Location: index.php'); exit; }
unset($_SESSION['req_submitted']);

$current_step = 4;
include __DIR__ . '/header.php';
$logged_in = !empty($_SESSION['user_id']);
?>

<div class="enroll-body">
  <div class="enroll-card" style="text-align:center;">

    <div class="success-icon">
      <i class="fa-solid fa-circle-check"></i>
    </div>

    <div class="success-title">Requirements Submitted!</div>

    <p class="success-sub">
      Your <strong><?= (int)$data['count'] ?> document<?= $data['count'] !== 1 ? 's' : '' ?></strong>
      have been received and are now pending review by the registrar.
      You will be contacted once your documents have been verified.
    </p>

    <?php if ($data['ref']): ?>
    <div class="ref-number">
      Reference No: <?= htmlspecialchars($data['ref']) ?>
    </div>
    <?php endif; ?>

    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;
                padding:14px 20px;display:inline-block;font-size:.82rem;
                color:#16a34a;margin-bottom:20px;text-align:left;max-width:420px;">
      <i class="fa-solid fa-circle-check"></i>
      <strong><?= (int)$data['count'] ?> file<?= $data['count'] !== 1 ? 's' : '' ?></strong>
      uploaded on <?= htmlspecialchars($data['time']) ?>.
      <?php if ($data['saved_db'] > 0): ?>
        <br><i class="fa-solid fa-database" style="margin-top:4px;"></i>
        <?= $data['saved_db'] ?> record<?= $data['saved_db'] !== 1 ? 's' : '' ?> saved to your enrollment file.
      <?php endif; ?>
      <?php if (!empty($data['ai_inspected']) && $data['ai_inspected'] > 0): ?>
        <br><i class="fa-solid fa-robot" style="margin-top:4px;"></i>
        <?= $data['ai_inspected'] ?> document<?= $data['ai_inspected'] !== 1 ? 's' : '' ?> automatically inspected by AI.
      <?php endif; ?>
    </div>

    <p style="font-size:.8rem;color:#888;margin-bottom:24px;">
      <i class="fa-solid fa-clock"></i>
      Review typically takes <strong>1–3 business days</strong>.
      Check your email for updates.
    </p>

    <div class="enroll-actions" style="justify-content:center;flex-wrap:wrap;gap:12px;">
      <a href="index.php" class="btn-back">
        <i class="fa-solid fa-rotate-left"></i> Submit More
      </a>
      <a href="../auth/signin.php" class="btn-proceed">
        <i class="fa-solid fa-right-to-bracket"></i> Sign In to Track Status
      </a>
    </div>

  </div>
</div>

</body>
</html>
