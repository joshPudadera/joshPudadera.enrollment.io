<?php
require_once __DIR__ . '/../shared/db.php';
session_start();
if (empty($_SESSION['user_id'])) { header('Location: ../auth/signin.php'); exit; }

$APP_ROOT   = '../';
$ACTIVE_NAV = 'subjects';
$PAGE_TITLE = 'Subjects';
$PAGE_ICON  = 'fa-solid fa-book';

// Sample subject list — replace with DB query when subjects table exists
$subjects = [
    ['CS 101',  'Introduction to Computing',        '3 units', '1st Year', 'Lec'],
    ['CS 102',  'Computer Programming 1',            '3 units', '1st Year', 'Lab'],
    ['CS 201',  'Data Structures & Algorithms',      '3 units', '2nd Year', 'Lec'],
    ['CS 202',  'Object-Oriented Programming',       '3 units', '2nd Year', 'Lab'],
    ['IT 301',  'Web Systems & Technologies',        '3 units', '3rd Year', 'Lec/Lab'],
    ['IT 302',  'Database Management Systems',       '3 units', '3rd Year', 'Lec/Lab'],
    ['IT 401',  'Systems Integration & Architecture','3 units', '4th Year', 'Lec'],
    ['IT 402',  'Capstone Project 1',                '3 units', '4th Year', 'Lab'],
    ['GE 001',  'Understanding the Self',            '3 units', 'All',      'Lec'],
    ['GE 002',  'The Contemporary World',            '3 units', 'All',      'Lec'],
    ['PE 001',  'Physical Education 1',              '2 units', '1st Year', 'Lec'],
    ['NSTP 1',  'National Service Training Program', '3 units', '1st Year', 'Lec'],
];

ob_start();
?>
<div class="crud-card">
  <div class="crud-header">
    <h3>Subject List</h3>
    <span style="font-size:0.78rem;color:#888;"><?= count($subjects) ?> subjects</span>
  </div>
  <table class="crud-table">
    <thead>
      <tr><th>Code</th><th>Subject Name</th><th>Units</th><th>Year Level</th><th>Type</th></tr>
    </thead>
    <tbody>
      <?php foreach ($subjects as [$code, $name, $units, $year, $type]): ?>
      <tr>
        <td><strong style="color:#2563eb;"><?= $code ?></strong></td>
        <td><?= $name ?></td>
        <td style="text-align:center;"><?= $units ?></td>
        <td style="font-size:0.78rem;color:#666;"><?= $year ?></td>
        <td><span class="badge-active" style="font-size:0.68rem;"><?= $type ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../shared/page_template.php';
