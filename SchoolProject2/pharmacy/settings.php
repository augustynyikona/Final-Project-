<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pharmacy') {
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

$pharmacist_name = $user_profile['name'];
$profile_pic = $user_profile['profile_pic'] ? '../uploads/' . $user_profile['profile_pic'] : "https://ui-avatars.com/api/?name=" . urlencode($pharmacist_name) . "&background=05cd99&color=fff";

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
            $pharmacist_name = $new_name;
            $user_profile['name'] = $new_name;
            $user_profile['email'] = $new_email;
            $success_message = "Pharmacist profile details updated successfully!";
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
        } elseif (($pwd_problem = password_problem($new_pwd)) !== '') {
            // Shared policy (config/security.php): 8+ chars, at least one letter and one number.
            $error_message = $pwd_problem;
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

// Low stock count
$low_stock_count = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= low_stock_threshold")->fetchColumn();

// Pending requisitions count
$pending_requisitions = $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'Pending'")->fetchColumn();

// Pending purchase orders
$pending_orders = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending'")->fetchColumn();

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'pharmacy') AND is_read = 0");
$stmt->execute([$user_id]);
$unread_msg_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Settings &amp; Security — VaxCare</title>
    <meta name="description" content="Manage pharmacist credentials, security credentials, and preferences in VaxCare.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/pharmacy_theme.css">
    <style>
        .topbar-breadcrumbs {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 4px;
        }
        .topbar-breadcrumbs a {
            color: #64748b;
            text-decoration: none;
        }
        .topbar-breadcrumbs a:hover {
            color: #05cd99;
        }
        .topbar-icon-btn {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 11px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #475569;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .topbar-icon-btn:hover {
            color: #05cd99;
            border-color: #05cd99;
            background: #f0fdf4;
            transform: translateY(-1px);
        }
        .notif-ping {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ef4444;
            border: 2px solid #ffffff;
        }
        .topbar-profile-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #ffffff;
            padding: 5px 14px 5px 6px;
            border-radius: 30px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
        }
        .topbar-profile-box img {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
        }
        .topbar-profile-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        .topbar-profile-meta .name {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }
        .topbar-profile-meta .role {
            font-size: 10.5px;
            font-weight: 600;
            color: #05cd99;
        }

        /* 2-Column Settings Layout */
        .settings-grid-layout {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 1080px) {
            .settings-grid-layout {
                grid-template-columns: 1fr;
            }
        }

        .settings-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
            overflow: hidden;
            padding: 24px;
            margin-bottom: 24px;
        }

        .form-control-styled {
            width: 100%;
            padding: 11px 14px 11px 42px;
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
            border-color: #05cd99;
            box-shadow: 0 0 0 3px rgba(5, 205, 153, 0.15);
        }
        .locked-field {
            background-color: #f1f5f9 !important;
            color: #64748b !important;
            cursor: not-allowed;
        }

        /* Toggle switch */
        .setting-toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .setting-toggle-row:last-child {
            border-bottom: none;
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background-color: #cbd5e1;
            transition: .3s;
            border-radius: 24px;
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .toggle-slider {
            background-color: #05cd99;
        }
        input:checked + .toggle-slider:before {
            transform: translateX(20px);
        }

        /* Responsive Mobile Drawer */
        .mobile-sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 95;
        }
        .mobile-sidebar-backdrop.show {
            display: block;
        }
        .mobile-nav-toggle {
            display: none;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: #0f172a;
            align-items: center;
            justify-content: center;
            margin-right: 14px;
            cursor: pointer;
        }
        @media (max-width: 1024px) {
            .mobile-nav-toggle {
                display: flex;
            }
            .sidebar {
                position: fixed;
                left: -280px;
                top: 0;
                bottom: 0;
                transition: left 0.3s ease;
                z-index: 100;
            }
            .sidebar.open {
                left: 0;
            }
        }
    </style>
