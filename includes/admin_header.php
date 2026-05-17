<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$admin_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Admin';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SkillSwap Admin Panel">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>

    <!-- Admin Navbar -->
    <nav class="navbar">
        <div class="container">
            <a href="../admin/dashboard.php" class="navbar-brand">
                <img src="../assets/finalLogo.png" alt="SkillSwap Logo"><span
                    style="font-size:0.75rem; color:var(--text-muted); margin-left:6px;">ADMIN</span>
            </a>
            <div class="nav-user">
                <span class="user-name"><?php echo htmlspecialchars($admin_name); ?></span>
                <a href="../pages/logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Admin Layout -->
    <div class="admin-layout">
        <aside class="sidebar">
            <ul class="sidebar-menu">
                <li><a href="../admin/dashboard.php"
                        class="<?php echo $current_page === 'dashboard' ? 'active' : ''; ?>"> Dashboard</a>
                </li>
                <li><a href="../admin/skills.php"
                        class="<?php echo $current_page === 'skills' ? 'active' : ''; ?>"> Skills Management</a>
                </li>
                <li><a href="../admin/users.php"
                        class="<?php echo $current_page === 'users' ? 'active' : ''; ?>"> Users Management</a></li>
                <li><a href="../admin/disputes.php"
                        class="<?php echo $current_page === 'disputes' ? 'active' : ''; ?>"> Reports</a></li>
                <li><a href="../admin/analytics.php"
                        class="<?php echo $current_page === 'analytics' ? 'active' : ''; ?>"> Analytics</a>
                </li>
            </ul>
        </aside>
        <main class="admin-content">