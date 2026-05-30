<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'Skill Taxonomy';
$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_skill') {
        $name = trim($_POST['skill_name'] ?? '');
        $cat = trim($_POST['catagory'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $diff = $_POST['difficulty_level'] ?? 'Beginner';

        if ($name) {
            // Check for duplicate
            $check = $conn->prepare("SELECT skill_id FROM skills WHERE skill_name = ?");
            $check->bind_param("s", $name);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $error = "A skill named \"$name\" already exists.";
            } else {
                $stmt = $conn->prepare("INSERT INTO skills (skill_name, catagory, description, difficulty_level) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $name, $cat, $desc, $diff);
                if ($stmt->execute()) {
                    $success = "\"$name\" added successfully!";
                } else {
                    $error = 'Failed to add skill.';
                }
                $stmt->close();
            }
            $check->close();
        } else {
            $error = 'Skill name is required.';
        }
    }

    if ($_POST['action'] === 'edit_skill') {
        $sid = intval($_POST['skill_id'] ?? 0);
        $name = trim($_POST['skill_name'] ?? '');
        $cat = trim($_POST['catagory'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $diff = $_POST['difficulty_level'] ?? 'Beginner';

        if ($name && $sid > 0) {
            $stmt = $conn->prepare("UPDATE skills SET skill_name = ?, catagory = ?, description = ?, difficulty_level = ? WHERE skill_id = ?");
            $stmt->bind_param("ssssi", $name, $cat, $desc, $diff, $sid);
            if ($stmt->execute()) {
                $success = "\"$name\" updated successfully.";
            } else {
                $error = 'Failed to update skill.';
            }
            $stmt->close();
        }
    }

    if ($_POST['action'] === 'delete_skill') {
        $sid = intval($_POST['skill_id'] ?? 0);
        // Check if skill is in use
        $in_use = $conn->query("SELECT COUNT(*) AS cnt FROM exchange_sessions WHERE skill_id = $sid")->fetch_assoc()['cnt'];
        if ($in_use > 0) {
            $error = "Cannot delete — this skill is used in $in_use session(s). Remove those first.";
        } else {
            $conn->query("DELETE FROM user_skills_offered WHERE skill_id = $sid");
            $conn->query("DELETE FROM user_skills_requested WHERE skill_id = $sid");
            $conn->query("DELETE FROM skills WHERE skill_id = $sid");
            $success = 'Skill deleted.';
        }
    }
}

// Fetch existing categories
$categories_q = $conn->query("SELECT DISTINCT catagory FROM skills WHERE catagory IS NOT NULL AND catagory != '' ORDER BY catagory");
$categories_arr = [];
while ($c = $categories_q->fetch_assoc()) {
    $categories_arr[] = $c['catagory'];
}

// Search & Filters
$search = trim($_GET['search'] ?? '');
$cat_filter = trim($_GET['category'] ?? '');
$diff_filter = trim($_GET['difficulty'] ?? '');

// Sorting parameters
$sort = trim($_GET['sort'] ?? 'category');
$order = trim($_GET['order'] ?? 'asc');

$allowed_sorts = [
    'id' => 's.skill_id',
    'name' => 's.skill_name',
    'category' => 's.catagory',
    'difficulty' => 's.difficulty_level',
    'teachers' => 'providers',
    'learners' => 'learners'
];

$sort_col = $allowed_sorts[$sort] ?? 's.catagory';
$order = (strtolower($order) === 'desc') ? 'DESC' : 'ASC';

if ($sort === 'category') {
    $sort_query = "s.catagory $order, s.skill_name ASC";
} elseif ($sort === 'difficulty') {
    $sort_query = "FIELD(s.difficulty_level, 'Beginner', 'Intermediate', 'Advanced') $order";
} else {
    $sort_query = "$sort_col $order";
}

// Sort URL generator
function getSortUrl($col, $dir, $search, $category, $difficulty) {
    $query = [
        'sort' => $col,
        'order' => $dir
    ];
    if ($search !== '') $query['search'] = $search;
    if ($category !== '') $query['category'] = $category;
    if ($difficulty !== '') $query['difficulty'] = $difficulty;
    return 'skills.php?' . http_build_query($query);
}

// Sort Buttons generator (single toggle button beside column header)
function renderSortButtons($col, $current_sort, $current_order, $search, $category, $difficulty) {
    if ($current_sort === $col) {
        if (strtolower($current_order) === 'asc') {
            $url = getSortUrl($col, 'desc', $search, $category, $difficulty);
            return '
            <span class="sort-arrows">
                <a href="' . $url . '" class="sort-arrow active" title="Sorted Ascending. Click to sort Descending">&#x25B4;</a>
            </span>';
        } else {
            $url = getSortUrl($col, 'asc', $search, $category, $difficulty);
            return '
            <span class="sort-arrows">
                <a href="' . $url . '" class="sort-arrow active" title="Sorted Descending. Click to sort Ascending">&#x25BE;</a>
            </span>';
        }
    } else {
        $url = getSortUrl($col, 'asc', $search, $category, $difficulty);
        return '
        <span class="sort-arrows">
            <a href="' . $url . '" class="sort-arrow" title="Click to sort Ascending">&#x25B4;</a>
        </span>';
    }
}

$where = "WHERE 1=1";
if ($search !== '') {
    $search_esc = $conn->real_escape_string($search);
    $where .= " AND (s.skill_name LIKE '%$search_esc%' OR s.description LIKE '%$search_esc%')";
}
if ($cat_filter !== '') {
    $cat_esc = $conn->real_escape_string($cat_filter);
    $where .= " AND s.catagory = '$cat_esc'";
}
if ($diff_filter !== '' && in_array($diff_filter, ['Beginner', 'Intermediate', 'Advanced'])) {
    $where .= " AND s.difficulty_level = '$diff_filter'";
}

// Fetch skills with filters
$skills = $conn->query("
    SELECT s.*, COUNT(DISTINCT uso.user_id) AS providers,
           COUNT(DISTINCT usr.user_id) AS learners
    FROM skills s
    LEFT JOIN user_skills_offered uso ON s.skill_id = uso.skill_id
    LEFT JOIN user_skills_requested usr ON s.skill_id = usr.skill_id
    $where
    GROUP BY s.skill_id
    ORDER BY $sort_query
");

// Summary stats
$total_skills = $conn->query("SELECT COUNT(*) AS cnt FROM skills")->fetch_assoc()['cnt'];
$count_beginner = $conn->query("SELECT COUNT(*) AS cnt FROM skills WHERE difficulty_level = 'Beginner'")->fetch_assoc()['cnt'];
$count_intermediate = $conn->query("SELECT COUNT(*) AS cnt FROM skills WHERE difficulty_level = 'Intermediate'")->fetch_assoc()['cnt'];
$count_advanced = $conn->query("SELECT COUNT(*) AS cnt FROM skills WHERE difficulty_level = 'Advanced'")->fetch_assoc()['cnt'];

// Category counts
$cat_counts = $conn->query("SELECT catagory, COUNT(*) AS cnt FROM skills WHERE catagory IS NOT NULL AND catagory != '' GROUP BY catagory ORDER BY cnt DESC");
$cat_count_arr = [];
while ($cc = $cat_counts->fetch_assoc()) {
    $cat_count_arr[$cc['catagory']] = $cc['cnt'];
}

include __DIR__ . '/../includes/admin_header.php';
?>

<div class="page-header">
    <h1 class="page-title" style="margin:0;"><i data-lucide="star" class="lucide-sm"></i> Skill Classification</h1>
    <button class="btn btn-primary" onclick="document.getElementById('add-skill-modal').classList.add('active')">+ Add New Skill</button>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Summary Stats -->
<div class="stats-grid" style="margin-bottom:16px;">
    <div class="stat-card stat-card-accent" style="--accent: var(--primary);">
        <span class="stat-value"><?php echo $total_skills; ?></span>
        <span class="stat-label">Total Skills</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--success);">
        <span class="stat-value"><?php echo $count_beginner; ?></span>
        <span class="stat-label">Beginner</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--warning);">
        <span class="stat-value"><?php echo $count_intermediate; ?></span>
        <span class="stat-label">Intermediate</span>
    </div>
    <div class="stat-card stat-card-accent" style="--accent: var(--danger);">
        <span class="stat-value"><?php echo $count_advanced; ?></span>
        <span class="stat-label">Advanced</span>
    </div>
</div>

<!-- Categories Overview -->
<div class="card mb-3">
    <div class="card-header">
        <h3>Categories</h3>
        <span style="font-size:0.82rem; color:var(--text-muted);"><?php echo count($cat_count_arr); ?> categories</span>
    </div>
    <div style="display:flex; flex-wrap:wrap; gap:8px;">
        <?php foreach ($cat_count_arr as $cat_name => $cat_cnt): ?>
            <a href="?category=<?php echo urlencode($cat_name); ?><?php echo $sort !== 'category' ? '&sort=' . urlencode($sort) : ''; ?><?php echo $order !== 'ASC' ? '&order=' . urlencode(strtolower($order)) : ''; ?>"
               class="skill-tag <?php echo $cat_filter === $cat_name ? 'skill-tag-active' : ''; ?>"
               style="cursor:pointer; text-decoration:none;">
                <?php echo htmlspecialchars($cat_name); ?>
                <span style="background:rgba(0,56,108,0.12); padding:1px 7px; border-radius:9999px; font-size:0.7rem; margin-left:4px;">
                    <?php echo $cat_cnt; ?>
                </span>
            </a>
        <?php endforeach; ?>
        <?php if (empty($cat_count_arr)): ?>
            <p style="color:var(--text-muted); font-size:0.85rem;">No categories yet. Add a skill to create one.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="card mb-3">
    <form method="GET" class="admin-filter-bar">
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
        <input type="hidden" name="order" value="<?php echo htmlspecialchars(strtolower($order)); ?>">
        <div class="admin-search-box">
            <input type="text" name="search" class="form-control" placeholder="&#x1F50D; Search skills by name or description..."
                value="<?php echo htmlspecialchars($search); ?>">
        </div>
        <select name="category" class="form-control" style="max-width:180px;">
            <option value="">All Categories</option>
            <?php foreach ($categories_arr as $cat): ?>
                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $cat_filter === $cat ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="admin-filter-tabs">
            <?php
            function buildTabUrl($diff, $search, $cat, $sort, $order) {
                $q = [];
                if ($diff !== '') $q['difficulty'] = $diff;
                if ($search !== '') $q['search'] = $search;
                if ($cat !== '') $q['category'] = $cat;
                if ($sort !== '') $q['sort'] = $sort;
                if ($order !== '') $q['order'] = strtolower($order);
                return '?' . http_build_query($q);
            }
            ?>
            <a href="<?php echo buildTabUrl('', $search, $cat_filter, $sort, $order); ?>"
               class="filter-tab <?php echo $diff_filter === '' ? 'active' : ''; ?>">All</a>
            <a href="<?php echo buildTabUrl('Beginner', $search, $cat_filter, $sort, $order); ?>"
               class="filter-tab <?php echo $diff_filter === 'Beginner' ? 'active' : ''; ?>" style="<?php echo $diff_filter === 'Beginner' ? '' : 'color:var(--success);'; ?>">Beginner</a>
            <a href="<?php echo buildTabUrl('Intermediate', $search, $cat_filter, $sort, $order); ?>"
               class="filter-tab <?php echo $diff_filter === 'Intermediate' ? 'active' : ''; ?>" style="<?php echo $diff_filter === 'Intermediate' ? '' : 'color:var(--warning);'; ?>">Intermediate</a>
            <a href="<?php echo buildTabUrl('Advanced', $search, $cat_filter, $sort, $order); ?>"
               class="filter-tab <?php echo $diff_filter === 'Advanced' ? 'active' : ''; ?>" style="<?php echo $diff_filter === 'Advanced' ? '' : 'color:var(--danger);'; ?>">Advanced</a>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
        <?php if ($search || $cat_filter || $diff_filter || $sort !== 'category' || $order !== 'ASC'): ?>
            <a href="skills.php" class="btn btn-sm btn-secondary">Clear All</a>
        <?php endif; ?>
    </form>
</div>

<!-- Skills Table -->
<div class="card">
    <div class="card-header">
        <h3>Showing <?php echo $skills->num_rows; ?> skill(s)</h3>
    </div>
    <?php if ($skills->num_rows > 0): ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>
                        <span class="th-content">
                            <span>ID</span>
                            <?php echo renderSortButtons('id', $sort, $order, $search, $cat_filter, $diff_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Skill Name</span>
                            <?php echo renderSortButtons('name', $sort, $order, $search, $cat_filter, $diff_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Category</span>
                            <?php echo renderSortButtons('category', $sort, $order, $search, $cat_filter, $diff_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span>Difficulty</span>
                            <?php echo renderSortButtons('difficulty', $sort, $order, $search, $cat_filter, $diff_filter); ?>
                        </span>
                    </th>
                    <th>Description</th>
                    <th>
                        <span class="th-content">
                            <span title="Users who teach this skill">Teachers</span>
                            <?php echo renderSortButtons('teachers', $sort, $order, $search, $cat_filter, $diff_filter); ?>
                        </span>
                    </th>
                    <th>
                        <span class="th-content">
                            <span title="Users who want to learn this skill">Learners</span>
                            <?php echo renderSortButtons('learners', $sort, $order, $search, $cat_filter, $diff_filter); ?>
                        </span>
                    </th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($s = $skills->fetch_assoc()):
                    // Difficulty color
                    $diff_class = 'badge-success';
                    if ($s['difficulty_level'] === 'Intermediate') $diff_class = 'badge-warning';
                    elseif ($s['difficulty_level'] === 'Advanced') $diff_class = 'badge-danger';

                    $desc_short = $s['description'] ? (strlen($s['description']) > 50 ? substr($s['description'], 0, 50) . '…' : $s['description']) : '—';
                ?>
                    <tr>
                        <td style="color:var(--text-muted);">#<?php echo $s['skill_id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($s['skill_name']); ?></strong></td>
                        <td>
                            <a href="?category=<?php echo urlencode($s['catagory'] ?? ''); ?><?php echo $sort !== 'category' ? '&sort=' . urlencode($sort) : ''; ?><?php echo $order !== 'ASC' ? '&order=' . urlencode(strtolower($order)) : ''; ?>" class="badge badge-orange" style="text-decoration:none;">
                                <?php echo htmlspecialchars($s['catagory'] ?? 'N/A'); ?>
                            </a>
                        </td>
                        <td><span class="badge <?php echo $diff_class; ?>"><?php echo $s['difficulty_level']; ?></span></td>
                        <td style="max-width:200px; font-size:0.82rem; color:var(--text-muted);"
                            title="<?php echo htmlspecialchars($s['description'] ?? ''); ?>">
                            <?php echo htmlspecialchars($desc_short); ?>
                        </td>
                        <td>
                            <?php if ($s['providers'] > 0): ?>
                                <span style="color:var(--success); font-weight:600;"><?php echo $s['providers']; ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s['learners'] > 0): ?>
                                <span style="color:var(--info); font-weight:600;"><?php echo $s['learners']; ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="flex gap-1" style="flex-wrap:nowrap;">
                                <!-- Edit Button -->
                                <button type="button" class="btn btn-sm btn-secondary" title="Edit Skill"
                                    onclick="openEditModal(<?php echo $s['skill_id']; ?>, '<?php echo addslashes(htmlspecialchars($s['skill_name'])); ?>', '<?php echo addslashes(htmlspecialchars($s['catagory'] ?? '')); ?>', '<?php echo addslashes(htmlspecialchars($s['description'] ?? '')); ?>', '<?php echo $s['difficulty_level']; ?>')">
                                    &#x270E;
                                </button>
                                <!-- Delete -->
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_skill">
                                    <input type="hidden" name="skill_id" value="<?php echo $s['skill_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete Skill"
                                        onclick="return confirm('Delete &quot;<?php echo addslashes(htmlspecialchars($s['skill_name'])); ?>&quot;? This cannot be undone.')">
                                        &#x1F5D1;
                                    </button>
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
            <div class="icon">&#x1F50D;</div>
            <p>No skills match your filters.</p>
            <a href="skills.php" class="btn btn-sm btn-secondary mt-2">Clear Filters</a>
        </div>
    <?php endif; ?>
</div>

<!-- ========== ADD SKILL MODAL ========== -->
<div class="modal-overlay" id="add-skill-modal">
    <div class="modal">
        <div class="modal-header">
            <h3>Add New Skill</h3>
            <button class="modal-close" onclick="document.getElementById('add-skill-modal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_skill">
            <div class="form-group">
                <label>Skill Name *</label>
                <input type="text" name="skill_name" class="form-control" placeholder="e.g. React.js, Public Speaking" required>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Category</label>
                    <input type="hidden" name="catagory" id="add-cat-value">
                    <select id="add-cat-select" class="form-control" onchange="toggleNewCategory('add')">
                        <option value="">Select category...</option>
                        <?php foreach ($categories_arr as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                        <option value="__new__">+ New Category</option>
                    </select>
                    <input type="text" id="add-cat-new" class="form-control mt-1" placeholder="Type new category name..." style="display:none;">
                </div>
                <div class="form-group">
                    <label>Difficulty</label>
                    <select name="difficulty_level" class="form-control">
                        <option value="Beginner">Beginner</option>
                        <option value="Intermediate">Intermediate</option>
                        <option value="Advanced">Advanced</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Brief description of the skill..."></textarea>
            </div>
            <div class="flex gap-1" style="justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('add-skill-modal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Skill</button>
            </div>
        </form>
    </div>
</div>

<!-- ========== EDIT SKILL MODAL ========== -->
<div class="modal-overlay" id="edit-skill-modal">
    <div class="modal">
        <div class="modal-header">
            <h3>Edit Skill</h3>
            <button class="modal-close" onclick="document.getElementById('edit-skill-modal').classList.remove('active')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_skill">
            <input type="hidden" name="skill_id" id="edit-skill-id">
            <div class="form-group">
                <label>Skill Name *</label>
                <input type="text" name="skill_name" id="edit-skill-name" class="form-control" required>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Category</label>
                    <input type="hidden" name="catagory" id="edit-cat-value">
                    <select id="edit-cat-select" class="form-control" onchange="toggleNewCategory('edit')">
                        <option value="">Select category...</option>
                        <?php foreach ($categories_arr as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                        <option value="__new__">+ New Category</option>
                    </select>
                    <input type="text" id="edit-cat-new" class="form-control mt-1" placeholder="Type new category name..." style="display:none;">
                </div>
                <div class="form-group">
                    <label>Difficulty</label>
                    <select name="difficulty_level" id="edit-skill-difficulty" class="form-control">
                        <option value="Beginner">Beginner</option>
                        <option value="Intermediate">Intermediate</option>
                        <option value="Advanced">Advanced</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="edit-skill-description" class="form-control" rows="3"></textarea>
            </div>
            <div class="flex gap-1" style="justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('edit-skill-modal').classList.remove('active')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// Category dropdown toggle
function toggleNewCategory(prefix) {
    var select = document.getElementById(prefix + '-cat-select');
    var newInput = document.getElementById(prefix + '-cat-new');
    var hidden = document.getElementById(prefix + '-cat-value');

    if (select.value === '__new__') {
        newInput.style.display = 'block';
        newInput.focus();
        hidden.value = '';
    } else {
        newInput.style.display = 'none';
        newInput.value = '';
        hidden.value = select.value;
    }
}

// Sync new category text to hidden field on typing
document.addEventListener('input', function(e) {
    if (e.target.id === 'add-cat-new') {
        document.getElementById('add-cat-value').value = e.target.value;
    }
    if (e.target.id === 'edit-cat-new') {
        document.getElementById('edit-cat-value').value = e.target.value;
    }
});

// Sync add modal select on form open
document.getElementById('add-cat-select').addEventListener('change', function() {
    toggleNewCategory('add');
});

// Edit modal opener
function openEditModal(id, name, category, description, difficulty) {
    document.getElementById('edit-skill-id').value = id;
    document.getElementById('edit-skill-name').value = name;
    document.getElementById('edit-skill-description').value = description;
    document.getElementById('edit-skill-difficulty').value = difficulty;

    // Set category: try to match an existing option, otherwise show new input
    var select = document.getElementById('edit-cat-select');
    var newInput = document.getElementById('edit-cat-new');
    var hidden = document.getElementById('edit-cat-value');
    var found = false;

    for (var i = 0; i < select.options.length; i++) {
        if (select.options[i].value === category) {
            select.value = category;
            hidden.value = category;
            newInput.style.display = 'none';
            newInput.value = '';
            found = true;
            break;
        }
    }
    if (!found && category) {
        select.value = '__new__';
        newInput.style.display = 'block';
        newInput.value = category;
        hidden.value = category;
    }

    document.getElementById('edit-skill-modal').classList.add('active');
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            overlay.classList.remove('active');
        }
    });
});

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(function(m) {
            m.classList.remove('active');
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>