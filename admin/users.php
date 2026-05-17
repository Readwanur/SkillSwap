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
        $conn->query("DELETE FROM users WHERE user_id = $uid");
        $success = "User #$uid deleted successfully.";
    }
}

// Search
$search = trim($_GET['search'] ?? '');

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
    ORDER BY u.created_at DESC
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
        <div class="admin-search-box">
            <input type="text" name="search" class="form-control" placeholder="&#x1F50D; Search by name, email, or location..."
                value="<?php echo htmlspecialchars($search); ?>" style="min-width:280px;">
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Search</button>
        <?php if ($search): ?>
            <a href="users.php" class="btn btn-sm btn-secondary">Clear</a>
        <?php endif; ?>
    </form>
</div>

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
                    <th>ID</th>
                    <th>User</th>
                    <th>Email</th>
                    <th>Location</th>
                    <th>Rep Score</th>
                    <th>Sessions</th>
                    <th>Balance</th>
                    <th>Joined</th>
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
                        </td>
                        <td><?php echo number_format($u['balance'] ?? 0, 2); ?> TC</td>
                        <td style="font-size:0.82rem; color:var(--text-muted);"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?php echo $u['user_id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Permanently delete this user? This cannot be undone.')"
                                    title="Delete User">&#x1F5D1;</button>
                            </form>
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