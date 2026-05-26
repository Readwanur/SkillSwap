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
$total_sessions = $user ? $user['total_completed_sessions'] : 0;

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
function getSortUrl($col, $dir) {
    $query = [
        'sort' => $col,
        'order' => $dir
    ];
    return 'dashboard.php?' . http_build_query($query);
}

// Sort Buttons generator (single toggle button beside column header)
function renderSortButtons($col, $current_sort, $current_order) {
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
// Uses UNION ALL to merge events from 4 different tables into
// a single chronological activity feed. Each subquery contributes
// a different event type with standardized columns.
$activity_timeline = $conn->query("
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
    LIMIT 10
");

include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/style.css">
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
            <div class="stat-card">
                <span class="stat-value"><?php echo $total_sessions; ?></span>
                <span class="stat-label">Sessions Completed</span>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?php echo htmlspecialchars($mentor_level); ?></span>
                <span class="stat-label">Mentor Level</span>
            </div>
        </div>

        <div class="grid-2">
            <!-- Skills Offered -->
            <div class="card">
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
            <div class="card">
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

        <!-- Upcoming Sessions -->
        <div class="card mt-3">
            <div class="card-header">
                <h3>Upcoming Sessions</h3>
                <a href="../pages/sessions.php" class="btn btn-sm btn-secondary">View All</a>
            </div>

            <?php if ($upcoming_q->num_rows > 0): ?>
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
            <?php else: ?>
                <div class="empty-state">
                    <div class="icon">&#128197;</div>
                    <p>No upcoming sessions. <a href="../pages/skills.php">Browse skills</a> to book one!</p>
                </div>
            <?php endif; ?>
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
                                    <td><span class="badge badge-orange"><?php echo htmlspecialchars($txn['transaction_category']); ?></span>
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
        <div class="card mt-3">
            <div class="card-header">
                <h3>Activity Timeline</h3>
                <span style="font-size:0.8rem; color:var(--text-muted);">Powered by UNION ALL across 4 tables</span>
            </div>
            <?php if ($activity_timeline && $activity_timeline->num_rows > 0): ?>
                <?php while ($event = $activity_timeline->fetch_assoc()):
                    $icon = '📌';
                    $badge = 'badge-info';
                    if ($event['event_type'] === 'session_booked') { $icon = '📅'; $badge = 'badge-warning'; }
                    elseif ($event['event_type'] === 'session_taught') { $icon = '🎓'; $badge = 'badge-success'; }
                    elseif ($event['event_type'] === 'task_completed') { $icon = '✅'; $badge = 'badge-primary'; }
                    elseif ($event['event_type'] === 'credit_received') { $icon = '💰'; $badge = 'badge-orange'; }
                ?>
                    <div class="session-card">
                        <div class="avatar"><?php echo $icon; ?></div>
                        <div class="session-info">
                            <h4><?php echo htmlspecialchars($event['description']); ?></h4>
                            <p><?php echo $event['event_time'] ? date('M d, Y h:i A', strtotime($event['event_time'])) : 'N/A'; ?></p>
                        </div>
                        <span class="badge <?php echo $badge; ?>"><?php echo ucfirst(str_replace('_', ' ', $event['event_type'])); ?></span>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <p>No activity yet. Start learning or teaching!</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>