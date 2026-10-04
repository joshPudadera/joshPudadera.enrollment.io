<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['enroll'] = array_merge($_SESSION['enroll'] ?? [], $_POST);
}
$d = $_SESSION['enroll'] ?? [];
if (empty($d['first_name'])) { header('Location: form.php'); exit; }

$current_step = 6;
include __DIR__ . '/header.php';

// Helper: print a value or em-dash
function rv($v) { return htmlspecialchars(trim((string)$v) ?: '—'); }

$groups = [
    'Enrollment Details' => [
        'Applicant Type'    => $d['applicant_type']  ?? '',
        'Student Category'  => $d['student_type']    ?? '',
        'Campus'            => $d['branch']           ?? '',
        'Program'           => $d['course']           ?? '',
    ],
    'Student Information' => [
        'Last Name'         => $d['last_name']        ?? '',
        'First Name'        => $d['first_name']       ?? '',
        'Middle Name'       => $d['middle_name']      ?? '',
        'Suffix'            => $d['suffix']           ?? '',
        'Date of Birth'     => $d['birthday']         ?? '',
        'Sex'               => $d['sex']              ?? '',
        'Civil Status'      => $d['civil_status']     ?? '',
        'Nationality'       => $d['nationality']      ?? '',
        'Religion'          => $d['religion']         ?? '',
        'Place of Birth'    => $d['place_of_birth']   ?? '',
        'Email'             => $d['email']            ?? '',
        'Mobile'            => $d['phone']            ?? '',
        'Address'           => $d['address']          ?? '',
    ],
    'Parent / Guardian Information' => [
        "Father's Name"     => trim(($d['father_first_name']??'').' '.($d['father_last_name']??'')),
        "Father's Occupation"=> $d['father_occupation'] ?? '',
        "Father's Contact"  => $d['father_phone']     ?? '',
        "Mother's Name"     => trim(($d['mother_first_name']??'').' '.($d['mother_last_name']??'')),
        "Mother's Occupation"=> $d['mother_occupation'] ?? '',
        "Mother's Contact"  => $d['mother_phone']     ?? '',
        'Guardian'          => $d['guardian_name']    ?? '',
        'Guardian Relation' => $d['guardian_relation'] ?? '',
        'Guardian Contact'  => $d['guardian_phone']   ?? '',
    ],
    'School Background' => [
        'Previous School'   => $d['prev_school']      ?? '',
        'School Address'    => $d['prev_school_address'] ?? '',
        'Last Year Level'   => $d['last_year_level']  ?? '',
        'Year Graduated'    => $d['grad_year']         ?? '',
        'General Average'   => $d['general_average']  ?? '',
        'Honors / Awards'   => $d['honors']           ?? '',
    ],
    'Emergency Contact' => [
        'Contact Person'    => $d['emergency_name']   ?? '',
        'Relationship'      => $d['emergency_relation'] ?? '',
        'Contact Number'    => $d['emergency_phone']  ?? '',
    ],
];
?>

<div class="enroll-body">
<div class="enroll-card">

  <h2 class="enroll-card-title">Review Your Application</h2>
  <p style="text-align:center;font-size:.82rem;color:#666;margin-bottom:24px;">
    Verify all details before submitting. Click Back to make changes.
  </p>

  <?php foreach ($groups as $heading => $fields): ?>
  <div style="margin-bottom:20px;">
    <div style="font-size:.7rem;font-weight:700;color:#1a3a8c;text-transform:uppercase;
                letter-spacing:.06em;padding:6px 0 6px;border-bottom:2px solid #1a3a8c;
                margin-bottom:2px;">
      <?= htmlspecialchars($heading) ?>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
      <?php foreach ($fields as $label => $value):
        $val = trim((string)$value);
        if ($val === '' || $val === '—') continue;
      ?>
      <tr style="border-bottom:1px solid #f0f2f5;">
        <td style="padding:7px 10px 7px 0;font-weight:700;color:#1a1a2e;width:36%;white-space:nowrap;vertical-align:top;">
          <?= htmlspecialchars($label) ?>
        </td>
        <td style="padding:7px 0;color:#1a1a2e;">
          <?= htmlspecialchars($val) ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endforeach; ?>

  <div style="background:#fff7ed;border:1px solid #fcd34d;border-radius:8px;
              padding:11px 15px;margin-top:8px;font-size:.8rem;color:#92400e;">
    By submitting, you confirm that all information provided is accurate and complete.
  </div>

  <div class="enroll-actions" style="margin-top:24px;">
    <a href="form.php" class="btn-back">Back</a>
    <form method="POST" action="submit.php" style="display:inline;">
      <button type="submit" class="btn-proceed">Submit Application</button>
    </form>
  </div>

</div>
</div>
</body>
</html>
