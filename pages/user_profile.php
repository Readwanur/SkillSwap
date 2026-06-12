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
    } else {
        $error = 'Please select a valid date and time for the session.';
    }
}


$user = $conn->query("
    SELECT u.name, u.location, u.bio, IF(u.profile_photo IS NOT NULL AND LENGTH(u.profile_photo) > 0, 1, 0) AS profile_photo, u.created_at, u.last_active_at, r.current_score, r.completed_sessions, r.mentor_level
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

// --- Feature 4: Get user's leaderboard badges ---
// Uses CTE + DENSE_RANK() OVER (PARTITION BY category) to find
// this user's rank across all skill categories
$user_badges = $conn->query("
    WITH provider_scores AS (
        SELECT es.provider_id, s.catagory AS category,
            DENSE_RANK() OVER (PARTITION BY s.catagory ORDER BY 
                (COUNT(*) * 0.4) + (COALESCE(AVG(es.rating),0)*6*0.3) + (COALESCE(r.current_score,5)*4*0.2) + (COALESCE(SUM(es.session_duration),0)/60.0*0.1) DESC
            ) AS rank_pos
        FROM exchange_sessions es
        JOIN skills s ON es.skill_id = s.skill_id
        LEFT JOIN reputation r ON es.provider_id = r.user_id
        WHERE es.status = 'completed' AND s.catagory IS NOT NULL
        GROUP BY es.provider_id, s.catagory
    )
    SELECT category, rank_pos FROM provider_scores 
    WHERE provider_id = $profile_id AND rank_pos <= 3
");
$profile_badges = [];
if ($user_badges) {
    while ($b = $user_badges->fetch_assoc()) {
        $profile_badges[] = $b;
    }
}

// Fetch availability
$availability = $conn->query("SELECT day_of_week, start_time, end_time FROM user_availability WHERE user_id = $profile_id ORDER BY FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), start_time ASC");

// Fetch availability lock status
$lock_res = $conn->query("SELECT availability_locked FROM users WHERE user_id = $profile_id");
$is_locked = ($lock_res && $lock_res->fetch_assoc()['availability_locked'] == 1);

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
                <?php if (!empty($user['profile_photo'])): ?>
                    <img src="../api/user_photo.php?user_id=<?php echo $profile_id; ?>" class="avatar-img avatar-lg"
                        alt="Profile Photo" onclick="openLightbox(this.src)" style="width: 120px; height: 120px;">
                <?php else: ?>
                    <div class="avatar avatar-lg" style="width: 120px; height: 120px; font-size: 3rem; line-height: 120px;">
                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <div style="flex:1;">
                    <h1 style="margin:0; font-size:1.8rem;"><?php echo htmlspecialchars($user['name']); ?></h1>
                    <p style="color:var(--text-secondary); margin-bottom: 8px;">
                        <?php echo htmlspecialchars($user['location'] ?? 'Unknown location'); ?>
                    </p>
                    <a href="messages.php?start_with_user_id=<?php echo $profile_id; ?>"
                        class="btn btn-secondary btn-sm" style="display:inline-block; text-decoration:none;">
                        <i data-lucide="message-square" class="lucide-sm"></i> Message User
                    </a>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 1.5rem; font-weight: bold; color: var(--warning);">
                        <?php 
                        if ($user['current_score'] !== null) {
                            echo '&#11088; ' . number_format((float)$user['current_score'], 2);
                        } else {
                            echo '<span style="font-size:1.2rem;color:var(--text-muted);font-weight:normal;">No Ratings Yet</span>';
                        }
                        ?>
                    </div>
                    <div style="color: var(--text-muted); font-size: 0.9rem;">
                        <?php echo $user['completed_sessions'] ?? 0; ?> Sessions
                    </div>
                    <span class="badge badge-orange mt-1"
                        style="display:inline-block;"><?php echo htmlspecialchars($user['mentor_level'] ?? 'Novice'); ?></span>
                </div>
            </div>

            <hr style="margin: 16px 0; border: 0; border-top: 1px solid var(--border-color);">

            <h3>About</h3>
            <p style="color:var(--text-secondary); margin-top:8px;">
                <?php echo htmlspecialchars($user['bio'] ?? 'No bio provided.'); ?>
            </p>

            <div class="mt-2" style="font-size:0.85rem; color:var(--text-muted);">
                Member since: <?php echo date('F j, Y', strtotime($user['created_at'])); ?> &middot;
                Last active: <?php echo date('M j, Y', strtotime($user['last_active_at'])); ?>
            </div>

            <?php if (!empty($profile_badges)): ?>
                <div class="mt-2" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <span style="font-size:0.8rem; color:var(--text-muted); font-weight:600;"><i data-lucide="award"
                            class="lucide-sm"></i> Badges:</span>
                    <?php foreach ($profile_badges as $badge):
                        $icon = '<i data-lucide="medal" class="lucide-sm"></i>';
                        $badge_cls = 'badge-info';
                        if ($badge['rank_pos'] == 1) {
                            $icon = '<i data-lucide="medal" style="color: gold;" class="lucide-sm"></i>';
                            $badge_cls = 'badge-warning';
                        } elseif ($badge['rank_pos'] == 2) {
                            $icon = '<i data-lucide="medal" style="color: silver;" class="lucide-sm"></i>';
                            $badge_cls = 'badge-info';
                        } elseif ($badge['rank_pos'] == 3) {
                            $icon = '<i data-lucide="medal" style="color: #cd7f32;" class="lucide-sm"></i>';
                            $badge_cls = 'badge-orange';
                        }
                        ?>
                        <span class="badge <?php echo $badge_cls; ?>" style="font-size:0.75rem;">
                            <?php echo $icon; ?> #<?php echo $badge['rank_pos']; ?>
                            <?php echo htmlspecialchars($badge['category']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
                                        <strong><a href="skill_detail.php?id=<?php echo $s['skill_id']; ?>"
                                                style="color:var(--primary); text-decoration:none;"><?php echo htmlspecialchars($s['skill_name']); ?></a></strong>
                                        <span class="badge badge-info"
                                            style="font-size:0.7rem; margin-left:8px; display:inline-block;"><?php echo htmlspecialchars($s['difficulty_level']); ?></span>
                                    </div>
                                    <button class="btn btn-primary btn-sm"
                                        onclick="openBooking(<?php echo $s['skill_id']; ?>, '<?php echo htmlspecialchars(addslashes($s['skill_name'])); ?>')">
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
                            <span class="badge"
                                style="background:var(--bg-secondary); color:var(--text-primary);"><?php echo htmlspecialchars($s['skill_name']); ?></span>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:0.9rem;">This user isn't looking to learn any specific
                        skills right now.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Availability -->
        <div class="card mt-3">
            <h3 class="section-title">Availability</h3>
            <?php if ($is_locked): ?>
                <div style="background:rgba(47,95,156,0.08); border:1px solid rgba(47,95,156,0.2); padding:10px; border-radius:var(--radius-sm); margin-bottom:12px;">
                    <span class="badge badge-info" style="margin-bottom:6px; display:inline-block;"><i data-lucide="lock" class="lucide-sm"></i> Locked</span>
                    <p style="font-size:0.85rem; color:var(--text-secondary); margin:0;">This teacher requires bookings to be made strictly within their predefined time slots below.</p>
                </div>
            <?php endif; ?>
            
            <?php if ($availability && $availability->num_rows > 0): ?>
                <div class="flex flex-wrap gap-1 mt-1">
                    <?php while ($a = $availability->fetch_assoc()): ?>
                        <span class="badge badge-info" style="font-size:0.85rem; padding: 6px 10px;">
                            <i data-lucide="clock" class="lucide-sm"></i> <?php echo $a['day_of_week']; ?>:
                            <?php echo date('h:i A', strtotime($a['start_time'])); ?> -
                            <?php echo date('h:i A', strtotime($a['end_time'])); ?>
                        </span>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <p style="color:var(--text-muted); font-size:0.9rem;">This user hasn't set any specific availability times.
                </p>
            <?php endif; ?>
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
        <form method="POST" id="bookingForm" onsubmit="return prepareBookingSubmit()">
            <input type="hidden" name="action" value="book_session">
            <input type="hidden" name="provider_id" value="<?php echo $profile_id; ?>">
            <input type="hidden" name="skill_id" id="modal_skill_id">
            <input type="hidden" name="scheduled_time" id="final_scheduled_time" value="">

            <p style="color:var(--text-secondary); margin-bottom:8px;">Provider:
                <strong><?php echo htmlspecialchars($user['name']); ?></strong>
            </p>
            <p style="color:var(--text-secondary); margin-bottom:16px;">Skill: <strong id="modal_skill_name"></strong>
            </p>
            <div id="surge-info"
                style="display:none; margin-bottom:16px; padding:8px 12px; border-radius:var(--radius-sm); font-size:0.85rem;">
            </div>

            <!-- Lock availability message -->
            <div id="availability-lock-info" style="display:none; background:rgba(220,20,60,0.06); border:1px solid rgba(220,20,60,0.25); border-radius:var(--radius-sm); padding:10px 14px; margin-bottom:16px; font-size:0.82rem; color:crimson;">
                <i data-lucide="lock" class="lucide-sm"></i>
                The teacher is not available outside of the preferred time slots. Please select one of the available slots provided by the teacher.
            </div>

            <div class="form-group" id="datetime-free-group">
                <label for="scheduled_time">Preferred Date &amp; Time</label>
                <input type="datetime-local" id="scheduled_time" class="form-control">
            </div>

            <!-- Slot picker (shown when locked) -->
            <div class="form-group" id="slot-picker-group" style="display:none;">
                <label for="slot_select">Select Available Slot</label>
                <select id="slot_select" class="form-control" onchange="onSlotSelected(this.value)">
                    <option value="">Choose a time slot...</option>
                </select>
                <input type="hidden" id="scheduled_time_locked">
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
            <div id="cost-display"
                style="text-align:center; margin-bottom:12px; font-size:0.9rem; color:var(--text-secondary);">Estimated
                cost: <strong id="cost-value">10.00 TC</strong></div>
            <button type="submit" class="btn btn-primary btn-block">Confirm Booking</button>
        </form>
    </div>
</div>

<script>
    var currentSurgeMultiplier = 1.0;
    var providerAvailabilityLocked = false;

    function prepareBookingSubmit() {
        var freeInput = document.getElementById('scheduled_time').value;
        var lockedInput = document.getElementById('scheduled_time_locked').value;
        var finalInput = document.getElementById('final_scheduled_time');
        
        if (providerAvailabilityLocked && document.getElementById('slot-picker-group').style.display !== 'none') {
            if (!lockedInput) {
                alert('Please select an available slot.');
                return false;
            }
            finalInput.value = lockedInput;
        } else {
            if (!freeInput) {
                alert('Please select a preferred date and time.');
                return false;
            }
            finalInput.value = freeInput;
        }
        return true;
    }

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

    function generateSlotOptions(slots) {
        var select = document.getElementById('slot_select');
        select.innerHTML = '<option value="">Choose a time slot...</option>';
        var dayMap = {'Sunday':0,'Monday':1,'Tuesday':2,'Wednesday':3,'Thursday':4,'Friday':5,'Saturday':6};
        var today = new Date();
        today.setHours(0,0,0,0);

        for (var i = 0; i < 14; i++) {
            var d = new Date(today);
            d.setDate(d.getDate() + i);
            var dayName = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][d.getDay()];

            slots.forEach(function(slot) {
                if (slot.day_of_week === dayName) {
                    var parts = slot.start_time.split(':');
                    var slotDate = new Date(d);
                    slotDate.setHours(parseInt(parts[0]), parseInt(parts[1]), 0, 0);

                    // Skip slots in the past
                    if (slotDate <= new Date()) return;

                    var y = slotDate.getFullYear();
                    var mo = String(slotDate.getMonth() + 1).padStart(2, '0');
                    var da = String(slotDate.getDate()).padStart(2, '0');
                    var dateStr = y + '-' + mo + '-' + da;

                    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                    var label = dayName + ', ' + months[slotDate.getMonth()] + ' ' + slotDate.getDate() + ' — ' + formatTime12(slot.start_time) + ' to ' + formatTime12(slot.end_time);
                    var value = dateStr + 'T' + slot.start_time;

                    var opt = document.createElement('option');
                    opt.value = value;
                    opt.textContent = label;
                    select.appendChild(opt);
                }
            });
        }
    }

    function formatTime12(t) {
        var parts = t.split(':');
        var h = parseInt(parts[0]);
        var m = parts[1];
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + m + ' ' + ampm;
    }

    function onSlotSelected(val) {
        document.getElementById('scheduled_time_locked').value = val;
    }

    function openBooking(skillId, skillName) {
        document.getElementById('modal_skill_id').value = skillId;
        document.getElementById('modal_skill_name').textContent = skillName;
        setMinDateTime();

        var providerId = <?php echo $profile_id; ?>;

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

        // Fetch availability lock status
        fetch('../api/provider_availability.php?provider_id=' + providerId)
            .then(r => r.json())
            .then(data => {
                providerAvailabilityLocked = data.locked == 1;
                var lockInfo = document.getElementById('availability-lock-info');
                var freeGroup = document.getElementById('datetime-free-group');
                var slotGroup = document.getElementById('slot-picker-group');

                if (providerAvailabilityLocked && data.slots && data.slots.length > 0) {
                    lockInfo.style.display = 'block';
                    freeGroup.style.display = 'none';
                    slotGroup.style.display = 'block';
                    document.getElementById('slot_select').setAttribute('required', 'required');
                    document.getElementById('scheduled_time').removeAttribute('required');
                    generateSlotOptions(data.slots);
                } else {
                    lockInfo.style.display = 'none';
                    freeGroup.style.display = 'block';
                    slotGroup.style.display = 'none';
                    document.getElementById('scheduled_time').setAttribute('required', 'required');
                    document.getElementById('slot_select').removeAttribute('required');
                }
            })
            .catch(() => {});

        document.getElementById('bookingModal').classList.add('active');
    }

    function closeBooking() {
        document.getElementById('bookingModal').classList.remove('active');
    }

    document.getElementById('bookingModal').addEventListener('click', function (e) {
        if (e.target === this) closeBooking();
    });

    // Lightbox Logic
    function openLightbox(src) {
        document.getElementById('lightbox_img').src = src;
        document.getElementById('imageLightbox').classList.add('active');
    }

    function closeLightbox() {
        document.getElementById('imageLightbox').classList.remove('active');
        setTimeout(() => document.getElementById('lightbox_img').src = '', 400); // Clear after animation
    }

    document.getElementById('imageLightbox').addEventListener('click', function (e) {
        if (e.target === this) closeLightbox();
    });
</script>

<!-- Image Lightbox Modal -->
<div class="image-lightbox" id="imageLightbox">
    <button class="image-lightbox-close" onclick="closeLightbox()">&times;</button>
    <img src="" id="lightbox_img" alt="Enlarged Profile Photo">
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>