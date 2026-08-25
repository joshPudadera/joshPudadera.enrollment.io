<?php
$current_step = $current_step ?? 1;
$steps = [
    1 => 'Requirements List',
    2 => 'Upload Documents',
    3 => 'Review Status',
    4 => 'Submit',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($steps[$current_step] ?? 'Requirements') ?> – BCP</title>
  <link rel="stylesheet" href="../enroll/enroll.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>

<div class="enroll-header">
  <div class="enroll-header-top">
    <img src="../images/BCP_LOGO.png" alt="BCP Logo"/>
    <span class="enroll-school-name">Bestlink College Of The Philippines</span>
  </div>
  <div class="enroll-page-title">Requirements Submission</div>
</div>

<div class="step-bar-wrap">
  <div class="step-bar">
    <?php foreach ($steps as $n => $label):
      $cls = $n < $current_step ? 'done' : ($n === $current_step ? 'active' : '');
    ?>
    <div class="step <?= $cls ?>">
      <div class="step-circle">
        <?php if ($n < $current_step): ?>
          <i class="fa-solid fa-check" style="font-size:.75rem;"></i>
        <?php else: ?>
          <?= $n ?>
        <?php endif; ?>
      </div>
      <span class="step-label"><?= $label ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
