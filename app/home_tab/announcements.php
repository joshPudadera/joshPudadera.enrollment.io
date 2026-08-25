<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'home';
$PAGE_TITLE = 'Announcements';
$PAGE_ICON  = 'fa-solid fa-bullhorn';

$page_content = '
<div class="form-card">
  <h3>Latest Announcements</h3>
  <p style="font-size:0.82rem;color:#888;margin-bottom:20px;">
    Stay updated with the latest news and notices from BCP Student Portal.
  </p>

  <div style="display:flex;flex-direction:column;gap:14px;">

    <div style="background:#eff6ff;border-left:4px solid #2563eb;border-radius:8px;padding:16px 18px;">
      <div style="font-size:0.72rem;color:#2563eb;font-weight:700;text-transform:uppercase;margin-bottom:4px;">
        <i class="fa-solid fa-circle-info"></i> System Notice
      </div>
      <div style="font-size:0.88rem;font-weight:700;color:#1a1a2e;margin-bottom:4px;">
        Enrollment for A.Y. 2025-2026 is now open
      </div>
      <div style="font-size:0.8rem;color:#555;line-height:1.6;">
        Online pre-registration is now available. Submit your application through the
        Enrollment module and upload the required documents.
      </div>
      <div style="font-size:0.72rem;color:#aaa;margin-top:8px;">Posted: July 1, 2025</div>
    </div>

    <div style="background:#f0fdf4;border-left:4px solid #22c55e;border-radius:8px;padding:16px 18px;">
      <div style="font-size:0.72rem;color:#16a34a;font-weight:700;text-transform:uppercase;margin-bottom:4px;">
        <i class="fa-solid fa-calendar-check"></i> Event
      </div>
      <div style="font-size:0.88rem;font-weight:700;color:#1a1a2e;margin-bottom:4px;">
        Orientation Day — August 5, 2025
      </div>
      <div style="font-size:0.8rem;color:#555;line-height:1.6;">
        All new and returning students are required to attend the school orientation.
        Venue: BCP Main Gymnasium, 8:00 AM.
      </div>
      <div style="font-size:0.72rem;color:#aaa;margin-top:8px;">Posted: June 28, 2025</div>
    </div>

    <div style="background:#fff7ed;border-left:4px solid #f59e0b;border-radius:8px;padding:16px 18px;">
      <div style="font-size:0.72rem;color:#d97706;font-weight:700;text-transform:uppercase;margin-bottom:4px;">
        <i class="fa-solid fa-triangle-exclamation"></i> Reminder
      </div>
      <div style="font-size:0.88rem;font-weight:700;color:#1a1a2e;margin-bottom:4px;">
        Deadline for document submission: July 31, 2025
      </div>
      <div style="font-size:0.8rem;color:#555;line-height:1.6;">
        All applicants must complete document uploads before the deadline to avoid
        delays in enrollment processing.
      </div>
      <div style="font-size:0.72rem;color:#aaa;margin-top:8px;">Posted: June 25, 2025</div>
    </div>

  </div>
</div>';

require_once __DIR__ . '/../shared/page_template.php';
