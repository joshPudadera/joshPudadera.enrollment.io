<?php
session_start();
$type = trim($_GET['type'] ?? $_SESSION['enroll']['applicant_type'] ?? '');
$valid_types = ['Freshman', 'Senior High'];
if (!$type || !in_array($type, $valid_types)) {
    header('Location: applicant_type.php'); exit;
}
if (!isset($_SESSION['enroll'])) $_SESSION['enroll'] = [];
$_SESSION['enroll']['applicant_type'] = $type;

$current_step = 3;
include __DIR__ . '/header.php';
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">What's your preferred Campus?</h2>
    <p style="text-align:center;font-size:.85rem;color:#666;margin-bottom:28px;">
      Select the BCP campus you wish to enroll in.
    </p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;max-width:560px;margin:0 auto 32px;">

      <a href="course.php?branch=Main+Campus" class="option-card">
        <i class="fa-solid fa-building-columns"></i>
        <span class="option-card-title">Main Campus</span>
        <span class="option-card-sub">Baliuag, Bulacan</span>
      </a>

      <a href="course.php?branch=Bulacan+Campus" class="option-card">
        <i class="fa-solid fa-city"></i>
        <span class="option-card-title">Bulacan Campus</span>
        <span class="option-card-sub">Bulacan, Bulacan</span>
      </a>

    </div>

    <div class="enroll-actions">
      <a href="applicant_type.php" class="btn-back">Back</a>
    </div>

  </div>
</div>
</body>
</html>
