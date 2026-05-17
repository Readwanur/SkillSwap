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

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'accept_task') {
        $task_id = intval($_POST['task_id'] ?? 0);
        if ($task_id > 0) {
            $stmt = $conn->prepare("UPDATE community_task SET user_id = ?, status = 'in-progress' WHERE task_id = ? AND status = 'pending'");
            $stmt->bind_param("ii", $user_id, $task_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = 'Task accepted! Complete it to earn reputation points.';
            }
            $stmt->close();
        }
    }

    if ($_POST['action'] === 'complete_task') {
        $task_id = intval($_POST['task_id'] ?? 0);
        if ($task_id > 0) {
            $task = $conn->query("SELECT * FROM community_task WHERE task_id = $task_id AND user_id = $user_id AND status = 'in-progress'")->fetch_assoc();
            if ($task) {
                $conn->query("UPDATE community_task SET status = 'completed', completed_at = NOW() WHERE task_id = $task_id");
                // Boost reputation
                $boost = $task['rep_boost'];
                $conn->query("UPDATE reputation SET current_score = LEAST(current_score + ($boost / 100), 5.00) WHERE user_id = $user_id");
                $success = 'Task completed! +' . number_format($boost, 2) . ' reputation boost applied.';
            }
        }
    }
}

// Fetch user reputation
$rep = $conn->query("SELECT * FROM reputation WHERE user_id = $user_id")->fetch_assoc();
$below_threshold = ($rep && $rep['current_score'] < 2.50);

// My tasks
$my_tasks = $conn->query("
    SELECT * FROM community_task
    WHERE user_id = $user_id
    ORDER BY assigned_at DESC
");

// Available tasks (unassigned)
$available_tasks = $conn->query("
    SELECT * FROM community_task
    WHERE status = 'pending' AND (user_id IS NULL OR user_id != $user_id)
    ORDER BY rep_boost DESC
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

        <?php if ($below_threshold): ?>
            <div class="alert alert-warning">
                &#9888; Your reputation is below 2.50. Complete community tasks to restore your reputation and regain full
                platform access.
            </div>
        <?php endif; ?>

        <!-- Reputation Status -->
        <div class="card mb-3">
            <div class="flex justify-between items-center">
                <div>
                    <h3 class="section-title" style="margin-bottom:4px;">Your Reputation</h3>
                    <p style="color:var(--text-secondary); font-size:0.85rem;">Complete community tasks to boost your
                        score</p>
                </div>
                <div style="text-align:right;">
                    <span
                        style="font-size:1.8rem; font-weight:700; color:var(--orange-primary);"><?php echo $rep ? $rep['current_score'] : '5.00'; ?></span>
                    <span style="color:var(--text-muted);">/5.00</span>
                </div>
            </div>
            <div class="progress-bar mt-2">
                <div class="fill" style="width: <?php echo ($rep ? ($rep['current_score'] / 5) * 100 : 100); ?>%;">
                </div>
            </div>
        </div>

        <!-- My Tasks -->
        <h2 class="section-title">My Tasks</h2>
        <?php if ($my_tasks->num_rows > 0): ?>
            <?php while ($t = $my_tasks->fetch_assoc()):
                $status_class = 'badge-warning';
                if ($t['status'] === 'completed')
                    $status_class = 'badge-success';
                elseif ($t['status'] === 'in-progress')
                    $status_class = 'badge-info';
                elseif ($t['status'] === 'cancelled')
                    $status_class = 'badge-danger';
                ?>
                <div class="task-card">
                    <div class="task-header">
                        <h4><?php echo htmlspecialchars($t['task_type']); ?> Task</h4>
                        <span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($t['status']); ?></span>
                    </div>
                    <p><?php echo htmlspecialchars($t['description']); ?></p>
                    <div class="task-meta">
                        <span>&#128205; <?php echo htmlspecialchars($t['location']); ?></span>
                        <span>&#11088; +<?php echo number_format($t['rep_boost'], 2); ?> Rep</span>
                        <span>Assigned: <?php echo date('M d, Y', strtotime($t['assigned_at'])); ?></span>
                        <?php if ($t['completed_at']): ?>
                            <span>Completed: <?php echo date('M d, Y', strtotime($t['completed_at'])); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($t['status'] === 'in-progress'): ?>
                        <form method="POST" class="mt-2">
                            <input type="hidden" name="action" value="complete_task">
                            <input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>">
                            <button type="submit" class="btn btn-sm btn-success">Mark Complete</button>
                        </form>
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

        <!-- Available Tasks -->
        <h2 class="section-title mt-3">Available Tasks</h2>
        <?php if ($available_tasks->num_rows > 0): ?>
            <?php while ($t = $available_tasks->fetch_assoc()): ?>
                <div class="task-card">
                    <div class="task-header">
                        <h4><?php echo htmlspecialchars($t['task_type']); ?> Task</h4>
                        <span class="badge badge-orange">+<?php echo number_format($t['rep_boost'], 2); ?> Rep</span>
                    </div>
                    <p><?php echo htmlspecialchars($t['description']); ?></p>
                    <div class="task-meta">
                        <span>&#128205; <?php echo htmlspecialchars($t['location']); ?></span>
                    </div>
                    <form method="POST" class="mt-2">
                        <input type="hidden" name="action" value="accept_task">
                        <input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>">
                        <button type="submit" class="btn btn-sm btn-primary">Accept Task</button>
                    </form>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="card">
                <div class="empty-state">
                    <p>No community tasks available at the moment.</p>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>