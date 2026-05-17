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
        // Mark as legitimately completed
        $conn->query("UPDATE exchange_sessions SET status = 'completed', completion_time = NOW() WHERE session_id = $session_id");
        $success = "Session #$session_id marked as completed.";
    }

    if ($_POST['action'] === 'resolve_cancelled') {
        $conn->query("UPDATE exchange_sessions SET status = 'cancelled' WHERE session_id = $session_id");
        $success = "Session #$session_id cancelled by admin.";
    }

    if ($_POST['action'] === 'refund') {
        // Reverse the credit transfer
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

// Fetch all sessions (flagged = cancelled ones, or sessions without reviews which might be disputes)
$disputes = $conn->query("
    SELECT es.*, s.skill_name,
           u_req.name AS requester_name, u_req.email AS requester_email,
           u_prov.name AS provider_name, u_prov.email AS provider_email,
           rv.rating, rv.comment AS review_comment
    FROM exchange_sessions es
    JOIN skills s ON es.skill_id = s.skill_id
    JOIN users u_req ON es.requester_id = u_req.user_id
    JOIN users u_prov ON es.provider_id = u_prov.user_id
    LEFT JOIN review rv ON es.session_id = rv.session_id
    ORDER BY es.scheduled_time DESC
");

include __DIR__ . '/../includes/admin_header.php';
?>

<h1 class="page-title">Reports</h1>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3>All Sessions</h3>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Skill</th>
                    <th>Requester</th>
                    <th>Provider</th>
                    <th>Date</th>
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
                        <td>#<?php echo $d['session_id']; ?></td>
                        <td><?php echo htmlspecialchars($d['skill_name']); ?></td>
                        <td><?php echo htmlspecialchars($d['requester_name']); ?></td>
                        <td><?php echo htmlspecialchars($d['provider_name']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($d['scheduled_time'])); ?></td>
                        <td><?php echo number_format($d['time_credit_transfer'], 2); ?> TC</td>
                        <td><span class="badge <?php echo $sc; ?>"><?php echo ucfirst($d['status']); ?></span></td>
                        <td>
                            <?php if ($d['rating']): ?>
                                &#11088; <?php echo $d['rating']; ?>/5
                            <?php else: ?>
                                <span style="color:var(--text-muted);">None</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['status'] === 'scheduled'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="session_id" value="<?php echo $d['session_id']; ?>">
                                    <input type="hidden" name="action" value="resolve_completed">
                                    <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="session_id" value="<?php echo $d['session_id']; ?>">
                                    <input type="hidden" name="action" value="resolve_cancelled">
                                    <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                                </form>
                            <?php elseif ($d['status'] === 'completed'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="session_id" value="<?php echo $d['session_id']; ?>">
                                    <input type="hidden" name="action" value="refund">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                        onclick="return confirm('Refund this session?')">Refund</button>
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
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>