<?php
session_start();
// Clear any previous enrollment session data when starting fresh
$_SESSION['enroll'] = [];

$current_step = 1;
include __DIR__ . '/header.php';
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Admission Process</h2>

    <h3 class="enroll-section-heading">Fill in and submit an online application form</h3>
    <p>In the online application form, you have to submit personal information, academic results and a reference(s) from your teacher(s).</p>
    <p>Documents required for the application:</p>
    <p>Original copy of the following:</p>

    <div class="req-cols">

      <!-- Left column -->
      <div>
        <p class="enroll-section-heading">College Requirements</p>
        <ul>
          <li>Form 138 (Report Card)</li>
          <li>Form 137</li>
          <li>Certificate of Good Moral</li>
          <li>PSA Authenticated Birth Certificate</li>
          <li>Passport Size ID Picture (White Background, Formal Attire) - 2pcs.</li>
          <li>Barangay Clearance</li>
        </ul>

        <p class="enroll-section-heading">College Transferee Requirements</p>
        <ul>
          <li>Transcript of Records from Previous School</li>
          <li>Honorable Dismissal</li>
          <li>Certificate of Good Moral</li>
          <li>PSA Authenticated Birth Certificate</li>
          <li>Passport Size ID Picture (White Background, Formal Attire) - 2pcs.</li>
          <li>Barangay Clearance</li>
        </ul>
      </div>

      <!-- Right column -->
      <div>
        <p class="enroll-section-heading">Senior High School Requirements</p>
        <ul>
          <li>Form 138 (Report Card)</li>
          <li>Form 137</li>
          <li>Certificate of Good Moral</li>
          <li>2"x2" ID Picture (White Background) - 2pcs.</li>
          <li>Photocopy of NCAE Result</li>
          <li>ESC Certificate, if a graduate of a private Junior High School</li>
          <li>PSA Authenticated Birth Certificate</li>
          <li>Barangay Clearance</li>
          <li>Photocopy of Diploma</li>
        </ul>
      </div>

    </div>

    <div class="enroll-actions">
      <a href="branch.php" class="btn-proceed">
        Proceed to Application <i class="fa-solid fa-arrow-right"></i>
      </a>
    </div>

  </div>
</div>

</body>
</html>
