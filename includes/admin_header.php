<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$admin_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Admin';

// Greeting based on time
$hour = (int) date('H');
if ($hour < 12) $greeting = 'Good Morning';
elseif ($hour < 17) $greeting = 'Good Afternoon';
else $greeting = 'Good Evening';

// Page titles for breadcrumb
$page_titles = [
    'dashboard' => 'Dashboard',
    'users' => 'User Management',
    'skills' => 'Skill Taxonomy',
    'disputes' => 'Reports',
    'community_tasks' => 'Community Tasks',
    'analytics' => 'Analytics',
    'system_audit' => 'System Audit Logs',
    'transaction_simulator' => 'ACID Transaction Simulator',
    'stress_test' => 'Stress Test & Index Profiler',
    'formal_disputes' => 'Formal Disputes',
];
$breadcrumb_title = $page_titles[$current_page] ?? ucfirst($current_page);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SkillSwap Admin Panel">
    <title>SkillSwap Admin — <?php echo $breadcrumb_title; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>

    <nav class="navbar">
        <div class="container" style="max-width: 100%; padding: 0 30px;">
            <a href="../admin/dashboard.php" class="navbar-brand">
                <img src="../assets/skillswap.png" alt="SkillSwap Logo"><span
                    style="font-size:0.75rem; color:var(--text-muted); margin-left:6px;">ADMIN</span>
            </a>

            <div class="admin-nav-center">
                <span class="admin-greeting">
                    <?php echo $greeting; ?>, <strong><?php echo htmlspecialchars($admin_name); ?></strong>
                </span>
            </div>

            <div class="nav-user">
                <span class="admin-clock" id="admin-clock"></span>
                <a href="../pages/logout.php" class="btn-logout"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Logout</a>
            </div>
        </div>
    </nav>

    <!-- Admin Layout -->
    <div class="admin-layout">
        <aside class="sidebar">
            <div class="sidebar-section-label">NAVIGATION</div>
            <ul class="sidebar-menu">
                <li><a href="../admin/dashboard.php"
                        class="<?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                        <span class="sidebar-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span> Dashboard</a>
                </li>
                <li><a href="../admin/users.php"
                        class="<?php echo $current_page === 'users' ? 'active' : ''; ?>">
                        <span class="sidebar-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> Users</a>
                </li>
                <li><a href="../admin/skills.php"
                        class="<?php echo $current_page === 'skills' ? 'active' : ''; ?>">
                        <span class="sidebar-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg></span> Skills</a>
                </li>
                <li><a href="../admin/disputes.php"
                        class="<?php echo $current_page === 'disputes' ? 'active' : ''; ?>">
                        <span class="sidebar-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span> Reports</a>
                </li>
                <li><a href="../admin/community_tasks.php"
                        class="<?php echo $current_page === 'community_tasks' ? 'active' : ''; ?>">
                        <span class="sidebar-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span> Tasks</a>
                </li>
                <li><a href="../admin/analytics.php"
                        class="<?php echo $current_page === 'analytics' ? 'active' : ''; ?>">
                        <span class="sidebar-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span> Analytics</a>
                </li>
            </ul>

            <div class="sidebar-section-label" style="margin-top: 20px;">DBMS SHOWCASE</div>
            <ul class="sidebar-menu">
                <li><a href="../admin/system_audit.php"
                        class="<?php echo $current_page === 'system_audit' ? 'active' : ''; ?>">
                        <span class="sidebar-icon">🛡️</span> Audit Logs</a>
                </li>
                <li><a href="../admin/transaction_simulator.php"
                        class="<?php echo $current_page === 'transaction_simulator' ? 'active' : ''; ?>">
                        <span class="sidebar-icon">⚡</span> ACID Simulator</a>
                </li>
                <li><a href="../admin/stress_test.php"
                        class="<?php echo $current_page === 'stress_test' ? 'active' : ''; ?>">
                        <span class="sidebar-icon">🚀</span> Stress Test</a>
                </li>
                <li><a href="../admin/formal_disputes.php"
                        class="<?php echo $current_page === 'formal_disputes' ? 'active' : ''; ?>">
                        <span class="sidebar-icon">⚖️</span> Formal Disputes</a>
                </li>
            </ul>

            <div class="sidebar-section-label" style="margin-top: 20px;">QUICK LINKS</div>
            <ul class="sidebar-menu">
                <li><a href="../index.php" target="_blank" style="font-size:0.82rem;">
                        <span class="sidebar-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2-2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></span> View Site</a>
                </li>
            </ul>
        </aside>
        <main class="admin-content">

            <!-- Breadcrumb -->
            <div class="admin-breadcrumb">
                <a href="../admin/dashboard.php">Admin</a>
                <span class="breadcrumb-sep">&#x276F;</span>
                <span class="breadcrumb-current"><?php echo $breadcrumb_title; ?></span>
            </div>

<script>
// Live clock in navbar
function updateClock() {
    const now = new Date();
    const opts = { hour: '2-digit', minute: '2-digit', hour12: true };
    const el = document.getElementById('admin-clock');
    if (el) el.textContent = now.toLocaleTimeString([], opts);
}
updateClock();
setInterval(updateClock, 30000);
</script>