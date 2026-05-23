<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'Credit Wallet';

// Fetch wallet
$wallet = $conn->query("SELECT * FROM wallet WHERE user_id = $user_id")->fetch_assoc();
$balance = $wallet ? $wallet['balance'] : 0;

// Aggregate: Total earned and spent
$earned = $conn->query("SELECT COALESCE(SUM(final_amount), 0) AS total FROM transactions WHERE to_user_id = $user_id")->fetch_assoc()['total'];
$spent = $conn->query("SELECT COALESCE(SUM(final_amount), 0) AS total FROM transactions WHERE from_user_id = $user_id")->fetch_assoc()['total'];

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
    'id' => 't.transcation_id',
    'amount' => 't.final_amount',
    'date' => 't.timestamp'
];

$sort_col = $allowed_sorts[$sort] ?? 't.timestamp';
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

// All transactions
$transactions = $conn->query("
    SELECT t.*, u_from.name AS from_name, u_to.name AS to_name, s.skill_name
    FROM transactions t
    JOIN users u_from ON t.from_user_id = u_from.user_id
    JOIN users u_to ON t.to_user_id = u_to.user_id
    LEFT JOIN exchange_sessions es ON t.session_id = es.session_id
    LEFT JOIN skills s ON es.skill_id = s.skill_id
    WHERE t.from_user_id = $user_id OR t.to_user_id = $user_id
    ORDER BY $sort_col $order
");

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <h1 class="page-title">Credit Wallet</h1>

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

        <!-- Transaction History -->
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
                                    </td>
                                    <td><?php echo htmlspecialchars($t['skill_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($t['from_name']); ?></td>
                                    <td><?php echo htmlspecialchars($t['to_name']); ?></td>
                                    <td
                                        style="color: <?php echo $is_incoming ? 'var(--success)' : 'var(--danger)'; ?>; font-weight:600;">
                                        <?php echo $is_incoming ? '+' : '-'; ?>        <?php echo number_format($t['final_amount'], 2); ?>
                                        TC
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

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>