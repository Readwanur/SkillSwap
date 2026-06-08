<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'Community Tasks';
$success = '';
$error = '';

// Penalize reliability score by -0.5 for users who failed to complete tasks within 24 hours
$conn->query("
    UPDATE users u
    JOIN community_task ct ON u.user_id = ct.user_id
    SET u.reliability_score = GREATEST(u.reliability_score - 0.50, 0)
    WHERE ct.status = 'in-progress' AND ct.assigned_at < NOW() - INTERVAL 1 DAY
");
// Auto-expire tasks assigned more than 24 hours ago
$conn->query("UPDATE community_task SET status = 'pending', user_id = NULL, assigned_at = NULL WHERE status = 'in-progress' AND assigned_at < NOW() - INTERVAL 1 DAY");

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'accept_task') {
        $task_id = intval($_POST['task_id'] ?? 0);
        if ($task_id > 0) {
            $stmt = $conn->prepare("UPDATE community_task SET user_id = ?, status = 'in-progress', assigned_at = NOW() WHERE task_id = ? AND status = 'pending'");
            $stmt->bind_param("ii", $user_id, $task_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = 'Task accepted! Complete it to earn time credits.';
            } else {
                $error = 'This task is no longer available or has already been accepted by another user.';
            }
            $stmt->close();
        }
    }

    if ($_POST['action'] === 'abandon_task') {
        $task_id = intval($_POST['task_id'] ?? 0);
        if ($task_id > 0) {
            $stmt = $conn->prepare("UPDATE community_task SET status = 'pending', user_id = NULL, assigned_at = NULL WHERE task_id = ? AND user_id = ? AND status = 'in-progress'");
            $stmt->bind_param("ii", $task_id, $user_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = 'Task returned to the available pool.';
            } else {
                $error = 'Failed to return task. It may not be in-progress.';
            }
            $stmt->close();
        }
    }

    if ($_POST['action'] === 'submit_task') {
        $task_id = intval($_POST['task_id'] ?? 0);
        $submission_note = trim($_POST['submission_note'] ?? '');
        
        if ($task_id > 0 && !empty($submission_note)) {
            $task = $conn->query("SELECT * FROM community_task WHERE task_id = $task_id AND user_id = $user_id AND status = 'in-progress'")->fetch_assoc();
            if ($task) {
                $stmt = $conn->prepare("UPDATE community_task SET status = 'under-review', submission_note = ? WHERE task_id = ?");
                $stmt->bind_param("si", $submission_note, $task_id);
                if ($stmt->execute()) {
                    $success = 'Task submitted for review! You will receive your credits once an admin approves it.';
                }
                $stmt->close();
            }
        } else {
            $error = 'Submission note is required to complete the task.';
        }
    }
}

// Fetch user wallet
$wallet = $conn->query("SELECT balance FROM wallet WHERE user_id = $user_id")->fetch_assoc();
$balance = $wallet ? $wallet['balance'] : 0.00;

