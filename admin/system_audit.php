<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'System Audit Logs';

// Filtering params
$table_filter = trim($_GET['table'] ?? '');
$action_filter = trim($_GET['action'] ?? '');
$search = trim($_GET['search'] ?? '');
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Base query parts
$where_clauses = [];
if ($table_filter !== '') {
    $where_clauses[] = "sal.table_affected = '" . $conn->real_escape_string($table_filter) . "'";
}
if ($action_filter !== '') {
    $where_clauses[] = "sal.action_type = '" . $conn->real_escape_string($action_filter) . "'";
}
if ($search !== '') {
    $escaped_search = $conn->real_escape_string($search);
    $where_clauses[] = "(sal.details LIKE '%$escaped_search%' OR u.name LIKE '%$escaped_search%')";
}

$where_sql = '';
if (count($where_clauses) > 0) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

// Count total for pagination
$count_query = "
    SELECT COUNT(*) as total 
    FROM system_audit_log sal
    LEFT JOIN users u ON sal.user_id = u.user_id
    $where_sql
";
$total_records = $conn->query($count_query)->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;

// Fetch logs
$logs_query = "
    SELECT sal.*, u.name AS user_name, u.email AS user_email
    FROM system_audit_log sal
    LEFT JOIN users u ON sal.user_id = u.user_id
    $where_sql
    ORDER BY sal.timestamp DESC
    LIMIT $limit OFFSET $offset
";
$logs_result = $conn->query($logs_query);

