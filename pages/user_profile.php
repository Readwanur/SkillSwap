<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$profile_id = intval($_GET['id'] ?? 0);
if ($profile_id <= 0) {
    header('Location: search_users.php');
    exit;
}

if ($profile_id === $_SESSION['user_id']) {
    header('Location: profile.php');
    exit;
}

$user = $conn->query("
    SELECT u.name, u.location, u.bio, u.created_at, u.last_active_at, r.current_score, r.completed_sessions, r.mentor_level
    FROM users u
    LEFT JOIN reputation r ON u.user_id = r.user_id
    WHERE u.user_id = $profile_id
")->fetch_assoc();

if (!$user) {
    echo "User not found.";
    exit;
}

$page_title = $user['name'] . "'s Profile";

$offered = $conn->query("SELECT s.skill_name, s.difficulty_level, s.skill_id FROM user_skills_offered uso JOIN skills s ON uso.skill_id = s.skill_id WHERE uso.user_id = $profile_id");
$requested = $conn->query("SELECT s.skill_name FROM user_skills_requested usr JOIN skills s ON usr.skill_id = s.skill_id WHERE usr.user_id = $profile_id");

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <a href="javascript:history.back()" style="color: var(--text-muted); font-size: 0.85rem;">&larr; Back</a>

        <div class="card mt-2 mb-3">
            <div class="flex gap-2 items-center">
                <div class="avatar avatar-lg"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
                <div style="flex:1;">
                    <h1 style="margin:0; font-size:1.8rem;"><?php echo htmlspecialchars($user['name']); ?></h1>
                    <p style="color:var(--text-secondary);"><?php echo htmlspecialchars($user['location'] ?? 'Unknown location'); ?></p>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 1.5rem; font-weight: bold; color: var(--warning);">&#11088; <?php echo $user['current_score'] ?? '5.00'; ?></div>
                    <div style="color: var(--text-muted); font-size: 0.9rem;"><?php echo $user['completed_sessions'] ?? 0; ?> Sessions</div>
                    <span class="badge badge-orange mt-1" style="display:inline-block;"><?php echo htmlspecialchars($user['mentor_level'] ?? 'Novice'); ?></span>
                </div>
            </div>
            
            <hr style="margin: 16px 0; border: 0; border-top: 1px solid var(--border-color);">
            
            <h3>About</h3>
            <p style="color:var(--text-secondary); margin-top:8px;"><?php echo htmlspecialchars($user['bio'] ?? 'No bio provided.'); ?></p>
            
            <div class="mt-2" style="font-size:0.85rem; color:var(--text-muted);">
                Member since: <?php echo date('F j, Y', strtotime($user['created_at'])); ?> &middot; 
                Last active: <?php echo date('M j, Y', strtotime($user['last_active_at'])); ?>
            </div>
        </div>

        <div class="grid-2">
            <!-- Skills Offered -->
            <div class="card">
                <h3 class="section-title">Skills Offered</h3>
                <?php if ($offered && $offered->num_rows > 0): ?>
                    <ul style="list-style:none; padding:0;">
                        <?php while ($s = $offered->fetch_assoc()): ?>
                            <li style="margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid var(--border-color);">
                                <div class="flex justify-between">
                                    <strong><a href="skill_detail.php?id=<?php echo $s['skill_id']; ?>" style="color:var(--primary); text-decoration:none;"><?php echo htmlspecialchars($s['skill_name']); ?></a></strong>
                                    <span class="badge badge-info" style="font-size:0.7rem;"><?php echo htmlspecialchars($s['difficulty_level']); ?></span>
                                </div>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:0.9rem;">This user isn't offering any skills right now.</p>
                <?php endif; ?>
            </div>

            <!-- Skills Requested -->
            <div class="card">
                <h3 class="section-title">Skills Wanted</h3>
                <?php if ($requested && $requested->num_rows > 0): ?>
                    <div class="flex flex-wrap gap-1 mt-1">
                        <?php while ($s = $requested->fetch_assoc()): ?>
                            <span class="badge" style="background:var(--bg-secondary); color:var(--text-primary);"><?php echo htmlspecialchars($s['skill_name']); ?></span>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:0.9rem;">This user isn't looking to learn any specific skills right now.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
