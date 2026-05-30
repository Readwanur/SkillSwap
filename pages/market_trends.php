<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'Market Trends & Insights';

// ============================================================
// FEATURE 1: MARKET TRENDS & SKILL DEMAND ANALYTICS
// ============================================================

// --- CQ: Scarcity Ratio (Demand / Supply per skill) ---
// Uses LEFT JOIN on derived tables (aggregate subqueries) to compute
// demand_count, supply_count, and scarcity_ratio per skill.
$scarcity = $conn->query("
    SELECT
        s.skill_id, s.skill_name, s.catagory,
        COALESCE(d.demand_count, 0) AS demand_count,
        COALESCE(o.supply_count, 0) AS supply_count,
        CASE
            WHEN COALESCE(o.supply_count, 0) = 0 AND COALESCE(d.demand_count, 0) > 0 THEN 999.99
            WHEN COALESCE(o.supply_count, 0) = 0 THEN 0
            ELSE ROUND(COALESCE(d.demand_count, 0) * 1.0 / o.supply_count, 2)
        END AS scarcity_ratio,
        COALESCE(sess.completed_sessions, 0) AS completed_sessions,
        COALESCE(sess.avg_tc_cost, 0) AS avg_tc_cost,
        CASE
            WHEN COALESCE(o.supply_count, 0) = 0 AND COALESCE(d.demand_count, 0) > 0 THEN 'Critical Gap'
            WHEN COALESCE(o.supply_count, 0) = 0 THEN 'No Activity'
            WHEN COALESCE(d.demand_count, 0) * 1.0 / o.supply_count > 3 THEN 'High Demand'
            WHEN COALESCE(d.demand_count, 0) * 1.0 / o.supply_count > 1.5 THEN 'Growing'
            WHEN COALESCE(d.demand_count, 0) * 1.0 / o.supply_count >= 0.8 THEN 'Balanced'
            ELSE 'Over-Supplied'
        END AS market_status
    FROM skills s
    LEFT JOIN (
        SELECT skill_id, COUNT(*) AS demand_count FROM user_skills_requested GROUP BY skill_id
    ) d ON s.skill_id = d.skill_id
    LEFT JOIN (
        SELECT skill_id, COUNT(*) AS supply_count FROM user_skills_offered GROUP BY skill_id
    ) o ON s.skill_id = o.skill_id
    LEFT JOIN (
        SELECT skill_id, COUNT(*) AS completed_sessions, ROUND(AVG(time_credit_transfer), 2) AS avg_tc_cost
        FROM exchange_sessions WHERE status = 'completed'
        GROUP BY skill_id
    ) sess ON s.skill_id = sess.skill_id
    WHERE COALESCE(d.demand_count, 0) > 0 OR COALESCE(o.supply_count, 0) > 0
    ORDER BY scarcity_ratio DESC
");

// --- CQ: Trending Skills (Month-over-Month growth via LAG window function) ---
// Uses CTE + LAG() to compare current month session count to previous month.
$trending = $conn->query("
    WITH monthly_skill_sessions AS (
        SELECT
            es.skill_id,
            s.skill_name,
            s.catagory,
            DATE_FORMAT(es.scheduled_time, '%Y-%m') AS month,
            COUNT(*) AS session_count
        FROM exchange_sessions es
        JOIN skills s ON es.skill_id = s.skill_id
        WHERE es.status = 'completed'
        GROUP BY es.skill_id, month
    ),
    with_lag AS (
        SELECT *,
            LAG(session_count) OVER (PARTITION BY skill_id ORDER BY month) AS prev_month_count
        FROM monthly_skill_sessions
    )
    SELECT
        skill_id, skill_name, catagory, month, session_count, prev_month_count,
        CASE
            WHEN prev_month_count IS NULL THEN NULL
            WHEN prev_month_count = 0 THEN 100.0
            ELSE ROUND((session_count - prev_month_count) * 100.0 / prev_month_count, 1)
        END AS growth_pct
    FROM with_lag
    ORDER BY month DESC, growth_pct DESC
    LIMIT 20
");

// --- CQ: Category Heatmap (Aggregated sessions, providers, learners per category) ---
// Uses nested correlated subqueries with GROUP BY.
$category_heat = $conn->query("
    SELECT
        s.catagory AS category,
        COUNT(DISTINCT s.skill_id) AS skill_count,
        COALESCE(SUM(DISTINCT uso_cnt.cnt), 0) AS total_providers,
        COALESCE(SUM(DISTINCT usr_cnt.cnt), 0) AS total_learners,
        (SELECT COUNT(*) FROM exchange_sessions es2
         JOIN skills sk ON es2.skill_id = sk.skill_id
         WHERE sk.catagory = s.catagory AND es2.status = 'completed') AS total_sessions,
        (SELECT ROUND(AVG(es3.rating), 1) FROM exchange_sessions es3
         JOIN skills sk2 ON es3.skill_id = sk2.skill_id
         WHERE sk2.catagory = s.catagory AND es3.rating IS NOT NULL) AS avg_rating
    FROM skills s
    LEFT JOIN (
        SELECT uso.skill_id, COUNT(*) AS cnt FROM user_skills_offered uso GROUP BY uso.skill_id
    ) uso_cnt ON s.skill_id = uso_cnt.skill_id
    LEFT JOIN (
        SELECT usr.skill_id, COUNT(*) AS cnt FROM user_skills_requested usr GROUP BY usr.skill_id
    ) usr_cnt ON s.skill_id = usr_cnt.skill_id
    WHERE s.catagory IS NOT NULL
    GROUP BY s.catagory
    ORDER BY total_sessions DESC
");

// --- CQ: Platform-wide summary stats ---
$total_skills = $conn->query("SELECT COUNT(*) AS cnt FROM skills")->fetch_assoc()['cnt'];
$total_providers = $conn->query("SELECT COUNT(DISTINCT user_id) AS cnt FROM user_skills_offered")->fetch_assoc()['cnt'];
$total_learners = $conn->query("SELECT COUNT(DISTINCT user_id) AS cnt FROM user_skills_requested")->fetch_assoc()['cnt'];
$avg_scarcity = $conn->query("
    SELECT ROUND(AVG(CASE WHEN o.cnt > 0 THEN d.cnt * 1.0 / o.cnt ELSE NULL END), 2) AS avg_ratio
    FROM (SELECT skill_id, COUNT(*) cnt FROM user_skills_requested GROUP BY skill_id) d
    JOIN (SELECT skill_id, COUNT(*) cnt FROM user_skills_offered GROUP BY skill_id) o ON d.skill_id = o.skill_id
")->fetch_assoc()['avg_ratio'];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <div class="flex justify-between items-center" style="flex-wrap:wrap; gap:12px;">
            <div>
                <h1 class="page-title" style="margin-bottom:4px;"><i data-lucide="bar-chart" class="lucide-sm"></i> Market Trends & Insights</h1>
                <p style="color:var(--text-muted); font-size:0.85rem;">Real-time skill demand analytics powered by complex SQL aggregations</p>
            </div>
            <div class="flex gap-1">
                <a href="../pages/smart_matches.php" class="btn btn-sm btn-secondary"><i data-lucide="link" class="lucide-sm"></i> Smart Matches</a>
                <a href="../pages/leaderboard.php" class="btn btn-sm btn-secondary"><i data-lucide="award" class="lucide-sm"></i> Leaderboard</a>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="stats-grid mt-3">
            <div class="stat-card stat-card-accent" style="--accent: var(--primary);">
                <span class="stat-icon"><i data-lucide="library" class="lucide-sm"></i></span>
                <span class="stat-value"><?php echo $total_skills; ?></span>
                <span class="stat-label">Total Skills</span>
            </div>
            <div class="stat-card stat-card-accent" style="--accent: var(--success);">
                <span class="stat-icon"><i data-lucide="graduation-cap" class="lucide-sm"></i></span>
                <span class="stat-value"><?php echo $total_providers; ?></span>
                <span class="stat-label">Active Providers</span>
            </div>
            <div class="stat-card stat-card-accent" style="--accent: var(--info);">
                <span class="stat-icon"><i data-lucide="book-open" class="lucide-sm"></i></span>
                <span class="stat-value"><?php echo $total_learners; ?></span>
                <span class="stat-label">Active Learners</span>
            </div>
            <div class="stat-card stat-card-accent" style="--accent: var(--warning);">
                <span class="stat-icon"><i data-lucide="scale" class="lucide-sm"></i></span>
                <span class="stat-value"><?php echo $avg_scarcity ?? 'N/A'; ?></span>
                <span class="stat-label">Avg Scarcity Ratio</span>
            </div>
        </div>

        <div class="grid-2 mt-3">
            <!-- Scarcity Ratio Table -->
            <div class="card">
                <div class="card-header">
                    <h3>Skill Demand vs Supply</h3>
                    <span class="badge badge-info" style="font-size:0.7rem;">Scarcity Ratio = Demand / Supply</span>
                </div>
                <?php if ($scarcity && $scarcity->num_rows > 0): ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Skill</th>
                                    <th>Category</th>
                                    <th>Demand</th>
                                    <th>Supply</th>
                                    <th>Ratio</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $scarcity->fetch_assoc()):
                                    $status_badge = 'badge-info';
                                    if ($row['market_status'] === 'Critical Gap') $status_badge = 'badge-danger';
                                    elseif ($row['market_status'] === 'High Demand') $status_badge = 'badge-warning';
                                    elseif ($row['market_status'] === 'Growing') $status_badge = 'badge-info';
                                    elseif ($row['market_status'] === 'Balanced') $status_badge = 'badge-success';
                                    elseif ($row['market_status'] === 'Over-Supplied') $status_badge = 'badge-orange';
                                ?>
                                    <tr>
                                        <td><a href="skill_detail.php?id=<?php echo $row['skill_id']; ?>" style="font-weight:600;"><?php echo htmlspecialchars($row['skill_name']); ?></a></td>
                                        <td><span class="badge badge-orange"><?php echo htmlspecialchars($row['catagory'] ?? 'N/A'); ?></span></td>
                                        <td style="text-align:center; font-weight:600; color:var(--danger);"><?php echo $row['demand_count']; ?></td>
                                        <td style="text-align:center; font-weight:600; color:var(--success);"><?php echo $row['supply_count']; ?></td>
                                        <td style="font-weight:700;">
                                            <?php echo $row['scarcity_ratio'] >= 999 ? '∞' : $row['scarcity_ratio']; ?>
                                        </td>
                                        <td><span class="badge <?php echo $status_badge; ?>"><?php echo $row['market_status']; ?></span></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><p>No market data available yet.</p></div>
                <?php endif; ?>
            </div>

            <!-- Trending Skills -->
            <div class="card">
                <div class="card-header">
                    <h3><i data-lucide="trending-up" class="lucide-sm"></i> Trending Skills</h3>
                    <span class="badge badge-success" style="font-size:0.7rem;">MoM Growth via LAG()</span>
                </div>
                <?php if ($trending && $trending->num_rows > 0): ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Skill</th>
                                    <th>Month</th>
                                    <th>Sessions</th>
                                    <th>Prev</th>
                                    <th>Growth</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($t = $trending->fetch_assoc()):
                                    $growth = $t['growth_pct'];
                                    $growth_color = 'var(--text-muted)';
                                    $growth_text = 'New';
                                    if ($growth !== null) {
                                        if ($growth > 0) { $growth_color = 'var(--success)'; $growth_text = '▲ +' . $growth . '%'; }
                                        elseif ($growth < 0) { $growth_color = 'var(--danger)'; $growth_text = '▼ ' . $growth . '%'; }
                                        else { $growth_text = '— 0%'; }
                                    }
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($t['skill_name']); ?></strong></td>
                                        <td style="color:var(--text-muted);"><?php echo $t['month']; ?></td>
                                        <td style="font-weight:600;"><?php echo $t['session_count']; ?></td>
                                        <td style="color:var(--text-muted);"><?php echo $t['prev_month_count'] ?? '—'; ?></td>
                                        <td style="font-weight:700; color:<?php echo $growth_color; ?>;"><?php echo $growth_text; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><p>Not enough session data to calculate trends yet.</p></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Category Heatmap -->
        <div class="card mt-3">
            <div class="card-header">
                <h3><i data-lucide="flame" class="lucide-sm"></i> Category Heatmap</h3>
                <span class="badge badge-warning" style="font-size:0.7rem;">Nested Subqueries + GROUP BY</span>
            </div>
            <?php if ($category_heat && $category_heat->num_rows > 0): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Skills</th>
                                <th>Providers</th>
                                <th>Learners</th>
                                <th>Completed Sessions</th>
                                <th>Avg Rating</th>
                                <th>Activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $max_sessions = 1;
                            $cat_rows = [];
                            while ($c = $category_heat->fetch_assoc()) {
                                $cat_rows[] = $c;
                                if ($c['total_sessions'] > $max_sessions) $max_sessions = $c['total_sessions'];
                            }
                            foreach ($cat_rows as $c):
                                $pct = min(($c['total_sessions'] / max($max_sessions, 1)) * 100, 100);
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($c['category']); ?></strong></td>
                                    <td><?php echo $c['skill_count']; ?></td>
                                    <td style="color:var(--success); font-weight:600;"><?php echo $c['total_providers']; ?></td>
                                    <td style="color:var(--info); font-weight:600;"><?php echo $c['total_learners']; ?></td>
                                    <td style="font-weight:600;"><?php echo $c['total_sessions']; ?></td>
                                    <td><i data-lucide="star" class="lucide-sm"></i> <?php echo $c['avg_rating'] ?? 'N/A'; ?></td>
                                    <td style="min-width:120px;">
                                        <div class="progress-bar" style="height:6px;">
                                            <div class="fill" style="width:<?php echo $pct; ?>%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state"><p>No category data available.</p></div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
