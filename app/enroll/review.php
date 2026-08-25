<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Store form data in session
    $_SESSION['enroll'] = array_merge($_SESSION['enroll'] ?? [], $_POST);
}
$d = $_SESSION['enroll'] ?? [];
if (empty($d['first_name'])) { header('Location: form.php'); exit; }

$current_step = 5;
include __DIR__ . '/header.php';

$rows = [
    'Branch / Campus'       => $d['branch']            ?? '—',
    'Program'               => $d['course']             ?? '—',
    'Last Name'             => $d['last_name']          ?? '—',
    'First Name'            => $d['first_name']         ?? '—',
    'Middle Name'           => $d['middle_name']        ?? '—',
    'Suffix'                => $d['suffix']             ?? '—',
    'Date of Birth'         => $d['birthday']           ?? '—',
    'Sex'                   => $d['sex']                ?? '—',
    'Civil Status'          => $d['civil_status']       ?? '—',
    'Nationality'           => $d['nationality']        ?? '—',
    'Religion'              => $d['religion']           ?? '—',
    'Place of Birth'        => $d['place_of_birth']     ?? '—',
    'Email'                 => $d['email']              ?? '—',
    'Mobile'                => $d['phone']              ?? '—',
    'Address'               => $d['address']            ?? '—',
    'Previous School'       => $d['prev_school']        ?? '—',
    'Last Year Level'       => $d['last_year_level']    ?? '—',
    'Year Graduated'        => $d['grad_year']          ?? '—',
    'Emergency Contact'     => $d['emergency_name']     ?? '—',
    'Relationship'          => $d['emergency_relation'] ?? '—',
    'Emergency Number'      => $d['emergency_phone']    ?? '—',
];
?>

<div class="enroll-body">
  <div class="enroll-card">

    <h2 class="enroll-card-title">Review Your Application</h2>
    <p style="text-align:center;font-size:.82rem;color:#666;margin-bottom:24px;">
      Please verify all information before submitting. Click <strong>Back</strong> to make changes.
    </p>

    <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
      <?php foreach ($rows as $label => $value): ?>
      <tr style="border-bottom:1px solid #f0f2f5;">
        <td style="padding:9px 12px;color:#888;font-weight:600;width:38%;white-space:nowrap;">
          <?= htmlspecialchars($label) ?>
        </td>
        <td style="padding:9px 12px;color:#1a1a2e;">
          <?= htmlspecialchars($value) ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <div style="background:#fff7ed;border:1px solid #fcd34d;border-radius:8px;
                padding:12px 16px;margin-top:20px;font-size:.8rem;color:#92400e;">
      <i class="fa-solid fa-triangle-exclamation"></i>
      By submitting, you confirm that all information provided is accurate and complete.
    </div>

    <div class="enroll-actions">
      <a href="form.php" class="btn-back">
        <i class="fa-solid fa-arrow-left"></i> Back
      </a>
      <form method="POST" action="submit.php" style="display:inline;">
        <button type="submit" class="btn-proceed">
          Submit Application <i class="fa-solid fa-paper-plane"></i>
        </button>
      </form>
    </div>

  </div>
</div>

</body>
</html>
