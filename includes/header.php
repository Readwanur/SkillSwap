<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Guest';
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
$is_admin = isset($_SESSION['is_admin']) ? $_SESSION['is_admin'] : false;

$time_credits = 0.00;
if (isset($conn) && $user_id > 0 && !$is_admin) {
    $wallet_res = $conn->query("SELECT balance FROM wallet WHERE user_id = $user_id");
    if ($wallet_res && $wallet_res->num_rows > 0) {
        $wallet_row = $wallet_res->fetch_assoc();
        $time_credits = $wallet_row['balance'];
    }
}
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

            <div class="nav-user" style="display:flex; align-items:center;">
                <?php if (!$is_admin && $user_id > 0): ?>
                    <form action="../pages/search_users.php" method="GET" style="display:inline-block; margin-right: 15px; position:relative;" id="headerSearchForm">
                        <input type="text" name="q" id="headerSearchInput" placeholder="Search users..." autocomplete="off" style="padding: 5px 10px; border-radius: 20px; border: 1px solid var(--border-color); font-size: 0.85rem; outline:none; background:var(--bg-secondary); color:var(--text-primary); width:180px;">
                        <div id="headerSearchSuggestions" style="position: absolute; top: 100%; left: 0; right: 0; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-top: 4px; z-index: 1000; display: none; max-height: 300px; overflow-y: auto;">
                        </div>
                    </form>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const searchInput = document.getElementById('headerSearchInput');
                            const suggestionsBox = document.getElementById('headerSearchSuggestions');
                            let timeout = null;

                            if (searchInput) {
                                searchInput.addEventListener('input', function() {
                                    clearTimeout(timeout);
                                    const query = this.value.trim();
                                    
                                    if (query.length < 2) {
                                        suggestionsBox.style.display = 'none';
                                        return;
                                    }

                                    timeout = setTimeout(() => {
                                        fetch(`../api/search_users.php?q=${encodeURIComponent(query)}`)
                                            .then(res => res.json())
                                            .then(data => {
                                                if (data.length > 0) {
                                                    let html = '';
                                                    data.forEach(user => {
                                                        html += `
                                                            <a href="../pages/user_profile.php?id=${user.id}" style="display: block; padding: 8px 10px; text-decoration: none; border-bottom: 1px solid var(--border-color); color: var(--text-primary);">
                                                                <div style="font-weight: 500; font-size: 0.9rem;">${user.name} <span class="badge" style="font-size:0.6rem; background:var(--bg-secondary); color:var(--text-secondary);">${user.badge}</span></div>
                                                                <div style="font-size: 0.75rem; color: var(--text-muted);">${user.location}</div>
                                                            </a>
                                                        `;
                                                    });
                                                    suggestionsBox.innerHTML = html;
                                                    suggestionsBox.style.display = 'block';
                                                } else {
                                                    suggestionsBox.innerHTML = '<div style="padding: 8px 10px; font-size: 0.85rem; color: var(--text-muted);">No users found</div>';
                                                    suggestionsBox.style.display = 'block';
                                                }
                                            })
                                            .catch(err => console.error(err));
                                    }, 300);
                                });

                                document.addEventListener('click', function(e) {
                                    if (!document.getElementById('headerSearchForm').contains(e.target)) {
                                        suggestionsBox.style.display = 'none';
                                    }
                                });
                            }
                        });
                    </script>
                <?php endif; ?>
                
                <div style="display: flex; flex-direction: column; align-items: flex-end; margin-right: 15px; line-height: 1.2;">
                    <span class="user-name" style="font-weight: 700; font-size: 1rem; color: var(--text-primary);"><?php echo htmlspecialchars($user_name); ?></span>
                    <?php if (!$is_admin && $user_id > 0): ?>
                        <span style="font-size: 0.75rem; color: var(--info); font-weight: 600;">
                            ⏱️ <?php echo number_format($time_credits, 2); ?> TC
                        </span>
                    <?php endif; ?>
                </div>

                <a href="../pages/logout.php" class="btn-logout"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Logout</a>
            </div>
        </div>
    </nav>