<?php
// ── Role-based router ─────────────────────────────────────────
// This file exists for backward compatibility.
// It immediately redirects to the correct role-specific dashboard.
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php'); exit;
}
if (!empty($_SESSION['must_set_password'])) {
    header('Location: ../auth/set_password.php'); exit;
}
if ($_SESSION['role'] === 'admin') {
    header('Location: ../admin_dashboard/dashboard.php');
} else {
    header('Location: ../student_dashboard/dashboard.php');
}
exit;
