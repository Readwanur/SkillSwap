<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'Leaderboard';

// ============================================================
// FEATURE 4: CATEGORY-BASED LEADERBOARDS & TIERED BADGES
// ============================================================

// Get selected category filter
$filter_category = trim($_GET['category'] ?? 'all');

// --- CQ: Get all categories with session activity ---
$categories = $conn->query("
    SELECT DISTINCT s.catagory 
    FROM skills s 
    JOIN exchange_sessions es ON s.skill_id = es.skill_id 
    WHERE es.status = 'completed' AND s.catagory IS NOT NULL
    ORDER BY s.catagory
");
$cat_list = [];
while ($c = $categories->fetch_assoc()) {
    $cat_list[] = $c['catagory'];
}

// Build category filter clause
$cat_filter = "";
if ($filter_category !== 'all' && !empty($filter_category)) {
    $safe_cat = $conn->real_escape_string($filter_category);
    $cat_filter = "AND s.catagory = '$safe_cat'";
}

// Sorting parameters
$sort = trim($_GET['sort'] ?? 'rank');
$order = trim($_GET['order'] ?? 'asc');

$allowed_sorts = [
    'rank' => 'category_rank',
    'name' => 'name',
    'sessions' => 'session_count',
    'rating' => 'avg_rating',
    'hours' => 'hours_taught',
    'reliability' => 'reliability',
    'score' => 'composite_score'
];

$sort_col = $allowed_sorts[$sort] ?? 'category_rank';
$order_sql = (strtolower($order) === 'desc') ? 'DESC' : 'ASC';

// --- CQ: Category-Based Leaderboard (CTE + DENSE_RANK + Composite Score) ---
// Composite score: 40% sessions + 30% rating*6 + 20% reliability*4 + 10% hours
// Uses DENSE_RANK() OVER (PARTITION BY category) for per-category ranking
// CASE WHEN assigns badge tier based on rank position
$leaderboard = $conn->query("
    WITH provider_scores AS (
        SELECT 
            es.provider_id,
            u.user_id,
            u.name,
            s.catagory AS category,
            COUNT(*) AS session_count,
            ROUND(AVG(es.rating), 2) AS avg_rating,
            SUM(es.session_duration) AS total_minutes,
            r.current_score AS reliability,
            r.mentor_level,
            ROUND(
                (COUNT(*) * 0.4) + 
                (COALESCE(AVG(es.rating), 0) * 6 * 0.3) + 
                (COALESCE(r.current_score, 5) * 4 * 0.2) + 
                (COALESCE(SUM(es.session_duration), 0) / 60.0 * 0.1)
            , 2) AS composite_score
        FROM exchange_sessions es
        JOIN skills s ON es.skill_id = s.skill_id
        JOIN users u ON es.provider_id = u.user_id
        LEFT JOIN reputation r ON u.user_id = r.user_id
        WHERE es.status = 'completed' AND s.catagory IS NOT NULL
        $cat_filter
        GROUP BY es.provider_id, s.catagory
    )
    SELECT *,
        DENSE_RANK() OVER (PARTITION BY category ORDER BY composite_score DESC) AS category_rank,
        ROUND(total_minutes / 60.0, 1) AS hours_taught,
        CASE 
            WHEN DENSE_RANK() OVER (PARTITION BY category ORDER BY composite_score DESC) = 1 THEN 'gold'
            WHEN DENSE_RANK() OVER (PARTITION BY category ORDER BY composite_score DESC) = 2 THEN 'silver'
            WHEN DENSE_RANK() OVER (PARTITION BY category ORDER BY composite_score DESC) = 3 THEN 'bronze'
            ELSE 'top10'
        END AS badge_tier
    FROM provider_scores
    WHERE 1=1
    ORDER BY category ASC, $sort_col $order_sql
");

// Organize by category
$leaderboard_data = [];
if ($leaderboard && $leaderboard->num_rows > 0) {
    while ($row = $leaderboard->fetch_assoc()) {
        $leaderboard_data[$row['category']][] = $row;
    }
}

// --- CQ: Get the current user's ranks across all categories ---
$my_ranks = $conn->query("
    WITH provider_scores AS (
        SELECT 
            es.provider_id,
            s.catagory AS category,
            ROUND(
                (COUNT(*) * 0.4) + 
                (COALESCE(AVG(es.rating), 0) * 6 * 0.3) + 
                (COALESCE(r.current_score, 5) * 4 * 0.2) + 
                (COALESCE(SUM(es.session_duration), 0) / 60.0 * 0.1)
            , 2) AS composite_score
        FROM exchange_sessions es
        JOIN skills s ON es.skill_id = s.skill_id
        LEFT JOIN reputation r ON es.provider_id = r.user_id
        WHERE es.status = 'completed' AND s.catagory IS NOT NULL
        GROUP BY es.provider_id, s.catagory
    ),
    ranked AS (
        SELECT *, DENSE_RANK() OVER (PARTITION BY category ORDER BY composite_score DESC) AS rank_pos
        FROM provider_scores
    )
    SELECT category, rank_pos, composite_score FROM ranked WHERE provider_id = $user_id
");

$my_rank_data = [];
if ($my_ranks) {
    while ($r = $my_ranks->fetch_assoc()) {
        $my_rank_data[$r['category']] = $r;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <div class="flex justify-between items-center" style="flex-wrap:wrap; gap:12px;">
            <div>
                <h1 class="page-title" style="margin-bottom:4px;"><i data-lucide="award" class="lucide-sm"></i> Leaderboard</h1>
                <p style="color:var(--text-muted); font-size:0.85rem;">Category-based rankings with composite scoring using DENSE_RANK()</p>
            </div>
            <div class="flex gap-1">
                <a href="../pages/market_trends.php" class="btn btn-sm btn-secondary"><i data-lucide="bar-chart" class="lucide-sm"></i> Insights</a>
                <a href="../pages/smart_matches.php" class="btn btn-sm btn-secondary"><i data-lucide="link" class="lucide-sm"></i> Matches</a>
            </div>
        </div>

        <!-- My Rank Summary -->
        <?php if (!empty($my_rank_data)): ?>
            <div class="card mt-3" style="border: 2px solid var(--primary); background:var(--primary-glow);">
                <h3 style="margin-bottom:10px;"><i data-lucide="map-pin" class="lucide-sm"></i> Your Rankings</h3>
                <div class="flex gap-2 flex-wrap">
                    <?php foreach ($my_rank_data as $cat => $rank): 
                        $rank_badge = '<i data-lucide="medal" class="lucide-sm"></i>';
                        if ($rank['rank_pos'] == 1) $rank_badge = '<i data-lucide="medal" style="color: gold;" class="lucide-sm"></i>';
                        elseif ($rank['rank_pos'] == 2) $rank_badge = '<i data-lucide="medal" style="color: silver;" class="lucide-sm"></i>';
                        elseif ($rank['rank_pos'] == 3) $rank_badge = '<i data-lucide="medal" style="color: #cd7f32;" class="lucide-sm"></i>';
                    ?>
                        <div style="background:var(--bg-card); border-radius:var(--radius-sm); padding:10px 16px; text-align:center; border:1px solid var(--border-light);">
                            <span style="font-size:1.4rem;"><?php echo $rank_badge; ?></span>
                            <div style="font-weight:700; font-size:1rem; color:var(--primary);">#<?php echo $rank['rank_pos']; ?></div>
                            <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;"><?php echo htmlspecialchars($cat); ?></div>
                            <div style="font-size:0.8rem; color:var(--text-secondary); margin-top:2px;">Score: <?php echo $rank['composite_score']; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Category Filter Tabs -->
        <div class="mt-3" style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:20px;">
            <a href="?category=all" class="filter-tab <?php echo $filter_category === 'all' ? 'active' : ''; ?>">All Categories</a>
            <?php foreach ($cat_list as $cat): ?>
                <a href="?category=<?php echo urlencode($cat); ?>" class="filter-tab <?php echo $filter_category === $cat ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($cat); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Leaderboard Tables -->
        <?php if (!empty($leaderboard_data)): ?>
            <?php foreach ($leaderboard_data as $category => $providers): ?>
                <div class="card mb-3">
                    <div class="card-header">
                        <h3><?php echo htmlspecialchars($category); ?></h3>
                        <span class="badge badge-orange"><?php echo count($providers); ?> providers ranked</span>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>
                                        <span class="th-content">
                                            <span>Rank</span>
                                            <?php echo renderTableSort('rank', $sort, $order); ?>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="th-content">
                                            <span>Provider</span>
                                            <?php echo renderTableSort('name', $sort, $order); ?>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="th-content">
                                            <span>Sessions</span>
                                            <?php echo renderTableSort('sessions', $sort, $order); ?>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="th-content">
                                            <span>Avg Rating</span>
                                            <?php echo renderTableSort('rating', $sort, $order); ?>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="th-content">
                                            <span>Hours Taught</span>
                                            <?php echo renderTableSort('hours', $sort, $order); ?>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="th-content">
                                            <span>Reliability</span>
                                            <?php echo renderTableSort('reliability', $sort, $order); ?>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="th-content">
                                            <span>Composite Score</span>
                                            <?php echo renderTableSort('score', $sort, $order); ?>
                                        </span>
                                    </th>
                                    <th>Badge</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($providers as $p):
                                    $is_me = ($p['user_id'] == $user_id);
                                    $badge_icon = '<i data-lucide="medal" class="lucide-sm"></i>';
                                    $badge_label = 'Top 10';
                                    $badge_class = 'badge-info';
                                    if ($p['badge_tier'] === 'gold') { $badge_icon = '<i data-lucide="medal" style="color: gold;" class="lucide-sm"></i>'; $badge_label = 'Gold'; $badge_class = 'badge-warning'; }
                                    elseif ($p['badge_tier'] === 'silver') { $badge_icon = '<i data-lucide="medal" style="color: silver;" class="lucide-sm"></i>'; $badge_label = 'Silver'; $badge_class = 'badge-info'; }
                                    elseif ($p['badge_tier'] === 'bronze') { $badge_icon = '<i data-lucide="medal" style="color: #cd7f32;" class="lucide-sm"></i>'; $badge_label = 'Bronze'; $badge_class = 'badge-orange'; }
                                    $row_style = $is_me ? 'background: rgba(0,56,108,0.06); font-weight:500;' : '';
                                ?>
                                    <tr style="<?php echo $row_style; ?>">
                                        <td>
                                            <span style="font-size:1.1rem;"><?php echo $badge_icon; ?></span>
                                            <strong>#<?php echo $p['category_rank']; ?></strong>
                                        </td>
                                        <td>
                                            <a href="user_profile.php?id=<?php echo $p['user_id']; ?>" style="font-weight:600; color:var(--primary); text-decoration:none;">
                                                <?php echo htmlspecialchars($p['name']); ?>
                                            </a>
                                            <?php if ($is_me): ?>
                                                <span class="badge badge-success" style="font-size:0.65rem; margin-left:4px;">YOU</span>
                                            <?php endif; ?>
                                            <br><span style="font-size:0.75rem; color:var(--text-muted);"><?php echo htmlspecialchars($p['mentor_level']); ?></span>
                                        </td>
                                        <td style="font-weight:600;"><?php echo $p['session_count']; ?></td>
                                        <td><i data-lucide="star" class="lucide-sm"></i> <?php echo $p['avg_rating'] ?? 'N/A'; ?></td>
                                        <td><?php echo $p['hours_taught']; ?>h</td>
                                        <td style="color:var(--success); font-weight:600;"><?php echo $p['reliability']; ?>/5</td>
                                        <td style="font-weight:700; color:var(--primary);"><?php echo $p['composite_score']; ?></td>
                                        <td><span class="badge <?php echo $badge_class; ?>"><?php echo $badge_label; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card mt-3">
                <div class="empty-state">
                    <div class="icon"><i data-lucide="award" class="lucide-sm"></i></div>
                    <p>No leaderboard data available yet.</p>
                    <p style="font-size:0.85rem; color:var(--text-muted); margin-top:8px;">
                        Complete teaching sessions to appear on the leaderboard!
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
