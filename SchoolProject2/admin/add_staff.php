<?php
session_start();
include '../config/db.php';
require_once '../config/security.php';
require_once '../api/mail_helper.php';
require_once '../config/app_url.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../admin/login.php");
    exit;
}

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['name'];
$success = '';
$error   = '';
$created_credential = null;

// Handle Provision New Staff Member
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_staff') {
    csrf_guard();
    $name      = trim($_POST['name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $role      = $_POST['role'] ?? '';
    $temp_pass = trim($_POST['temp_password'] ?? '');

    if (empty($name) || empty($phone) || empty($email) || empty($role) || empty($temp_pass)) {
        $error = "All fields (Full Name, Phone, Email, Role, and Password) are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide a valid personal or Gmail address (e.g. name@gmail.com).";
    } elseif (!valid_staff_role($role)) {
        $error = "Please choose a valid role: Doctor, Nurse, Pharmacist or Administrator.";
    } elseif (($pw_problem = password_problem($temp_pass)) !== '') {
        $error = $pw_problem;
    } else {
        // Check if phone or email already registered
        $stmt = $pdo->prepare("SELECT id, phone, email FROM users WHERE phone = ? OR email = ?");
        $stmt->execute([$phone, $email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['phone'] === $phone) {
                $error = "A user account with phone number '$phone' is already registered in the system.";
            } else {
                $error = "A user account with email '$email' is already registered in the system.";
            }
        } else {
            $hash = password_hash($temp_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, phone, email, password_hash, role, is_on_duty, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
            if ($stmt->execute([$name, $phone, $email, $hash, $role])) {
                audit_log($pdo, 'staff.created', 'user', (int)$pdo->lastInsertId(), "{$name} ({$role})");
                $roleName = $role === 'pharmacy' ? 'Pharmacist' : ucfirst($role);

                // Prepare URLs and message
                $login_url = app_login_url();
                $subject = "Welcome to VaxiCare - {$roleName} Login Credentials";
                $year = date('Y');

                $clean_staff_phone = preg_replace('/[^0-9]/', '', $phone);
                if (strpos($clean_staff_phone, '09') === 0 || strpos($clean_staff_phone, '07') === 0) {
                    $clean_staff_phone = '260' . substr($clean_staff_phone, 1);
                }

                $staff_wa_text = "Hello *{$name}*,\n\n"
                               . "Welcome to VaxiCare! An administrator has provisioned your *{$roleName}* account.\n\n"
                               . "Access your staff portal here:\n"
                               . "{$login_url}\n\n"
                               . "*Login ID (Phone):* {$phone}\n"
                               . "*Temporary Password:* {$temp_pass}\n\n"
                               . "_Please change your temporary password after logging in for the first time._";

                $whatsapp_url = "https://api.whatsapp.com/send?phone=" . urlencode($clean_staff_phone) . "&text=" . rawurlencode($staff_wa_text);

                $mailto_body = "Hello {$name},\n\nWelcome to VaxiCare! An administrator has provisioned your {$roleName} account.\n\nPortal Login URL: {$login_url}\nPhone (Login ID): {$phone}\nTemporary Password: {$temp_pass}\n\nPlease change your password upon your first login.";
                $mailto_url = "mailto:" . rawurlencode($email) . "?subject=" . rawurlencode($subject) . "&body=" . rawurlencode($mailto_body);
                $gmail_url = "https://mail.google.com/mail/?view=cm&fs=1&to=" . rawurlencode($email) . "&su=" . rawurlencode($subject) . "&body=" . rawurlencode($mailto_body);

                $message = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
                    <div style='background: linear-gradient(135deg, #1e3a8a, #2563eb); padding: 24px; text-align: center; color: white;'>
                        <h1 style='margin: 0; font-size: 24px; font-weight: bold; letter-spacing: 1px;'>VaxiCare System</h1>
                        <p style='margin: 5px 0 0; opacity: 0.9; font-size: 14px;'>Clinical Staff Portal Access</p>
                    </div>
                    <div style='padding: 32px; background: #ffffff; color: #334155;'>
                        <h2 style='margin-top: 0; color: #0f172a; font-size: 20px;'>Hello {$name},</h2>
                        <p style='line-height: 1.6; font-size: 15px;'>An administrator has provisioned a <strong>{$roleName}</strong> account for you on the VaxiCare platform.</p>
                        <p style='line-height: 1.6; font-size: 15px;'>You can now log in to manage clinical workflows, view schedules, and access your departmental tools.</p>
                        
                        <div style='background: #f8fafc; border-left: 4px solid #2563eb; padding: 16px; margin: 24px 0; border-radius: 4px;'>
                            <p style='margin: 0 0 10px; font-size: 14px;'><strong>Login ID (Phone):</strong> {$phone}</p>
                            <p style='margin: 0; font-size: 14px;'><strong>Temporary Password:</strong> <span style='font-family: monospace; font-weight: bold; color: #dc2626; font-size: 16px;'>{$temp_pass}</span></p>
                        </div>
                        
                        <div style='text-align: center; margin: 32px 0;'>
                            <a href='{$login_url}' style='background: #2563eb; color: white; text-decoration: none; padding: 14px 28px; border-radius: 8px; font-weight: bold; display: inline-block; font-size: 15px;'>Access Staff Portal</a>
                        </div>
                        
                        <p style='line-height: 1.6; font-size: 14px; color: #64748b;'>For security reasons, you will be required to change your temporary password immediately upon your first login.</p>
                    </div>
                    <div style='background: #f1f5f9; padding: 16px; text-align: center; font-size: 12px; color: #94a3b8;'>
                        &copy; {$year} VaxiCare Health System. All rights reserved.
                    </div>
                </div>";
                
                $email_sent = sendVaxiCareEmail($email, $name, $subject, $message);

                $created_credential = [
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'role' => $roleName,
                    'password' => $temp_pass,
                    'created_at' => date('Y-m-d H:i:s'),
                    'email_sent' => $email_sent,
                    'mailto_url' => $mailto_url,
                    'gmail_url' => $gmail_url,
                    'whatsapp_url' => $whatsapp_url
                ];

                if ($email_sent) {
                    $success = "Medical staff member <strong>" . htmlspecialchars($name) . "</strong> has been provisioned successfully! An email with login credentials and temporary password was sent directly to <strong>" . htmlspecialchars($email) . "</strong>.";
                } else {
                    $success = "Medical staff member <strong>" . htmlspecialchars($name) . "</strong> created successfully! <em>Note: SMTP automated delivery to " . htmlspecialchars($email) . " could not be completed. You can click 'Open in Gmail' or 'Email App' below to dispatch immediately.</em>";
                }
            } else {
                $error = "Database error: Failed to provision staff account. Please try again.";
            }
        }
    }
}

// Fetch Recently Provisioned Staff
$recent_staff = $pdo->query("
    SELECT id, name, phone, email, role, is_on_duty, created_at 
    FROM users 
    WHERE role IN ('doctor', 'nurse', 'pharmacy') 
    ORDER BY created_at DESC 
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$low_stock_count = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= low_stock_threshold")->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'admin') AND is_read = 0");
$stmt->execute([$admin_id]);
$unread_msg_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onboard Medical Staff — VaxiCare Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/admin_theme.css">
    <style>
        .onboard-container {
            display: grid;
            grid-template-columns: 1.25fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 1024px) {
            .onboard-container {
                grid-template-columns: 1fr;
            }
        }
        .role-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .role-option {
            border: 1.5px solid #cbd5e1;
            border-radius: 14px;
            padding: 16px 12px;
            text-align: center;
            cursor: pointer;
            transition: var(--adm-transition);
            background: #fff;
            position: relative;
        }
        .role-option:hover {
            border-color: var(--adm-blue);
            background: #f8faff;
            transform: translateY(-2px);
        }
        .role-option input[type="radio"] {
            display: none;
        }
        .role-option.selected {
            border-color: var(--adm-blue);
            background: var(--adm-blue-light);
            box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.15);
        }
        .role-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 18px;
            transition: var(--adm-transition);
        }
        .role-option.selected .role-icon.doc { background: #dbeafe; color: #1d4ed8; }
        .role-option.selected .role-icon.nurse { background: #dcfce7; color: #15803d; }
        .role-option.selected .role-icon.pharm { background: #fef3c7; color: #b45309; }
        
        .role-icon.doc { background: #f1f5f9; color: #3b82f6; }
        .role-icon.nurse { background: #f1f5f9; color: #10b981; }
        .role-icon.pharm { background: #f1f5f9; color: #f59e0b; }

        .role-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--adm-text-primary);
        }

        .input-group-custom {
            margin-bottom: 18px;
        }
        .input-group-custom label {
            display: block;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            margin-bottom: 6px;
        }
        .input-wrapper-custom {
            position: relative;
        }
        .input-wrapper-custom i.icon-prefix {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 14px;
        }
        .form-control-custom {
            width: 100%;
            padding: 12px 14px 12px 42px;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            font-size: 13.5px;
            font-family: inherit;
            color: var(--adm-text-primary);
            background: #fff;
            outline: none;
            transition: var(--adm-transition);
            box-sizing: border-box;
        }
        .form-control-custom:focus {
            border-color: var(--adm-blue);
            box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.12);
        }

        .cred-card-preview {
            background: linear-gradient(135deg, #111c44 0%, #1b254b 100%);
            border-radius: 18px;
            padding: 24px;
            color: #fff;
            box-shadow: 0 12px 30px rgba(17, 28, 68, 0.25);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.12);
            margin-bottom: 20px;
        }
        .cred-card-preview::before {
            content: '';
            position: absolute;
            top: -40px;
            right: -40px;
            width: 140px;
            height: 140px;
            background: radial-gradient(circle, rgba(67, 24, 255, 0.4), transparent 70%);
            border-radius: 50%;
        }

        .pass-gen-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: #f4f2ff;
            border: 1px solid var(--adm-blue-border);
            color: var(--adm-blue);
            padding: 6px 12px;
            border-radius: 7px;
            font-size: 11.5px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--adm-transition);
        }
        .pass-gen-btn:hover {
            background: #e8e4ff;
        }
    </style>
</head>
<body class="app-body">

    <!-- Mobile Backdrop Overlay -->
    <div class="mobile-sidebar-backdrop" id="mobileSidebarBackdrop" onclick="toggleSidebar(false)"></div>

    <!-- ===== SIDEBAR (ELEVATED VAXICARE ADMIN) ===== -->
    <aside class="sidebar" id="adminSidebar">
        <div class="sidebar-brand-box">
            <a href="dashboard.php" class="sidebar-brand-link">
                <div class="sidebar-brand-icon"><i class="fa-solid fa-shield-heart"></i></div>
                <div class="sidebar-brand-info">
                    <span class="sidebar-brand-title">Vax<span>Care</span></span>
                    <span class="sidebar-brand-sub">Admin Console</span>
                </div>
            </a>
        </div>
        
        <nav class="sidebar-menu">
            <div class="sidebar-section-title">Overview</div>
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-border-all"></i> Dashboard
            </a>

            <div class="sidebar-section-title">Administration</div>
            <a href="user_management.php" class="sidebar-item">
                <i class="fa-solid fa-users-gear"></i> User Management
            </a>
            <a href="staff.php" class="sidebar-item">
                <i class="fa-solid fa-users"></i> Staff Directory
            </a>
            <a href="add_staff.php" class="sidebar-item active">
                <i class="fa-solid fa-user-plus"></i> Onboard Staff
            </a>

            <div class="sidebar-section-title">Clinical &amp; Stock</div>
            <a href="patients.php" class="sidebar-item">
                <i class="fa-solid fa-child-reaching"></i> Master Patients
            </a>
            <a href="inventory.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-stacked"></i> Vaccine Inventory
                <?php if($low_stock_count > 0): ?>
                    <span class="badge-red"><?= $low_stock_count ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Governance</div>
            <a href="audit_logs.php" class="sidebar-item">
                <i class="fa-solid fa-clipboard-list"></i> Audit Logs
            </a>
            <a href="reports.php" class="sidebar-item">
                <i class="fa-solid fa-chart-pie"></i> Reports &amp; Analytics
            </a>
            <a href="messages.php" class="sidebar-item">
                <i class="fa-regular fa-envelope"></i> Broadcasts
                <?php if($unread_msg_count > 0): ?>
                    <span class="badge-red"><?= $unread_msg_count ?></span>
                <?php endif; ?>
            </a>
            <a href="settings.php" class="sidebar-item">
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

        <!-- Topbar -->
        <header class="app-topbar">
            <div style="display:flex; align-items:center;">
                <button type="button" class="mobile-nav-toggle" onclick="toggleSidebar(true)" aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-left">
                    <div class="topbar-breadcrumbs">
                        <span>Administration</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span style="color:var(--adm-text-primary);">Onboard Staff</span>
                    </div>
                    <h1>Onboard Clinical &amp; Pharmacy Personnel</h1>
                </div>
            </div>

            <div class="topbar-right">
                <a href="staff.php" class="ph-btn ph-btn-outline ph-btn-sm" style="text-decoration:none;">
                    <i class="fa-solid fa-users"></i> Staff Directory
                </a>

                <div class="topbar-divider"></div>

                <div class="topbar-profile">
                    <div class="topbar-avatar-wrap">
                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=4318ff&color=fff&size=72" alt="Admin">
                        <span class="avatar-status-dot"></span>
                    </div>
                    <div class="topbar-profile-info">
                        <span class="topbar-profile-name"><?= htmlspecialchars(explode(' ', $admin_name)[0] ?? 'Admin') ?></span>
                        <span class="topbar-profile-role">Administrator</span>
                    </div>
                </div>
            </div>
        </header>

        <?php if($error): ?>
            <div style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-circle-exclamation" style="color:#dc2626; font-size:16px;"></i>
                <span><?= $error ?></span>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div style="background:#ecfdf5; border:1px solid #6ee7b7; color:#047857; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-circle-check" style="color:#05cd99; font-size:16px;"></i>
                <span><?= $success ?></span>
            </div>
        <?php endif; ?>

        <!-- Temporary Credential Modal / Card on Successful Provisioning -->
        <?php if($created_credential): ?>
            <div style="background:#eff6ff; border:1.5px solid #93c5fd; border-radius:16px; padding:22px; margin-bottom:24px; box-shadow:0 4px 14px rgba(37,99,235,0.08);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:12px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:38px; height:38px; background:var(--adm-blue); color:#fff; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px;">
                            <i class="fa-solid fa-paper-plane"></i>
                        </div>
                        <div>
                            <h4 style="margin:0; font-size:16px; font-weight:800; color:#1e3a8a;">New Staff Credentials Generated</h4>
                            <p style="margin:2px 0 0; font-size:12.5px; font-weight:600; color:<?= !empty($created_credential['email_sent']) ? '#059669' : '#d97706' ?>;">
                                <?php if (!empty($created_credential['email_sent'])): ?>
                                    <i class="fa-solid fa-circle-check"></i> Dispatched to <?= htmlspecialchars($created_credential['email']) ?> via automated SMTP mailer.
                                <?php else: ?>
                                    <i class="fa-solid fa-triangle-exclamation"></i> SMTP dispatch pending — Send directly to their Gmail/Email app below.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <?php if (!empty($created_credential['gmail_url'])): ?>
                            <a href="<?= htmlspecialchars($created_credential['gmail_url']) ?>" target="_blank" class="ph-btn" style="background:#ea4335; color:#fff; font-size:12px; padding:8px 14px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-weight:700;">
                                <i class="fa-brands fa-google"></i> Open Gmail
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($created_credential['whatsapp_url'])): ?>
                            <a href="<?= htmlspecialchars($created_credential['whatsapp_url']) ?>" target="_blank" class="ph-btn" style="background:#25D366; color:#fff; font-size:12px; padding:8px 14px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-weight:700;">
                                <i class="fa-brands fa-whatsapp"></i> WhatsApp
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($created_credential['mailto_url'])): ?>
                            <a href="<?= htmlspecialchars($created_credential['mailto_url']) ?>" class="ph-btn" style="background:#2563eb; color:#fff; font-size:12px; padding:8px 14px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; font-weight:700;">
                                <i class="fa-solid fa-envelope"></i> Email App
                            </a>
                        <?php endif; ?>
                        <button class="ph-btn ph-btn-outline ph-btn-sm" onclick="navigator.clipboard.writeText('VaxiCare Credentials\nName: <?= addslashes($created_credential['name']) ?>\nRole: <?= addslashes($created_credential['role']) ?>\nPhone: <?= addslashes($created_credential['phone']) ?>\nEmail: <?= addslashes($created_credential['email']) ?>\nTemp Password: <?= addslashes($created_credential['password']) ?>\nLogin Portal: <?= $login_url ?>'); alert('Copied full staff credentials to clipboard!');">
                            <i class="fa-solid fa-copy"></i> Copy
                        </button>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; background:#fff; padding:14px 18px; border-radius:10px; border:1px solid #bfdbfe; font-size:13px;">
                    <div><span style="color:#64748b; font-size:11px; text-transform:uppercase; font-weight:700;">Full Name</span><br><strong><?= htmlspecialchars($created_credential['name']) ?></strong></div>
                    <div><span style="color:#64748b; font-size:11px; text-transform:uppercase; font-weight:700;">Phone / ID</span><br><span style="font-family:monospace; font-weight:700; color:#1e40af;"><?= htmlspecialchars($created_credential['phone']) ?></span></div>
                    <div><span style="color:#64748b; font-size:11px; text-transform:uppercase; font-weight:700;">Personal / Gmail</span><br><span style="font-weight:600; color:#0f172a;"><?= htmlspecialchars($created_credential['email']) ?></span></div>
                    <div><span style="color:#64748b; font-size:11px; text-transform:uppercase; font-weight:700;">Temp Password</span><br><span style="font-family:monospace; font-weight:800; color:#dc2626; background:#fee2e2; padding:2px 6px; border-radius:4px;"><?= htmlspecialchars($created_credential['password']) ?></span></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="onboard-container">

            <!-- Form Card (Left) -->
            <div class="mar-card" style="box-shadow: 0 4px 20px rgba(112, 144, 176, 0.08); border: 1px solid #e0e5f2; border-radius: 18px; padding: 24px;">
                <div class="mar-card-header" style="border-bottom: 1px solid #f1f5f9; padding-bottom: 16px; margin-bottom: 22px;">
                    <div>
                        <h3 class="mar-card-title"><i class="fa-solid fa-id-badge" style="color:var(--adm-blue);"></i> Staff Onboarding Form</h3>
                        <div class="mar-card-subtitle">Fill in verified medical personnel details. The system generates initial authorization credentials.</div>
                    </div>
                </div>

                <form method="POST" id="onboardForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_staff">

                    <!-- Role Selection Cards -->
                    <label style="display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#475569; margin-bottom:8px;">
                        Select Clinical Department / Medical Role
                    </label>
                    <div class="role-selector">
                        <label class="role-option selected" onclick="selectRole(this, 'doctor')">
                            <input type="radio" name="role" value="doctor" checked>
                            <div class="role-icon doc"><i class="fa-solid fa-user-doctor"></i></div>
                            <div class="role-title">Medical Doctor</div>
                        </label>
                        <label class="role-option" onclick="selectRole(this, 'nurse')">
                            <input type="radio" name="role" value="nurse">
                            <div class="role-icon nurse"><i class="fa-solid fa-user-nurse"></i></div>
                            <div class="role-title">Clinical Nurse</div>
                        </label>
                        <label class="role-option" onclick="selectRole(this, 'pharmacy')">
                            <input type="radio" name="role" value="pharmacy">
                            <div class="role-icon pharm"><i class="fa-solid fa-prescription-bottle-medical"></i></div>
                            <div class="role-title">Pharmacist</div>
                        </label>
                    </div>

                    <!-- Full Name -->
                    <div class="input-group-custom">
                        <label for="name">Staff Full Name</label>
                        <div class="input-wrapper-custom">
                            <i class="fa-solid fa-user icon-prefix"></i>
                            <input type="text" id="name" name="name" class="form-control-custom" placeholder="e.g. Dr. Mutale Kangwa" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" oninput="updateLivePreview()">
                        </div>
                    </div>

                    <!-- Grid for Phone & Email -->
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:14px;">
                        <!-- Phone Number -->
                        <div class="input-group-custom">
                            <label for="phone">Phone Number (Login ID)</label>
                            <div class="input-wrapper-custom">
                                <i class="fa-solid fa-phone icon-prefix"></i>
                                <input type="tel" id="phone" name="phone" class="form-control-custom" placeholder="e.g. 0977123456" required value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" oninput="updateLivePreview()">
                            </div>
                        </div>

                        <!-- Email Address -->
                        <div class="input-group-custom">
                            <label for="email">Personal / Gmail Address</label>
                            <div class="input-wrapper-custom">
                                <i class="fa-solid fa-envelope icon-prefix"></i>
                                <input type="email" id="email" name="email" class="form-control-custom" placeholder="e.g. staff.member@gmail.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" oninput="updateLivePreview()">
                            </div>
                            <div style="font-size:11.5px; color:#64748b; margin-top:5px;">
                                <i class="fa-solid fa-circle-info" style="color:var(--adm-blue);"></i> Used to send credentials and schedule notices.
                            </div>
                        </div>
                    </div>

                    <!-- Temporary Password with Generator -->
                    <div class="input-group-custom" style="margin-bottom:24px;">
                        <label for="temp_password">Initial Temporary Password</label>
                        <div class="input-wrapper-custom">
                            <i class="fa-solid fa-key icon-prefix"></i>
                            <input type="text" id="temp_password" name="temp_password" class="form-control-custom" placeholder="Min. 6 characters" required style="padding-right:130px;" value="<?= htmlspecialchars($_POST['temp_password'] ?? '') ?>" oninput="updateLivePreview()">
                            <button type="button" class="pass-gen-btn" onclick="generateSecurePassword()">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Auto Generate
                            </button>
                        </div>
                        <div style="font-size:11px; color:#64748b; margin-top:5px;">
                            <i class="fa-solid fa-shield-halved" style="color:var(--adm-green);"></i> Personnel can update their password upon first logging in.
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; border-top:1px solid #f1f5f9; padding-top:18px;">
                        <button type="submit" class="ph-btn ph-btn-blue" style="padding:12px 26px;">
                            <i class="fa-solid fa-user-plus"></i> Onboard &amp; Authorize Personnel
                        </button>
                    </div>
                </form>
            </div>

            <!-- Right Column: Live Badge Preview & Security Notice -->
            <div>
                <!-- Live Staff Badge Preview Card -->
                <div class="cred-card-preview">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <i class="fa-solid fa-shield-virus" style="color:#38bdf8; font-size:20px;"></i>
                            <span style="font-size:13.5px; font-weight:800; letter-spacing:0.5px;">VAXICARE MEDICAL ID</span>
                        </div>
                        <span id="previewRoleBadge" style="background:#2563eb; color:#fff; font-size:11px; font-weight:700; padding:4px 10px; border-radius:20px; text-transform:uppercase;">
                            Doctor
                        </span>
                    </div>

                    <div style="display:flex; align-items:center; gap:16px; margin-bottom:18px;">
                        <div style="width:52px; height:52px; background:rgba(255,255,255,0.1); border:2px solid #38bdf8; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:22px; color:#38bdf8;">
                            <i class="fa-solid fa-user-doctor" id="previewAvatarIcon"></i>
                        </div>
                        <div>
                            <h3 id="previewName" style="margin:0; font-size:16px; font-weight:800; color:#f8fafc;">Dr. Staff Member</h3>
                            <p id="previewEmail" style="margin:2px 0 0; font-size:12px; color:#94a3b8;">staff.member@gmail.com</p>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; background:rgba(255,255,255,0.06); padding:12px 14px; border-radius:10px; font-size:12px; border:1px solid rgba(255,255,255,0.08);">
                        <div>
                            <span style="color:#94a3b8; font-size:10px; text-transform:uppercase; font-weight:700;">Login Phone / ID</span><br>
                            <span id="previewPhone" style="font-family:monospace; font-weight:700; color:#38bdf8;">097X-XXX-XXX</span>
                        </div>
                        <div>
                            <span style="color:#94a3b8; font-size:10px; text-transform:uppercase; font-weight:700;">Initial Password</span><br>
                            <span id="previewPassword" style="font-family:monospace; font-weight:700; color:#34d399;">••••••••</span>
                        </div>
                    </div>
                </div>

                <!-- Onboarding Policy Notice -->
                <div class="mar-card" style="box-shadow: 0 4px 20px rgba(112, 144, 176, 0.08); border: 1px solid #e0e5f2; border-radius: 18px; padding:20px;">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                        <i class="fa-solid fa-circle-info" style="color:var(--adm-blue); font-size:16px;"></i>
                        <h4 style="margin:0; font-size:14px; font-weight:800; color:var(--adm-text-primary);">Hospital Access &amp; Compliance Policy</h4>
                    </div>
                    <ul style="padding-left:18px; font-size:12.5px; color:#64748b; line-height:1.6; margin:0;">
                        <li style="margin-bottom:6px;">Staff members receive immediate access to their designated clinical portal upon account provisioning.</li>
                        <li style="margin-bottom:6px;">Temporary passwords are encrypted securely using standard bcrypt hashing algorithms.</li>
                        <li style="margin-bottom:6px;">Staff can modify their password anytime inside their personal security profile.</li>
                    </ul>
                </div>
            </div>

        </div>

        <!-- Recently Provisioned Staff Roster -->
        <div class="mar-card" style="box-shadow: 0 4px 20px rgba(112, 144, 176, 0.08); border: 1px solid #e0e5f2; border-radius: 18px; padding: 24px;">
            <div class="mar-card-header" style="border-bottom: 1px solid #f1f5f9; padding-bottom: 16px; margin-bottom: 16px;">
                <h3 class="mar-card-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--adm-blue);"></i> Recently Onboarded Staff Members</h3>
                <a href="staff.php" class="ph-btn ph-btn-outline ph-btn-sm" style="text-decoration:none;">View Full Directory &rarr;</a>
            </div>

            <div class="table-responsive">
                <table class="mar-table">
                    <thead>
                        <tr>
                            <th>Medical Staff</th>
                            <th>Role / Department</th>
                            <th>Phone (Login ID)</th>
                            <th>Email Address</th>
                            <th>Onboarded Date</th>
                            <th style="text-align:right;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($recent_staff)): ?>
                            <tr><td colspan="6" style="text-align:center; padding:24px; color:#64748b;">No staff members provisioned recently.</td></tr>
                        <?php else: ?>
                            <?php foreach($recent_staff as $st): ?>
                                <tr>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:12px; font-weight:700;">
                                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($st['name']) ?>&background=111c44&color=fff&size=64" style="width:34px; height:34px; border-radius:50%; border:1px solid #e2e8f0;">
                                            <span><?= htmlspecialchars($st['name']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($st['role'] === 'doctor'): ?>
                                            <span class="role-pill role-doctor"><i class="fa-solid fa-user-doctor"></i> Doctor</span>
                                        <?php elseif($st['role'] === 'nurse'): ?>
                                            <span class="role-pill role-nurse"><i class="fa-solid fa-user-nurse"></i> Nurse</span>
                                        <?php else: ?>
                                            <span class="role-pill role-pharmacy"><i class="fa-solid fa-pills"></i> Pharmacist</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-family:monospace; font-weight:700; color:var(--adm-blue); background:#f4f2ff; padding:3px 8px; border-radius:6px; font-size:12.5px;">
                                            <?= htmlspecialchars($st['phone']) ?>
                                        </span>
                                    </td>
                                    <td style="color:#64748b; font-size:12.5px;"><?= htmlspecialchars($st['email'] ?? '—') ?></td>
                                    <td style="font-size:12.5px; color:#64748b;"><?= date('d M Y, H:i', strtotime($st['created_at'])) ?></td>
                                    <td style="text-align:right;">
                                        <span class="duty-badge on"><span class="duty-pulse"></span> Active</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <script>
        function selectRole(element, role) {
            document.querySelectorAll('.role-option').forEach(el => el.classList.remove('selected'));
            element.classList.add('selected');
            element.querySelector('input[type="radio"]').checked = true;

            const badge = document.getElementById('previewRoleBadge');
            const icon = document.getElementById('previewAvatarIcon');
            badge.innerText = role.toUpperCase();

            if (role === 'doctor') {
                badge.style.background = '#2563eb';
                icon.className = 'fa-solid fa-user-doctor';
            } else if (role === 'nurse') {
                badge.style.background = '#10b981';
                icon.className = 'fa-solid fa-user-nurse';
            } else if (role === 'pharmacy') {
                badge.style.background = '#f59e0b';
                icon.className = 'fa-solid fa-prescription-bottle-medical';
            }
        }

        function generateSecurePassword() {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$';
            let password = '';
            for (let i = 0; i < 8; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('temp_password').value = password;
            updateLivePreview();
        }

        function updateLivePreview() {
            const name = document.getElementById('name').value.trim() || 'Dr. Staff Member';
            const email = document.getElementById('email').value.trim() || 'staff.member@gmail.com';
            const phone = document.getElementById('phone').value.trim() || '097X-XXX-XXX';
            const pass = document.getElementById('temp_password').value.trim() || '••••••••';

            document.getElementById('previewName').innerText = name;
            document.getElementById('previewEmail').innerText = email;
            document.getElementById('previewPhone').innerText = phone;
            document.getElementById('previewPassword').innerText = pass;
        }

        function toggleSidebar(open) {
            const sidebar = document.getElementById('adminSidebar');
            const backdrop = document.getElementById('mobileSidebarBackdrop');
            if (open) {
                sidebar.classList.add('open');
                backdrop.classList.add('show');
            } else {
                sidebar.classList.remove('open');
                backdrop.classList.remove('show');
            }
        }

        // Auto-generate password on load if empty
        if (!document.getElementById('temp_password').value) {
            generateSecurePassword();
        }
    </script>
    <script src="../js/notifications.js"></script>
</body>
</html>

