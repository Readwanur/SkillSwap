<?php
require_once __DIR__ . '/../config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } else {
        // Admin login — admin is NOT a user, just a site manager
        if (strcasecmp($email, 'Admin@SkillSwap.com') === 0 && $password === 'Admin123') {
            session_regenerate_id(true);
            $_SESSION['user_id'] = 0;
            $_SESSION['user_name'] = 'Administrator';
            $_SESSION['user_email'] = $email;
            $_SESSION['is_admin'] = true;
            header('Location: ../admin/dashboard.php');
            exit;
        }

        // Regular user login from the database
        $stmt = $conn->prepare("SELECT user_id, name, email, password_hash, status FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password_hash'])) {
                if ($user['status'] === 'suspended') {
                    $error = 'Your account has been suspended by an administrator.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['is_admin'] = false;
                    header('Location: ../pages/dashboard.php');
                    exit;
                }
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
</head>

<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            <div class="auth-logo">
                <a href="../index.php?v=<?=time()?>"><img src="../assets/loading.png" alt="Logo" class="auth-logo-img"></a>
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
                <br><br>
                <a href="../index.php?v=<?=time()?>" style="font-size:0.8rem; color:var(--text-muted);">&larr; Back to Home</a>
            </div>
        </div>
    </div>
    <canvas id="bg-canvas"></canvas>
    <script>
        const canvas = document.getElementById('bg-canvas');
        const ctx = canvas.getContext('2d');
        let width, height;
        let nodes = [];
        let particles = [];
        let flowParticles = [];
        const maxDistance = 180;
        
        function resize() {
            width = window.innerWidth;
            height = window.innerHeight;
            canvas.width = width;
            canvas.height = height;
        }
        window.addEventListener('resize', resize);
        resize();
        
        function getBezierPoint(t, p0, p1, p2, p3) {
            const u = 1 - t;
            const tt = t * t;
            const uu = u * u;
            let x = (uu * u) * p0.x + 3 * uu * t * p1.x + 3 * u * tt * p2.x + (tt * t) * p3.x;
            let y = (uu * u) * p0.y + 3 * uu * t * p1.y + 3 * u * tt * p2.y + (tt * t) * p3.y;
            return {x, y};
        }

        class Node {
            constructor() {
                this.x = Math.random() * width;
                this.y = Math.random() * height;
                this.vx = (Math.random() - 0.5) * 0.3;
                this.vy = (Math.random() - 0.5) * 0.3;
                this.radius = Math.random() * 2 + 1;
            }
            update() {
                this.x += this.vx;
                this.y += this.vy;
                if (this.x < 0 || this.x > width) this.vx *= -1;
                if (this.y < 0 || this.y > height) this.vy *= -1;
            }
            draw() {
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(0, 56, 108, 0.4)';
                ctx.fill();
            }
        }
        
        class Particle {
            constructor(source, target) {
                this.source = source;
                this.target = target;
                this.progress = 0;
                this.speed = Math.random() * 0.004 + 0.002;
            }
            update() {
                this.progress += this.speed;
                return this.progress >= 1;
            }
            draw() {
                const x = this.source.x + (this.target.x - this.source.x) * this.progress;
                const y = this.source.y + (this.target.y - this.source.y) * this.progress;
                ctx.beginPath();
                ctx.arc(x, y, 1.5, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(255, 255, 255, 0.9)';
                ctx.shadowBlur = 6;
                ctx.shadowColor = 'rgba(255, 255, 255, 0.8)';
                ctx.fill();
                ctx.shadowBlur = 0;
            }
        }

        class FlowParticle {
            constructor(curveIndex) {
                this.curveIndex = curveIndex;
                this.progress = Math.random();
                this.speed = Math.random() * 0.0015 + 0.0005;
                this.size = Math.random() * 2 + 1;
                this.opacity = Math.random() * 0.5 + 0.3;
            }
            update() {
                this.progress += this.speed;
                if (this.progress >= 1) {
                    this.progress = 0;
                    this.curveIndex = Math.random() > 0.5 ? 0 : 1;
                }
            }
            draw(flowOffset) {
                let p0, p1, p2, p3;
                if (this.curveIndex === 0) {
                    p0 = {x: -100, y: height * 0.2 + Math.sin(flowOffset) * 50};
                    p1 = {x: width * 0.3, y: height * 0.1 + Math.cos(flowOffset) * 50};
                    p2 = {x: width * 0.6, y: height * 0.8 + Math.sin(flowOffset) * 50};
                    p3 = {x: width + 100, y: height * 0.4};
                } else {
                    p0 = {x: -100, y: height * 0.8 + Math.cos(flowOffset) * 50};
                    p1 = {x: width * 0.4, y: height * 0.9 + Math.sin(flowOffset) * 50};
                    p2 = {x: width * 0.7, y: height * 0.2 + Math.cos(flowOffset) * 50};
                    p3 = {x: width + 100, y: height * 0.6};
                }
                const pos = getBezierPoint(this.progress, p0, p1, p2, p3);
                
                ctx.beginPath();
                ctx.arc(pos.x, pos.y, this.size, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(255, 255, 255, ${this.opacity})`;
                ctx.shadowBlur = 8;
                ctx.shadowColor = 'rgba(255, 255, 255, 0.6)';
                ctx.fill();
                ctx.shadowBlur = 0;
            }
        }
        
        function init() {
            nodes = [];
            particles = [];
            flowParticles = [];
            const nodeCount = Math.floor((width * height) / 12000);
            for (let i = 0; i < nodeCount; i++) {
                nodes.push(new Node());
            }
            for (let i = 0; i < 15; i++) {
                flowParticles.push(new FlowParticle(Math.random() > 0.5 ? 0 : 1));
            }
        }
        init();
        
        let flowOffset = 0;

        function animate() {
            ctx.clearRect(0, 0, width, height);
            flowOffset += 0.001;
            
            ctx.beginPath();
            ctx.moveTo(-100, height * 0.2 + Math.sin(flowOffset) * 50);
            ctx.bezierCurveTo(
                width * 0.3, height * 0.1 + Math.cos(flowOffset) * 50, 
                width * 0.6, height * 0.8 + Math.sin(flowOffset) * 50, 
                width + 100, height * 0.4
            );
            ctx.strokeStyle = 'rgba(0, 56, 108, 0.025)';
            ctx.lineWidth = 100;
            ctx.stroke();

            ctx.beginPath();
            ctx.moveTo(-100, height * 0.8 + Math.cos(flowOffset) * 50);
            ctx.bezierCurveTo(
                width * 0.4, height * 0.9 + Math.sin(flowOffset) * 50, 
                width * 0.7, height * 0.2 + Math.cos(flowOffset) * 50, 
                width + 100, height * 0.6
            );
            ctx.strokeStyle = 'rgba(0, 56, 108, 0.02)';
            ctx.lineWidth = 150;
            ctx.stroke();

            flowParticles.forEach(fp => {
                fp.update();
                fp.draw(flowOffset);
            });

            for (let i = 0; i < nodes.length; i++) {
                nodes[i].update();
                nodes[i].draw();
                
                for (let j = i + 1; j < nodes.length; j++) {
                    const dx = nodes[i].x - nodes[j].x;
                    const dy = nodes[i].y - nodes[j].y;
                    const dist = Math.sqrt(dx * dx + dy * dy);
                    
                    if (dist < maxDistance) {
                        ctx.beginPath();
                        ctx.moveTo(nodes[i].x, nodes[i].y);
                        ctx.lineTo(nodes[j].x, nodes[j].y);
                        const alpha = 1 - (dist / maxDistance);
                        ctx.strokeStyle = `rgba(115, 119, 129, ${alpha * 0.25})`;
                        ctx.lineWidth = 0.8;
                        ctx.stroke();
                        
                        if (Math.random() < 0.0005) {
                            particles.push(new Particle(nodes[i], nodes[j]));
                        }
                    }
                }
            }
            
            particles = particles.filter(p => {
                const reached = p.update();
                p.draw();
                return !reached;
            });
            
            requestAnimationFrame(animate);
        }
        
        animate();
        window.addEventListener('resize', () => {
            resize();
            init();
        });
    </script>
</body>

</html>