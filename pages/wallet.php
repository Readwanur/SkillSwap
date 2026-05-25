<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'Credit Wallet';

$success_msg = '';
$error_msg = '';

// Handle POST actions for loans and gifts
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    
    if ($action === 'request_loan') {
        $amount = floatval($_POST['amount'] ?? 0);
        
        $stmt = $conn->prepare("CALL sp_request_loan(?, ?, @status, @msg)");
        $stmt->bind_param("id", $user_id, $amount);
        $stmt->execute();
        $stmt->close();
        
        $res = $conn->query("SELECT @status AS status, @msg AS msg")->fetch_assoc();
        if ($res['status'] === 'success') {
            $success_msg = $res['msg'];
        } else {
            $error_msg = $res['msg'];
        }
    } 
    elseif ($action === 'repay_loan') {
        $loan_id = intval($_POST['loan_id'] ?? 0);
        
        $stmt = $conn->prepare("CALL sp_repay_loan(?, ?, @status, @msg)");
        $stmt->bind_param("ii", $user_id, $loan_id);
        $stmt->execute();
        $stmt->close();
        
        $res = $conn->query("SELECT @status AS status, @msg AS msg")->fetch_assoc();
        if ($res['status'] === 'success') {
            $success_msg = $res['msg'];
        } else {
            $error_msg = $res['msg'];
        }
    } 
    elseif ($action === 'gift_credits') {
        $recipient_email = trim($_POST['recipient_email'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        
        $stmt = $conn->prepare("CALL sp_gift_credits(?, ?, ?, @status, @msg)");
        $stmt->bind_param("isd", $user_id, $recipient_email, $amount);
        $stmt->execute();
        $stmt->close();
        
        $res = $conn->query("SELECT @status AS status, @msg AS msg")->fetch_assoc();
        if ($res['status'] === 'success') {
            $success_msg = $res['msg'];
        } else {
            $error_msg = $res['msg'];
        }
    }
}

// Fetch wallet state after potential updates
$wallet = $conn->query("SELECT * FROM wallet WHERE user_id = $user_id")->fetch_assoc();
$balance = $wallet ? $wallet['balance'] : 0;

// Fetch active loans
$active_loan = $conn->query("SELECT * FROM loans WHERE user_id = $user_id AND status = 'active' LIMIT 1")->fetch_assoc();

// Calculate max borrow limit based on user reliability score
$user_reliability = $conn->query("SELECT reliability_score FROM users WHERE user_id = $user_id")->fetch_assoc()['reliability_score'];
$user_reliability = $user_reliability !== null ? floatval($user_reliability) : 5.00;
$max_borrow_limit = $user_reliability * 5.00;

// Fetch completed sessions from reputation to check loan eligibility (needs >= 2)
$completed_sess = $conn->query("SELECT completed_sessions FROM reputation WHERE user_id = $user_id")->fetch_assoc()['completed_sessions'];
$completed_sess = $completed_sess ? intval($completed_sess) : 0;

// Aggregate: Total earned and spent (excluding loan liabilities)
$earned = $conn->query("SELECT COALESCE(SUM(final_amount), 0) AS total FROM transactions WHERE to_user_id = $user_id AND type NOT IN ('loan_disbursement', 'loan_repayment')")->fetch_assoc()['total'];
$spent = $conn->query("SELECT COALESCE(SUM(final_amount), 0) AS total FROM transactions WHERE from_user_id = $user_id AND type NOT IN ('loan_disbursement', 'loan_repayment')")->fetch_assoc()['total'];

// Aggregate: Total hours taught
$hours_taught = $conn->query("SELECT COALESCE(SUM(session_duration), 0) AS total_min FROM exchange_sessions WHERE provider_id = $user_id AND status = 'completed'")->fetch_assoc()['total_min'];
$hours_taught = round($hours_taught / 60, 1);

// Subquery: Is my balance above the platform average?
$above_avg = $conn->query("
    SELECT (SELECT balance FROM wallet WHERE user_id = $user_id) >
           (SELECT AVG(balance) FROM wallet) AS is_above
")->fetch_assoc()['is_above'];

// Sorting parameters
$sort = trim($_GET['sort'] ?? 'date');
$order = trim($_GET['order'] ?? 'desc');

$allowed_sorts = [
    'id' => 'transcation_id',
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
    return 'wallet.php?' . http_build_query($query);
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
// Fetch transactions using the transaction view
$transactions = $conn->query("
    SELECT *
    FROM vw_transaction_ledger
    WHERE from_user_id = $user_id OR to_user_id = $user_id
    ORDER BY $sort_col $order
");

// --- COMPLEX QUERY: CQ-4 ---
// Top Net Earners Leaderboard (HAVING and correlated aggregate subqueries)
$net_earners = $conn->query("
    SELECT
        u.user_id,
        u.name,
        w.balance,
        (SELECT COALESCE(SUM(final_amount), 0) FROM transactions WHERE to_user_id = u.user_id) AS total_earned,
        (SELECT COALESCE(SUM(final_amount), 0) FROM transactions WHERE from_user_id = u.user_id) AS total_spent
    FROM users u
    JOIN wallet w ON u.user_id = w.user_id
    HAVING total_earned > total_spent
    ORDER BY (total_earned - total_spent) DESC
    LIMIT 5
");

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <h1 class="page-title">Credit Wallet</h1>

        <?php if ($success_msg): ?>
            <div class="alert alert-success" style="margin-bottom: 20px; padding: 12px 20px; border-radius: var(--radius-sm); background: rgba(26, 122, 66, 0.1); color: var(--success); border: 1px solid rgba(26, 122, 66, 0.2); font-weight: 500;">
                <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px; padding: 12px 20px; border-radius: var(--radius-sm); background: rgba(186, 26, 26, 0.1); color: var(--danger); border: 1px solid rgba(186, 26, 26, 0.2); font-weight: 500;">
                <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <!-- Balance Card -->
        <div class="wallet-balance">
            <div class="balance-amount"><?php echo number_format($balance, 2); ?> TC</div>
            <div class="balance-label">Current Balance (Time Credits)</div>
            <?php if ($above_avg): ?>
                <span class="badge badge-success mt-1">&#10003; Above Platform Average</span>
            <?php else: ?>
                <span class="badge badge-warning mt-1">Below Platform Average</span>
            <?php endif; ?>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-value" style="color: var(--success);"><?php echo number_format($earned, 2); ?></span>
                <span class="stat-label">Total Earned (TC)</span>
            </div>
            <div class="stat-card">
                <span class="stat-value" style="color: var(--danger);"><?php echo number_format($spent, 2); ?></span>
                <span class="stat-label">Total Spent (TC)</span>
            </div>
            <div class="stat-card">
                <span class="stat-value"><?php echo $hours_taught; ?></span>
                <span class="stat-label">Hours Taught</span>
            </div>
        </div>

        <!-- Grid Layout for Transaction History & Top Earners -->
        <div class="grid-2" style="align-items: start;">
            
            <!-- Left: Transaction History -->
            <div class="card">
                <div class="card-header">
                    <h3>Transaction History</h3>
                </div>

                <?php if ($transactions->num_rows > 0): ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>
                                        <span class="th-content">
                                            <span>ID</span>
                                            <?php echo renderSortButtons('id', $sort, $order); ?>
                                        </span>
                                    </th>
                                    <th>Type</th>
                                    <th>Skill</th>
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
                                <?php while ($t = $transactions->fetch_assoc()):
                                    $is_incoming = ($t['to_user_id'] == $user_id);
                                    ?>
                                    <tr>
                                        <td>#<?php echo $t['transcation_id']; ?></td>
                                        <td>
                                            <?php if ($is_incoming): ?>
                                                <span class="badge badge-success">Received</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">Sent</span>
                                            <?php endif; ?>
                                            <br><small style="color:var(--text-muted); font-size:0.75rem;"><?php echo htmlspecialchars($t['transaction_category']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($t['skill_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($t['sender_name'] ?? 'System / Reward'); ?></td>
                                        <td><?php echo htmlspecialchars($t['receiver_name'] ?? 'System'); ?></td>
                                        <td
                                            style="color: <?php echo $is_incoming ? 'var(--success)' : 'var(--danger)'; ?>; font-weight:600;">
                                            <?php echo $is_incoming ? '+' : '-'; ?> <?php echo number_format($t['final_amount'], 2); ?> TC
                                        </td>
                                        <td><?php echo date('M d, Y h:i A', strtotime($t['timestamp'])); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon">&#128176;</div>
                        <p>No transactions yet. Complete a session to earn Time Credits!</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: Actions & Leaderboards -->
            <div style="display: flex; flex-direction: column; gap: 24px;">

                <!-- Active Loan Card -->
                <?php if ($active_loan): ?>
                    <div class="card" style="border: 1px solid var(--border-color); background: rgba(0, 56, 108, 0.02);">
                        <div class="card-header">
                            <h3>Active Loan</h3>
                            <span class="badge badge-danger">Unpaid</span>
                        </div>
                        <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 10px;">
                            You currently have an outstanding credit loan of <strong><?php echo number_format($active_loan['amount'], 2); ?> TC</strong> from the platform.
                        </p>
                        <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 5px; margin-bottom: 20px;">
                            Due date: <?php echo date('M d, Y', strtotime($active_loan['due_date'])); ?>
                        </p>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="repay_loan">
                            <input type="hidden" name="loan_id" value="<?php echo $active_loan['loan_id']; ?>">
                            <button type="submit" class="btn btn-primary btn-block" style="background: var(--primary); color: white; width:100%; padding: 10px; border-radius:var(--radius-sm); border:none; font-weight:600; cursor:pointer;">
                                Repay <?php echo number_format($active_loan['amount'], 2); ?> TC
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- Borrow Card -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Borrow Time Credits</h3>
                            <span class="badge badge-success">Available</span>
                        </div>
                        
                        <?php if ($completed_sess >= 2): ?>
                            <p style="color: var(--text-secondary); font-size: 0.88rem; margin-top: 10px; margin-bottom: 15px;">
                                You qualify for a platform loan. Your borrow limit based on your reliability score is <strong><?php echo number_format($max_borrow_limit, 2); ?> TC</strong>.
                            </p>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="request_loan">
                                <div class="form-group" style="margin-bottom: 15px;">
                                    <label for="borrow_amount" style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 5px;">Amount to Borrow (TC)</label>
                                    <input type="number" id="borrow_amount" name="amount" class="form-control" 
                                           min="1" max="<?php echo $max_borrow_limit; ?>" step="0.5" 
                                           style="width: 100%; padding: 8px 12px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); outline:none; background:var(--bg-secondary); color:var(--text-primary);" 
                                           required>
                                </div>
                                <button type="submit" class="btn btn-primary btn-block" style="background: var(--primary); color: white; width:100%; padding: 10px; border-radius:var(--radius-sm); border:none; font-weight:600; cursor:pointer;">
                                    Request Loan
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="locked-state" style="background: rgba(115, 119, 129, 0.05); padding: 16px; border-radius: var(--radius-md); text-align: center; margin-top: 10px;">
                                <div style="font-size: 1.5rem; margin-bottom: 8px;">🔒</div>
                                <p style="font-size: 0.85rem; color: var(--text-secondary); font-weight: 500;">
                                    Loan Locked. Complete at least 2 sessions to unlock credit borrowing (Current completed: <?php echo $completed_sess; ?>).
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Gift Card -->
                <div class="card">
                    <div class="card-header">
                        <h3>Gift Time Credits</h3>
                        <span class="badge badge-orange">Transfer</span>
                    </div>
                    <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 5px; margin-bottom: 15px;">
                        Transfer credits to a collaborator. Locked until you have completed a session together.
                    </p>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="gift_credits">
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label for="recipient_email" style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 5px;">Recipient Email</label>
                            <input type="email" id="recipient_email" name="recipient_email" class="form-control" 
                                   placeholder="collaborator@example.com" 
                                   style="width: 100%; padding: 8px 12px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); outline:none; background:var(--bg-secondary); color:var(--text-primary);" 
                                   required>
                        </div>
                        <div class="form-group" style="margin-bottom: 15px;">
                            <label for="gift_amount" style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 5px;">Amount to Gift (TC)</label>
                            <input type="number" id="gift_amount" name="amount" class="form-control" 
                                   min="0.5" step="0.5" 
                                   style="width: 100%; padding: 8px 12px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); outline:none; background:var(--bg-secondary); color:var(--text-primary);" 
                                   required>
                        </div>
                        <button type="submit" class="btn btn-secondary btn-block" style="width:100%; padding: 10px; border-radius:var(--radius-sm); border: 1px solid var(--border-color); font-weight:600; cursor:pointer;">
                            Send Gift
                        </button>
                    </form>
                </div>

                <!-- Top Net Earners Leaderboard (CQ-4) -->
                <div class="card">
                    <div class="card-header">
                        <h3>Top Net Earners</h3>
                        <span class="badge badge-orange">Net Positive</span>
                    </div>
                    <p style="color: var(--text-muted); font-size: 0.8rem; margin-bottom: 15px;">
                        Users who have contributed more time credits to the platform than they have spent.
                    </p>
                    <?php if ($net_earners && $net_earners->num_rows > 0): ?>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th style="text-align: right;">Net Surplus</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $rank = 1;
                                    while ($ne = $net_earners->fetch_assoc()): 
                                        $net_surplus = $ne['total_earned'] - $ne['total_spent'];
                                        $is_current_user = ($ne['user_id'] == $user_id);
                                        ?>
                                        <tr style="<?php echo $is_current_user ? 'background: var(--primary-glow); font-weight: 600;' : ''; ?>">
                                            <td>
                                                <span style="font-weight: bold; color: var(--secondary); margin-right: 5px;"><?php echo $rank++; ?>.</span>
                                                <?php echo htmlspecialchars($ne['name']); ?>
                                                <?php if ($is_current_user): ?>
                                                    <span class="badge badge-success" style="font-size:0.6rem; padding: 2px 6px;">You</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right; color: var(--success); font-weight: 600;">
                                                +<?php echo number_format($net_surplus, 1); ?> TC
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="color: var(--text-muted); font-size: 0.85rem; text-align: center;">No net earners recorded yet.</p>
                    <?php endif; ?>
                </div>

            </div>

        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>