<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = 'My Sessions';
$success = '';
$error = '';

// Handle session actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    $session_id = intval($_POST['session_id'] ?? 0);

    if ($_POST['action'] === 'submit_proof' && $session_id > 0) {
        $submission_note = trim($_POST['submission_note'] ?? '');
        if ($submission_note) {
            $stmt = $conn->prepare("UPDATE exchange_sessions SET status = 'under-review', submission_note = ? WHERE session_id = ? AND status = 'scheduled'");
            $stmt->bind_param("si", $submission_note, $session_id);
            if ($stmt->execute()) {
                $success = 'Proof submitted! Admin will verify the session shortly.';
            } else {
                $error = 'Failed to submit proof.';
            }
            $stmt->close();
        }
    }

    if ($_POST['action'] === 'cancel' && $session_id > 0) {
        $conn->query("UPDATE exchange_sessions SET status = 'cancelled' WHERE session_id = $session_id AND (requester_id = $user_id OR provider_id = $user_id)");
        // Update cancelled count
        $conn->query("UPDATE reputation SET cancelled_sessions = cancelled_sessions + 1 WHERE user_id = $user_id");
        $success = 'Session cancelled.';
    }

    if ($_POST['action'] === 'submit_review') {
        $session_id = intval($_POST['session_id'] ?? 0);
        $rating = intval($_POST['rating'] ?? 5);
        $comment = trim($_POST['comment'] ?? '');

        $rating = max(1, min(5, $rating));

        $stmt = $conn->prepare("UPDATE exchange_sessions SET rating = ?, comment = ?, feedback_given = TRUE WHERE session_id = ?");
        $stmt->bind_param("isi", $rating, $comment, $session_id);
        if ($stmt->execute()) {
            // Update provider reputation score (running average)
            $session = $conn->query("SELECT provider_id FROM exchange_sessions WHERE session_id = $session_id")->fetch_assoc();
            if ($session) {
                $provider_id = $session['provider_id'];
                $avg = $conn->query("SELECT AVG(rating) as avg_rating FROM exchange_sessions WHERE provider_id = $provider_id AND rating IS NOT NULL")->fetch_assoc();
                if ($avg && $avg['avg_rating']) {
                    $new_score = round($avg['avg_rating'], 2);
                    $conn->query("UPDATE reputation SET current_score = $new_score WHERE user_id = $provider_id");
                }
            }

            $success = 'Review submitted. Thank you!';
        }
        $stmt->close();
    }
}

// Fetch sessions
$filter = $_GET['filter'] ?? 'all';
$filter_sql = "";
if ($filter === 'scheduled')
    $filter_sql = "AND es.status = 'scheduled'";
elseif ($filter === 'under-review')
    $filter_sql = "AND es.status = 'under-review'";
elseif ($filter === 'completed')
    $filter_sql = "AND es.status = 'completed'";
elseif ($filter === 'cancelled')
    $filter_sql = "AND es.status = 'cancelled'";

