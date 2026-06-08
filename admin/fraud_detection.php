<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'Fraud Detection';

// ============================================================
// FEATURE 5: AUTOMATED WASH TRADING & FRAUD DETECTION
// ============================================================

// --- CQ: Circular Ring Detection (A→B→C→A within 72 hours) ---
// Triple self-JOIN on transactions table to find transaction cycles
// where User A pays B, B pays C, and C pays A within a 72-hour window.
// This is the classic "wash trading" pattern.
$circular_rings = $conn->query("
    SELECT 
        t1.from_user_id AS user_a_id,
        t1.to_user_id AS user_b_id,
        t2.to_user_id AS user_c_id,
        u1.name AS name_a,
        u2.name AS name_b,
        u3.name AS name_c,
        ROUND(t1.final_amount, 2) AS amt_ab,
        ROUND(t2.final_amount, 2) AS amt_bc,
        ROUND(t3.final_amount, 2) AS amt_ca,
        t1.timestamp AS time_ab,
        t2.timestamp AS time_bc,
        t3.timestamp AS time_ca,
        TIMESTAMPDIFF(HOUR, t1.timestamp, t3.timestamp) AS ring_hours
    FROM transactions t1
    JOIN transactions t2 
        ON t1.to_user_id = t2.from_user_id 
        AND t2.type = 'credit_transfer'
    JOIN transactions t3 
        ON t2.to_user_id = t3.from_user_id 
        AND t3.to_user_id = t1.from_user_id
        AND t3.type = 'credit_transfer'
    JOIN users u1 ON t1.from_user_id = u1.user_id
    JOIN users u2 ON t1.to_user_id = u2.user_id
    JOIN users u3 ON t2.to_user_id = u3.user_id
    WHERE t1.type = 'credit_transfer'
      AND t1.from_user_id != t1.to_user_id
      AND t2.from_user_id != t2.to_user_id
      AND t3.from_user_id != t3.to_user_id
      AND t2.timestamp BETWEEN t1.timestamp AND DATE_ADD(t1.timestamp, INTERVAL 72 HOUR)
      AND t3.timestamp BETWEEN t1.timestamp AND DATE_ADD(t1.timestamp, INTERVAL 72 HOUR)
      AND t1.from_user_id < t2.to_user_id
    ORDER BY t1.timestamp DESC
    LIMIT 20
");

// --- CQ: Suspicious Velocity (>3 completed sessions by same provider in one day) ---
// Uses GROUP BY with HAVING to detect abnormally high session completion rates
$suspicious_velocity = $conn->query("
    SELECT 
        u.user_id,
        u.name,
        DATE(es.completion_time) AS day,
        COUNT(*) AS sessions_in_day,
        SUM(es.time_credit_transfer) AS total_credits_earned,
        GROUP_CONCAT(DISTINCT u_req.name ORDER BY u_req.name SEPARATOR ', ') AS requester_names
    FROM exchange_sessions es
    JOIN users u ON es.provider_id = u.user_id
    JOIN users u_req ON es.requester_id = u_req.user_id
    WHERE es.status = 'completed' AND es.completion_time IS NOT NULL
    GROUP BY es.provider_id, DATE(es.completion_time)
    HAVING sessions_in_day > 3
    ORDER BY sessions_in_day DESC, day DESC
    LIMIT 20
");

// --- CQ: Rating Anomaly (100% 5-star with 3+ rated sessions) ---
// Uses aggregate functions with HAVING to find statistically improbable ratings
$rating_anomaly = $conn->query("
    SELECT 
        u.user_id,
        u.name,
        COUNT(*) AS rated_sessions,
        ROUND(AVG(es.rating), 2) AS avg_rating,
        MIN(es.rating) AS min_rating,
        MAX(es.rating) AS max_rating,
        ROUND(STDDEV(es.rating), 2) AS rating_stddev,
        GROUP_CONCAT(DISTINCT u_req.name ORDER BY u_req.name SEPARATOR ', ') AS raters
    FROM exchange_sessions es
    JOIN users u ON es.provider_id = u.user_id
    JOIN users u_req ON es.requester_id = u_req.user_id
    WHERE es.rating IS NOT NULL AND es.status = 'completed'
    GROUP BY es.provider_id
    HAVING rated_sessions >= 3 AND min_rating = 5 AND max_rating = 5
    ORDER BY rated_sessions DESC
    LIMIT 20
");

// --- CQ: Mutual Rapid Trading (Same two users trading back and forth) ---
// Self-join to find pairs of users who frequently trade with each other
$mutual_trading = $conn->query("
    SELECT 
        LEAST(es1.requester_id, es1.provider_id) AS user_a_id,
        GREATEST(es1.requester_id, es1.provider_id) AS user_b_id,
        ua.name AS name_a,
        ub.name AS name_b,
        COUNT(*) AS session_count,
        SUM(es1.time_credit_transfer) AS total_credits,
        MIN(es1.scheduled_time) AS first_session,
        MAX(es1.scheduled_time) AS last_session,
        DATEDIFF(MAX(es1.scheduled_time), MIN(es1.scheduled_time)) AS span_days
    FROM exchange_sessions es1
    JOIN users ua ON LEAST(es1.requester_id, es1.provider_id) = ua.user_id
    JOIN users ub ON GREATEST(es1.requester_id, es1.provider_id) = ub.user_id
    WHERE es1.status = 'completed'
    GROUP BY LEAST(es1.requester_id, es1.provider_id), GREATEST(es1.requester_id, es1.provider_id)
    HAVING session_count >= 4
    ORDER BY session_count DESC
    LIMIT 20
");

// Summary stats
$total_flagged_rings = ($circular_rings && $circular_rings->num_rows > 0) ? $circular_rings->num_rows : 0;
$total_velocity_flags = ($suspicious_velocity && $suspicious_velocity->num_rows > 0) ? $suspicious_velocity->num_rows : 0;
$total_rating_flags = ($rating_anomaly && $rating_anomaly->num_rows > 0) ? $rating_anomaly->num_rows : 0;
$total_mutual_flags = ($mutual_trading && $mutual_trading->num_rows > 0) ? $mutual_trading->num_rows : 0;

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="admin-welcome-banner" style="background: linear-gradient(135deg, #b71c1c 0%, #d32f2f 100%);">
    <div>
        <h1 class="page-title" style="margin:0; color:#fff; border-bottom-color:rgba(255,255,255,0.3);"><i data-lucide="shield" class="lucide-sm"></i> Fraud Detection Engine</h1>
        <p style="color:rgba(255,255,255,0.85); margin-top:6px;">Automated wash trading & suspicious activity detection via complex SQL pattern matching</p>
    </div>
    <div class="admin-quick-actions">
        <span class="btn" style="cursor:default;">
            <?php echo ($total_flagged_rings + $total_velocity_flags + $total_rating_flags + $total_mutual_flags); ?> Total Alerts
        </span>
    </div>
</div>

<!-- Alert Summary -->
<div class="stats-grid mb-3">
    <div class="stat-card stat-card-accent" style="--accent: var(--danger);">
        <span class="stat-icon"><i data-lucide="refresh-cw" class="lucide-sm"></i></span>
        <span class="stat-value" style="color:var(--danger);"><?php echo $total_flagged_rings; ?></span>
        <span class="stat-label">Circular Rings</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--warning);">
        <span class="stat-icon"><i data-lucide="zap" class="lucide-sm"></i></span>
        <span class="stat-value" style="color:var(--warning);"><?php echo $total_velocity_flags; ?></span>
        <span class="stat-label">Velocity Alerts</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--info);">
        <span class="stat-icon"><i data-lucide="star" class="lucide-sm"></i></span>
        <span class="stat-value" style="color:var(--info);"><?php echo $total_rating_flags; ?></span>
        <span class="stat-label">Rating Anomalies</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: #6a1b9a;">
        <span class="stat-icon"><i data-lucide="handshake" class="lucide-sm"></i></span>
        <span class="stat-value" style="color:#6a1b9a;"><?php echo $total_mutual_flags; ?></span>
        <span class="stat-label">Mutual Trading</span>
    </div>
</div>

<!-- Circular Ring Detection -->
<div class="card mb-3" style="border-left:4px solid var(--danger);">
    <div class="card-header">
        <h3><i data-lucide="refresh-cw" class="lucide-sm"></i> Circular Transaction Rings (A→B→C→A)</h3>
        </div>
    <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:12px;">
        Detects transaction cycles where User A pays B, B pays C, and C pays A within 72 hours — a classic wash-trading pattern to farm credits or inflate reputation.
    </p>
    <?php if ($circular_rings && $circular_rings->num_rows > 0): ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Ring</th>
                        <th>A → B</th>
                        <th>B → C</th>
                        <th>C → A</th>
                        <th>Window</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($r = $circular_rings->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong style="color:var(--danger);">
                                    <?php echo htmlspecialchars($r['name_a']); ?> → 
                                    <?php echo htmlspecialchars($r['name_b']); ?> → 
                                    <?php echo htmlspecialchars($r['name_c']); ?> ↺
                                </strong>
                            </td>
                            <td>
                                <span style="font-weight:600;"><?php echo $r['amt_ab']; ?> TC</span>
                                <br><small style="color:var(--text-muted);"><?php echo date('M d H:i', strtotime($r['time_ab'])); ?></small>
                            </td>
                            <td>
                                <span style="font-weight:600;"><?php echo $r['amt_bc']; ?> TC</span>
                                <br><small style="color:var(--text-muted);"><?php echo date('M d H:i', strtotime($r['time_bc'])); ?></small>
                            </td>
                            <td>
                                <span style="font-weight:600;"><?php echo $r['amt_ca']; ?> TC</span>
                                <br><small style="color:var(--text-muted);"><?php echo date('M d H:i', strtotime($r['time_ca'])); ?></small>
                            </td>
                            <td><span class="badge badge-danger"><?php echo $r['ring_hours']; ?>h</span></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state" style="padding:20px;">
            <p style="color:var(--success);"><i data-lucide="check-circle" class="lucide-sm"></i> No circular transaction rings detected. Platform integrity is intact.</p>
        </div>
    <?php endif; ?>
</div>

<div class="grid-2 mb-3">
    <!-- Suspicious Velocity -->
    <div class="card" style="border-left:4px solid var(--warning);">
        <div class="card-header">
            <h3><i data-lucide="zap" class="lucide-sm"></i> Velocity Alerts</h3>
            </div>
        <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:12px;">
            Providers who completed >3 sessions in a single day.
        </p>
        <?php if ($suspicious_velocity && $suspicious_velocity->num_rows > 0): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th>Date</th>
                            <th>Sessions</th>
                            <th>TC Earned</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($sv = $suspicious_velocity->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($sv['name']); ?></strong>
                                    <br><small style="color:var(--text-muted);">with: <?php echo htmlspecialchars($sv['requester_names']); ?></small>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($sv['day'])); ?></td>
                                <td style="font-weight:700; color:var(--warning);"><?php echo $sv['sessions_in_day']; ?></td>
                                <td style="font-weight:600;"><?php echo number_format($sv['total_credits_earned'], 2); ?> TC</td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state" style="padding:20px;">
                <p style="color:var(--success);"><i data-lucide="check-circle" class="lucide-sm"></i> No velocity anomalies detected.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Rating Anomaly -->
    <div class="card" style="border-left:4px solid var(--info);">
        <div class="card-header">
            <h3><i data-lucide="star" class="lucide-sm"></i> Rating Anomalies</h3>
            </div>
        <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:12px;">
            Providers with 100% 5-star ratings from 3+ sessions — statistically improbable.
        </p>
        <?php if ($rating_anomaly && $rating_anomaly->num_rows > 0): ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th>Sessions</th>
                            <th>Rating</th>
                            <th>Raters</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($ra = $rating_anomaly->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($ra['name']); ?></strong></td>
                                <td style="font-weight:600;"><?php echo $ra['rated_sessions']; ?></td>
                                <td style="font-weight:700; color:var(--warning);"><i data-lucide="star" class="lucide-sm"></i> <?php echo $ra['avg_rating']; ?> (all 5★)</td>
                                <td style="font-size:0.8rem; color:var(--text-muted);"><?php echo htmlspecialchars($ra['raters']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state" style="padding:20px;">
                <p style="color:var(--success);"><i data-lucide="check-circle" class="lucide-sm"></i> No rating anomalies detected.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Mutual Rapid Trading -->
<div class="card mb-3" style="border-left:4px solid #6a1b9a;">
    <div class="card-header">
        <h3><i data-lucide="handshake" class="lucide-sm"></i> Mutual Trading Pairs</h3>
        </div>
    <p style="font-size:0.82rem; color:var(--text-muted); margin-bottom:12px;">
        Pairs of users who have 4+ completed sessions together — could indicate coordinated credit farming.
    </p>
    <?php if ($mutual_trading && $mutual_trading->num_rows > 0): ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>User A</th>
                        <th>User B</th>
                        <th>Sessions</th>
                        <th>Total TC</th>
                        <th>First</th>
                        <th>Last</th>
                        <th>Span</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($mt = $mutual_trading->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($mt['name_a']); ?></strong></td>
                            <td><strong><?php echo htmlspecialchars($mt['name_b']); ?></strong></td>
                            <td style="font-weight:700; color:#6a1b9a;"><?php echo $mt['session_count']; ?></td>
                            <td style="font-weight:600;"><?php echo number_format($mt['total_credits'], 2); ?> TC</td>
                            <td style="font-size:0.8rem;"><?php echo date('M d', strtotime($mt['first_session'])); ?></td>
                            <td style="font-size:0.8rem;"><?php echo date('M d', strtotime($mt['last_session'])); ?></td>
                            <td><span class="badge badge-warning"><?php echo $mt['span_days']; ?> days</span></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state" style="padding:20px;">
            <p style="color:var(--success);"><i data-lucide="check-circle" class="lucide-sm"></i> No suspicious mutual trading patterns detected.</p>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
