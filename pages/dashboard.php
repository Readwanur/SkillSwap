<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'Dashboard';

// --- VIEW: vw_user_dashboard ---
// Single query replaces 4 separate queries (users, wallet, reputation, session count)
// using LEFT JOINs, COALESCE, and correlated scalar subqueries.
$user = $conn->query("SELECT * FROM vw_user_dashboard WHERE user_id = $user_id")->fetch_assoc();
$balance = $user ? number_format($user['wallet_balance'], 2) : '0.00';
$rep_score = $user ? $user['reputation_score'] : '5.00';
$mentor_level = $user ? $user['mentor_level'] : 'Novice';
$guided_sessions_query = $conn->query("SELECT COUNT(*) AS total FROM exchange_sessions WHERE provider_id = $user_id AND status = 'completed'");
$guided_sessions = $guided_sessions_query->fetch_assoc()['total'] ?? 0;

$learned_sessions_query = $conn->query("SELECT COUNT(*) AS total FROM exchange_sessions WHERE requester_id = $user_id AND status = 'completed'");
$learned_sessions = $learned_sessions_query->fetch_assoc()['total'] ?? 0;
// Upcoming sessions (JOIN with users and skills)
$upcoming_q = $conn->query("
    SELECT es.*, s.skill_name,
           u_req.name AS requester_name,
           u_prov.name AS provider_name
    FROM exchange_sessions es
    JOIN skills s ON es.skill_id = s.skill_id
    JOIN users u_req ON es.requester_id = u_req.user_id
    JOIN users u_prov ON es.provider_id = u_prov.user_id
    WHERE (es.requester_id = $user_id OR es.provider_id = $user_id)
      AND es.status = 'scheduled'
    ORDER BY es.scheduled_time ASC
    LIMIT 5
");

// Skills offered
$skills_offered = $conn->query("
    SELECT s.skill_name FROM user_skills_offered uso
    JOIN skills s ON uso.skill_id = s.skill_id
    WHERE uso.user_id = $user_id
");

// Skills requested
$skills_requested = $conn->query("
    SELECT s.skill_name FROM user_skills_requested usr
    JOIN skills s ON usr.skill_id = s.skill_id
    WHERE usr.user_id = $user_id
");

// Sorting parameters
$sort = trim($_GET['sort'] ?? 'date');
$order = trim($_GET['order'] ?? 'desc');

$allowed_sorts = [
    'amount' => 'final_amount',
    'date' => 'timestamp'
];

$sort_col = $allowed_sorts[$sort] ?? 'timestamp';
$order = (strtolower($order) === 'asc') ? 'ASC' : 'DESC';

// Sort URL generator
function getSortUrl($col, $dir)
{
    $query = [
        'sort' => $col,
        'order' => $dir
    ];
    return 'dashboard.php?' . http_build_query($query);
}

// Sort Buttons generator (single toggle button beside column header)
function renderSortButtons($col, $current_sort, $current_order)
{
    if ($current_sort === $col) {
        if (strtolower($current_order) === 'asc') {
            $url = getSortUrl($col, 'desc');
            return '
            <span class="sort-arrows">
                <a href="' . $url . '" class="sort-arrow active" title="Sorted Ascending. Click to sort Descending">&#x25B4;</a>
            </span>';
        } else {
            $url = getSortUrl($col, 'asc');
            return '
            <span class="sort-arrows">
                <a href="' . $url . '" class="sort-arrow active" title="Sorted Descending. Click to sort Ascending">&#x25BE;</a>
            </span>';
        }
    } else {
        $url = getSortUrl($col, 'asc');
        return '
        <span class="sort-arrows">
            <a href="' . $url . '" class="sort-arrow" title="Click to sort Ascending">&#x25B4;</a>
        </span>';
    }
}

// --- VIEW: vw_transaction_ledger ---
// Uses the transaction ledger view with CASE WHEN for readable categories.
// Replaces manual JOIN with a pre-built view that joins transactions,
// users, exchange_sessions, and skills tables.
$recent_txn = $conn->query("
    SELECT * FROM vw_transaction_ledger
    WHERE from_user_id = $user_id OR to_user_id = $user_id
    ORDER BY $sort_col $order
    LIMIT 5
");

// --- COMPLEX QUERY CQ-7: User Activity Timeline ---
$activity_page = max(1, intval($_GET['activity_page'] ?? 1));
$activity_limit = 5;
$activity_offset = ($activity_page - 1) * $activity_limit;
$activity_limit_plus_one = $activity_limit + 1;

$activity_timeline_query = "
    (SELECT 'session_booked' AS event_type, es.scheduled_time AS event_time,
            CONCAT('Booked session for ', s.skill_name) AS description
     FROM exchange_sessions es
     JOIN skills s ON es.skill_id = s.skill_id
     WHERE es.requester_id = $user_id)
    UNION ALL
    (SELECT 'session_taught', es.completion_time,
            CONCAT('Taught ', s.skill_name)
     FROM exchange_sessions es
     JOIN skills s ON es.skill_id = s.skill_id
     WHERE es.provider_id = $user_id AND es.status = 'completed')
    UNION ALL
    (SELECT 'task_completed', ct.completed_at,
            CONCAT('Completed task: ', ct.task_type)
     FROM community_task ct
     WHERE ct.user_id = $user_id AND ct.status = 'completed')
    UNION ALL
    (SELECT 'credit_received', t.timestamp,
            CONCAT('Received ', t.final_amount, ' TC')
     FROM transactions t
     WHERE t.to_user_id = $user_id)
    ORDER BY event_time DESC
    LIMIT $activity_limit_plus_one OFFSET $activity_offset
";
$activity_timeline_res = $conn->query($activity_timeline_query);

$activities = [];
if ($activity_timeline_res) {
    while ($row = $activity_timeline_res->fetch_assoc()) {
        $activities[] = $row;
    }
}
$has_next_activity = count($activities) > $activity_limit;
if ($has_next_activity) {
    array_pop($activities); // Remove the N+1th element for display
}

// --- FEATURE 3: COLLABORATIVE FILTERING RECOMMENDATIONS ---
// CTE-based collaborative filtering: finds skills learned by users
// who learned the same skills as the current user.
// Step 1: CTE my_skills = skills current user has learned (completed sessions as requester)
// Step 2: CTE similar_users = other users who also learned those same skills
// Step 3: Find OTHER skills those similar users learned, excluding my_skills
// Step 4: Rank by frequency (how many similar users learned each skill)
$recommendations = $conn->query("
    WITH my_skills AS (
        SELECT DISTINCT skill_id 
        FROM exchange_sessions
        WHERE requester_id = $user_id AND status = 'completed'
    ),
    similar_users AS (
        SELECT DISTINCT es.requester_id
        FROM exchange_sessions es
        JOIN my_skills ms ON es.skill_id = ms.skill_id
        WHERE es.requester_id != $user_id AND es.status = 'completed'
    )
    SELECT s.skill_id, s.skill_name, s.catagory, s.difficulty_level, 
           COUNT(*) AS learn_count,
           (SELECT ROUND(AVG(es2.rating), 1) FROM exchange_sessions es2 
            WHERE es2.skill_id = s.skill_id AND es2.rating IS NOT NULL) AS avg_rating
    FROM exchange_sessions es
    JOIN similar_users su ON es.requester_id = su.requester_id
    JOIN skills s ON es.skill_id = s.skill_id
    WHERE es.status = 'completed'
      AND es.skill_id NOT IN (SELECT skill_id FROM my_skills)
    GROUP BY s.skill_id
    ORDER BY learn_count DESC
    LIMIT 6
");

// --- Leaderboard badges for current user ---
$my_badges = $conn->query("
    WITH provider_scores AS (
        SELECT es.provider_id, s.catagory AS category,
            DENSE_RANK() OVER (PARTITION BY s.catagory ORDER BY 
                (COUNT(*) * 0.4) + (COALESCE(AVG(es.rating),0)*6*0.3) + (COALESCE(r.current_score,5)*4*0.2) + (COALESCE(SUM(es.session_duration),0)/60.0*0.1) DESC
            ) AS rank_pos
        FROM exchange_sessions es
        JOIN skills s ON es.skill_id = s.skill_id
        LEFT JOIN reputation r ON es.provider_id = r.user_id
        WHERE es.status = 'completed' AND s.catagory IS NOT NULL
        GROUP BY es.provider_id, s.catagory
    )
    SELECT category, rank_pos FROM provider_scores 
    WHERE provider_id = $user_id AND rank_pos <= 3
");
$badge_list = [];
if ($my_badges) {
    while ($b = $my_badges->fetch_assoc()) {
        $badge_list[] = $b;
    }
}

include __DIR__ . '/../includes/header.php'; ?>
<div class="page-wrapper">
    <div class="container">
        <h1 class="page-title">Dashboard</h1>

        <!-- Stats Row -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-value"><?php echo $balance; ?></span>
                <span class="stat-label">Time Credits</span>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?php echo $rep_score; ?>/5</span>
                <span class="stat-label">Reputation Score</span>
            </div>
            <div class="stat-card" style="padding: 0; display: flex; flex-direction: row;">
                <a href="../pages/sessions.php?filter=completed&role=guided" style="flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; text-decoration: none; color: inherit; padding: 10px; border-right: 1px solid var(--border-light); transition: background 0.2s;" onmouseover="this.style.backgroundColor='var(--bg-hover)'" onmouseout="this.style.backgroundColor='transparent'">
                    <span class="stat-value" style="font-size: 1.5rem;"><?php echo $guided_sessions; ?></span>
                    <span class="stat-label" style="font-size: 0.75rem;">Sessions Guided</span>
                </a>
                <a href="../pages/sessions.php?filter=completed&role=learned" style="flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; text-decoration: none; color: inherit; padding: 10px; transition: background 0.2s;" onmouseover="this.style.backgroundColor='var(--bg-hover)'" onmouseout="this.style.backgroundColor='transparent'">
                    <span class="stat-value" style="font-size: 1.5rem;"><?php echo $learned_sessions; ?></span>
                    <span class="stat-label" style="font-size: 0.75rem;">Sessions Learned</span>
                </a>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?php echo htmlspecialchars($mentor_level); ?></span>
                <span class="stat-label">Mentor Level</span>
            </div>
        </div>

        <div class="grid-2 mt-3">
            <!-- First Column: Skills I Teach & Skills I Want to Learn -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                <!-- Skills Offered -->
                <div class="card" style="height: 100%;">
                    <div class="card-header">
                        <h3>Skills I Teach</h3>
                        <a href="../pages/profile.php" class="btn btn-sm btn-secondary">Edit</a>
                    </div>
                    <?php if ($skills_offered->num_rows > 0): ?>
                        <div>
                            <?php while ($s = $skills_offered->fetch_assoc()): ?>
                                <span class="skill-tag"><?php echo htmlspecialchars($s['skill_name']); ?></span>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p class="empty-state">No skills listed yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Skills Requested -->
                <div class="card" style="height: 100%;">
                    <div class="card-header">
                        <h3>Skills I Want to Learn</h3>
                        <a href="../pages/profile.php" class="btn btn-sm btn-secondary">Edit</a>
                    </div>
                    <?php if ($skills_requested->num_rows > 0): ?>
                        <div>
                            <?php while ($s = $skills_requested->fetch_assoc()): ?>
                                <span class="skill-tag"><?php echo htmlspecialchars($s['skill_name']); ?></span>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p class="empty-state">No skills listed yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Second Column: Upcoming Sessions -->
            <div class="card" style="height: 100%; display: flex; flex-direction: column;">
                <div class="card-header">
                    <h3>Upcoming Sessions</h3>
                    <a href="../pages/sessions.php" class="btn btn-sm btn-secondary">View All</a>
                </div>

                <?php if ($upcoming_q->num_rows > 0): ?>
                    <div style="flex-grow: 1;">
                        <?php while ($session = $upcoming_q->fetch_assoc()): ?>
                            <div class="session-card">
                                <div class="avatar">
                                    <?php
                                    $partner_name = ($session['requester_id'] == $user_id) ? $session['provider_name'] : $session['requester_name'];
                                    echo strtoupper(substr($partner_name, 0, 1));
                                    ?>
                                </div>
                                <div class="session-info">
                                    <h4><?php echo htmlspecialchars($session['skill_name']); ?></h4>
                                    <p>
                                        with <strong><?php echo htmlspecialchars($partner_name); ?></strong>
                                        &middot; <?php echo date('M d, Y h:i A', strtotime($session['scheduled_time'])); ?>
                                        &middot; <?php echo $session['session_duration']; ?> min
                                    </p>
                                </div>
                                <span class="badge badge-warning">Scheduled</span>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state"
                        style="flex-grow: 1; display: flex; flex-direction: column; justify-content: center;">
                        <div class="icon">&#128197;</div>
                        <p>No upcoming sessions. <a href="../pages/skills.php">Browse skills</a> to book one!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Feature 3: Recommended Skills (Collaborative Filtering) & Badges -->
        <div class="grid-2 mt-3">
            <div class="card">
                <div class="card-header">
                    <h3><i data-lucide="lightbulb" class="lucide-sm"></i> Recommended For You</h3>
                    <span style="font-size:0.75rem; color:var(--text-muted);">Collaborative Filtering via CTEs</span>
                </div>
                <?php if ($recommendations && $recommendations->num_rows > 0): ?>
                    <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:12px;">Users who learned the same
                        skills as you also learned:</p>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                        <?php while ($rec = $recommendations->fetch_assoc()): ?>
                            <a href="skill_detail.php?id=<?php echo $rec['skill_id']; ?>"
                                style="display:block; text-decoration:none; padding:10px 12px; background:var(--bg-hover); border-radius:var(--radius-sm); border:1px solid var(--border-light); transition:var(--transition);"
                                onmouseover="this.style.borderColor='var(--primary)'"
                                onmouseout="this.style.borderColor='var(--border-light)'">
                                <strong
                                    style="color:var(--primary); font-size:0.88rem;"><?php echo htmlspecialchars($rec['skill_name']); ?></strong>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:4px;">
                                    <span class="badge badge-orange"
                                        style="font-size:0.65rem;"><?php echo htmlspecialchars($rec['catagory']); ?></span>
                                    <span style="font-size:0.75rem; color:var(--text-muted);">
                                        <?php echo $rec['avg_rating'] ? '<i data-lucide="star" class="lucide-sm"></i> ' . $rec['avg_rating'] : ''; ?>
                                        · <?php echo $rec['learn_count']; ?> similar
                                    </span>
                                </div>
                            </a>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:20px;">
                        <p>Complete some sessions to get personalized recommendations!</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Leaderboard Badges -->
            <div class="card">
                <div class="card-header">
                    <h3><i data-lucide="award" class="lucide-sm"></i> Your Badges</h3>
                    <a href="../pages/leaderboard.php" class="btn btn-sm btn-secondary">Full Leaderboard</a>
                </div>
                <?php if (!empty($badge_list)): ?>
                    <div style="display:flex; flex-wrap:wrap; gap:10px;">
                        <?php foreach ($badge_list as $badge):
                            $icon = '<i data-lucide="medal" class="lucide-sm"></i>';
                            $color = 'var(--info)';
                            if ($badge['rank_pos'] == 1) {
                                $icon = '<i data-lucide="medal" style="color: gold;" class="lucide-sm"></i>';
                                $color = 'var(--warning)';
                            } elseif ($badge['rank_pos'] == 2) {
                                $icon = '<i data-lucide="medal" style="color: silver;" class="lucide-sm"></i>';
                                $color = 'var(--info)';
                            } elseif ($badge['rank_pos'] == 3) {
                                $icon = '<i data-lucide="medal" style="color: #cd7f32;" class="lucide-sm"></i>';
                                $color = 'var(--primary)';
                            }
                            ?>
                            <div
                                style="text-align:center; padding:12px 16px; background:var(--bg-hover); border-radius:var(--radius-sm); border:1px solid var(--border-light); min-width:100px;">
                                <span style="font-size:1.6rem;"><?php echo $icon; ?></span>
                                <div style="font-weight:700; color:<?php echo $color; ?>; font-size:0.9rem;">
                                    #<?php echo $badge['rank_pos']; ?></div>
                                <div
                                    style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; font-weight:600;">
                                    <?php echo htmlspecialchars($badge['category']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:20px;">
                        <p>Complete teaching sessions to earn category badges!</p>
                        <p style="font-size:0.8rem; color:var(--text-muted); margin-top:6px;">Top 3 in any skill category
                            earns <i data-lucide="medal" style="color: gold;" class="lucide-sm"></i><i data-lucide="medal"
                                style="color: silver;" class="lucide-sm"></i><i data-lucide="medal" style="color: #cd7f32;"
                                class="lucide-sm"></i></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>



        <!-- Recent Transactions -->
        <div class="card mt-3">
            <div class="card-header">
                <h3>Recent Transactions</h3>
                <a href="../pages/wallet.php" class="btn btn-sm btn-secondary">View Wallet</a>
            </div>

            <?php if ($recent_txn->num_rows > 0): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>From</th>
                                <th>To</th>
                                <th>
                                    <span class="th-content">
                                        <span>Amount</span>
                                        <?php echo renderSortButtons('amount', $sort, $order); ?>
                                    </span>
                                </th>
                                <th>
                                    <span class="th-content">
                                        <span>Date</span>
                                        <?php echo renderSortButtons('date', $sort, $order); ?>
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($txn = $recent_txn->fetch_assoc()): ?>
                                <tr>
                                    <td><span
                                            class="badge badge-orange"><?php echo htmlspecialchars($txn['transaction_category']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($txn['sender_name'] ?? 'System'); ?></td>
                                    <td><?php echo htmlspecialchars($txn['receiver_name']); ?></td>
                                    <td><?php echo number_format($txn['final_amount'], 2); ?> TC</td>
                                    <td><?php echo date('M d, Y', strtotime($txn['timestamp'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p>No transactions yet.</p>
                </div>
            <?php endif; ?>
            <!-- CQ-7: Activity Timeline (UNION ALL query) -->
            <div class="card mt-3" id="timeline">
                <div class="card-header">
                    <h3>Activity Timeline</h3>
                </div>
                <?php if (count($activities) > 0): ?>
                    <?php foreach ($activities as $event):
                        $icon = '<i data-lucide="pin" class="lucide-sm"></i>';
                        $badge = 'badge-info';
                        if ($event['event_type'] === 'session_booked') {
                            $icon = '<i data-lucide="calendar" class="lucide-sm"></i>';
                            $badge = 'badge-warning';
                        } elseif ($event['event_type'] === 'session_taught') {
                            $icon = '<i data-lucide="graduation-cap" class="lucide-sm"></i>';
                            $badge = 'badge-success';
                        } elseif ($event['event_type'] === 'task_completed') {
                            $icon = '<i data-lucide="check-circle" class="lucide-sm"></i>';
                            $badge = 'badge-primary';
                        } elseif ($event['event_type'] === 'credit_received') {
                            $icon = '<i data-lucide="coins" class="lucide-sm"></i>';
                            $badge = 'badge-orange';
                        }
                        ?>
                        <div class="session-card">
                            <div class="avatar"><?php echo $icon; ?></div>
                            <div class="session-info">
                                <h4><?php echo htmlspecialchars($event['description']); ?></h4>
                                <p><?php echo $event['event_time'] ? date('M d, Y h:i A', strtotime($event['event_time'])) : 'N/A'; ?>
                                </p>
                            </div>
                            <span
                                class="badge <?php echo $badge; ?>"><?php echo ucfirst(str_replace('_', ' ', $event['event_type'])); ?></span>
                        </div>
                    <?php endforeach; ?>

                    <!-- Pagination -->
                    <div class="flex justify-between items-center mt-3"
                        style="border-top: 1px solid var(--border-light); padding-top: 16px;">
                        <div>
                            <?php if ($activity_page > 1): ?>
                                <a href="dashboard.php?activity_page=<?php echo $activity_page - 1; ?>#timeline"
                                    class="btn btn-sm btn-secondary" style="border-radius: 99px;">&larr; Previous</a>
                            <?php else: ?>
                                <button class="btn btn-sm btn-secondary" disabled
                                    style="opacity:0.5; cursor:not-allowed; border-radius: 99px;">&larr; Previous</button>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:0.85rem; color:var(--text-muted); font-weight:500;">Page
                            <?php echo $activity_page; ?></div>
                        <div>
                            <?php if ($has_next_activity): ?>
                                <a href="dashboard.php?activity_page=<?php echo $activity_page + 1; ?>#timeline"
                                    class="btn btn-sm btn-secondary" style="border-radius: 99px;">Next &rarr;</a>
                            <?php else: ?>
                                <button class="btn btn-sm btn-secondary" disabled
                                    style="opacity:0.5; cursor:not-allowed; border-radius: 99px;">Next &rarr;</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <p>No activity yet. Start learning or teaching!</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>