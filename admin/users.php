<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'User Management';

// Fetch all users with reputation and wallet
$users = $conn->query("
    SELECT u.*, r.current_score, r.completed_sessions, r.cancelled_sessions, r.mentor_level,
           w.balance
    FROM users u
    LEFT JOIN reputation r ON u.user_id = r.user_id
    LEFT JOIN wallet w ON u.user_id = w.user_id
    ORDER BY u.created_at DESC
");

include __DIR__ . '/../includes/admin_header.php';
?>

<h1 class="page-title">User Management</h1>

<div class="card">
    <div class="card-header">
        <h3>All Users (<?php echo $users->num_rows; ?>)</h3>
    </div>
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
                    <th>Level</th>
                    <th>Joined</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($u = $users->fetch_assoc()):
                    $rep_color = 'var(--text-primary)';
                    if ($u['current_score'] < 2.5)
                        $rep_color = 'var(--danger)';
                    elseif ($u['current_score'] >= 4.5)
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
                            <span style="color:var(--success);"><?php echo $u['completed_sessions'] ?? 0; ?></span> /
                            <span style="color:var(--danger);"><?php echo $u['cancelled_sessions'] ?? 0; ?></span>
                        </td>
                        <td><?php echo number_format($u['balance'] ?? 0, 2); ?> TC</td>
                        <td><span
                                class="badge badge-orange"><?php echo htmlspecialchars($u['mentor_level'] ?? 'N/A'); ?></span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>