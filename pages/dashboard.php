<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'Dashboard';

// Fetch user info
$user = $conn->query("SELECT * FROM users WHERE user_id = $user_id")->fetch_assoc();

// Fetch wallet balance
$wallet = $conn->query("SELECT balance FROM wallet WHERE user_id = $user_id")->fetch_assoc();
$balance = $wallet ? number_format($wallet['balance'], 2) : '0.00';

// Fetch reputation
$rep = $conn->query("SELECT * FROM reputation WHERE user_id = $user_id")->fetch_assoc();
$rep_score = $rep ? $rep['current_score'] : '5.00';
$mentor_level = $rep ? $rep['mentor_level'] : 'Novice';

// Count sessions
$total_sessions = $conn->query("SELECT COUNT(*) as cnt FROM exchange_sessions WHERE (requester_id = $user_id OR provider_id = $user_id) AND status = 'completed'")->fetch_assoc()['cnt'];

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

// Recent transactions
$recent_txn = $conn->query("
    SELECT t.*, u_from.name AS from_name, u_to.name AS to_name
    FROM transactions t
    JOIN users u_from ON t.from_user_id = u_from.user_id
    JOIN users u_to ON t.to_user_id = u_to.user_id
    WHERE t.from_user_id = $user_id OR t.to_user_id = $user_id
    ORDER BY t.timestamp DESC
    LIMIT 5
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
                                <th>Amount</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($txn = $recent_txn->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="badge badge-orange"><?php echo htmlspecialchars($txn['type']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($txn['from_name']); ?></td>
                                    <td><?php echo htmlspecialchars($txn['to_name']); ?></td>
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
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>