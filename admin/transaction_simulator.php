<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'ACID Transaction Simulator';

$simulation_result = null;
$logs = [];

// Fetch all users with their balances for selection and monitoring
$users_query = "
    SELECT u.user_id, u.name, u.email, w.balance, u.reliability_score
    FROM users u
    JOIN wallet w ON u.user_id = w.user_id
    LEFT JOIN reputation r ON u.user_id = r.user_id
    ORDER BY u.name ASC
";
$users_list = [];
$res = $conn->query($users_query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $users_list[$row['user_id']] = $row;
    }
}

// Process simulation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simulate_action'])) {
    $action = $_POST['simulate_action'];
    $inject_failure = isset($_POST['inject_failure']) && $_POST['inject_failure'] == '1';
    
    // Store pre-transaction balances
    $pre_balances = [];
    foreach ($users_list as $uid => $u) {
        $pre_balances[$uid] = $u['balance'];
    }

    $logs[] = "[SESSION] Initializing simulation: " . ($action === 'transfer' ? 'P2P Transfer' : 'Loan Issuance');
    $logs[] = "[SQL] START TRANSACTION;";
    
    $conn->begin_transaction();
    
    try {
        if ($action === 'transfer') {
            $from_id = intval($_POST['from_user_id'] ?? 0);
            $to_id = intval($_POST['to_user_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            
            if ($from_id <= 0 || $to_id <= 0 || $from_id === $to_id) {
                throw new Exception("Invalid sender or receiver selection.");
            }
            if ($amount <= 0) {
                throw new Exception("Amount must be greater than 0.");
            }
            
            // Lock and check sender balance
            $logs[] = "[SQL] SELECT balance FROM wallet WHERE user_id = $from_id FOR UPDATE;";
            $sender_wallet = $conn->query("SELECT balance FROM wallet WHERE user_id = $from_id FOR UPDATE")->fetch_assoc();
            
            if (!$sender_wallet) {
                throw new Exception("Sender wallet not found.");
            }
            
            $sender_bal = floatval($sender_wallet['balance']);
            $logs[] = "[CHECK] Sender balance is: {$sender_bal} TC. Request: {$amount} TC.";
            
            if ($sender_bal < $amount) {
                throw new Exception("Insufficient funds! Sender only has {$sender_bal} TC.");
            }
            
            // Deduct
            $logs[] = "[SQL] UPDATE wallet SET balance = balance - $amount WHERE user_id = $from_id;";
            if (!$conn->query("UPDATE wallet SET balance = balance - $amount WHERE user_id = $from_id")) {
                throw new Exception("Failed to deduct credits: " . $conn->error);
            }
            
            // Add
            $logs[] = "[SQL] UPDATE wallet SET balance = balance + $amount WHERE user_id = $to_id;";
            if (!$conn->query("UPDATE wallet SET balance = balance + $amount WHERE user_id = $to_id")) {
                throw new Exception("Failed to credit receiver: " . $conn->error);
            }
            
            // Log Transaction
            $note = $conn->real_escape_string("Simulated P2P ACID transfer");
            $logs[] = "[SQL] INSERT INTO transactions (from_user_id, to_user_id, type, base_amount, final_amount, note) VALUES ($from_id, $to_id, 'session', $amount, $amount, '$note');";
            if (!$conn->query("INSERT INTO transactions (from_user_id, to_user_id, type, base_amount, final_amount, note) VALUES ($from_id, $to_id, 'session', $amount, $amount, '$note')")) {
                throw new Exception("Failed to log transaction history: " . $conn->error);
            }
            
        } elseif ($action === 'loan') {
            $user_id = intval($_POST['loan_user_id'] ?? 0);
            $amount = floatval($_POST['loan_amount'] ?? 0);
            
            if ($user_id <= 0) {
                throw new Exception("Invalid borrower selection.");
            }
            if ($amount <= 0 || $amount > 15) {
                throw new Exception("Loan amount must be between 1 and 15 TC.");
            }
            
            // Check active loans
            $logs[] = "[SQL] SELECT COUNT(*) as active_loans FROM loans WHERE user_id = $user_id AND status = 'active';";
            $active_loans = $conn->query("SELECT COUNT(*) as active_loans FROM loans WHERE user_id = $user_id AND status = 'active'")->fetch_assoc()['active_loans'];
            
            if ($active_loans > 0) {
                throw new Exception("Borrower already has an active loan. Limit is 1 active loan.");
            }
            
            // Insert loan record
            $due_date = date('Y-m-d H:i:s', strtotime('+7 days'));
            $logs[] = "[SQL] INSERT INTO loans (user_id, amount, due_date, status) VALUES ($user_id, $amount, '$due_date', 'active');";
            if (!$conn->query("INSERT INTO loans (user_id, amount, due_date, status) VALUES ($user_id, $amount, '$due_date', 'active')")) {
                throw new Exception("Failed to insert loan record: " . $conn->error);
            }
            $loan_id = $conn->insert_id;
            
            // Add credits to borrower
            $logs[] = "[SQL] UPDATE wallet SET balance = balance + $amount WHERE user_id = $user_id;";
            if (!$conn->query("UPDATE wallet SET balance = balance + $amount WHERE user_id = $user_id")) {
                throw new Exception("Failed to add loan credits: " . $conn->error);
            }
            
            // Log loan audit (Triggers also run here, but let's record the simulator step)
            $logs[] = "[SQL_TRIGGER] trg_after_loan_status_change fired automatically on loans update.";
        }
        
        // Failure injection point
        if ($inject_failure) {
            $logs[] = "[FAILURE_INJECTION] Force failure checkbox detected! Simulating execution timeout/network interruption...";
            throw new Exception("Forced Simulating Transaction Failure (ACID Rollback Demonstration)");
        }
        
        // Commit
        $conn->commit();
        $logs[] = "[SQL] COMMIT;";
        $simulation_result = [
            'status' => 'committed',
            'message' => 'Transaction Committed Successfully! All database updates persist.',
            'action' => $action
        ];
        
    } catch (Exception $e) {
        // Rollback
        $conn->rollback();
        $logs[] = "[SQL] ROLLBACK;";
        $logs[] = "[ROLLBACK] Reverted all pending changes. Database state completely restored.";
        $simulation_result = [
            'status' => 'rolled_back',
            'message' => 'Transaction Rolled Back! Error: ' . $e->getMessage(),
            'action' => $action
        ];
    }
    
    // Refresh user list to capture updated balances
    $res = $conn->query($users_query);
    $users_list = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $users_list[$row['user_id']] = $row;
        }
    }
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="grid-2">
    <!-- Left panel: Control center & Forms -->
    <div>
        <!-- P2P Transfer Simulator Card -->
        <div class="card mb-3">
            <div class="card-header">
                <h3><i data-lucide="zap" class="lucide-sm"></i> Peer-to-Peer Transfer Simulator</h3>
            </div>
            <div style="padding: 20px;">
                <form method="POST">
                    <input type="hidden" name="simulate_action" value="transfer">
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: bold; margin-bottom: 5px;">Sender (From)</label>
                        <select name="from_user_id" required style="width: 100%; padding: 8px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-secondary); color: var(--text-primary);">
                            <option value="">-- Select Sender --</option>
                            <?php foreach ($users_list as $u): ?>
                                <option value="<?php echo $u['user_id']; ?>">
                                    <?php echo htmlspecialchars($u['name']); ?> (Bal: <?php echo number_format($u['balance'], 2); ?> TC)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: bold; margin-bottom: 5px;">Receiver (To)</label>
                        <select name="to_user_id" required style="width: 100%; padding: 8px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-secondary); color: var(--text-primary);">
                            <option value="">-- Select Receiver --</option>
                            <?php foreach ($users_list as $u): ?>
                                <option value="<?php echo $u['user_id']; ?>">
                                    <?php echo htmlspecialchars($u['name']); ?> (Bal: <?php echo number_format($u['balance'], 2); ?> TC)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: bold; margin-bottom: 5px;">Credits to Transfer (TC)</label>
                        <input type="number" name="amount" min="0.1" max="100" step="0.1" value="5.0" required style="width: 100%; padding: 8px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-secondary); color: var(--text-primary);">
                    </div>

                    <!-- Failure Injection Toggle -->
                    <div style="margin-bottom: 20px; background: #fff5f5; border: 1px solid #ffd1d1; padding: 12px; border-radius: var(--radius-sm); display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" name="inject_failure" value="1" id="fail_transfer" style="width: 18px; height: 18px; cursor: pointer;">
                        <label for="fail_transfer" style="color: var(--danger); font-weight: 600; font-size: 0.85rem; cursor: pointer; user-select: none;">
                            <i data-lucide="siren" class="lucide-sm"></i> Inject failure midway (Force ROLLBACK)
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">Execute ACID Transfer</button>
                </form>
            </div>
        </div>

        <!-- Platform Loan Simulator Card -->
        <div class="card">
            <div class="card-header">
                <h3><i data-lucide="banknote" class="lucide-sm"></i> Platform Loan Request Simulator</h3>
            </div>
            <div style="padding: 20px;">
                <form method="POST">
                    <input type="hidden" name="simulate_action" value="loan">
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: bold; margin-bottom: 5px;">Borrower (User)</label>
                        <select name="loan_user_id" required style="width: 100%; padding: 8px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-secondary); color: var(--text-primary);">
                            <option value="">-- Select Borrower --</option>
                            <?php foreach ($users_list as $u): ?>
                                <option value="<?php echo $u['user_id']; ?>">
                                    <?php echo htmlspecialchars($u['name']); ?> (Rel Score: <?php echo number_format($u['reliability_score'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: bold; margin-bottom: 5px;">Loan Amount (Max 15 TC)</label>
                        <input type="number" name="loan_amount" min="1" max="15" step="1" value="10" required style="width: 100%; padding: 8px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-secondary); color: var(--text-primary);">
                    </div>

                    <!-- Failure Injection Toggle -->
                    <div style="margin-bottom: 20px; background: #fff5f5; border: 1px solid #ffd1d1; padding: 12px; border-radius: var(--radius-sm); display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" name="inject_failure" value="1" id="fail_loan" style="width: 18px; height: 18px; cursor: pointer;">
                        <label for="fail_loan" style="color: var(--danger); font-weight: 600; font-size: 0.85rem; cursor: pointer; user-select: none;">
                            <i data-lucide="siren" class="lucide-sm"></i> Inject failure midway (Force ROLLBACK)
                        </label>
                    </div>

                    <button type="submit" class="btn btn-info" style="width: 100%;">Execute ACID Loan</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right panel: Monitor Console & Simulation Logs -->
    <div>
        <!-- Live Console Monitor -->
        <div class="card mb-3" style="background: #111c2c; border: 1px solid #1a2c42; color: #a4b8d1;">
            <div class="card-header" style="border-bottom: 1px solid #1a2c42; background: #0c1624;">
                <h3 style="color: #ffffff; margin: 0; display: flex; align-items: center; gap: 10px;">
                    <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #00ff66; box-shadow: 0 0 8px #00ff66; animation: pulse 2s infinite;"></span>
                    Simulation Log Console
                </h3>
            </div>
            
            <div style="padding: 20px; font-family: monospace; font-size: 0.82rem; height: 350px; overflow-y: auto;">
                <?php if (count($logs) > 0): ?>
                    <?php foreach ($logs as $log): 
                        $color = '#a4b8d1';
                        if (strpos($log, '[SQL]') !== false) $color = '#ffd866';
                        elseif (strpos($log, '[CHECK]') !== false) $color = '#78dce8';
                        elseif (strpos($log, '[FAILURE_INJECTION]') !== false) $color = '#ff6188';
                        elseif (strpos($log, '[ROLLBACK]') !== false) $color = '#ff6188';
                        elseif (strpos($log, 'COMMIT') !== false) $color = '#a9dc76';
                    ?>
                        <div style="margin-bottom: 6px; color: <?php echo $color; ?>; line-height: 1.4;">
                            <?php echo htmlspecialchars($log); ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="color: #637791; text-align: center; margin-top: 100px;">
                        Waiting for action to execute...<br>
                        Select parameters on the left and submit to view SQL sequence.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Before & After Comparison -->
        <?php if ($simulation_result): ?>
            <?php 
                $outcome_bg = $simulation_result['status'] === 'committed' ? '#eefcf3' : '#fff5f5';
                $outcome_border = $simulation_result['status'] === 'committed' ? '#c3f2d2' : '#ffd1d1';
                $outcome_text = $simulation_result['status'] === 'committed' ? 'var(--success)' : 'var(--danger)';
            ?>
            <div class="card" style="background: <?php echo $outcome_bg; ?>; border: 1px solid <?php echo $outcome_border; ?>;">
                <div style="padding: 20px;">
                    <h3 style="color: <?php echo $outcome_text; ?>; margin-bottom: 8px;">
                        <?php echo $simulation_result['status'] === 'committed' ? '<i data-lucide="check-circle" class="lucide-sm"></i> SUCCESS: COMMITTED' : '<i data-lucide="siren" class="lucide-sm"></i> REVERTED: ROLLED BACK'; ?>
                    </h3>
                    <p style="color: var(--text-secondary); font-size: 0.88rem; font-weight: 500;"><?php echo htmlspecialchars($simulation_result['message']); ?></p>
                    
                    <h4 style="margin-top: 20px; font-size: 0.85rem; text-transform: uppercase; color: var(--text-muted); border-bottom: 1px solid <?php echo $outcome_border; ?>; padding-bottom: 6px;">State Inspection Matrix</h4>
                    
                    <div style="margin-top: 10px; display: flex; flex-direction: column; gap: 8px;">
                        <?php foreach ($users_list as $uid => $u): ?>
                            <?php 
                                $pre = $pre_balances[$uid] ?? 0;
                                $post = $u['balance'];
                                $diff = $post - $pre;
                                $changed = abs($diff) > 0.001;
                            ?>
                            <?php if ($changed || $uid == ($from_id ?? 0) || $uid == ($to_id ?? 0) || $uid == ($user_id ?? 0)): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; padding: 6px 10px; background: rgba(255,255,255,0.6); border-radius: var(--radius-sm);">
                                    <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($u['name']); ?></strong>
                                    <div style="font-family: monospace;">
                                        <span>Before: <?php echo number_format($pre, 2); ?> TC</span>
                                        <span style="margin: 0 10px; color: var(--text-muted);">&rarr;</span>
                                        <span style="font-weight: bold; color: <?php echo $changed ? ($diff > 0 ? 'var(--success)' : 'var(--danger)') : 'var(--text-secondary)'; ?>">
                                            After: <?php echo number_format($post, 2); ?> TC
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
@keyframes pulse {
    0% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.2); opacity: 0.6; }
    100% { transform: scale(1); opacity: 1; }
}
</style>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
