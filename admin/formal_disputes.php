<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'Formal Disputes';
$success = '';
$error = '';

// Handle resolution actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve') {
    $dispute_id = intval($_POST['dispute_id'] ?? 0);
    $verdict = $_POST['verdict'] ?? ''; // 'refund' or 'payout'

    if ($dispute_id > 0 && in_array($verdict, ['refund', 'payout'])) {
        // Run stored procedure sp_resolve_dispute
        $stmt = $conn->prepare("CALL sp_resolve_dispute(?, ?, @sp_status, @sp_message)");
        $stmt->bind_param("is", $dispute_id, $verdict);
        $stmt->execute();
        $stmt->close();

        // Get outputs
        $res = $conn->query("SELECT @sp_status AS status, @sp_message AS message")->fetch_assoc();
        if ($res['status'] === 'success') {
            $success = $res['message'];
        } else {
            $error = $res['message'];
        }
    } else {
        $error = 'Invalid parameters for resolution.';
    }
}

// Fetch disputes
$disputes = $conn->query("
    SELECT 
        d.dispute_id,
        d.reason,
        d.status AS dispute_status,
        d.created_at AS dispute_filed_at,
        es.session_id,
        es.scheduled_time AS session_scheduled,
        es.time_credit_transfer AS session_credits,
        es.status AS session_status,
        s.skill_name,
        u_filer.name AS filer_name,
        u_req.name AS requester_name,
        u_prov.name AS provider_name
    FROM disputes d
    JOIN exchange_sessions es ON d.session_id = es.session_id
    JOIN skills s ON es.skill_id = s.skill_id
    JOIN users u_filer ON d.filed_by_user_id = u_filer.user_id
    JOIN users u_req ON es.requester_id = u_req.user_id
    JOIN users u_prov ON es.provider_id = u_prov.user_id
    ORDER BY d.created_at DESC
");

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="card mb-3">
    <div class="card-header" style="border-bottom: 1px solid var(--border-light); padding-bottom: 15px;">
        <div>
            <h2 style="color: var(--primary); font-family: var(--font-headline); font-weight: 700; margin: 0;"><i data-lucide="scale" class="lucide-sm"></i> Formal Disputes Center</h2>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 4px;">Review complaints filed by platform users and resolve them fairly.</p>
        </div>
    </div>

    <div style="padding: 20px;">
        <?php if ($success): ?>
            <div class="alert alert-success" style="margin-bottom: 20px;"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($disputes && $disputes->num_rows > 0): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Dispute ID</th>
                            <th>Filer</th>
                            <th>Session Details</th>
                            <th>Reason Filed</th>
                            <th>Escrowed Amount</th>
                            <th>Status</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($d = $disputes->fetch_assoc()): 
                            $status_class = 'badge-warning';
                            if ($d['dispute_status'] === 'resolved_refunded') $status_class = 'badge-danger';
                            elseif ($d['dispute_status'] === 'resolved_payout') $status_class = 'badge-success';
                        ?>
                            <tr>
                                <td><strong>#<?php echo $d['dispute_id']; ?></strong></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($d['filer_name']); ?></strong>
                                    <div style="font-size:0.75rem; color:var(--text-muted);">Filed: <?php echo date('M d, Y h:i A', strtotime($d['dispute_filed_at'])); ?></div>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($d['skill_name']); ?></strong>
                                    <div style="font-size:0.8rem; color:var(--text-secondary);">
                                        Learner: <?php echo htmlspecialchars($d['requester_name']); ?><br>
                                        Teacher: <?php echo htmlspecialchars($d['provider_name']); ?>
                                    </div>
                                    <div style="font-size:0.75rem; color:var(--text-muted);">Date: <?php echo date('M d, Y h:i A', strtotime($d['session_scheduled'])); ?></div>
                                </td>
                                <td style="max-width: 250px; font-size: 0.85rem; color: var(--text-secondary);">
                                    <?php echo nl2br(htmlspecialchars($d['reason'])); ?>
                                </td>
                                <td><strong><?php echo number_format($d['session_credits'], 2); ?> TC</strong></td>
                                <td><span class="badge <?php echo $status_class; ?>"><?php echo ucfirst(str_replace('resolved_', 'Resolved: ', $d['dispute_status'])); ?></span></td>
                                <td style="text-align: right;">
                                    <?php if ($d['dispute_status'] === 'open'): ?>
                                        <div style="display:flex; justify-content: flex-end; gap:8px;">
                                            <form method="POST" onsubmit="return confirm('Refund this session? The learner will get their credits back, and the provider reliability will be penalized.');">
                                                <input type="hidden" name="action" value="resolve">
                                                <input type="hidden" name="dispute_id" value="<?php echo $d['dispute_id']; ?>">
                                                <input type="hidden" name="verdict" value="refund">
                                                <button type="submit" class="btn btn-sm btn-danger">Refund Requester</button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('Pay out this session? The provider will receive the escrowed credits.');">
                                                <input type="hidden" name="action" value="resolve">
                                                <input type="hidden" name="dispute_id" value="<?php echo $d['dispute_id']; ?>">
                                                <input type="hidden" name="verdict" value="payout">
                                                <button type="submit" class="btn btn-sm btn-success">Payout Provider</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span style="font-size:0.8rem; color:var(--text-muted); font-style:italic;">No Actions Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="padding: 40px; text-align: center; color: var(--text-muted);">
                <div style="font-size: 3rem; margin-bottom: 10px;"><i data-lucide="scale" class="lucide-sm"></i></div>
                <h3>No disputes filed yet</h3>
                <p>Everything is running smoothly. When users file formal disputes on scheduled or under-review sessions, they will show up here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
