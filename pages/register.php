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
        // Check if email already exists
        $check = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = 'An account with this email already exists.';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash, location, bio) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $name, $email, $hashed_password, $location, $bio);

            if ($stmt->execute()) {
                $new_user_id = $stmt->insert_id;

                // Create wallet for new user
                $wallet_stmt = $conn->prepare("INSERT INTO wallet (user_id, balance) VALUES (?, 10.00)");
                $wallet_stmt->bind_param("i", $new_user_id);
                $wallet_stmt->execute();
                $wallet_stmt->close();

                // Create reputation entry
                $rep_stmt = $conn->prepare("INSERT INTO reputation (user_id, current_score, completed_sessions, mentor_level) VALUES (?, 5.00, 0, 'Novice')");
                $rep_stmt->bind_param("i", $new_user_id);
                $rep_stmt->execute();
                $rep_stmt->close();

                $success = 'Account created! You received 10 Time Credits as a welcome bonus.';
            } else {
                $error = 'Registration failed. Please try again.';
            }
            $stmt->close();
        }
        $check->close();
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

                <img src="../assets/finalLogo.png" alt="SkillSwap Logo" class="auth-logo-img">

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
                    <input type="text" id="location" name="location" class="form-control" placeholder="City, Country"
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
            </div>
        </div>
    </div>
</body>

</html>