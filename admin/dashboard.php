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
$total_hours = $conn->query("SELECT COALESCE(SUM(session_duration), 0) AS total FROM exchange_sessions WHERE status = 'completed'")->fetch_assoc()['total'];
$total_hours = round($total_hours / 60, 1);
$total_credits = $conn->query("SELECT COALESCE(SUM(final_amount), 0) AS total FROM transactions")->fetch_assoc()['total'];

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

include __DIR__ . '/../includes/admin_header.php';
?>

<h1 class="page-title">Admin Dashboard</h1>

<!-- Platform Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-value"><?php echo $total_users; ?></span>
        <span class="stat-label">Total Users</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?php echo $total_skills; ?></span>
        <span class="stat-label">Skills Listed</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?php echo $total_sessions; ?></span>
        <span class="stat-label">Total Sessions</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?php echo $completed_sessions; ?></span>
        <span class="stat-label">Completed Sessions</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?php echo $total_hours; ?>h</span>
        <span class="stat-label">Hours Exchanged</span>
    </div>
    <div class="stat-card">
        <span class="stat-value"><?php echo number_format($total_credits, 0); ?></span>
        <span class="stat-label">Credits Transferred</span>
    </div>
</div>

<div class="grid-2">
    <!-- Recent Sessions -->
    <div class="card">
        <div class="card-header">
            <h3>Recent Sessions</h3>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Skill</th>
                        <th>Requester</th>
                        <th>Provider</th>
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
                            <td><?php echo htmlspecialchars($s['skill_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['requester_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['provider_name']); ?></td>
                            <td><span class="badge <?php echo $sc; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Users -->
    <div class="card">
        <div class="card-header">
            <h3>Recent Users</h3>
            <a href="../admin/users.php" class="btn btn-sm btn-secondary">View All</a>
        </div>
        <?php while ($u = $recent_users->fetch_assoc()): ?>
            <div class="user-card mb-1">
                <div class="avatar"><?php echo strtoupper(substr($u['name'], 0, 1)); ?></div>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($u['name']); ?></h4>
                    <p><?php echo htmlspecialchars($u['email']); ?> &middot;
                        <?php echo htmlspecialchars($u['location'] ?? 'N/A'); ?></p>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>