<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$skill_id = intval($_GET['id'] ?? 0);
$page_title = 'Skill Detail';
$success = '';
$error = '';

if ($skill_id <= 0) {
    header('Location: ../pages/skills.php');
    exit;
}

// Handle session booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_session') {
    $provider_id = intval($_POST['provider_id'] ?? 0);
    $scheduled_time = $_POST['scheduled_time'] ?? '';
    $duration = intval($_POST['duration'] ?? 60);

    if ($provider_id > 0 && $scheduled_time !== '') {
        // Check wallet balance
        $wallet = $conn->query("SELECT balance FROM wallet WHERE user_id = $user_id")->fetch_assoc();
        $credit_cost = ($duration / 60) * 10; // 10 credits per hour

        $scheduled_timestamp = strtotime($scheduled_time);
        if ($scheduled_timestamp < time()) {
            $error = 'You cannot book a session in the past. Please select a valid date and time.';
        } else if ($wallet && $wallet['balance'] >= $credit_cost) {
            $conn->begin_transaction();
            try {
                // Generate a 4-digit OTP
                $completion_otp = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);

                // 1. Deduct from requester's wallet (Escrow)
                $conn->query("UPDATE wallet SET balance = balance - $credit_cost WHERE user_id = $user_id");

                // 2. Insert session with OTP
                $stmt = $conn->prepare("INSERT INTO exchange_sessions (requester_id, provider_id, skill_id, status, scheduled_time, session_duration, time_credit_transfer, completion_otp) VALUES (?, ?, ?, 'scheduled', ?, ?, ?, ?)");
                $stmt->bind_param("iiisids", $user_id, $provider_id, $skill_id, $scheduled_time, $duration, $credit_cost, $completion_otp);
                
                if ($stmt->execute()) {
                    $conn->commit();
                    $success = 'Session booked successfully! ' . number_format($credit_cost, 2) . ' TC has been held in escrow.';
                } else {
                    throw new Exception('Failed to book session.');
                }
                $stmt->close();
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        } else {
            $error = 'Insufficient Time Credits. You need ' . number_format($credit_cost, 2) . ' TC but your balance is ' . number_format($wallet['balance'] ?? 0, 2) . ' TC.';
        }
    }
}

