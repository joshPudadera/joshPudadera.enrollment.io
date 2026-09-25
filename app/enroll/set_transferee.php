<?php
// Saves transferee-specific session data then forwards to branch selection.
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: transferee_details.php'); exit; }

$valid_years = ['1st Year','2nd Year','3rd Year','4th Year'];
$yr  = trim($_POST['transfer_year_level']  ?? '');
$crs = trim($_POST['transfer_course']      ?? '');
$sch = trim($_POST['transfer_from_school'] ?? '');

if (!$yr || !in_array($yr, $valid_years) || !$crs) {
    header('Location: transferee_details.php?err=missing'); exit;
}

if (!isset($_SESSION['enroll'])) $_SESSION['enroll'] = [];
$_SESSION['enroll']['applicant_type']        = 'Transferee';
$_SESSION['enroll']['transfer_year_level']   = $yr;
$_SESSION['enroll']['transfer_course']       = $crs;
$_SESSION['enroll']['transfer_from_school']  = $sch;
// Pre-set course so course.php can pre-select it
$_SESSION['enroll']['course']                = $crs;

header('Location: branch.php');
exit;