$sessions = $conn->query("
    SELECT es.*, s.skill_name,
           u_req.name AS requester_name,
           u_prov.name AS provider_name
    FROM exchange_sessions es
    JOIN skills s ON es.skill_id = s.skill_id
    JOIN users u_req ON es.requester_id = u_req.user_id
    JOIN users u_prov ON es.provider_id = u_prov.user_id
    WHERE (es.requester_id = $user_id OR es.provider_id = $user_id)
    $filter_sql
    ORDER BY es.scheduled_time DESC
");

include __DIR__ . '/../includes/header.php';
?>

<div class="page-wrapper">
    <div class="container">
        <h1 class="page-title">My Sessions</h1>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Filter Tabs -->
        <div class="tabs">
            <a href="?filter=all" class="tab-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="?filter=scheduled" class="tab-btn <?php echo $filter === 'scheduled' ? 'active' : ''; ?>">Scheduled</a>
            <a href="?filter=under-review" class="tab-btn <?php echo $filter === 'under-review' ? 'active' : ''; ?>">Under Review</a>
            <a href="?filter=completed" class="tab-btn <?php echo $filter === 'completed' ? 'active' : ''; ?>">Completed</a>
            <a href="?filter=cancelled" class="tab-btn <?php echo $filter === 'cancelled' ? 'active' : ''; ?>">Cancelled</a>
        </div>

        <!-- Sessions List -->
        <?php if ($sessions->num_rows > 0): ?>
            <?php while ($s = $sessions->fetch_assoc()):
                $is_requester = ($s['requester_id'] == $user_id);
                $partner_name = $is_requester ? $s['provider_name'] : $s['requester_name'];
                $role = $is_requester ? 'Learner' : 'Teacher';

                $status_class = 'badge-warning';
                if ($s['status'] === 'completed')
                    $status_class = 'badge-success';
                elseif ($s['status'] === 'cancelled')
                    $status_class = 'badge-danger';
                ?>
                <div class="session-card">
                    <div class="avatar"><?php echo strtoupper(substr($partner_name, 0, 1)); ?></div>
                    <div class="session-info">
                        <h4><?php echo htmlspecialchars($s['skill_name']); ?></h4>
                        <p>
                            with <strong><?php echo htmlspecialchars($partner_name); ?></strong>
                            (You: <?php echo $role; ?>)
                            &middot; <?php echo date('M d, Y h:i A', strtotime($s['scheduled_time'])); ?>
                            &middot; <?php echo $s['session_duration']; ?> min
                            &middot; <?php echo number_format($s['time_credit_transfer'], 2); ?> TC
                        </p>
                    </div>
                    <div class="session-actions">
                        <span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($s['status']); ?></span>

                        <?php if ($s['status'] === 'scheduled'): ?>
                            <button class="btn btn-sm btn-success" onclick="openComplete(<?php echo $s['session_id']; ?>)">Submit Proof</button>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="session_id" value="<?php echo $s['session_id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($s['status'] === 'completed' && !$s['feedback_given'] && $is_requester): ?>
                            <button class="btn btn-sm btn-primary"
                                onclick="openReview(<?php echo $s['session_id']; ?>, '<?php echo htmlspecialchars($s['skill_name']); ?>')">Review</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="card">
                <div class="empty-state">
                    <div class="icon">&#128197;</div>
                    <p>No sessions found. <a href="../pages/skills.php">Browse skills</a> to book your first
                        session!</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Review Modal -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Leave a Review</h3>
            <button class="modal-close" onclick="closeReview()">&times;</button>
        </div>
        <p style="color:var(--text-secondary); margin-bottom:16px;" id="review_skill_name"></p>
        <form method="POST">
            <input type="hidden" name="action" value="submit_review">
            <input type="hidden" name="session_id" id="review_session_id">
            <div class="form-group">
                <label for="rating">Rating (1-5)</label>
                <select name="rating" id="rating" class="form-control">
                    <option value="5">&#11088; 5 - Excellent</option>
                    <option value="4">&#11088; 4 - Very Good</option>
                    <option value="3">&#11088; 3 - Good</option>
                    <option value="2">&#11088; 2 - Fair</option>
                    <option value="1">&#11088; 1 - Poor</option>
                </select>
            </div>
            <div class="form-group">
                <label for="comment">Comment</label>
                <textarea name="comment" id="comment" class="form-control"
                    placeholder="Share your experience..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Submit Review</button>
        </form>
    </div>
</div>

<script>
    function openReview(sessionId, skillName) {
        document.getElementById('review_session_id').value = sessionId;
        document.getElementById('review_skill_name').textContent = 'Session: ' + skillName;
        document.getElementById('reviewModal').classList.add('active');
    }

    function closeReview() {
        document.getElementById('reviewModal').classList.remove('active');
    }

    document.getElementById('reviewModal').addEventListener('click', function (e) {
        if (e.target === this) closeReview();
    });

    function openComplete(sessionId) {
        document.getElementById('complete_session_id').value = sessionId;
        document.getElementById('completeModal').classList.add('active');
    }

    function closeComplete() {
        document.getElementById('completeModal').classList.remove('active');
    }

    document.getElementById('completeModal').addEventListener('click', function (e) {
        if (e.target === this) closeComplete();
    });
</script>

<!-- Complete Modal -->
<div class="modal-overlay" id="completeModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Submit Proof of Completion</h3>
            <button class="modal-close" onclick="closeComplete()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="submit_proof">
            <input type="hidden" name="session_id" id="complete_session_id">
            <div class="form-group">
                <label for="submission_note">Proof / Note</label>
                <textarea name="submission_note" id="submission_note" class="form-control" placeholder="Describe what was covered in the session..." required></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Submit for Admin Review</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>