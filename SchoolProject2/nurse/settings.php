<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user profile from database
$stmt = $pdo->prepare("SELECT id, name, phone, email, profile_pic, password_hash, role, created_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user_profile) {
    header("Location: ../auth/login.php");
    exit;
}

$nurse_name = $user_profile['name'] ?? $_SESSION['name'] ?? 'Bupe';
$profile_pic = ($user_profile['profile_pic']) 
    ? '../uploads/' . htmlspecialchars($user_profile['profile_pic']) 
    : "https://ui-avatars.com/api/?name=" . urlencode($nurse_name) . "&background=2563eb&color=fff&bold=true";

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $new_name = trim($_POST['name'] ?? '');
        $new_email = trim($_POST['email'] ?? '');
        
        if (!empty($new_name)) {
            $update_stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $update_stmt->execute([$new_name, $new_email, $user_id]);
            
            $_SESSION['name'] = $new_name;
            $nurse_name = $new_name;
            $user_profile['name'] = $new_name;
            $user_profile['email'] = $new_email;
            $success_message = "Nurse profile details successfully updated in database!";
        } else {
            $error_message = "Full name cannot be left blank.";
        }
    } 
    elseif ($action === 'update_password') {
        $current_pwd = $_POST['current_password'] ?? '';
        $new_pwd = $_POST['new_password'] ?? '';
        $confirm_pwd = $_POST['confirm_password'] ?? '';
        
        if (empty($current_pwd) || empty($new_pwd) || empty($confirm_pwd)) {
            $error_message = "All password fields are required.";
        } elseif ($new_pwd !== $confirm_pwd) {
            $error_message = "New password and confirmation do not match.";
        } elseif (strlen($new_pwd) < 6) {
            $error_message = "New password must be at least 6 characters in length.";
        } else {
            if (password_verify($current_pwd, $user_profile['password_hash'])) {
                $hashed = password_hash($new_pwd, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hashed, $user_id]);
                $user_profile['password_hash'] = $hashed;
                $success_message = "Security password updated successfully!";
            } else {
                $error_message = "Current password entered is incorrect. Authentication failed.";
            }
        }
    }
}

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'nurse') AND is_read = 0");
$stmt->execute([$user_id]);
$unread_msg_count = (int)$stmt->fetchColumn();

// Pending requisitions for sidebar
$pending_reqs_count = (int)$pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'Pending'")->fetchColumn();
$current_date = date('l, d F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings &amp; Security &mdash; VaxiCare</title>
    
    <!-- Google Fonts: Inter & Space Grotesk -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Canonical Nurse Station Stylesheet -->
    <link rel="stylesheet" href="../css/nurse_theme.css">

    <style>
        .settings-grid-layout {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 1024px) {
            .settings-grid-layout {
                grid-template-columns: 1fr;
            }
        }

        .settings-card {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 16px;
            padding: 26px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            position: relative;
        }

        .settings-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 18px;
            margin-bottom: 22px;
        }

        .settings-card-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .settings-icon-pill {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .icon-pill-amber {
            background: #fffbe8;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        .icon-pill-emerald {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }

        .form-label-styled {
            display: block;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            margin-bottom: 7px;
        }

        .input-icon-wrap {
            position: relative;
            width: 100%;
        }

        .input-icon-wrap i.prefix-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 14px;
        }

        .input-icon-wrap .toggle-pwd-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 6px;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .input-icon-wrap .toggle-pwd-btn:hover {
            color: #2563eb;
            background: #f1f5f9;
        }

        .form-control-styled {
            width: 100%;
            padding: 12px 14px 12px 42px;
            font-size: 13.5px;
            font-family: inherit;
            border-radius: 10px;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            color: #0f172a;
            box-sizing: border-box;
            outline: none;
            transition: all 0.2s;
        }

        .form-control-styled:focus {
            border-color: #2563eb;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .locked-field {
            background-color: #f8fafc !important;
            color: #64748b !important;
            border-color: #e2e8f0 !important;
            cursor: not-allowed;
        }

        .avatar-preview-banner {
            display: flex;
            align-items: center;
            gap: 16px;
            background: #f8fbff;
            border: 1.5px solid #dbeafe;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 22px;
        }

        .avatar-preview-img {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #2563eb;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.18);
        }

        .avatar-preview-meta h4 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px 0;
        }

        .avatar-preview-meta p {
            font-size: 12px;
            color: #64748b;
            margin: 0;
        }
    </style>
