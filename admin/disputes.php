<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'Reports';
$success = '';
$error = '';

// Refund policy constants
define('REFUND_WINDOW_DAYS', 7);

// Handle dispute actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $session_id = intval($_POST['session_id'] ?? 0);

    if ($_POST['action'] === 'approve_completion') {
        $session = $conn->query("SELECT * FROM exchange_sessions WHERE session_id = $session_id")->fetch_assoc();
        if ($session && $session['status'] === 'under-review') {
            $conn->begin_transaction();
            try {
                $requester_id = $session['requester_id'];
                $amount = $session['time_credit_transfer'];
                
                $conn->query("UPDATE exchange_sessions SET status = 'completed', completion_time = NOW() WHERE session_id = $session_id");
                
                // Escrow is already deducted, so we just transfer to provider
                $conn->query("UPDATE wallet SET balance = balance + $amount WHERE user_id = {$session['provider_id']}");

                $stmt = $conn->prepare("INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount) VALUES (?, ?, ?, 'credit_transfer', ?, ?)");
                $stmt->bind_param("iiidd", $session_id, $session['requester_id'], $session['provider_id'], $amount, $amount);
                $stmt->execute();
                $stmt->close();

                $conn->query("UPDATE reputation SET completed_sessions = completed_sessions + 1 WHERE user_id = {$session['provider_id']}");

                $conn->commit();
                $success = "Session #$session_id approved. " . number_format($amount, 2) . " TC transferred.";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to approve session: " . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'reject_proof') {
        $conn->query("UPDATE exchange_sessions SET status = 'scheduled', submission_note = NULL WHERE session_id = $session_id");
        $success = "Session #$session_id proof rejected and moved back to scheduled.";
    }

    if ($_POST['action'] === 'resolve_completed') {
        $conn->query("UPDATE exchange_sessions SET status = 'completed', completion_time = NOW() WHERE session_id = $session_id");
        $success = "Session #$session_id marked as completed (No credits transferred).";
    }

    if ($_POST['action'] === 'resolve_cancelled') {
        $session = $conn->query("SELECT * FROM exchange_sessions WHERE session_id = $session_id AND status != 'cancelled'")->fetch_assoc();
        if ($session) {
            $conn->begin_transaction();
            try {
                $conn->query("UPDATE exchange_sessions SET status = 'cancelled' WHERE session_id = $session_id");
                // Refund escrow to requester
                $amount = $session['time_credit_transfer'];
                $conn->query("UPDATE wallet SET balance = balance + $amount WHERE user_id = {$session['requester_id']}");
                $conn->commit();
                $success = "Session #$session_id cancelled by admin and credits refunded to requester.";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to cancel session: " . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'refund') {
        $refund_reason = trim($_POST['refund_reason'] ?? '');
        $refund_amount = floatval($_POST['refund_amount'] ?? 0);

        // Validate reason
        if (empty($refund_reason)) {
            $error = "Refund reason is required.";
        } else {
            // --- STORED PROCEDURE: sp_issue_refund ---
            // Handles all validation (time window, amount bounds, provider balance)
            // and atomically updates wallets, session status, reputation, and
            // logs the refund transaction. Uses TIMESTAMPDIFF, IF(), GREATEST.
            $stmt = $conn->prepare("CALL sp_issue_refund(?, ?, ?, ?, @sp_status, @sp_message)");
            $window = REFUND_WINDOW_DAYS;
            $stmt->bind_param("idsi", $session_id, $refund_amount, $refund_reason, $window);
            $stmt->execute();
            $stmt->close();
            $result = $conn->query("SELECT @sp_status AS status, @sp_message AS message")->fetch_assoc();
            if ($result['status'] === 'success') {
                $success = "Session #$session_id: " . $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    }

}

// Status filter
$status_filter = trim($_GET['status'] ?? '');
$where_clause = '';
if ($status_filter !== '' && in_array($status_filter, ['scheduled', 'under-review', 'completed', 'cancelled', 'refunded'])) {
    $where_clause = "WHERE es.status = '$status_filter'";
}

// Sorting parameters
$sort = trim($_GET['sort'] ?? 'scheduled');
$order = trim($_GET['order'] ?? 'desc');

$allowed_sorts = [
    'id' => 'es.session_id',
    'skill' => 's.skill_name',
    'requester' => 'u_req.name',
    'provider' => 'u_prov.name',
    'scheduled' => 'es.scheduled_time',
    'duration' => 'es.session_duration',
    'credits' => 'es.time_credit_transfer',
    'status' => 'es.status',
    'rating' => 'es.rating'
];

$sort_col = $allowed_sorts[$sort] ?? 'es.scheduled_time';
$order = (strtolower($order) === 'asc') ? 'ASC' : 'DESC';

// Sort URL generator
function getSortUrl($col, $dir, $status) {
    $query = [
        'sort' => $col,
        'order' => $dir
    ];
    if ($status !== '') {
        $query['status'] = $status;
    }
    return 'disputes.php?' . http_build_query($query);
}

// Sort Buttons generator (single toggle button beside column header)
function renderSortButtons($col, $current_sort, $current_order, $status) {
    if ($current_sort === $col) {
        if (strtolower($current_order) === 'asc') {
            $url = getSortUrl($col, 'desc', $status);
            return '
            <span class="sort-arrows">
                <a href="' . $url . '" class="sort-arrow active" title="Sorted Ascending. Click to sort Descending">&#x25B4;</a>
            </span>';
        } else {
            $url = getSortUrl($col, 'asc', $status);
            return '
            <span class="sort-arrows">
                <a href="' . $url . '" class="sort-arrow active" title="Sorted Descending. Click to sort Ascending">&#x25BE;</a>
            </span>';
        }
    } else {
        $url = getSortUrl($col, 'asc', $status);
        return '
        <span class="sort-arrows">
            <a href="' . $url . '" class="sort-arrow" title="Click to sort Ascending">&#x25B4;</a>
        </span>';
    }
}

// Counts
$count_all = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions")->fetch_assoc()['cnt'];
$count_scheduled = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions WHERE status = 'scheduled'")->fetch_assoc()['cnt'];
$count_under_review = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions WHERE status = 'under-review'")->fetch_assoc()['cnt'];
$count_completed = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions WHERE status = 'completed'")->fetch_assoc()['cnt'];
$count_cancelled = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions WHERE status = 'cancelled'")->fetch_assoc()['cnt'];
$count_refunded = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions WHERE status = 'refunded'")->fetch_assoc()['cnt'];

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
    ORDER BY $sort_col $order
");

// Fetch recent refund transactions for the notification panel
$recent_refunds = $conn->query("
    SELECT t.*, es.session_id,
           u_from.name AS from_name, u_to.name AS to_name
    FROM transactions t
    JOIN exchange_sessions es ON t.session_id = es.session_id
    JOIN users u_from ON t.from_user_id = u_from.user_id
    JOIN users u_to ON t.to_user_id = u_to.user_id
    WHERE t.type IN ('full_refund', 'partial_refund')
    ORDER BY t.timestamp DESC
    LIMIT 5
");

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="page-header">
    <h1 class="page-title" style="margin:0;"><i data-lucide="clipboard-list" class="lucide-sm"></i> Session Reports</h1>
    <span class="badge badge-info" style="padding:6px 14px; font-size:0.8rem;">Refund window: <?php echo REFUND_WINDOW_DAYS; ?> days</span>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Summary Stats -->
<?php
function buildTabUrl($status, $sort, $order) {
    $q = [];
    if ($status !== '') $q['status'] = $status;
    if ($sort !== '') $q['sort'] = $sort;
    if ($order !== '') $q['order'] = strtolower($order);
    return '?' . http_build_query($q);
}
?>
<div class="stats-grid" style="margin-bottom:16px;">
    <div class="stat-card stat-card-accent" style="--accent: var(--primary); cursor:pointer;" onclick="location.href='<?php echo buildTabUrl('', $sort, $order); ?>'">
        <span class="stat-value"><?php echo $count_all; ?></span>
        <span class="stat-label">Total Sessions</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--warning); cursor:pointer;" onclick="location.href='<?php echo buildTabUrl('scheduled', $sort, $order); ?>'">
        <span class="stat-value"><?php echo $count_scheduled; ?></span>
        <span class="stat-label">Scheduled</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: #2196F3; cursor:pointer;" onclick="location.href='<?php echo buildTabUrl('under-review', $sort, $order); ?>'">
        <span class="stat-value"><?php echo $count_under_review; ?></span>
        <span class="stat-label">Under Review</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--success); cursor:pointer;" onclick="location.href='<?php echo buildTabUrl('completed', $sort, $order); ?>'">
        <span class="stat-value"><?php echo $count_completed; ?></span>
        <span class="stat-label">Completed</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--danger); cursor:pointer;" onclick="location.href='<?php echo buildTabUrl('cancelled', $sort, $order); ?>'">
        <span class="stat-value"><?php echo $count_cancelled; ?></span>
        <span class="stat-label">Cancelled</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: #9c27b0; cursor:pointer;" onclick="location.href='<?php echo buildTabUrl('refunded', $sort, $order); ?>'">
        <span class="stat-value"><?php echo $count_refunded; ?></span>
        <span class="stat-label">Refunded</span>
    </div>
</div>

<!-- #6 — Recent Refund Log (Notification Panel) -->
<?php if ($recent_refunds->num_rows > 0): ?>
<div class="card mb-3" style="border-left: 4px solid #9c27b0;">
    <div class="card-header">
        <h3>&#x1F4B8; Recent Refunds</h3>
    </div>
    <div style="padding: 0 16px 12px;">
        <?php while ($ref = $recent_refunds->fetch_assoc()): ?>
            <div style="display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--border-color);">
                <span class="badge <?php echo $ref['type'] === 'full_refund' ? 'badge-danger' : 'badge-warning'; ?>" style="font-size:0.7rem; white-space:nowrap;">
                    <?php echo $ref['type'] === 'full_refund' ? 'FULL' : 'PARTIAL'; ?>
                </span>
                <div style="flex:1; font-size:0.85rem;">
                    <strong><?php echo number_format($ref['final_amount'], 2); ?> TC</strong> returned to
                    <strong><?php echo htmlspecialchars($ref['to_name']); ?></strong>
                    from <?php echo htmlspecialchars($ref['from_name']); ?>
                    (Session #<?php echo $ref['session_id']; ?>)
                </div>
                <div style="font-size:0.75rem; color:var(--text-muted); white-space:nowrap;">
                    <?php echo date('M d, h:i A', strtotime($ref['timestamp'])); ?>
                </div>
            </div>
            <?php if ($ref['note']): ?>
                <div style="font-size:0.78rem; color:var(--text-secondary); padding:4px 0 6px 52px; font-style:italic;">
                    "<?php echo htmlspecialchars($ref['note']); ?>"
                </div>
            <?php endif; ?>
        <?php endwhile; ?>
    </div>
</div>
<?php endif; ?>

<!-- Sessions Table -->
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
            <a href="<?php echo buildTabUrl('', $sort, $order); ?>" class="filter-tab <?php echo $status_filter === '' ? 'active' : ''; ?>">All</a>
            <a href="<?php echo buildTabUrl('scheduled', $sort, $order); ?>" class="filter-tab <?php echo $status_filter === 'scheduled' ? 'active' : ''; ?>"><i data-lucide="hourglass" class="lucide-sm"></i> Scheduled</a>
            <a href="<?php echo buildTabUrl('under-review', $sort, $order); ?>" class="filter-tab <?php echo $status_filter === 'under-review' ? 'active' : ''; ?>">&#x1F50E; Under Review</a>
            <a href="<?php echo buildTabUrl('completed', $sort, $order); ?>" class="filter-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>"><i data-lucide="check-circle" class="lucide-sm"></i> Completed</a>
            <a href="<?php echo buildTabUrl('cancelled', $sort, $order); ?>" class="filter-tab <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">&#x274C; Cancelled</a>
            <a href="<?php echo buildTabUrl('refunded', $sort, $order); ?>" class="filter-tab <?php echo $status_filter === 'refunded' ? 'active' : ''; ?>">&#x1F4B8; Refunded</a>
        </div>
    </div>

    <?php if ($disputes->num_rows > 0): ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>
                        <span class="th-content">
                            <span>ID</span>
                            <?php echo renderSortButtons('id', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Skill</span>
                            <?php echo renderSortButtons('skill', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Requester</span>
                            <?php echo renderSortButtons('requester', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Provider</span>
                            <?php echo renderSortButtons('provider', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Scheduled</span>
                            <?php echo renderSortButtons('scheduled', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Duration</span>
                            <?php echo renderSortButtons('duration', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Credits</span>
                            <?php echo renderSortButtons('credits', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Status</span>
                            <?php echo renderSortButtons('status', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Review</span>
                            <?php echo renderSortButtons('rating', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($d = $disputes->fetch_assoc()):
                    $sc = 'badge-warning';
                    if ($d['status'] === 'completed')
                        $sc = 'badge-success';
                    elseif ($d['status'] === 'under-review')
                        $sc = 'badge-info';
                    elseif ($d['status'] === 'cancelled')
                        $sc = 'badge-danger';
                    elseif ($d['status'] === 'refunded')
                        $sc = 'badge-secondary';

                    // Calculate refund eligibility
                    $completion = $d['completion_time'] ?? $d['scheduled_time'];
                    $days_since = (time() - strtotime($completion)) / 86400;
                    $refund_eligible = ($d['status'] === 'completed' && $days_since <= REFUND_WINDOW_DAYS);
                    $days_remaining = max(0, REFUND_WINDOW_DAYS - $days_since);
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
                        <td>
                            <span class="badge <?php echo $sc; ?>"><?php echo ucfirst($d['status']); ?></span>
                            <?php if ($d['submission_note']): ?>
                                <br><small style="color:var(--text-muted); font-size:0.75rem;">Proof: "<?php echo htmlspecialchars($d['submission_note']); ?>"</small>
                            <?php endif; ?>
                        </td>
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
                                        <button type="submit" class="btn btn-sm btn-success" title="Force complete without credit transfer"><i data-lucide="check-circle" class="lucide-sm"></i></button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="session_id" value="<?php echo $d['session_id']; ?>">
                                        <input type="hidden" name="action" value="resolve_cancelled">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Cancel session">&#x274C;</button>
                                    </form>
                                </div>
                            <?php elseif ($d['status'] === 'under-review'): ?>
                                <div class="flex gap-1" style="flex-wrap:nowrap;">
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="session_id" value="<?php echo $d['session_id']; ?>">
                                        <input type="hidden" name="action" value="approve_completion">
                                        <button type="submit" class="btn btn-sm btn-success" title="Approve and Transfer Credits"><i data-lucide="check-circle" class="lucide-sm"></i> Approve</button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="session_id" value="<?php echo $d['session_id']; ?>">
                                        <input type="hidden" name="action" value="reject_proof">
                                        <button type="submit" class="btn btn-sm btn-warning" title="Reject Proof">&#x21A9;&#xFE0F; Reject</button>
                                    </form>
                                </div>
                            <?php elseif ($d['status'] === 'completed'): ?>
                                <?php if ($refund_eligible): ?>
                                    <button type="button" class="btn btn-sm btn-danger"
                                        onclick="openRefundModal(<?php echo $d['session_id']; ?>, '<?php echo addslashes(htmlspecialchars($d['requester_name'])); ?>', '<?php echo addslashes(htmlspecialchars($d['provider_name'])); ?>', <?php echo $d['time_credit_transfer']; ?>, <?php echo round($days_remaining, 1); ?>)"
                                        title="Issue refund">&#x1F4B8; Refund</button>
                                <?php else: ?>
                                    <span style="font-size:0.72rem; color:var(--text-muted);" title="Refund window (<?php echo REFUND_WINDOW_DAYS; ?> days) has expired">&#x23F0; Expired</span>
                                <?php endif; ?>
                            <?php elseif ($d['status'] === 'refunded'): ?>
                                <span class="badge" style="background:rgba(156,39,176,0.1); color:#9c27b0; font-size:0.7rem;">Refunded</span>
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

<!-- ========== REFUND MODAL ========== -->
<div class="modal-overlay" id="refund-modal">
    <div class="modal">
        <div class="modal-header">
            <h3>&#x1F4B8; Issue Refund</h3>
            <button class="modal-close" onclick="document.getElementById('refund-modal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="refund">
            <input type="hidden" name="session_id" id="refund-session-id">

            <!-- Session Info -->
            <div style="background:var(--bg-hover); border-radius:var(--radius-sm); padding:12px; margin-bottom:16px;">
                <div class="grid-2" style="gap:8px;">
                    <div><small style="color:var(--text-muted);">Session</small><br><strong id="refund-session-label">#—</strong></div>
                    <div><small style="color:var(--text-muted);">Max Amount</small><br><strong id="refund-max-label">— TC</strong></div>
                    <div><small style="color:var(--text-muted);">Requester (receives)</small><br><span id="refund-requester-label">—</span></div>
                    <div><small style="color:var(--text-muted);">Provider (deducted)</small><br><span id="refund-provider-label">—</span></div>
                </div>
                <div style="margin-top:8px; font-size:0.78rem; color:var(--text-muted);">
                    &#x23F0; <span id="refund-days-label">—</span> days remaining in refund window
                </div>
            </div>

            <!-- Refund Amount -->
            <div class="form-group">
                <label>Refund Amount (TC) *</label>
                <div class="flex gap-1" style="align-items:center;">
                    <input type="number" name="refund_amount" id="refund-amount" class="form-control" min="0.01" step="0.01" required style="flex:1;">
                    <button type="button" class="btn btn-sm btn-secondary" onclick="document.getElementById('refund-amount').value = document.getElementById('refund-amount').max" title="Set to full amount">Full</button>
                </div>
                <small style="color:var(--text-muted); font-size:0.72rem;">Enter the full amount for a complete refund, or a smaller amount for a partial refund.</small>
            </div>

            <!-- Refund Reason -->
            <div class="form-group">
                <label>Reason for Refund *</label>
                <select id="refund-reason-select" class="form-control" onchange="handleReasonSelect()">
                    <option value="">Select a reason...</option>
                    <option value="Poor quality session">Poor quality session</option>
                    <option value="Provider no-show">Provider no-show</option>
                    <option value="Session was incomplete">Session was incomplete</option>
                    <option value="User complaint">User complaint</option>
                    <option value="Billing error">Billing error</option>
                    <option value="__custom__">Other (custom reason)</option>
                </select>
                <textarea name="refund_reason" id="refund-reason-text" class="form-control mt-1" rows="2" placeholder="Describe the refund reason..." required></textarea>
            </div>

            <!-- Impact Warning -->
            <div class="alert alert-warning" style="font-size:0.82rem; margin-bottom:16px;">
                <strong>&#x26A0; Refund Impact:</strong>
                <ul style="margin:6px 0 0 16px; padding:0;">
                    <li>Credits will be returned to the requester's wallet</li>
                    <li>Credits will be deducted from the provider's wallet</li>
                    <li>Provider's completed session count will decrease by 1</li>
                    <li>Session rating will be removed</li>
                    <li>Session status will change to "Refunded"</li>
                </ul>
            </div>

            <div class="flex gap-1" style="justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('refund-modal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-danger">Confirm Refund</button>
            </div>
        </form>
    </div>
</div>

<script>
// Open refund modal with session details
function openRefundModal(sessionId, requester, provider, maxAmount, daysLeft) {
    document.getElementById('refund-session-id').value = sessionId;
    document.getElementById('refund-session-label').textContent = '#' + sessionId;
    document.getElementById('refund-max-label').textContent = maxAmount.toFixed(2) + ' TC';
    document.getElementById('refund-requester-label').textContent = requester;
    document.getElementById('refund-provider-label').textContent = provider;
    document.getElementById('refund-days-label').textContent = daysLeft;

    var amountInput = document.getElementById('refund-amount');
    amountInput.max = maxAmount;
    amountInput.value = maxAmount;

    // Reset reason fields
    document.getElementById('refund-reason-select').value = '';
    document.getElementById('refund-reason-text').value = '';

    document.getElementById('refund-modal').classList.add('active');
}

// Handle reason dropdown
function handleReasonSelect() {
    var select = document.getElementById('refund-reason-select');
    var textarea = document.getElementById('refund-reason-text');
    if (select.value && select.value !== '__custom__') {
        textarea.value = select.value;
    } else if (select.value === '__custom__') {
        textarea.value = '';
        textarea.focus();
    }
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('active');
    });
});

// Close modals on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(function(m) {
            m.classList.remove('active');
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>