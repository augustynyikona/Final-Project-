<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once '../api/mail_helper.php';
require_once '../config/app_url.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: ../auth/login.php");
    exit;
}

$nurse_name = $_SESSION['name'] ?? 'Bupe';
$error = '';
$success = '';
$whatsapp_url = '';
$guardian_phone_clean = '';
$email_sent = false;

// Fetch nurse profile
$stmt = $pdo->prepare("SELECT name, profile_pic FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user_profile && !empty($user_profile['name'])) {
    $nurse_name = $user_profile['name'];
}

$profile_pic = ($user_profile && !empty($user_profile['profile_pic'])) 
    ? '../uploads/' . htmlspecialchars($user_profile['profile_pic']) 
    : "https://ui-avatars.com/api/?name=" . urlencode($nurse_name) . "&background=2563eb&color=fff&bold=true";

// Fetch unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'nurse') AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_msg_count = (int)$stmt->fetchColumn();

// Fetch pending today for appointment badge
$stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE AND status != 'completed' AND status != 'cancelled'");
$pending_today = (int)$stmt->fetchColumn();

// Fetch available vaccines for optional initial appointment assignment
$stmt = $pdo->query("SELECT id, name FROM vaccines ORDER BY name ASC");
$vaccines = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $guardian_name  = trim($_POST['guardian_name'] ?? '');
    $guardian_phone = trim($_POST['guardian_phone'] ?? '');
    $guardian_email = trim($_POST['guardian_email'] ?? '');
    $child_name     = trim($_POST['child_name'] ?? '');
    $child_dob      = $_POST['child_dob'] ?? '';
    $child_gender   = $_POST['child_gender'] ?? 'Male';
    $initial_vaccine_id = !empty($_POST['initial_vaccine_id']) ? (int)$_POST['initial_vaccine_id'] : null;
    $appointment_date   = $_POST['appointment_date'] ?? '';
    $appointment_time   = $_POST['appointment_time'] ?? '09:00:00';
    $temp_password      = trim($_POST['temp_password'] ?? 'password123');

    if (empty($guardian_name) || empty($guardian_phone) || empty($child_name) || empty($child_dob)) {
        $error = "Please fill in all required guardian and infant details.";
    } else {
        try {
            $pdo->beginTransaction();

            // Format phone number for WhatsApp
            $clean_phone = preg_replace('/[^0-9]/', '', $guardian_phone);
            if (strpos($clean_phone, '09') === 0 || strpos($clean_phone, '07') === 0) {
                $clean_phone = '260' . substr($clean_phone, 1);
            }
            $guardian_phone_clean = $clean_phone;

            // 1. Check or create Guardian account
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
            $stmt->execute([$guardian_phone]);
            $guardian = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($guardian) {
                $guardian_id = $guardian['id'];
                if (!empty($guardian_email)) {
                    $stmt = $pdo->prepare("UPDATE users SET email = ?, name = ? WHERE id = ?");
                    $stmt->execute([$guardian_email, $guardian_name, $guardian_id]);
                }
            } else {
                $hash = password_hash($temp_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, phone, email, password_hash, role) VALUES (?, ?, ?, ?, 'guardian')");
                $stmt->execute([$guardian_name, $guardian_phone, $guardian_email, $hash]);
                $guardian_id = $pdo->lastInsertId();
            }

            // 2. Insert or find existing Child record (prevents duplicates on resubmission)
            $stmt = $pdo->prepare("SELECT id FROM children WHERE guardian_id = ? AND LOWER(name) = LOWER(?)");
            $stmt->execute([$guardian_id, $child_name]);
            $existing_child = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_child) {
                $child_id = $existing_child['id'];
                $stmt = $pdo->prepare("UPDATE children SET date_of_birth = ?, gender = ? WHERE id = ?");
                $stmt->execute([$child_dob, $child_gender, $child_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO children (guardian_id, name, date_of_birth, gender) VALUES (?, ?, ?, ?)");
                $stmt->execute([$guardian_id, $child_name, $child_dob, $child_gender]);
                $child_id = $pdo->lastInsertId();
            }

            // 3. Optional: Create initial vaccine appointment if selected
            $appt_created = false;
            $appt_id = null;
            $appt_date_problem = appointment_date_problem($appointment_date);
            if ($initial_vaccine_id && !empty($appointment_date) && $appt_date_problem === '') {
                $stmt = $pdo->prepare("INSERT INTO appointments (child_id, vaccine_id, appointment_date, appointment_time, status) VALUES (?, ?, ?, ?, 'scheduled')");
                $stmt->execute([$child_id, $initial_vaccine_id, $appointment_date, $appointment_time]);
                $appt_id = $pdo->lastInsertId();
                $appt_created = true;

                // In-System Notification
                if (function_exists('recordAppointmentNotification')) {
                    recordAppointmentNotification($pdo, $appt_id, 'booked');
                }
            }

            // 4. Generate & Log SMS / WhatsApp invitation link
            $login_url = app_login_url('phone=' . urlencode($guardian_phone));
            
            $wa_text = "Hello *{$guardian_name}*,\n\n"
                     . "Welcome to VaxiCare! Your infant *{$child_name}* has been successfully registered by Nurse {$nurse_name}.\n\n"
                     . "Access your interactive vaccination passport & schedule here:\n"
                     . "{$login_url}\n\n"
                     . "*Login ID (Phone):* {$guardian_phone}\n"
                     . "*Temporary Password:* {$temp_password}\n\n"
                     . "_Please change your temporary password after logging in for the first time._";

            $whatsapp_url = "https://api.whatsapp.com/send?phone=" . urlencode($clean_phone) . "&text=" . rawurlencode($wa_text);

            $mailto_url = "";
            if (!empty($guardian_email)) {
                $subject = "Welcome to VaxiCare - Pediatric Registration for {$child_name}";
                $year = date('Y');
                $message = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
                    <div style='background: linear-gradient(135deg, #111c44, #1b254b); padding: 24px; text-align: center; color: white;'>
                        <h1 style='margin: 0; font-size: 24px; font-weight: bold; letter-spacing: 0.5px;'>VaxiCare.</h1>
                        <p style='margin: 5px 0 0; opacity: 0.85; font-size: 13px;'>Clinical Pediatrics &amp; Immunization Ward</p>
                    </div>
                    <div style='padding: 32px; background: #ffffff; color: #334155;'>
                        <h2 style='margin-top: 0; color: #0f172a; font-size: 20px;'>Hello {$guardian_name},</h2>
                        <p style='line-height: 1.6; font-size: 14.5px;'>Welcome to VaxiCare! Your child <strong>{$child_name}</strong> has been enrolled into the digital immunization registry by Nurse {$nurse_name}.</p>
                        <p style='line-height: 1.6; font-size: 14.5px;'>You can now monitor growth milestones, view the complete national vaccine schedule, and receive upcoming SMS notifications.</p>
                        
                        <div style='background: #f8fafc; border-left: 4px solid #2563eb; padding: 16px; margin: 24px 0; border-radius: 6px;'>
                            <p style='margin: 0 0 8px; font-size: 14px;'><strong>Login Phone:</strong> {$guardian_phone}</p>
                            <p style='margin: 0; font-size: 14px;'><strong>Temporary Password:</strong> <span style='font-family: monospace; font-weight: bold; color: #dc2626; font-size: 15px;'>{$temp_password}</span></p>
                        </div>
                        
                        <div style='text-align: center; margin: 28px 0;'>
                            <a href='{$login_url}' style='background: #2563eb; color: white; text-decoration: none; padding: 13px 28px; border-radius: 8px; font-weight: bold; display: inline-block; font-size: 14px;'>Access Guardian Portal</a>
                        </div>
                        
                        <p style='line-height: 1.5; font-size: 13px; color: #64748b;'>For security, please change your temporary password immediately upon signing in.</p>
                    </div>
                    <div style='background: #f1f5f9; padding: 14px; text-align: center; font-size: 12px; color: #94a3b8;'>
                        &copy; {$year} VaxiCare Clinical System. All rights reserved.
                    </div>
                </div>";
                
                $email_sent = sendVaxiCareEmail($guardian_email, $guardian_name, $subject, $message);
                $mailto_body = "Hello {$guardian_name},\n\nWelcome to VaxiCare! Your child {$child_name} has been registered.\n\nLogin here: {$login_url}\nTemporary password: {$temp_password}";
                $mailto_url = "mailto:" . rawurlencode($guardian_email) . "?subject=" . rawurlencode($subject) . "&body=" . rawurlencode($mailto_body);
            }

            // Commit primary patient & guardian registration first
            $pdo->commit();
            $success = "Patient <strong>" . htmlspecialchars($child_name) . "</strong> and Guardian <strong>" . htmlspecialchars($guardian_name) . "</strong> registered successfully!";
            if ($email_sent) {
                $success .= " An onboarding email was dispatched to <strong>" . htmlspecialchars($guardian_email) . "</strong>.";
            }
            // Tell the nurse when a requested initial appointment was not created, and why.
            if (!empty($initial_vaccine_id) && !$appt_created) {
                $skip_reason = ($appt_date_problem !== '') ? $appt_date_problem : 'no appointment date was supplied';
                $success .= " <strong>The initial vaccine appointment was not booked:</strong> " . htmlspecialchars($skip_reason) . ".";
            }

            // Post-commit: Log onboarding invitation SMS (safe & non-blocking)
            try {
                $log_appt_id = ($appt_created && !empty($appt_id)) ? $appt_id : null;
                $stmt = $pdo->prepare("INSERT INTO sms_logs (appointment_id, recipient_phone, message, status) VALUES (?, ?, ?, 'Sent')");
                $stmt->execute([$log_appt_id, $guardian_phone, $wa_text]);
            } catch (Exception $sms_err) {
                error_log("sms_logs insert skipped: " . $sms_err->getMessage());
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Registration failed: " . $e->getMessage();
        }
    }
}

$current_date = date('d M, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Infant Registration — VaxiCare</title>
    <meta name="description" content="Pediatric patient enrollment and guardian account provisioning portal for nursing staff.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/nurse_theme.css">
    <style>
        .reg-container {
            max-width: 920px;
            margin: 0 auto 40px auto;
        }

        .reg-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid var(--nr-border);
            padding: 36px 40px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
            position: relative;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 22px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
        }

        .section-header .step-badge {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 800;
        }

        .section-header h3 {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.2px;
            margin: 0;
        }

        .section-header p {
            font-size: 12px;
            color: #64748b;
            margin: 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .reg-card { padding: 24px 20px; }
        }

        .input-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .input-group label {
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .input-group .req {
            color: #ef4444;
        }

        .input-control {
            width: 100%;
            padding: 12px 16px;
            border-radius: 11px;
            border: 1.5px solid #e2e8f0;
            font-size: 13.5px;
            color: #0f172a;
            background: #f8fafc;
            outline: none;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .input-control:focus {
            background: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }

        .gender-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .gender-option-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            border-radius: 11px;
            border: 1.5px solid #e2e8f0;
            background: #f8fafc;
            font-size: 13.5px;
            font-weight: 700;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
        }

        .gender-option-label:hover {
            border-color: #bfdbfe;
            background: #eff6ff;
        }

        .gender-option-label input[type="radio"] {
            accent-color: #2563eb;
        }

        .gender-option-label.active {
            border-color: #2563eb;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .password-field-wrapper {
            position: relative;
        }

        .password-field-wrapper input {
            padding-right: 90px;
        }

        .btn-gen-pass {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 5px 10px;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
            color: #2563eb;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-gen-pass:hover {
            background: #eff6ff;
            border-color: #93c5fd;
        }

        .success-dispatch-card {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border: 1px solid #a7f3d0;
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 28px;
        }

        .dispatch-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .btn-whatsapp-direct {
            background: #25d366;
            color: #ffffff;
            padding: 11px 22px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(37, 211, 102, 0.35);
            transition: all 0.2s ease;
        }

        .btn-whatsapp-direct:hover {
            background: #1eb956;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(37, 211, 102, 0.45);
        }

        .btn-email-direct {
            background: #2563eb;
            color: #ffffff;
            padding: 11px 22px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
            transition: all 0.2s ease;
        }

        .btn-email-direct:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
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
            <a href="register_patient.php" class="sidebar-item active">
                <i class="fa-solid fa-user-plus"></i> Register Patient
            </a>
            <a href="patients.php" class="sidebar-item">
                <i class="fa-solid fa-users"></i> Patient Directory
            </a>
            <a href="appointments.php" class="sidebar-item">
                <i class="fa-solid fa-calendar-check"></i> Appointments
                <?php if($pending_today > 0): ?>
                    <span class="badge-red" style="background:#2563eb;"><?= $pending_today ?></span>
                <?php endif; ?>
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
            </a>

            <div class="sidebar-section-title">Governance &amp; Profile</div>
            <a href="messages.php" class="sidebar-item">
                <i class="fa-solid fa-envelope"></i> Messages
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
        
        <!-- ===== TOPBAR (WITH INLINE BREADCRUMBS & CENTERED DATE) ===== -->
        <header class="app-topbar">
            <div style="display:flex; align-items:center;">
                <button type="button" class="mobile-nav-toggle" onclick="toggleSidebar(true)" aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-left" style="display:flex; flex-direction:row; align-items:center; gap:14px; flex-wrap:wrap;">
                    <h1>Register Patient</h1>
                    <div class="topbar-breadcrumbs" style="display:flex; align-items:center; gap:6px; margin:0;">
                        <span>Pediatric Care</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span class="current">Register Patient</span>
                    </div>
                </div>
            </div>

            <!-- Centered Date & Ward Context -->
            <div class="topbar-center" style="display:flex; align-items:center; justify-content:center;">
                <div class="date-range">
                    <i class="fa-regular fa-calendar"></i> <?= $current_date ?> &bull; Pediatrics &amp; Immunization Ward
                </div>
            </div>
            
            <div class="topbar-right">
                <a href="patients.php" class="nr-btn nr-btn-outline nr-btn-sm" style="text-decoration:none;">
                    <i class="fa-solid fa-users"></i> Patient Directory
                </a>

                <div class="topbar-control-pill">
                    <a href="messages.php" class="topbar-action-btn" title="Messages">
                        <i class="fa-regular fa-bell"></i>
                        <?php if($unread_msg_count > 0): ?>
                            <span class="notif-ping"></span>
                        <?php endif; ?>
                    </a>

                    <a href="settings.php" class="topbar-action-btn" title="Settings">
                        <i class="fa-solid fa-gear"></i>
                    </a>

                    <div class="topbar-divider"></div>

                    <div class="topbar-profile">
                        <div class="topbar-avatar-wrap">
                            <img src="<?= $profile_pic ?>" alt="<?= htmlspecialchars($nurse_name) ?>">
                            <span class="avatar-status-dot"></span>
                        </div>
                        <div class="topbar-profile-info">
                            <span class="topbar-profile-name"><?= htmlspecialchars($nurse_name) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- ===== REGISTRATION FORM CONTAINER ===== -->
        <div class="reg-container">

            <?php if($error): ?>
                <div style="background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; padding:16px 20px; border-radius:12px; margin-bottom:24px; font-weight:600; display:flex; align-items:center; gap:12px;">
                    <i class="fa-solid fa-circle-exclamation" style="color:#dc2626; font-size:18px;"></i>
                    <div><?= $error ?></div>
                </div>
            <?php endif; ?>

            <?php if($success): ?>
                <div class="success-dispatch-card">
                    <div style="display:flex; align-items:flex-start; gap:12px;">
                        <div style="width:36px; height:36px; border-radius:50%; background:#10b981; color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <i class="fa-solid fa-check"></i>
                        </div>
                        <div>
                            <h4 style="margin:0 0 4px 0; color:#065f46; font-size:15px; font-weight:800;">Registration Successful &amp; Guardian Enrolled</h4>
                            <p style="margin:0; color:#047857; font-size:13.5px; line-height:1.4;"><?= $success ?></p>
                        </div>
                    </div>

                    <?php if ($whatsapp_url): ?>
                        <div style="margin-top:16px; padding-top:16px; border-top:1px solid #a7f3d0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                            <div style="font-size:13px; color:#065f46; font-weight:600;">
                                <i class="fa-solid fa-paper-plane" style="margin-right:4px;"></i> Dispatch portal credentials directly to parent:
                            </div>
                            <div class="dispatch-actions">
                                <a href="<?= htmlspecialchars($whatsapp_url) ?>" target="_blank" class="btn-whatsapp-direct">
                                    <i class="fa-brands fa-whatsapp" style="font-size:17px;"></i> Send WhatsApp Invite
                                </a>
                                <?php if (!empty($mailto_url)): ?>
                                    <a href="<?= htmlspecialchars($mailto_url) ?>" class="btn-email-direct">
                                        <i class="fa-solid fa-envelope" style="font-size:15px;"></i> Send Email
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <script>
                            setTimeout(function() {
                                window.open(<?= json_encode($whatsapp_url) ?>, '_blank');
                            }, 600);
                        </script>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="reg-card">
                <form method="POST" action="" id="registrationForm">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    
                    <!-- Section 1: Guardian Credentials -->
                    <div class="section-header">
                        <div class="step-badge">1</div>
                        <div>
                            <h3>Parent / Guardian Profile</h3>
                            <p>Primary caregiver contact details and secure portal access credentials</p>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="input-group">
                            <label for="guardian_name">Guardian Full Name <span class="req">*</span></label>
                            <input type="text" id="guardian_name" name="guardian_name" class="input-control" placeholder="e.g. Mary Banda" required value="<?= htmlspecialchars($_POST['guardian_name'] ?? '') ?>">
                        </div>

                        <div class="input-group">
                            <label for="guardian_phone">Primary Phone (Guardian Login ID) <span class="req">*</span></label>
                            <input type="tel" id="guardian_phone" name="guardian_phone" class="input-control" placeholder="e.g. 0971000199 or +260971000199" required value="<?= htmlspecialchars($_POST['guardian_phone'] ?? '') ?>">
                        </div>

                        <div class="input-group">
                            <label for="guardian_email">Email Address (Optional / Notifications)</label>
                            <input type="email" id="guardian_email" name="guardian_email" class="input-control" placeholder="e.g. parent@example.com" value="<?= htmlspecialchars($_POST['guardian_email'] ?? '') ?>">
                        </div>

                        <div class="input-group">
                            <label for="temp_password">Temporary Portal Password <span class="req">*</span></label>
                            <div class="password-field-wrapper">
                                <input type="text" id="temp_password" name="temp_password" class="input-control" placeholder="e.g. Pass@2026" required value="<?= htmlspecialchars($_POST['temp_password'] ?? 'vaxi' . rand(1000, 9999)) ?>">
                                <button type="button" class="btn-gen-pass" onclick="generateNewPassword()">Generate</button>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Pediatric Infant Details -->
                    <div class="section-header" style="margin-top:16px;">
                        <div class="step-badge">2</div>
                        <div>
                            <h3>Infant Demographics</h3>
                            <p>Clinical identification and date of birth for immunization scheduling</p>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="input-group">
                            <label for="child_name">Infant Full Name <span class="req">*</span></label>
                            <input type="text" id="child_name" name="child_name" class="input-control" placeholder="e.g. Emmanuel Banda" required value="<?= htmlspecialchars($_POST['child_name'] ?? '') ?>">
                        </div>

                        <div class="input-group">
                            <label for="child_dob">Date of Birth <span class="req">*</span></label>
                            <input type="date" id="child_dob" name="child_dob" class="input-control" max="<?= date('Y-m-d') ?>" required value="<?= htmlspecialchars($_POST['child_dob'] ?? '') ?>">
                        </div>

                        <div class="input-group" style="grid-column: 1 / -1;">
                            <label>Biological Gender <span class="req">*</span></label>
                            <div class="gender-options">
                                <label class="gender-option-label" id="labelMale">
                                    <input type="radio" name="child_gender" value="Male" checked onchange="updateGenderStyle()">
                                    <i class="fa-solid fa-mars" style="color:#2563eb;"></i> Male
                                </label>
                                <label class="gender-option-label" id="labelFemale">
                                    <input type="radio" name="child_gender" value="Female" onchange="updateGenderStyle()">
                                    <i class="fa-solid fa-venus" style="color:#ec4899;"></i> Female
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Initial Vaccine Booking -->
                    <div class="section-header" style="margin-top:16px;">
                        <div class="step-badge">3</div>
                        <div>
                            <h3>Initial Vaccine Booking (Optional)</h3>
                            <p>Pre-schedule the infant's first clinical immunization slot</p>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="input-group">
                            <label for="initial_vaccine_id">Initial Vaccine Dose</label>
                            <select id="initial_vaccine_id" name="initial_vaccine_id" class="input-control">
                                <option value="">-- No Immediate Dose / Consult First --</option>
                                <?php foreach ($vaccines as $v): ?>
                                    <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="input-group">
                            <label for="appointment_date">Scheduled Date</label>
                            <input type="date" id="appointment_date" name="appointment_date" class="input-control" min="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="input-group">
                            <label for="appointment_time">Time Slot</label>
                            <select id="appointment_time" name="appointment_time" class="input-control">
                                <option value="08:30:00">08:30 AM (Morning Triage)</option>
                                <option value="09:30:00" selected>09:30 AM (Standard Slot)</option>
                                <option value="10:30:00">10:30 AM</option>
                                <option value="11:30:00">11:30 AM</option>
                                <option value="14:00:00">02:00 PM (Afternoon Clinic)</option>
                            </select>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:36px; padding-top:24px; border-top:1px solid #f1f5f9;">
                        <a href="patients.php" class="nr-btn nr-btn-outline" style="text-decoration:none;">
                            <i class="fa-solid fa-xmark"></i> Cancel
                        </a>

                        <button type="submit" class="nr-btn nr-btn-primary" style="padding:12px 28px; font-size:14px;">
                            <i class="fa-solid fa-user-plus"></i> Enroll Infant &amp; Dispatch Pass
                        </button>
                    </div>

                </form>
            </div>

        </div>

    </main>

    <script>
        function toggleSidebar(open) {
            const sidebar = document.getElementById('nurseSidebar');
            const backdrop = document.getElementById('mobileSidebarBackdrop');
            if (open) {
                sidebar.classList.add('open');
                backdrop.classList.add('show');
            } else {
                sidebar.classList.remove('open');
                backdrop.classList.remove('show');
            }
        }

        function updateGenderStyle() {
            const male = document.querySelector('input[name="child_gender"][value="Male"]').checked;
            document.getElementById('labelMale').classList.toggle('active', male);
            document.getElementById('labelFemale').classList.toggle('active', !male);
        }

        function generateNewPassword() {
            const chars = "abcdefghjkmnpqrstuvwxyz23456789";
            let pass = "Vax@";
            for (let i = 0; i < 4; i++) {
                pass += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('temp_password').value = pass;
        }

        document.addEventListener('DOMContentLoaded', updateGenderStyle);
    </script>
    <script src="../js/notifications.js"></script>
</body>
</html>
