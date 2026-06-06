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
    <link rel="stylesheet" href="../assets/css/style.css?v=<?=time()?>">
    <style>
        .auth-wrapper {
            background: transparent !important;
            position: relative;
            z-index: 1;
        }
        #bg-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            background: var(--surface-container-low);
        }
        .auth-card {
            animation: slideUpFade 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            transform: translateY(30px);
        }
        @keyframes slideUpFade {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .form-control {
            transition: border-color 0.3s ease, box-shadow 0.3s ease, transform 0.3s ease;
        }
        .form-control:focus {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 56, 108, 0.08);
        }
        .btn {
            transition: all 0.3s ease;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 56, 108, 0.15);
        }
        .auth-logo-img {
            transition: transform 0.5s ease;
        }
        .auth-logo-img:hover {
            transform: scale(1.05);
        }
    </style>
    </style>
</head>

<body>
    <div class="auth-wrapper">
        <div class="auth-card" style="max-width: 480px;">
            <div class="auth-logo">
                <a href="../index.php?v=<?=time()?>"><img src="../assets/loading.png" alt="SkillSwap Logo" class="auth-logo-img"></a>
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
    <canvas id="bg-canvas"></canvas>
    <script>
        const canvas = document.getElementById('bg-canvas');
        const ctx = canvas.getContext('2d');
        let width, height, cx, cy;
        let orbits = [];
        let particles = [];
        
        function resize() {
            width = window.innerWidth;
            height = window.innerHeight;
            canvas.width = width;
            canvas.height = height;
            cx = width / 2;
            cy = height / 2;
        }
        window.addEventListener('resize', () => {
            resize();
            init();
        });
        resize();
        
        class Orbit {
            constructor(radius) {
                this.radius = radius;
                this.speed = (Math.random() * 0.0002 + 0.0001) * (Math.random() > 0.5 ? 1 : -1);
                this.angle = Math.random() * Math.PI * 2;
            }
            draw() {
                ctx.beginPath();
                ctx.arc(cx, cy, this.radius, 0, Math.PI * 2);
                ctx.strokeStyle = 'rgba(115, 119, 129, 0.15)'; // Faint blue-gray orbital path
                ctx.lineWidth = 1;
                ctx.stroke();
            }
        }
        
        class Particle {
            constructor(orbit) {
                this.orbit = orbit;
                this.angle = Math.random() * Math.PI * 2;
                this.speed = (Math.random() * 0.0015 + 0.0005) * (Math.random() > 0.5 ? 1 : -1);
                this.size = Math.random() * 1.8 + 1.2;
                this.baseAlpha = Math.random() * 0.5 + 0.4;
                this.pulseSpeed = Math.random() * 0.02 + 0.01;
                this.pulseTime = Math.random() * Math.PI * 2;
            }
            update() {
                this.angle += this.speed;
                this.pulseTime += this.pulseSpeed;
            }
            draw() {
                const x = cx + Math.cos(this.angle) * this.orbit.radius;
                const y = cy + Math.sin(this.angle) * this.orbit.radius;
                
                const alpha = this.baseAlpha + Math.sin(this.pulseTime) * 0.2;
                
                ctx.beginPath();
                ctx.arc(x, y, this.size, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(255, 255, 255, ${alpha})`;
                ctx.shadowBlur = 12;
                ctx.shadowColor = `rgba(255, 255, 255, ${alpha})`;
                ctx.fill();
                ctx.shadowBlur = 0;
                
                // Trail
                ctx.beginPath();
                const trailLen = this.speed * 30;
                ctx.arc(cx, cy, this.orbit.radius, this.angle - trailLen, this.angle, this.speed < 0);
                ctx.strokeStyle = `rgba(0, 56, 108, ${alpha * 0.5})`; // Navy blue trail
                ctx.lineWidth = 2.5;
                ctx.stroke();
            }
        }
        
        function init() {
            orbits = [];
            particles = [];
            const maxRadius = Math.max(width, height) * 0.7;
            const numOrbits = Math.floor(maxRadius / 45); // Spacing between orbits
            
            for (let i = 1; i <= numOrbits; i++) {
                if (Math.random() > 0.1) { // 90% chance to create an orbit at this spacing
                    const orbit = new Orbit(i * 45 + Math.random() * 15);
                    orbits.push(orbit);
                    
                    const numParticles = Math.floor(Math.random() * 3) + 1;
                    for (let j = 0; j < numParticles; j++) {
                        particles.push(new Particle(orbit));
                    }
                }
            }
        }
        init();
        
        function animate() {
            ctx.clearRect(0, 0, width, height);
            
            const gradient = ctx.createRadialGradient(cx, cy, 0, cx, cy, Math.max(width, height) * 0.6);
            gradient.addColorStop(0, 'rgba(0, 56, 108, 0.04)');
            gradient.addColorStop(1, 'transparent');
            ctx.fillStyle = gradient;
            ctx.fillRect(0, 0, width, height);
            
            orbits.forEach(orbit => {
                orbit.angle += orbit.speed;
                orbit.draw();
            });
            
            particles.forEach(p => {
                p.update();
                p.draw();
            });
            
            requestAnimationFrame(animate);
        }
        
        animate();
    </script>
    <script src="../assets/js/city-autocomplete.js"></script>
</body>

</html>