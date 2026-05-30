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
        <p style="font-size:0.8rem; color:var(--text-muted); margin-top:-12px; margin-bottom:12px;"><i data-lucide="lightbulb" class="lucide-sm"></i> Dynamic pricing based on provider demand (surge multiplier calculated via SQL subqueries)</p>

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
                            <!-- Surge pricing badge (Feature 6) -->
                            <span id="surge-badge-<?php echo $p['user_id']; ?>" class="badge" style="display:none; margin-left:8px;"></span>
                        </div>
                        <div>
                            <?php if ($p['user_id'] == $user_id): ?>
                                <button class="btn btn-secondary btn-sm" disabled>
                                    This is You
                                </button>
                            <?php else: ?>
                                <a href="../pages/messages.php?start_with_user_id=<?php echo $p['user_id']; ?>" class="btn btn-secondary btn-sm" style="margin-right: 5px;">Message User</a>
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
            <p style="color:var(--text-secondary); margin-bottom:8px;">Provider: <strong
                    id="modal_provider_name"></strong></p>
            <div id="surge-info" style="display:none; margin-bottom:16px; padding:8px 12px; border-radius:var(--radius-sm); font-size:0.85rem;"></div>
            <div class="form-group">
                <label for="scheduled_time">Preferred Date & Time</label>
                <input type="datetime-local" id="scheduled_time" name="scheduled_time" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="duration">Duration (minutes)</label>
                <select name="duration" id="duration" class="form-control" onchange="updateSurgeCost()">
                    <option value="30">30 minutes</option>
                    <option value="60" selected>60 minutes</option>
                    <option value="90">90 minutes</option>
                    <option value="120">120 minutes</option>
                </select>
            </div>
            <div id="cost-display" style="text-align:center; margin-bottom:12px; font-size:0.9rem; color:var(--text-secondary);">Estimated cost: <strong id="cost-value">10.00 TC</strong></div>
            <button type="submit" class="btn btn-primary btn-block">Confirm Booking</button>
        </form>
    </div>
</div>

<script>
    var currentSurgeMultiplier = 1.0;

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

    function updateSurgeCost() {
        var duration = parseInt(document.getElementById('duration').value);
        var baseCost = (duration / 60) * 10;
        var surgedCost = (baseCost * currentSurgeMultiplier).toFixed(2);
        var costEl = document.getElementById('cost-value');
        if (currentSurgeMultiplier > 1) {
            costEl.innerHTML = '<span style="text-decoration:line-through;color:var(--text-muted);">' + baseCost.toFixed(2) + ' TC</span> → <span style="color:var(--danger);">' + surgedCost + ' TC</span>';
        } else {
            costEl.textContent = baseCost.toFixed(2) + ' TC';
        }
    }

    function openBooking(providerId, providerName) {
        document.getElementById('modal_provider_id').value = providerId;
        document.getElementById('modal_provider_name').textContent = providerName;
        setMinDateTime();
        // Fetch surge pricing for this provider
        fetch('../api/surge_pricing.php?provider_id=' + providerId)
            .then(r => r.json())
            .then(data => {
                currentSurgeMultiplier = data.surge_multiplier || 1.0;
                var infoEl = document.getElementById('surge-info');
                if (data.surge_multiplier > 1) {
                    var level = data.demand_level;
                    var bgColor = level === 'extreme' ? 'rgba(186,26,26,0.08)' : level === 'high' ? 'rgba(115,92,0,0.08)' : 'rgba(47,95,156,0.08)';
                    var borderColor = level === 'extreme' ? 'var(--danger)' : level === 'high' ? 'var(--warning)' : 'var(--info)';
                    infoEl.style.display = 'block';
                    infoEl.style.background = bgColor;
                    infoEl.style.border = '1px solid ' + borderColor;
                    infoEl.innerHTML = '<i data-lucide="flame" class="lucide-sm"></i> <strong>Surge Pricing Active (' + data.surge_multiplier + '×)</strong> — This provider has ' + data.provider_sessions_7d + ' bookings this week (platform avg: ' + data.platform_avg_7d + ')';
                } else {
                    infoEl.style.display = 'none';
                }
                updateSurgeCost();
            })
            .catch(() => { currentSurgeMultiplier = 1.0; updateSurgeCost(); });
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

    // --- Feature 6: Load surge badges for all providers on page load ---
    document.addEventListener('DOMContentLoaded', function() {
        var badges = document.querySelectorAll('[id^="surge-badge-"]');
        badges.forEach(function(badge) {
            var pid = badge.id.replace('surge-badge-', '');
            fetch('../api/surge_pricing.php?provider_id=' + pid)
                .then(r => r.json())
                .then(data => {
                    if (data.surge_multiplier > 1) {
                        badge.style.display = 'inline-block';
                        var level = data.demand_level;
                        if (level === 'extreme') { badge.className = 'badge badge-danger'; badge.textContent = '<i data-lucide="flame" class="lucide-sm"></i> ' + data.surge_multiplier + '× Demand'; }
                        else if (level === 'high') { badge.className = 'badge badge-warning'; badge.textContent = '<i data-lucide="trending-up" class="lucide-sm"></i> ' + data.surge_multiplier + '× Demand'; }
                        else { badge.className = 'badge badge-info'; badge.textContent = '↗ ' + data.surge_multiplier + '× Demand'; }
                    }
                })
                .catch(() => {});
        });
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