<?php
// OTP step removed — redirect straight to sign-in
session_start();
header('Location: signin.php');
exit;
