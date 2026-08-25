<?php
session_start();
$current_step = 1;
include __DIR__ . '/header.php';

$applicant = $_SESSION['enroll_applicant'] ?? null;
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Document Requirements</h2>

    <p style="font-size:.85rem;color:#555;line-height:1.7;margin-bottom:6px;">
      Upload the required documents below to complete your enrollment application.
      All files must be clear, legible, and in <strong>PDF, JPG, or PNG</strong> format.
      Maximum file size per document: <strong>5 MB</strong>.
    </p>

    <?php if ($applicant): ?>
    <div style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px;
                padding:12px 16px;margin:16px 0;font-size:.82rem;color:#1d4ed8;">
      <i class="fa-solid fa-circle-info"></i>
      Submitting for: <strong><?= htmlspecialchars($applicant) ?></strong>
    </div>
    <?php endif; ?>

    <div class="req-cols" style="margin-top:20px;">

      <div>
        <p class="enroll-section-heading">
          <i class="fa-solid fa-graduation-cap"></i> College Applicants
        </p>
        <ul>
          <li>Form 138 (Report Card)</li>
          <li>Form 137</li>
          <li>Certificate of Good Moral Character</li>
          <li>PSA Authenticated Birth Certificate</li>
          <li>2 pcs. Passport-size ID Photo (White Background, Formal Attire)</li>
          <li>Barangay Clearance</li>
        </ul>

        <p class="enroll-section-heading" style="margin-top:20px;">
          <i class="fa-solid fa-right-left"></i> College Transferees
        </p>
        <ul>
          <li>Transcript of Records from Previous School</li>
          <li>Honorable Dismissal</li>
          <li>Certificate of Good Moral Character</li>
          <li>PSA Authenticated Birth Certificate</li>
          <li>2 pcs. Passport-size ID Photo (White Background, Formal Attire)</li>
          <li>Barangay Clearance</li>
        </ul>
      </div>

      <div>
        <p class="enroll-section-heading">
          <i class="fa-solid fa-school"></i> Senior High School Applicants
        </p>
        <ul>
          <li>Form 138 (Report Card)</li>
          <li>Form 137</li>
          <li>Certificate of Good Moral Character</li>
          <li>2"x2" ID Photo (White Background) — 2 pcs.</li>
          <li>Photocopy of NCAE Result</li>
          <li>ESC Certificate (if from private Junior High School)</li>
          <li>PSA Authenticated Birth Certificate</li>
          <li>Barangay Clearance</li>
          <li>Photocopy of Diploma</li>
        </ul>
      </div>

    </div>

    <div style="background:#fff7ed;border:1px solid #fcd34d;border-radius:8px;
                padding:12px 16px;margin-top:20px;font-size:.8rem;color:#92400e;">
      <i class="fa-solid fa-triangle-exclamation"></i>
      Incomplete submissions will delay your enrollment. Please prepare all documents before proceeding.
    </div>

    <div class="enroll-actions">
      <a href="../dashboard/dashboard.php" class="btn-back">
        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
      </a>
      <a href="upload.php" class="btn-proceed">
        Upload Documents <i class="fa-solid fa-arrow-right"></i>
      </a>
    </div>

  </div>
</div>

</body>
</html>
