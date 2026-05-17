<?php
session_start();

if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
    header('Location: ./admin/dashboard.php');
} elseif (isset($_SESSION['user_id'])) {
    header('Location: ./pages/dashboard.php');
} else {
    header('Location: ./pages/login.php');
}
exit();
?>
