<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$page_title = 'Search Users';
$query = trim($_GET['q'] ?? '');
$users = null;

if ($query !== '') {
    $escaped = $conn->real_escape_string($query);
    $users = $conn->query("
        SELECT u.user_id, u.name, u.location, u.bio, r.current_score, r.mentor_level
        FROM users u
        LEFT JOIN reputation r ON u.user_id = r.user_id
        WHERE u.name LIKE '%$escaped%' AND u.user_id != {$_SESSION['user_id']}
        ORDER BY r.current_score DESC
    ");
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <h1 class="page-title">Search Users</h1>

        <form method="GET" class="flex gap-1 mb-3">
            <input type="text" name="q" class="form-control" placeholder="Search by name..." value="<?php echo htmlspecialchars($query); ?>" required style="max-width:300px;">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>

        <?php if ($query !== ''): ?>
            <h3 class="mb-2">Results for "<?php echo htmlspecialchars($query); ?>"</h3>
            <?php if ($users && $users->num_rows > 0): ?>
                <div class="grid-3">
                    <?php while ($u = $users->fetch_assoc()): ?>
                        <div class="card">
                            <div class="flex gap-2 items-center mb-1">
                                <div class="avatar avatar-md"><?php echo strtoupper(substr($u['name'], 0, 1)); ?></div>
                                <div>
                                    <h3 style="margin:0;"><a href="user_profile.php?id=<?php echo $u['user_id']; ?>" style="color:var(--primary); text-decoration:none;"><?php echo htmlspecialchars($u['name']); ?></a></h3>
                                    <span class="badge badge-orange" style="font-size:0.7rem;"><?php echo htmlspecialchars($u['mentor_level'] ?? 'Novice'); ?></span>
                                </div>
                            </div>
                            <p style="color:var(--text-secondary); font-size:0.85rem; margin-top:8px;"><?php echo htmlspecialchars($u['location'] ?? 'Unknown location'); ?></p>
                            <div class="mt-2" style="font-size:0.85rem;">
                                &#11088; <?php echo $u['current_score'] ?? '5.00'; ?>/5
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div class="card">
                    <p style="color:var(--text-muted);">No users found matching "<?php echo htmlspecialchars($query); ?>"</p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p style="color:var(--text-muted);">Enter a username to begin searching.</p>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
