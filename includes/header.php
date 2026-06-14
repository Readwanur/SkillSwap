<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Guest';
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
$is_admin = isset($_SESSION['is_admin']) ? $_SESSION['is_admin'] : false;

$time_credits = 0.00;
$unread_msg_count = 0;
if (isset($conn) && $user_id > 0 && !$is_admin) {
    $wallet_res = $conn->query("SELECT balance FROM wallet WHERE user_id = $user_id");
    if ($wallet_res && $wallet_res->num_rows > 0) {
        $wallet_row = $wallet_res->fetch_assoc();
        $time_credits = $wallet_row['balance'];
    }

    // Count unread chat messages
    $unread_msg_res = $conn->query("
        SELECT COUNT(*) AS count 
        FROM messages m
        INNER JOIN conversation_members cm ON m.conversation_id = cm.conversation_id
        WHERE cm.user_id = $user_id 
          AND m.sender_id != $user_id 
          AND m.is_read = 0
    ");
    if ($unread_msg_res && $unread_msg_res->num_rows > 0) {
        $unread_msg_count = intval($unread_msg_res->fetch_assoc()['count']);
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
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo time(); ?>">
    <script>
        // Check local storage for theme preference
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    </script>
</head>

<body>

    <!-- Preloader -->
    <div id="page-preloader" class="preloader" style="opacity: 0; visibility: hidden;">
        <div class="preloader-content">
            <img src="../assets/loading.png" alt="SkillSwap Loading" class="preloader-logo">
            <div class="preloader-progress"><div class="preloader-progress-bar"></div></div>
        </div>
    </div>
    <script>
        window.preloaderTimer = setTimeout(function() {
            var p = document.getElementById("page-preloader");
            if (p) {
                p.style.visibility = "visible";
                p.style.opacity = "1";
            }
        }, 300);
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const themeBtn = document.getElementById('themeToggleBtn');
            const themeIcon = document.getElementById('themeIcon');
            
            // Init icon based on current theme
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            if (currentTheme === 'dark' && themeIcon) {
                themeIcon.setAttribute('data-lucide', 'sun');
            }

            if (themeBtn) {
                themeBtn.addEventListener('click', function() {
                    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                    if (isDark) {
                        document.documentElement.removeAttribute('data-theme');
                        localStorage.setItem('theme', 'light');
                    } else {
                        document.documentElement.setAttribute('data-theme', 'dark');
                        localStorage.setItem('theme', 'dark');
                    }
                });
            }
        });
    </script>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
    </script>

    <!-- Navbar -->
    <nav class="navbar">
        <div class="container" style="max-width: 100%; padding: 0 30px;">
            <a href="../pages/dashboard.php" class="navbar-brand">
                <img src="../assets/loading.png" alt="SkillSwap Logo">
            </a>

            <button class="nav-toggle"
                onclick="document.querySelector('.nav-links').classList.toggle('show')">&#9776;</button>

            <?php if (!$is_admin): ?>
                <div class="nav-menu-group" style="display:flex; align-items:center; gap: 15px;">
                    <ul class="nav-links">
                        <li><a href="../pages/dashboard.php"
                                class="<?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">Dashboard</a></li>
                        <li class="nav-item-dropdown">
                            <a href="#"
                                class="<?php echo ($current_page == 'skills' || $current_page == 'smart_matches' || $current_page == 'market_trends') ? 'active' : ''; ?>">Browse ▾</a>
                            <ul class="nav-dropdown-menu">
                                <li><a href="../pages/skills.php"
                                        class="<?php echo $current_page == 'skills' ? 'active' : ''; ?>">Skills</a></li>
                                <li><a href="../pages/smart_matches.php"
                                        class="<?php echo $current_page == 'smart_matches' ? 'active' : ''; ?>">Smart Matches</a></li>
                                <li><a href="../pages/market_trends.php"
                                        class="<?php echo $current_page == 'market_trends' ? 'active' : ''; ?>">Insights</a></li>
                            </ul>
                        </li>
                        <li><a href="../pages/sessions.php"
                                class="<?php echo $current_page == 'sessions' ? 'active' : ''; ?>">Sessions</a></li>
                        <li><a href="../pages/wallet.php"
                                class="<?php echo $current_page == 'wallet' ? 'active' : ''; ?>">Wallet</a></li>
                        <li class="nav-item-dropdown">
                            <a href="#"
                                class="<?php echo ($current_page == 'community_tasks' || $current_page == 'leaderboard') ? 'active' : ''; ?>">Activity ▾</a>
                            <ul class="nav-dropdown-menu">
                                <li><a href="../pages/community_tasks.php"
                                        class="<?php echo $current_page == 'community_tasks' ? 'active' : ''; ?>">Community Tasks</a></li>
                                <li><a href="../pages/leaderboard.php"
                                        class="<?php echo $current_page == 'leaderboard' ? 'active' : ''; ?>">Leaderboard</a></li>
                            </ul>
                        </li>
                        <li><a href="../pages/profile.php"
                                class="<?php echo $current_page == 'profile' ? 'active' : ''; ?>">Profile</a></li>
                    </ul>
                    <?php if ($user_id > 0): ?>
                        <div style="display:inline-block; position:relative; margin-right: 15px; margin-left: 10px;">
                            <form action="../pages/search_users.php" method="GET" id="headerSearchForm" style="position:relative; display:flex; align-items:center;">
                                <i data-lucide="search" style="position:absolute; left:14px; color:var(--text-muted); width:16px; height:16px; z-index:2; pointer-events:none;"></i>
                                <input type="text" name="q" id="headerSearchInput" placeholder="Find mentors & learners..." autocomplete="off">
                                
                                <div id="headerSearchSuggestions">
                                </div>
                            </form>
                        </div>
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
                                            suggestionsBox.style.opacity = '0';
                                            suggestionsBox.style.transform = 'translateY(-10px)';
                                            setTimeout(() => { if (searchInput.value.trim().length < 2) suggestionsBox.style.display = 'none'; }, 300);
                                            return;
                                        }

                                        timeout = setTimeout(() => {
                                            fetch(`../api/search_users.php?q=${encodeURIComponent(query)}`)
                                                .then(res => res.json())
                                                .then(data => {
                                                    if (data.length > 0) {
                                                        let html = '<div style="padding: 10px 16px; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border-light);">Top Matches</div>';
                                                        data.forEach(user => {
                                                            let initial = user.name.charAt(0).toUpperCase();
                                                            let avatarHtml = user.has_photo 
                                                                ? `<img src="../api/user_photo.php?user_id=${user.id}" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid rgba(0, 56, 108, 0.1); flex-shrink: 0;" alt="Avatar">`
                                                                : `<div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(0, 56, 108, 0.06); color: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; border: 1px solid rgba(0, 56, 108, 0.1); flex-shrink: 0;">${initial}</div>`;
                                                            html += `
                                                                <a href="../pages/user_profile.php?id=${user.id}" style="display: flex; align-items: center; gap: 14px; padding: 12px 16px; text-decoration: none; border-bottom: 1px solid var(--border-light); transition: background 0.2s ease;" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background='transparent'">
                                                                    ${avatarHtml}
                                                                    <div style="flex: 1; min-width: 0;">
                                                                        <div style="font-weight: 600; font-size: 0.95rem; color: var(--text-primary); margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${user.name}</div>
                                                                        <div style="font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; gap: 8px;">
                                                                            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${user.location || 'Anywhere'}</span>
                                                                            <span class="badge" style="font-size:0.65rem; background:rgba(243, 185, 34, 0.15); color:var(--secondary-dark); padding: 2px 6px;">${user.badge}</span>
                                                                        </div>
                                                                    </div>
                                                                </a>
                                                            `;
                                                        });
                                                        suggestionsBox.innerHTML = html;
                                                    } else {
                                                        suggestionsBox.innerHTML = '<div style="padding: 24px 16px; text-align: center; color: var(--text-muted);"><i data-lucide="search-x" style="width: 32px; height: 32px; opacity: 0.5; margin-bottom: 12px;"></i><br><span style="font-size:0.95rem; font-weight:500;">No users found</span><br><span style="font-size:0.8rem;">Try a different name or location</span></div>';
                                                    }
                                                    
                                                    if (window.lucide) lucide.createIcons();
                                                    suggestionsBox.style.display = 'block';
                                                    // Trigger reflow
                                                    void suggestionsBox.offsetWidth;
                                                    suggestionsBox.style.opacity = '1';
                                                    suggestionsBox.style.transform = 'translateY(0)';
                                                })
                                                .catch(err => console.error(err));
                                        }, 250);
                                    });

                                    document.addEventListener('click', function(e) {
                                        if (!document.getElementById('headerSearchForm').contains(e.target)) {
                                            suggestionsBox.style.opacity = '0';
                                            suggestionsBox.style.transform = 'translateY(-10px)';
                                            setTimeout(() => { suggestionsBox.style.display = 'none'; }, 300);
                                        }
                                    });
                                }
                            });
                        </script>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="nav-user" style="display:flex; align-items:center;">
                <!-- Theme Toggle -->
                <div id="themeToggleBtn" class="theme-toggle-pill" role="button" tabindex="0" title="Toggle Dark Mode">
                    <div class="theme-toggle-thumb"></div>
                    <div class="theme-toggle-icons">
                        <i data-lucide="moon" class="moon-icon"></i>
                        <i data-lucide="sun" class="sun-icon"></i>
                    </div>
                </div>

                <?php if ($user_id > 0 && !$is_admin): ?>
                <!-- Messages Icon -->
                <a href="../pages/messages.php" class="notif-bell-wrapper" style="position: relative; margin-right: 15px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; padding: 5px; color: var(--primary); transition: var(--transition);">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2C6.48 2 2 6.13 2 11.23C2 14.12 3.53 16.69 5.86 18.31V22L9.36 19.98C10.2 20.25 11.08 20.41 12 20.41C17.52 20.41 22 16.29 22 11.23C22 6.13 17.52 2 12 2ZM13.06 14.28L10.74 11.75L6.2 14.28L11.16 8.75L13.56 11.21L17.96 8.75L13.06 14.28Z"/>
                    </svg>
                    <?php if ($unread_msg_count > 0): ?>
                        <span style="position: absolute; top: -2px; right: -2px; background: var(--danger); color: #ffffff; font-size: 0.65rem; font-weight: bold; border-radius: 50%; width: 16px; height: 16px; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);"><?php echo $unread_msg_count; ?></span>
                    <?php endif; ?>
                </a>

                <!-- Notification Bell -->
                <div class="notif-bell-wrapper" style="position: relative; margin-right: 18px; display: inline-block;">
                    <button id="notifBellBtn" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; padding: 5px; position: relative; color: var(--text-secondary); transition: var(--transition); display: flex; align-items: center; justify-content: center; outline: none;">
                        <i data-lucide="bell" class="lucide-sm"></i>
                        <span id="notifCountBadge" style="position: absolute; top: -2px; right: -2px; background: var(--danger); color: #ffffff; font-size: 0.65rem; font-weight: bold; border-radius: 50%; width: 16px; height: 16px; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm); display: none;">0</span>
                    </button>
                    <!-- Dropdown Content -->
                    <div id="notifDropdown" style="display: none; position: absolute; top: 120%; right: -50px; width: 320px; background: var(--bg-glass); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); z-index: 2001; overflow: hidden;">
                        <div style="padding: 12px 16px; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center; background: rgba(0,0,0,0.02);">
                            <span style="font-family: var(--font-headline); font-weight: 700; font-size: 0.9rem; color: var(--primary);">Notifications</span>
                            <button id="notifMarkAllRead" style="background: none; border: none; color: var(--info); font-size: 0.75rem; font-weight: 600; cursor: pointer; padding: 2px 5px; border-radius: var(--radius-sm); transition: var(--transition);">Mark all read</button>
                        </div>
                        <div id="notifItemsList" style="max-height: 280px; overflow-y: auto;">
                            <div style="padding: 20px; text-align: center; color: var(--text-muted); font-size: 0.85rem;">No new notifications</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div style="display: flex; flex-direction: column; align-items: flex-end; margin-right: 15px; line-height: 1.2;">
                    <span class="user-name" style="font-weight: 700; font-size: 1rem; color: var(--text-primary);"><?php echo htmlspecialchars($user_name); ?></span>
                    <?php if (!$is_admin && $user_id > 0): ?>
                        <span style="font-size: 0.75rem; color: var(--info); font-weight: 600;">
                            <i data-lucide="clock" class="lucide-sm"></i> <?php echo number_format($time_credits, 2); ?> TC
                        </span>
                    <?php endif; ?>
                </div>

                <div class="glass-button-wrap">
                    <a href="../pages/logout.php" class="glass-button">
                        <span class="glass-button-text">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Logout
                        </span>
                    </a>
                    <div class="glass-button-shadow"></div>
                </div>
            </div>
        </div>
    </nav>

    <?php if ($user_id > 0 && !$is_admin): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const notifBellBtn = document.getElementById('notifBellBtn');
        const notifDropdown = document.getElementById('notifDropdown');
        const notifCountBadge = document.getElementById('notifCountBadge');
        const notifItemsList = document.getElementById('notifItemsList');
        const notifMarkAllRead = document.getElementById('notifMarkAllRead');

        if (!notifBellBtn) return;

        // Toggle dropdown
        notifBellBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const show = notifDropdown.style.display === 'block';
            notifDropdown.style.display = show ? 'none' : 'block';
            if (!show) {
                fetchNotifications();
            }
        });

        // Close when clicking outside
        document.addEventListener('click', function(e) {
            if (!notifDropdown.contains(e.target) && e.target !== notifBellBtn) {
                notifDropdown.style.display = 'none';
            }
        });

        // Mark single notification as read
        notifItemsList.addEventListener('click', function(e) {
            const item = e.target.closest('.notif-item');
            if (item) {
                const notifId = item.getAttribute('data-id');
                const link = item.getAttribute('data-link');
                markAsRead(notifId, item, link);
            }
        });

        // Mark all read
        notifMarkAllRead.addEventListener('click', function(e) {
            e.stopPropagation();
            markAllRead();
        });

        function fetchNotifications() {
            fetch('../api/notifications.php?unread_only=1')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        updateBadge(data.unread_count);
                        renderNotifications(data.notifications);
                    }
                })
                .catch(err => console.error('Error fetching notifications:', err));
        }

        function updateBadge(count) {
            if (count > 0) {
                notifCountBadge.textContent = count;
                notifCountBadge.style.display = 'flex';
            } else {
                notifCountBadge.style.display = 'none';
            }
        }

        function renderNotifications(notifications) {
            if (notifications.length === 0) {
                notifItemsList.innerHTML = '<div style="padding: 20px; text-align: center; color: var(--text-muted); font-size: 0.85rem;">No new notifications</div>';
                return;
            }

            let html = '';
            notifications.forEach(n => {
                let icon = '<i data-lucide="bell" class="lucide-sm"></i>';
                let link = '#';
                
                if (n.type === 'booking' || n.type === 'session_update') {
                    if (n.type === 'booking') icon = '<i data-lucide="calendar" class="lucide-sm"></i>';
                    if (n.type === 'session_update') icon = '<i data-lucide="message-square" class="lucide-sm"></i>';
                    
                    let filter = '';
                    let msgLower = n.message.toLowerCase();
                    if (msgLower.includes('completed')) filter = '?filter=completed';
                    else if (msgLower.includes('dispute')) filter = '?filter=disputed';
                    else if (msgLower.includes('review')) filter = '?filter=under-review';
                    else if (msgLower.includes('cancel')) filter = '?filter=cancelled';
                    
                    link = '../pages/sessions.php' + filter;
                } else if (n.type === 'loan_default' || n.type === 'loan_repaid') {
                    if (n.type === 'loan_default') icon = '<i data-lucide="alert-triangle" class="lucide-sm"></i>';
                    if (n.type === 'loan_repaid') icon = '<i data-lucide="check-circle" class="lucide-sm"></i>';
                    link = '../pages/wallet.php';
                } else if (n.type === 'system') {
                    if (n.message.toLowerCase().includes('task')) {
                        icon = '<i data-lucide="clipboard-list" class="lucide-sm"></i>';
                        link = '../pages/community_tasks.php';
                    }
                }

                html += `
                    <div class="notif-item" data-id="${n.id}" data-link="${link}" style="padding: 12px 16px; border-bottom: 1px solid var(--border-light); cursor: pointer; display: flex; gap: 10px; transition: var(--transition); background: transparent;" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='transparent'">
                        <span style="font-size: 1.1rem; flex-shrink: 0; margin-top: 2px;">${icon}</span>
                        <div style="flex-grow: 1; min-width: 0;">
                            <p style="margin: 0; color: var(--text-primary); font-size: 0.85rem; line-height: 1.4; word-wrap: break-word;">${n.message}</p>
                            <span style="font-size: 0.7rem; color: var(--text-muted); display: block; margin-top: 4px;">${n.created_at}</span>
                        </div>
                    </div>
                `;
            });
            notifItemsList.innerHTML = html;
            if (window.lucide) lucide.createIcons();
        }

        function markAsRead(notifId, element, link = null) {
            if (link && link !== '#') {
                element.style.opacity = '0.5';
            }
            
            fetch('../api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_read', notif_id: notifId, csrf_token: window.csrfToken })
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    if (link && link !== '#') {
                        window.location.href = link;
                    } else {
                        element.style.opacity = '0.5';
                        setTimeout(() => {
                            element.remove();
                            fetchNotifications();
                        }, 300);
                    }
                }
            })
            .catch(err => {
                console.error('Error marking notification read:', err);
                if (link && link !== '#') {
                    window.location.href = link;
                }
            });
        }

        function markAllRead() {
            fetch('../api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_read', csrf_token: window.csrfToken })
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    notifItemsList.querySelectorAll('.notif-item').forEach(item => {
                        item.style.opacity = '0.5';
                    });
                    setTimeout(() => {
                        fetchNotifications();
                    }, 300);
                }
            })
            .catch(err => console.error('Error marking all notifications read:', err));
        }

        // Initial check and set up interval polling
        fetchNotifications();
        setInterval(fetchNotifications, 10000); // Poll every 10 seconds
    });
    </script>
    <?php endif; ?>

    <!-- Global CSRF Auto-Injection -->
    <script>
    window.csrfToken = "<?php echo $_SESSION['csrf_token'] ?? ''; ?>";
    document.addEventListener('DOMContentLoaded', function() {
        const forms = document.querySelectorAll('form[method="POST"], form[method="post"]');
        forms.forEach(form => {
            if (!form.querySelector('input[name="csrf_token"]')) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'csrf_token';
                input.value = window.csrfToken;
                form.appendChild(input);
            }
        });
    });
    </script>