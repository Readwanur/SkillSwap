<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'Community Tasks';
$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_task') {
        $task_type = trim($_POST['task_type'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $rep_boost = floatval($_POST['rep_boost'] ?? 0.25);

        if ($task_type && $description) {
            $stmt = $conn->prepare("INSERT INTO community_task (task_type, description, location, rep_boost, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->bind_param("sssd", $task_type, $description, $location, $rep_boost);
            if ($stmt->execute()) {
                $success = "Task created successfully! It's now available for users to accept.";
            } else {
                $error = 'Failed to create task.';
            }
            $stmt->close();
        } else {
            $error = 'Task type and description are required.';
        }
    }

    if ($_POST['action'] === 'edit_task') {
        $tid = intval($_POST['task_id'] ?? 0);
        $task_type = trim($_POST['task_type'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $rep_boost = floatval($_POST['rep_boost'] ?? 0.25);
        $status = $_POST['status'] ?? 'pending';

        if ($tid > 0 && $task_type && $description) {
            $stmt = $conn->prepare("UPDATE community_task SET task_type = ?, description = ?, location = ?, rep_boost = ?, status = ? WHERE task_id = ?");
            $stmt->bind_param("sssdsi", $task_type, $description, $location, $rep_boost, $status, $tid);
            if ($stmt->execute()) {
                $success = "Task #$tid updated.";
            }
            $stmt->close();
        }
    }

    if ($_POST['action'] === 'delete_task') {
        $tid = intval($_POST['task_id'] ?? 0);
        $conn->query("DELETE FROM community_task WHERE task_id = $tid");
        $success = "Task #$tid deleted.";
    }

    if ($_POST['action'] === 'cancel_task') {
        $tid = intval($_POST['task_id'] ?? 0);
        $conn->query("UPDATE community_task SET status = 'cancelled' WHERE task_id = $tid");
        $success = "Task #$tid cancelled.";
    }

    if ($_POST['action'] === 'reset_task') {
        $tid = intval($_POST['task_id'] ?? 0);
        $conn->query("UPDATE community_task SET status = 'pending', user_id = NULL, completed_at = NULL WHERE task_id = $tid");
        $success = "Task #$tid reset to pending and unassigned.";
    }
}

// Status filter
$status_filter = trim($_GET['status'] ?? '');
$where_clause = '';
if ($status_filter !== '' && in_array($status_filter, ['pending', 'in-progress', 'completed', 'cancelled'])) {
    $where_clause = "WHERE ct.status = '$status_filter'";
}

// Counts
$count_all = $conn->query("SELECT COUNT(*) AS cnt FROM community_task")->fetch_assoc()['cnt'];
$count_pending = $conn->query("SELECT COUNT(*) AS cnt FROM community_task WHERE status = 'pending'")->fetch_assoc()['cnt'];
$count_progress = $conn->query("SELECT COUNT(*) AS cnt FROM community_task WHERE status = 'in-progress'")->fetch_assoc()['cnt'];
$count_completed = $conn->query("SELECT COUNT(*) AS cnt FROM community_task WHERE status = 'completed'")->fetch_assoc()['cnt'];
$count_cancelled = $conn->query("SELECT COUNT(*) AS cnt FROM community_task WHERE status = 'cancelled'")->fetch_assoc()['cnt'];

// Fetch tasks
$tasks = $conn->query("
    SELECT ct.*, u.name AS assigned_user
    FROM community_task ct
    LEFT JOIN users u ON ct.user_id = u.user_id
    $where_clause
    ORDER BY ct.assigned_at DESC
");

// Existing task types for dropdown
$task_types_q = $conn->query("SELECT DISTINCT task_type FROM community_task WHERE task_type IS NOT NULL ORDER BY task_type");
$task_types = [];
while ($tt = $task_types_q->fetch_assoc()) {
    $task_types[] = $tt['task_type'];
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="page-header">
    <h1 class="page-title" style="margin:0;">&#x1F4CB; Community Tasks</h1>
    <button class="btn btn-primary" onclick="document.getElementById('add-task-modal').classList.add('active')">+ Create New Task</button>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Summary Stats -->
<div class="stats-grid" style="margin-bottom:16px;">
    <div class="stat-card stat-card-accent" style="--accent: var(--primary); cursor:pointer;" onclick="location.href='?'">
        <span class="stat-value"><?php echo $count_all; ?></span>
        <span class="stat-label">Total Tasks</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--warning); cursor:pointer;" onclick="location.href='?status=pending'">
        <span class="stat-value"><?php echo $count_pending; ?></span>
        <span class="stat-label">Pending</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--info); cursor:pointer;" onclick="location.href='?status=in-progress'">
        <span class="stat-value"><?php echo $count_progress; ?></span>
        <span class="stat-label">In Progress</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--success); cursor:pointer;" onclick="location.href='?status=completed'">
        <span class="stat-value"><?php echo $count_completed; ?></span>
        <span class="stat-label">Completed</span>
    </div>
</div>

<!-- Filter + Table -->
<div class="card">
    <div class="card-header" style="flex-wrap:wrap; gap:8px;">
        <h3>
            <?php if ($status_filter): ?>
                <?php echo ucfirst($status_filter); ?> Tasks (<?php echo $tasks->num_rows; ?>)
            <?php else: ?>
                All Tasks (<?php echo $tasks->num_rows; ?>)
            <?php endif; ?>
        </h3>
        <div class="admin-filter-tabs">
            <a href="?" class="filter-tab <?php echo $status_filter === '' ? 'active' : ''; ?>">All</a>
            <a href="?status=pending" class="filter-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="?status=in-progress" class="filter-tab <?php echo $status_filter === 'in-progress' ? 'active' : ''; ?>">In Progress</a>
            <a href="?status=completed" class="filter-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">Completed</a>
            <a href="?status=cancelled" class="filter-tab <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">Cancelled</a>
        </div>
    </div>

    <?php if ($tasks->num_rows > 0): ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>Location</th>
                    <th>Rep Boost</th>
                    <th>Assigned To</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($t = $tasks->fetch_assoc()):
                    $sc = 'badge-warning';
                    if ($t['status'] === 'completed') $sc = 'badge-success';
                    elseif ($t['status'] === 'in-progress') $sc = 'badge-info';
                    elseif ($t['status'] === 'cancelled') $sc = 'badge-danger';

                    $desc_short = $t['description'] ? (strlen($t['description']) > 60 ? substr($t['description'], 0, 60) . '…' : $t['description']) : '—';
                ?>
                    <tr>
                        <td style="color:var(--text-muted);">#<?php echo $t['task_id']; ?></td>
                        <td><span class="badge badge-orange"><?php echo htmlspecialchars($t['task_type']); ?></span></td>
                        <td style="max-width:220px; font-size:0.85rem;" title="<?php echo htmlspecialchars($t['description'] ?? ''); ?>">
                            <?php echo htmlspecialchars($desc_short); ?>
                        </td>
                        <td style="font-size:0.85rem;"><?php echo htmlspecialchars($t['location'] ?? '—'); ?></td>
                        <td><strong style="color:var(--success);">+<?php echo number_format($t['rep_boost'], 2); ?></strong></td>
                        <td>
                            <?php if ($t['assigned_user']): ?>
                                <span><?php echo htmlspecialchars($t['assigned_user']); ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted); font-style:italic;">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo $sc; ?>"><?php echo ucfirst($t['status']); ?></span></td>
                        <td style="font-size:0.82rem; color:var(--text-muted);"><?php echo date('M d, Y', strtotime($t['assigned_at'])); ?></td>
                        <td>
                            <div class="flex gap-1" style="flex-wrap:nowrap;">
                                <!-- Edit -->
                                <button type="button" class="btn btn-sm btn-secondary" title="Edit Task"
                                    onclick="openEditTask(<?php echo $t['task_id']; ?>, '<?php echo addslashes(htmlspecialchars($t['task_type'])); ?>', '<?php echo addslashes(htmlspecialchars($t['description'] ?? '')); ?>', '<?php echo addslashes(htmlspecialchars($t['location'] ?? '')); ?>', <?php echo $t['rep_boost']; ?>, '<?php echo $t['status']; ?>')">
                                    &#x270E;
                                </button>

                                <?php if ($t['status'] === 'in-progress' || $t['status'] === 'completed'): ?>
                                    <!-- Reset to pending -->
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="reset_task">
                                        <input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-secondary" title="Reset to Pending"
                                            onclick="return confirm('Reset this task to pending and unassign it?')">&#x21BA;</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($t['status'] !== 'cancelled'): ?>
                                    <!-- Cancel -->
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="cancel_task">
                                        <input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Cancel Task"
                                            onclick="return confirm('Cancel this task?')">&#x274C;</button>
                                    </form>
                                <?php endif; ?>

                                <!-- Delete -->
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_task">
                                    <input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete Permanently"
                                        onclick="return confirm('Permanently delete this task?')">&#x1F5D1;</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="empty-state">
            <div class="icon">&#x1F4AD;</div>
            <p>No <?php echo $status_filter ?: ''; ?> tasks found.</p>
            <?php if ($status_filter): ?>
                <a href="community_tasks.php" class="btn btn-sm btn-secondary mt-2">View All</a>
            <?php else: ?>
                <button class="btn btn-sm btn-primary mt-2" onclick="document.getElementById('add-task-modal').classList.add('active')">Create First Task</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ========== ADD TASK MODAL ========== -->
<div class="modal-overlay" id="add-task-modal">
    <div class="modal">
        <div class="modal-header">
            <h3>Create Community Task</h3>
            <button class="modal-close" onclick="document.getElementById('add-task-modal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_task">
            <div class="grid-2">
                <div class="form-group">
                    <label>Task Type *</label>
                    <select name="task_type" class="form-control" id="add-task-type-select" onchange="toggleNewTaskType('add')" required>
                        <option value="">Select type...</option>
                        <?php foreach ($task_types as $tt): ?>
                            <option value="<?php echo htmlspecialchars($tt); ?>"><?php echo htmlspecialchars($tt); ?></option>
                        <?php endforeach; ?>
                        <option value="__new__">+ New Type</option>
                    </select>
                    <input type="text" id="add-task-type-new" class="form-control mt-1" placeholder="Type new task type..." style="display:none;">
                </div>
                <div class="form-group">
                    <label>Rep Boost</label>
                    <input type="number" name="rep_boost" class="form-control" value="0.25" min="0.05" max="1.00" step="0.05">
                    <small style="color:var(--text-muted); font-size:0.72rem;">How much reputation this task rewards (0.05 – 1.00)</small>
                </div>
            </div>
            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Describe what the volunteer needs to do..." required></textarea>
            </div>
            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location" class="form-control" placeholder="e.g. Community Center, Main Library...">
            </div>
            <div class="flex gap-1" style="justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-task-modal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Task</button>
            </div>
        </form>
    </div>
</div>

<!-- ========== EDIT TASK MODAL ========== -->
<div class="modal-overlay" id="edit-task-modal">
    <div class="modal">
        <div class="modal-header">
            <h3>Edit Task</h3>
            <button class="modal-close" onclick="document.getElementById('edit-task-modal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_task">
            <input type="hidden" name="task_id" id="edit-task-id">
            <div class="grid-2">
                <div class="form-group">
                    <label>Task Type *</label>
                    <input type="text" name="task_type" id="edit-task-type" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit-task-status" class="form-control">
                        <option value="pending">Pending</option>
                        <option value="in-progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" id="edit-task-desc" class="form-control" rows="3" required></textarea>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Location</label>
                    <input type="text" name="location" id="edit-task-location" class="form-control">
                </div>
                <div class="form-group">
                    <label>Rep Boost</label>
                    <input type="number" name="rep_boost" id="edit-task-boost" class="form-control" min="0.05" max="1.00" step="0.05">
                </div>
            </div>
            <div class="flex gap-1" style="justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('edit-task-modal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// New task type toggle for Add modal
function toggleNewTaskType(prefix) {
    var select = document.getElementById(prefix + '-task-type-select');
    var newInput = document.getElementById(prefix + '-task-type-new');
    if (select.value === '__new__') {
        newInput.style.display = 'block';
        newInput.focus();
        // Override select name so the text input is used
        select.removeAttribute('name');
        newInput.setAttribute('name', 'task_type');
    } else {
        newInput.style.display = 'none';
        newInput.value = '';
        select.setAttribute('name', 'task_type');
        newInput.removeAttribute('name');
    }
}

// Edit modal opener
function openEditTask(id, type, desc, location, boost, status) {
    document.getElementById('edit-task-id').value = id;
    document.getElementById('edit-task-type').value = type;
    document.getElementById('edit-task-desc').value = desc;
    document.getElementById('edit-task-location').value = location;
    document.getElementById('edit-task-boost').value = boost;
    document.getElementById('edit-task-status').value = status;
    document.getElementById('edit-task-modal').classList.add('active');
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