// Get distinct values for filter dropdowns
$tables_result = $conn->query("SELECT DISTINCT table_affected FROM system_audit_log ORDER BY table_affected");
$actions_result = $conn->query("SELECT DISTINCT action_type FROM system_audit_log ORDER BY action_type");

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="card mb-3">
    <div class="card-header" style="border-bottom: 1px solid var(--border-light); padding-bottom: 15px;">
        <div>
            <h2 style="color: var(--primary); font-family: var(--font-headline); font-weight: 700; margin: 0;"><i data-lucide="shield" class="lucide-sm"></i> System Audit Trail</h2>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 4px;">Track database state modifications and automated trigger executions in real time.</p>
        </div>
    </div>
    
    <div style="padding: 15px 20px; background: var(--bg-primary); border-bottom: 1px solid var(--border-light);">
        <form method="GET" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
            <div style="flex: 1; min-width: 200px;">
                <label style="display: block; font-size: 0.75rem; font-weight: bold; color: var(--text-secondary); margin-bottom: 5px;">Search Details / User</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="e.g. status, user name..." style="width: 100%; padding: 8px 12px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-secondary); color: var(--text-primary); font-size: 0.85rem;">
            </div>
            
            <div style="width: 150px;">
                <label style="display: block; font-size: 0.75rem; font-weight: bold; color: var(--text-secondary); margin-bottom: 5px;">Filter Table</label>
                <select name="table" style="width: 100%; padding: 8px 12px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-secondary); color: var(--text-primary); font-size: 0.85rem;">
                    <option value="">All Tables</option>
                    <?php while ($t = $tables_result->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($t['table_affected']); ?>" <?php echo $table_filter === $t['table_affected'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($t['table_affected']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div style="width: 150px;">
                <label style="display: block; font-size: 0.75rem; font-weight: bold; color: var(--text-secondary); margin-bottom: 5px;">Filter Action</label>
                <select name="action" style="width: 100%; padding: 8px 12px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-secondary); color: var(--text-primary); font-size: 0.85rem;">
                    <option value="">All Actions</option>
                    <?php while ($a = $actions_result->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($a['action_type']); ?>" <?php echo $action_filter === $a['action_type'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($a['action_type']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary" style="padding: 8px 16px;">Apply Filters</button>
                <?php if ($search || $table_filter || $action_filter): ?>
                    <a href="system_audit.php" class="btn btn-secondary" style="padding: 8px 16px; display: flex; align-items: center; justify-content: center;">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if ($logs_result && $logs_result->num_rows > 0): ?>
    <div class="table-wrapper">
        <table style="border-collapse: collapse; width: 100%;">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th style="width: 150px;">Timestamp</th>
                    <th style="width: 100px;">Action</th>
                    <th style="width: 150px;">Affected Table</th>
                    <th style="width: 100px;">Record ID</th>
                    <th style="width: 180px;">User Context</th>
                    <th>Details / Old vs New State</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($log = $logs_result->fetch_assoc()): 
                    $badge_class = 'badge-info';
                    if ($log['action_type'] === 'INSERT') $badge_class = 'badge-success';
                    elseif ($log['action_type'] === 'UPDATE') $badge_class = 'badge-warning';
                    elseif ($log['action_type'] === 'DELETE') $badge_class = 'badge-danger';
                ?>
                    <tr style="border-bottom: 1px solid var(--border-light);">
                        <td style="color: var(--text-muted); font-size: 0.82rem; font-weight: bold;">#<?php echo $log['log_id']; ?></td>
                        <td style="font-size: 0.82rem; color: var(--text-muted); white-space: nowrap;"><?php echo date('Y-m-d H:i:s', strtotime($log['timestamp'])); ?></td>
                        <td><span class="badge <?php echo $badge_class; ?>" style="font-size: 0.75rem;"><?php echo htmlspecialchars($log['action_type']); ?></span></td>
                        <td><code style="background: var(--bg-hover); color: var(--primary); padding: 2px 6px; border-radius: var(--radius-sm); font-size: 0.8rem;"><?php echo htmlspecialchars($log['table_affected']); ?></code></td>
                        <td><code style="background: var(--bg-hover); color: var(--text-secondary); padding: 2px 6px; border-radius: var(--radius-sm); font-size: 0.8rem;">ID: <?php echo $log['record_id']; ?></code></td>
                        <td>
                            <?php if ($log['user_id']): ?>
                                <strong style="font-size: 0.85rem; color: var(--text-primary);"><?php echo htmlspecialchars($log['user_name']); ?></strong>
                                <div style="font-size: 0.7rem; color: var(--text-muted);">ID: <?php echo $log['user_id']; ?></div>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-style: italic; font-size: 0.85rem;">System Trigger</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 0.85rem; line-height: 1.4; color: var(--text-secondary);">
                            <?php echo htmlspecialchars($log['details']); ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; border-top: 1px solid var(--border-light); background: var(--bg-primary);">
            <div style="font-size: 0.85rem; color: var(--text-muted);">
                Showing <strong><?php echo $offset + 1; ?></strong> to <strong><?php echo min($offset + $limit, $total_records); ?></strong> of <strong><?php echo $total_records; ?></strong> audit entries
            </div>
            <div style="display: flex; gap: 5px;">
                <?php if ($page > 1): ?>
                    <a href="?search=<?php echo urlencode($search); ?>&table=<?php echo urlencode($table_filter); ?>&action=<?php echo urlencode($action_filter); ?>&page=<?php echo $page - 1; ?>" class="btn btn-sm btn-secondary" style="padding: 6px 12px;">&larr; Prev</a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                        <a href="?search=<?php echo urlencode($search); ?>&table=<?php echo urlencode($table_filter); ?>&action=<?php echo urlencode($action_filter); ?>&page=<?php echo $i; ?>" class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 6px 12px;"><?php echo $i; ?></a>
                    <?php elseif ($i == 2 || $i == $total_pages - 1): ?>
                        <span style="padding: 6px; color: var(--text-muted);">...</span>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?search=<?php echo urlencode($search); ?>&table=<?php echo urlencode($table_filter); ?>&action=<?php echo urlencode($action_filter); ?>&page=<?php echo $page + 1; ?>" class="btn btn-sm btn-secondary" style="padding: 6px 12px;">Next &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php else: ?>
        <div style="padding: 40px; text-align: center; color: var(--text-muted);">
            <div style="font-size: 2.5rem; margin-bottom: 10px;"><i data-lucide="clipboard" class="lucide-sm"></i></div>
            <p>No audit log entries match your filter settings.</p>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
