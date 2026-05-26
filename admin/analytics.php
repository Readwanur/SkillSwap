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

// --- OLAP STAR-SCHEMA ANALYTICS ---
// 1. Quarter-over-Quarter (QoQ) Growth
$qoq_result = $conn->query("
    WITH quarterly_totals AS (
        SELECT 
            t.year, 
            t.quarter, 
            SUM(f.credits) AS total_credits,
            COUNT(f.session_id) AS total_sessions
        FROM vw_fact_sessions f
        JOIN vw_dim_time t ON f.date_key = t.date_key
        WHERE f.status = 'completed'
        GROUP BY t.year, t.quarter
    )
    SELECT 
        year, 
        quarter, 
        total_credits, 
        total_sessions,
        LAG(total_credits) OVER (ORDER BY year, quarter) AS prev_quarter_credits,
        ROUND(
            (total_credits - LAG(total_credits) OVER (ORDER BY year, quarter)) * 100.0 / 
            COALESCE(LAG(total_credits) OVER (ORDER BY year, quarter), 1),
            1
        ) AS qoq_growth_pct
    FROM quarterly_totals
    ORDER BY year DESC, quarter DESC
    LIMIT 8
");

// 2. OLAP Cube Pivot (WITH ROLLUP)
$rollup_result = $conn->query("
    SELECT 
        s.category,
        s.difficulty_level,
        SUM(f.minutes) AS total_minutes,
        SUM(f.credits) AS total_credits,
        COUNT(f.session_id) AS session_count
    FROM vw_fact_sessions f
    JOIN vw_dim_skills s ON f.skill_id = s.skill_id
    WHERE f.status = 'completed'
    GROUP BY s.category, s.difficulty_level WITH ROLLUP
    LIMIT 25
");

// 3. 7-Day Moving Average
$moving_avg_result = $conn->query("
    WITH daily_totals AS (
        SELECT 
            t.full_date,
            SUM(f.credits) AS daily_credits
        FROM vw_fact_sessions f
        JOIN vw_dim_time t ON f.date_key = t.date_key
        WHERE f.status = 'completed'
        GROUP BY t.full_date
    )
    SELECT 
        full_date,
        daily_credits,
        ROUND(
            AVG(daily_credits) OVER (
                ORDER BY full_date 
                ROWS BETWEEN 6 PRECEDING AND CURRENT ROW
            ),
            2
        ) AS moving_avg
    FROM daily_totals
    ORDER BY full_date DESC
    LIMIT 10
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

// --- OLAP BI: Demand-to-Supply Ratio ---
$demand_supply = $conn->query("
    SELECT
        s.skill_name,
        s.catagory,
        COALESCE(d.demand_count, 0) AS demand_count,
        COALESCE(o.supply_count, 0) AS supply_count,
        CASE
            WHEN COALESCE(o.supply_count, 0) = 0 THEN 999.99
            ELSE ROUND(COALESCE(d.demand_count, 0) * 1.0 / o.supply_count, 2)
        END AS demand_supply_ratio,
        CASE
            WHEN COALESCE(o.supply_count, 0) = 0 THEN 'Critical Gap ⛔'
            WHEN COALESCE(d.demand_count, 0) * 1.0 / o.supply_count > 3 THEN 'High Demand 🔥'
            WHEN COALESCE(d.demand_count, 0) * 1.0 / o.supply_count > 1.5 THEN 'Growing 📈'
            WHEN COALESCE(d.demand_count, 0) * 1.0 / o.supply_count >= 0.8 THEN 'Balanced ⚖️'
            ELSE 'Over-Supplied 📦'
        END AS market_status
    FROM skills s
    LEFT JOIN (
        SELECT skill_id, COUNT(*) AS demand_count
        FROM user_skills_requested
        GROUP BY skill_id
    ) d ON s.skill_id = d.skill_id
    LEFT JOIN (
        SELECT skill_id, COUNT(*) AS supply_count
        FROM user_skills_offered
        GROUP BY skill_id
    ) o ON s.skill_id = o.skill_id
    WHERE COALESCE(d.demand_count, 0) > 0 OR COALESCE(o.supply_count, 0) > 0
    ORDER BY demand_supply_ratio DESC
    LIMIT 15
");

// --- OLAP BI: Skill Popularity Percentiles (NTILE) ---
$skill_percentiles = $conn->query("
    WITH skill_activity AS (
        SELECT
            s.skill_id,
            s.skill_name,
            s.catagory,
            COUNT(es.session_id) AS total_sessions,
            COALESCE(SUM(es.session_duration), 0) AS total_minutes,
            COALESCE(ROUND(AVG(es.rating), 2), 0) AS avg_rating
        FROM skills s
        LEFT JOIN exchange_sessions es ON s.skill_id = es.skill_id AND es.status = 'completed'
        GROUP BY s.skill_id
        HAVING total_sessions > 0
    )
    SELECT
        skill_name,
        catagory,
        total_sessions,
        ROUND(total_minutes / 60.0, 1) AS total_hours,
        avg_rating,
        NTILE(4) OVER (ORDER BY total_sessions DESC) AS popularity_quartile,
        PERCENT_RANK() OVER (ORDER BY total_sessions DESC) AS pct_rank
    FROM skill_activity
    ORDER BY popularity_quartile ASC, total_sessions DESC
    LIMIT 20
");

// --- OLAP BI: Month-over-Month (MoM) Session Booking Growth ---
$mom_growth = $conn->query("
    WITH monthly_bookings AS (
        SELECT
            DATE_FORMAT(scheduled_time, '%Y-%m') AS month,
            COUNT(*) AS total_bookings,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            ROUND(AVG(session_duration), 0) AS avg_duration_mins
        FROM exchange_sessions
        GROUP BY month
    )
    SELECT
        month,
        total_bookings,
        completed,
        cancelled,
        avg_duration_mins,
        LAG(total_bookings) OVER (ORDER BY month) AS prev_month_bookings,
        CASE
            WHEN LAG(total_bookings) OVER (ORDER BY month) IS NULL THEN NULL
            WHEN LAG(total_bookings) OVER (ORDER BY month) = 0 THEN 100.0
            ELSE ROUND(
                (total_bookings - LAG(total_bookings) OVER (ORDER BY month)) * 100.0 /
                LAG(total_bookings) OVER (ORDER BY month),
                1
            )
        END AS mom_growth_pct,
        SUM(total_bookings) OVER (ORDER BY month) AS cumulative_bookings
    FROM monthly_bookings
    ORDER BY month DESC
    LIMIT 12
");

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

<!-- ============================================================ -->
<!-- OLAP DATA WAREHOUSE REPORTS -->
<!-- ============================================================ -->
<div class="card mb-3" style="border: 2px solid var(--primary);">
    <div class="card-header" style="background: var(--primary-glow); border-bottom: 1px solid var(--border-light); padding: 15px 20px;">
        <div>
            <h2 style="color: var(--primary); font-family: var(--font-headline); font-weight: 700; margin: 0; font-size: 1.3rem;">📊 OLAP Data Warehouse Analytics</h2>
            <p style="color: var(--text-muted); font-size: 0.82rem; margin-top: 4px;">Advanced reporting engine executing cross-dimensional aggregations over Fact & Dimension views.</p>
        </div>
        <span class="badge badge-success" style="font-size: 0.75rem;">Star-Schema Architecture</span>
    </div>
    
    <div style="padding: 20px;">
        <div class="grid-2 mb-3">
            <!-- Quarter-over-Quarter Growth -->
            <div class="card" style="background: var(--bg-primary); border: 1px solid var(--border-light);">
                <div class="card-header" style="padding: 10px 15px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-light);">
                    <h4 style="margin:0; font-size:0.9rem; color:var(--primary);">Quarter-over-Quarter (QoQ) Growth</h4>
                </div>
                <div class="table-wrapper">
                    <table style="font-size: 0.8rem; background: var(--bg-secondary);">
                        <thead>
                            <tr style="background: var(--bg-primary);">
                                <th>Quarter</th>
                                <th style="text-align: right;">Total Credits</th>
                                <th style="text-align: center;">Sessions</th>
                                <th style="text-align: right;">QoQ Growth</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($qoq_result && $qoq_result->num_rows > 0): ?>
                                <?php while ($q = $qoq_result->fetch_assoc()): 
                                    $growth = $q['qoq_growth_pct'];
                                    $growth_color = 'var(--text-secondary)';
                                    $growth_text = $growth . '%';
                                    if ($growth > 0) {
                                        $growth_color = 'var(--success)';
                                        $growth_text = '▲ +' . $growth . '%';
                                    } elseif ($growth < 0) {
                                        $growth_color = 'var(--danger)';
                                        $growth_text = '▼ ' . $growth . '%';
                                    }
                                ?>
                                    <tr style="border-bottom: 1px solid var(--border-light);">
                                        <td><strong><?php echo $q['year'] . ' Q' . $q['quarter']; ?></strong></td>
                                        <td style="text-align: right; font-weight: 600;"><?php echo number_format($q['total_credits'], 2); ?> TC</td>
                                        <td style="text-align: center; color: var(--text-muted);"><?php echo $q['total_sessions']; ?></td>
                                        <td style="text-align: right; font-weight: bold; color: <?php echo $growth_color; ?>;"><?php echo $growth_text; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 15px;">No historical quarter data available.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 7-Day Moving Average -->
            <div class="card" style="background: var(--bg-primary); border: 1px solid var(--border-light);">
                <div class="card-header" style="padding: 10px 15px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-light);">
                    <h4 style="margin:0; font-size:0.9rem; color:var(--primary);">7-Day Moving Average of Exchanges</h4>
                </div>
                <div class="table-wrapper">
                    <table style="font-size: 0.8rem; background: var(--bg-secondary);">
                        <thead>
                            <tr style="background: var(--bg-primary);">
                                <th>Date</th>
                                <th style="text-align: right;">Daily Credits</th>
                                <th style="text-align: right;">7-Day Moving Avg</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($moving_avg_result && $moving_avg_result->num_rows > 0): ?>
                                <?php while ($m = $moving_avg_result->fetch_assoc()): ?>
                                    <tr style="border-bottom: 1px solid var(--border-light);">
                                        <td><strong><?php echo date('M d, Y', strtotime($m['full_date'])); ?></strong></td>
                                        <td style="text-align: right; color: var(--text-secondary);"><?php echo number_format($m['daily_credits'], 2); ?> TC</td>
                                        <td style="text-align: right; font-weight: bold; color: var(--info);"><?php echo number_format($m['moving_avg'], 2); ?> TC</td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 15px;">No recent exchange session history.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Multi-Dimensional Roll-up Pivot -->
        <div class="card" style="background: var(--bg-primary); border: 1px solid var(--border-light);">
            <div class="card-header" style="padding: 10px 15px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-light);">
                <h4 style="margin:0; font-size:0.9rem; color:var(--primary);">OLAP Roll-up Pivot (Category & Difficulty)</h4>
            </div>
            <div class="table-wrapper">
                <table style="font-size: 0.8rem; background: var(--bg-secondary);">
                    <thead>
                        <tr style="background: var(--bg-primary);">
                            <th>Skill Category</th>
                            <th>Difficulty Level</th>
                            <th style="text-align: center;">Total Hours</th>
                            <th style="text-align: right;">Total Credits</th>
                            <th style="text-align: center;">Completed Sessions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rollup_result && $rollup_result->num_rows > 0): ?>
                            <?php while ($r = $rollup_result->fetch_assoc()): 
                                $is_category_total = is_null($r['difficulty_level']) && !is_null($r['category']);
                                $is_grand_total = is_null($r['category']) && is_null($r['difficulty_level']);
                                
                                $row_bg = 'transparent';
                                $font_weight = 'normal';
                                $cat_display = htmlspecialchars($r['category'] ?? '');
                                $diff_display = htmlspecialchars($r['difficulty_level'] ?? '');
                                
                                if ($is_grand_total) {
                                    $row_bg = 'var(--primary-glow)';
                                    $font_weight = 'bold';
                                    $cat_display = '✨ GRAND TOTAL';
                                    $diff_display = 'All Difficulties';
                                } elseif ($is_category_total) {
                                    $row_bg = 'var(--bg-hover)';
                                    $font_weight = '600';
                                    $cat_display = '📁 ' . $cat_display;
                                    $diff_display = 'Subtotal';
                                }
                            ?>
                                <tr style="background: <?php echo $row_bg; ?>; font-weight: <?php echo $font_weight; ?>; border-bottom: 1px solid var(--border-light);">
                                    <td><strong><?php echo $cat_display; ?></strong></td>
                                    <td><?php echo $diff_display; ?></td>
                                    <td style="text-align: center;"><?php echo round($r['total_minutes'] / 60.0, 1); ?>h</td>
                                    <td style="text-align: right;"><?php echo number_format($r['total_credits'], 2); ?> TC</td>
                                    <td style="text-align: center; color: var(--text-muted);"><?php echo $r['session_count']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 15px;">No aggregated OLAP cube data found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- BUSINESS INTELLIGENCE OLAP PANELS -->
<!-- ============================================================ -->
<div class="card mb-3" style="border: 2px solid var(--info);">
    <div class="card-header" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.08), rgba(14, 165, 233, 0.08)); border-bottom: 1px solid var(--border-light); padding: 15px 20px;">
        <div>
            <h2 style="color: var(--info); font-family: var(--font-headline); font-weight: 700; margin: 0; font-size: 1.3rem;">🧠 Business Intelligence Analytics</h2>
            <p style="color: var(--text-muted); font-size: 0.82rem; margin-top: 4px;">Advanced market metrics using NTILE(), LAG(), correlated subqueries, and demand-supply ratio analysis.</p>
        </div>
        <span class="badge badge-info" style="font-size: 0.75rem;">Window Functions + CTEs</span>
    </div>

    <div style="padding: 20px;">

        <!-- Demand-to-Supply Ratio -->
        <div class="card mb-3" style="background: var(--bg-primary); border: 1px solid var(--border-light);">
            <div class="card-header" style="padding: 12px 15px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-light);">
                <h4 style="margin:0; font-size:0.95rem; color:var(--primary);">📊 Demand-to-Supply Ratio (Market Economics)</h4>
                <span class="badge badge-warning" style="font-size: 0.7rem;">Correlated Subqueries</span>
            </div>
            <div class="table-wrapper">
                <table style="font-size: 0.82rem; background: var(--bg-secondary);">
                    <thead>
                        <tr style="background: var(--bg-primary);">
                            <th>Skill</th>
                            <th>Category</th>
                            <th style="text-align: center;">Demand (Learners)</th>
                            <th style="text-align: center;">Supply (Providers)</th>
                            <th style="text-align: right;">D:S Ratio</th>
                            <th style="text-align: center;">Market Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($demand_supply && $demand_supply->num_rows > 0): ?>
                            <?php while ($ds = $demand_supply->fetch_assoc()):
                                $ratio = $ds['demand_supply_ratio'];
                                $ratio_color = 'var(--text-secondary)';
                                if ($ratio >= 999) $ratio_color = 'var(--danger)';
                                elseif ($ratio > 3) $ratio_color = '#ef4444';
                                elseif ($ratio > 1.5) $ratio_color = 'var(--warning)';
                                elseif ($ratio >= 0.8) $ratio_color = 'var(--success)';
                                else $ratio_color = 'var(--info)';

                                $status_badge = 'badge-info';
                                if (strpos($ds['market_status'], 'Critical') !== false) $status_badge = 'badge-danger';
                                elseif (strpos($ds['market_status'], 'High') !== false) $status_badge = 'badge-warning';
                                elseif (strpos($ds['market_status'], 'Growing') !== false) $status_badge = 'badge-orange';
                                elseif (strpos($ds['market_status'], 'Balanced') !== false) $status_badge = 'badge-success';
                            ?>
                                <tr style="border-bottom: 1px solid var(--border-light);">
                                    <td><strong><?php echo htmlspecialchars($ds['skill_name']); ?></strong></td>
                                    <td><span class="badge badge-orange"><?php echo htmlspecialchars($ds['catagory'] ?? 'General'); ?></span></td>
                                    <td style="text-align: center; color: var(--danger); font-weight: 600;"><?php echo $ds['demand_count']; ?></td>
                                    <td style="text-align: center; color: var(--success); font-weight: 600;"><?php echo $ds['supply_count']; ?></td>
                                    <td style="text-align: right; font-weight: bold; color: <?php echo $ratio_color; ?>;">
                                        <?php echo $ratio >= 999 ? '∞' : $ratio . ':1'; ?>
                                    </td>
                                    <td style="text-align: center;"><span class="badge <?php echo $status_badge; ?>"><?php echo $ds['market_status']; ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 15px;">No demand/supply data available.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid-2 mb-3">
            <!-- Skill Popularity Percentiles (NTILE) -->
            <div class="card" style="background: var(--bg-primary); border: 1px solid var(--border-light);">
                <div class="card-header" style="padding: 12px 15px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-light);">
                    <h4 style="margin:0; font-size:0.95rem; color:var(--primary);">🏅 Skill Popularity Percentiles</h4>
                    <span class="badge badge-success" style="font-size: 0.7rem;">NTILE(4) OVER()</span>
                </div>
                <div class="table-wrapper">
                    <table style="font-size: 0.82rem; background: var(--bg-secondary);">
                        <thead>
                            <tr style="background: var(--bg-primary);">
                                <th>Skill</th>
                                <th style="text-align: center;">Sessions</th>
                                <th style="text-align: center;">Hours</th>
                                <th style="text-align: center;">Rating</th>
                                <th style="text-align: center;">Quartile</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($skill_percentiles && $skill_percentiles->num_rows > 0): ?>
                                <?php while ($sp = $skill_percentiles->fetch_assoc()):
                                    $q = (int)$sp['popularity_quartile'];
                                    $quartile_labels = [1 => 'Top 25% 🥇', 2 => 'Top 50% 🥈', 3 => 'Top 75% 🥉', 4 => 'Bottom 25%'];
                                    $quartile_badges = [1 => 'badge-success', 2 => 'badge-info', 3 => 'badge-warning', 4 => 'badge-danger'];
                                    $pct = round((1 - (float)$sp['pct_rank']) * 100, 0);
                                ?>
                                    <tr style="border-bottom: 1px solid var(--border-light);">
                                        <td>
                                            <strong><?php echo htmlspecialchars($sp['skill_name']); ?></strong>
                                            <br><small style="color:var(--text-muted);"><?php echo htmlspecialchars($sp['catagory'] ?? 'General'); ?></small>
                                        </td>
                                        <td style="text-align: center; font-weight: 600;"><?php echo $sp['total_sessions']; ?></td>
                                        <td style="text-align: center; color: var(--text-muted);"><?php echo $sp['total_hours']; ?>h</td>
                                        <td style="text-align: center;">⭐ <?php echo $sp['avg_rating']; ?></td>
                                        <td style="text-align: center;">
                                            <span class="badge <?php echo $quartile_badges[$q] ?? 'badge-info'; ?>">
                                                <?php echo $quartile_labels[$q] ?? 'Q'.$q; ?>
                                            </span>
                                            <br><small style="color:var(--text-muted);">Top <?php echo $pct; ?>%</small>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 15px;">No completed sessions to rank.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Month-over-Month Growth -->
            <div class="card" style="background: var(--bg-primary); border: 1px solid var(--border-light);">
                <div class="card-header" style="padding: 12px 15px; background: var(--bg-secondary); border-bottom: 1px solid var(--border-light);">
                    <h4 style="margin:0; font-size:0.95rem; color:var(--primary);">📈 Month-over-Month Session Growth</h4>
                    <span class="badge badge-info" style="font-size: 0.7rem;">LAG() OVER()</span>
                </div>
                <div class="table-wrapper">
                    <table style="font-size: 0.82rem; background: var(--bg-secondary);">
                        <thead>
                            <tr style="background: var(--bg-primary);">
                                <th>Month</th>
                                <th style="text-align: center;">Bookings</th>
                                <th style="text-align: center;">Completed</th>
                                <th style="text-align: center;">Avg Duration</th>
                                <th style="text-align: right;">MoM Growth</th>
                                <th style="text-align: right;">Cumulative</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($mom_growth && $mom_growth->num_rows > 0): ?>
                                <?php while ($mg = $mom_growth->fetch_assoc()):
                                    $mom_pct = $mg['mom_growth_pct'];
                                    $mom_color = 'var(--text-secondary)';
                                    $mom_text = '—';
                                    if ($mom_pct !== null) {
                                        if ($mom_pct > 0) {
                                            $mom_color = 'var(--success)';
                                            $mom_text = '▲ +' . $mom_pct . '%';
                                        } elseif ($mom_pct < 0) {
                                            $mom_color = 'var(--danger)';
                                            $mom_text = '▼ ' . $mom_pct . '%';
                                        } else {
                                            $mom_text = '→ 0%';
                                        }
                                    }
                                ?>
                                    <tr style="border-bottom: 1px solid var(--border-light);">
                                        <td><strong><?php echo $mg['month']; ?></strong></td>
                                        <td style="text-align: center; font-weight: 600;"><?php echo $mg['total_bookings']; ?></td>
                                        <td style="text-align: center; color: var(--success);"><?php echo $mg['completed']; ?></td>
                                        <td style="text-align: center; color: var(--text-muted);"><?php echo $mg['avg_duration_mins']; ?> min</td>
                                        <td style="text-align: right; font-weight: bold; color: <?php echo $mom_color; ?>;"><?php echo $mom_text; ?></td>
                                        <td style="text-align: right; font-weight: 600;"><?php echo $mg['cumulative_bookings']; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 15px;">No monthly booking data found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>