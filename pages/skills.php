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

// Pagination setup
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;
$limit = 9; // Display 9 skills per page for a nice 3x3 grid
$offset = ($page - 1) * $limit;

$count_query = "SELECT COUNT(*) as total FROM vw_skill_marketplace s WHERE $where";
$total_records = $conn->query($count_query)->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

// Fetch categories
$categories = $conn->query("SELECT DISTINCT catagory FROM skills WHERE catagory IS NOT NULL ORDER BY catagory");

// --- VIEW: vw_skill_marketplace ---
// Fetch skills with provider count, rating, and session stats using view
$skills = $conn->query("
    SELECT *
    FROM vw_skill_marketplace s
    WHERE $where
    ORDER BY s.skill_name
    LIMIT $limit OFFSET $offset
");

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <div class="page-header">
            <h1 class="page-title" style="margin-bottom:0;">Skills Marketplace</h1>
        </div>

        <!-- Search & Filter -->
        <div class="card mb-3" style="padding: 16px 24px; position: relative; z-index: 10;">
            <form method="GET" class="flex flex-wrap gap-2 items-center" id="skillsSearchForm">
                <div style="position:relative; display:flex; align-items:center;">
                    <i data-lucide="search" style="position:absolute; left:14px; color:var(--text-muted); width:16px; height:16px; z-index:2; pointer-events:none;"></i>
                    <input type="text" name="search" id="skillsSearchInput" placeholder="Search skills..." autocomplete="off"
                        value="<?php echo htmlspecialchars($search); ?>">
                    <div id="skillsSearchSuggestions">
                    </div>
                </div>
                <select name="category" class="form-control" style="width:200px; border-radius: 99px; padding-left: 16px;">
                    <option value="">All Categories</option>
                    <?php if ($categories): ?>
                        <?php while ($c = $categories->fetch_assoc()): ?>
                            <option value="<?php echo htmlspecialchars($c['catagory']); ?>" <?php echo ($category_filter === $c['catagory']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['catagory']); ?>
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>
                <button type="submit" class="btn btn-primary" style="border-radius: 99px; padding: 10px 24px;">Search</button>
                <a href="../pages/skills.php" class="btn btn-secondary" style="border-radius: 99px; padding: 10px 24px;">Clear</a>
            </form>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const searchInput = document.getElementById('skillsSearchInput');
                const suggestionsBox = document.getElementById('skillsSearchSuggestions');
                let timeout = null;

                if (searchInput) {
                    searchInput.addEventListener('input', function() {
                        clearTimeout(timeout);
                        const query = this.value.trim();
                        
                        if (query.length < 2) {
                            suggestionsBox.style.opacity = '0';
                            suggestionsBox.style.transform = 'translateY(-10px)';
                            setTimeout(() => { if (searchInput.value.trim().length < 2) suggestionsBox.style.display = 'none'; }, 300);
                            return;
                        }

                        timeout = setTimeout(() => {
                            fetch(`../api/search_skills.php?q=${encodeURIComponent(query)}`)
                                .then(res => res.json())
                                .then(data => {
                                    if (data.length > 0) {
                                        let html = '<div style="padding: 10px 16px; font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border-light);">Suggested Skills</div>';
                                        data.forEach(skill => {
                                            html += `
                                                <a href="../pages/skill_detail.php?id=${skill.id}" style="display: flex; align-items: center; gap: 14px; padding: 12px 16px; text-decoration: none; border-bottom: 1px solid var(--border-light); transition: background 0.2s ease;" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background='transparent'">
                                                    <div style="width: 36px; height: 36px; border-radius: 8px; background: rgba(0, 56, 108, 0.06); color: var(--primary); display: flex; align-items: center; justify-content: center; border: 1px solid rgba(0, 56, 108, 0.1); flex-shrink: 0;"><i data-lucide="book-open" class="lucide-sm"></i></div>
                                                    <div style="flex: 1; min-width: 0;">
                                                        <div style="font-weight: 600; font-size: 0.95rem; color: var(--text-primary); margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${skill.name}</div>
                                                        <div style="font-size: 0.8rem; color: var(--text-muted); display: flex; align-items: center; gap: 8px;">
                                                            <span class="badge" style="font-size:0.65rem; background:rgba(0,56,108,0.08); color:var(--primary); padding: 2px 6px;">${skill.category}</span>
                                                            <span class="badge" style="font-size:0.65rem; background:rgba(47,95,156,0.1); color:var(--info); padding: 2px 6px;">${skill.difficulty}</span>
                                                        </div>
                                                    </div>
                                                </a>
                                            `;
                                        });
                                        suggestionsBox.innerHTML = html;
                                    } else {
                                        suggestionsBox.innerHTML = '<div style="padding: 24px 16px; text-align: center; color: var(--text-muted);"><i data-lucide="search-x" style="width: 32px; height: 32px; opacity: 0.5; margin-bottom: 12px;"></i><br><span style="font-size:0.95rem; font-weight:500;">No skills found</span><br><span style="font-size:0.8rem;">Try a different keyword</span></div>';
                                    }
                                    
                                    if (window.lucide) lucide.createIcons();
                                    suggestionsBox.style.display = 'block';
                                    // Trigger reflow
                                    void suggestionsBox.offsetWidth;
                                    suggestionsBox.style.opacity = '1';
                                    suggestionsBox.style.transform = 'translateY(0)';
                                })
                                .catch(err => console.error(err));
                        }, 250);
                    });

                    document.addEventListener('click', function(e) {
                        if (!document.getElementById('skillsSearchForm').contains(e.target)) {
                            suggestionsBox.style.opacity = '0';
                            suggestionsBox.style.transform = 'translateY(-10px)';
                            setTimeout(() => { suggestionsBox.style.display = 'none'; }, 300);
                        }
                    });
                }
            });
        </script>

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
                                    <span style="color: var(--secondary); font-weight:600;"><i data-lucide="star" class="lucide-sm"></i> <?php echo number_format($skill['avg_skill_rating'], 1); ?></span>
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

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 20px 0; margin-top: 20px; border-top: 1px solid var(--border-light);">
                    <div style="font-size: 0.85rem; color: var(--text-muted);">
                        Showing <strong><?php echo $offset + 1; ?></strong> to <strong><?php echo min($offset + $limit, $total_records); ?></strong> of <strong><?php echo $total_records; ?></strong> skills
                    </div>
                    <div style="display: flex; gap: 5px;">
                        <?php if ($page > 1): ?>
                            <a href="?search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&page=<?php echo $page - 1; ?>" class="btn btn-sm btn-secondary" style="padding: 6px 12px;">&larr; Prev</a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                <a href="?search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&page=<?php echo $i; ?>" class="btn btn-sm <?php echo $i === $page ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 6px 12px;"><?php echo $i; ?></a>
                            <?php elseif ($i == 2 || $i == $total_pages - 1): ?>
                                <span style="padding: 6px; color: var(--text-muted);">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?search=<?php echo urlencode($search); ?>&category=<?php echo urlencode($category_filter); ?>&page=<?php echo $page + 1; ?>" class="btn btn-sm btn-secondary" style="padding: 6px 12px;">Next &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
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