<?php
require_once __DIR__ . '/../config/db.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm = trim($_POST['confirm_password'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $bio = trim($_POST['bio'] ?? '');

    if (empty($name) || empty($email) || empty($password)) {
        $error = 'Name, email, and password are required.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 4) {
        $error = 'Password must be at least 4 characters.';
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // --- STORED PROCEDURE: sp_register_user ---
        // Atomically creates user, wallet (with welcome bonus), and reputation.
        // Uses EXISTS subquery to check for duplicate emails.
        // Replaces separate INSERT statements with a single atomic DB call.
        $stmt = $conn->prepare("CALL sp_register_user(?, ?, ?, ?, ?, @sp_status, @sp_user_id)");
        $stmt->bind_param("sssss", $name, $email, $hashed_password, $location, $bio);
        $stmt->execute();
        $stmt->close();
        $result = $conn->query("SELECT @sp_status AS status, @sp_user_id AS user_id")->fetch_assoc();

        if ($result['status'] === 'success') {
            $success = 'Account created! You received 10 Time Credits as a welcome bonus.';
        } elseif ($result['status'] === 'duplicate') {
            $error = 'An account with this email already exists.';
        } else {
            $error = 'Registration failed. Please try again.';
        }
    }

}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create your SkillSwap account and start exchanging skills.">
    <title>SkillSwap — Register</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
    <div class="auth-wrapper">
        <div class="auth-card" style="max-width: 480px;">
            <div class="auth-logo">
                <a href="../index.php?v=<?=time()?>"><img src="../assets/skillswap.png" alt="SkillSwap Logo" class="auth-logo-img"></a>
                <p>Create Your Account</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?> <a
                        href="../pages/login.php">Login now</a></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="name">Full Name *</label>
                    <input type="text" id="name" name="name" class="form-control" placeholder="Shake Russell" required
                        value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="you@bsds.uiu.ac.bd"
                        required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" class="form-control"
                            placeholder="Minimum 4 characters" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                            placeholder="Re-enter" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" class="form-control city-autocomplete" placeholder="City, Country"
                        value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="bio">Bio</label>
                    <textarea id="bio" name="bio" class="form-control"
                        placeholder="Tell us about yourself..."><?php echo htmlspecialchars($_POST['bio'] ?? ''); ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="btn-register">Create Account</button>
            </form>

            <div class="auth-footer">
                Already have an account? <a href="../pages/login.php">Login</a>
                <br><br>
                <a href="../index.php?v=<?=time()?>" style="font-size:0.8rem; color:var(--text-muted);">&larr; Back to Home</a>
            </div>
        </div>
    </div>
    <script src="../assets/js/city-autocomplete.js"></script>
</body>

</html>