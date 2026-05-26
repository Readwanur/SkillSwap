<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'Stress Test & Index Profiler';

$message = '';
$message_type = '';

// Get stats
$total_txns = $conn->query("SELECT COUNT(*) AS cnt FROM transactions")->fetch_assoc()['cnt'];
$seeded_txns = $conn->query("SELECT COUNT(*) AS cnt FROM transactions WHERE note = 'SEEDED_STRESS_TEST'")->fetch_assoc()['cnt'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_seed'])) {
        $count = intval($_POST['seed_count'] ?? 10000);
        if ($count < 1000 || $count > 60000) {
            $count = 10000;
        }

        // Fetch users to assign random transactions
        $users_res = $conn->query("SELECT user_id FROM users");
        $user_ids = [];
        if ($users_res) {
            while ($row = $users_res->fetch_assoc()) {
                $user_ids[] = intval($row['user_id']);
            }
        }

        if (count($user_ids) < 2) {
            $message = "Error: Please register at least 2 users in the system before seeding transactions to satisfy FK constraints.";
            $message_type = "danger";
        } else {
            // Seed transactions in batches of 5,000 to keep it extremely fast
            $batch_size = 5000;
            $batches = ceil($count / $batch_size);
            $inserted = 0;
            
            // Disable autocommit for speed
            $conn->autocommit(false);
            
            $types = ['session', 'gift', 'community_reward'];
            
            try {
                for ($b = 0; $b < $batches; $b++) {
                    $current_batch_size = min($batch_size, $count - $inserted);
                    $values = [];
                    
                    for ($i = 0; $i < $current_batch_size; $i++) {
                        $from_user = $user_ids[array_rand($user_ids)];
                        $to_user = $user_ids[array_rand($user_ids)];
                        while ($from_user === $to_user) {
                            $to_user = $user_ids[array_rand($user_ids)];
                        }
                        
                        $type = $types[array_rand($types)];
                        $amount = rand(5, 50);
                        
                        // Distribute timestamps over the last 3 years to make the index partition effective
                        $days_ago = rand(1, 1000);
                        $timestamp = date('Y-m-d H:i:s', strtotime("-$days_ago days"));
                        
                        $values[] = "(NULL, $from_user, $to_user, '$type', $amount, $amount, 'SEEDED_STRESS_TEST', '$timestamp')";
                    }
                    
                    $sql = "INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount, note, timestamp) VALUES " . implode(',', $values);
                    if (!$conn->query($sql)) {
                        throw new Exception($conn->error);
                    }
                    $inserted += $current_batch_size;
                }
                
                $conn->commit();
                $conn->autocommit(true);
                
                $message = "Successfully seeded " . number_format($inserted) . " stress test transactions in milliseconds!";
                $message_type = "success";
                
                // Refresh counts
                $total_txns = $conn->query("SELECT COUNT(*) AS cnt FROM transactions")->fetch_assoc()['cnt'];
                $seeded_txns = $conn->query("SELECT COUNT(*) AS cnt FROM transactions WHERE note = 'SEEDED_STRESS_TEST'")->fetch_assoc()['cnt'];
            } catch (Exception $e) {
                $conn->rollback();
                $conn->autocommit(true);
                $message = "Error seeding transactions: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    } elseif (isset($_POST['action_truncate'])) {
        if ($conn->query("DELETE FROM transactions WHERE note = 'SEEDED_STRESS_TEST'")) {
            $message = "All seeded stress test records have been truncated.";
            $message_type = "success";
            
            // Refresh counts
            $total_txns = $conn->query("SELECT COUNT(*) AS cnt FROM transactions")->fetch_assoc()['cnt'];
            $seeded_txns = $conn->query("SELECT COUNT(*) AS cnt FROM transactions WHERE note = 'SEEDED_STRESS_TEST'")->fetch_assoc()['cnt'];
        } else {
            $message = "Error truncating transactions: " . $conn->error;
            $message_type = "danger";
        }
    }
}

// Benchmarking section
$benchmark_results = null;
if (isset($_GET['benchmark'])) {
    // Generate dates that span the middle part of the timestamp distribution
    $start_date = date('Y-m-d H:i:s', strtotime('-700 days'));
    $end_date = date('Y-m-d H:i:s', strtotime('-100 days'));
    
    // Benchmark 1: WITH INDEX
    $time_start = microtime(true);
    $q_indexed = "
        SELECT type, SUM(final_amount) as total_credits, COUNT(*) as txn_count
        FROM transactions
        WHERE timestamp BETWEEN '$start_date' AND '$end_date'
        GROUP BY type
    ";
    $res_indexed = $conn->query($q_indexed);
    if ($res_indexed) {
        $res_indexed->fetch_all(MYSQLI_ASSOC);
    }
    $time_indexed = microtime(true) - $time_start;

    // Get EXPLAIN plan for indexed query
    $explain_indexed = $conn->query("EXPLAIN $q_indexed")->fetch_all(MYSQLI_ASSOC);

    // Benchmark 2: WITHOUT INDEX (IGNORE INDEX)
    $time_start = microtime(true);
    $q_unindexed = "
        SELECT type, SUM(final_amount) as total_credits, COUNT(*) as txn_count
        FROM transactions IGNORE INDEX (idx_txn_timestamp)
        WHERE timestamp BETWEEN '$start_date' AND '$end_date'
        GROUP BY type
    ";
    $res_unindexed = $conn->query($q_unindexed);
    if ($res_unindexed) {
        $res_unindexed->fetch_all(MYSQLI_ASSOC);
    }
    $time_unindexed = microtime(true) - $time_start;

    // Get EXPLAIN plan for unindexed query
    $explain_unindexed = $conn->query("EXPLAIN $q_unindexed")->fetch_all(MYSQLI_ASSOC);

    $speedup = $time_indexed > 0 ? ($time_unindexed / $time_indexed) : 0;
    
    $benchmark_results = [
        'time_indexed' => $time_indexed,
        'time_unindexed' => $time_unindexed,
        'speedup' => $speedup,
        'explain_indexed' => $explain_indexed,
        'explain_unindexed' => $explain_unindexed,
        'start_date' => $start_date,
        'end_date' => $end_date
    ];
}

include __DIR__ . '/../includes/admin_header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?> mb-3">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Top statistics cards -->
<div class="stats-grid mb-3">
    <div class="stat-card stat-card-accent" style="--accent: var(--primary);">
        <div class="stat-icon">📊</div>
        <span class="stat-value"><?php echo number_format($total_txns); ?></span>
        <span class="stat-label">Total Transactions</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--info);">
        <div class="stat-icon">🧪</div>
        <span class="stat-value"><?php echo number_format($seeded_txns); ?></span>
        <span class="stat-label">Seeded Stress Records</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--warning);">
        <div class="stat-icon">⚡</div>
        <span class="stat-value"><?php echo ($total_txns > 10000) ? 'Ready' : 'Low Density'; ?></span>
        <span class="stat-label">Stress Level Status</span>
    </div>