// My tasks
$my_tasks = $conn->query("
    SELECT * FROM community_task
    WHERE user_id = $user_id AND status != 'completed'
    ORDER BY assigned_at DESC
");

// Pagination setup for Available Tasks
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;
$limit = 9; // Display 9 tasks per page
$offset = ($page - 1) * $limit;

$count_query = "SELECT COUNT(*) as total FROM community_task WHERE status = 'pending' AND (user_id IS NULL OR user_id != $user_id)";
$total_records = $conn->query($count_query)->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

// Available tasks (unassigned)
$available_tasks = $conn->query("
    SELECT * FROM community_task
    WHERE status = 'pending' AND (user_id IS NULL OR user_id != $user_id)
    ORDER BY credit_reward DESC
    LIMIT $limit OFFSET $offset
");

// Sorting parameters
$sort = trim($_GET['sort'] ?? 'rank');
$order = trim($_GET['order'] ?? 'asc');

$allowed_sorts = [
    'rank' => 'task_rank',
    'name' => 'name',
    'rewards' => 'total_rewards'
];

$sort_col = $allowed_sorts[$sort] ?? 'task_rank';
$order_sql = (strtolower($order) === 'desc') ? 'DESC' : 'ASC';

// --- COMPLEX QUERY: CQ-12 ---
// Community Task Leaderboard (CTE + Window Function DENSE_RANK)
$task_leaderboard = $conn->query("
    WITH task_ranks AS (
        SELECT
            u.name,
            u.user_id,
            COUNT(ct.task_id) AS tasks_completed,
            SUM(ct.credit_reward) AS total_rewards,
            DENSE_RANK() OVER (ORDER BY COUNT(ct.task_id) DESC) as task_rank
        FROM community_task ct
        JOIN users u ON ct.user_id = u.user_id
        WHERE ct.status = 'completed'
        GROUP BY ct.user_id
    )
    SELECT * FROM task_ranks WHERE task_rank <= 5 ORDER BY $sort_col $order_sql
");

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <h1 class="page-title">Community Tasks</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Wallet Status -->
        <div class="card mb-3">
            <div class="flex justify-between items-center">
                <div>
                    <h3 class="section-title" style="margin-bottom:4px;">Your Wallet</h3>
                    <p style="color:var(--text-secondary); font-size:0.85rem;">Complete community tasks to earn time credits</p>
                </div>
                <div style="text-align:right;">
                    <span style="font-size:1.8rem; font-weight:700; color:var(--orange-primary);"><?php echo number_format($balance, 2); ?></span>
                    <span style="color:var(--text-muted);">TC</span>
                </div>
            </div>
        </div>

        <div class="grid-2" style="align-items: start;">
            
            <!-- Left: Tasks List -->
            <div>
                <!-- My Tasks -->
                <h2 class="section-title" style="margin-top:0;">My Tasks</h2>
                <?php if ($my_tasks->num_rows > 0): ?>
                    <?php while ($t = $my_tasks->fetch_assoc()):
                        $status_class = 'badge-warning';
                        if ($t['status'] === 'completed')
                            $status_class = 'badge-success';
                        elseif ($t['status'] === 'in-progress')
                            $status_class = 'badge-info';
                        elseif ($t['status'] === 'under-review')
                            $status_class = 'badge-primary';
                        elseif ($t['status'] === 'cancelled')
                            $status_class = 'badge-danger';
                        ?>
                        <div class="task-card">
                            <div class="task-header">
                                <h4><?php echo htmlspecialchars($t['task_type']); ?> Task</h4>
                                <span class="badge <?php echo $status_class; ?>">
                                    <?php 
                                    if ($t['status'] === 'under-review') echo 'Under Review';
                                    else echo ucfirst($t['status']); 
                                    ?>
                                </span>
                            </div>
                            <p><?php echo htmlspecialchars($t['description']); ?></p>
                            <div class="task-meta">
                                <span style="display: flex; align-items: center; gap: 4px;"><i data-lucide="map-pin" class="lucide-sm"></i> <?php echo htmlspecialchars($t['location']); ?></span>
                                <span style="display: flex; align-items: center; gap: 4px;"><i data-lucide="coins" class="lucide-sm"></i> +<?php echo number_format($t['credit_reward'], 2); ?> TC</span>
                            </div>
                            <div class="task-meta mt-1" style="font-size: 0.75rem;">
                                <span>Assigned: <?php echo date('M d, Y', strtotime($t['assigned_at'])); ?></span>
                                <?php if ($t['completed_at']): ?>
                                    <span>Completed: <?php echo date('M d, Y', strtotime($t['completed_at'])); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($t['status'] === 'in-progress'): ?>
                                <div class="alert alert-info mt-2" style="font-size:0.8rem; padding:8px; display: flex; align-items: flex-start; gap: 6px;">
                                    <i data-lucide="clock" class="lucide-sm" style="flex-shrink: 0; margin-top: 2px;"></i> 
                                    <div>You have 24 hours to complete this task from the time of assignment, or it will be automatically returned to the pool.</div>
                                </div>
                                <div style="display:flex; gap: 8px;">
                                    <button type="button" class="btn btn-sm btn-success mt-2" onclick="openSubmitModal(<?php echo $t['task_id']; ?>)">Submit for Review</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to return this task?');">
                                        <input type="hidden" name="action" value="abandon_task">
                                        <input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger mt-2" style="background: transparent; color: var(--danger); border: 1px solid var(--danger);">Return Task</button>
                                    </form>
                                </div>
                            <?php elseif ($t['status'] === 'under-review' && !empty($t['submission_note'])): ?>
                                <div style="margin-top:10px; background:var(--bg-hover); padding:10px; border-radius:var(--radius-sm); font-size:0.85rem; border-left:3px solid var(--primary);">
                                    <strong>Your Submission:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($t['submission_note'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="card mb-3">
                        <div class="empty-state">
                            <p>You have no assigned tasks.</p>
                        </div>
                    </div>
                <?php endif; ?>


            </div>

            <!-- Right: Task Leaderboard -->
            <div class="card">
                <div class="card-header">
                    <h3>Top Contributors</h3>
                    <span class="badge badge-orange">Leaderboard</span>
                </div>
                <p style="color:var(--text-muted); font-size:0.8rem; margin-bottom: 15px;">
                    Users who have earned the most credits by completing community tasks.
                </p>
                <?php if ($task_leaderboard && $task_leaderboard->num_rows > 0): ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>
                                        <span class="th-content">
                                            <span>Rank</span>
                                            <?php echo renderTableSort('rank', $sort, $order); ?>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="th-content">
                                            <span>Contributor</span>
                                            <?php echo renderTableSort('name', $sort, $order); ?>
                                        </span>
                                    </th>
                                    <th style="text-align: right;">
                                        <span class="th-content" style="justify-content: flex-end;">
                                            <span>Rewards</span>
                                            <?php echo renderTableSort('rewards', $sort, $order); ?>
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($tl = $task_leaderboard->fetch_assoc()): 
                                    $is_current_user = ($tl['user_id'] == $user_id);
                                    ?>
                                    <tr style="<?php echo $is_current_user ? 'background: var(--primary-glow); font-weight: 600;' : ''; ?>">
                                        <td><strong>#<?php echo $tl['task_rank']; ?></strong></td>
                                        <td>
                                            <?php echo htmlspecialchars($tl['name']); ?>
                                            <?php if ($is_current_user): ?>
                                                <span class="badge badge-success" style="font-size:0.6rem; padding: 2px 6px;">You</span>
                                            <?php endif; ?>
                                            <br><small style="color:var(--text-muted); font-size:0.75rem;"><?php echo $tl['tasks_completed']; ?> task<?php echo $tl['tasks_completed'] == 1 ? '' : 's'; ?></small>
                                        </td>
                                        <td style="text-align: right; color: var(--success); font-weight: 600;">
                                            +<?php echo number_format($tl['total_rewards'], 1); ?> TC
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="color: var(--text-muted); font-size: 0.85rem; text-align: center;">No task contributors recorded yet.</p>
                <?php endif; ?>
            </div>

        </div>

        <!-- Available Tasks -->
        <h2 class="section-title mt-4">Available Tasks</h2>
        <?php if ($available_tasks->num_rows > 0): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;">
                <?php while ($t = $available_tasks->fetch_assoc()): ?>
                    <div class="task-card">
                        <div class="task-header">
                            <h4><?php echo htmlspecialchars($t['task_type']); ?> Task</h4>
                            <span class="badge badge-orange">+<?php echo number_format($t['credit_reward'], 2); ?> TC</span>
                        </div>
                        <p><?php echo htmlspecialchars($t['description']); ?></p>
                        <div class="task-meta">
                            <span style="display: flex; align-items: center; gap: 4px;"><i data-lucide="map-pin" class="lucide-sm"></i> <?php echo htmlspecialchars($t['location']); ?></span>
                        </div>
                        <form method="POST" class="mt-2">
                            <input type="hidden" name="action" value="accept_task">
                            <input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>">
                            <button type="submit" class="btn btn-sm btn-primary" style="width: 100%;">Accept Task</button>
                        </form>
                    </div>
                <?php endwhile; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 20px 0; margin-top: 20px; border-top: 1px solid var(--border-light);">
                    <div style="font-size: 0.85rem; color: var(--text-muted);">
                        Showing <strong><?php echo $offset + 1; ?></strong> to <strong><?php echo min($offset + $limit, $total_records); ?></strong> of <strong><?php echo $total_records; ?></strong> tasks
                    </div>
                    <div style="display: flex; gap: 5px;">
                        <?php if ($page > 1): ?>
                            <a href="?sort=<?php echo urlencode($sort); ?>&order=<?php echo urlencode($order); ?>&page=<?php echo $page - 1; ?>" class="btn btn-sm btn-secondary" style="padding: 6px 12px;">&larr; Prev</a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                <a href="?sort=<?php echo urlencode($sort); ?>&order=<?php echo urlencode($order); ?>&page=<?php echo $i; ?>" class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 6px 12px;"><?php echo $i; ?></a>
                            <?php elseif ($i == 2 || $i == $total_pages - 1): ?>
                                <span style="padding: 6px; color: var(--text-muted);">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?sort=<?php echo urlencode($sort); ?>&order=<?php echo urlencode($order); ?>&page=<?php echo $page + 1; ?>" class="btn btn-sm btn-secondary" style="padding: 6px 12px;">Next &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="card">
                <div class="empty-state">
                    <p>No community tasks available at the moment.</p>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- ========== SUBMIT TASK MODAL ========== -->
<div class="modal-overlay" id="submit-task-modal">
    <div class="modal">
        <div class="modal-header">
            <h3>Submit Task for Review</h3>
            <button class="modal-close" onclick="document.getElementById('submit-task-modal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="submit_task">
            <input type="hidden" name="task_id" id="submit-task-id">
            
            <div class="form-group">
                <label>Proof of Completion *</label>
                <textarea name="submission_note" class="form-control" rows="4" placeholder="Briefly describe what you did or provide a link to your work..." required></textarea>
                <small style="color:var(--text-muted); font-size:0.75rem;">This note will be reviewed by an administrator before your time credits are awarded.</small>
            </div>
            
            <div class="flex gap-1" style="justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('submit-task-modal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-success">Submit Task</button>
            </div>
        </form>
    </div>
</div>

<script>
function openSubmitModal(taskId) {
    document.getElementById('submit-task-id').value = taskId;
    document.getElementById('submit-task-modal').classList.add('active');
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('active');
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>