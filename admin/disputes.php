<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'Reports';
$success = '';

// Handle dispute actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $session_id = intval($_POST['session_id'] ?? 0);

    if ($_POST['action'] === 'resolve_completed') {
        $conn->query("UPDATE exchange_sessions SET status = 'completed', completion_time = NOW() WHERE session_id = $session_id");
        $success = "Session #$session_id marked as completed.";
    }

    if ($_POST['action'] === 'resolve_cancelled') {
        $conn->query("UPDATE exchange_sessions SET status = 'cancelled' WHERE session_id = $session_id");
        $success = "Session #$session_id cancelled by admin.";
    }

    if ($_POST['action'] === 'refund') {
        $session = $conn->query("SELECT * FROM exchange_sessions WHERE session_id = $session_id")->fetch_assoc();
        if ($session && $session['time_credit_transfer'] > 0) {
            $amount = $session['time_credit_transfer'];
            $conn->query("UPDATE wallet SET balance = balance + $amount WHERE user_id = {$session['requester_id']}");
            $conn->query("UPDATE wallet SET balance = balance - $amount WHERE user_id = {$session['provider_id']}");
            $conn->query("UPDATE exchange_sessions SET status = 'cancelled' WHERE session_id = $session_id");
            $success = "Session #$session_id refunded. {$amount} TC returned to requester.";
        }
    }
}

// Status filter
$status_filter = trim($_GET['status'] ?? '');
$where_clause = '';
if ($status_filter !== '' && in_array($status_filter, ['scheduled', 'completed', 'cancelled'])) {
    $where_clause = "WHERE es.status = '$status_filter'";
}

// Counts
$count_all = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions")->fetch_assoc()['cnt'];
$count_scheduled = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions WHERE status = 'scheduled'")->fetch_assoc()['cnt'];
$count_completed = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions WHERE status = 'completed'")->fetch_assoc()['cnt'];
$count_cancelled = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions WHERE status = 'cancelled'")->fetch_assoc()['cnt'];

// Fetch sessions
$disputes = $conn->query("
    SELECT es.*, s.skill_name,
           u_req.name AS requester_name, u_req.email AS requester_email,
           u_prov.name AS provider_name, u_prov.email AS provider_email
    FROM exchange_sessions es
    JOIN skills s ON es.skill_id = s.skill_id
    JOIN users u_req ON es.requester_id = u_req.user_id
    JOIN users u_prov ON es.provider_id = u_prov.user_id
    $where_clause
    ORDER BY es.scheduled_time DESC
");

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="page-header">
    <h1 class="page-title" style="margin:0;">&#x1F4CB; Session Reports</h1>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<!-- Summary Stats -->
<div class="stats-grid" style="margin-bottom:16px;">
    <div class="stat-card stat-card-accent" style="--accent: var(--primary); cursor:pointer;" onclick="location.href='?'">
        <span class="stat-value"><?php echo $count_all; ?></span>
        <span class="stat-label">Total Sessions</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--warning); cursor:pointer;" onclick="location.href='?status=scheduled'">
        <span class="stat-value"><?php echo $count_scheduled; ?></span>
        <span class="stat-label">Scheduled</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--success); cursor:pointer;" onclick="location.href='?status=completed'">
        <span class="stat-value"><?php echo $count_completed; ?></span>
        <span class="stat-label">Completed</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--danger); cursor:pointer;" onclick="location.href='?status=cancelled'">
        <span class="stat-value"><?php echo $count_cancelled; ?></span>
        <span class="stat-label">Cancelled</span>
    </div>
</div>

<!-- Filter Tabs -->
<div class="card">
    <div class="card-header" style="flex-wrap:wrap; gap:8px;">
        <h3>
            <?php if ($status_filter): ?>
                <?php echo ucfirst($status_filter); ?> Sessions (<?php echo $disputes->num_rows; ?>)
            <?php else: ?>
                All Sessions (<?php echo $disputes->num_rows; ?>)
            <?php endif; ?>
        </h3>
        <div class="admin-filter-tabs">
            <a href="?" class="filter-tab <?php echo $status_filter === '' ? 'active' : ''; ?>">All</a>
            <a href="?status=scheduled" class="filter-tab <?php echo $status_filter === 'scheduled' ? 'active' : ''; ?>">&#x23F3; Scheduled</a>
            <a href="?status=completed" class="filter-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">&#x2705; Completed</a>
            <a href="?status=cancelled" class="filter-tab <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">&#x274C; Cancelled</a>
        </div>
    </div>

    <?php if ($disputes->num_rows > 0): ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Skill</th>
                    <th>Requester</th>
                    <th>Provider</th>
                    <th>Scheduled</th>
                    <th>Duration</th>
                    <th>Credits</th>
                    <th>Status</th>
                    <th>Review</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($d = $disputes->fetch_assoc()):
                    $sc = 'badge-warning';
                    if ($d['status'] === 'completed')
                        $sc = 'badge-success';
                    elseif ($d['status'] === 'cancelled')
                        $sc = 'badge-danger';
                    ?>
                    <tr>
                        <td><strong>#<?php echo $d['session_id']; ?></strong></td>
                        <td><?php echo htmlspecialchars($d['skill_name']); ?></td>
                        <td>
                            <span title="<?php echo htmlspecialchars($d['requester_email']); ?>">
                                <?php echo htmlspecialchars($d['requester_name']); ?>
                            </span>
                        </td>
                        <td>
                            <span title="<?php echo htmlspecialchars($d['provider_email']); ?>">
                                <?php echo htmlspecialchars($d['provider_name']); ?>
                            </span>
                        </td>
                        <td style="font-size:0.82rem;"><?php echo date('M d, Y h:i A', strtotime($d['scheduled_time'])); ?></td>
                        <td><?php echo $d['session_duration'] ? $d['session_duration'] . ' min' : '—'; ?></td>
                        <td><strong><?php echo number_format($d['time_credit_transfer'], 2); ?></strong> TC</td>
                        <td><span class="badge <?php echo $sc; ?>"><?php echo ucfirst($d['status']); ?></span></td>
                        <td>
                            <?php if ($d['rating']): ?>
                                <span title="<?php echo htmlspecialchars($d['comment'] ?? ''); ?>">
                                    &#11088; <?php echo $d['rating']; ?>/5
                                </span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['status'] === 'scheduled'): ?>
                                <div class="flex gap-1" style="flex-wrap:nowrap;">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="session_id" value="<?php echo $d['session_id']; ?>">
                                        <input type="hidden" name="action" value="resolve_completed">
                                        <button type="submit" class="btn btn-sm btn-success" title="Mark as completed">&#x2705;</button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="session_id" value="<?php echo $d['session_id']; ?>">
                                        <input type="hidden" name="action" value="resolve_cancelled">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Cancel session">&#x274C;</button>
                                    </form>
                                </div>
                            <?php elseif ($d['status'] === 'completed'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="session_id" value="<?php echo $d['session_id']; ?>">
                                    <input type="hidden" name="action" value="refund">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                        onclick="return confirm('Refund this session? Credits will be returned to the requester.')"
                                        title="Refund credits">&#x1F4B8; Refund</button>
                                </form>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="empty-state">
            <div class="icon">&#x1F4AD;</div>
            <p>No <?php echo $status_filter ?: ''; ?> sessions found.</p>
            <?php if ($status_filter): ?>
                <a href="disputes.php" class="btn btn-sm btn-secondary mt-2">View All Sessions</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>