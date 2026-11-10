<?php
session_start();
include '../config/db.php';

// If already logged in, redirect based on role
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'guardian') {
        session_unset();
        session_destroy();
        session_start();
        $error = "Guardian accounts are now managed exclusively through the VaxiCare Mobile App. Please log in on your smartphone.";
    } elseif ($_SESSION['role'] === 'nurse') {
        header("Location: ../nurse/dashboard.php");
        exit;
    } elseif ($_SESSION['role'] === 'doctor') {
        header("Location: ../doctor/dashboard.php");
        exit;
    } elseif ($_SESSION['role'] === 'pharmacy') {
        header("Location: ../pharmacy/dashboard.php");
        exit;
    } elseif ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];

    if (empty($phone) || empty($password)) {
        $error = "Both phone number and password are required.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['role'] === 'admin') {
                // Redirect admin to dedicated executive login
                header("Location: ../admin/login.php");
                exit;
            }

            if ($user['role'] === 'guardian') {
                $error = "Guardian accounts are now managed exclusively through the VaxiCare Mobile App. Please log in on your smartphone.";
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['name'] = $user['name'];
                
                if ($user['role'] === 'nurse') {
                    header("Location: ../nurse/dashboard.php");
                } elseif ($user['role'] === 'doctor') {
                    header("Location: ../doctor/dashboard.php");
                } elseif ($user['role'] === 'pharmacy') {
                    header("Location: ../pharmacy/dashboard.php");
                } else {
                    header("Location: ../index.php"); 
                }
                exit;
            }
        } else {
            $error = "Invalid phone number or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — VaxiCare Healthcare Platform</title>
    <meta name="description" content="Sign in to VaxiCare to access pediatric vaccination schedules, patient follow-ups, and pharmacy inventory.">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --navy: #1B254B;
            --navy-deep: #0f1535;
            --blue: #4318FF;
            --blue-hover: #3710e0;
            --blue-soft: rgba(67, 24, 255, 0.08);
            --green: #05CD99;
            --text-dark: #1A1A1A;
            --text-muted: #64748B;
            --border: #E2E8F0;
            --radius-md: 16px;
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
            position: relative;
            overflow-x: hidden;
        }

        /* Subtle glowing background orbs */
        body::before {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(67, 24, 255, 0.25), transparent 70%);
            top: -100px;
            left: -100px;
            pointer-events: none;
        }
        body::after {
            content: '';
            position: absolute;
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, rgba(5, 205, 153, 0.15), transparent 70%);
            bottom: -100px;
            right: -100px;
            pointer-events: none;
        }

        .auth-card {
            background: #ffffff;
            width: 100%;
            max-width: 480px;
            border-radius: var(--radius-lg);
            padding: 44px 40px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.45);
            position: relative;
            z-index: 10;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .auth-brand {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
        }

        .brand-logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 22px;
            font-weight: 800;
            color: var(--navy);
            text-decoration: none;
            letter-spacing: -0.5px;
        }
        .brand-logo i {
            color: var(--blue);
            font-size: 24px;
        }

        .home-pill {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 100px;
            background: #F4F7FE;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .home-pill:hover {
            color: var(--blue);
            background: var(--blue-soft);
        }

        .auth-header {
            margin-bottom: 28px;
        }
        .auth-header h1 {
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 8px;
            line-height: 1.2;
        }
        .auth-header p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* Role badge pill banner */
        .role-banner {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #F4F7FE;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 11.5px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 24px;
        }
        .role-banner i {
            color: var(--blue);
            font-size: 13px;
        }

        .alert-error {
            background: #FEF2F2;
            border: 1px solid #FCA5A5;
            color: #991B1B;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            display: block;
            font-size: 12.5px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .input-wrapper {
            position: relative;
        }
        .input-wrapper i.field-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
            font-size: 15px;
            transition: color 0.2s;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px 14px 44px;
            font-size: 14px;
            font-family: inherit;
            color: #0F172A;
            background: #F8FAFC;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            outline: none;
            transition: all 0.2s ease;
        }
        .form-control:focus {
            background: #FFFFFF;
            border-color: var(--blue);
            box-shadow: 0 0 0 4px rgba(67, 24, 255, 0.1);
        }
        .form-control:focus + i.field-icon {
            color: var(--blue);
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94A3B8;
            cursor: pointer;
            font-size: 14px;
            padding: 4px;
        }
        .toggle-password:hover {
            color: var(--text-dark);
        }

        .btn-submit {
            width: 100%;
            padding: 15px;
            background: var(--blue);
            color: #ffffff;
            border: none;
            border-radius: 100px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 10px 20px rgba(67, 24, 255, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 26px;
        }
        .btn-submit:hover {
            background: var(--blue-hover);
            transform: translateY(-2px);
            box-shadow: 0 14px 26px rgba(67, 24, 255, 0.35);
        }

        .auth-footer-links {
            margin-top: 28px;
            padding-top: 22px;
            border-top: 1px solid #F1F5F9;
            text-align: center;
            font-size: 13.5px;
            color: var(--text-muted);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .auth-footer-links a {
            color: var(--blue);
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s;
        }
        .auth-footer-links a:hover {
            text-decoration: underline;
        }

        .admin-link-card {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 600;
            color: #475569;
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-decoration: none;
            transition: all 0.2s;
            margin-top: 6px;
        }
        .admin-link-card:hover {
            background: #EFF6FF;
            border-color: #BFDBFE;
            color: var(--blue);
        }

        @media (max-width: 480px) {
            .auth-card {
                padding: 32px 24px;
            }
            .auth-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>

    <div class="auth-card">
        <div class="auth-brand">
            <a href="../index.php" class="brand-logo">
                <i class="fa-solid fa-shield-virus"></i> VaxiCare
            </a>
            <a href="../index.php" class="home-pill">
                <i class="fa-solid fa-arrow-left"></i> Home
            </a>
        </div>

        <div class="auth-header">
            <h1>Welcome Back</h1>
            <p>Sign in to access your portal, vaccination schedule, and clinical records.</p>
        </div>

        <div class="role-banner">
            <i class="fa-solid fa-circle-check"></i>
            <span>Portal for Guardians, Nurses, Doctors &amp; Pharmacy</span>
        </div>

        <?php if($error): ?>
            <div class="alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label" for="phone">Phone Number</label>
                <div class="input-wrapper">
                    <input type="tel" id="phone" name="phone" class="form-control" placeholder="e.g. 0978000001" value="<?= htmlspecialchars($_POST['phone'] ?? $_GET['phone'] ?? '') ?>" required autofocus>
                    <i class="fa-solid fa-phone field-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
                    <i class="fa-solid fa-lock field-icon"></i>
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password visibility">
                        <i class="fa-regular fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fa-solid fa-right-to-bracket"></i> Sign In to Portal
            </button>
        </form>

    </div>

    <script>
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');

        togglePassword.addEventListener('click', () => {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            eyeIcon.className = isPassword ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
        });
    </script>
</body>
</html>
