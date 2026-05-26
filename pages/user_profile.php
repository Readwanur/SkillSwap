<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$profile_id = intval($_GET['id'] ?? 0);
if ($profile_id <= 0) {
    header('Location: search_users.php');
    exit;
}

if ($profile_id === $_SESSION['user_id']) {
    header('Location: profile.php');
    exit;
}

$success = '';
$error = '';

// Handle session booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_session') {
    $provider_id = intval($_POST['provider_id'] ?? 0);
    $skill_id = intval($_POST['skill_id'] ?? 0);
    $scheduled_time = $_POST['scheduled_time'] ?? '';
    $duration = intval($_POST['duration'] ?? 60);

    if ($provider_id > 0 && $skill_id > 0 && $scheduled_time !== '') {
        // --- STORED PROCEDURE: sp_book_session ---
        // Atomically validates balance, deducts escrow, generates OTP,
        // and creates the session record. Replaces multi-query PHP logic.
        $stmt = $conn->prepare("CALL sp_book_session(?, ?, ?, ?, ?, @sp_status, @sp_message)");
        $stmt->bind_param("iiisi", $user_id, $provider_id, $skill_id, $scheduled_time, $duration);
        $stmt->execute();
        $stmt->close();
        $result = $conn->query("SELECT @sp_status AS status, @sp_message AS message")->fetch_assoc();
        if ($result['status'] === 'success') {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}


$user = $conn->query("
    SELECT u.name, u.location, u.bio, u.created_at, u.last_active_at, r.current_score, r.completed_sessions, r.mentor_level
    FROM vw_public_users u
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

        <?php if ($success): ?>
            <div class="alert alert-success mt-2"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger mt-2"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card mt-2 mb-3">
            <div class="flex gap-2 items-center">
                <div class="avatar avatar-lg"><?php echo strtoupper(substr($user['name'], 0, 1)); ?></div>
                <div style="flex:1;">
                    <h1 style="margin:0; font-size:1.8rem;"><?php echo htmlspecialchars($user['name']); ?></h1>
                    <p style="color:var(--text-secondary); margin-bottom: 8px;"><?php echo htmlspecialchars($user['location'] ?? 'Unknown location'); ?></p>
                    <a href="messages.php?start_with_user_id=<?php echo $profile_id; ?>" class="btn btn-secondary btn-sm" style="display:inline-block; text-decoration:none;">
                        💬 Message User
                    </a>
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
                            <li style="margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid var(--border-color);">
                                <div class="flex justify-between items-center" style="gap:10px;">
                                    <div>
                                        <strong><a href="skill_detail.php?id=<?php echo $s['skill_id']; ?>" style="color:var(--primary); text-decoration:none;"><?php echo htmlspecialchars($s['skill_name']); ?></a></strong>
                                        <span class="badge badge-info" style="font-size:0.7rem; margin-left:8px; display:inline-block;"><?php echo htmlspecialchars($s['difficulty_level']); ?></span>
                                    </div>
                                    <button class="btn btn-primary btn-sm" onclick="openBooking(<?php echo $s['skill_id']; ?>, '<?php echo htmlspecialchars(addslashes($s['skill_name'])); ?>')">
                                        Book Session
                                    </button>
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

<!-- Booking Modal -->
<div class="modal-overlay" id="bookingModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Book a Session</h3>
            <button class="modal-close" onclick="closeBooking()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="book_session">
            <input type="hidden" name="provider_id" value="<?php echo $profile_id; ?>">
            <input type="hidden" name="skill_id" id="modal_skill_id">
            
            <p style="color:var(--text-secondary); margin-bottom:8px;">Provider: <strong><?php echo htmlspecialchars($user['name']); ?></strong></p>
            <p style="color:var(--text-secondary); margin-bottom:16px;">Skill: <strong id="modal_skill_name"></strong></p>
            
            <div class="form-group">
                <label for="scheduled_time">Preferred Date & Time</label>
                <input type="datetime-local" id="scheduled_time" name="scheduled_time" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="duration">Duration (minutes)</label>
                <select name="duration" id="duration" class="form-control">
                    <option value="30">30 minutes (5 TC)</option>
                    <option value="60" selected>60 minutes (10 TC)</option>
                    <option value="90">90 minutes (15 TC)</option>
                    <option value="120">120 minutes (20 TC)</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Confirm Booking</button>
        </form>
    </div>
</div>

<script>
    // Set min datetime to the user's local "now"
    function setMinDateTime() {
        var now = new Date();
        var y = now.getFullYear();
        var m = String(now.getMonth() + 1).padStart(2, '0');
        var d = String(now.getDate()).padStart(2, '0');
        var h = String(now.getHours()).padStart(2, '0');
        var min = String(now.getMinutes()).padStart(2, '0');
        document.getElementById('scheduled_time').min = y + '-' + m + '-' + d + 'T' + h + ':' + min;
    }

    function openBooking(skillId, skillName) {
        document.getElementById('modal_skill_id').value = skillId;
        document.getElementById('modal_skill_name').textContent = skillName;
        setMinDateTime();
        document.getElementById('bookingModal').classList.add('active');
    }

    function closeBooking() {
        document.getElementById('bookingModal').classList.remove('active');
    }

    document.getElementById('bookingModal').addEventListener('click', function (e) {
        if (e.target === this) closeBooking();
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
