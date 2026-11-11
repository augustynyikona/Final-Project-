<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user profile from database
$stmt = $pdo->prepare("SELECT id, name, phone, email, profile_pic, password_hash FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user_profile) {
    header("Location: ../auth/login.php");
    exit;
}

$doctor_name = $user_profile['name'];
$profile_pic = ($user_profile && $user_profile['profile_pic']) 
    ? '../uploads/' . $user_profile['profile_pic'] 
    : "https://ui-avatars.com/api/?name=" . urlencode($doctor_name) . "&background=0284c7&color=fff";

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
            $doctor_name = $new_name;
            $user_profile['name'] = $new_name;
            $user_profile['email'] = $new_email;
            $success_message = "Doctor profile details updated successfully!";
        }
    } 
    elseif ($action === 'upload_picture') {
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $filename = $_FILES['profile_picture']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed) && $_FILES['profile_picture']['size'] <= 2 * 1024 * 1024) { // 2MB limit
                if (!file_exists('../uploads')) {
                    mkdir('../uploads', 0777, true);
                }
                
                $new_filename = 'doc_' . $user_id . '_' . time() . '.' . $ext;
                $destination = '../uploads/' . $new_filename;
                
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $destination)) {
                    $stmt = $pdo->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
                    $stmt->execute([$new_filename, $user_id]);
                    $profile_pic = $destination;
                    $success_message = "Profile picture updated successfully!";
                } else {
                    $error_message = "Failed to save uploaded picture.";
                }
            } else {
                $error_message = "Invalid file format or file exceeds 2MB limit.";
            }
        } else {
            $error_message = "Please select a valid image file.";
        }
    }
    elseif ($action === 'update_password') {
        $current_pwd = $_POST['current_password'] ?? '';
        $new_pwd = $_POST['new_password'] ?? '';
        $confirm_pwd = $_POST['confirm_password'] ?? '';
        
        if (empty($current_pwd) || empty($new_pwd) || empty($confirm_pwd)) {
            $error_message = "All password fields are required.";
        } elseif ($new_pwd !== $confirm_pwd) {
            $error_message = "New passwords do not match.";
        } elseif (strlen($new_pwd) < 6) {
            $error_message = "New password must be at least 6 characters in length.";
        } else {
            if (password_verify($current_pwd, $user_profile['password_hash'])) {
                $hashed = password_hash($new_pwd, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hashed, $user_id]);
                $user_profile['password_hash'] = $hashed;
                $success_message = "Password updated successfully in real time!";
            } else {
                $error_message = "Current password entered is incorrect.";
            }
        }
    }
}

// Unread messages and alerts for sidebar
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'doctor' OR recipient_role = 'all') AND is_read = 0");
$stmt->execute([$user_id]);
$unread_msg_count = (int)$stmt->fetchColumn();

$pending_alerts = (int)$pdo->query("SELECT COUNT(*) FROM triage_referrals WHERE status = 'pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Profile & Security Settings — VaxiCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/doctor_theme.css">
    <style>
        .settings-grid {
            display: grid;
            grid-template-columns: 1.25fr 1fr;
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 1024px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-label {
            display: block;
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #475569;
            margin-bottom: 6px;
        }

        .form-control-wrap {
            position: relative;
            margin-bottom: 18px;
        }

        .form-control-wrap i.input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 11px 14px 11px 42px;
            font-size: 13.5px;
            font-family: inherit;
            border-radius: 10px;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            color: #0f172a;
            outline: none;
            transition: all 0.2s;
        }

        .form-input:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
        }

        .form-input.readonly {
            background-color: #f8fafc;
            color: #64748b;
            cursor: not-allowed;
            border-color: #e2e8f0;
        }

        .avatar-upload-box {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 16px;
            background: #f8fafc;
            border-radius: 14px;
            border: 1.5px dashed #cbd5e1;
            margin-bottom: 20px;
        }

        .avatar-preview {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #0284c7;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.2);
        }
    </style>
