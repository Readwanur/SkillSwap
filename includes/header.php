<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Guest';
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
$is_admin = isset($_SESSION['is_admin']) ? $_SESSION['is_admin'] : false;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description"
        content="SkillSwap - Exchange skills, earn time credits. A community-driven skill sharing platform.">
    <title>SkillSwap<?php echo isset($page_title) ? ' — ' . $page_title : ''; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>

    <!-- Navbar -->
    <nav class="navbar">
        <div class="container">
            <a href="../pages/dashboard.php" class="navbar-brand">
                <img src="../assets/finalLogo.png" alt="SkillSwap Logo">
            </a>

            <button class="nav-toggle"
                onclick="document.querySelector('.nav-links').classList.toggle('show')">&#9776;</button>

            <?php if (!$is_admin): ?>
                <ul class="nav-links">
                    <li><a href="../pages/dashboard.php"
                            class="<?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">Dashboard</a></li>
                    <li><a href="../pages/skills.php"
                            class="<?php echo $current_page == 'skills' ? 'active' : ''; ?>">Skills</a></li>
                    <li><a href="../pages/sessions.php"
                            class="<?php echo $current_page == 'sessions' ? 'active' : ''; ?>">Sessions</a></li>
                    <li><a href="../pages/wallet.php"
                            class="<?php echo $current_page == 'wallet' ? 'active' : ''; ?>">Wallet</a></li>
                    <li><a href="../pages/community_tasks.php"
                            class="<?php echo $current_page == 'community_tasks' ? 'active' : ''; ?>">Community</a></li>
                    <li><a href="../pages/profile.php"
                            class="<?php echo $current_page == 'profile' ? 'active' : ''; ?>">Profile</a></li>
                </ul>
            <?php endif; ?>

            <div class="nav-user">
                <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                <a href="../pages/logout.php" class="btn-logout">Logout</a>
            </div>
        </div>
    </nav>