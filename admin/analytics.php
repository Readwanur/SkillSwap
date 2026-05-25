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

// --- COMPLEX QUERY: CQ-1 ---
// Rank users by total hours taught (Window Function + CTE)
$top_providers = $conn->query("
    WITH provider_stats AS (
        SELECT
            es.provider_id,
            u.name,
            r.current_score,
            SUM(es.session_duration) AS total_minutes,
            COUNT(*) AS session_count,
            ROUND(AVG(es.rating), 2) AS avg_rating
        FROM exchange_sessions es
        JOIN users u ON es.provider_id = u.user_id
        LEFT JOIN reputation r ON u.user_id = r.user_id
        WHERE es.status = 'completed'
        GROUP BY es.provider_id
    )
    SELECT
        *,
        ROUND(total_minutes / 60.0, 1) AS hours_taught,
        RANK() OVER (ORDER BY total_minutes DESC) AS teaching_rank,
        DENSE_RANK() OVER (ORDER BY avg_rating DESC) AS rating_rank
    FROM provider_stats
    ORDER BY teaching_rank ASC
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

// --- COMPLEX QUERY: CQ-8 ---
// Top skill categories by engagement (Nested subqueries + CASE)
$categories_engagement = $conn->query("
    SELECT
        s.catagory,
        COUNT(DISTINCT s.skill_id) AS skill_count,
        (SELECT COUNT(*) FROM exchange_sessions es
         JOIN skills sk ON es.skill_id = sk.skill_id
         WHERE sk.catagory = s.catagory AND es.status = 'completed') AS completed_sessions,
        (SELECT ROUND(AVG(es2.rating), 1) FROM exchange_sessions es2
         JOIN skills sk2 ON es2.skill_id = sk2.skill_id
         WHERE sk2.catagory = s.catagory AND es2.rating IS NOT NULL) AS avg_category_rating,
        CASE
            WHEN (SELECT COUNT(*) FROM exchange_sessions es3
                  JOIN skills sk3 ON es3.skill_id = sk3.skill_id
                  WHERE sk3.catagory = s.catagory AND es3.status = 'completed') >= 10 THEN 'Hot 🔥'
            WHEN (SELECT COUNT(*) FROM exchange_sessions es3
                  JOIN skills sk3 ON es3.skill_id = sk3.skill_id
                  WHERE sk3.catagory = s.catagory AND es3.status = 'completed') >= 5 THEN 'Growing 📈'
            ELSE 'New 🌱'
        END AS category_status
    FROM skills s
    WHERE s.catagory IS NOT NULL
    GROUP BY s.catagory
    ORDER BY completed_sessions DESC
");

// --- COMPLEX QUERY: CQ-6 ---
// Monthly financial trend (CTE + Window function SUM OVER)
$revenue_trends = $conn->query("
    WITH monthly_revenue AS (
        SELECT
            DATE_FORMAT(timestamp, '%Y-%m') AS month,
            SUM(CASE WHEN type = 'credit_transfer' THEN final_amount ELSE 0 END) AS session_revenue,
            SUM(CASE WHEN type = 'community_reward' THEN final_amount ELSE 0 END) AS community_rewards,
            SUM(CASE WHEN type IN ('full_refund', 'partial_refund') THEN final_amount ELSE 0 END) AS refunds,
            COUNT(*) AS transaction_count
        FROM transactions
        GROUP BY month
    )
    SELECT
        month,
        session_revenue,
        community_rewards,
        refunds,
        (session_revenue - refunds) AS net_revenue,
        transaction_count,
        SUM(session_revenue - refunds) OVER (ORDER BY month) AS cumulative_net
    FROM monthly_revenue
    ORDER BY month DESC
");

// --- COMPLEX QUERY: CQ-5 ---
// Gap Analysis: requested skills with no active providers (NOT EXISTS)
$skills_gap = $conn->query("
    SELECT s.skill_name, s.catagory, COUNT(usr.user_id) AS demand_count
    FROM skills s
    JOIN user_skills_requested usr ON s.skill_id = usr.skill_id
    WHERE NOT EXISTS (
        SELECT 1 FROM user_skills_offered uso
        WHERE uso.skill_id = s.skill_id
    )
    GROUP BY s.skill_id
    HAVING demand_count > 0
    ORDER BY demand_count DESC
    LIMIT 10
");

// --- COMPLEX QUERY: CQ-9 ---
// User Reliability Monitoring (CASE in aggregate to calculate cancel rate)
$reliability_stats = $conn->query("
    SELECT
        u.name,
        r.completed_sessions,
        r.cancelled_sessions,
        (r.completed_sessions + r.cancelled_sessions) AS total_sessions,
        ROUND(
            CASE
                WHEN (r.completed_sessions + r.cancelled_sessions) > 0
                THEN (r.cancelled_sessions * 100.0) / (r.completed_sessions + r.cancelled_sessions)
                ELSE 0
            END, 1
        ) AS cancellation_rate_pct,
        CASE
            WHEN r.cancelled_sessions = 0 THEN 'Reliable ✅'
            WHEN r.cancelled_sessions <= 2 THEN 'Good ⚠️'
            ELSE 'At Risk ❌'
        END AS reliability_status
    FROM users u
    JOIN reputation r ON u.user_id = r.user_id
    ORDER BY cancellation_rate_pct DESC, r.cancelled_sessions DESC
    LIMIT 10
");

// --- COMPLEX QUERY: CQ-12 ---
// Community Task Leaderboard (CTE + DENSE_RANK Window Function)
$task_leaderboard = $conn->query("
    WITH task_ranks AS (
        SELECT
            u.name,
            COUNT(ct.task_id) AS tasks_completed,
            SUM(ct.credit_reward) AS total_rewards,
            DENSE_RANK() OVER (ORDER BY COUNT(ct.task_id) DESC) as task_rank
        FROM community_task ct
        JOIN users u ON ct.user_id = u.user_id
        WHERE ct.status = 'completed'
        GROUP BY ct.user_id
    )
    SELECT * FROM task_ranks WHERE task_rank <= 10
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
    <!-- Top Providers (CQ-1) -->
    <div class="card">
        <div class="card-header">
            <h3>Top Providers Leaderboard</h3>
            <span class="badge badge-success">Ranked by Hours</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Provider</th>
                        <th>Hours Taught</th>
                        <th>Avg Rating</th>
                        <th>Rating Rank</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($p = $top_providers->fetch_assoc()): ?>
                        <tr>
                            <td><strong>#<?php echo $p['teaching_rank']; ?></strong></td>
                            <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                            <td><?php echo $p['hours_taught']; ?>h <small style="color:var(--text-muted);">(<?php echo $p['session_count']; ?> sessions)</small></td>
                            <td>⭐ <?php echo $p['avg_rating'] ?? 'N/A'; ?></td>
                            <td><span class="badge badge-info">#<?php echo $p['rating_rank']; ?></span></td>
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
    <!-- Category Engagement (CQ-8) -->
    <div class="card">
        <div class="card-header">
            <h3>Category Engagement</h3>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Skills</th>
                        <th>Completed Sessions</th>
                        <th>Avg Rating</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($cat = $categories_engagement->fetch_assoc()): 
                        $badge_class = 'badge-orange';
                        if ($cat['category_status'] === 'Hot 🔥') $badge_class = 'badge-danger';
                        elseif ($cat['category_status'] === 'Growing 📈') $badge_class = 'badge-info';
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($cat['catagory']); ?></strong></td>
                            <td><?php echo $cat['skill_count']; ?></td>
                            <td><?php echo $cat['completed_sessions']; ?></td>
                            <td>⭐ <?php echo $cat['avg_category_rating'] ?? 'N/A'; ?></td>
                            <td><span class="badge <?php echo $badge_class; ?>"><?php echo $cat['category_status']; ?></span></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- User Reliability Monitor (CQ-9) -->
    <div class="card">
        <div class="card-header">
            <h3>User Cancellation & Reliability</h3>
            <span class="badge badge-danger">High Alert</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Completed</th>
                        <th>Cancelled</th>
                        <th>Cancel Rate</th>
                        <th>Reliability</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($r = $reliability_stats->fetch_assoc()): 
                        $rel_badge = 'badge-success';
                        if ($r['reliability_status'] === 'At Risk ❌') $rel_badge = 'badge-danger';
                        elseif ($r['reliability_status'] === 'Good ⚠️') $rel_badge = 'badge-warning';
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($r['name']); ?></strong></td>
                            <td><?php echo $r['completed_sessions']; ?></td>
                            <td><?php echo $r['cancelled_sessions']; ?></td>
                            <td style="font-weight: 600; color: <?php echo $r['cancellation_rate_pct'] > 20 ? 'var(--danger)' : 'var(--text-secondary)'; ?>;">
                                <?php echo $r['cancellation_rate_pct']; ?>%
                            </td>
                            <td><span class="badge <?php echo $rel_badge; ?>"><?php echo $r['reliability_status']; ?></span></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid-2 mb-3">
    <!-- Monthly Revenue Trends (CQ-6) -->
    <div class="card">
        <div class="card-header">
            <h3>Financial & Revenue Trends</h3>
            <span class="badge badge-info">In Time Credits</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Volume</th>
                        <th>Session Inflows</th>
                        <th>Refunds</th>
                        <th>Net Inflow</th>
                        <th>Cumulative Net</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($rev = $revenue_trends->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo $rev['month']; ?></strong></td>
                            <td><?php echo $rev['transaction_count']; ?> tx</td>
                            <td style="color:var(--success); font-weight:600;">+<?php echo number_format($rev['session_revenue'], 1); ?> TC</td>
                            <td style="color:var(--danger); font-weight:600;">-<?php echo number_format($rev['refunds'], 1); ?> TC</td>
                            <td style="color:var(--primary); font-weight:600;"><?php echo number_format($rev['net_revenue'], 1); ?> TC</td>
                            <td><strong><?php echo number_format($rev['cumulative_net'], 1); ?> TC</strong></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Skills Gap Analysis (CQ-5) -->
    <div class="card">
        <div class="card-header">
            <h3>Market Gaps (Demand with No Providers)</h3>
            <span class="badge badge-warning">NOT EXISTS</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Skill</th>
                        <th>Category</th>
                        <th>Learner Requests</th>
                        <th>Active Providers</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($skills_gap && $skills_gap->num_rows > 0): ?>
                        <?php while ($sg = $skills_gap->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($sg['skill_name']); ?></strong></td>
                                <td><span class="badge badge-orange"><?php echo htmlspecialchars($sg['catagory'] ?? 'General'); ?></span></td>
                                <td style="color:var(--danger); font-weight:600; text-align:center;"><?php echo $sg['demand_count']; ?> requests</td>
                                <td style="text-align:center;"><span class="badge badge-danger">0</span></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center; color:var(--text-muted);">No market gaps identified. All requested skills have providers!</td>
                        </tr>
                    <?php endif; ?>
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
            <h3>Community Tasks Summary</h3>
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

<div class="grid-2 mb-3">
    <!-- Community Task Leaderboard (CQ-12) -->
    <div class="card">
        <div class="card-header">
            <h3>Community Task Contributors</h3>
            <span class="badge badge-orange">DENSE_RANK</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Contributor</th>
                        <th>Tasks Completed</th>
                        <th>Total Rewards Earned</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($task_leaderboard && $task_leaderboard->num_rows > 0): ?>
                        <?php while ($tl = $task_leaderboard->fetch_assoc()): ?>
                            <tr>
                                <td><strong>#<?php echo $tl['task_rank']; ?></strong></td>
                                <td><strong><?php echo htmlspecialchars($tl['name']); ?></strong></td>
                                <td><?php echo $tl['tasks_completed']; ?> tasks completed</td>
                                <td style="color:var(--success); font-weight:600;">+<?php echo number_format($tl['total_rewards'], 2); ?> TC</td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center; color:var(--text-muted);">No task completions recorded.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
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
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>