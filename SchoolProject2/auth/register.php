<?php
session_start();
include '../config/db.php';

// If already logged in, redirect based on role
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    header("Location: ../" . $_SESSION['role'] . "/dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Registration Information — VaxiCare</title>
    <meta name="description" content="Patient accounts are registered directly at the pediatric clinic by healthcare nurses.">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --navy: #1B254B;
            --blue: #4318FF;
            --text-dark: #1A1A1A;
            --text-muted: #64748B;
            --radius-lg: 24px;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background: linear-gradient(145deg, #0b1128 0%, #151d45 60%, #101738 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
            color: #1a1a1a;
        }
        .notice-card {
            background: #ffffff;
            width: 100%;
            max-width: 520px;
            border-radius: var(--radius-lg);
            padding: 44px 40px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.45);
            text-align: center;
        }
        .icon-badge {
            width: 72px;
            height: 72px;
            background: rgba(67, 24, 255, 0.1);
            color: var(--blue);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 20px;
        }
        h1 {
            font-family: 'Playfair Display', serif;
            font-size: 26px;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 12px;
        }
        p {
            font-size: 14.5px;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .info-box {
            background: #F4F7FE;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 18px 20px;
            text-align: left;
            font-size: 13.5px;
            color: #334155;
            margin-bottom: 28px;
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }
        .info-box i {
            color: var(--blue);
            font-size: 18px;
            margin-top: 2px;
        }
        .btn-login {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 15px;
            background: var(--blue);
            color: #ffffff;
            border: none;
            border-radius: 100px;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 10px 20px rgba(67, 24, 255, 0.25);
            transition: all 0.2s;
        }
        .btn-login:hover {
            background: #3710e0;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="notice-card">
        <div class="icon-badge">
            <i class="fa-solid fa-hospital-user"></i>
        </div>
        <h1>Clinical Registration Only</h1>
        <p>Patient and Guardian accounts are created directly by pediatric triage nurses during your clinic visit.</p>
        
        <div class="info-box">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <strong>Already registered at the clinic?</strong><br>
                Check your mobile phone for an SMS login link sent by the attending nurse, or sign in using your registered phone number.
            </div>
        </div>

        <a href="login.php" class="btn-login">
            <i class="fa-solid fa-right-to-bracket"></i> Sign In to Portal
        </a>
    </div>
</body>
</html>
