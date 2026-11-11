<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

// If already logged in, redirect based on role
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: dashboard.php");
        exit;
    } else {
        header("Location: ../" . $_SESSION['role'] . "/dashboard.php");
        exit;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($phone) || empty($password)) {
        $error = "Please enter both administrator phone number and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['role'] !== 'admin') {
                $error = "Access Denied: This portal is restricted to System Administrators only. Clinical staff and guardians must use the main login.";
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role']    = $user['role'];
                $_SESSION['name']    = $user['name'];
                header("Location: dashboard.php");
                exit;
            }
        } else {
            $error = "Invalid administrator credentials.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrator Console — VaxiCare Governance</title>
    <meta name="description" content="Secure administrative access for system configuration, user auditing, and hospital operational reporting.">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --navy: #0F172A;
            --navy-surface: #1E293B;
            --accent: #2563EB;
            --accent-hover: #1D4ED8;
            --accent-soft: rgba(37, 99, 235, 0.12);
            --gold: #F59E0B;
            --text-dark: #0F172A;
            --text-muted: #64748B;
            --border: #E2E8F0;
            --radius-md: 16px;
            --radius-lg: 24px;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background: #0B1120;
            background-image: 
                radial-gradient(at 15% 15%, rgba(37, 99, 235, 0.25) 0px, transparent 55%),
                radial-gradient(at 85% 85%, rgba(15, 23, 42, 0.9) 0px, transparent 55%),
                radial-gradient(at 50% 50%, rgba(245, 158, 11, 0.05) 0px, transparent 60%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 20px;
            color: #0f172a;
            position: relative;
            overflow-x: hidden;
        }

        .admin-card {
            background: #ffffff;
            width: 100%;
            max-width: 460px;
            border-radius: var(--radius-lg);
            padding: 44px 40px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.7);
            position: relative;
            z-index: 10;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .admin-brand {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: #FEF3C7;
            border: 1px solid #FDE68A;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
            color: #92400E;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .home-pill {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 100px;
            background: #F1F5F9;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .home-pill:hover {
            color: var(--accent);
            background: #E2E8F0;
        }

        .admin-header {
            margin-bottom: 24px;
        }
        .admin-header h1 {
            font-family: 'Playfair Display', serif;
            font-size: 26px;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 8px;
            line-height: 1.2;
        }
        .admin-header p {
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.5;
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
            font-size: 12px;
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
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
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

        .btn-admin {
            width: 100%;
            padding: 15px;
            background: #0F172A;
            color: #ffffff;
            border: none;
            border-radius: 100px;
            font-size: 14.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 26px;
        }
        .btn-admin:hover {
            background: #1E293B;
            transform: translateY(-2px);
            box-shadow: 0 14px 26px rgba(15, 23, 42, 0.4);
        }

        .admin-footer {
            margin-top: 26px;
            padding-top: 20px;
            border-top: 1px solid #F1F5F9;
            text-align: center;
            font-size: 13px;
            color: var(--text-muted);
        }
        .admin-footer a {
            color: var(--accent);
            font-weight: 600;
            text-decoration: none;
        }
        .admin-footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .admin-card {
                padding: 32px 24px;
            }
            .admin-header h1 {
                font-size: 23px;
            }
        }
    </style>
</head>
<body>

    <div class="admin-card">
        <div class="admin-brand">
            <span class="brand-badge">
                <i class="fa-solid fa-lock"></i> Restricted Access
            </span>
            <a href="../index.php" class="home-pill">
                <i class="fa-solid fa-house"></i> Home
            </a>
        </div>

        <div class="admin-header">
            <h1>Admin Console</h1>
            <p>Enter your administrator credentials to access governance, auditing, and system controls.</p>
        </div>

        <?php if($error): ?>
            <div class="alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="form-group">
                <label class="form-label" for="phone">Admin Phone / ID</label>
                <div class="input-wrapper">
                    <input type="tel" id="phone" name="phone" class="form-control" placeholder="0970000001" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required autofocus>
                    <i class="fa-solid fa-user-shield field-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Security Password</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
                    <i class="fa-solid fa-key field-icon"></i>
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password visibility">
                        <i class="fa-regular fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-admin">
                <i class="fa-solid fa-right-to-bracket"></i> Authenticate Administrator
            </button>
        </form>

        <div class="admin-footer">
            <p><a href="../index.php"><i class="fa-solid fa-house" style="margin-right:4px;"></i> Return to Public Landing Page</a></p>
        </div>
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