// Fetch skill info
$skill_res = $conn->query("
    SELECT s.*
    FROM skills s
    WHERE s.skill_id = $skill_id
");
$skill = $skill_res ? $skill_res->fetch_assoc() : null;

if (!$skill) {
    header('Location: ../pages/skills.php');
    exit;
}

// Fetch providers (users who offer this skill, excluding current user)
$providers = $conn->query("
    SELECT u.user_id, u.name, u.location, u.bio,
           r.current_score, r.completed_sessions, r.mentor_level
    FROM user_skills_offered uso
    JOIN users u ON uso.user_id = u.user_id
    LEFT JOIN reputation r ON u.user_id = r.user_id
    WHERE uso.skill_id = $skill_id
    ORDER BY r.current_score DESC
");

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <a href="../pages/skills.php" style="color: var(--text-muted); font-size: 0.85rem;">&larr; Back to
            Skills</a>

        <?php if ($success): ?>
            <div class="alert alert-success mt-2"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger mt-2"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Skill Info -->
        <div class="card mt-2 mb-3">
            <div class="flex justify-between items-center mb-1">
                <h1 style="margin:0; font-size:1.5rem;"><?php echo htmlspecialchars($skill['skill_name']); ?></h1>
                <div class="flex gap-1">
                    <span
                        class="badge badge-orange"><?php echo htmlspecialchars($skill['catagory'] ?? 'General'); ?></span>
                    <span class="badge badge-info"><?php echo htmlspecialchars($skill['difficulty_level']); ?></span>
                </div>
            </div>
            <p style="color:var(--text-secondary); margin-top: 8px;">
                <?php echo htmlspecialchars($skill['description'] ?? 'No description available.'); ?></p>
        </div>

        <!-- Providers -->
        <h2 class="section-title">Available Providers</h2>

        <?php if ($providers && $providers->num_rows > 0): ?>
            <?php while ($providers && $p = $providers->fetch_assoc()): ?>
                <div class="card mb-2">
                    <div class="flex gap-2">
                        <div class="avatar avatar-lg"><?php echo strtoupper(substr($p['name'], 0, 1)); ?></div>
                        <div style="flex:1;">
                            <h3 style="margin-bottom: 4px;">
                                <a href="javascript:void(0)" onclick="openMentorInfo('<?php echo htmlspecialchars(addslashes($p['name'])); ?>', '<?php echo htmlspecialchars(addslashes($p['location'] ?? '')); ?>', '<?php echo htmlspecialchars(addslashes($p['bio'] ?? '')); ?>', '<?php echo $p['current_score'] ?? '5.00'; ?>', <?php echo $p['completed_sessions'] ?? 0; ?>, '<?php echo htmlspecialchars(addslashes($p['mentor_level'] ?? 'Novice')); ?>')" style="color: var(--primary); text-decoration: none;">
                                    <?php echo htmlspecialchars($p['name']); ?>
                                </a>
                            </h3>
                            <p style="color:var(--text-secondary); font-size:0.85rem;">
                                <?php echo htmlspecialchars($p['location'] ?? 'Unknown'); ?></p>
                            <p style="color:var(--text-muted); font-size:0.85rem; margin-top:4px;">
                                <?php echo htmlspecialchars($p['bio'] ?? ''); ?></p>
                            <div class="flex gap-2 mt-1" style="font-size: 0.85rem;">
                                <span>&#11088; <?php echo $p['current_score'] ?? '5.00'; ?>/5</span>
                                <span style="color:var(--text-muted);"><?php echo $p['completed_sessions'] ?? 0; ?>
                                    sessions</span>
                                <span
                                    class="badge badge-orange"><?php echo htmlspecialchars($p['mentor_level'] ?? 'Novice'); ?></span>
                            </div>
                        </div>
                        <div>
                            <?php if ($p['user_id'] == $user_id): ?>
                                <button class="btn btn-secondary btn-sm" disabled>
                                    This is You
                                </button>
                            <?php else: ?>
                                <button class="btn btn-primary btn-sm"
                                    onclick="openBooking(<?php echo $p['user_id']; ?>, '<?php echo htmlspecialchars($p['name']); ?>')">
                                    Book Session
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="card">
                <div class="empty-state">
                    <div class="icon">&#128100;</div>
                    <p>No providers available for this skill yet.</p>
                </div>
            </div>
        <?php endif; ?>

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
            <input type="hidden" name="provider_id" id="modal_provider_id">
            <p style="color:var(--text-secondary); margin-bottom:16px;">Provider: <strong
                    id="modal_provider_name"></strong></p>
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

    function openBooking(providerId, providerName) {
        document.getElementById('modal_provider_id').value = providerId;
        document.getElementById('modal_provider_name').textContent = providerName;
        setMinDateTime();
        document.getElementById('bookingModal').classList.add('active');
    }

    function closeBooking() {
        document.getElementById('bookingModal').classList.remove('active');
    }

    document.getElementById('bookingModal').addEventListener('click', function (e) {
        if (e.target === this) closeBooking();
    });

    function openMentorInfo(name, location, bio, score, sessions, level) {
        document.getElementById('mentor_info_content').innerHTML = `
            <h3 style="margin-bottom: 8px;">${name}</h3>
            <p style="color:var(--text-secondary); font-size:0.9rem;"><strong>Location:</strong> ${location || 'Unknown'}</p>
            <p style="color:var(--text-muted); font-size:0.9rem; margin-top:8px;"><strong>Bio:</strong> ${bio || 'No bio available'}</p>
            <div class="flex gap-2 mt-3" style="font-size: 0.9rem; padding: 10px; background: var(--bg-secondary); border-radius: 8px;">
                <span>&#11088; ${score}/5 Rating</span>
                <span style="color:var(--text-muted);">&bull; ${sessions} Sessions</span>
                <span class="badge badge-orange">${level}</span>
            </div>
        `;
        document.getElementById('mentorInfoModal').classList.add('active');
    }

    function closeMentorInfo() {
        document.getElementById('mentorInfoModal').classList.remove('active');
    }

    document.getElementById('mentorInfoModal').addEventListener('click', function (e) {
        if (e.target === this) closeMentorInfo();
    });
</script>

<!-- Mentor Info Modal -->
<div class="modal-overlay" id="mentorInfoModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Mentor Profile</h3>
            <button class="modal-close" onclick="closeMentorInfo()">&times;</button>
        </div>
        <div id="mentor_info_content"></div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>