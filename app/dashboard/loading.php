<?php
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/signin.php'); exit;
}
if (!empty($_SESSION['must_set_password'])) {
    header('Location: ../auth/set_password.php'); exit;
}
$role = $_SESSION['role'] ?? 'student';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Loading – BCP Student Portal</title>
  <link rel="stylesheet" href="../css/loading.css"/>
</head>
<body>
  <img class="bcp-logo" src="../images/BCP_LOGO.png" alt="BCP Logo"/>
  <p class="welcome">Magandang Buhay BCPian!</p>
  <p class="wait">Please wait<span class="dots"><span></span><span></span><span></span></span></p>
  <script>
    setTimeout(function () {
      window.location.href = '<?= $role === 'admin'
        ? '../admin_dashboard/dashboard.php'
        : '../student_dashboard/dashboard.php' ?>';
    }, 2500);
  </script>
</body>
</html>
