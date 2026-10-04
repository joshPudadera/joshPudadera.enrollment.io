<?php
session_start();
if (empty($_SESSION['enroll'])) $_SESSION['enroll'] = [];

$current_step = 2;
include __DIR__ . '/header.php';
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">How Are You Enrolling?</h2>
    <p style="text-align:center;font-size:.85rem;color:#666;margin-bottom:28px;">
      Select the category that best describes you.
    </p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;max-width:560px;margin:0 auto 32px;">

      <a href="branch.php?type=Freshman"
         onclick="setType('Freshman')"
         style="display:flex;flex-direction:column;align-items:center;gap:14px;
                background:#fff;border:2px solid #e5e7eb;border-radius:14px;
                padding:28px 20px;text-align:center;text-decoration:none;
                transition:border-color .15s,transform .15s,box-shadow .15s;"
         onmouseover="this.style.borderColor='#16a34a';this.style.transform='translateY(-3px)';this.style.boxShadow='0 6px 20px rgba(0,0,0,.1)';"
         onmouseout="this.style.borderColor='#e5e7eb';this.style.transform='';this.style.boxShadow='';">
        <div style="width:60px;height:60px;border-radius:50%;background:#dcfce7;
                    display:flex;align-items:center;justify-content:center;">
          <i class="fa-solid fa-graduation-cap" style="font-size:1.5rem;color:#16a34a;"></i>
        </div>
        <div>
          <div style="font-size:1rem;font-weight:700;color:#1a1a2e;">Freshman</div>
          <div style="font-size:.78rem;color:#888;margin-top:5px;line-height:1.5;">
            New college student enrolling for the first time.
          </div>
        </div>
      </a>

      <a href="branch.php?type=Senior+High"
         onclick="setType('Senior High')"
         style="display:flex;flex-direction:column;align-items:center;gap:14px;
                background:#fff;border:2px solid #e5e7eb;border-radius:14px;
                padding:28px 20px;text-align:center;text-decoration:none;
                transition:border-color .15s,transform .15s,box-shadow .15s;"
         onmouseover="this.style.borderColor='#2563eb';this.style.transform='translateY(-3px)';this.style.boxShadow='0 6px 20px rgba(0,0,0,.1)';"
         onmouseout="this.style.borderColor='#e5e7eb';this.style.transform='';this.style.boxShadow='';">
        <div style="width:60px;height:60px;border-radius:50%;background:#eff6ff;
                    display:flex;align-items:center;justify-content:center;">
          <i class="fa-solid fa-book-open" style="font-size:1.5rem;color:#2563eb;"></i>
        </div>
        <div>
          <div style="font-size:1rem;font-weight:700;color:#1a1a2e;">Senior High School</div>
          <div style="font-size:.78rem;color:#888;margin-top:5px;line-height:1.5;">
            Enrolling in Grade 11 or Grade 12 at BCP.
          </div>
        </div>
      </a>

    </div>

    <div class="enroll-actions">
      <a href="index.php" class="btn-back">Back</a>
    </div>

  </div>
</div>

<script>
function setType(type) {
    fetch('set_type.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'applicant_type=' + encodeURIComponent(type)
    });
}
</script>
</body>
</html>
