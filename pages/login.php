<?php
require_once __DIR__ . '/../config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        // Check for admin login
        if ($email === 'admin@skillswap.com' && $password === 'admin123') {
            $_SESSION['user_id'] = 0;
            $_SESSION['user_name'] = 'Administrator';
            $_SESSION['user_email'] = $email;
            $_SESSION['is_admin'] = true;
            header('Location: ../admin/dashboard.php');
            exit;
        }

        $stmt = $conn->prepare("SELECT user_id, name, email, password_hash FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            // Using plain text comparison since the demo data uses plain text passwords
            if ($password === $user['password_hash']) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['is_admin'] = false;
                header('Location: ../pages/dashboard.php');
                exit;
            } else {
                $error = 'Invalid password.';
            }
        } else {
            $error = 'No account found with that email.';
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Login to SkillSwap - Exchange skills and earn time credits.">
    <title>SkillSwap — Login</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            <div class="auth-logo">

                <img src="../assets/finalLogo.png" alt="Logo" class="auth-logo-img">
                <p>Exchange Skills, Earn Time Credits</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="you@bscse.uiu.ac.bd"
                        required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control"
                        placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="btn-login">Login</button>
            </form>

            <div class="auth-footer">
                Don't have an account? <a href="../pages/register.php">Sign Up</a>
            </div>
        </div>
    </div>
</body>

</html>