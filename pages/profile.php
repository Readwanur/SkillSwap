<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'Profile';
$success = '';
$error = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        if (!empty($name)) {
            $img_data = null;
            $file_mime = null;
            if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['profile_photo']['tmp_name'];
                $file_mime = mime_content_type($file_tmp);
                if (strpos($file_mime, 'image/') === 0) {
                    $img_data = file_get_contents($file_tmp);
                }
            }

            if ($img_data) {
                $stmt = $conn->prepare("UPDATE users SET name = ?, location = ?, bio = ?, profile_photo = ?, profile_photo_mime = ? WHERE user_id = ?");
                $null = NULL;
                $stmt->bind_param("sssbsi", $name, $location, $bio, $null, $file_mime, $user_id);
                $stmt->send_long_data(3, $img_data);
            } else {
                $stmt = $conn->prepare("UPDATE users SET name = ?, location = ?, bio = ? WHERE user_id = ?");
                $stmt->bind_param("sssi", $name, $location, $bio, $user_id);
            }

            if ($stmt->execute()) {
                $_SESSION['user_name'] = $name;
                $success = 'Profile updated successfully.';
            } else {
                $error = 'Failed to update profile. Error: ' . $stmt->error;
            }
            $stmt->close();
        }
    }

    if ($_POST['action'] === 'add_offered') {
        $skill_id = intval($_POST['skill_id'] ?? 0);
        if ($skill_id > 0) {
            $stmt = $conn->prepare("INSERT IGNORE INTO user_skills_offered (user_id, skill_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user_id, $skill_id);
            $stmt->execute();
            $stmt->close();
            $success = 'Skill added to your offerings.';
        }
    }

    if ($_POST['action'] === 'remove_offered') {
        $skill_id = intval($_POST['skill_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM user_skills_offered WHERE user_id = ? AND skill_id = ?");
        $stmt->bind_param("ii", $user_id, $skill_id);
        $stmt->execute();
        $stmt->close();
        $success = 'Skill removed from your offerings.';
    }

    if ($_POST['action'] === 'add_requested') {
        $skill_id = intval($_POST['skill_id'] ?? 0);
        if ($skill_id > 0) {
            $stmt = $conn->prepare("INSERT IGNORE INTO user_skills_requested (user_id, skill_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $user_id, $skill_id);
            $stmt->execute();
            $stmt->close();
            $success = 'Skill added to your learning list.';
        }
    }

    if ($_POST['action'] === 'remove_requested') {
        $skill_id = intval($_POST['skill_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM user_skills_requested WHERE user_id = ? AND skill_id = ?");
        $stmt->bind_param("ii", $user_id, $skill_id);
        $stmt->execute();
        $stmt->close();
        $success = 'Skill removed from your learning list.';
    }

    if ($_POST['action'] === 'add_availability') {
        $day = $_POST['day_of_week'] ?? '';
        $start = $_POST['start_time'] ?? '';
        $end = $_POST['end_time'] ?? '';
        if ($day && $start && $end) {
            if (strtotime($start) >= strtotime($end)) {
                $error = 'End time must be after start time.';
            } else {
                // Check if an overlapping slot already exists
                $check_stmt = $conn->prepare("SELECT availability_id FROM user_availability WHERE user_id = ? AND day_of_week = ? AND start_time < ? AND end_time > ?");
                $check_stmt->bind_param("isss", $user_id, $day, $end, $start);
                $check_stmt->execute();
                $result = $check_stmt->get_result();

                if ($result->num_rows > 0) {
                    $error = 'This time slot overlaps with an existing availability.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO user_availability (user_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("isss", $user_id, $day, $start, $end);
                    $stmt->execute();
                    $stmt->close();
                    $success = 'Availability added.';
                }
                $check_stmt->close();
            }
        }
    }

    if ($_POST['action'] === 'remove_availability') {
        $avail_id = intval($_POST['availability_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM user_availability WHERE availability_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $avail_id, $user_id);
        $stmt->execute();
        $stmt->close();
        $success = 'Availability removed.';
    }

    if ($_POST['action'] === 'toggle_availability_lock') {
        $locked = isset($_POST['availability_locked']) ? 1 : 0;
        
        // Prevent locking if no slots exist
        $check_slots = $conn->query("SELECT COUNT(*) AS count FROM user_availability WHERE user_id = $user_id")->fetch_assoc()['count'];
        
        if ($locked && $check_slots == 0) {
            $error = 'You must add at least one availability slot before enabling the lock.';
        } else {
            $stmt = $conn->prepare("UPDATE users SET availability_locked = ? WHERE user_id = ?");
            $stmt->bind_param("ii", $locked, $user_id);
            $stmt->execute();
            $stmt->close();
            $success = 'Availability lock setting updated.';
        }
    }
}

// Fetch user data
$res = $conn->query("SELECT user_id, name, email, location, bio, created_at, last_active_at, IF(profile_photo IS NOT NULL AND LENGTH(profile_photo) > 0, 1, 0) AS profile_photo FROM users WHERE user_id = $user_id");
if (!$res) {
    die("SQL Error: " . $conn->error);
}
$user = $res->fetch_assoc();
$rep = $conn->query("SELECT * FROM reputation WHERE user_id = $user_id")->fetch_assoc();

$guided_sessions_query = $conn->query("SELECT COUNT(*) AS total FROM exchange_sessions WHERE provider_id = $user_id AND status = 'completed'");
$guided_sessions = $guided_sessions_query->fetch_assoc()['total'] ?? 0;

$learned_sessions_query = $conn->query("SELECT COUNT(*) AS total FROM exchange_sessions WHERE requester_id = $user_id AND status = 'completed'");
$learned_sessions = $learned_sessions_query->fetch_assoc()['total'] ?? 0;

$total_completed = $guided_sessions + $learned_sessions;

// Fetch lock status
$lock_status_res = $conn->query("SELECT availability_locked FROM users WHERE user_id = $user_id");
$is_locked = ($lock_status_res && $lock_status_res->fetch_assoc()['availability_locked'] == 1);
$slots_count = $conn->query("SELECT COUNT(*) AS count FROM user_availability WHERE user_id = $user_id")->fetch_assoc()['count'];

// Fetch skills offered & requested
$offered = $conn->query("SELECT s.skill_id, s.skill_name FROM user_skills_offered uso JOIN skills s ON uso.skill_id = s.skill_id WHERE uso.user_id = $user_id");
$requested = $conn->query("SELECT s.skill_id, s.skill_name FROM user_skills_requested usr JOIN skills s ON usr.skill_id = s.skill_id WHERE usr.user_id = $user_id");

// All skills for dropdowns
$all_skills = $conn->query("SELECT skill_id, skill_name FROM skills ORDER BY skill_name");

// Sorting parameters
$sort = trim($_GET['sort'] ?? 'day');
$order = trim($_GET['order'] ?? 'asc');

$allowed_sorts = [
    'day' => "FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')",
    'start' => 'start_time',
    'end' => 'end_time'
];

$sort_col = $allowed_sorts[$sort] ?? $allowed_sorts['day'];
$order_sql = (strtolower($order) === 'desc') ? 'DESC' : 'ASC';

// Availability
$availability = $conn->query("SELECT * FROM user_availability WHERE user_id = $user_id ORDER BY $sort_col $order_sql");

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <h1 class="page-title">My Profile</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="grid-2">
            <!-- Profile Info -->
            <div class="card">
                <h3 class="section-title">Personal Information</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="profile-photo-upload-container">
                        <div>
                            <?php if (!empty($user['profile_photo'])): ?>
                                <img src="../api/user_photo.php?user_id=<?php echo $user_id; ?>&v=<?php echo time(); ?>"
                                    class="avatar-img avatar-lg" alt="Profile Photo">
                            <?php else: ?>
                                <div class="avatar avatar-lg" style="margin: 0;">
                                    <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;">
                            <label style="display:block; font-weight:600; margin-bottom:8px;">Profile Picture</label>
                            <div class="upload-btn-wrapper">
                                <button type="button" class="btn btn-sm btn-secondary"><i data-lucide="upload"
                                        class="lucide-sm"></i> Choose Image</button>
                                <input type="file" name="profile_photo" accept="image/jpeg, image/png, image/webp" />
                            </div>
                            <p style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Recommended: Square
                                image, max 2MB. Updates when you click Save Changes.</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="name">Full Name</label>
                        <input type="text" id="name" name="name" class="form-control"
                            value="<?php echo htmlspecialchars($user['name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>"
                            disabled>
                    </div>
                    <div class="form-group">
                        <label for="location">Location</label>
                        <input type="text" id="location" name="location" class="form-control city-autocomplete"
                            value="<?php echo htmlspecialchars($user['location'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="bio">Bio</label>
                        <textarea id="bio" name="bio"
                            class="form-control"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" id="btn-save-profile">Save Changes</button>
                </form>
            </div>

            <!-- Reputation -->
            <div>
                <div class="card mb-2">
                    <h3 class="section-title">Reputation</h3>
                    <div style="text-align:center; padding: 10px 0;">
                        <span style="font-size: 2rem; font-weight: 700; color: var(--orange-primary);">
                            <?php 
                            $raw_score = $rep ? $rep['current_score'] : null;
                            echo $raw_score !== null ? number_format((float)$raw_score, 2) : '<span style="font-size:1.2rem;color:var(--text-muted);">No Ratings Yet</span>'; 
                            ?>
                        </span>
                        <?php if ($raw_score !== null): ?>
                        <span style="color: var(--text-secondary); font-size: 1.1rem;">/5.00</span>
                        <?php endif; ?>
                        <div style="margin-top: 8px;">
                            <div class="progress-bar">
                                <div class="fill"
                                    style="width: <?php echo ($raw_score !== null ? ($raw_score / 5) * 100 : 0); ?>%">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; justify-content: space-around; text-align:center; margin-top: 15px;">
                        <div>
                            <strong><?php echo $guided_sessions; ?></strong>
                            <br><strong style="color:var(--text-muted); font-size:0.8rem;">Guided</strong>
                        </div>
                        <div>
                            <strong><?php echo $learned_sessions; ?></strong>
                            <br><strong style="color:var(--text-muted); font-size:0.8rem;">Learned</strong>
                        </div>
                        <div>
                            <strong><?php echo $rep ? $rep['cancelled_sessions'] : 0; ?></strong>
                            <br><strong style="color:var(--text-muted); font-size:0.8rem;">Cancelled</strong>
                        </div>
                        <div>
                            <strong><?php echo $total_completed; ?></strong>
                            <br><strong style="color:var(--text-muted); font-size:0.8rem;">Total</strong>
                        </div>
                    </div>
                    <div class="text-center mt-2">
                        <span
                            class="badge badge-orange"><?php echo htmlspecialchars($rep ? $rep['mentor_level'] : 'Novice'); ?></span>
                    </div>
                </div>

                <div class="card">
                    <h3 class="section-title">Member Since</h3>
                    <p style="color: var(--text-secondary);">
                        <?php echo date('F j, Y', strtotime($user['created_at'])); ?>
                    </p>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 4px;">Last active:
                        <?php echo date('M j, Y g:i A', strtotime($user['last_active_at'])); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Skills Offered -->
        <div class="card mt-3">
            <div class="card-header">
                <h3>Skills I Teach</h3>
            </div>
            <div class="mb-2">
                <?php while ($offered && $s = $offered->fetch_assoc()): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="remove_offered">
                        <input type="hidden" name="skill_id" value="<?php echo $s['skill_id']; ?>">
                        <span class="skill-tag"><?php echo htmlspecialchars($s['skill_name']); ?>
                            <button type="submit"
                                style="background:none; border:none; color:var(--danger); cursor:pointer; font-size:0.8rem;">&times;</button>
                        </span>
                    </form>
                <?php endwhile; ?>
            </div>
            <form method="POST" class="flex gap-1 items-center">
                <input type="hidden" name="action" value="add_offered">
                <select name="skill_id" class="form-control" style="max-width: 250px;" required>
                    <option value="">Select a skill...</option>
                    <?php
                    if ($all_skills) {
                        $all_skills->data_seek(0);
                        while ($s = $all_skills->fetch_assoc()): ?>
                            <option value="<?php echo $s['skill_id']; ?>"><?php echo htmlspecialchars($s['skill_name']); ?>
                            </option>
                        <?php endwhile;
                    } ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary">Add</button>
            </form>
        </div>

        <!-- Skills Requested -->
        <div class="card mt-2">
            <div class="card-header">
                <h3>Skills I Want to Learn</h3>
            </div>
            <div class="mb-2">
                <?php while ($requested && $s = $requested->fetch_assoc()): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="action" value="remove_requested">
                        <input type="hidden" name="skill_id" value="<?php echo $s['skill_id']; ?>">
                        <span class="skill-tag"><?php echo htmlspecialchars($s['skill_name']); ?>
                            <button type="submit"
                                style="background:none; border:none; color:var(--danger); cursor:pointer; font-size:0.8rem;">&times;</button>
                        </span>
                    </form>
                <?php endwhile; ?>
            </div>
            <form method="POST" class="flex gap-1 items-center">
                <input type="hidden" name="action" value="add_requested">
                <select name="skill_id" class="form-control" style="max-width: 250px;" required>
                    <option value="">Select a skill...</option>
                    <?php
                    if ($all_skills) {
                        $all_skills->data_seek(0);
                        while ($s = $all_skills->fetch_assoc()): ?>
                            <option value="<?php echo $s['skill_id']; ?>"><?php echo htmlspecialchars($s['skill_name']); ?>
                            </option>
                        <?php endwhile;
                    } ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary">Add</button>
            </form>
        </div>

        <!-- Availability -->
        <div class="card mt-2">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <h3>My Availability</h3>
                
                <form method="POST" style="display:flex; align-items:center; gap:10px; background:var(--bg-secondary); padding:8px 12px; border-radius:var(--radius-sm);">
                    <input type="hidden" name="action" value="toggle_availability_lock">
                    <label for="availability_locked" style="font-size:0.9rem; font-weight:600; cursor:pointer; color:var(--text-secondary);">Lock</label>
                    <div class="toggle-switch">
                        <input type="checkbox" id="availability_locked" name="availability_locked" value="1" 
                            <?php echo $is_locked ? 'checked' : ''; ?>
                            <?php echo $slots_count == 0 ? 'disabled' : ''; ?>
                            onchange="this.form.submit()">
                        <label for="availability_locked" class="toggle-label"></label>
                    </div>
                    <?php if ($slots_count == 0): ?>
                        <small style="color:var(--text-muted); font-size:0.75rem;">(Add slots first)</small>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($availability && $availability->num_rows > 0): ?>
                <div class="table-wrapper mb-2">
                    <table>
                        <thead>
                            <tr>
                                <th>
                                    <span class="th-content">
                                        <span>Day</span>
                                        <?php echo renderTableSort('day', $sort, $order); ?>
                                    </span>
                                </th>
                                <th>
                                    <span class="th-content">
                                        <span>Start</span>
                                        <?php echo renderTableSort('start', $sort, $order); ?>
                                    </span>
                                </th>
                                <th>
                                    <span class="th-content">
                                        <span>End</span>
                                        <?php echo renderTableSort('end', $sort, $order); ?>
                                    </span>
                                </th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($availability && $a = $availability->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $a['day_of_week']; ?></td>
                                    <td><?php echo date('h:i A', strtotime($a['start_time'])); ?></td>
                                    <td><?php echo date('h:i A', strtotime($a['end_time'])); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="remove_availability">
                                            <input type="hidden" name="availability_id"
                                                value="<?php echo $a['availability_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <form method="POST" class="flex flex-wrap gap-1 items-center">
                <input type="hidden" name="action" value="add_availability">
                <select name="day_of_week" class="form-control" style="max-width:150px;" required>
                    <option value="">Day...</option>
                    <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day): ?>
                        <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="time" name="start_time" class="form-control" style="max-width:140px;" required>
                <input type="time" name="end_time" class="form-control" style="max-width:140px;" required>
                <button type="submit" class="btn btn-sm btn-primary">Add Slot</button>
            </form>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>