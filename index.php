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
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <style>
        html {
            scroll-behavior: smooth;
        }        /* Modern Design System Tokens */
        :root {
            --primary: #00386c;
            --primary-dark: #002548;
            --primary-glow: rgba(0, 56, 108, 0.05);
            --secondary: #f3b922;
            --secondary-dark: #b88a14;
            --bg-primary: #ffffff;
            --bg-secondary: #f4f7f9;
            --bg-card: #ffffff;
            --text-primary: #121826;
            --text-secondary: #4a5568;
            --text-muted: #8492a6;
            --border-light: #e2e8f0;
            --border-color: #cbd5e1;
            --success: #10b981;
            --info: #3b82f6;
            --radius-sm: 12px;
            --radius-md: 20px;
            --shadow-sm: 0 4px 12px rgba(0,0,0,0.03);
            --shadow-md: 0 12px 30px rgba(0,0,0,0.05);
            --shadow-lg: 0 20px 40px rgba(0,56,108,0.1);
            --transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
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
            background: transparent;
            line-height: 1.6;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        #bg-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background: var(--bg-primary);
            pointer-events: none;
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
                <div class="hero-text" data-aos="fade-right" data-aos-duration="1000">
                    <h1>Time as <span>Currency.</span></h1>
                    <p>
                        SkillSwap is a scholarly marketplace where your expertise is measured in hours, not in Taka. Exchange your knowledge, build your reputation, and invest in your growth.
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
                <div class="hero-image" data-aos="fade-left" data-aos-duration="1000" data-aos-delay="200">
                    <!-- Embedded Vector illustration generated previously -->
                    <img src="assets/landing_hero.png" alt="SkillSwap Illustration">
                    
                    <!-- Floating Current Balance Badge -->
                    <div class="balance-badge">
                        <span class="icon"><i data-lucide="clock"></i></span>
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
            <div class="section-header" data-aos="fade-up">
                <h2>The Exchange Process</h2>
                <p>Simple, equitable, and transparent time-banking.</p>
            </div>
            
            <div class="process-grid">
                <div class="process-line"></div>
                
                <!-- Step 1: Request -->
                <div class="process-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="badge-icon badge-blue">
                        <span><i data-lucide="edit-3"></i></span>
                    </div>
                    <h3>1. Request</h3>
                    <div class="card-bar"></div>
                    <p>
                        Find a skill you need and send a request. Negotiate the time value based on the task's complexity.
                    </p>
                </div>

                <!-- Step 2: Session -->
                <div class="process-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="badge-icon badge-yellow">
                        <span><i data-lucide="users"></i></span>
                    </div>
                    <h3>2. Session</h3>
                    <div class="card-bar"></div>
                    <p>
                        Conduct the learning session or complete the task. Use our built-in workspace for seamless collaboration.
                    </p>
                </div>

                <!-- Step 3: Transfer -->
                <div class="process-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="badge-icon badge-grey">
                        <span><i data-lucide="refresh-cw"></i></span>
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
            <div class="section-header" data-aos="fade-up">
                <h2>Built for Students & Peer Learning</h2>
                <p>A complete framework for exchange, collaboration, and growth.</p>
            </div>

            <!-- Top Row: Reliability & Tasks -->
            <div class="features-grid">
                
                <!-- Scholarly Reliability -->
                <div class="feature-card reliability-card" data-aos="fade-right" data-aos-delay="100">
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
                        <span class="meta-badge"><i data-lucide="award" class="lucide-sm"></i> Top Mentor</span>
                        <span class="meta-badge"><i data-lucide="check-circle" class="lucide-sm"></i> 50+ Sessions</span>
                    </div>
                </div>

                <!-- Community Tasks -->
                <div class="feature-card tasks-card" data-aos="fade-left" data-aos-delay="200">
                    <div>
                        <div class="icon-header"><i data-lucide="clipboard-list"></i></div>
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
                <div class="feature-card matching-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="badge-icon"><i data-lucide="search"></i></div>
                    <h3>Smart Matching</h3>
                    <p>
                        Our AI suggests skills you might need based on your academic path and current gaps.
                    </p>
                </div>

                <!-- Transaction Transparency -->
                <div class="feature-card transparency-card" data-aos="fade-up" data-aos-delay="200">
                    <h3>Transaction Transparency</h3>
                    
                    <div class="transparency-list">
                        <div class="transparency-item">
                            <div class="details">
                                <div class="icon"><i data-lucide="book-open"></i></div>
                                <div class="name">Advanced Python Tutoring</div>
                            </div>
                            <div class="amount amount-plus">+2.0h</div>
                        </div>

                        <div class="transparency-item">
                            <div class="details">
                                <div class="icon"><i data-lucide="palette"></i></div>
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
    <section class="cta-section" data-aos="zoom-in" data-aos-duration="1000">
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
                <a href="pages/login.php" class="btn btn-orange btn-lg" style="padding: 16px 36px; font-size:1.05rem;">Exchange Time Now</a>
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

    <!-- AOS Scripts -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        AOS.init({
            once: true,
            offset: 50,
            duration: 800,
            easing: 'ease-out-cubic'
        });
        
        // Initialize Lucide Icons
        document.addEventListener("DOMContentLoaded", function() {
            lucide.createIcons();
        });
    </script>
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
                this.radius = Math.random() * 2 + 1.5;
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
                ctx.arc(x, y, 2, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(243, 185, 34, 0.9)'; // Theme yellow
                ctx.shadowBlur = 8;
                ctx.shadowColor = 'rgba(243, 185, 34, 0.6)';
                ctx.fill();
                ctx.shadowBlur = 0;
            }
        }

        class FlowParticle {
            constructor(curveIndex) {
                this.curveIndex = curveIndex;
                this.progress = Math.random();
                this.speed = Math.random() * 0.0015 + 0.0005;
                this.size = Math.random() * 2.5 + 1.5;
                this.opacity = Math.random() * 0.5 + 0.4;
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
                ctx.fillStyle = `rgba(243, 185, 34, ${this.opacity})`; // Theme yellow
                ctx.shadowBlur = 10;
                ctx.shadowColor = 'rgba(243, 185, 34, 0.5)';
                ctx.fill();
                ctx.shadowBlur = 0;
            }
        }
        
        function init() {
            nodes = [];
            particles = [];
            flowParticles = [];
            const nodeCount = Math.floor((width * height) / 15000); 
            for (let i = 0; i < nodeCount; i++) {
                nodes.push(new Node());
            }
            for (let i = 0; i < 12; i++) {
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
            ctx.strokeStyle = 'rgba(0, 56, 108, 0.03)';
            ctx.lineWidth = 120;
            ctx.stroke();

            ctx.beginPath();
            ctx.moveTo(-100, height * 0.8 + Math.cos(flowOffset) * 50);
            ctx.bezierCurveTo(
                width * 0.4, height * 0.9 + Math.sin(flowOffset) * 50, 
                width * 0.7, height * 0.2 + Math.cos(flowOffset) * 50, 
                width + 100, height * 0.6
            );
            ctx.strokeStyle = 'rgba(0, 56, 108, 0.025)';
            ctx.lineWidth = 180;
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
                        ctx.strokeStyle = `rgba(0, 56, 108, ${alpha * 0.15})`;
                        ctx.lineWidth = 1;
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
