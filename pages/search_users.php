<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'Search Users';
$query = trim($_GET['q'] ?? '');
$users = null;

if ($query !== '') {
    $escaped = $conn->real_escape_string($query);
    $users = $conn->query("
        SELECT u.user_id, u.name, u.location, u.bio, r.current_score, r.mentor_level, IF(u.profile_photo IS NOT NULL AND LENGTH(u.profile_photo) > 0, 1, 0) AS has_photo
        FROM vw_public_users u
        LEFT JOIN reputation r ON u.user_id = r.user_id
        WHERE u.name LIKE '%$escaped%' AND u.user_id != $user_id
        ORDER BY r.current_score DESC
    ");
} else {
    // --- COMPLEX QUERY: Recommend providers via vw_public_users ---
    $recommended_providers = $conn->query("
        SELECT DISTINCT
            u.user_id,
            u.name,
            u.location,
            IF(u.profile_photo IS NOT NULL AND LENGTH(u.profile_photo) > 0, 1, 0) AS has_photo,
            s.skill_name,
            s.catagory,
            r.current_score,
            r.mentor_level
        FROM user_skills_requested usr
        JOIN user_skills_offered uso ON usr.skill_id = uso.skill_id
        JOIN vw_public_users u ON uso.user_id = u.user_id
        JOIN skills s ON usr.skill_id = s.skill_id
        LEFT JOIN reputation r ON u.user_id = r.user_id
        WHERE usr.user_id = $user_id
          AND u.user_id != $user_id
          AND u.status = 'active'
        ORDER BY r.current_score DESC
        LIMIT 6
    ");

    // --- SMART MATCHMAKING VIEW: Mutual skill exchanges via vw_smart_matches ---
    $mutual_exchanges = $conn->query("
        SELECT 
            m.user_b_id AS user_id,
            m.user_b_name AS name,
            m.user_b_location AS location,
            (SELECT IF(profile_photo IS NOT NULL AND LENGTH(profile_photo) > 0, 1, 0) FROM users WHERE user_id = m.user_b_id) AS has_photo,
            m.user_a_requests_skill_name AS they_teach_me,
            m.user_b_requests_skill_name AS i_teach_them,
            r.current_score,
            r.mentor_level
        FROM vw_smart_matches m
        LEFT JOIN reputation r ON m.user_b_id = r.user_id
        WHERE m.user_a_id = $user_id
        ORDER BY m.match_rank ASC
        LIMIT 6
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
            <?php if ($query !== ''): ?>
                <a href="search_users.php" class="btn btn-secondary">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($query !== ''): ?>
            <h3 class="mb-2">Results for "<?php echo htmlspecialchars($query); ?>"</h3>
            <?php if ($users && $users->num_rows > 0): ?>
                <div class="grid-3">
                    <?php while ($u = $users->fetch_assoc()): ?>
                        <div class="card">
                            <div class="flex gap-2 items-center mb-1">
                                <?php if (!empty($u['has_photo'])): ?>
                                    <img src="../api/user_photo.php?user_id=<?php echo $u['user_id']; ?>" class="avatar-img avatar-md" alt="<?php echo htmlspecialchars($u['name']); ?>" style="object-fit:cover;">
                                <?php else: ?>
                                    <div class="avatar avatar-md"><?php echo strtoupper(substr($u['name'], 0, 1)); ?></div>
                                <?php endif; ?>
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
            
            <!-- Smart Matchmaking recommendations when search is empty -->
            <div class="mb-4">
                <h2 class="section-title">Smart Matchmaking</h2>
                <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom: 20px;">
                    We automatically scan user listings to find ideal learning opportunities based on your profile preferences.
                </p>

                <!-- 1. Direct Mutual Exchanges (CQ-3) -->
                <h3 class="mb-2" style="color: var(--secondary);"><i data-lucide="sparkles" class="lucide-sm"></i> Mutual Exchange Partners</h3>
                <?php if ($mutual_exchanges && $mutual_exchanges->num_rows > 0): ?>
                    <div class="grid-3 mb-3">
                        <?php while ($me = $mutual_exchanges->fetch_assoc()): ?>
                            <div class="card" style="border: 1.5px solid var(--secondary); background: rgba(115,92,0,0.02);">
                                <div class="flex gap-2 items-center mb-1">
                                    <?php if (!empty($me['has_photo'])): ?>
                                        <img src="../api/user_photo.php?user_id=<?php echo $me['user_id']; ?>" class="avatar-img avatar-md" alt="<?php echo htmlspecialchars($me['name']); ?>" style="object-fit:cover; border-color: var(--secondary);">
                                    <?php else: ?>
                                        <div class="avatar avatar-md" style="border-color: var(--secondary);"><?php echo strtoupper(substr($me['name'], 0, 1)); ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <h3 style="margin:0;"><a href="user_profile.php?id=<?php echo $me['user_id']; ?>" style="color:var(--secondary); text-decoration:none;"><?php echo htmlspecialchars($me['name']); ?></a></h3>
                                        <span class="badge badge-warning" style="font-size:0.7rem;">Direct Match</span>
                                    </div>
                                </div>
                                <p style="color:var(--text-secondary); font-size:0.85rem; margin-top:8px;"><?php echo htmlspecialchars($me['location'] ?? 'Unknown location'); ?></p>
                                
                                <div class="mt-2" style="font-size:0.8rem; background: var(--bg-hover); padding: 8px; border-radius: var(--radius-sm);">
                                    <div style="color: var(--success); font-weight: 500;"><i data-lucide="book-open" class="lucide-sm"></i> Teaches: <?php echo htmlspecialchars($me['they_teach_me']); ?></div>
                                    <div style="color: var(--primary); font-weight: 500; margin-top:3px;"><i data-lucide="graduation-cap" class="lucide-sm"></i> Learns: <?php echo htmlspecialchars($me['i_teach_them']); ?></div>
                                </div>
                                <div class="mt-2" style="font-size:0.85rem;">
                                    &#11088; <?php echo $me['current_score'] ?? '5.00'; ?>/5
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="card mb-3" style="padding: 16px;">
                        <p style="color:var(--text-muted); font-size: 0.85rem;">No mutual exchange pairings found. Try adding more skills you request or offer in your profile settings.</p>
                    </div>
                <?php endif; ?>

                <!-- 2. Recommended Providers (CQ-2) -->
                <h3 class="mb-2 mt-3" style="color: var(--primary);"><i data-lucide="target" class="lucide-sm"></i> Recommended for You</h3>
                <?php if ($recommended_providers && $recommended_providers->num_rows > 0): ?>
                    <div class="grid-3">
                        <?php while ($rp = $recommended_providers->fetch_assoc()): ?>
                            <div class="card">
                                <div class="flex gap-2 items-center mb-1">
                                    <?php if (!empty($rp['has_photo'])): ?>
                                        <img src="../api/user_photo.php?user_id=<?php echo $rp['user_id']; ?>" class="avatar-img avatar-md" alt="<?php echo htmlspecialchars($rp['name']); ?>" style="object-fit:cover;">
                                    <?php else: ?>
                                        <div class="avatar avatar-md"><?php echo strtoupper(substr($rp['name'], 0, 1)); ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <h3 style="margin:0;"><a href="user_profile.php?id=<?php echo $rp['user_id']; ?>" style="color:var(--primary); text-decoration:none;"><?php echo htmlspecialchars($rp['name']); ?></a></h3>
                                        <span class="badge badge-orange" style="font-size:0.7rem;"><?php echo htmlspecialchars($rp['mentor_level'] ?? 'Novice'); ?></span>
                                    </div>
                                </div>
                                <p style="color:var(--text-secondary); font-size:0.85rem; margin-top:8px;"><?php echo htmlspecialchars($rp['location'] ?? 'Unknown location'); ?></p>
                                <p style="font-size:0.8rem; color:var(--text-primary); margin:5px 0;">
                                    Offers skill you want: <strong><?php echo htmlspecialchars($rp['skill_name']); ?></strong>
                                </p>
                                <div class="mt-2" style="font-size:0.85rem;">
                                    &#11088; <?php echo $rp['current_score'] ?? '5.00'; ?>/5
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="card" style="padding: 16px;">
                        <p style="color:var(--text-muted); font-size: 0.85rem;">No recommendations available. Fill in the "Skills You Want to Learn" in your profile settings to get matched.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
