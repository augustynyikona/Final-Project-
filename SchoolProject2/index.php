<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VaxiCare | Smarter Pediatric Vaccination Management</title>
    <meta name="description"
        content="A premium web-based vaccination scheduling and notification system with pharmacy integration.">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;0,800;1,600&display=swap"
        rel="stylesheet">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* ==========================================================================
           GLOBAL VARIABLES & RESET
           ========================================================================== */
        :root {
            /* Core Brand Colors */
            --navy: #1B254B;
            --navy-deep: #111827;
            --blue: #4318FF;
            --blue-hover: #3b14e6;
            --blue-soft: rgba(67, 24, 255, 0.08);
            --green: #05CD99;
            --green-soft: rgba(5, 205, 153, 0.1);

            /* Backgrounds */
            --bg-white: #FFFFFF;
            --bg-off-white: #F8F6F3;
            --bg-gray: #F4F7FE;

            /* Typography Colors */
            --text-dark: #1A1A1A;
            --text-muted: #64748B;

            /* Structural */
            --border: #E2E8F0;
            --radius-sm: 8px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 10px 25px -5px rgba(27, 37, 75, 0.05);
            --shadow-lg: 0 25px 50px -12px rgba(27, 37, 75, 0.15);

            /* Fonts */
            --font-sans: 'Inter', sans-serif;
            --font-serif: 'Playfair Display', serif;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-white);
            color: var(--text-dark);
            line-height: 1.6;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        img {
            max-width: 100%;
            height: auto;
            display: block;
        }

        ul {
            list-style: none;
        }

        /* ==========================================================================
           UTILITY CLASSES
           ========================================================================== */
        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 40px;
        }

        .section {
            padding: 120px 0;
        }

        .section-light {
            background-color: var(--bg-white);
        }

        .section-gray {
            background-color: var(--bg-gray);
        }

        .section-dark {
            background-color: var(--navy);
            color: white;
        }

        .tagline {
            display: inline-block;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--blue);
            margin-bottom: 16px;
        }

        .tagline.light {
            color: var(--green);
        }

        .heading-xl {
            font-family: var(--font-serif);
            font-size: clamp(40px, 5vw, 64px);
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 24px;
        }

        .heading-lg {
            font-family: var(--font-serif);
            font-size: clamp(32px, 4vw, 48px);
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 24px;
            color: var(--navy-deep);
        }

        .body-lg {
            font-size: 18px;
            color: var(--text-muted);
            line-height: 1.7;
            max-width: 700px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 16px 32px;
            border-radius: 100px;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-primary {
            background-color: var(--blue);
            color: white;
            box-shadow: 0 10px 20px rgba(67, 24, 255, 0.2);
        }

        .btn-primary:hover {
            background-color: var(--blue-hover);
            transform: translateY(-2px);
            box-shadow: 0 15px 25px rgba(67, 24, 255, 0.3);
        }

        .btn-outline {
            border: 1px solid var(--border);
            color: var(--text-dark);
            background: transparent;
        }

        .btn-outline:hover {
            border-color: var(--navy);
            background: rgba(27, 37, 75, 0.03);
        }

        .btn-outline-light {
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
        }

        .btn-outline-light:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.4);
        }

        /* ==========================================================================
           1. NAVIGATION
           ========================================================================== */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 20px 0;
            transition: all 0.3s ease;
            background: transparent;
        }

        .navbar.scrolled {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 14px 0;
            box-shadow: var(--shadow-sm);
        }

        .navbar.scrolled .nav-logo {
            color: var(--navy);
        }

        .navbar.scrolled .nav-links a {
            color: var(--text-muted);
        }

        .navbar.scrolled .nav-links a:hover {
            color: var(--blue);
        }

        /* Nav colors on transparent dark background */
        .navbar:not(.scrolled) .nav-logo {
            color: white;
        }

        .navbar:not(.scrolled) .nav-links a {
            color: rgba(255, 255, 255, 0.7);
        }

        .navbar:not(.scrolled) .nav-links a:hover {
            color: white;
        }

        .nav-inner {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .nav-logo {
            font-size: 22px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-logo i {
            color: var(--blue);
        }

        .nav-links {
            display: flex;
            gap: 32px;
            align-items: center;
        }

        .nav-links a {
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
        }

        .nav-actions {
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .nav-login {
            font-size: 14px;
            font-weight: 600;
            margin-right: 8px;
        }

        .navbar:not(.scrolled) .nav-login {
            color: white;
        }

        .navbar.scrolled .nav-login {
            color: var(--text-dark);
        }

        .navbar.scrolled .nav-login:hover {
            color: var(--blue);
        }

        .nav-btn {
            background: var(--blue);
            color: white !important;
            padding: 10px 24px;
            border-radius: 100px;
            font-size: 14px;
            font-weight: 600;
        }

        .nav-btn:hover {
            background: var(--blue-hover);
        }

        /* ==========================================================================
           2. HERO SECTION
           ========================================================================== */
        .hero {
            padding: 180px 0 100px;
            background: linear-gradient(145deg, #0f1535 0%, #1b254b 100%);
            position: relative;
            text-align: center;
            color: white;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 50% -20%, rgba(67, 24, 255, 0.4), transparent 50%),
                radial-gradient(circle at 10% 80%, rgba(5, 205, 153, 0.15), transparent 40%);
            pointer-events: none;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 800px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 8px 16px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 32px;
            backdrop-filter: blur(4px);
        }

        .hero-badge span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 10px var(--green);
        }

        .hero-headline em {
            font-style: italic;
            background: linear-gradient(135deg, var(--green), #34d399);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero .body-lg {
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 40px;
        }

        .hero-actions {
            display: flex;
            gap: 16px;
            justify-content: center;
            margin-bottom: 60px;
        }

        /* Hero Dashboard Mockup */
        .hero-mockup {
            position: relative;
            z-index: 2;
            margin: 0 auto -200px;
            /* Pulls next section up slightly */
            max-width: 1000px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transform: perspective(1000px) rotateX(2deg);
            transition: transform 0.5s ease;
        }

        .hero-mockup:hover {
            transform: perspective(1000px) rotateX(0deg);
        }

        .mockup-header {
            background: #F8FAFC;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid var(--border);
        }

        .mockup-dots {
            display: flex;
            gap: 6px;
        }

        .mockup-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #E2E8F0;
        }

        .mockup-dot.r {
            background: #ff5f56;
        }

        .mockup-dot.y {
            background: #ffbd2e;
        }

        .mockup-dot.g {
            background: #27c93f;
        }

        .mockup-body {
            height: 400px;
            background: #F4F7FE url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" opacity="0.03"><rect width="100" height="100" fill="none" stroke="%231B254B" stroke-width="2"/></svg>');
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .mockup-placeholder-text {
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 500;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }

        .mockup-placeholder-text i {
            font-size: 32px;
            color: var(--border);
        }

        /* ==========================================================================
           3. PROBLEM -> SOLUTION
           ========================================================================== */
        .ps-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 80px;
            align-items: center;
        }

        .problem-side {
            padding-right: 40px;
            border-right: 1px solid var(--border);
        }

        .ps-list {
            margin-top: 32px;
        }

        .ps-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 24px;
        }

        .ps-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 12px;
        }

        .problem-side .ps-icon {
            background: #FEF2F2;
            color: #EF4444;
        }

        .solution-side .ps-icon {
            background: var(--green-soft);
            color: var(--green);
        }

        .ps-text h4 {
            font-size: 16px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 4px;
        }

        .ps-text p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        /* ==========================================================================
           4. CORE FEATURES
           ========================================================================== */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
            margin-top: 60px;
        }

        .feature-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 40px 32px;
            transition: all 0.3s ease;
        }

        .feature-card:hover {
            border-color: rgba(67, 24, 255, 0.3);
            box-shadow: var(--shadow-md);
            transform: translateY(-4px);
        }

        .feature-icon-wrap {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--blue-soft);
            color: var(--blue);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 24px;
        }

        .feature-card h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 12px;
        }

        .feature-card p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        /* ==========================================================================
           5. HOW IT WORKS (WORKFLOW)
           ========================================================================== */
        .workflow {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-top: 60px;
            position: relative;
        }

        .workflow::before {
            content: '';
            position: absolute;
            top: 24px;
            left: 12%;
            right: 12%;
            height: 2px;
            background: var(--border);
            z-index: 0;
        }

        .step-card {
            text-align: center;
            position: relative;
            z-index: 1;
        }

        .step-num {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: white;
            border: 2px solid var(--blue);
            color: var(--blue);
            font-size: 16px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            box-shadow: 0 0 0 10px var(--bg-gray);
        }

        .step-card h4 {
            font-size: 16px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 8px;
        }

        .step-card p {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        /* ==========================================================================
           6. ROLE-BASED EXPERIENCE
           ========================================================================== */
        .roles-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-top: 60px;
        }

        .role-card {
            background: var(--bg-gray);
            border-radius: var(--radius-md);
            padding: 32px 24px;
            text-align: center;
            border: 1px solid transparent;
            transition: all 0.3s;
        }

        .role-card:hover {
            background: white;
            border-color: var(--border);
            box-shadow: var(--shadow-sm);
        }

        .role-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }

        .role-card h4 {
            font-size: 18px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 12px;
        }

        .role-card p {
            font-size: 13px;
            color: var(--text-muted);
        }

        /* ==========================================================================
           7. DASHBOARD SHOWCASE
           ========================================================================== */
        .showcase-wrapper {
            margin-top: 60px;
            position: relative;
        }

        .showcase-main {
            background: var(--bg-gray);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            aspect-ratio: 16/9;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .showcase-text {
            text-align: center;
            color: var(--text-muted);
        }

        /* ==========================================================================
           8. BENEFITS
           ========================================================================== */
        .benefits-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 60px;
        }

        .benefit-row {
            display: flex;
            gap: 20px;
            padding: 24px;
            border-radius: var(--radius-md);
            transition: background 0.2s;
        }

        .benefit-row:hover {
            background: var(--bg-gray);
        }

        .benefit-check {
            color: var(--green);
            font-size: 20px;
            margin-top: 2px;
        }

        .benefit-content h4 {
            font-size: 18px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 8px;
        }

        .benefit-content p {
            font-size: 14px;
            color: var(--text-muted);
        }

        /* ==========================================================================
           9. TRUST & SECURITY
           ========================================================================== */
        .trust-section {
            background-color: var(--navy);
            color: white;
            text-align: center;
            padding: 80px 0;
        }

        .trust-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 40px;
            margin-top: 48px;
        }

        .trust-item i {
            font-size: 32px;
            color: rgba(255, 255, 255, 0.3);
            margin-bottom: 16px;
        }

        .trust-item h4 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .trust-item p {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.6);
        }

        /* ==========================================================================
           10. CTA & FOOTER
           ========================================================================== */
        .cta-section {
            text-align: center;
            padding: 120px 0;
            background: var(--bg-gray);
        }

        .cta-section .heading-lg {
            margin-bottom: 16px;
        }

        .cta-section .body-lg {
            margin: 0 auto 40px;
        }

        .footer {
            background: white;
            border-top: 1px solid var(--border);
            padding: 80px 0 40px;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 60px;
            margin-bottom: 60px;
        }

        .footer-brand p {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 16px;
            max-width: 300px;
        }

        .footer-col h5 {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--navy);
            margin-bottom: 20px;
            letter-spacing: 1px;
        }

        .footer-col ul {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .footer-col a {
            font-size: 14px;
            color: var(--text-muted);
            transition: color 0.2s;
        }

        .footer-col a:hover {
            color: var(--blue);
        }

        .footer-bottom {
            border-top: 1px solid var(--border);
            padding-top: 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: var(--text-muted);
        }

        /* ==========================================================================
           RESPONSIVE DESIGN
           ========================================================================== */
        @media (max-width: 1024px) {
            .ps-grid {
                grid-template-columns: 1fr;
                gap: 40px;
            }

            .problem-side {
                border-right: none;
                padding-right: 0;
                border-bottom: 1px solid var(--border);
                padding-bottom: 40px;
            }

            .features-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .roles-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .footer-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }

            .workflow {
                grid-template-columns: 1fr;
                gap: 40px;
            }

            .workflow::before {
                display: none;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .benefits-grid {
                grid-template-columns: 1fr;
            }

            .trust-grid {
                grid-template-columns: 1fr;
            }

            .hero-mockup {
                display: none;
            }

            .footer-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <!-- 1. NAVIGATION -->
    <nav class="navbar" id="navbar">
        <div class="container nav-inner">
            <a href="#" class="nav-logo">
                <i class="fa-solid fa-shield-virus"></i> VaxiCare
            </a>
            <div class="nav-links">
                <a href="#overview">Overview</a>
                <a href="#features">Features</a>
                <a href="#how-it-works">How It Works</a>
                <a href="#benefits">Benefits</a>
            </div>
            <div class="nav-actions">
                <a href="auth/login.php" class="nav-login">Sign In</a>
                <a href="admin/login.php" class="btn nav-btn"><i class="fa-solid fa-user-shield" style="margin-right:6px;"></i> Admin Portal</a>
            </div>
        </div>
    </nav>

    <!-- 2. HERO SECTION -->
    <header class="hero">
        <div class="container">
            <div class="hero-content">
                <div class="hero-badge">
                    <span></span> Built for Pediatric Facilities
                </div>
                <h1 class="heading-xl">
                    Smarter Vaccination Management.<br>
                    <em>Better Patient Follow Up.</em>
                </h1>
                <p class="body-lg">
                    Bring appointment scheduling, Guardian/Parent reminders, vaccination tracking, and pharmacy coordination
                    into one connected platform.
                </p>
                <div class="hero-actions">
                    <a href="auth/login.php" class="btn btn-primary">Get Started</a>
                    <a href="#overview" class="btn btn-outline-light">Explore the Platform</a>
                </div>
            </div>


        </div>
    </header>

    <!-- 3. PROBLEM -> SOLUTION -->
    <section class="section section-light prob-sol-section" id="overview">
        <div class="container">
            <div class="ps-grid">
                <div class="problem-side">
                    <span class="tagline">The Challenge</span>
                    <h2 class="heading-lg">Healthcare shouldn't depend on disconnected processes.</h2>
                    <p class="body-lg" style="font-size:16px;">Manual processes lead to critical gaps in patient care
                        and operational efficiency.</p>
                    <div class="ps-list">
                        <div class="ps-item">
                            <div class="ps-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
                            <div class="ps-text">
                                <h4>Missed Appointments</h4>
                                <p>Guardians/Parents forget dates without automated follow-ups.</p>
                            </div>
                        </div>
                        <div class="ps-item">
                            <div class="ps-icon"><i class="fa-solid fa-box-open"></i></div>
                            <div class="ps-text">
                                <h4>Pharmacy Stock-Outs</h4>
                                <p>No visibility into upcoming vaccine demand.</p>
                            </div>
                        </div>
                        <div class="ps-item">
                            <div class="ps-icon"><i class="fa-solid fa-users-slash"></i></div>
                            <div class="ps-text">
                                <h4>Poor Communication</h4>
                                <p>Fragmented communication between departments.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="solution-side">
                    <span class="tagline light">The Solution</span>
                    <h2 class="heading-lg">One connected platform for complete oversight.</h2>
                    <p class="body-lg" style="font-size:16px;">VaxiCare coordinates scheduling, notifications, and
                        inventory in real-time.</p>
                    <div class="ps-list">
                        <div class="ps-item">
                            <div class="ps-icon"><i class="fa-solid fa-calendar-check"></i></div>
                            <div class="ps-text">
                                <h4>Unified Scheduling</h4>
                                <p>Centralized booking visible to all authorized staff.</p>
                            </div>
                        </div>
                        <div class="ps-item">
                            <div class="ps-icon"><i class="fa-solid fa-comment-sms"></i></div>
                            <div class="ps-text">
                                <h4>Proactive Reminders</h4>
                                <p>Automated SMS alerts ensure Guardians/Parents show up.</p>
                            </div>
                        </div>
                        <div class="ps-item">
                            <div class="ps-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                            <div class="ps-text">
                                <h4>Demand Forecasting</h4>
                                <p>Pharmacy sees exactly what vaccines are needed, when.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 4. CORE FEATURES -->
    <section class="section section-gray" id="features">
        <div class="container">
            <div style="text-align: center; max-width: 600px; margin: 0 auto;">
                <span class="tagline">Platform Features</span>
                <h2 class="heading-lg">Everything needed to manage pediatric vaccinations.</h2>
            </div>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon-wrap"><i class="fa-regular fa-calendar-plus"></i></div>
                    <h3>Smart Appointment Scheduling</h3>
                    <p>Schedule, reschedule, confirm, and manage pediatric vaccination appointments from one centralized
                        platform.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon-wrap"><i class="fa-solid fa-mobile-screen-button"></i></div>
                    <h3>Automated Reminders</h3>
                    <p>Send timely two-way SMS reminders to Guardians/Parents before scheduled vaccination appointments.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon-wrap"><i class="fa-solid fa-list-check"></i></div>
                    <h3>Vaccination Tracking</h3>
                    <p>Allow healthcare staff to monitor scheduled appointments, arrivals, no-shows, and administered
                        vaccinations.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon-wrap"><i class="fa-solid fa-pills"></i></div>
                    <h3>Pharmacy Integration</h3>
                    <p>Connect appointment demand with pharmacy inventory so staff can better anticipate upcoming
                        vaccine requirements.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon-wrap"><i class="fa-solid fa-triangle-exclamation"></i></div>
                    <h3>Low-Stock Alerts</h3>
                    <p>Provide visibility into vaccine supply levels and notify pharmacy staff when stock requires
                        attention.</p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon-wrap"><i class="fa-solid fa-users-gear"></i></div>
                    <h3>Real-Time Coordination</h3>
                    <p>Give nurses, pharmacists, Guardians/Parents, and administrators the exact information they need through
                        role-specific interfaces.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 5. HOW IT WORKS -->
    <section class="section section-light" id="how-it-works">
        <div class="container">
            <div style="text-align: center; max-width: 600px; margin: 0 auto;">
                <span class="tagline">Workflow</span>
                <h2 class="heading-lg">A seamless process from booking to vaccination.</h2>
            </div>

            <div class="workflow">
                <div class="step-card">
                    <div class="step-num">01</div>
                    <h4>Schedule</h4>
                    <p>A Guardian/Parent or nurse schedules a child's vaccination appointment in the system.</p>
                </div>
                <div class="step-card">
                    <div class="step-num">02</div>
                    <h4>Confirm</h4>
                    <p>The appointment is instantly confirmed and recorded in the clinic's master schedule.</p>
                </div>
                <div class="step-card">
                    <div class="step-num">03</div>
                    <h4>Remind</h4>
                    <p>The system automatically sends timely SMS reminders prior to the visit.</p>
                </div>
                <div class="step-card">
                    <div class="step-num">04</div>
                    <h4>Prepare & Vaccinate</h4>
                    <p>Pharmacy anticipates demand, staff confirms arrival, and the dose is administered.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 6. ROLE-BASED EXPERIENCE -->
    <section class="section section-gray">
        <div class="container">
            <div style="text-align: center; max-width: 600px; margin: 0 auto;">
                <span class="tagline">Tailored Interfaces</span>
                <h2 class="heading-lg">Designed specifically for the people using it.</h2>
            </div>

            <div class="roles-grid">
                <div class="role-card">
                    <div class="role-avatar" style="background: linear-gradient(135deg, #10B981, #059669);"><i
                            class="fa-solid fa-user-group"></i></div>
                    <h4>Guardian/Parent</h4>
                    <p>Easily track children, view upcoming vaccination dates, and receive helpful notifications.</p>
                </div>
                <div class="role-card">
                    <div class="role-avatar" style="background: linear-gradient(135deg, #3B82F6, #2563EB);"><i
                            class="fa-solid fa-user-nurse"></i></div>
                    <h4>Nurse</h4>
                    <p>View daily schedules, record administered vaccinations, and manage patient arrival statuses.</p>
                </div>
                <div class="role-card">
                    <div class="role-avatar" style="background: linear-gradient(135deg, #8B5CF6, #6D28D9);"><i
                            class="fa-solid fa-prescription-bottle-medical"></i></div>
                    <h4>Pharmacist</h4>
                    <p>Monitor real-time vaccine stock levels and anticipate upcoming demand based on schedules.</p>
                </div>
                <div class="role-card">
                    <div class="role-avatar" style="background: linear-gradient(135deg, #475569, #334155);"><i
                            class="fa-solid fa-user-shield"></i></div>
                    <h4>Administrator</h4>
                    <p>Manage system users, monitor SMS delivery, and oversee global hospital operations.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 7. DASHBOARD SHOWCASE -->
    <section class="section section-light">
        <div class="container">
            <div style="text-align: center; max-width: 600px; margin: 0 auto;">
                <span class="tagline">Product Showcase</span>
                <h2 class="heading-lg">Everything you need.<br>One connected platform.</h2>
            </div>

            <div class="showcase-wrapper">
                <div class="mockup-header"
                    style="border-radius: 16px 16px 0 0; border: 1px solid var(--border); border-bottom:none;">
                    <div class="mockup-dots">
                        <div class="mockup-dot r"></div>
                        <div class="mockup-dot y"></div>
                        <div class="mockup-dot g"></div>
                    </div>
                </div>
                <div class="showcase-main">
                        <img src="assets/images/dashboard_mockup.jpg" alt="VaxiCare Dashboard UI Mockup" style="width: 100%; height: auto; display: block; border-radius: 0 0 16px 16px;">
                </div>
            </div>
        </div>
    </section>

    <!-- 8. BENEFITS -->
    <section class="section section-gray" id="benefits">
        <div class="container">
            <div style="text-align: center; max-width: 600px; margin: 0 auto;">
                <span class="tagline">Outcomes</span>
                <h2 class="heading-lg">Measurable improvements for modern hospitals.</h2>
            </div>

            <div class="benefits-grid">
                <div class="benefit-row">
                    <div class="benefit-check"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="benefit-content">
                        <h4>Reduce Missed Appointments</h4>
                        <p>Help Guardians/Parents stay informed about upcoming vaccination visits with automated SMS reminders.
                        </p>
                    </div>
                </div>
                <div class="benefit-row">
                    <div class="benefit-check"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="benefit-content">
                        <h4>Improve Clinical Readiness</h4>
                        <p>Give healthcare teams better visibility into scheduled vaccination demand to organize daily
                            workflows.</p>
                    </div>
                </div>
                <div class="benefit-row">
                    <div class="benefit-check"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="benefit-content">
                        <h4>Improve Vaccine Availability</h4>
                        <p>Help pharmacy teams anticipate upcoming requirements and prevent stock-outs before they
                            happen.</p>
                    </div>
                </div>
                <div class="benefit-row">
                    <div class="benefit-check"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="benefit-content">
                        <h4>Reduce Administrative Friction</h4>
                        <p>Replace fragmented scheduling and communication processes with one centralized, digital
                            platform.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 9. TRUST & SECURITY -->
    <section class="trust-section">
        <div class="container">
            <h2 class="heading-lg" style="color:white; margin-bottom:16px;">Built for Healthcare Reliability</h2>
            <p class="body-lg" style="margin: 0 auto; color:rgba(255,255,255,0.7);">Protecting patient data and ensuring
                operational continuity.</p>

            <div class="trust-grid">
                <div class="trust-item">
                    <i class="fa-solid fa-user-lock"></i>
                    <h4>Role-Based Access</h4>
                    <p>Strict access controls ensure staff only see the patient data required for their specific role.
                    </p>
                </div>
                <div class="trust-item">
                    <i class="fa-solid fa-shield-halved"></i>
                    <h4>Secure Information</h4>
                    <p>Built following standard security practices to protect sensitive pediatric records.</p>
                </div>
                <div class="trust-item">
                    <i class="fa-solid fa-tower-cell"></i>
                    <h4>Reliable Notifications</h4>
                    <p>Integrated with robust SMS gateways to ensure appointment reminders are delivered on time.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 10. CTA & FOOTER -->
    <section class="cta-section">
        <div class="container">
            <h2 class="heading-lg">Make every vaccination appointment count.</h2>
            <p class="body-lg">Bring appointment scheduling, Guardian/Parent reminders, vaccination tracking, and pharmacy
                coordination into one connected platform.</p>
            <a href="auth/login.php" class="btn btn-primary" style="padding: 18px 40px; font-size: 16px;">Get
                Started</a>
        </div>
    </section>

    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <a href="#" class="nav-logo" style="color:var(--navy);">
                        <i class="fa-solid fa-shield-virus"></i> VaxiCare
                    </a>
                    <p>Smarter pediatric vaccination management, built to help healthcare facilities reduce missed
                        appointments and optimize inventory.</p>
                </div>
                <div class="footer-col">
                    <h5>Platform</h5>
                    <ul>
                        <li><a href="#features">Features</a></li>
                        <li><a href="#how-it-works">How It Works</a></li>
                        <li><a href="#benefits">Benefits</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h5>Access</h5>
                    <ul>
                        <li><span style="color:#64748b; font-size:13.5px;">Guardian: Mobile App</span></li>
                        <li><a href="auth/login.php">Staff Sign In</a></li>
                        <li><a href="admin/login.php">Admin Sign In</a></li>
                    </ul>
                </div>
                <div class="footer-col">
                    <h5>Company</h5>
                    <ul>
                        <li><a href="#">About Project</a></li>
                        <li><a href="#">Contact Support</a></li>
                        <li><a href="#">Privacy Policy</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2026 VaxiCare. All rights reserved.</p>
                <div style="display:flex; gap:16px;">
                    <a href="#"><i class="fa-brands fa-twitter"></i></a>
                    <a href="#"><i class="fa-brands fa-linkedin"></i></a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Sticky Navbar logic
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
    </script>
</body>

</html>