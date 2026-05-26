<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'User Management';
$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'delete_user') {
        $uid = intval($_POST['user_id'] ?? 0);
        
        // Prevent deleting admin account (though admins aren't in this table, it's safe)
        if (isset($_SESSION['user_id']) && $uid === $_SESSION['user_id']) {
            $error = "You cannot delete your own account.";
        } else {
            // Check for transaction or session history
            $sessions = $conn->query("SELECT COUNT(*) as cnt FROM exchange_sessions WHERE requester_id = $uid OR provider_id = $uid")->fetch_assoc()['cnt'];
            $txns = $conn->query("SELECT COUNT(*) as cnt FROM transactions WHERE from_user_id = $uid OR to_user_id = $uid")->fetch_assoc()['cnt'];
            
            if ($sessions > 0 || $txns > 0) {
                $error = "Cannot delete User #$uid: They have transaction or session history. Please Suspend them instead to preserve historical data.";
            } else {
                $conn->begin_transaction();
                try {
                    // Manual cascading delete for tables without ON DELETE CASCADE
                    $conn->query("DELETE FROM user_skills_offered WHERE user_id = $uid");
                    $conn->query("DELETE FROM user_skills_requested WHERE user_id = $uid");
                    $conn->query("DELETE FROM wallet WHERE user_id = $uid");
                    $conn->query("DELETE FROM reputation WHERE user_id = $uid");
                    
                    // Reset any tasks they were working on
                    $conn->query("UPDATE community_task SET user_id = NULL, status = 'pending', submission_note = NULL, assigned_at = CURRENT_TIMESTAMP WHERE user_id = $uid");
                    
                    // Finally delete the user
                    $conn->query("DELETE FROM users WHERE user_id = $uid");
                    
                    $conn->commit();
                    $success = "User #$uid deleted safely.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Deletion failed: " . $e->getMessage();
                }
            }
        }
    }

    if ($_POST['action'] === 'suspend_user') {
        $uid = intval($_POST['user_id'] ?? 0);
        $conn->query("UPDATE users SET status = 'suspended' WHERE user_id = $uid");
        $success = "User #$uid suspended. They can no longer log in.";
    }

    if ($_POST['action'] === 'activate_user') {
        $uid = intval($_POST['user_id'] ?? 0);
        $conn->query("UPDATE users SET status = 'active' WHERE user_id = $uid");
        $success = "User #$uid activated.";
    }
}

// Search
$search = trim($_GET['search'] ?? '');

// Sorting parameters
$sort = trim($_GET['sort'] ?? 'joined');
$order = trim($_GET['order'] ?? 'desc');

$allowed_sorts = [
    'id' => 'u.user_id',
    'name' => 'u.name',
    'rep' => 'r.current_score',
    'sessions' => 'r.completed_sessions',
    'balance' => 'w.balance',
    'joined' => 'u.created_at'
];

$sort_col = $allowed_sorts[$sort] ?? 'u.created_at';
$order = (strtolower($order) === 'asc') ? 'ASC' : 'DESC';

// Sort URL generator
function getSortUrl($col, $dir, $search) {
    $query = [
        'sort' => $col,
        'order' => $dir
    ];
    if ($search !== '') {
        $query['search'] = $search;
    }
    return 'users.php?' . http_build_query($query);
}

// Sort Buttons generator (single toggle button beside column header)
function renderSortButtons($col, $current_sort, $current_order, $search) {
    if ($current_sort === $col) {
        if (strtolower($current_order) === 'asc') {
            // Currently sorted ascending. Click to sort descending.
            $url = getSortUrl($col, 'desc', $search);
            return '
            <span class="sort-arrows">
                <a href="' . $url . '" class="sort-arrow active" title="Sorted Ascending. Click to sort Descending">&#x25B4;</a>
            </span>';
        } else {
            // Currently sorted descending. Click to sort ascending.
            $url = getSortUrl($col, 'asc', $search);
            return '
            <span class="sort-arrows">
                <a href="' . $url . '" class="sort-arrow active" title="Sorted Descending. Click to sort Ascending">&#x25BE;</a>
            </span>';
        }
    } else {
        // Not currently sorted. Click to sort ascending.
        $url = getSortUrl($col, 'asc', $search);
        return '
        <span class="sort-arrows">
            <a href="' . $url . '" class="sort-arrow" title="Click to sort Ascending">&#x25B4;</a>
        </span>';
    }
}