</div>

<div class="grid-2">
    <!-- Left panel: Control Center for Seeding & Truncating -->
    <div>
        <div class="card mb-3">
            <div class="card-header">
                <h3>🚀 Stress Seed Engine</h3>
            </div>
            <div style="padding: 20px;">
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 15px;">
                    Simulate production workload by seeding tens of thousands of mock transactions. Generates a balanced distribution of P2P transfers, gifts, and community task rewards with timestamps spanning 3 years.
                </p>
                
                <form method="POST">
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; font-size: 0.85rem; font-weight: bold; margin-bottom: 5px;">Record Density</label>
                        <select name="seed_count" style="width: 100%; padding: 8px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-secondary); color: var(--text-primary);">
                            <option value="10000">10,000 Records (Fast)</option>
                            <option value="25000">25,000 Records (Standard)</option>
                            <option value="50000" selected>50,000 Records (High Density)</option>
                        </select>
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="submit" name="action_seed" class="btn btn-primary" style="flex: 2;">Seed Records</button>
                        <?php if ($seeded_txns > 0): ?>
                            <button type="submit" name="action_truncate" class="btn btn-danger" style="flex: 1; display: flex; align-items: center; justify-content: center;" onclick="return confirm('Truncate all mock seeded transactions?')">Truncate</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>⚡ B-Tree Index Profiler Benchmark</h3>
            </div>
            <div style="padding: 20px;">
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 15px;">
                    Benchmark query execution performance over the database. Contrasts index scan (`idx_txn_timestamp`) against a forced table scan (`IGNORE INDEX`) to visualize database optimization.
                </p>
                
                <?php if ($total_txns < 5000): ?>
                    <div class="alert alert-warning" style="font-size: 0.8rem; padding: 10px;">
                        ⚠️ Warning: Low record density (less than 5,000 total records). Speed differences might be negligible. Please seed 50,000 records first for high fidelity benchmark profiling.
                    </div>
                <?php endif; ?>
                
                <a href="?benchmark=1" class="btn btn-info" style="display: block; text-align: center; text-decoration: none;">Run Optimizer Profiler</a>
            </div>
        </div>
    </div>

    <!-- Right panel: Benchmark Results & EXPLAIN visualizer -->
    <div>
        <?php if ($benchmark_results): ?>
            <!-- Performance metrics -->
            <div class="card mb-3" style="background: var(--bg-secondary); border: 1px solid var(--border-light);">
                <div class="card-header">
                    <h3>📈 Profiler Performance Metrics</h3>
                </div>
                <div style="padding: 20px;">
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <!-- Indexed speed -->
                        <div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 5px;">
                                <strong>B-Tree Indexed Scan:</strong>
                                <span style="color: var(--success); font-weight: bold; font-family: monospace;"><?php echo number_format($benchmark_results['time_indexed'] * 1000, 2); ?> ms</span>
                            </div>
                            <div style="background: var(--bg-primary); height: 8px; border-radius: 4px; overflow: hidden;">
                                <div style="background: var(--success); height: 100%; width: <?php echo max(2, min(100, ($benchmark_results['time_indexed'] / max($benchmark_results['time_unindexed'], 0.0001)) * 100)); ?>%;"></div>
                            </div>
                        </div>

                        <!-- Unindexed speed -->
                        <div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 5px;">
                                <strong>Forced Full Table Scan (No Index):</strong>
                                <span style="color: var(--danger); font-weight: bold; font-family: monospace;"><?php echo number_format($benchmark_results['time_unindexed'] * 1000, 2); ?> ms</span>
                            </div>
                            <div style="background: var(--bg-primary); height: 8px; border-radius: 4px; overflow: hidden;">
                                <div style="background: var(--danger); height: 100%; width: 100%;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Speedup Factor Banner -->
                    <div style="margin-top: 20px; text-align: center; padding: 15px; border-radius: var(--radius-md); background: var(--primary-glow); border: 1px solid var(--border-light);">
                        <span style="font-size: 0.85rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase;">Optimization Factor</span>
                        <h2 style="color: var(--primary); font-size: 2rem; font-weight: 800; margin: 5px 0;">
                            <?php echo number_format($benchmark_results['speedup'], 1); ?>x Speedup
                        </h2>
                        <p style="margin: 0; font-size: 0.8rem; color: var(--text-muted);">
                            Using index condition pushdown over a dataset of <?php echo number_format($total_txns); ?> records.
                        </p>
                    </div>
                </div>
            </div>

            <!-- EXPLAIN Visualizer -->
            <div class="card">
                <div class="card-header">
                    <h3>🔍 Execution Plan Explainer (EXPLAIN)</h3>
                </div>
                <div style="padding: 15px; overflow-x: auto;">
                    <h4 style="font-size: 0.85rem; color: var(--success); margin-bottom: 8px; border-bottom: 1px solid var(--border-light); padding-bottom: 4px;">Indexed Query Execution Details</h4>
                    <table style="font-size: 0.75rem; width: 100%; border-collapse: collapse; margin-bottom: 20px; min-width: 400px;">
                        <thead>
                            <tr style="background: var(--bg-primary);">
                                <th style="padding: 6px;">select_type</th>
                                <th style="padding: 6px;">type</th>
                                <th style="padding: 6px;">possible_keys</th>
                                <th style="padding: 6px;">key</th>
                                <th style="padding: 6px;">rows</th>
                                <th style="padding: 6px;">Extra</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($benchmark_results['explain_indexed'] as $row): ?>
                                <tr style="border-bottom: 1px solid var(--border-light);">
                                    <td style="padding: 6px; font-family: monospace;"><?php echo htmlspecialchars($row['select_type'] ?? 'SIMPLE'); ?></td>
                                    <td style="padding: 6px; font-family: monospace; font-weight: bold; color: var(--success);"><?php echo htmlspecialchars($row['type'] ?? 'ALL'); ?></td>
                                    <td style="padding: 6px; font-family: monospace;"><?php echo htmlspecialchars($row['possible_keys'] ?? 'NULL'); ?></td>
                                    <td style="padding: 6px; font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($row['key'] ?? 'NULL'); ?></td>
                                    <td style="padding: 6px; font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($row['rows'] ?? 'N/A'); ?></td>
                                    <td style="padding: 6px; font-family: monospace; color: var(--text-secondary);"><?php echo htmlspecialchars($row['Extra'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h4 style="font-size: 0.85rem; color: var(--danger); margin-bottom: 8px; border-bottom: 1px solid var(--border-light); padding-bottom: 4px;">Table Scan (Ignored Index) Execution Details</h4>
                    <table style="font-size: 0.75rem; width: 100%; border-collapse: collapse; min-width: 400px;">
                        <thead>
                            <tr style="background: var(--bg-primary);">
                                <th style="padding: 6px;">select_type</th>
                                <th style="padding: 6px;">type</th>
                                <th style="padding: 6px;">possible_keys</th>
                                <th style="padding: 6px;">key</th>
                                <th style="padding: 6px;">rows</th>
                                <th style="padding: 6px;">Extra</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($benchmark_results['explain_unindexed'] as $row): ?>
                                <tr style="border-bottom: 1px solid var(--border-light);">
                                    <td style="padding: 6px; font-family: monospace;"><?php echo htmlspecialchars($row['select_type'] ?? 'SIMPLE'); ?></td>
                                    <td style="padding: 6px; font-family: monospace; font-weight: bold; color: var(--danger);"><?php echo htmlspecialchars($row['type'] ?? 'ALL'); ?></td>
                                    <td style="padding: 6px; font-family: monospace; color: var(--text-muted);"><?php echo htmlspecialchars($row['possible_keys'] ?? 'NULL'); ?></td>
                                    <td style="padding: 6px; font-family: monospace; color: var(--text-muted); font-weight: bold;"><?php echo htmlspecialchars($row['key'] ?? 'NULL'); ?></td>
                                    <td style="padding: 6px; font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($row['rows'] ?? 'N/A'); ?></td>
                                    <td style="padding: 6px; font-family: monospace; color: var(--text-secondary);"><?php echo htmlspecialchars($row['Extra'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div style="padding: 40px; text-align: center; color: var(--text-muted); border: 1px dashed var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary);">
                <div style="font-size: 2.5rem; margin-bottom: 10px;">📉</div>
                <p>Run the Index Profiler to benchmark query optimization characteristics.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
