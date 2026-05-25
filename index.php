<?php
session_start();
// Prevent caching of the landing page
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SkillSwap - Exchange skills, earn time credits. A community-driven peer-to-peer time bank.">
    <title>SkillSwap — Time as Currency</title>
    
    <!-- Google Fonts for premium typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        /* Modern Design System Tokens */
        :root {
            --primary: #00386c;
            --primary-dark: #002548;
            --primary-glow: rgba(0, 56, 108, 0.05);
            --secondary: #f3b922;
            --secondary-dark: #cca41b;
            --success: #1a7a42;
            --danger: #ba1a1a;
            --info: #2f5f9c;
            --bg-primary: #ffffff;
            --bg-secondary: #f7f9fc;
            --bg-card: #ffffff;
            --text-primary: #191c20;
            --text-secondary: #43474e;
            --text-muted: #737781;
            --border-color: #dbe2f9;
            --border-light: #f0f4ff;
            --radius-sm: 8px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow-sm: 0 2px 8px rgba(0, 56, 108, 0.05);
            --shadow-md: 0 8px 24px rgba(0, 56, 108, 0.08);
            --shadow-lg: 0 16px 40px rgba(0, 56, 108, 0.12);
        }

        /* Reset & Base Styles */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            color: var(--text-primary);
            background: var(--bg-primary);
            line-height: 1.6;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, h4 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 700;
            color: var(--primary);
        }

        a {
            text-decoration: none;
            transition: var(--transition);
        }

        /* Layout Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 24px;
            position: relative;
        }

        /* Header Navbar Navigation */
        .navbar {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-light);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 8px 0;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }

        .navbar .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logo span {
            color: var(--secondary);
        }

        .logo img {
            width: 250px;
            height: 70px;
            object-fit: cover;
            object-position: center 46%;
        }

        .nav-links {
            display: flex;
            align-items: center;
            list-style: none;
            gap: 28px;
        }

        .nav-links a {
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Buttons */
        .btn {
            font-family: 'Plus Jakarta Sans', sans-serif;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 22px;
            border-radius: 9999px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: var(--transition);
        }

        .btn-text {
            color: var(--primary);
            background: transparent;
            padding: 10px 16px;
        }

        .btn-text:hover {
            background: var(--primary-glow);
        }

        .btn-primary {
            background: var(--primary);
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(0, 56, 108, 0.2);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 56, 108, 0.3);
        }

        .btn-secondary {
            background: #ffffff;
            color: var(--primary);
            border: 1.5px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--bg-secondary);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .btn-orange {
            background: var(--secondary);
            color: var(--primary-dark);
            box-shadow: 0 4px 14px rgba(243, 185, 34, 0.25);
        }

        .btn-orange:hover {
            background: var(--secondary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(243, 185, 34, 0.35);
        }

        /* Hero Banner Section */
        .hero {
            padding: 160px 0 80px 0;
            background: linear-gradient(180deg, rgba(0, 56, 108, 0.02) 0%, rgba(255, 255, 255, 0) 100%);
            position: relative;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            align-items: center;
            gap: 48px;
        }

        .hero-text h1 {
            font-size: 3.5rem;
            line-height: 1.15;
            margin-bottom: 20px;
            letter-spacing: -0.02em;
        }

        .hero-text h1 span {
            color: var(--secondary);
        }

        .hero-text p {
            font-size: 1.15rem;
            color: var(--text-secondary);
            margin-bottom: 36px;
            max-width: 520px;
            line-height: 1.65;
        }

        .hero-actions {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .hero-image {
            position: relative;
        }

        .hero-image img {
            width: 100%;
            height: auto;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border-light);
            transition: var(--transition);
        }

        .hero-image:hover img {
            transform: translateY(-4px);
        }

        /* Floating Badge (Current Balance Badge) */
        .balance-badge {
            position: absolute;
            bottom: -20px;
            left: 20px;
            background: var(--secondary);
            border-radius: var(--radius-md);
            padding: 16px 24px;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 12px;
            border: 2px solid #ffffff;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }

        .balance-badge .icon {
            font-size: 1.8rem;
        }

        .balance-badge .label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--primary-dark);
            opacity: 0.8;
            line-height: 1;
        }

        .balance-badge .value {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--primary);
            font-family: 'Plus Jakarta Sans', sans-serif;
            line-height: 1.2;
        }

        /* Section Global Settings */
        .section {
            padding: 80px 0;
        }

        .section-header {
            text-align: center;
            margin-bottom: 56px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .section-header h2 {
            font-size: 2.2rem;
            margin-bottom: 12px;
            letter-spacing: -0.01em;
        }

        .section-header p {
            color: var(--text-secondary);
            font-size: 1.05rem;
        }

        /* Exchange Process Column Cards */
        .process-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
            position: relative;
        }

        .process-card {
            background: #ffffff;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: 32px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            position: relative;
            z-index: 2;
        }

        .process-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
            border-color: var(--border-color);
        }

        .process-line {
            position: absolute;
            top: 60px;
            left: 10%;
            right: 10%;
            height: 2px;
            background: var(--border-light);
            z-index: 1;
        }

        .process-card .card-bar {
            height: 4px;
            width: 80px;
            background: var(--border-color);
            margin-bottom: 24px;
            border-radius: 2px;
            transition: var(--transition);
        }

        .process-card:hover .card-bar {
            width: 100%;
            background: var(--primary);
        }

        .process-card:nth-child(2):hover .card-bar {
            background: var(--secondary);
        }

        .process-card .badge-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 20px;
            font-weight: 700;
        }

        .badge-blue { background: rgba(0, 56, 108, 0.08); color: var(--primary); }
        .badge-yellow { background: rgba(243, 185, 34, 0.15); color: var(--secondary-dark); }
        .badge-grey { background: rgba(115, 119, 129, 0.08); color: var(--text-secondary); }

        .process-card h3 {
            font-size: 1.25rem;
            margin-bottom: 12px;
            color: var(--primary-dark);
        }

        .process-card p {
            color: var(--text-secondary);
            font-size: 0.92rem;
            line-height: 1.6;
        }

        /* Features Dashboard Grid */
        .features-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .features-subgrid {
            display: grid;
            grid-template-columns: 0.9fr 1.1fr;
            gap: 24px;
        }

        .feature-card {
            background: var(--bg-card);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: 32px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .feature-card:hover {
            box-shadow: var(--shadow-md);
        }

        /* Scholarly Reliability Card */
        .reliability-card .trust-score {
            margin: 24px 0 16px 0;
        }

        .reliability-card .trust-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .reliability-card .progress-bar {
            width: 100%;
            height: 10px;
            background: var(--bg-secondary);
            border-radius: 9999px;
            overflow: hidden;
            border: 1px solid var(--border-light);
        }

        .reliability-card .progress-fill {
            width: 98%;
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--info));
            border-radius: 9999px;
        }

        .reliability-card .meta-badges {
            display: flex;
            gap: 16px;
            margin-top: 20px;
            border-top: 1px solid var(--border-light);
            padding-top: 16px;
        }

        .reliability-card .meta-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        /* Community Tasks Card (Sleek Dark Blue) */
        .tasks-card {
            background: var(--primary);
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border: none;
            box-shadow: var(--shadow-md);
        }

        .tasks-card h3 {
            color: #ffffff;
            font-size: 1.5rem;
            margin-bottom: 12px;
        }

        .tasks-card p {
            color: rgba(255, 255, 255, 0.85);
            font-size: 0.95rem;
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .tasks-card .icon-header {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 24px;
        }

        /* Smart Matching Card (Sleek Light Blue Glow) */
        .matching-card {
            background: rgba(0, 56, 108, 0.03);
            border: 1.5px dashed var(--primary);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .matching-card .badge-icon {
            width: 44px;
            height: 44px;
            background: rgba(0, 56, 108, 0.08);
            color: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 20px;
        }

        .matching-card h3 {
            font-size: 1.25rem;
            margin-bottom: 8px;
        }

        .matching-card p {
            color: var(--text-secondary);
            font-size: 0.88rem;
            line-height: 1.6;
        }

        /* Transaction Transparency List */
        .transparency-card h3 {
            font-size: 1.25rem;
            margin-bottom: 20px;
        }

        .transparency-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .transparency-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 12px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition);
        }

        .transparency-item:hover {
            background: #ffffff;
            border-color: var(--border-color);
            transform: translateX(4px);
        }

        .transparency-item .details {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .transparency-item .icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(0, 56, 108, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
        }

        .transparency-item .name {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .transparency-item .amount {
            font-weight: 700;
            font-size: 0.95rem;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        .amount-plus { color: var(--success); }
        .amount-minus { color: var(--text-muted); }

        /* Bottom Call to Action Section */
        .cta-section {
            background: linear-gradient(180deg, rgba(255, 255, 255, 0) 0%, rgba(0, 56, 108, 0.02) 100%);
            padding: 100px 0;
            text-align: center;
        }

        .cta-section h2 {
            font-size: 2.8rem;
            margin-bottom: 16px;
            letter-spacing: -0.02em;
        }

        .cta-section p {
            color: var(--text-secondary);
            font-size: 1.15rem;
            max-width: 580px;
            margin: 0 auto 36px auto;
            line-height: 1.65;
        }

        /* Footer Section */
        .footer {
            background: #ffffff;
            border-top: 1px solid var(--border-light);
            padding: 40px 0;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.88rem;
        }

        .footer p {
            margin-bottom: 8px;
        }

        /* Responsive Mobile Layouts */
        @media (max-width: 991px) {
            .hero-grid {
                grid-template-columns: 1fr;
                gap: 56px;
                text-align: center;
            }

            .hero-text p {
                margin-left: auto;
                margin-right: auto;
            }

            .hero-actions {
                justify-content: center;
            }

            .balance-badge {
                left: 50%;
                transform: translateX(-50%);
                bottom: -15px;
            }

            .process-grid {
                grid-template-columns: 1fr;
                gap: 24px;
            }

            .process-line {
                display: none;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .navbar .container {
                flex-direction: column;
                gap: 16px;
            }
            .nav-links {
                display: none; /* Hide standard nav list on mobile header */
            }
            .hero-text h1 {
                font-size: 2.5rem;
            }
            .features-subgrid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <!-- Header Navbar Navigation -->
    <nav class="navbar">
        <div class="container">
            <a href="index.php" class="logo">
                <img src="assets/skillswap.png" alt="SkillSwap Logo">
            </a>
            
            <ul class="nav-links">
                <li><a href="#how-it-works">How it Works</a></li>
                <li><a href="#features">Features</a></li>
                <li><a href="pages/skills.php">Marketplace</a></li>
            </ul>

            <div class="nav-actions">
                <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                    <a href="admin/dashboard.php" class="btn btn-primary">Admin Dashboard</a>
                <?php elseif (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0): ?>
                    <a href="pages/dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                <?php else: ?>
                    <a href="pages/login.php" class="btn btn-text">Log In</a>
                    <a href="pages/register.php" class="btn btn-primary">Sign Up</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Hero Banner Section -->
    <header class="hero">
        <div class="container">
            <div class="hero-grid">
                <div class="hero-text">
                    <h1>Time as <span>Currency.</span></h1>
                    <p>
                        SkillSwap is a scholarly marketplace where your expertise is measured in hours, not dollars. Exchange your knowledge, build your reputation, and invest in your growth.
                    </p>
                    <div class="hero-actions">
                        <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                            <a href="admin/dashboard.php" class="btn btn-orange">Go to Dashboard</a>
                        <?php elseif (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0): ?>
                            <a href="pages/dashboard.php" class="btn btn-orange">Go to Dashboard</a>
                        <?php else: ?>
                            <a href="pages/register.php" class="btn btn-orange">Join the Marketplace</a>
                        <?php endif; ?>
                        <a href="#how-it-works" class="btn btn-secondary">How it Works</a>
                    </div>
                </div>
                <div class="hero-image">
                    <!-- Embedded Vector illustration generated previously -->
                    <img src="assets/landing_hero.png" alt="SkillSwap Illustration">
                    
                    <!-- Floating Current Balance Badge -->
                    <div class="balance-badge">
                        <span class="icon">⏱️</span>
                        <div>
                            <div class="label">Current Balance</div>
                            <div class="value">12.5 Hours</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Exchange Process section (How it Works) -->
    <section class="section" id="how-it-works" style="background: var(--bg-secondary);">
        <div class="container">
            <div class="section-header">
                <h2>The Exchange Process</h2>
                <p>Simple, equitable, and transparent time-banking.</p>
            </div>
            
            <div class="process-grid">
                <div class="process-line"></div>
                
                <!-- Step 1: Request -->
                <div class="process-card">
                    <div class="badge-icon badge-blue">
                        <span>✏️</span>
                    </div>
                    <h3>1. Request</h3>
                    <div class="card-bar"></div>
                    <p>
                        Find a skill you need and send a request. Negotiate the time value based on the task's complexity.
                    </p>
                </div>

                <!-- Step 2: Session -->
                <div class="process-card">
                    <div class="badge-icon badge-yellow">
                        <span>👥</span>
                    </div>
                    <h3>2. Session</h3>
                    <div class="card-bar"></div>
                    <p>
                        Conduct the learning session or complete the task. Use our built-in workspace for seamless collaboration.
                    </p>
                </div>

                <!-- Step 3: Transfer -->
                <div class="process-card">
                    <div class="badge-icon badge-grey">
                        <span>🔄</span>
                    </div>
                    <h3>3. Transfer</h3>
                    <div class="card-bar"></div>
                    <p>
                        Once the session is verified, the agreed time is automatically transferred to the mentor's wallet.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Showcase section -->
    <section class="section" id="features">
        <div class="container">
            <div class="section-header">
                <h2>Built for Students & Peer Learning</h2>
                <p>A complete framework for exchange, collaboration, and growth.</p>
            </div>

            <!-- Top Row: Reliability & Tasks -->
            <div class="features-grid">
                
                <!-- Scholarly Reliability -->
                <div class="feature-card reliability-card">
                    <span class="badge badge-orange" style="text-transform:uppercase; letter-spacing:0.5px; font-size:0.65rem;">Reputation System</span>
                    <h3 style="margin-top: 10px; font-size:1.5rem;">Scholarly Reliability</h3>
                    <p style="color:var(--text-secondary); font-size:0.92rem; margin-top:8px;">
                        Your credibility is your most valuable asset. Our algorithmic scoring system tracks punctuality, session quality, and community feedback.
                    </p>
                    
                    <!-- Trust Score progress -->
                    <div class="trust-score">
                        <div class="trust-label">
                            <span>Trust Score</span>
                            <span>98%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill"></div>
                        </div>
                    </div>

                    <div class="meta-badges">
                        <span class="meta-badge">🏆 Top Mentor</span>
                        <span class="meta-badge">✔️ 50+ Sessions</span>
                    </div>
                </div>

                <!-- Community Tasks -->
                <div class="feature-card tasks-card">
                    <div>
                        <div class="icon-header">📋</div>
                        <h3>Community Tasks</h3>
                        <p>
                            Earn hours by contributing to university projects or helping peers with short-form tasks.
                        </p>
                    </div>
                    <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                        <a href="admin/community_tasks.php" class="btn btn-orange" style="align-self: flex-start; padding: 10px 24px; border-radius:var(--radius-sm);">View Tasks</a>
                    <?php elseif (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0): ?>
                        <a href="pages/community_tasks.php" class="btn btn-orange" style="align-self: flex-start; padding: 10px 24px; border-radius:var(--radius-sm);">View Tasks</a>
                    <?php else: ?>
                        <a href="pages/login.php" class="btn btn-orange" style="align-self: flex-start; padding: 10px 24px; border-radius:var(--radius-sm);">View Tasks</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bottom Row: Smart Matching & Transactions -->
            <div class="features-grid">
                
                <!-- Smart Matching -->
                <div class="feature-card matching-card">
                    <div class="badge-icon">🔍</div>
                    <h3>Smart Matching</h3>
                    <p>
                        Our AI suggests skills you might need based on your academic path and current gaps.
                    </p>
                </div>

                <!-- Transaction Transparency -->
                <div class="feature-card transparency-card">
                    <h3>Transaction Transparency</h3>
                    
                    <div class="transparency-list">
                        <div class="transparency-item">
                            <div class="details">
                                <div class="icon">📖</div>
                                <div class="name">Advanced Python Tutoring</div>
                            </div>
                            <div class="amount amount-plus">+2.0h</div>
                        </div>

                        <div class="transparency-item">
                            <div class="details">
                                <div class="icon">🎨</div>
                                <div class="name">UI Design Review</div>
                            </div>
                            <div class="amount amount-minus">-1.5h</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer CTA Call to Action Section -->
    <section class="cta-section">
        <div class="container">
            <h2>Ready to exchange your time?</h2>
            <p>
                Join 5,000+ students already mastering new skills through the power of peer-to-peer exchange. No money, just growth.
            </p>
            <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                <a href="admin/dashboard.php" class="btn btn-orange btn-lg" style="padding: 16px 36px; font-size:1.05rem;">Go to Dashboard</a>
            <?php elseif (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0): ?>
                <a href="pages/dashboard.php" class="btn btn-orange btn-lg" style="padding: 16px 36px; font-size:1.05rem;">Go to Dashboard</a>
            <?php else: ?>
                <a href="pages/register.php" class="btn btn-orange btn-lg" style="padding: 16px 36px; font-size:1.05rem;">Exchange Time Now</a>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer copyright info -->
    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> SkillSwap Platform. All rights reserved.</p>
            <p style="font-size:0.75rem;">Created for Database Management Systems coursework.</p>
        </div>
    </footer>

</body>
</html>
