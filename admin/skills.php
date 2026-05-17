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

    if ($_POST['action'] === 'add_category') {
        $name = trim($_POST['category_name'] ?? '');
        if ($name) {
            $stmt = $conn->prepare("INSERT INTO categories (category_name) VALUES (?)");
            $stmt->bind_param("s", $name);
            if ($stmt->execute()) {
                $success = 'Category added.';
            } else {
                $error = 'Category already exists or error occurred.';
            }
            $stmt->close();
        }
    }

    if ($_POST['action'] === 'add_skill') {
        $name = trim($_POST['skill_name'] ?? '');
        $cat_id = intval($_POST['category_id'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        $diff = $_POST['difficulty_level'] ?? 'Beginner';
        $dur = intval($_POST['base_duration'] ?? 60);

        if ($name) {
            $stmt = $conn->prepare("INSERT INTO skills (skill_name, category_id, description, difficulty_level, base_duration) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sissi", $name, $cat_id, $desc, $diff, $dur);
            if ($stmt->execute()) {
                $success = 'Skill added.';
            } else {
                $error = 'Failed to add skill.';
            }
            $stmt->close();
        }
    }

    if ($_POST['action'] === 'edit_skill') {
        $sid = intval($_POST['skill_id'] ?? 0);
        $name = trim($_POST['skill_name'] ?? '');
        $cat_id = intval($_POST['category_id'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        $diff = $_POST['difficulty_level'] ?? 'Beginner';

        $stmt = $conn->prepare("UPDATE skills SET skill_name = ?, category_id = ?, description = ?, difficulty_level = ? WHERE skill_id = ?");
        $stmt->bind_param("sissi", $name, $cat_id, $desc, $diff, $sid);
        if ($stmt->execute()) {
            $success = 'Skill updated.';
        } else {
            $error = 'Failed to update skill.';
        }
        $stmt->close();
    }

    if ($_POST['action'] === 'delete_skill') {
        $sid = intval($_POST['skill_id'] ?? 0);
        $conn->query("DELETE FROM skills WHERE skill_id = $sid");
        $success = 'Skill deleted.';
    }
}

// Fetch data
$categories = $conn->query("SELECT * FROM categories ORDER BY category_name");
$skills = $conn->query("
    SELECT s.*, c.category_name, COUNT(DISTINCT uso.user_id) AS providers
    FROM skills s
    LEFT JOIN categories c ON s.category_id = c.category_id
    LEFT JOIN user_skills_offered uso ON s.skill_id = uso.skill_id
    GROUP BY s.skill_id
    ORDER BY c.category_name, s.skill_name
");

include __DIR__ . '/../includes/admin_header.php';
?>

<h1 class="page-title">Skill Classification</h1>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="grid-2 mb-3">
    <!-- Add Category -->
    <div class="card">
        <h3 class="section-title">Add Category</h3>
        <form method="POST" class="flex gap-1 items-center">
            <input type="hidden" name="action" value="add_category">
            <input type="text" name="category_name" class="form-control" placeholder="Category name..." required>
            <button type="submit" class="btn btn-primary btn-sm">Add</button>
        </form>

        <div class="mt-2">
            <h4 style="font-size:0.85rem; color:var(--text-muted); margin-bottom:8px;">Existing Categories:</h4>
            <?php
            $categories->data_seek(0);
            while ($c = $categories->fetch_assoc()): ?>
                <span class="skill-tag"><?php echo htmlspecialchars($c['category_name']); ?></span>
            <?php endwhile; ?>
        </div>
    </div>

    <!-- Add Skill -->
    <div class="card">
        <h3 class="section-title">Add Skill</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_skill">
            <div class="form-group">
                <label>Skill Name</label>
                <input type="text" name="skill_name" class="form-control" placeholder="e.g. React.js" required>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id" class="form-control" required>
                        <?php
                        $categories->data_seek(0);
                        while ($c = $categories->fetch_assoc()): ?>
                            <option value="<?php echo $c['category_id']; ?>">
                                <?php echo htmlspecialchars($c['category_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
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
                <textarea name="description" class="form-control" placeholder="Brief description..."></textarea>
            </div>
            <div class="form-group">
                <label>Base Duration (min)</label>
                <input type="number" name="base_duration" class="form-control" value="60" min="15" max="240">
            </div>
            <button type="submit" class="btn btn-primary">Add Skill</button>
        </form>
    </div>
</div>

<!-- Skills Table -->
<div class="card">
    <div class="card-header">
        <h3>All Skills (<?php echo $skills->num_rows; ?>)</h3>
    </div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Skill</th>
                    <th>Category</th>
                    <th>Difficulty</th>
                    <th>Duration</th>
                    <th>Providers</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($s = $skills->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $s['skill_id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($s['skill_name']); ?></strong></td>
                        <td><span
                                class="badge badge-orange"><?php echo htmlspecialchars($s['category_name'] ?? 'N/A'); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($s['difficulty_level']); ?></td>
                        <td><?php echo $s['base_duration']; ?> min</td>
                        <td><?php echo $s['providers']; ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="delete_skill">
                                <input type="hidden" name="skill_id" value="<?php echo $s['skill_id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                    onclick="return confirm('Delete this skill?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/admin_footer.php'; ?>