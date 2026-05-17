<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'System Analytics';

// --- Aggregates & Subqueries ---

// Total hours exchanged
$total_hours = $conn->query("SELECT COALESCE(SUM(session_duration), 0) AS total FROM exchange_sessions WHERE status = 'completed'")->fetch_assoc()['total'];
$total_hours = round($total_hours / 60, 1);

// Total credits in circulation
$total_credits = $conn->query("SELECT COALESCE(SUM(balance), 0) AS total FROM wallet")->fetch_assoc()['total'];

// Average reputation
$avg_rep = $conn->query("SELECT ROUND(AVG(current_score), 2) AS avg_score FROM reputation")->fetch_assoc()['avg_score'];

// Average wallet balance
$avg_balance = $conn->query("SELECT ROUND(AVG(balance), 2) AS avg_bal FROM wallet")->fetch_assoc()['avg_bal'];

// Platform growth: users per month
$growth = $conn->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS user_count
    FROM users
    GROUP BY month
    ORDER BY month DESC
    LIMIT 12
");

// Top providers by hours taught (aggregate)
$top_providers = $conn->query("
    SELECT u.name, ROUND(SUM(es.session_duration) / 60, 1) AS hours_taught, COUNT(*) AS session_count,
           r.current_score
    FROM exchange_sessions es
    JOIN users u ON es.provider_id = u.user_id
    LEFT JOIN reputation r ON u.user_id = r.user_id
    WHERE es.status = 'completed'
    GROUP BY es.provider_id
    ORDER BY hours_taught DESC
    LIMIT 10
");

// Top skills by session count
$top_skills = $conn->query("
    SELECT s.skill_name, s.catagory, COUNT(*) AS session_count
    FROM exchange_sessions es
    JOIN skills s ON es.skill_id = s.skill_id
    GROUP BY es.skill_id
    ORDER BY session_count DESC
    LIMIT 10
");

// Users above average balance (subquery)
$above_avg_users = $conn->query("
    SELECT u.name, w.balance
    FROM users u
    JOIN wallet w ON u.user_id = w.user_id
    WHERE w.balance > (SELECT AVG(balance) FROM wallet)
    ORDER BY w.balance DESC
");

// Community task completion stats
$task_stats = $conn->query("
    SELECT status, COUNT(*) AS cnt
    FROM community_task
    GROUP BY status
");
$task_data = [];
while ($ts = $task_stats->fetch_assoc()) {
    $task_data[$ts['status']] = $ts['cnt'];
}

include __DIR__ . '/../includes/admin_header.php';
?>

<h1 class="page-title">System Analytics</h1>

<!-- Overview Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-value"><?php echo $total_hours; ?>h</span>
        <span class="stat-label">Total Hours Exchanged</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?php echo number_format($total_credits, 0); ?></span>
        <span class="stat-label">Credits in Circulation</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?php echo $avg_rep; ?></span>
        <span class="stat-label">Avg Reputation</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?php echo number_format($avg_balance, 2); ?></span>
        <span class="stat-label">Avg Wallet Balance</span>
    </div>
</div>

<div class="grid-2 mb-3">
    <!-- Top Providers -->
    <div class="card">
        <div class="card-header">
            <h3>Top Providers (by Hours)</h3>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Hours</th>
                        <th>Sessions</th>
                        <th>Score</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($p = $top_providers->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                            <td><?php echo $p['hours_taught']; ?>h</td>
                            <td><?php echo $p['session_count']; ?></td>
                            <td>&#11088; <?php echo $p['current_score']; ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Top Skills -->
    <div class="card">
        <div class="card-header">
            <h3>Most Popular Skills</h3>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Skill</th>
                        <th>Category</th>
                        <th>Sessions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($s = $top_skills->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($s['skill_name']); ?></strong></td>
                            <td><span
                                    class="badge badge-orange"><?php echo htmlspecialchars($s['catagory'] ?? 'N/A'); ?></span>
                            </td>
                            <td><?php echo $s['session_count']; ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid-2 mb-3">
    <!-- Users Above Average Balance -->
    <div class="card">
        <div class="card-header">
            <h3>Users Above Avg Balance</h3>
            <span class="badge badge-info">Avg: <?php echo number_format($avg_balance, 2); ?> TC</span>
        </div>
        <?php if ($above_avg_users->num_rows > 0): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($u = $above_avg_users->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($u['name']); ?></td>
                                <td style="color:var(--success); font-weight:600;">
                                    <?php echo number_format($u['balance'], 2); ?> TC</td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <p>No users above average.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Community Task Stats -->
    <div class="card">
        <div class="card-header">
            <h3>Community Tasks</h3>
        </div>
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-value" style="color:var(--warning);"><?php echo $task_data['pending'] ?? 0; ?></span>
                <span class="stat-label">Pending</span>
            </div>
            <div class="stat-card">
                <span class="stat-value" style="color:var(--info);"><?php echo $task_data['in-progress'] ?? 0; ?></span>
                <span class="stat-label">In Progress</span>
            </div>
            <div class="stat-card">
                <span class="stat-value"
                    style="color:var(--success);"><?php echo $task_data['completed'] ?? 0; ?></span>
                <span class="stat-label">Completed</span>
            </div>
        </div>
    </div>
</div>

<!-- User Growth -->
<div class="card">
    <div class="card-header">
        <h3>User Growth (Monthly)</h3>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Month</th>
                    <th>New Users</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($g = $growth->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $g['month']; ?></td>
                        <td>
                            <div class="flex items-center gap-1">
                                <div class="progress-bar" style="max-width:200px;">
                                    <div class="fill" style="width: <?php echo min($g['user_count'] * 20, 100); ?>%;"></div>
                                </div>
                                <span><?php echo $g['user_count']; ?></span>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>