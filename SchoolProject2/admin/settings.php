<?php
session_start();
include '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../admin/login.php");
    exit;
}

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['name'];
$success = '';
$error   = '';

// Fetch admin profile
$stmt = $pdo->prepare("SELECT id, name, phone, email, password_hash FROM users WHERE id = ?");
$stmt->execute([$admin_id]);
$admin_profile = $stmt->fetch(PDO::FETCH_ASSOC);
$admin_phone = $admin_profile['phone'] ?? '0970000001';

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_password') {
    csrf_guard();
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass     = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
        $error = "All password fields are required.";
    } elseif (!password_verify($current_pass, $admin_profile['password_hash'])) {
        $error = "The current administrator password entered is incorrect.";
    } elseif (strlen($new_pass) < 6) {
        $error = "New password must be at least 6 characters in length.";
    } elseif ($new_pass !== $confirm_pass) {
        $error = "New password and confirmation password do not match.";
    } else {
        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        if ($stmt->execute([$new_hash, $admin_id])) {
            $success = "Administrator security credentials updated successfully in real time!";
            $admin_profile['password_hash'] = $new_hash;
        } else {
            $error = "Failed to update administrator password.";
        }
    }
}

// Load persisted facility settings (defaults only if the table is empty)
$facility_settings = [];
try {
    foreach ($pdo->query("SELECT setting_key, setting_value FROM app_settings") as $row) {
        $facility_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Throwable $e) { /* app_settings not created yet */ }
$fs = fn(string $k, string $d = '') => htmlspecialchars($facility_settings[$k] ?? $d, ENT_QUOTES, 'UTF-8');

// Handle Facility Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_facility') {
    csrf_guard();
    $hospital_name = trim($_POST['hospital_name'] ?? '');
    $facility_code = trim($_POST['facility_code'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $location      = trim($_POST['location'] ?? '');

    if (empty($hospital_name)) {
        $error = "Hospital facility name cannot be empty.";
    } else {
        $pairs = [
            'facility_name'    => $hospital_name,
            'facility_code'    => $facility_code,
            'facility_email'   => $contact_email,
            'facility_phone'   => $contact_phone,
            'facility_address' => $location,
        ];
        try {
            $pdo->beginTransaction();
            $upsert = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ($pairs as $k => $v) {
                $upsert->execute([$k, $v]);
            }
            $pdo->commit();
            audit_log($pdo, 'settings.facility_updated', 'setting', null, $hospital_name);
            $success = "Hospital facility parameters saved successfully.";
            $facility_settings = array_merge($facility_settings, $pairs);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = "Could not save facility settings: " . $e->getMessage();
        }
    }
}

$low_stock_count = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= low_stock_threshold")->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'admin' OR recipient_role = 'all') AND is_read = 0");
$stmt->execute([$admin_id]);
$unread_msg_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System &amp; Security Settings — VaxCare Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/admin_theme.css">
    <style>
        .locked-field {
            background-color: #f1f5f9 !important;
            color: var(--admin-text-muted) !important;
            cursor: not-allowed;
            border-color: var(--admin-border) !important;
        }
        .form-control-pass {
            width: 100%;
            padding: 12px 14px 12px 42px;
            font-size: 13.5px;
            font-weight: 500;
            border-radius: 10px;
            border: 1.5px solid var(--admin-border);
            background: #ffffff;
            color: var(--admin-navy);
            box-sizing: border-box;
            outline: none;
            transition: all 0.2s;
        }
        .form-control-pass:focus {
            border-color: var(--admin-primary);
            box-shadow: 0 0 0 3px rgba(67, 24, 255, 0.12);
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
            <a href="add_staff.php" class="sidebar-item">
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

        <!-- Topbar -->
        <header class="app-topbar">
            <div class="topbar-left">
                <h1>Hospital Facility &amp; Security Settings</h1>
                <div class="topbar-date-pill">
                    <i class="fa-solid fa-sliders" style="color:var(--admin-secondary);"></i>
                    <span>System Parameters, Facility Metadata &amp; Administrator Security</span>
                </div>
            </div>
            <div class="topbar-right">
                <a href="messages.php" class="topbar-icon-btn" title="System Messages">
                    <i class="fa-regular fa-bell"></i>
                    <?php if($unread_msg_count > 0): ?>
                        <span class="badge-dot"></span>
                    <?php endif; ?>
                </a>
                <div class="topbar-profile">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=1B254B&color=fff&bold=true" alt="Admin Avatar">
                    <div class="topbar-profile-info">
                        <span class="topbar-profile-name"><?= htmlspecialchars($admin_name) ?></span>
                        <span class="topbar-profile-role">Super Administrator</span>
                    </div>
                </div>
            </div>
        </header>

        <?php if($error): ?>
            <div class="admin-alert" style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-circle-exclamation" style="font-size:18px;"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="admin-alert" style="background:#ecfdf5; border:1px solid #a7f3d0; color:#047857; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-circle-check" style="font-size:18px;"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <div class="settings-grid">

            <!-- Column 1: Admin Real-Time Password Update -->
            <div class="mar-card" style="box-shadow:var(--adm-shadow-sm); border:1px solid var(--adm-border);">
                <div class="mar-card-header" style="border-bottom: 1px solid var(--adm-border); padding-bottom: 14px; margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div class="mar-card-title" style="display:flex; align-items:center; gap:8px;">
                            <div style="width:32px; height:32px; border-radius:8px; background:var(--adm-blue-light); color:var(--adm-blue); display:flex; align-items:center; justify-content:center; font-size:14px;">
                                <i class="fa-solid fa-key"></i>
                            </div>
                            <span>Administrator Credentials</span>
                        </div>
                        <div class="mar-card-subtitle" style="margin-top:2px;">Update administrator password credentials in real time</div>
                    </div>
                    <span class="role-badge role-badge-admin" style="font-size:10.5px; padding:3px 10px;">
                        <i class="fa-solid fa-shield-halved"></i> 256-Bit Crypt
                    </span>
                </div>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_password">

                    <!-- Admin Phone / ID (Locked) -->
                    <div class="form-group" style="margin-bottom:18px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label class="form-label" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--adm-text-secondary); margin:0;">
                                Administrator Account ID (Phone)
                            </label>
                            <span style="color:#ef4444; font-size:11px; font-weight:700;"><i class="fa-solid fa-lock"></i> Permanent Lock</span>
                        </div>
                        <div class="input-field-group">
                            <input type="text" class="modern-field locked" value="<?= htmlspecialchars($admin_phone) ?>" readonly disabled title="Admin phone identifier cannot be changed">
                            <i class="fa-solid fa-phone field-icon"></i>
                        </div>
                        <div style="font-size:11px; color:var(--adm-text-muted); margin-top:6px; display:flex; align-items:center; gap:6px;">
                            <i class="fa-solid fa-circle-info" style="color:var(--adm-blue);"></i> Security standard: Administrative phone IDs are strictly immutable.
                        </div>
                    </div>

                    <!-- Current Password -->
                    <div class="form-group" style="margin-bottom:18px;">
                        <label class="form-label" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--adm-text-secondary); margin-bottom:6px; display:block;">
                            Current Administrator Password
                        </label>
                        <div class="input-field-group">
                            <input type="password" name="current_password" id="curPassField" class="modern-field" placeholder="Enter current password..." required>
                            <i class="fa-solid fa-shield-halved field-icon"></i>
                            <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('curPassField', 'curPassIcon')">
                                <i class="fa-regular fa-eye" id="curPassIcon"></i>
                            </button>
                        </div>
                    </div>

                    <!-- New Password -->
                    <div class="form-group" style="margin-bottom:18px;">
                        <label class="form-label" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--adm-text-secondary); margin-bottom:6px; display:block;">
                            New Secure Password
                        </label>
                        <div class="input-field-group">
                            <input type="password" name="new_password" id="newPassField" class="modern-field" placeholder="Min. 6 alphanumeric characters" minlength="6" required>
                            <i class="fa-solid fa-lock field-icon"></i>
                            <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('newPassField', 'newPassIcon')">
                                <i class="fa-regular fa-eye" id="newPassIcon"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Confirm New Password -->
                    <div class="form-group" style="margin-bottom:24px;">
                        <label class="form-label" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--adm-text-secondary); margin-bottom:6px; display:block;">
                            Re-type New Password
                        </label>
                        <div class="input-field-group">
                            <input type="password" name="confirm_password" id="confirmPassField" class="modern-field" placeholder="Confirm new password" minlength="6" required>
                            <i class="fa-solid fa-circle-check field-icon"></i>
                            <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('confirmPassField', 'confirmPassIcon')">
                                <i class="fa-regular fa-eye" id="confirmPassIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; border-top:1px solid var(--adm-border); padding-top:16px;">
                        <button type="submit" class="admin-btn admin-btn-primary" style="padding:11px 22px; font-weight:700; border-radius:11px;">
                            <i class="fa-solid fa-arrow-rotate-right"></i> Update Credentials in Real Time
                        </button>
                    </div>
                </form>
            </div>

            <!-- Column 2: Facility Metadata Settings -->
            <div class="mar-card" style="box-shadow:var(--adm-shadow-sm); border:1px solid var(--adm-border);">
                <div class="mar-card-header" style="border-bottom: 1px solid var(--adm-border); padding-bottom: 14px; margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div class="mar-card-title" style="display:flex; align-items:center; gap:8px;">
                            <div style="width:32px; height:32px; border-radius:8px; background:var(--adm-green-light); color:var(--adm-green); display:flex; align-items:center; justify-content:center; font-size:14px;">
                                <i class="fa-solid fa-hospital"></i>
                            </div>
                            <span>Hospital &amp; Facility Metadata</span>
                        </div>
                        <div class="mar-card-subtitle" style="margin-top:2px;">Healthcare center registration and provincial ward details</div>
                    </div>
                    <span class="role-badge" style="background:var(--adm-green-light); color:var(--adm-green); border:1px solid var(--adm-green-border); font-size:10.5px; padding:3px 10px;">
                        <i class="fa-solid fa-check"></i> MOH Registered
                    </span>
                </div>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_facility">

                    <div class="form-group" style="margin-bottom:18px;">
                        <label class="form-label" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--adm-text-secondary); margin-bottom:6px; display:block;">
                            Healthcare Facility Name
                        </label>
                        <div class="input-field-group">
                            <input type="text" name="hospital_name" class="modern-field" value="<?= $fs('facility_name', 'VaxiCare Health Centre') ?>" required>
                            <i class="fa-solid fa-building-shield field-icon"></i>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:18px;">
                        <label class="form-label" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--adm-text-secondary); margin-bottom:6px; display:block;">
                            Ministry of Health (MOH) Code
                        </label>
                        <div class="input-field-group">
                            <input type="text" name="facility_code" class="modern-field" value="<?= $fs('facility_code', 'VXC-NDL-001') ?>" style="font-family:monospace; font-weight:700; color:var(--adm-blue);">
                            <i class="fa-solid fa-id-card field-icon"></i>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:18px;">
                        <label class="form-label" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--adm-text-secondary); margin-bottom:6px; display:block;">
                            Official Clinic Support Email
                        </label>
                        <div class="input-field-group">
                            <input type="email" name="contact_email" class="modern-field" value="<?= $fs('facility_email', 'admin@vaxicare.local') ?>">
                            <i class="fa-solid fa-envelope field-icon"></i>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:24px;">
                        <label class="form-label" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--adm-text-secondary); margin-bottom:6px; display:block;">
                            Provincial Ward Location
                        </label>
                        <div class="input-field-group">
                            <input type="text" name="location" class="modern-field" value="<?= $fs('facility_address', 'Ndola Central Ward, Copperbelt, Zambia') ?>">
                            <i class="fa-solid fa-location-dot field-icon"></i>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; border-top:1px solid var(--adm-border); padding-top:16px;">
                        <button type="submit" class="admin-btn admin-btn-success" style="padding:11px 22px; font-weight:700; border-radius:11px;">
                            <i class="fa-solid fa-floppy-disk"></i> Save Hospital Parameters
                        </button>
                    </div>
                </form>
            </div>

        </div>

    </main>

    <script>
        function togglePasswordVisibility(fieldId, iconId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(iconId);
            if (!field || !icon) return;
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
    <script src="../js/notifications.js"></script>
</body>
</html>