</head>
<body class="app-body">

    <!-- Canonical Doctor Sidebar -->
    <aside class="sidebar" id="doctorSidebar">
        <div class="sidebar-brand-box">
            <a href="dashboard.php" class="sidebar-brand-link">
                <div class="sidebar-brand-icon">
                    <i class="fa-solid fa-user-doctor"></i>
                </div>
                <div class="sidebar-brand-info">
                    <div class="sidebar-brand-title">Vaxi<span>Care</span></div>
                    <div class="sidebar-brand-sub">Clinical Pediatrics</div>
                </div>
            </a>
        </div>

        <nav class="sidebar-menu">
            <div class="sidebar-section-title">Clinical Management</div>
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-chart-line"></i>
                <span>Clinical Overview</span>
            </a>
            <a href="patients.php" class="sidebar-item">
                <i class="fa-solid fa-children"></i>
                <span>Patients Directory</span>
            </a>
            <a href="schedule.php" class="sidebar-item">
                <i class="fa-regular fa-calendar-check"></i>
                <span>Clinical Schedule</span>
            </a>
            <a href="critical_alerts.php" class="sidebar-item">
                <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444;"></i>
                <span>Critical Alerts</span>
                <?php if ($pending_alerts > 0): ?>
                    <span class="badge-red"><?= $pending_alerts ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Clinical Intelligence</div>
            <a href="reports.php" class="sidebar-item">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Coverage Reports</span>
            </a>
            <a href="messages.php" class="sidebar-item">
                <i class="fa-regular fa-envelope"></i>
                <span>Consult Messages</span>
                <?php if ($unread_msg_count > 0): ?>
                    <span class="badge-red"><?= $unread_msg_count ?></span>
                <?php endif; ?>
            </a>
            <a href="settings.php" class="sidebar-item active">
                <i class="fa-solid fa-gear"></i>
                <span>Doctor Settings</span>
            </a>
        </nav>

        <div class="sidebar-logout">
            <a href="../auth/logout.php">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Log Out</span>
            </a>
        </div>
    </aside>

    <!-- Mobile Overlay Backdrop -->
    <div class="mobile-sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Main Workspace Content -->
    <main class="app-main">

        <!-- Canonical Elevated Topbar -->
        <header class="app-topbar">
            <div style="display: flex; align-items: center;">
                <button type="button" class="mobile-nav-toggle" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px; flex-wrap: wrap;">
                    <h1>Doctor Account Settings</h1>
                    <div class="topbar-breadcrumbs" style="display: flex; align-items: center; gap: 6px; margin: 0;">
                        <span>Doctor Station</span>
                        <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #cbd5e1;"></i>
                        <span class="current">Profile & Security Management</span>
                    </div>
                </div>
            </div>

            <div class="topbar-center">
                <div class="date-range">
                    <i class="fa-solid fa-user-shield" style="color: #0284c7;"></i>
                    <span>Authorized Physician Account &bull; Clinical Pediatric Station</span>
                </div>
            </div>

            <div class="topbar-right">
                <div class="topbar-control-pill">
                    <a href="messages.php" class="topbar-action-btn" title="Consult Messages">
                        <i class="fa-regular fa-bell"></i>
                        <?php if ($unread_msg_count > 0): ?>
                            <span class="notif-ping"></span>
                        <?php endif; ?>
                    </a>
                    <a href="settings.php" class="topbar-action-btn active" title="Account Settings">
                        <i class="fa-solid fa-gear"></i>
                    </a>
                    <div class="topbar-divider"></div>
                    <a href="settings.php" class="topbar-profile" title="Doctor Account">
                        <div class="topbar-avatar-wrap">
                            <img src="<?= htmlspecialchars($profile_pic) ?>" alt="<?= htmlspecialchars($doctor_name) ?>">
                            <span class="avatar-status-dot"></span>
                        </div>
                        <div class="topbar-profile-info">
                            <span class="topbar-profile-name"><?= htmlspecialchars($doctor_name) ?></span>
                        </div>
                    </a>
                </div>
            </div>
        </header>

        <?php if (!empty($error_message)): ?>
            <div style="background: #fef2f2; border: 1.5px solid #fecdd3; color: #991b1b; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error_message) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div style="background: #e6f8f3; border: 1.5px solid #a7f3d0; color: #05cd99; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($success_message) ?></span>
            </div>
        <?php endif; ?>

        <div class="settings-grid">

            <!-- Left: Profile Credentials & Avatar -->
            <div>
                <!-- Profile Picture Card -->
                <div class="dr-card">
                    <div class="dr-card-header">
                        <div class="dr-card-title">
                            <i class="fa-solid fa-camera" style="color: #0284c7;"></i>
                            <span>Physician Avatar Photo</span>
                        </div>
                    </div>
                    <div class="dr-card-body">
                        <form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="upload_picture">
                            <div class="avatar-upload-box">
                                <img src="<?= htmlspecialchars($profile_pic) ?>" alt="Doctor Avatar" class="avatar-preview">
                                <div>
                                    <div style="font-weight: 700; color: #0f172a; font-size: 14px; margin-bottom: 4px;">Update Profile Photo</div>
                                    <div style="font-size: 12px; color: #64748b; margin-bottom: 10px;">JPG, PNG, or WEBP up to 2MB</div>
                                    <input type="file" name="profile_picture" accept="image/*" required style="font-size: 12px;">
                                </div>
                            </div>
                            <button type="submit" class="dr-btn dr-btn-outline dr-btn-sm" style="border-radius: 8px;">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Upload New Picture
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Profile Information Card -->
                <div class="dr-card">
                    <div class="dr-card-header">
                        <div class="dr-card-title">
                            <i class="fa-solid fa-user-doctor" style="color: #05cd99;"></i>
                            <span>Clinical Identity Information</span>
                        </div>
                    </div>
                    <div class="dr-card-body">
                        <form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="update_profile">

                            <div class="form-control-wrap">
                                <label class="form-label">Doctor Full Name</label>
                                <i class="fa-solid fa-user input-icon"></i>
                                <input type="text" name="name" class="form-input" value="<?= htmlspecialchars($doctor_name) ?>" required>
                            </div>

                            <div class="form-control-wrap">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <label class="form-label" style="margin: 0;">Phone Number (Fixed User ID)</label>
                                    <span style="font-size: 10.5px; color: #ef4444; font-weight: 700;"><i class="fa-solid fa-lock"></i> Clinic Locked</span>
                                </div>
                                <i class="fa-solid fa-phone input-icon"></i>
                                <input type="tel" class="form-input readonly" value="<?= htmlspecialchars($user_profile['phone']) ?>" readonly disabled>
                            </div>

                            <div class="form-control-wrap">
                                <label class="form-label">Clinical Email Address</label>
                                <i class="fa-solid fa-envelope input-icon"></i>
                                <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($user_profile['email'] ?? '') ?>" placeholder="doctor@adch.gov.zm">
                            </div>

                            <button type="submit" class="dr-btn dr-btn-primary" style="width: 100%; border-radius: 10px; padding: 12px;">
                                <i class="fa-solid fa-floppy-disk"></i> Save Profile Details
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right: Change Password Card -->
            <div class="dr-card">
                <div class="dr-card-header">
                    <div class="dr-card-title">
                        <i class="fa-solid fa-key" style="color: #4318ff;"></i>
                        <span>Security & Password</span>
                    </div>
                </div>

                <div class="dr-card-body">
                    <form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="update_password">

                        <div class="form-control-wrap">
                            <label class="form-label">Current Account Password</label>
                            <i class="fa-solid fa-shield-halved input-icon"></i>
                            <input type="password" name="current_password" class="form-input" placeholder="••••••••" required>
                        </div>

                        <div class="form-control-wrap">
                            <label class="form-label">New Password</label>
                            <i class="fa-solid fa-lock input-icon"></i>
                            <input type="password" name="new_password" class="form-input" placeholder="Min. 6 characters" minlength="6" required>
                        </div>

                        <div class="form-control-wrap">
                            <label class="form-label">Confirm New Password</label>
                            <i class="fa-solid fa-circle-check input-icon"></i>
                            <input type="password" name="confirm_password" class="form-input" placeholder="Re-enter new password" minlength="6" required>
                        </div>

                        <button type="submit" class="dr-btn dr-btn-primary" style="width: 100%; border-radius: 10px; padding: 12px; background: #4318ff; border-color: #4318ff;">
                            <i class="fa-solid fa-arrow-rotate-right"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>

        </div>

    </main>

    <!-- Mobile Navigation Toggle Script -->
    <script>
        const sidebarToggle = document.getElementById('sidebarToggle');
        const doctorSidebar = document.getElementById('doctorSidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');

        if (sidebarToggle && doctorSidebar && sidebarBackdrop) {
            sidebarToggle.addEventListener('click', () => {
                doctorSidebar.classList.toggle('mobile-open');
                sidebarBackdrop.classList.toggle('active');
            });
            sidebarBackdrop.addEventListener('click', () => {
                doctorSidebar.classList.remove('mobile-open');
                sidebarBackdrop.classList.remove('active');
            });
        }
    </script>
</body>
</html>
