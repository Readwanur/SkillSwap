<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'Skills Marketplace';

// Filter
$category_filter = $_GET['category'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = "1=1";
if ($category_filter !== '') {
    $escaped_cat = $conn->real_escape_string($category_filter);
    $where .= " AND s.catagory = '$escaped_cat'";
}
if ($search !== '') {
    $escaped = $conn->real_escape_string($search);
    $where .= " AND (s.skill_name LIKE '%$escaped%' OR s.description LIKE '%$escaped%')";
}

// Fetch categories
$categories = $conn->query("SELECT DISTINCT catagory FROM skills WHERE catagory IS NOT NULL ORDER BY catagory");

// --- VIEW: vw_skill_marketplace ---
// Fetch skills with provider count, rating, and session stats using view
$skills = $conn->query("
    SELECT *
    FROM vw_skill_marketplace s
    WHERE $where
    ORDER BY s.skill_name
");

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <div class="page-header">
            <h1 class="page-title" style="margin-bottom:0;">Skills Marketplace</h1>
        </div>

        <!-- Search & Filter -->
        <div class="card mb-3">
            <form method="GET" class="flex flex-wrap gap-1 items-center">
                <input type="text" name="search" class="form-control" style="max-width:300px;"
                    placeholder="Search skills..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="category" class="form-control" style="max-width:200px;">
                    <option value="">All Categories</option>
                    <?php if ($categories): ?>
                        <?php while ($c = $categories->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($c['catagory']); ?>" <?php echo ($category_filter === $c['catagory']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['catagory']); ?>
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
                <a href="../pages/skills.php" class="btn btn-secondary btn-sm">Clear</a>
            </form>
        </div>

        <!-- Skills Grid -->
        <?php if ($skills && $skills->num_rows > 0): ?>
            <div class="grid-3">
                <?php while ($skills && $skill = $skills->fetch_assoc()): ?>
                    <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <span
                                    class="badge badge-orange"><?php echo htmlspecialchars($skill['catagory'] ?? 'General'); ?></span>
                                <span class="badge badge-info"><?php echo htmlspecialchars($skill['difficulty_level']); ?></span>
                            </div>
                            <h3 style="margin-top:8px; margin-bottom:2px;"><?php echo htmlspecialchars($skill['skill_name']); ?></h3>
                            
                            <div style="font-size:0.8rem; margin-bottom: 8px;">
                                <?php if ($skill['avg_skill_rating'] !== null): ?>
                                    <span style="color: var(--secondary); font-weight:600;">⭐ <?php echo number_format($skill['avg_skill_rating'], 1); ?></span>
                                    <span style="color: var(--text-muted); opacity: 0.8;">(<?php echo $skill['total_sessions']; ?> session<?php echo $skill['total_sessions'] == 1 ? '' : 's'; ?>)</span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-style:italic; font-size:0.75rem;">No reviews yet</span>
                                <?php endif; ?>
                            </div>

                            <p style="color:var(--text-secondary); font-size:0.85rem; margin: 8px 0;">
                                <?php echo htmlspecialchars($skill['description'] ?? 'No description'); ?></p>
                        </div>
                        <div class="flex justify-between items-center mt-2" style="border-top: 1px solid var(--border-light); padding-top: 10px;">
                            <span style="color:var(--text-muted); font-size:0.8rem;"><?php echo $skill['provider_count']; ?> provider(s)</span>
                            <a href="../pages/skill_detail.php?id=<?php echo $skill['skill_id']; ?>"
                                class="btn btn-sm btn-primary">View Providers</a>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="empty-state">
                    <div class="icon">&#128270;</div>
                    <p>No skills found matching your criteria.</p>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>