$where = "WHERE 1=1";
$params = [];
$types = '';

if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.location LIKE ?)";
    $search_param = "%$search%";
    $params[] = &$search_param;
    $params[] = &$search_param;
    $params[] = &$search_param;
    $types .= 'sss';
}

$sql = "
    SELECT u.*, r.current_score, r.completed_sessions, r.cancelled_sessions,
           w.balance
    FROM users u
    LEFT JOIN reputation r ON u.user_id = r.user_id
    LEFT JOIN wallet w ON u.user_id = w.user_id
    $where
    ORDER BY $sort_col $order
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $users = $stmt->get_result();
} else {
    $users = $conn->query($sql);
}

$count_all = $conn->query("SELECT COUNT(*) AS cnt FROM users")->fetch_assoc()['cnt'];

// --- COMPLEX QUERY: CQ-11 ---
// Inactive users with no sessions in the past 30 days (DATEDIFF + NOT EXISTS)
$inactive_users = $conn->query("
    SELECT u.user_id, u.name, u.email, u.last_active_at,
           DATEDIFF(NOW(), u.last_active_at) AS days_inactive
    FROM users u
    WHERE u.status = 'active'
      AND NOT EXISTS (
          SELECT 1 FROM exchange_sessions es
          WHERE (es.requester_id = u.user_id OR es.provider_id = u.user_id)
            AND es.scheduled_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      )
    ORDER BY days_inactive DESC
");

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="page-header">
    <h1 class="page-title" style="margin:0;">&#x1F465; User Management</h1>
    <span class="badge badge-info" style="font-size:0.85rem; padding:6px 16px;"><?php echo $count_all; ?> Registered Users</span>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Search Bar -->
<div class="card mb-3">
    <form method="GET" class="admin-filter-bar">
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
        <input type="hidden" name="order" value="<?php echo htmlspecialchars(strtolower($order)); ?>">
        <div class="admin-search-box">
            <input type="text" name="search" class="form-control" placeholder="&#x1F50D; Search by name, email, or location..."
                value="<?php echo htmlspecialchars($search); ?>" style="min-width:280px;">
        </div>

        <button type="submit" class="btn btn-sm btn-primary">Search</button>
        <?php if ($search || $sort !== 'joined' || $order !== 'DESC'): ?>
            <a href="users.php" class="btn btn-sm btn-secondary">Clear</a>
        <?php endif; ?>
</div>

<!-- Alert: Inactive Users (CQ-11) -->
<?php if ($inactive_users && $inactive_users->num_rows > 0): ?>
    <div class="card mb-3" style="border: 1px solid var(--warning); background: rgba(115,92,0,0.02);">
        <div class="card-header" style="border-bottom: 1px solid rgba(115,92,0,0.15); margin-bottom: 15px;">
            <h3 style="color: var(--warning); margin:0;">⚠️ Inactive Users Alert (30+ Days No Session)</h3>
            <span class="badge badge-warning" style="background: rgba(115,92,0,0.15); color: var(--warning);"><?php echo $inactive_users->num_rows; ?> User(s)</span>
        </div>
        <p style="color: var(--text-muted); font-size: 0.82rem; margin-bottom: 15px;">
            Active accounts that have not participated in any session (as requester or provider) within the past 30 days.
        </p>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>User</th>
                        <th>Email</th>
                        <th>Last Active At</th>
                        <th>Inactivity Period</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($iu = $inactive_users->fetch_assoc()): ?>
                        <tr>
                            <td>#<?php echo $iu['user_id']; ?></td>
                            <td>
                                <strong><a href="../pages/user_profile.php?id=<?php echo $iu['user_id']; ?>" style="color: var(--primary); text-decoration:none;"><?php echo htmlspecialchars($iu['name']); ?></a></strong>
                            </td>
                            <td><?php echo htmlspecialchars($iu['email']); ?></td>
                            <td style="color:var(--text-muted);"><?php echo date('M d, Y', strtotime($iu['last_active_at'])); ?></td>
                            <td style="color:var(--danger); font-weight:600;"><?php echo $iu['days_inactive']; ?> days inactive</td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Users Table -->
<div class="card">
    <div class="card-header">
        <h3>Showing <?php echo $users->num_rows; ?> user(s)</h3>
    </div>
    <?php if ($users->num_rows > 0): ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>
                        <span class="th-content">
                            <span>ID</span>
                            <?php echo renderSortButtons('id', $sort, $order, $search); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>User</span>
                            <?php echo renderSortButtons('name', $sort, $order, $search); ?>
                        </span>
                    </th>
                    <th>Email</th>
                    <th>Location</th>
                    <th>
                        <span class="th-content">
                            <span>Rep Score</span>
                            <?php echo renderSortButtons('rep', $sort, $order, $search); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Sessions</span>
                            <?php echo renderSortButtons('sessions', $sort, $order, $search); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Balance</span>
                            <?php echo renderSortButtons('balance', $sort, $order, $search); ?>
                        </span>
                    </th>
                    <th>Status</th>
                    <th>
                        <span class="th-content">
                            <span>Joined</span>
                            <?php echo renderSortButtons('joined', $sort, $order, $search); ?>
                        </span>
                    </th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($u = $users->fetch_assoc()):
                    $rep_color = 'var(--text-primary)';
                    if (($u['current_score'] ?? 5) < 2.5)
                        $rep_color = 'var(--danger)';
                    elseif (($u['current_score'] ?? 5) >= 4.5)
                        $rep_color = 'var(--success)';
                    ?>
                    <tr>
                        <td><?php echo $u['user_id']; ?></td>
                        <td>
                            <div class="flex items-center gap-1">
                                <div class="avatar" style="width:32px; height:32px; font-size:0.8rem;">
                                    <?php echo strtoupper(substr($u['name'], 0, 1)); ?></div>
                                <strong><?php echo htmlspecialchars($u['name']); ?></strong>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td><?php echo htmlspecialchars($u['location'] ?? 'N/A'); ?></td>
                        <td style="color:<?php echo $rep_color; ?>; font-weight:600;">
                            <?php echo $u['current_score'] ?? 'N/A'; ?></td>
                        <td>
                            <span style="color:var(--success);" title="Completed"><?php echo $u['completed_sessions'] ?? 0; ?></span> /
                            <span style="color:var(--danger);" title="Cancelled"><?php echo $u['cancelled_sessions'] ?? 0; ?></span>
                            <?php 
                            $total = ($u['completed_sessions'] ?? 0) + ($u['cancelled_sessions'] ?? 0);
                            $rate = $total > 0 ? round(($u['cancelled_sessions'] ?? 0) * 100 / $total, 1) : 0;
                            ?>
                            <br><small style="color: <?php echo $rate > 20 ? 'var(--danger)' : 'var(--text-muted)'; ?>; font-size:0.75rem;">Cancel Rate: <?php echo $rate; ?>%</small>
                        </td>
                        <td><?php echo number_format($u['balance'] ?? 0, 2); ?> TC</td>
                        <td>
                            <?php if ($u['status'] === 'active'): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Suspended</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.82rem; color:var(--text-muted);"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                        <td>
                            <div class="flex gap-1" style="flex-wrap:nowrap;">
                                <?php if ($u['status'] === 'active'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="suspend_user">
                                        <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-warning" title="Suspend User"
                                            onclick="return confirm('Suspend User #<?php echo $u['user_id']; ?>? They will not be able to log in.')">&#x26D4; Suspend</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="activate_user">
                                        <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success" title="Activate User"
                                            onclick="return confirm('Activate User #<?php echo $u['user_id']; ?>?')">&#x2705; Activate</button>
                                    </form>
                                <?php endif; ?>

                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Hard Delete"
                                        onclick="return confirm('Permanently delete User #<?php echo $u['user_id']; ?>? (Will fail if they have transaction history)')">&#x1F5D1;</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="empty-state">
            <div class="icon">&#x1F50D;</div>
            <p>No users match your search.</p>
            <a href="users.php" class="btn btn-sm btn-secondary mt-2">Clear Search</a>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>