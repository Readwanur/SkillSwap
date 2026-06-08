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

    if ($_POST['action'] === 'complete_session_otp' && $session_id > 0) {
        $otp = trim($_POST['otp'] ?? '');
        // --- STORED PROCEDURE: sp_complete_session ---
        // Replaces multi-query PHP transaction with a single atomic DB call.
        // The procedure validates OTP, transfers credits, logs transaction,
        // and updates reputation (trigger TR-3 auto-updates mentor_level).
        $stmt = $conn->prepare("CALL sp_complete_session(?, ?, ?, @sp_status, @sp_message)");
        $stmt->bind_param("iis", $session_id, $user_id, $otp);
        $stmt->execute();
        $stmt->close();
        $result = $conn->query("SELECT @sp_status AS status, @sp_message AS message")->fetch_assoc();
        if ($result['status'] === 'success') {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }


    if ($_POST['action'] === 'submit_proof' && $session_id > 0) {
        $type = $_POST['dispute_action'] ?? 'proof';
        $submission_note = trim($_POST['submission_note'] ?? '');
        if (empty($submission_note)) {
            $error = 'Please provide details or notes.';
        } else {
            if ($type === 'proof') {
                $stmt = $conn->prepare("UPDATE exchange_sessions SET status = 'under-review', submission_note = ? WHERE session_id = ? AND status = 'scheduled' AND (provider_id = ? OR requester_id = ?)");
                $stmt->bind_param("siii", $submission_note, $session_id, $user_id, $user_id);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $success = 'Proof submitted! Admin will verify the session shortly.';
                } else {
                    $error = 'Failed to submit proof. Unauthorized or invalid session.';
                }
                $stmt->close();
            } else {
                $conn->begin_transaction();
                try {
                    $stmt1 = $conn->prepare("UPDATE exchange_sessions SET status = 'disputed' WHERE session_id = ? AND status IN ('scheduled', 'under-review') AND (provider_id = ? OR requester_id = ?)");
                    $stmt1->bind_param("iii", $session_id, $user_id, $user_id);
                    $stmt1->execute();

                    if ($stmt1->affected_rows > 0) {
                        $stmt2 = $conn->prepare("INSERT INTO disputes (session_id, filed_by_user_id, reason) VALUES (?, ?, ?)");
                        $stmt2->bind_param("iis", $session_id, $user_id, $submission_note);
                        $stmt2->execute();
                        $stmt2->close();

                        $conn->commit();
                        $success = 'Formal dispute filed successfully! Admin has been notified and the session is locked.';
                    } else {
                        $conn->rollback();
                        $error = 'Failed to file dispute. Session may not be in scheduled or under-review state.';
                    }
                    $stmt1->close();
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }

    if ($_POST['action'] === 'cancel' && $session_id > 0) {
        $sess = $conn->query("SELECT requester_id, time_credit_transfer, status FROM exchange_sessions WHERE session_id = $session_id AND (requester_id = $user_id OR provider_id = $user_id)")->fetch_assoc();
        if ($sess && $sess['status'] === 'scheduled') {
            $conn->begin_transaction();
            try {
                $conn->query("UPDATE exchange_sessions SET status = 'cancelled' WHERE session_id = $session_id");
                $amount = $sess['time_credit_transfer'];
                $req_id = $sess['requester_id'];
                $conn->query("UPDATE wallet SET balance = balance + $amount WHERE user_id = $req_id");
                $conn->query("UPDATE reputation SET cancelled_sessions = cancelled_sessions + 1 WHERE user_id = $user_id");
                $conn->commit();
                $success = 'Session cancelled and credits refunded.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to cancel session.';
            }
        }
    }

    if ($_POST['action'] === 'submit_review') {
        $session_id = intval($_POST['session_id'] ?? 0);
        $rating = intval($_POST['rating'] ?? 5);
        $comment = trim($_POST['comment'] ?? '');

        $rating = max(1, min(5, $rating));

        $stmt = $conn->prepare("UPDATE exchange_sessions SET rating = ?, comment = ?, feedback_given = TRUE WHERE session_id = ? AND requester_id = ?");
        $stmt->bind_param("isii", $rating, $comment, $session_id, $user_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            // NOTE: Trigger TR-2 (trg_after_session_rated) automatically
            // recalculates the provider's avg reputation score using
            // a correlated subquery: AVG(rating) FROM exchange_sessions.
            // No manual PHP recalculation needed.
            $success = 'Review submitted. Thank you!';
        } else {
            $error = 'Failed to submit review. Unauthorized or invalid session.';
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
elseif ($filter === 'disputed')
    $filter_sql = "AND es.status = 'disputed'";

$sessions = $conn->query("
    SELECT es.*, s.skill_name,
           u_req.name AS requester_name,
           u_prov.name AS provider_name,
           IF(u_req.profile_photo IS NOT NULL AND LENGTH(u_req.profile_photo) > 0, 1, 0) AS req_has_photo,
           IF(u_prov.profile_photo IS NOT NULL AND LENGTH(u_prov.profile_photo) > 0, 1, 0) AS prov_has_photo
    FROM exchange_sessions es
    JOIN skills s ON es.skill_id = s.skill_id
    JOIN users u_req ON es.requester_id = u_req.user_id
    JOIN users u_prov ON es.provider_id = u_prov.user_id
    WHERE (es.requester_id = $user_id OR es.provider_id = $user_id)
    $filter_sql
    ORDER BY es.session_id DESC
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
            <a href="?filter=scheduled"
                class="tab-btn <?php echo $filter === 'scheduled' ? 'active' : ''; ?>">Scheduled</a>
            <a href="?filter=under-review"
                class="tab-btn <?php echo $filter === 'under-review' ? 'active' : ''; ?>">Under Review</a>
            <a href="?filter=completed"
                class="tab-btn <?php echo $filter === 'completed' ? 'active' : ''; ?>">Completed</a>
            <a href="?filter=cancelled"
                class="tab-btn <?php echo $filter === 'cancelled' ? 'active' : ''; ?>">Cancelled</a>
            <a href="?filter=disputed"
                class="tab-btn <?php echo $filter === 'disputed' ? 'active' : ''; ?>">Disputed</a>
        </div>

        <!-- Sessions List -->
        <?php if ($sessions->num_rows > 0): ?>
            <?php while ($s = $sessions->fetch_assoc()):
                $is_requester = ($s['requester_id'] == $user_id);
                $partner_name = $is_requester ? $s['provider_name'] : $s['requester_name'];
                $partner_id = $is_requester ? $s['provider_id'] : $s['requester_id'];
                $partner_has_photo = $is_requester ? $s['prov_has_photo'] : $s['req_has_photo'];
                $role = $is_requester ? 'Learner' : 'Teacher';

                $status_class = 'badge-warning';
                if ($s['status'] === 'completed')
                    $status_class = 'badge-success';
                elseif ($s['status'] === 'cancelled')
                    $status_class = 'badge-danger';
                elseif ($s['status'] === 'disputed')
                    $status_class = 'badge-danger';
                ?>
                <div class="session-card">
                    <?php if (!empty($partner_has_photo)): ?>
                        <img src="../api/user_photo.php?user_id=<?php echo $partner_id; ?>" class="avatar-img" alt="<?php echo htmlspecialchars($partner_name); ?>" style="object-fit:cover; width: 45px; height: 45px; flex-shrink: 0;">
                    <?php else: ?>
                        <div class="avatar"><?php echo strtoupper(substr($partner_name, 0, 1)); ?></div>
                    <?php endif; ?>
                    <div class="session-info">
                        <h4><?php echo htmlspecialchars($s['skill_name']); ?></h4>
                        <p>
                            with <strong><?php echo htmlspecialchars($partner_name); ?></strong>
                            (You: <?php echo $role; ?>)
                            &middot; <?php echo date('M d, Y h:i A', strtotime($s['scheduled_time'])); ?>
                            &middot; <?php echo $s['session_duration']; ?> min
                            &middot; <?php echo number_format($s['time_credit_transfer'], 2); ?> TC
                            <?php if ($is_requester && $s['status'] === 'scheduled'): ?>
                                <br><span class="badge badge-info mt-1">Your OTP:
                                    <?php echo htmlspecialchars($s['completion_otp']); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="session-actions">
                        <span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($s['status']); ?></span>

                        <a href="../pages/messages.php?start_with_user_id=<?php echo $partner_id; ?>"
                            class="btn btn-secondary btn-sm" style="margin-left: 5px;">Message User</a>

                        <?php if ($s['status'] === 'scheduled' || $s['status'] === 'under-review'): ?>
                            <?php if ($s['status'] === 'scheduled' && !$is_requester): ?>
                                <button class="btn btn-sm btn-success" onclick="openComplete(<?php echo $s['session_id']; ?>)">Complete
                                    Session</button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-secondary" onclick="openDispute(<?php echo $s['session_id']; ?>)">Submit
                                Proof / Dispute</button>
                            <?php if ($s['status'] === 'scheduled'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="session_id" value="<?php echo $s['session_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                                </form>
                            <?php endif; ?>
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

    function openDispute(sessionId) {
        document.getElementById('dispute_session_id').value = sessionId;
        document.getElementById('disputeModal').classList.add('active');
    }

    function closeDispute() {
        document.getElementById('disputeModal').classList.remove('active');
    }

    document.getElementById('disputeModal').addEventListener('click', function (e) {
        if (e.target === this) closeDispute();
    });
</script>

<!-- Complete Modal -->
<div class="modal-overlay" id="completeModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Complete Session</h3>
            <button class="modal-close" onclick="closeComplete()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="complete_session_otp">
            <input type="hidden" name="session_id" id="complete_session_id">
            <div class="form-group">
                <label for="otp">Enter 4-Digit OTP from Learner</label>
                <input type="text" name="otp" id="otp" class="form-control" maxlength="4" placeholder="e.g. 1234"
                    required>
            </div>
            <button type="submit" class="btn btn-success btn-block">Confirm Completion</button>
        </form>
    </div>
</div>

<!-- Dispute Modal -->
<div class="modal-overlay" id="disputeModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Submit Proof / Dispute</h3>
            <button class="modal-close" onclick="closeDispute()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="submit_proof">
            <input type="hidden" name="session_id" id="dispute_session_id">

            <div class="form-group">
                <label for="dispute_action">Action Type</label>
                <select name="dispute_action" id="dispute_action" class="form-control">
                    <option value="proof" selected>Submit Completion Proof (I taught/attended this session)</option>
                    <option value="dispute">File Formal Dispute (No-show, conflict, or credit issue)</option>
                </select>
            </div>

            <div class="form-group">
                <label for="submission_note">Details / Note / Reason</label>
                <textarea name="submission_note" id="submission_note" class="form-control"
                    placeholder="Provide details, proof notes, or dispute reason..." required></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Submit to Admin</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>