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
            $stmt = $conn->prepare("UPDATE users SET name = ?, location = ?, bio = ? WHERE user_id = ?");
            $stmt->bind_param("sssi", $name, $location, $bio, $user_id);
            if ($stmt->execute()) {
                $_SESSION['user_name'] = $name;
                $success = 'Profile updated successfully.';
            } else {
                $error = 'Failed to update profile.';
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
            $stmt = $conn->prepare("INSERT INTO user_availability (user_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $user_id, $day, $start, $end);
            $stmt->execute();
            $stmt->close();
            $success = 'Availability added.';
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
}

// Fetch user data
$user = $conn->query("SELECT * FROM users WHERE user_id = $user_id")->fetch_assoc();
$rep = $conn->query("SELECT * FROM reputation WHERE user_id = $user_id")->fetch_assoc();

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
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
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
                        <span
                            style="font-size: 2rem; font-weight: 700; color: var(--orange-primary);"><?php echo $rep ? $rep['current_score'] : '5.00'; ?></span>
                        <span style="color: var(--text-secondary); font-size: 1.1rem;">/5.00</span>
                        <div style="margin-top: 8px;">
                            <div class="progress-bar">
                                <div class="fill"
                                    style="width: <?php echo ($rep ? ($rep['current_score'] / 5) * 100 : 100); ?>%">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="grid-2 mt-2" style="text-align:center;">
                        <div>
                            <strong><?php echo $rep ? $rep['completed_sessions'] : 0; ?></strong>
                            <br><span style="color:var(--text-muted); font-size:0.8rem;">Completed</span>
                        </div>
                        <div>
                            <strong><?php echo $rep ? $rep['cancelled_sessions'] : 0; ?></strong>
                            <br><span style="color:var(--text-muted); font-size:0.8rem;">Cancelled</span>
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
                        <?php echo date('F j, Y', strtotime($user['created_at'])); ?></p>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 4px;">Last active:
                        <?php echo date('M j, Y g:i A', strtotime($user['last_active_at'])); ?></p>
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
            <div class="card-header">
                <h3>My Availability</h3>
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