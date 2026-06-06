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
        
        $check_bal = $conn->query("SELECT balance FROM wallet WHERE user_id = $user_id")->fetch_assoc();
        if ($check_bal && $check_bal['balance'] > 0) {
            $error_msg = 'You can only request a loan when your balance is 0.';
        } else {
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
        
        if ($amount > 5) {
            $error_msg = 'You can only send a maximum of 5 TC as a gift.';
        } else {
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
}

// Fetch wallet state after potential updates
$wallet = $conn->query("SELECT * FROM wallet WHERE user_id = $user_id")->fetch_assoc();
$balance = $wallet ? $wallet['balance'] : 0;

// Fetch active or defaulted loans (show most urgent first)
$active_loan = $conn->query("SELECT * FROM loans WHERE user_id = $user_id AND status IN ('active', 'defaulted') ORDER BY FIELD(status, 'defaulted', 'active') LIMIT 1")->fetch_assoc();

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

        <!-- Section 1: Balance + Compact Stats Bar -->
        <div class="wallet-hero">
            <div class="wallet-hero-top">
                <div class="balance-amount"><?php echo number_format($balance, 2); ?> TC</div>
                <div class="balance-label">Current Balance (Time Credits)</div>
                <div style="margin-top: 10px;">
                    <?php if ($above_avg): ?>
                        <span class="badge badge-success">&#10003; Above Platform Average</span>
                    <?php else: ?>
                        <span class="badge badge-warning">Below Platform Average</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="wallet-inline-stats">
                <div class="w-stat">
                    <span class="w-stat-value" style="color: var(--success);"><?php echo number_format($earned, 2); ?> TC</span>
                    <span class="w-stat-label">Total Earned</span>
                </div>
                <div class="w-stat">
                    <span class="w-stat-value" style="color: var(--danger);"><?php echo number_format($spent, 2); ?> TC</span>
                    <span class="w-stat-label">Total Spent</span>
                </div>
                <div class="w-stat">
                    <span class="w-stat-value"><?php echo $hours_taught; ?> hrs</span>
                    <span class="w-stat-label">Hours Taught</span>
                </div>
                <div class="w-stat">
                    <span class="w-stat-value" style="color: var(--primary);"><?php echo $completed_sess; ?></span>
                    <span class="w-stat-label">Sessions Completed</span>
                </div>
            </div>
        </div>

        <!-- Section 2: Actions Panel (Tabbed) -->
        <div class="wallet-tabs">
            <div class="wallet-tab-nav">
                <?php if ($active_loan): ?>
                    <button class="wallet-tab-btn active" data-tab="loan-tab">
                        <span class="tab-icon"><i data-lucide="clipboard" class="lucide-sm"></i></span> Active Loan
                    </button>
                <?php else: ?>
                    <button class="wallet-tab-btn active" data-tab="borrow-tab">
                        <span class="tab-icon"><i data-lucide="landmark" class="lucide-sm"></i></span> Borrow Credits
                    </button>
                <?php endif; ?>
                <button class="wallet-tab-btn" data-tab="gift-tab">
                    <span class="tab-icon"><i data-lucide="gift" class="lucide-sm"></i></span> Gift Credits
                </button>
            </div>

            <!-- Tab Content: Active Loan (if active) -->
            <?php if ($active_loan): ?>
                <div class="wallet-tab-panel active" id="loan-tab">
                    <div class="wallet-section-title">Active Loan Details</div>
                    <div class="card" style="border: 1px solid <?php echo $active_loan['status'] === 'defaulted' ? 'var(--danger)' : 'var(--border-light)'; ?>; background: <?php echo $active_loan['status'] === 'defaulted' ? 'rgba(186, 26, 26, 0.02)' : 'var(--bg-secondary)'; ?>; margin: 0; box-shadow: none;">
                        <div class="card-header" style="padding-top: 0; padding-left: 0; padding-right: 0;">
                            <h3 style="font-size: 1.1rem;"><?php echo $active_loan['status'] === 'defaulted' ? '<i data-lucide="alert-triangle" class="lucide-sm"></i> Overdue Loan Notification' : 'Outstanding Balance'; ?></h3>
                            <span class="badge <?php echo $active_loan['status'] === 'defaulted' ? 'badge-danger' : 'badge-warning'; ?>">
                                <?php echo $active_loan['status'] === 'defaulted' ? 'DEFAULTED' : 'UNPAID'; ?>
                            </span>
                        </div>
                        
                        <?php if ($active_loan['status'] === 'defaulted'): ?>
                            <p style="color: var(--danger); font-size: 0.85rem; margin-top: 10px; font-weight: 500; line-height: 1.4;">
                                <i data-lucide="alert-triangle" class="lucide-sm"></i> This loan is overdue and has been marked as defaulted. Your reliability score has been penalized. Session booking is blocked until repayment.
                            </p>
                        <?php endif; ?>
                        
                        <!-- Loan Info Grid -->
                        <div class="loan-info-grid">
                            <div class="loan-info-item">
                                <span class="info-value"><?php echo number_format($active_loan['amount'], 2); ?> TC</span>
                                <span class="info-label">Principal</span>
                            </div>
                            <div class="loan-info-item">
                                <span class="info-value"><?php echo number_format($active_loan['interest_rate'], 1); ?>%</span>
                                <span class="info-label">Interest</span>
                            </div>
                            <div class="loan-info-item">
                                <span class="info-value" style="color: var(--danger);"><?php echo number_format($active_loan['total_due'], 2); ?> TC</span>
                                <span class="info-label">Total Due</span>
                            </div>
                        </div>

                        <p style="color: var(--text-muted); font-size: 0.8rem; margin: 12px 0 20px;">
                            Repayment Due Date: <strong><?php echo date('M d, Y', strtotime($active_loan['due_date'])); ?></strong>
                            <?php if ($active_loan['status'] === 'defaulted'): ?>
                                <span style="color: var(--danger); font-weight: 600;"> (OVERDUE)</span>
                            <?php endif; ?>
                        </p>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="repay_loan">
                            <input type="hidden" name="loan_id" value="<?php echo $active_loan['loan_id']; ?>">
                            <button type="submit" class="btn btn-primary" style="background: var(--primary); color: white; width:100%; padding: 12px; border-radius:var(--radius-md); border:none; font-weight:600; cursor:pointer; font-size: 0.95rem; transition: background 0.2s;">
                                Repay <?php echo number_format($active_loan['total_due'], 2); ?> TC
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Tab Content: Request Loan -->
                <div class="wallet-tab-panel active" id="borrow-tab">
                    <div class="wallet-section-title">Borrow Time Credits</div>
                    <?php if ($completed_sess >= 2 && $balance <= 0): ?>
                        <p style="color: var(--text-secondary); font-size: 0.88rem; margin-bottom: 12px; line-height: 1.4;">
                            You qualify for a platform loan. Your borrow limit based on your reliability score is <strong><?php echo number_format($max_borrow_limit, 2); ?> TC</strong>.
                        </p>
                        <p style="color: var(--text-muted); font-size: 0.8rem; margin-bottom: 20px; line-height: 1.4;">
                            <i data-lucide="pin" class="lucide-sm"></i> A <strong>5% interest rate</strong> applies. Repayment is due within <strong>30 days</strong>. Overdue loans are automatically marked as defaulted and result in a reliability penalty.
                        </p>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="request_loan">
                            <div class="form-group" style="margin-bottom: 16px;">
                                <label for="borrow_amount" style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 6px; color: var(--text-primary);">Amount to Borrow (TC)</label>
                                <input type="number" id="borrow_amount" name="amount" class="form-control" 
                                       min="1" max="<?php echo $max_borrow_limit; ?>" step="0.5" 
                                       placeholder="e.g. 10"
                                       style="width: 100%; padding: 10px 14px; border-radius: var(--radius-md); border: 1px solid var(--border-color); outline:none; background:var(--bg-secondary); color:var(--text-primary); font-size: 0.9rem;" 
                                       required>
                            </div>
                            <button type="submit" class="btn btn-primary" style="background: var(--primary); color: white; width:100%; padding: 12px; border-radius:var(--radius-md); border:none; font-weight:600; cursor:pointer; font-size: 0.95rem;">
                                Request Loan
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="locked-state" style="background: rgba(115, 119, 129, 0.03); border: 1px dashed var(--border-light); padding: 24px; border-radius: var(--radius-md); text-align: center;">
                            <div style="font-size: 2rem; margin-bottom: 12px;"><i data-lucide="lock" class="lucide-sm"></i></div>
                            <p style="font-size: 0.9rem; color: var(--text-secondary); font-weight: 600; margin-bottom: 6px;">
                                Borrowing Option Locked
                            </p>
                            <p style="font-size: 0.82rem; color: var(--text-muted); line-height: 1.4; max-width: 320px; margin: 0 auto;">
                                <?php if ($balance > 0): ?>
                                    You can only request a loan when your balance is 0 TC.<br>
                                    <span style="display:inline-block; margin-top: 6px; font-weight: 600; color: var(--primary);">Current balance: <?php echo number_format($balance, 2); ?> TC</span>
                                <?php else: ?>
                                    Complete at least 2 exchange sessions to unlock credit borrowing.<br>
                                    <span style="display:inline-block; margin-top: 6px; font-weight: 600; color: var(--primary);">Current completed: <?php echo $completed_sess; ?> / 2</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Tab Content: Gift Credits -->
            <div class="wallet-tab-panel" id="gift-tab">
                <div class="wallet-section-title">Gift Time Credits</div>
                <p style="color: var(--text-muted); font-size: 0.82rem; margin-bottom: 20px; line-height: 1.4;">
                    Transfer credits to a collaborator. Maximum <strong>5 TC</strong> per transfer, <strong>50 TC</strong> daily limit. 
                    Gifts are restricted to collaborators with mutual session history or established accounts.
                </p>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="gift_credits">
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label for="recipient_email" style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 6px; color: var(--text-primary);">Recipient Email</label>
                        <input type="email" id="recipient_email" name="recipient_email" class="form-control" 
                               placeholder="collaborator@example.com" 
                               style="width: 100%; padding: 10px 14px; border-radius: var(--radius-md); border: 1px solid var(--border-color); outline:none; background:var(--bg-secondary); color:var(--text-primary); font-size: 0.9rem;" 
                               required>
                    </div>
                    <div class="form-group" style="margin-bottom: 18px;">
                        <label for="gift_amount" style="font-size: 0.85rem; font-weight: 600; display: block; margin-bottom: 6px; color: var(--text-primary);">Amount to Gift (TC)</label>
                        <input type="number" id="gift_amount" name="amount" class="form-control" 
                               min="0.5" max="5" step="0.5" placeholder="e.g. 5"
                               style="width: 100%; padding: 10px 14px; border-radius: var(--radius-md); border: 1px solid var(--border-color); outline:none; background:var(--bg-secondary); color:var(--text-primary); font-size: 0.9rem;" 
                               required>
                    </div>
                    <button type="submit" class="btn btn-secondary" style="width:100%; padding: 12px; border-radius:var(--radius-md); border: 1px solid var(--border-color); font-weight:600; cursor:pointer; font-size: 0.95rem; background: var(--bg-card); color: var(--text-primary); transition: all 0.2s;">
                        Send Gift
                    </button>
                </form>
            </div>
        </div>

        <!-- Section 3: History & Insights (Grid-2 layout) -->
        <div class="grid-2" style="align-items: start;">
            
            <!-- Left: Transaction History -->
            <div class="card" style="margin-bottom: 0;">
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
                                <?php 
                                $cnt = 0;
                                while ($t = $transactions->fetch_assoc()):
                                    $is_incoming = ($t['to_user_id'] == $user_id);
                                    $cnt++;
                                    $row_style = ($cnt > 5) ? 'display: none;' : '';
                                    $row_class = ($cnt > 5) ? 'hidden-txn-row' : '';
                                    ?>
                                    <tr class="<?php echo $row_class; ?>" style="<?php echo $row_style; ?>">
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
                    <?php if ($transactions->num_rows > 5): ?>
                        <div style="text-align: center; padding: 12px; border-top: 1px solid var(--border-light); background: var(--bg-primary); border-bottom-left-radius: var(--radius-md); border-bottom-right-radius: var(--radius-md);">
                            <button id="toggleTxnBtn" style="background: none; border: none; color: var(--primary); font-family: var(--font-main); font-weight: 700; font-size: 0.85rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; outline: none; transition: var(--transition); padding: 6px 12px; border-radius: var(--radius-sm);">
                                <span id="toggleTxnText">Show More Transactions</span>
                                <span id="toggleTxnIcon" style="font-size: 0.7rem; transition: transform 0.3s; display: inline-block;">▼</span>
                            </button>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon">&#128176;</div>
                        <p>No transactions yet. Complete a session to earn Time Credits!</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: Top Net Earners Leaderboard (CQ-4) -->
            <div class="card" style="margin-bottom: 0;">
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Wallet Tab Switching
    const tabBtns = document.querySelectorAll('.wallet-tab-btn');
    const tabPanels = document.querySelectorAll('.wallet-tab-panel');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            // Remove active class from all buttons and panels
            tabBtns.forEach(b => b.classList.remove('active'));
            tabPanels.forEach(p => p.classList.remove('active'));

            // Add active class to clicked button
            this.classList.add('active');

            // Show target panel
            const targetTab = this.getAttribute('data-tab');
            const targetPanel = document.getElementById(targetTab);
            if (targetPanel) {
                targetPanel.classList.add('active');
            }
        });
    });

    // Collapsible Transaction History
    const toggleBtn = document.getElementById('toggleTxnBtn');
    if (toggleBtn) {
        let expanded = false;
        const hiddenRows = document.querySelectorAll('.hidden-txn-row');
        const textSpan = document.getElementById('toggleTxnText');
        const iconSpan = document.getElementById('toggleTxnIcon');

        toggleBtn.addEventListener('click', function() {
            expanded = !expanded;
            hiddenRows.forEach(row => {
                if (expanded) {
                    row.style.display = 'table-row';
                    row.style.opacity = '0';
                    setTimeout(() => {
                        row.style.transition = 'opacity 0.25s ease-in-out';
                        row.style.opacity = '1';
                    }, 20);
                } else {
                    row.style.display = 'none';
                }
            });

            // Adjust button look
            textSpan.textContent = expanded ? 'Show Less Transactions' : 'Show More Transactions';
            iconSpan.style.transform = expanded ? 'rotate(180deg)' : 'rotate(0deg)';
            toggleBtn.style.color = expanded ? 'var(--info)' : 'var(--primary)';
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>