</head>
<body class="app-body">
    
    <!-- Mobile Backdrop Overlay -->
    <div class="mobile-sidebar-backdrop" id="mobileSidebarBackdrop" onclick="toggleSidebar(false)"></div>

    <!-- ===== CANONICAL NURSE SIDEBAR ===== -->
    <aside class="sidebar" id="nurseSidebar">
        <div class="sidebar-brand-box">
            <a href="dashboard.php" class="sidebar-brand-link">
                <div class="sidebar-brand-icon"><i class="fa-solid fa-user-nurse"></i></div>
                <div class="sidebar-brand-info">
                    <span class="sidebar-brand-title">Vax<span>Care</span></span>
                    <span class="sidebar-brand-sub">Clinical Station</span>
                </div>
            </a>
        </div>
        
        <nav class="sidebar-menu">
            <div class="sidebar-section-title">Overview</div>
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-border-all"></i> Dashboard
            </a>

            <div class="sidebar-section-title">Pediatric Care</div>
            <a href="register_patient.php" class="sidebar-item">
                <i class="fa-solid fa-user-plus"></i> Register Patient
            </a>
            <a href="patients.php" class="sidebar-item">
                <i class="fa-solid fa-users"></i> Patient Directory
            </a>
            <a href="appointments.php" class="sidebar-item">
                <i class="fa-solid fa-calendar-check"></i> Appointments
            </a>
            <a href="triage.php" class="sidebar-item">
                <i class="fa-solid fa-heart-pulse"></i> Triage Vitals
            </a>

            <div class="sidebar-section-title">Supplies &amp; Comms</div>
            <a href="sms_reminders.php" class="sidebar-item">
                <i class="fa-solid fa-comment-sms"></i> SMS Reminders
            </a>
            <a href="requisition.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-stacked"></i> Requisitions
                <?php if($pending_reqs_count > 0): ?>
                    <span class="badge-red" style="background:#f59e0b;"><?= $pending_reqs_count ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Governance &amp; Profile</div>
            <a href="messages.php" class="sidebar-item">
                <i class="fa-solid fa-envelope"></i> Messages
                <?php if($unread_msg_count > 0): ?>
                    <span class="badge-red"><?= $unread_msg_count ?></span>
                <?php endif; ?>
            </a>
            <a href="settings.php" class="sidebar-item active">
                <i class="fa-solid fa-gear"></i> Settings
            </a>
        </nav>
        
        <div class="sidebar-logout">
            <a href="../auth/logout.php">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
            </a>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="app-main">
        
        <!-- ===== TOPBAR (WITH INLINE BREADCRUMBS & CENTERED DATE) ===== -->
        <header class="app-topbar">
            <div style="display:flex; align-items:center;">
                <button type="button" class="mobile-nav-toggle" onclick="toggleSidebar(true)" aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-left" style="display:flex; flex-direction:row; align-items:center; gap:14px; flex-wrap:wrap;">
                    <h1>Account Settings</h1>
                    <div class="topbar-breadcrumbs" style="display:flex; align-items:center; gap:6px; margin:0;">
                        <span>Clinical Station</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span class="current">Settings &amp; Security</span>
                    </div>
                </div>
            </div>

            <!-- Centered Date & Context -->
            <div class="topbar-center" style="display:flex; align-items:center; justify-content:center;">
                <div class="date-range">
                    <i class="fa-solid fa-user-shield"></i> Nurse Security Portal &bull; <?= $current_date ?>
                </div>
            </div>

            <!-- Right Controls -->
            <div class="topbar-right">
                <div class="topbar-control-pill">
                    <a href="messages.php" class="topbar-action-btn" title="Messages">
                        <i class="fa-regular fa-bell"></i>
                        <?php if($unread_msg_count > 0): ?>
                            <span class="notif-ping"></span>
                        <?php endif; ?>
                    </a>

                    <a href="settings.php" class="topbar-action-btn active" title="Settings">
                        <i class="fa-solid fa-gear"></i>
                    </a>

                    <div class="topbar-divider"></div>

                    <div class="topbar-profile">
                        <div class="topbar-avatar-wrap">
                            <img src="<?= htmlspecialchars($profile_pic) ?>" alt="<?= htmlspecialchars($nurse_name) ?>">
                            <span class="avatar-status-dot"></span>
                        </div>
                        <div class="topbar-profile-info">
                            <span class="topbar-profile-name"><?= htmlspecialchars($nurse_name) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- System Response Feedback Banners -->
        <?php if($success_message): ?>
            <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:16px 20px; border-radius:14px; font-weight:700; margin-bottom:24px; display:flex; align-items:center; gap:12px; box-shadow:0 4px 12px rgba(16,185,129,0.08);">
                <i class="fa-solid fa-circle-check" style="font-size:18px; color:#10b981;"></i>
                <span><?= htmlspecialchars($success_message) ?></span>
            </div>
        <?php endif; ?>

        <?php if($error_message): ?>
            <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:16px 20px; border-radius:14px; font-weight:700; margin-bottom:24px; display:flex; align-items:center; gap:12px; box-shadow:0 4px 12px rgba(239,68,68,0.08);">
                <i class="fa-solid fa-triangle-exclamation" style="font-size:18px; color:#ef4444;"></i>
                <span><?= htmlspecialchars($error_message) ?></span>
            </div>
        <?php endif; ?>

        <!-- ===== ENLARGED TOP KPI METRIC CARDS ===== -->
        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 26px;">
            <div class="stat-card card-blue">
                <div class="stat-icon icon-blue">
                    <i class="fa-solid fa-user-doctor"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Clinical Identity</div>
                    <div class="stat-value" style="font-size:18px; line-height:1.2; margin: 4px 0;"><?= htmlspecialchars($nurse_name) ?></div>
                    <div class="stat-trend trend-neutral" style="color:#2563eb;">
                        <i class="fa-solid fa-id-badge"></i> Authorized Clinical Station Staff
                    </div>
                </div>
            </div>

            <div class="stat-card card-green">
                <div class="stat-icon icon-green">
                    <i class="fa-solid fa-shield-check"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Login Identifier</div>
                    <div class="stat-value" style="font-size:18px; line-height:1.2; margin: 4px 0;"><?= htmlspecialchars($user_profile['phone']) ?></div>
                    <div class="stat-trend trend-neutral" style="color:#10b981;">
                        <i class="fa-solid fa-mobile-screen-button"></i> Verified Primary System ID
                    </div>
                </div>
            </div>

            <div class="stat-card card-amber">
                <div class="stat-icon icon-amber">
                    <i class="fa-solid fa-key"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Security Protocol</div>
                    <div class="stat-value" style="font-size:18px; line-height:1.2; margin: 4px 0;">Bcrypt 256-bit</div>
                    <div class="stat-trend trend-neutral">
                        <i class="fa-solid fa-lock"></i> Real-time Hash Encryption
                    </div>
                </div>
            </div>

            <div class="stat-card card-purple">
                <div class="stat-icon icon-purple">
                    <i class="fa-solid fa-envelope"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Comms Queue</div>
                    <div class="stat-value"><?= number_format($unread_msg_count) ?> Unread</div>
                    <div class="stat-trend trend-neutral" style="color:<?= $unread_msg_count > 0 ? '#7c3aed' : '#64748b' ?>;">
                        <i class="fa-solid fa-inbox"></i> Inbound Nurse Inbox
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== MAIN 2-COLUMN SETTINGS WORKSPACE ===== -->
        <div class="settings-grid-layout">

            <!-- LEFT: Update Password Card -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="settings-card-title">
                        <div class="settings-icon-pill icon-pill-amber">
                            <i class="fa-solid fa-key"></i>
                        </div>
                        <div>
                            <h3 style="font-family:'Space Grotesk',sans-serif; font-size:16.5px; font-weight:700; color:#0f172a; margin:0;">
                                Change Security Password
                            </h3>
                            <p style="font-size:12px; color:#64748b; margin:2px 0 0 0;">
                                Update your clinical login password with instantaneous bcrypt encryption
                            </p>
                        </div>
                    </div>
                </div>

                <form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="update_password">

                    <div style="margin-bottom: 18px;">
                        <label class="form-label-styled">Current Password <span style="color:#ef4444;">*</span></label>
                        <div class="input-icon-wrap">
                            <i class="fa-solid fa-shield-halved prefix-icon"></i>
                            <input type="password" name="current_password" id="currPwdInput" class="form-control-styled" placeholder="Enter existing password" required>
                            <button type="button" class="toggle-pwd-btn" onclick="togglePasswordVisibility('currPwdInput', this)">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div style="margin-bottom: 18px;">
                        <label class="form-label-styled">New Password <span style="color:#ef4444;">*</span></label>
                        <div class="input-icon-wrap">
                            <i class="fa-solid fa-lock prefix-icon"></i>
                            <input type="password" name="new_password" id="newPwdInput" class="form-control-styled" placeholder="Min. 6 characters" minlength="6" required>
                            <button type="button" class="toggle-pwd-btn" onclick="togglePasswordVisibility('newPwdInput', this)">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div style="margin-bottom: 24px;">
                        <label class="form-label-styled">Confirm New Password <span style="color:#ef4444;">*</span></label>
                        <div class="input-icon-wrap">
                            <i class="fa-solid fa-circle-check prefix-icon"></i>
                            <input type="password" name="confirm_password" id="confPwdInput" class="form-control-styled" placeholder="Re-type new password" minlength="6" required>
                            <button type="button" class="toggle-pwd-btn" onclick="togglePasswordVisibility('confPwdInput', this)">
                                <i class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; border-top:1px solid #f1f5f9; padding-top:18px;">
                        <button type="submit" class="nr-btn nr-btn-primary" style="padding:12px 24px; font-weight:700; border: 1.5px solid #1d4ed8; box-shadow:0 4px 14px rgba(37,99,235,0.25);">
                            <i class="fa-solid fa-arrow-rotate-right"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>

            <!-- RIGHT: Profile Details Card -->
            <div class="settings-card">
                <div class="settings-card-header">
                    <div class="settings-card-title">
                        <div class="settings-icon-pill icon-pill-emerald">
                            <i class="fa-solid fa-user-nurse"></i>
                        </div>
                        <div>
                            <h3 style="font-family:'Space Grotesk',sans-serif; font-size:16.5px; font-weight:700; color:#0f172a; margin:0;">
                                Profile Information
                            </h3>
                            <p style="font-size:12px; color:#64748b; margin:2px 0 0 0;">
                                Registered nurse staff profile &amp; contact channels
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Avatar & Identity Preview Banner -->
                <div class="avatar-preview-banner">
                    <img src="<?= htmlspecialchars($profile_pic) ?>" alt="<?= htmlspecialchars($nurse_name) ?>" class="avatar-preview-img">
                    <div class="avatar-preview-meta">
                        <h4><?= htmlspecialchars($nurse_name) ?></h4>
                        <p><i class="fa-solid fa-hospital" style="color:#2563eb; margin-right:4px;"></i> Pediatrics &amp; Immunization Ward</p>
                    </div>
                </div>

                <form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div style="margin-bottom: 18px;">
                        <label class="form-label-styled">Nurse Full Name <span style="color:#ef4444;">*</span></label>
                        <div class="input-icon-wrap">
                            <i class="fa-solid fa-user prefix-icon"></i>
                            <input type="text" name="name" class="form-control-styled" value="<?= htmlspecialchars($nurse_name) ?>" required>
                        </div>
                    </div>

                    <div style="margin-bottom: 18px;">
                        <label class="form-label-styled" style="display:flex; justify-content:space-between; align-items:center;">
                            <span>Phone Number (Login ID)</span>
                            <span style="color:#dc2626; font-size:11px; text-transform:none; font-weight:700;"><i class="fa-solid fa-lock"></i> Fixed System ID</span>
                        </label>
                        <div class="input-icon-wrap">
                            <i class="fa-solid fa-phone prefix-icon"></i>
                            <input type="tel" class="form-control-styled locked-field" value="<?= htmlspecialchars($user_profile['phone']) ?>" readonly disabled>
                        </div>
                    </div>

                    <div style="margin-bottom: 24px;">
                        <label class="form-label-styled">Hospital Email Address</label>
                        <div class="input-icon-wrap">
                            <i class="fa-solid fa-envelope prefix-icon"></i>
                            <input type="email" name="email" class="form-control-styled" value="<?= htmlspecialchars($user_profile['email'] ?? '') ?>" placeholder="nurse@vaxicare.adch.gov.zm">
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; border-top:1px solid #f1f5f9; padding-top:18px;">
                        <button type="submit" class="nr-btn nr-btn-emerald" style="padding:12px 24px; font-weight:700; width:100%; border: 1.5px solid #047857; box-shadow:0 4px 14px rgba(16,185,129,0.22);">
                            <i class="fa-solid fa-floppy-disk"></i> Save Profile Details
                        </button>
                    </div>
                </form>
            </div>

        </div>

    </main>

    <script>
        // Toggle password visibility
        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Mobile Sidebar Toggle
        function toggleSidebar(open) {
            const sidebar = document.getElementById('nurseSidebar');
            const backdrop = document.getElementById('mobileSidebarBackdrop');
            if (sidebar && backdrop) {
                if (open) {
                    sidebar.classList.add('mobile-open');
                    backdrop.classList.add('active');
                } else {
                    sidebar.classList.remove('mobile-open');
                    backdrop.classList.remove('active');
                }
            }
        }
    </script>
</body>
</html>
