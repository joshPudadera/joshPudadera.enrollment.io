<?php
// Lightweight endpoint to persist applicant_type to session.
// Called via fetch() from applicant_type.php before navigation.
session_start();
$valid = ['Freshman', 'Senior High', 'Octoberian', 'Transferee'];
$type  = trim($_POST['applicant_type'] ?? $_GET['type'] ?? '');
if (in_array($type, $valid)) {
    if (!isset($_SESSION['enroll'])) $_SESSION['enroll'] = [];
    $_SESSION['enroll']['applicant_type']      = $type;
    $_SESSION['enroll']['transfer_year_level'] = '';   // reset until transferee_details sets it
}
echo 'ok';
