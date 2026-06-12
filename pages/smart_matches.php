<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'Smart Matches';
$success = '';
$error = '';

// Handle session booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_session') {
    $provider_id = intval($_POST['provider_id'] ?? 0);
    $skill_id = intval($_POST['skill_id'] ?? 0);
    $scheduled_time = $_POST['scheduled_time'] ?? '';
    $duration = intval($_POST['duration'] ?? 60);

    if ($scheduled_time !== '') {
        $timestamp = strtotime($scheduled_time);
        if ($timestamp !== false) {
            $scheduled_time = date('Y-m-d H:i:s', $timestamp);
        } else {
            $scheduled_time = '';
        }
    }

    if ($provider_id > 0 && $skill_id > 0 && $scheduled_time !== '') {
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

// Fetch current user location for city filtering
$my_user = $conn->query("SELECT location FROM users WHERE user_id = $user_id")->fetch_assoc();
$my_location = $my_user['location'] ?? '';

$filter_city = isset($_GET['filter_city']) ? 1 : 0;
$filter_time = isset($_GET['filter_time']) ? 1 : 0;

$city_condition = "";
if ($filter_city && !empty($my_location)) {
    $city_condition = " AND sm.user_b_location = '" . $conn->real_escape_string($my_location) . "' ";
}

// FEATURE 2: TIMEZONE-AWARE PERFECT MATCH ENGINE

// --- CQ: Smart Matches with availability overlap ---
// Uses the existing vw_smart_matches view (which already performs 
// multi-table JOINs with DENSE_RANK) and extends it with
// user_availability overlap logic using time range intersection.
$matches = $conn->query("
    SELECT 
        sm.user_b_id,
        sm.user_b_name,
        sm.user_b_location,
        (SELECT IF(profile_photo IS NOT NULL AND LENGTH(profile_photo) > 0, 1, 0) FROM users WHERE user_id = sm.user_b_id) AS has_photo,
        sm.user_b_reliability,
        sm.user_a_requests_skill_name,
        sm.user_a_requests_skill_id,
        sm.user_b_requests_skill_name,
        sm.user_b_requests_skill_id,
        sm.match_rank
    FROM vw_smart_matches sm
    WHERE sm.user_a_id = $user_id
    $city_condition
    ORDER BY sm.match_rank ASC
    LIMIT 20
");

// For each match, check availability overlap
$grouped_matches = [];
if ($matches && $matches->num_rows > 0) {
    while ($m = $matches->fetch_assoc()) {
        $partner_id = $m['user_b_id'];
        
        if (!isset($grouped_matches[$partner_id])) {
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
            
            if ($filter_time && empty($overlaps)) {
                $grouped_matches[$partner_id] = null; // Mark as filtered out
                continue;
            }

            // Get partner's reputation info
            $rep = $conn->query("SELECT r.completed_sessions, r.mentor_level FROM reputation r WHERE r.user_id = $partner_id")->fetch_assoc();
            
            $m['overlaps'] = $overlaps;
            $m['completed_sessions'] = $rep['completed_sessions'] ?? 0;
            $m['mentor_level'] = $rep['mentor_level'] ?? 'Novice';
            $m['skills_they_teach'] = [];
            $m['skills_they_learn'] = [];
            
            $grouped_matches[$partner_id] = $m;
        }

        if (isset($grouped_matches[$partner_id]) && $grouped_matches[$partner_id] !== null) {
            $teach_id = $m['user_a_requests_skill_id'];
            $teach_name = $m['user_a_requests_skill_name'];
            $learn_id = $m['user_b_requests_skill_id'];
            $learn_name = $m['user_b_requests_skill_name'];
            
            // Add teach skill if not present
            $found_teach = false;
            foreach ($grouped_matches[$partner_id]['skills_they_teach'] as $s) {
                if ($s['id'] == $teach_id) { $found_teach = true; break; }
            }
            if (!$found_teach) {
                $grouped_matches[$partner_id]['skills_they_teach'][] = ['id' => $teach_id, 'name' => $teach_name];
            }
            
            // Add learn skill if not present
            $found_learn = false;
            foreach ($grouped_matches[$partner_id]['skills_they_learn'] as $s) {
                if ($s['id'] == $learn_id) { $found_learn = true; break; }
            }
            if (!$found_learn) {
                $grouped_matches[$partner_id]['skills_they_learn'][] = ['id' => $learn_id, 'name' => $learn_name];
            }
        }
    }
}
$match_data = array_filter(array_values($grouped_matches));

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

        <div class="card mt-3 mb-2" style="padding: 12px 16px;">
            <form method="GET" style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin: 0;">
                <span style="font-weight: 600; font-size: 0.9rem; color: var(--text-color);"><i data-lucide="filter" class="lucide-sm"></i> Filters:</span>
                <label class="custom-checkbox">
                    <input type="checkbox" name="filter_city" value="1" <?php echo $filter_city ? 'checked' : ''; ?>>
                    <span class="checkmark"></span>
                    Same City <?php echo $my_location ? '('.htmlspecialchars($my_location).')' : ''; ?>
                </label>
                <label class="custom-checkbox">
                    <input type="checkbox" name="filter_time" value="1" <?php echo $filter_time ? 'checked' : ''; ?>>
                    <span class="checkmark"></span>
                    Same Available Slot
                </label>
                <button type="submit" class="btn btn-sm btn-primary" style="margin-left: auto;">Apply Filters</button>
                <?php if ($filter_city || $filter_time): ?>
                    <a href="smart_matches.php" class="btn btn-sm btn-secondary">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success mt-2"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger mt-2"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

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
                            <?php if (!empty($m['has_photo'])): ?>
                                <img src="../api/user_photo.php?user_id=<?php echo $m['user_b_id']; ?>" class="avatar-img avatar-lg" alt="<?php echo htmlspecialchars($m['user_b_name']); ?>" style="object-fit:cover;">
                            <?php else: ?>
                                <div class="avatar avatar-lg"><?php echo strtoupper(substr($m['user_b_name'], 0, 1)); ?></div>
                            <?php endif; ?>
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
                                        <span style="font-size:0.72rem; text-transform:uppercase; color:var(--text-muted); font-weight:600;">They can teach you:</span><br>
                                        <?php foreach ($m['skills_they_teach'] as $index => $skill): ?>
                                            <a href="skill_detail.php?id=<?php echo $skill['id']; ?>" style="font-weight:600; color:var(--primary); text-decoration:none;">
                                                <?php echo htmlspecialchars($skill['name']); ?>
                                            </a><?php echo $index < count($m['skills_they_teach']) - 1 ? '<span style="color:var(--text-muted);">, </span>' : ''; ?>
                                        <?php endforeach; ?>
                                    </div>
                                    <div style="background:var(--bg-hover); border-radius:var(--radius-sm); padding:8px 14px; flex:1; min-width:150px;">
                                        <span style="font-size:0.72rem; text-transform:uppercase; color:var(--text-muted); font-weight:600;">They want to learn:</span><br>
                                        <?php foreach ($m['skills_they_learn'] as $index => $skill): ?>
                                            <a href="skill_detail.php?id=<?php echo $skill['id']; ?>" style="font-weight:600; color:var(--success); text-decoration:none;">
                                                <?php echo htmlspecialchars($skill['name']); ?>
                                            </a><?php echo $index < count($m['skills_they_learn']) - 1 ? '<span style="color:var(--text-muted);">, </span>' : ''; ?>
                                        <?php endforeach; ?>
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
                                <div class="mt-2 flex gap-2" style="flex-wrap: wrap;">
                                    
                                    <!-- Book to Learn Button -->
                                    <div style="display:flex; align-items:center; gap:6px; background:var(--bg-hover); padding:4px; border-radius:var(--radius-sm); border:1px solid var(--border-color);">
                                        <?php if (count($m['skills_they_teach']) > 1): ?>
                                            <span style="font-size:0.75rem; font-weight:600; color:var(--text-muted); padding-left:4px;">Learn:</span>
                                            <select id="book_skill_<?php echo $m['user_b_id']; ?>" class="form-control" style="width:auto; height:30px; padding:0 8px; font-size:0.8rem; display:inline-block; border:none; background:var(--bg-input); color:var(--text-primary);">
                                                <?php foreach($m['skills_they_teach'] as $s): ?>
                                                    <option value="<?php echo $s['id']; ?>" style="background:var(--bg-input); color:var(--text-primary);"><?php echo htmlspecialchars($s['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button onclick="
                                                var sid = document.getElementById('book_skill_<?php echo $m['user_b_id']; ?>').value;
                                                openBooking(<?php echo $m['user_b_id']; ?>, '<?php echo htmlspecialchars(addslashes($m['user_b_name'])); ?>', sid);
                                            " class="btn btn-sm btn-primary"><i data-lucide="calendar" class="lucide-sm"></i> Book to Learn</button>
                                        <?php else: ?>
                                            <button onclick="openBooking(<?php echo $m['user_b_id']; ?>, '<?php echo htmlspecialchars(addslashes($m['user_b_name'])); ?>', <?php echo $m['skills_they_teach'][0]['id']; ?>)" class="btn btn-sm btn-primary"><i data-lucide="calendar" class="lucide-sm"></i> Book to Learn</button>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Offer to Teach Button -->
                                    <div style="display:flex; align-items:center; gap:6px; background:var(--bg-hover); padding:4px; border-radius:var(--radius-sm); border:1px solid var(--border-color);">
                                        <?php if (count($m['skills_they_learn']) > 1): ?>
                                            <span style="font-size:0.75rem; font-weight:600; color:var(--text-muted); padding-left:4px;">Teach:</span>
                                            <select id="offer_skill_<?php echo $m['user_b_id']; ?>" class="form-control" style="width:auto; height:30px; padding:0 8px; font-size:0.8rem; display:inline-block; border:none; background:var(--bg-input); color:var(--text-primary);">
                                                <?php foreach($m['skills_they_learn'] as $s): ?>
                                                    <option value="<?php echo $s['id']; ?>" data-name="<?php echo htmlspecialchars($s['name']); ?>" style="background:var(--bg-input); color:var(--text-primary);"><?php echo htmlspecialchars($s['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button onclick="
                                                var s = document.getElementById('offer_skill_<?php echo $m['user_b_id']; ?>');
                                                var sid = s.value;
                                                var sname = s.options[s.selectedIndex].getAttribute('data-name');
                                                var prefill = 'Hi! I saw in Smart Matches that you\'re looking to learn **' + sname + '**. I teach it! Let me know when you\'re free, or click below to book a session.';
                                                window.location.href = 'messages.php?start_with_user_id=<?php echo $m['user_b_id']; ?>&prefill_msg=' + encodeURIComponent(prefill) + '&offer_skill_id=' + sid + '&offer_skill_name=' + encodeURIComponent(sname);
                                            " class="btn btn-sm btn-success"><i data-lucide="award" class="lucide-sm"></i> Offer to Teach</button>
                                        <?php else: ?>
                                            <?php 
                                            $primary_learn_skill = $m['skills_they_learn'][0];
                                            $prefill = "Hi! I saw in Smart Matches that you're looking to learn **" . $primary_learn_skill['name'] . "**. I teach it! Let me know when you're free, or click below to book a session.";
                                            $prefill_encoded = urlencode($prefill);
                                            $offer_skill_id = $primary_learn_skill['id'];
                                            $offer_skill_name = urlencode($primary_learn_skill['name']);
                                            ?>
                                            <a href="messages.php?start_with_user_id=<?php echo $m['user_b_id']; ?>&prefill_msg=<?php echo $prefill_encoded; ?>&offer_skill_id=<?php echo $offer_skill_id; ?>&offer_skill_name=<?php echo $offer_skill_name; ?>" class="btn btn-sm btn-success"><i data-lucide="award" class="lucide-sm"></i> Offer to Teach</a>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div style="display:flex; align-items:center;">
                                        <a href="messages.php?start_with_user_id=<?php echo $m['user_b_id']; ?>" class="btn btn-sm btn-secondary"><i data-lucide="message-square" class="lucide-sm"></i> Message</a>
                                    </div>
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

<!-- Booking Modal -->
<div class="modal-overlay" id="bookingModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Book a Session</h3>
            <button class="modal-close" onclick="closeBooking()">&times;</button>
        </div>
        <form method="POST" id="bookingForm" onsubmit="return prepareBookingSubmit()">
            <input type="hidden" name="action" value="book_session">
            <input type="hidden" name="provider_id" id="modal_provider_id">
            <input type="hidden" name="skill_id" id="modal_skill_id">
            <input type="hidden" name="scheduled_time" id="final_scheduled_time" value="">
            <p style="color:var(--text-secondary); margin-bottom:8px;">Provider: <strong
                    id="modal_provider_name"></strong></p>
            <div id="surge-info" style="display:none; margin-bottom:16px; padding:8px 12px; border-radius:var(--radius-sm); font-size:0.85rem;"></div>
            
            <!-- Lock availability message -->
            <div id="availability-lock-info" style="display:none; background:rgba(220,20,60,0.06); border:1px solid rgba(220,20,60,0.25); border-radius:var(--radius-sm); padding:10px 14px; margin-bottom:16px; font-size:0.82rem; color:crimson;">
                <i data-lucide="lock" class="lucide-sm"></i>
                The teacher is not available outside of the preferred time slots. Please select one of the available slots provided by the teacher.
            </div>

            <!-- Free-form datetime (shown when unlocked) -->
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
            <div id="cost-display" style="text-align:center; margin-bottom:12px; font-size:0.9rem; color:var(--text-secondary);">Estimated cost: <strong id="cost-value">10.00 TC</strong></div>
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

    function openBooking(providerId, providerName, skillId) {
        document.getElementById('modal_provider_id').value = providerId;
        document.getElementById('modal_skill_id').value = skillId;
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
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
