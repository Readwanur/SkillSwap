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
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>

    <!-- Navbar -->
    <nav class="navbar">
        <div class="container" style="max-width: 100%; padding: 0 30px;">
            <a href="../pages/dashboard.php" class="navbar-brand">
                <img src="../assets/skillswap.png" alt="SkillSwap Logo">
            </a>

            <button class="nav-toggle"
                onclick="document.querySelector('.nav-links').classList.toggle('show')">&#9776;</button>

            <?php if (!$is_admin): ?>
                <div class="nav-menu-group" style="display:flex; align-items:center; gap: 15px;">
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
                        <li><a href="../pages/messages.php"
                                class="<?php echo $current_page == 'messages' ? 'active' : ''; ?>">Messages
                                <?php if ($unread_msg_count > 0): ?>
                                    <span class="badge badge-orange" style="font-size:0.65rem; padding: 2px 5px; border-radius: 50%; display:inline-block; vertical-align:middle; line-height:1;"><?php echo $unread_msg_count; ?></span>
                                <?php endif; ?>
                            </a></li>
                    </ul>
                    <?php if ($user_id > 0): ?>
                        <form action="../pages/search_users.php" method="GET" style="display:inline-block; position:relative;" id="headerSearchForm">
                            <input type="text" name="q" id="headerSearchInput" placeholder="Search users..." autocomplete="off" style="padding: 5px 12px; border-radius: 20px; border: 1px solid var(--border-color); font-size: 0.85rem; outline:none; background:var(--bg-secondary); color:var(--text-primary); width:180px;">
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
                </div>
            <?php endif; ?>

            <div class="nav-user" style="display:flex; align-items:center;">
                <?php if ($user_id > 0 && !$is_admin): ?>
                <!-- Notification Bell -->
                <div class="notif-bell-wrapper" style="position: relative; margin-right: 18px; display: inline-block;">
                    <button id="notifBellBtn" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; padding: 5px; position: relative; color: var(--text-secondary); transition: var(--transition); display: flex; align-items: center; justify-content: center; outline: none;">
                        🔔
                        <span id="notifCountBadge" style="position: absolute; top: -2px; right: -2px; background: var(--danger); color: #ffffff; font-size: 0.65rem; font-weight: bold; border-radius: 50%; width: 16px; height: 16px; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm); display: none;">0</span>
                    </button>
                    <!-- Dropdown Content -->
                    <div id="notifDropdown" style="display: none; position: absolute; top: 120%; right: -50px; width: 320px; background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); z-index: 2001; overflow: hidden;">
                        <div style="padding: 12px 16px; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center; background: var(--bg-primary);">
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
                            ⏱️ <?php echo number_format($time_credits, 2); ?> TC
                        </span>
                    <?php endif; ?>
                </div>

                <a href="../pages/logout.php" class="btn-logout"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> Logout</a>
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
                markAsRead(notifId, item);
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
                let icon = '🔔';
                if (n.type === 'booking') icon = '📅';
                else if (n.type === 'session_update') icon = '💬';
                else if (n.type === 'loan_default') icon = '⚠️';
                else if (n.type === 'loan_repaid') icon = '✅';

                html += `
                    <div class="notif-item" data-id="${n.id}" style="padding: 12px 16px; border-bottom: 1px solid var(--border-light); cursor: pointer; display: flex; gap: 10px; transition: var(--transition); background: var(--bg-card);" onmouseover="this.style.background='var(--bg-hover)'" onmouseout="this.style.background='var(--bg-card)'">
                        <span style="font-size: 1.1rem; flex-shrink: 0; margin-top: 2px;">${icon}</span>
                        <div style="flex-grow: 1; min-width: 0;">
                            <p style="margin: 0; color: var(--text-primary); font-size: 0.85rem; line-height: 1.4; word-wrap: break-word;">${n.message}</p>
                            <span style="font-size: 0.7rem; color: var(--text-muted); display: block; margin-top: 4px;">${n.created_at}</span>
                        </div>
                    </div>
                `;
            });
            notifItemsList.innerHTML = html;
        }

        function markAsRead(notifId, element) {
            fetch('../api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_read', notif_id: notifId })
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    element.style.opacity = '0.5';
                    setTimeout(() => {
                        element.remove();
                        fetchNotifications();
                    }, 300);
                }
            })
            .catch(err => console.error('Error marking notification read:', err));
        }

        function markAllRead() {
            fetch('../api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_read' })
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