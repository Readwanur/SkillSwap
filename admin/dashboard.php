<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'Dashboard';

// Stats
$total_users = $conn->query("SELECT COUNT(*) AS cnt FROM users")->fetch_assoc()['cnt'];
$total_skills = $conn->query("SELECT COUNT(*) AS cnt FROM skills")->fetch_assoc()['cnt'];
$total_sessions = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions")->fetch_assoc()['cnt'];
$completed_sessions = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions WHERE status = 'completed'")->fetch_assoc()['cnt'];
$pending_sessions = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions WHERE status = 'scheduled'")->fetch_assoc()['cnt'];
$total_hours = $conn->query("SELECT COALESCE(SUM(session_duration), 0) AS total FROM exchange_sessions WHERE status = 'completed'")->fetch_assoc()['total'];
$total_hours = round($total_hours / 60, 1);
$total_credits = $conn->query("SELECT COALESCE(SUM(final_amount), 0) AS total FROM transactions")->fetch_assoc()['total'];
$total_wallet = $conn->query("SELECT COALESCE(SUM(balance), 0) AS total FROM wallet")->fetch_assoc()['total'];

// Recent sessions
$recent_sessions = $conn->query("
    SELECT es.*, s.skill_name, u_req.name AS requester_name, u_prov.name AS provider_name
    FROM exchange_sessions es
    JOIN skills s ON es.skill_id = s.skill_id
    JOIN users u_req ON es.requester_id = u_req.user_id
    JOIN users u_prov ON es.provider_id = u_prov.user_id
    ORDER BY es.scheduled_time DESC
    LIMIT 10
");

// Recent users
$recent_users = $conn->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5");

// Pending community tasks
$pending_tasks = $conn->query("SELECT COUNT(*) AS cnt FROM community_task WHERE status = 'pending'")->fetch_assoc()['cnt'];

include __DIR__ . '/../includes/admin_header.php';
?>

<!-- Welcome Banner -->
<div class="admin-welcome-banner">
    <div>
        <h1 class="page-title" style="border:none; margin:0; padding:0;">Admin Dashboard</h1>
        <p style="color:#ffffff; margin-top:4px;">Here's what's happening on your platform today.</p>
    </div>
    <div class="admin-quick-actions">
        <a href="../admin/users.php" class="btn btn-sm btn-secondary">&#x1F465; Manage Users</a>
        <a href="../admin/skills.php" class="btn btn-sm btn-secondary">&#x1F4DA; Add Skill</a>
        <a href="../admin/disputes.php" class="btn btn-sm btn-primary">&#x1F4CB; View Reports</a>
    </div>
</div>

<!-- Platform Stats -->
<div class="stats-grid">
    <div class="stat-card stat-card-accent" style="--accent: var(--primary);">
        <div class="stat-icon">&#x1F465;</div>
        <span class="stat-value"><?php echo $total_users; ?></span>
        <span class="stat-label">Total Users</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--info);">
        <div class="stat-icon">&#x1F4DA;</div>
        <span class="stat-value"><?php echo $total_skills; ?></span>
        <span class="stat-label">Skills Listed</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--success);">
        <div class="stat-icon">&#x2705;</div>
        <span class="stat-value"><?php echo $completed_sessions; ?></span>
        <span class="stat-label">Completed</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--warning);">
        <div class="stat-icon">&#x23F3;</div>
        <span class="stat-value"><?php echo $pending_sessions; ?></span>
        <span class="stat-label">Pending</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: #6b5ce7;">
        <div class="stat-icon">&#x23F1;</div>
        <span class="stat-value"><?php echo $total_hours; ?>h</span>
        <span class="stat-label">Hours Exchanged</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--secondary);">
        <div class="stat-icon">&#x1F4B0;</div>
        <span class="stat-value"><?php echo number_format($total_wallet, 0); ?></span>
        <span class="stat-label">Total Credits</span>
    </div>
</div>

<!-- At-a-glance Alerts -->
<?php if ($pending_sessions > 0 || $pending_tasks > 0): ?>
<div class="admin-alerts mb-3">
    <?php if ($pending_sessions > 0): ?>
        <div class="alert alert-warning" style="display:flex; justify-content:space-between; align-items:center;">
            <span>&#x26A0;&#xFE0F; <strong><?php echo $pending_sessions; ?> session(s)</strong> are awaiting action.</span>
            <a href="../admin/disputes.php" class="btn btn-sm btn-secondary">Review Now</a>
        </div>
    <?php endif; ?>
    <?php if ($pending_tasks > 0): ?>
        <div class="alert alert-info" style="display:flex; justify-content:space-between; align-items:center;">
            <span>&#x1F4CB; <strong><?php echo $pending_tasks; ?> community task(s)</strong> are pending assignment.</span>
            <a href="../admin/community_tasks.php" class="btn btn-sm btn-secondary">View</a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid-2">
    <!-- Recent Sessions -->
    <div class="card">
        <div class="card-header">
            <h3>&#x1F4C5; Recent Sessions</h3>
            <a href="../admin/disputes.php" class="btn btn-sm btn-secondary">View All &rarr;</a>
        </div>
        <?php if ($recent_sessions->num_rows > 0): ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Skill</th>
                        <th>Requester</th>
                        <th>Provider</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($s = $recent_sessions->fetch_assoc()):
                        $sc = 'badge-warning';
                        if ($s['status'] === 'completed')
                            $sc = 'badge-success';
                        elseif ($s['status'] === 'cancelled')
                            $sc = 'badge-danger';
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($s['skill_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($s['requester_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['provider_name']); ?></td>
                            <td style="color:var(--text-muted); font-size:0.82rem;"><?php echo date('M d', strtotime($s['scheduled_time'])); ?></td>
                            <td><span class="badge <?php echo $sc; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty-state"><p>No sessions yet.</p></div>
        <?php endif; ?>
    </div>

    <!-- Recent Users -->
    <div class="card">
        <div class="card-header">
            <h3>&#x1F464; Recent Users</h3>
            <a href="../admin/users.php" class="btn btn-sm btn-secondary">View All &rarr;</a>
        </div>
        <?php while ($u = $recent_users->fetch_assoc()): ?>
            <div class="user-card mb-1">
                <div class="avatar"><?php echo strtoupper(substr($u['name'], 0, 1)); ?></div>
                <div class="user-info" style="flex:1;">
                    <h4><?php echo htmlspecialchars($u['name']); ?></h4>
                    <p><?php echo htmlspecialchars($u['email']); ?> &middot;
                        <?php echo htmlspecialchars($u['location'] ?? 'N/A'); ?></p>
                </div>
                <span style="font-size:0.75rem; color:var(--text-muted);"><?php echo date('M d', strtotime($u['created_at'])); ?></span>
            </div>
        <?php endwhile; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>