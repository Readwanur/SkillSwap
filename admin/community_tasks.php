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

    if ($_POST['action'] === 'approve_task') {
        $tid = intval($_POST['task_id'] ?? 0);
        $task = $conn->query("SELECT * FROM community_task WHERE task_id = $tid AND status = 'under-review'")->fetch_assoc();
        
        if ($task) {
            $uid = $task['user_id'];
            $reward = $task['credit_reward'];
            
            $conn->begin_transaction();
            try {
                // 1. Mark task completed
                $conn->query("UPDATE community_task SET status = 'completed', completed_at = NOW() WHERE task_id = $tid");
                
                // 2. Add credits to user wallet
                $existing = $conn->query("SELECT * FROM wallet WHERE user_id = $uid")->fetch_assoc();
                if ($existing) {
                    $conn->query("UPDATE wallet SET balance = balance + $reward WHERE user_id = $uid");
                } else {
                    $conn->query("INSERT INTO wallet (user_id, balance) VALUES ($uid, $reward)");
                }
                
                // 3. Log transaction (from_user_id is NULL for system rewards)
                $stmt = $conn->prepare("INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount, note) VALUES (NULL, NULL, ?, 'community_reward', ?, ?, 'Task #$tid completed')");
                $stmt->bind_param("idd", $uid, $reward, $reward);
                $stmt->execute();
                $stmt->close();
                
                $conn->commit();
                $success = "Task #$tid approved! $reward TC awarded to the user.";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Approval failed: " . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'reject_task') {
        $tid = intval($_POST['task_id'] ?? 0);
        $conn->query("UPDATE community_task SET status = 'pending', user_id = NULL, submission_note = NULL, assigned_at = CURRENT_TIMESTAMP WHERE task_id = $tid");
        $success = "Task #$tid rejected and returned to the pending pool.";
    }

    if ($_POST['action'] === 'add_task') {
        $task_type = trim($_POST['task_type'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $credit_reward = floatval($_POST['credit_reward'] ?? 5.00);

        if ($task_type && $description) {
            $stmt = $conn->prepare("INSERT INTO community_task (task_type, description, location, credit_reward, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->bind_param("sssd", $task_type, $description, $location, $credit_reward);
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
        $credit_reward = floatval($_POST['credit_reward'] ?? 5.00);
        $status = $_POST['status'] ?? 'pending';

        if ($tid > 0 && $task_type && $description) {
            $stmt = $conn->prepare("UPDATE community_task SET task_type = ?, description = ?, location = ?, credit_reward = ?, status = ? WHERE task_id = ?");
            $stmt->bind_param("sssdsi", $task_type, $description, $location, $credit_reward, $status, $tid);
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
        $conn->query("UPDATE community_task SET status = 'pending', user_id = NULL, completed_at = NULL, submission_note = NULL WHERE task_id = $tid");
        $success = "Task #$tid reset to pending and unassigned.";
    }
}

// Status filter
$status_filter = trim($_GET['status'] ?? '');
$where_clause = '';
if ($status_filter !== '' && in_array($status_filter, ['pending', 'in-progress', 'under-review', 'completed', 'cancelled'])) {
    $where_clause = "WHERE ct.status = '$status_filter'";
}

// Sorting parameters
$sort = trim($_GET['sort'] ?? 'created');
$order = trim($_GET['order'] ?? 'desc');

$allowed_sorts = [
    'id' => 'ct.task_id',
    'type' => 'ct.task_type',
    'location' => 'ct.location',
    'reward' => 'ct.credit_reward',
    'assigned' => 'u.name',
    'status' => 'ct.status',
    'created' => 'ct.assigned_at'
];

$sort_col = $allowed_sorts[$sort] ?? 'ct.assigned_at';
$order = (strtolower($order) === 'asc') ? 'ASC' : 'DESC';

// Sort URL generator
function getSortUrl($col, $dir, $status) {
    $query = [
        'sort' => $col,
        'order' => $dir
    ];
    if ($status !== '') {
        $query['status'] = $status;
    }
    return 'community_tasks.php?' . http_build_query($query);
}

// Sort Buttons generator (single toggle button beside column header)
function renderSortButtons($col, $current_sort, $current_order, $status) {
    if ($current_sort === $col) {
        if (strtolower($current_order) === 'asc') {
            $url = getSortUrl($col, 'desc', $status);
            return '
            <span class="sort-arrows">
                <a href="' . $url . '" class="sort-arrow active" title="Sorted Ascending. Click to sort Descending">&#x25B4;</a>
            </span>';
        } else {
            $url = getSortUrl($col, 'asc', $status);
            return '
            <span class="sort-arrows">
                <a href="' . $url . '" class="sort-arrow active" title="Sorted Descending. Click to sort Ascending">&#x25BE;</a>
            </span>';
        }
    } else {
        $url = getSortUrl($col, 'asc', $status);
        return '
        <span class="sort-arrows">
            <a href="' . $url . '" class="sort-arrow" title="Click to sort Ascending">&#x25B4;</a>
        </span>';
    }
}

// Counts
$count_all = $conn->query("SELECT COUNT(*) AS cnt FROM community_task")->fetch_assoc()['cnt'];
$count_pending = $conn->query("SELECT COUNT(*) AS cnt FROM community_task WHERE status = 'pending'")->fetch_assoc()['cnt'];
$count_progress = $conn->query("SELECT COUNT(*) AS cnt FROM community_task WHERE status = 'in-progress'")->fetch_assoc()['cnt'];
$count_review = $conn->query("SELECT COUNT(*) AS cnt FROM community_task WHERE status = 'under-review'")->fetch_assoc()['cnt'];
$count_completed = $conn->query("SELECT COUNT(*) AS cnt FROM community_task WHERE status = 'completed'")->fetch_assoc()['cnt'];
$count_cancelled = $conn->query("SELECT COUNT(*) AS cnt FROM community_task WHERE status = 'cancelled'")->fetch_assoc()['cnt'];

// Fetch tasks
$tasks = $conn->query("
    SELECT ct.*, u.name AS assigned_user
    FROM community_task ct
    LEFT JOIN users u ON ct.user_id = u.user_id
    $where_clause
    ORDER BY $sort_col $order
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
    <h1 class="page-title" style="margin:0;"><i data-lucide="clipboard-list" class="lucide-sm"></i> Community Tasks</h1>
    <button class="btn btn-primary" onclick="document.getElementById('add-task-modal').classList.add('active')">+ Create New Task</button>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Summary Stats -->
<?php
function buildTabUrl($status, $sort, $order) {
    $q = [];
    if ($status !== '') $q['status'] = $status;
    if ($sort !== '') $q['sort'] = $sort;
    if ($order !== '') $q['order'] = strtolower($order);
    return '?' . http_build_query($q);
}
?>
<div class="stats-grid" style="margin-bottom:16px;">
    <div class="stat-card stat-card-accent" style="--accent: var(--primary); cursor:pointer;" onclick="location.href='<?php echo buildTabUrl('', $sort, $order); ?>'">
        <span class="stat-value"><?php echo $count_all; ?></span>
        <span class="stat-label">Total Tasks</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--warning); cursor:pointer;" onclick="location.href='<?php echo buildTabUrl('pending', $sort, $order); ?>'">
        <span class="stat-value"><?php echo $count_pending; ?></span>
        <span class="stat-label">Pending</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--info); cursor:pointer;" onclick="location.href='<?php echo buildTabUrl('in-progress', $sort, $order); ?>'">
        <span class="stat-value"><?php echo $count_progress; ?></span>
        <span class="stat-label">In Progress</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: #9c27b0; cursor:pointer;" onclick="location.href='<?php echo buildTabUrl('under-review', $sort, $order); ?>'">
        <span class="stat-value"><?php echo $count_review; ?></span>
        <span class="stat-label">Under Review</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--success); cursor:pointer;" onclick="location.href='<?php echo buildTabUrl('completed', $sort, $order); ?>'">
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
            <a href="<?php echo buildTabUrl('', $sort, $order); ?>" class="filter-tab <?php echo $status_filter === '' ? 'active' : ''; ?>">All</a>
            <a href="<?php echo buildTabUrl('pending', $sort, $order); ?>" class="filter-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="<?php echo buildTabUrl('in-progress', $sort, $order); ?>" class="filter-tab <?php echo $status_filter === 'in-progress' ? 'active' : ''; ?>">In Progress</a>
            <a href="<?php echo buildTabUrl('under-review', $sort, $order); ?>" class="filter-tab <?php echo $status_filter === 'under-review' ? 'active' : ''; ?>">Under Review</a>
            <a href="<?php echo buildTabUrl('completed', $sort, $order); ?>" class="filter-tab <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">Completed</a>
            <a href="<?php echo buildTabUrl('cancelled', $sort, $order); ?>" class="filter-tab <?php echo $status_filter === 'cancelled' ? 'active' : ''; ?>">Cancelled</a>
        </div>
    </div>

    <?php if ($tasks->num_rows > 0): ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>
                        <span class="th-content">
                            <span>ID</span>
                            <?php echo renderSortButtons('id', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Type</span>
                            <?php echo renderSortButtons('type', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>Description</th>
                    <th>
                        <span class="th-content">
                            <span>Location</span>
                            <?php echo renderSortButtons('location', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Reward</span>
                            <?php echo renderSortButtons('reward', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Assigned To</span>
                            <?php echo renderSortButtons('assigned', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Status</span>
                            <?php echo renderSortButtons('status', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Created</span>
                            <?php echo renderSortButtons('created', $sort, $order, $status_filter); ?>
                        </span>
                    </th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($t = $tasks->fetch_assoc()):
                    $sc = 'badge-warning';
                    if ($t['status'] === 'completed') $sc = 'badge-success';
                    elseif ($t['status'] === 'in-progress') $sc = 'badge-info';
                    elseif ($t['status'] === 'under-review') $sc = 'badge-primary';
                    elseif ($t['status'] === 'cancelled') $sc = 'badge-danger';

                    $desc_short = $t['description'] ? (strlen($t['description']) > 60 ? substr($t['description'], 0, 60) . '…' : $t['description']) : '—';
                ?>
                    <tr style="<?php echo $t['status'] === 'under-review' ? 'background-color:rgba(156,39,176,0.05);' : ''; ?>">
                        <td style="color:var(--text-muted);">#<?php echo $t['task_id']; ?></td>
                        <td><span class="badge badge-orange"><?php echo htmlspecialchars($t['task_type']); ?></span></td>
                        <td style="max-width:240px; font-size:0.85rem;">
                            <div title="<?php echo htmlspecialchars($t['description'] ?? ''); ?>"><?php echo htmlspecialchars($desc_short); ?></div>
                            <?php if ($t['status'] === 'under-review' && !empty($t['submission_note'])): ?>
                                <div style="margin-top:8px; padding:6px; background:#fff; border-left:2px solid var(--primary); border-radius:4px; font-size:0.8rem; color:var(--text-secondary);">
                                    <strong>Proof:</strong> <?php echo htmlspecialchars($t['submission_note']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:0.85rem;"><?php echo htmlspecialchars($t['location'] ?? '—'); ?></td>
                        <td><strong style="color:var(--success);">+<?php echo number_format($t['credit_reward'], 2); ?> TC</strong></td>
                        <td>
                            <?php if ($t['assigned_user']): ?>
                                <span><?php echo htmlspecialchars($t['assigned_user']); ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted); font-style:italic;">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $sc; ?>">
                                <?php 
                                if ($t['status'] === 'under-review') echo 'Review';
                                else echo ucfirst($t['status']); 
                                ?>
                            </span>
                        </td>
                        <td style="font-size:0.82rem; color:var(--text-muted);"><?php echo date('M d, Y', strtotime($t['assigned_at'])); ?></td>
                        <td>
                            <div class="flex gap-1" style="flex-wrap:nowrap;">
                                
                                <?php if ($t['status'] === 'under-review'): ?>
                                    <!-- Approve -->
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="approve_task">
                                        <input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success" title="Approve Task and Award Credits"
                                            onclick="return confirm('Approve this task? This will award <?php echo $t['credit_reward']; ?> TC to <?php echo htmlspecialchars($t['assigned_user']); ?>.')"><i data-lucide="check-circle" class="lucide-sm"></i></button>
                                    </form>
                                    <!-- Reject -->
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="reject_task">
                                        <input type="hidden" name="task_id" value="<?php echo $t['task_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" title="Reject Task"
                                            onclick="return confirm('Reject this submission? The task will be returned to the pending pool.')">&#x274C;</button>
                                    </form>
                                <?php else: ?>
                                
                                    <!-- Edit -->
                                    <button type="button" class="btn btn-sm btn-secondary" title="Edit Task"
                                        onclick="openEditTask(<?php echo $t['task_id']; ?>, '<?php echo addslashes(htmlspecialchars($t['task_type'])); ?>', '<?php echo addslashes(htmlspecialchars($t['description'] ?? '')); ?>', '<?php echo addslashes(htmlspecialchars($t['location'] ?? '')); ?>', <?php echo $t['credit_reward']; ?>, '<?php echo $t['status']; ?>')">
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
                                <?php endif; ?>
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
                    <label>Credit Reward (TC)</label>
                    <input type="number" name="credit_reward" class="form-control" value="5.00" min="1" max="50" step="0.50">
                    <small style="color:var(--text-muted); font-size:0.72rem;">Time credits rewarded on completion (1 – 50 TC)</small>
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
                        <option value="under-review">Under Review</option>
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
                    <label>Credit Reward (TC)</label>
                    <input type="number" name="credit_reward" id="edit-task-reward" class="form-control" min="1" max="50" step="0.50">
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
function openEditTask(id, type, desc, location, reward, status) {
    document.getElementById('edit-task-id').value = id;
    document.getElementById('edit-task-type').value = type;
    document.getElementById('edit-task-desc').value = desc;
    document.getElementById('edit-task-location').value = location;
    document.getElementById('edit-task-reward').value = reward;
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