</head>
<body class="app-body">

    <!-- Mobile Backdrop Overlay -->
    <div class="mobile-sidebar-backdrop" id="mobileSidebarBackdrop" onclick="toggleSidebar(false)"></div>

    <!-- ===== SIDEBAR (ELEVATED PHARMACY CONSOLE) ===== -->
    <aside class="sidebar" id="pharmacySidebar">
        <div class="sidebar-brand-box">
            <a href="dashboard.php" class="sidebar-brand-link">
                <div class="sidebar-brand-icon"><i class="fa-solid fa-prescription-bottle-medical"></i></div>
                <div class="sidebar-brand-info">
                    <span class="sidebar-brand-title">Vax<span>Care</span></span>
                    <span class="sidebar-brand-sub">Pharmacy Console</span>
                </div>
            </a>
        </div>
        
        <nav class="sidebar-menu">
            <div class="sidebar-section-title">Overview</div>
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-border-all"></i> Dashboard
            </a>

            <div class="sidebar-section-title">Cold Chain &amp; Stock</div>
            <a href="inventory.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-stacked"></i> Vaccine Stock
                <?php if($low_stock_count > 0): ?>
                    <span class="badge-red"><?= $low_stock_count ?></span>
                <?php endif; ?>
            </a>
            <a href="add_batch.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-packing"></i> Intake Batch
            </a>
            <a href="requisitions.php" class="sidebar-item">
                <i class="fa-solid fa-clipboard-check"></i> Requisitions
                <?php if($pending_requisitions > 0): ?>
                    <span class="badge-red"><?= $pending_requisitions ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Procurement &amp; Billing</div>
            <a href="orders.php" class="sidebar-item">
                <i class="fa-solid fa-truck-ramp-box"></i> Purchase Orders
                <?php if($pending_orders > 0): ?>
                    <span class="badge-red"><?= $pending_orders ?></span>
                <?php endif; ?>
            </a>
            <a href="billing.php" class="sidebar-item">
                <i class="fa-solid fa-file-invoice-dollar"></i> Invoices &amp; POS
            </a>

            <div class="sidebar-section-title">Governance &amp; Comms</div>
            <a href="reports.php" class="sidebar-item">
                <i class="fa-solid fa-chart-pie"></i> Stock Analytics
            </a>
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
        
        <div class="sidebar-logout" style="padding:14px; margin-top:auto; border-top:1px solid rgba(255,255,255,0.08);">
            <a href="../auth/logout.php" style="display:flex; align-items:center; gap:12px; padding:11px 16px; color:#8fa0bd; text-decoration:none; font-size:13.5px; font-weight:600; border-radius:11px; transition:all 0.2s;">
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
                    <h1 style="margin:0 0 6px 0;">Pharmacy Settings &amp; Security</h1>
                    <div class="topbar-breadcrumbs" style="margin-bottom:6px;">
                        <span>Governance &amp; Comms</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span style="color:#0f172a; font-weight:700;">Settings</span>
                    </div>
                    <div class="date-range" style="margin-top:0;">
                        <i class="fa-solid fa-snowflake" style="color:#05cd99;"></i> <?= date('d M, Y') ?> &bull; Ndola Central Hospital &bull; Pharmacist Identity &amp; Access
                    </div>
                </div>
            </div>

            <div class="topbar-right">
                <a href="dashboard.php" class="ph-btn ph-btn-outline" style="padding: 6px 12px; font-size: 12px; border-radius: 8px; gap: 5px; height: 34px; text-decoration:none;">
                    <i class="fa-solid fa-border-all"></i> Dashboard
                </a>
                <a href="messages.php" class="topbar-icon-btn" title="Messages">
                    <i class="fa-regular fa-envelope"></i>
                    <?php if($unread_msg_count > 0): ?>
                        <span class="notif-ping"></span>
                    <?php endif; ?>
                </a>
                <div class="topbar-profile-box">
                    <img src="<?= htmlspecialchars($profile_pic) ?>" alt="Profile">
                    <div class="topbar-profile-meta">
                        <span class="name"><?= htmlspecialchars($pharmacist_name) ?></span>
                        <span class="role">Chief Pharmacist</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Feedback Alerts -->
        <?php if($error_message): ?>
            <div style="background:#fef2f2; border:1.5px solid #fecaca; color:#b91c1c; padding:16px 20px; border-radius:14px; margin-bottom:24px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 6px rgba(239,68,68,0.06);">
                <i class="fa-solid fa-triangle-exclamation" style="font-size:18px;"></i>
                <div>
                    <div style="font-weight:700;">Settings Error</div>
                    <div style="font-size:12.5px; font-weight:500; opacity:0.95;"><?= htmlspecialchars($error_message) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if($success_message): ?>
            <div style="background:#ecfdf5; border:1.5px solid #a7f3d0; color:#065f46; padding:16px 20px; border-radius:14px; margin-bottom:24px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 6px rgba(16,185,129,0.06);">
                <i class="fa-solid fa-circle-check" style="font-size:18px; color:#05cd99;"></i>
                <div>
                    <div style="font-weight:700;">Settings Updated</div>
                    <div style="font-size:12.5px; font-weight:500; opacity:0.95;"><?= htmlspecialchars($success_message) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Two Column Settings Workspace -->
        <div class="settings-grid-layout">

            <!-- Left Column: Password Update & Notification Settings -->
            <div>
                <!-- Change Password Card -->
                <div class="settings-card">
                    <div style="border-bottom: 1px solid #f1f5f9; padding-bottom: 16px; margin-bottom: 22px;">
                        <h2 style="font-size:16px; font-weight:800; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px;">
                            <i class="fa-solid fa-key" style="color:#05cd99;"></i> Change Account Password
                        </h2>
                        <div style="font-size:12px; color:#64748b; margin-top:3px;">
                            Update your credentials to maintain strict cold-chain dispensary access compliance
                        </div>
                    </div>

                    <form method="POST" action="settings.php">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="update_password">

                        <div style="margin-bottom:18px;">
                            <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">
                                Current Password <span style="color:#ef4444;">*</span>
                            </label>
                            <div style="position:relative;">
                                <i class="fa-solid fa-shield-halved" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:14px;"></i>
                                <input type="password" name="current_password" class="form-control-styled" placeholder="••••••••" required>
                            </div>
                        </div>

                        <div style="margin-bottom:18px;">
                            <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">
                                New Password <span style="color:#ef4444;">*</span>
                            </label>
                            <div style="position:relative;">
                                <i class="fa-solid fa-lock" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:14px;"></i>
                                <input type="password" name="new_password" class="form-control-styled" placeholder="Minimum 6 characters" minlength="6" required>
                            </div>
                        </div>

                        <div style="margin-bottom:24px;">
                            <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">
                                Confirm New Password <span style="color:#ef4444;">*</span>
                            </label>
                            <div style="position:relative;">
                                <i class="fa-solid fa-circle-check" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:14px;"></i>
                                <input type="password" name="confirm_password" class="form-control-styled" placeholder="Re-type new password" minlength="6" required>
                            </div>
                        </div>

                        <div style="display:flex; justify-content:flex-end; border-top:1px solid #f1f5f9; padding-top:18px;">
                            <button type="submit" class="ph-btn ph-btn-green" style="padding:11px 22px; font-size:13px; font-weight:700;">
                                <i class="fa-solid fa-arrow-rotate-right" style="margin-right:6px;"></i> Update Password
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Cold-Chain Preferences Card -->
                <div class="settings-card">
                    <div style="border-bottom: 1px solid #f1f5f9; padding-bottom: 16px; margin-bottom: 20px;">
                        <h2 style="font-size:16px; font-weight:800; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px;">
                            <i class="fa-solid fa-bell" style="color:#2563eb;"></i> Cold-Chain &amp; Alert Preferences
                        </h2>
                        <div style="font-size:12px; color:#64748b; margin-top:3px;">
                            Configure realtime alert behaviors on stock levels and requisitions
                        </div>
                    </div>

                    <div class="setting-toggle-row">
                        <div>
                            <strong style="font-size:13px; color:#0f172a;">Critical Low Stock Push Alerts</strong>
                            <div style="font-size:12px; color:#64748b;">Notify immediately when vaccine falls below reorder threshold</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="setting-toggle-row">
                        <div>
                            <strong style="font-size:13px; color:#0f172a;">Nurse Requisition Alerts</strong>
                            <div style="font-size:12px; color:#64748b;">Instant badge update upon submission of daily ward requisitions</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="setting-toggle-row">
                        <div>
                            <strong style="font-size:13px; color:#0f172a;">Temperature Logger Tolerance Warnings</strong>
                            <div style="font-size:12px; color:#64748b;">Alert if any cold storage unit breaches 2°C to 8°C boundaries</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Right Column: Profile Information Card -->
            <div>
                <div class="settings-card">
                    <div style="border-bottom: 1px solid #f1f5f9; padding-bottom: 18px; margin-bottom: 20px; display:flex; align-items:center; gap:14px;">
                        <img src="<?= htmlspecialchars($profile_pic) ?>" alt="Avatar" style="width:52px; height:52px; border-radius:50%; border:2px solid #a7f3d0;">
                        <div>
                            <h2 style="font-size:16px; font-weight:800; color:#0f172a; margin:0;">
                                <?= htmlspecialchars($pharmacist_name) ?>
                            </h2>
                            <span style="display:inline-flex; align-items:center; gap:5px; color:#05cd99; font-size:11.5px; font-weight:700; margin-top:2px;">
                                <span style="width:6px; height:6px; border-radius:50%; background:#05cd99; display:inline-block;"></span>
                                Chief Pharmacist &bull; Cold-Chain Station
                            </span>
                        </div>
                    </div>

                    <form method="POST" action="settings.php">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="update_profile">

                        <div style="margin-bottom:18px;">
                            <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">
                                Pharmacist Full Name <span style="color:#ef4444;">*</span>
                            </label>
                            <div style="position:relative;">
                                <i class="fa-solid fa-user" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:14px;"></i>
                                <input type="text" name="name" class="form-control-styled" value="<?= htmlspecialchars($pharmacist_name) ?>" required>
                            </div>
                        </div>

                        <div style="margin-bottom:18px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <label style="font-size:12px; font-weight:700; color:#1e293b; margin:0;">
                                    Phone Number (Login ID)
                                </label>
                                <span style="font-size:10.5px; font-weight:700; color:#b91c1c; background:#fef2f2; padding:1px 6px; border-radius:4px;">
                                    <i class="fa-solid fa-lock"></i> Fixed System ID
                                </span>
                            </div>
                            <div style="position:relative;">
                                <i class="fa-solid fa-phone" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:14px;"></i>
                                <input type="tel" class="form-control-styled locked-field" value="<?= htmlspecialchars($user_profile['phone']) ?>" readonly disabled>
                            </div>
                        </div>

                        <div style="margin-bottom:24px;">
                            <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">
                                Hospital Email Address
                            </label>
                            <div style="position:relative;">
                                <i class="fa-solid fa-envelope" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:14px;"></i>
                                <input type="email" name="email" class="form-control-styled" value="<?= htmlspecialchars($user_profile['email'] ?? '') ?>" placeholder="pharmacist@vaxcare.nch.gov.zm">
                            </div>
                        </div>

                        <button type="submit" class="ph-btn ph-btn-green" style="width:100%; justify-content:center; padding:12px; font-size:13.5px; font-weight:700;">
                            <i class="fa-solid fa-floppy-disk" style="margin-right:6px;"></i> Save Profile Details
                        </button>
                    </form>
                </div>
            </div>

        </div>

    </main>

    <script>
        function toggleSidebar(open) {
            const sidebar = document.getElementById('pharmacySidebar');
            const backdrop = document.getElementById('mobileSidebarBackdrop');
            if (open) {
                sidebar.classList.add('open');
                backdrop.classList.add('show');
            } else {
                sidebar.classList.remove('open');
                backdrop.classList.remove('show');
            }
        }
    </script>
</body>
</html>
