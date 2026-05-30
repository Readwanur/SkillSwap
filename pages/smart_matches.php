<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'Smart Matches';

// ============================================================
// FEATURE 2: TIMEZONE-AWARE PERFECT MATCH ENGINE
// ============================================================

// --- CQ: Smart Matches with availability overlap ---
// Uses the existing vw_smart_matches view (which already performs 
// multi-table JOINs with DENSE_RANK) and extends it with
// user_availability overlap logic using time range intersection.
$matches = $conn->query("
    SELECT 
        sm.user_b_id,
        sm.user_b_name,
        sm.user_b_location,
        sm.user_b_reliability,
        sm.user_a_requests_skill_name,
        sm.user_a_requests_skill_id,
        sm.user_b_requests_skill_name,
        sm.user_b_requests_skill_id,
        sm.match_rank
    FROM vw_smart_matches sm
    WHERE sm.user_a_id = $user_id
    ORDER BY sm.match_rank ASC
    LIMIT 20
");

// For each match, check availability overlap
$match_data = [];
if ($matches && $matches->num_rows > 0) {
    while ($m = $matches->fetch_assoc()) {
        $partner_id = $m['user_b_id'];
        
        // --- CQ: Availability overlap using time range intersection ---
        // Finds shared free time: WHERE my_start < their_end AND my_end > their_start
        // Uses GREATEST/LEAST to compute the actual overlapping window
        $overlap_q = $conn->query("
            SELECT 
                ua_me.day_of_week,
                TIME_FORMAT(GREATEST(ua_me.start_time, ua_them.start_time), '%h:%i %p') AS overlap_start,
                TIME_FORMAT(LEAST(ua_me.end_time, ua_them.end_time), '%h:%i %p') AS overlap_end,
                TIMESTAMPDIFF(MINUTE, 
                    GREATEST(ua_me.start_time, ua_them.start_time),
                    LEAST(ua_me.end_time, ua_them.end_time)
                ) AS overlap_minutes
            FROM user_availability ua_me
            JOIN user_availability ua_them 
                ON ua_them.user_id = $partner_id 
                AND ua_them.day_of_week = ua_me.day_of_week
            WHERE ua_me.user_id = $user_id
              AND ua_me.start_time < ua_them.end_time 
              AND ua_me.end_time > ua_them.start_time
            ORDER BY FIELD(ua_me.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
        ");
        
        $overlaps = [];
        if ($overlap_q && $overlap_q->num_rows > 0) {
            while ($o = $overlap_q->fetch_assoc()) {
                $overlaps[] = $o;
            }
        }
        
        // Get partner's reputation info
        $rep = $conn->query("SELECT r.completed_sessions, r.mentor_level FROM reputation r WHERE r.user_id = $partner_id")->fetch_assoc();
        
        $m['overlaps'] = $overlaps;
        $m['completed_sessions'] = $rep['completed_sessions'] ?? 0;
        $m['mentor_level'] = $rep['mentor_level'] ?? 'Novice';
        $match_data[] = $m;
    }
}

// Count user's own availability slots
$my_avail_count = $conn->query("SELECT COUNT(*) AS cnt FROM user_availability WHERE user_id = $user_id")->fetch_assoc()['cnt'];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <div class="flex justify-between items-center" style="flex-wrap:wrap; gap:12px;">
            <div>
                <h1 class="page-title" style="margin-bottom:4px;"><i data-lucide="link" class="lucide-sm"></i> Smart Matches</h1>
                <p style="color:var(--text-muted); font-size:0.85rem;">Mutual skill matches with timezone-aware availability overlap</p>
            </div>
            <div class="flex gap-1">
                <a href="../pages/market_trends.php" class="btn btn-sm btn-secondary"><i data-lucide="bar-chart" class="lucide-sm"></i> Market Trends</a>
                <a href="../pages/leaderboard.php" class="btn btn-sm btn-secondary"><i data-lucide="award" class="lucide-sm"></i> Leaderboard</a>
            </div>
        </div>

        <?php if ($my_avail_count == 0): ?>
            <div class="alert alert-warning mt-2">
                <i data-lucide="alert-triangle" class="lucide-sm"></i> You haven't set your availability schedule yet. <a href="../pages/profile.php">Add your availability</a> to see timezone-aware matching with shared free hours.
            </div>
        <?php endif; ?>

        <?php if (count($match_data) > 0): ?>
            <div class="mt-3">
                <?php foreach ($match_data as $m): ?>
                    <div class="card mb-2" style="border-left: 3px solid var(--primary);">
                        <div class="flex gap-2" style="flex-wrap:wrap;">
                            <div class="avatar avatar-lg"><?php echo strtoupper(substr($m['user_b_name'], 0, 1)); ?></div>
                            <div style="flex:1; min-width:200px;">
                                <div class="flex justify-between items-center" style="flex-wrap:wrap; gap:8px;">
                                    <div>
                                        <h3 style="margin-bottom:2px;">
                                            <a href="user_profile.php?id=<?php echo $m['user_b_id']; ?>" style="color:var(--primary); text-decoration:none;">
                                                <?php echo htmlspecialchars($m['user_b_name']); ?>
                                            </a>
                                            <span class="badge badge-orange" style="font-size:0.7rem; margin-left:6px;"><?php echo htmlspecialchars($m['mentor_level']); ?></span>
                                        </h3>
                                        <p style="color:var(--text-muted); font-size:0.85rem;"><?php echo htmlspecialchars($m['user_b_location'] ?? 'Unknown'); ?></p>
                                    </div>
                                    <div style="text-align:right;">
                                        <span style="font-weight:700; color:var(--warning);"><i data-lucide="star" class="lucide-sm"></i> <?php echo $m['user_b_reliability']; ?></span>
                                        <span style="color:var(--text-muted); font-size:0.8rem;"> · <?php echo $m['completed_sessions']; ?> sessions</span>
                                    </div>
                                </div>
                                
                                <!-- Skill Exchange -->
                                <div class="mt-2" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                    <div style="background:var(--bg-hover); border-radius:var(--radius-sm); padding:8px 14px; flex:1; min-width:150px;">
                                        <span style="font-size:0.72rem; text-transform:uppercase; color:var(--text-muted); font-weight:600;">You learn</span><br>
                                        <a href="skill_detail.php?id=<?php echo $m['user_a_requests_skill_id']; ?>" style="font-weight:600; color:var(--primary); text-decoration:none;">
                                            <?php echo htmlspecialchars($m['user_a_requests_skill_name']); ?>
                                        </a>
                                    </div>
                                    <span style="font-size:1.2rem;">⇄</span>
                                    <div style="background:var(--bg-hover); border-radius:var(--radius-sm); padding:8px 14px; flex:1; min-width:150px;">
                                        <span style="font-size:0.72rem; text-transform:uppercase; color:var(--text-muted); font-weight:600;">You teach</span><br>
                                        <a href="skill_detail.php?id=<?php echo $m['user_b_requests_skill_id']; ?>" style="font-weight:600; color:var(--success); text-decoration:none;">
                                            <?php echo htmlspecialchars($m['user_b_requests_skill_name']); ?>
                                        </a>
                                    </div>
                                </div>

                                <!-- Availability Overlap -->
                                <?php if (!empty($m['overlaps'])): ?>
                                    <div class="mt-2" style="background:rgba(26,122,66,0.06); border:1px solid rgba(26,122,66,0.15); border-radius:var(--radius-sm); padding:10px 14px;">
                                        <span style="font-size:0.75rem; font-weight:700; color:var(--success); text-transform:uppercase;"><i data-lucide="circle" fill="currentColor" class="lucide-sm"></i> Shared Availability</span>
                                        <div class="mt-1" style="display:flex; flex-wrap:wrap; gap:6px;">
                                            <?php foreach ($m['overlaps'] as $o): ?>
                                                <span class="badge badge-success" style="font-size:0.75rem;">
                                                    <?php echo $o['day_of_week']; ?>: <?php echo $o['overlap_start']; ?> – <?php echo $o['overlap_end']; ?>
                                                    <small>(<?php echo $o['overlap_minutes']; ?>m)</small>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="mt-2" style="font-size:0.8rem; color:var(--text-muted);">
                                        <i data-lucide="hourglass" class="lucide-sm"></i> No overlapping availability found. <a href="profile.php">Update your schedule</a> or message them to coordinate.
                                    </div>
                                <?php endif; ?>

                                <!-- Actions -->
                                <div class="mt-2 flex gap-1">
                                    <a href="messages.php?start_with_user_id=<?php echo $m['user_b_id']; ?>" class="btn btn-sm btn-secondary"><i data-lucide="message-square" class="lucide-sm"></i> Message</a>
                                    <a href="skill_detail.php?id=<?php echo $m['user_a_requests_skill_id']; ?>" class="btn btn-sm btn-primary"><i data-lucide="calendar" class="lucide-sm"></i> Book Session</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card mt-3">
                <div class="empty-state">
                    <div class="icon"><i data-lucide="link" class="lucide-sm"></i></div>
                    <p>No mutual skill matches found yet.</p>
                    <p style="font-size:0.85rem; color:var(--text-muted); margin-top:8px;">
                        Make sure you have skills listed in both your <strong>"Skills I Teach"</strong> and <strong>"Skills I Want to Learn"</strong> sections on your <a href="profile.php">profile</a>.
                        Matches appear when another user wants to learn what you teach, AND teaches what you want to learn.
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
