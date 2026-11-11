<?php
session_start();
include __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: ../auth/login.php");
    exit;
}

$nurse_id = $_SESSION['user_id'];
$nurse_name = $_SESSION['name'] ?? 'Bupe';

// Fetch user profile from database
$stmt = $pdo->prepare("SELECT name, profile_pic FROM users WHERE id = ?");
$stmt->execute([$nurse_id]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user_profile && !empty($user_profile['name'])) {
    $nurse_name = $user_profile['name'];
}
$profile_pic = ($user_profile && $user_profile['profile_pic']) ? '../uploads/' . $user_profile['profile_pic'] : "https://ui-avatars.com/api/?name=" . urlencode($nurse_name) . "&background=3d72e7&color=fff";

$success = '';
$error = '';

// Handle Dispatching Batch SMS via Engine
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dispatch_sms') {
    csrf_guard();
    
    // Complex query to find all applicable appointments for the 4 tiers of reminders
    $sql = "
        SELECT a.id, a.appointment_date, a.appointment_time, c.name as child_name, u.phone as guardian_phone,
            CASE 
                WHEN a.appointment_date = CURDATE() + INTERVAL 7 DAY THEN '7_day'
                WHEN a.appointment_date = CURDATE() + INTERVAL 3 DAY THEN '3_day'
                WHEN a.appointment_date = CURDATE() + INTERVAL 1 DAY THEN '1_day'
                WHEN a.appointment_date = CURDATE() AND a.appointment_time BETWEEN CURTIME() AND ADDTIME(CURTIME(), '02:00:00') THEN '2_hour'
            END as reminder_type
        FROM appointments a
        JOIN children c ON a.child_id = c.id
        JOIN users u ON c.guardian_id = u.id
        WHERE a.status IN ('scheduled', 'confirmed')
        HAVING reminder_type IS NOT NULL
    ";
    
    $stmt = $pdo->query($sql);
    $to_remind = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sent_count = 0;
    
    if (count($to_remind) === 0) {
        $error = "No pending appointments currently meet the 7-day, 3-day, 1-day, or 2-hour notification criteria.";
    } else {
        // Require the SMS service for API dispatch
        require_once __DIR__ . '/../config/sms_service.php';

        try {
            $pdo->beginTransaction();
            $stmtCheck = $pdo->prepare("SELECT 1 FROM sms_logs WHERE appointment_id = ? AND reminder_type = ?");
            
            foreach ($to_remind as $appt) {
                // Verify we haven't already sent THIS specific type of reminder for this appointment
                $stmtCheck->execute([$appt['id'], $appt['reminder_type']]);
                if ($stmtCheck->fetchColumn()) {
                    continue; // Already sent
                }
                
                $time_formatted = date('h:i A', strtotime($appt['appointment_time']));
                $date_formatted = date('M d', strtotime($appt['appointment_date']));
                
                // Customize message based on type
                if ($appt['reminder_type'] === '7_day') {
                    $msg = "VaxiCare Alert: " . $appt['child_name'] . " has an upcoming vaccination in 1 week on $date_formatted at $time_formatted. Pediatric Ward.";
                } elseif ($appt['reminder_type'] === '3_day') {
                    $msg = "VaxiCare Reminder: " . $appt['child_name'] . " is due for vaccination in 3 days ($date_formatted at $time_formatted). Please confirm attendance.";
                } elseif ($appt['reminder_type'] === '1_day') {
                    $msg = "VaxiCare Alert: " . $appt['child_name'] . " has a vaccination scheduled for TOMORROW at $time_formatted. Please arrive 10 mins early.";
                } else {
                    $msg = "URGENT: " . $appt['child_name'] . "'s appointment is in less than 2 hours ($time_formatted). Nursing station is ready.";
                }

                // Dispatch via Africa's Talking API (this also logs to DB)
                $result = sendSMS($appt['guardian_phone'], $msg, $appt['id'], $appt['reminder_type']);
                
                if ($result) {
                    $sent_count++;
                }
            }
            
            $pdo->commit();
            if ($sent_count > 0) {
                $success = "Successfully dispatched $sent_count automated SMS reminder(s) to parent mobile devices.";
            } else {
                $error = "All applicable reminders for this window have already been sent or are up to date.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to dispatch SMS: " . $e->getMessage();
        }
    }
}

// Handle Direct Quick SMS Dispatch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_sms') {
    csrf_guard();
    $phone = trim($_POST['phone'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $appt_id = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;

    if ($phone && $message) {
        require_once __DIR__ . '/../config/sms_service.php';
        $res = sendSMS($phone, $message, $appt_id, 'manual');
        if ($res) {
            $success = "Direct SMS successfully transmitted to $phone.";
        } else {
            $error = "Failed to transmit SMS. Please check phone number format.";
        }
    } else {
        $error = "Please provide both recipient phone number and message body.";
    }
}

// Fetch all SMS Logs
$stmt = $pdo->query("
    SELECT s.*, c.name as child_name, u.name as guardian_name, c.id as child_id
    FROM sms_logs s
    LEFT JOIN appointments a ON s.appointment_id = a.id
    LEFT JOIN children c ON a.child_id = c.id
    LEFT JOIN users u ON c.guardian_id = u.id
    ORDER BY s.sent_at DESC
");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Quick Stats
$stats = [
    'total' => count($logs),
    '7_day' => 0,
    '3_day' => 0,
    '1_day' => 0,
    '2_hour' => 0,
    'manual' => 0
];
foreach($logs as $log) {
    if (isset($stats[$log['reminder_type']])) {
        $stats[$log['reminder_type']]++;
    } elseif ($log['reminder_type'] === 'manual') {
        $stats['manual']++;
    }
}

// Check how many appointments are currently upcoming within the next 7 days
$stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date BETWEEN CURDATE() AND (CURDATE() + INTERVAL 7 DAY) AND status IN ('scheduled', 'confirmed')");
$upcoming_week_appts = (int)$stmt->fetchColumn();

// Fetch scheduled appointments list for the Quick SMS dropdown
$stmt = $pdo->query("
    SELECT a.id, a.appointment_date, a.appointment_time, c.name as child_name, u.name as guardian_name, u.phone as guardian_phone
    FROM appointments a
    JOIN children c ON a.child_id = c.id
    JOIN users u ON c.guardian_id = u.id
    WHERE a.status IN ('scheduled', 'confirmed')
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
    LIMIT 30
");
$scheduled_recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'nurse' OR recipient_role = 'all') AND is_read = 0");
$stmt->execute([$nurse_id]);
$unread_msg_count = (int)$stmt->fetchColumn();

// Today date string
$current_date = date('d M, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automated SMS Reminders &amp; Gateway — VaxiCare</title>
    <meta name="description" content="Pediatric automated SMS reminder gateway, 4-tier appointment alert pipeline, delivery telemetry, and guardian communication station.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/nurse_theme.css">
    <style>
        .filter-bar {
            background: #ffffff;
            border: 1px solid var(--nr-border);
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.03);
        }

        .search-box-wrap {
            position: relative;
            flex: 1;
            min-width: 280px;
            max-width: 480px;
        }

        .search-box-wrap input {
            width: 100%;
            padding: 12px 18px 12px 42px;
            border-radius: 12px;
            border: 1.5px solid #cbd5e1;
            background: #f8fafc;
            font-size: 14px;
            color: #0f172a;
            outline: none;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .search-box-wrap input:focus {
            background: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 3.5px rgba(37, 99, 235, 0.12);
        }

        .search-box-wrap i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 15px;
        }

        .premium-tab-track {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            flex-wrap: wrap;
        }

        .premium-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 700;
            color: #64748b;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            border: none;
            background: transparent;
            cursor: pointer;
        }

        .premium-tab-btn:hover {
            color: #0f172a;
            background: rgba(255, 255, 255, 0.6);
        }

        .premium-tab-btn.active {
            background: #ffffff;
            color: #2563eb;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
        }

        .tier-pipeline-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin: 20px 0;
        }

        @media (max-width: 1024px) {
            .tier-pipeline-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 640px) {
            .tier-pipeline-grid {
                grid-template-columns: 1fr;
            }
        }

        .tier-card {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            position: relative;
            transition: all 0.2s ease;
        }

        .tier-card:hover {
            background: #ffffff;
            border-color: #bfdbfe;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.08);
            transform: translateY(-2px);
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11, 19, 43, 0.78);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: modalFadeIn 0.22s ease-out;
        }

        .modal-overlay.active {
            display: flex;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-box {
            background: #ffffff;
            border-radius: 26px;
            width: 100%;
            max-width: 600px;
            padding: 32px 36px;
            box-shadow: 0 30px 65px -15px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(226, 232, 240, 0.8);
            position: relative;
            max-height: 92vh;
            overflow-y: auto;
            border: 1px solid rgba(255, 255, 255, 0.9);
            animation: modalSlideUp 0.28s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalSlideUp {
            from { transform: translateY(22px) scale(0.98); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }

        .modal-close {
            position: absolute;
            top: 24px;
            right: 24px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .modal-close:hover {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5;
            transform: rotate(90deg) scale(1.05);
        }

        .live-pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            background: #10b981;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: livePulse 1.8s infinite;
        }

        @keyframes livePulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
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
            <a href="sms_reminders.php" class="sidebar-item active">
                <i class="fa-solid fa-comment-sms"></i> SMS Reminders
                <?php if($upcoming_week_appts > 0): ?>
                    <span class="badge-red" style="background:#059669;"><?= $upcoming_week_appts ?></span>
                <?php endif; ?>
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
                    <h1>Automated SMS Reminders</h1>
                    <div class="topbar-breadcrumbs" style="display:flex; align-items:center; gap:6px; margin:0;">
                        <span>Clinical Overview</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span class="current">SMS Gateway</span>
                    </div>
                </div>
            </div>

            <!-- Centered Date & Ward Context -->
            <div class="topbar-center" style="display:flex; align-items:center; justify-content:center;">
                <div class="date-range">
                    <i class="fa-regular fa-calendar"></i> <?= $current_date ?> &bull; Pediatrics &amp; Immunization Ward
                </div>
            </div>

            <!-- Right Controls -->
            <div class="topbar-right">
                <button type="button" class="nr-btn nr-btn-emerald" onclick="openQuickSMSModal()" style="padding:8px 18px; font-size:13px;">
                    <i class="fa-solid fa-paper-plane"></i> Quick SMS
                </button>
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
        <?php if($success): ?>
            <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:16px 20px; border-radius:14px; font-weight:700; margin-bottom:24px; display:flex; align-items:center; gap:12px; box-shadow:0 4px 12px rgba(16,185,129,0.08);">
                <i class="fa-solid fa-circle-check" style="font-size:18px; color:#10b981;"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>
        <?php if($error): ?>
            <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:16px 20px; border-radius:14px; font-weight:700; margin-bottom:24px; display:flex; align-items:center; gap:12px; box-shadow:0 4px 12px rgba(239,68,68,0.08);">
                <i class="fa-solid fa-triangle-exclamation" style="font-size:18px; color:#ef4444;"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- ===== ENLARGED TOP STATS GRID ===== -->
        <div class="stats-grid">
            <!-- Stat 1: Total Dispatched -->
            <div class="stat-card card-blue">
                <div class="stat-icon icon-blue">
                    <i class="fa-solid fa-paper-plane"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Total Dispatched</div>
                    <div class="stat-value"><?= number_format($stats['total']) ?></div>
                    <div class="stat-trend trend-up">
                        <i class="fa-solid fa-shield-check"></i> Carrier Verified
                    </div>
                </div>
            </div>

            <!-- Stat 2: 7-Day Alerts -->
            <div class="stat-card card-green">
                <div class="stat-icon icon-green">
                    <i class="fa-solid fa-calendar-week"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">7-Day Advance Alerts</div>
                    <div class="stat-value"><?= number_format($stats['7_day']) ?></div>
                    <div class="stat-trend trend-neutral">
                        <i class="fa-solid fa-calendar-days"></i> 1 Week Notice
                    </div>
                </div>
            </div>

            <!-- Stat 3: 3-Day Reminders -->
            <div class="stat-card card-purple">
                <div class="stat-icon icon-purple">
                    <i class="fa-solid fa-bell"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">3-Day Reminders</div>
                    <div class="stat-value"><?= number_format($stats['3_day']) ?></div>
                    <div class="stat-trend trend-up">
                        <i class="fa-solid fa-clock"></i> Caregiver Prep
                    </div>
                </div>
            </div>

            <!-- Stat 4: Urgent Calls (24h & 2h) -->
            <div class="stat-card card-amber">
                <div class="stat-icon icon-red">
                    <i class="fa-solid fa-bolt"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Urgent (24h &amp; 2h)</div>
                    <div class="stat-value"><?= number_format($stats['1_day'] + $stats['2_hour']) ?></div>
                    <div class="stat-trend trend-down">
                        <i class="fa-solid fa-triangle-exclamation"></i> Day-of Arrivals
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== AUTOMATED ENGINE CONTROL PANEL ===== -->
        <div class="mar-card" style="margin-bottom:24px;">
            <div class="mar-card-header" style="flex-wrap:wrap; gap:14px;">
                <div>
                    <h3 class="mar-card-title">
                        <i class="fa-solid fa-tower-broadcast" style="color:var(--nr-primary);"></i>
                        Automated SMS Dispatch Engine
                    </h3>
                    <p style="font-size:12.5px; color:var(--nr-text-muted); margin-top:2px;">
                        Evaluates all pediatric appointment queues against real-time countdown thresholds
                    </p>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="badge-status bg-green-light" style="padding:6px 14px; font-size:12px;">
                        <span class="live-pulse-dot" style="margin-right:6px;"></span> RapidAPI (D7SMS Gateway) Connected
                    </span>
                    <form method="POST" action="" onsubmit="return confirm('Trigger automated SMS reminder engine evaluation for all pending appointments?');">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="dispatch_sms">
                        <button type="submit" class="nr-btn nr-btn-primary" style="padding:9px 20px; font-size:13px; font-weight:800; box-shadow:0 4px 14px rgba(37,99,235,0.3);">
                            <i class="fa-solid fa-play"></i> Run Engine Dispatch Now
                        </button>
                    </form>
                </div>
            </div>

            <!-- Pipeline 4-Tier Visual Breakdown -->
            <div class="tier-pipeline-grid">
                <div class="tier-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <span class="badge-status bg-green-light" style="font-size:11px;">Tier 1</span>
                        <i class="fa-solid fa-calendar-check" style="color:#059669;"></i>
                    </div>
                    <div style="font-weight:800; color:#0f172a; font-size:14.5px;">7 Days Prior</div>
                    <p style="font-size:12px; color:#64748b; margin:4px 0 0 0;">First notice to allow caregivers to arrange clinic transportation.</p>
                </div>

                <div class="tier-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <span class="badge-status bg-blue-light" style="font-size:11px;">Tier 2</span>
                        <i class="fa-solid fa-calendar-day" style="color:#2563eb;"></i>
                    </div>
                    <div style="font-weight:800; color:#0f172a; font-size:14.5px;">3 Days Prior</div>
                    <p style="font-size:12px; color:#64748b; margin:4px 0 0 0;">Follow-up reminder prompting confirmation of attendance.</p>
                </div>

                <div class="tier-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <span class="badge-status bg-amber-light" style="font-size:11px;">Tier 3</span>
                        <i class="fa-solid fa-clock" style="color:#d97706;"></i>
                    </div>
                    <div style="font-weight:800; color:#0f172a; font-size:14.5px;">24 Hours Prior</div>
                    <p style="font-size:12px; color:#64748b; margin:4px 0 0 0;">Tomorrow alert reminding parent to bring child growth passport.</p>
                </div>

                <div class="tier-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <span class="badge-status bg-red-light" style="font-size:11px;">Tier 4</span>
                        <i class="fa-solid fa-bolt" style="color:#dc2626;"></i>
                    </div>
                    <div style="font-weight:800; color:#0f172a; font-size:14.5px;">2 Hours Before</div>
                    <p style="font-size:12px; color:#64748b; margin:4px 0 0 0;">Urgent arrival call for same-day scheduled morning appointments.</p>
                </div>
            </div>
        </div>

        <!-- ===== SEARCH & FILTER TRACK ===== -->
        <div class="filter-bar">
            <div class="search-box-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="smsLogSearch" placeholder="Search by parent name, child name, or phone number..." oninput="filterLogs()">
            </div>

            <div class="premium-tab-track">
                <button type="button" class="premium-tab-btn active" onclick="filterByTier('all', this)">
                    <i class="fa-solid fa-list-check"></i> All Logs (<?= count($logs) ?>)
                </button>
                <button type="button" class="premium-tab-btn" onclick="filterByTier('7_day', this)">
                    <i class="fa-solid fa-calendar-week"></i> 7-Day (<?= $stats['7_day'] ?>)
                </button>
                <button type="button" class="premium-tab-btn" onclick="filterByTier('3_day', this)">
                    <i class="fa-solid fa-bell"></i> 3-Day (<?= $stats['3_day'] ?>)
                </button>
                <button type="button" class="premium-tab-btn" onclick="filterByTier('1_day', this)">
                    <i class="fa-solid fa-clock"></i> 1-Day (<?= $stats['1_day'] ?>)
                </button>
                <button type="button" class="premium-tab-btn" onclick="filterByTier('2_hour', this)">
                    <i class="fa-solid fa-bolt"></i> 2-Hour (<?= $stats['2_hour'] ?>)
                </button>
            </div>
        </div>

        <!-- ===== TRANSMISSION AUDIT LOGS TABLE ===== -->
        <div class="mar-card" style="margin-bottom:0;">
            <div class="mar-card-header">
                <div>
                    <h3 class="mar-card-title">
                        <i class="fa-solid fa-clock-rotate-left" style="color:var(--nr-primary);"></i>
                        SMS Transmission Audit Log
                    </h3>
                    <p style="font-size:12px; color:var(--nr-text-muted); margin-top:2px;">Chronological delivery status and SMS payload telemetry</p>
                </div>
                <div style="font-size:12px; color:var(--nr-text-muted); font-weight:600;" id="logCountDisplay">
                    Showing <?= count($logs) ?> logged transmissions
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table class="mar-table" id="smsLogTable">
                    <thead>
                        <tr>
                            <th>Sent Timestamp</th>
                            <th>Recipient &amp; Child</th>
                            <th>Mobile Number</th>
                            <th>Reminder Tier</th>
                            <th>Message Payload</th>
                            <th>Gateway Status</th>
                            <th style="text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($logs)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding: 48px 16px; color: var(--nr-text-muted);">
                                    <div style="width:44px; height:44px; border-radius:50%; background:#f1f5f9; display:inline-flex; align-items:center; justify-content:center; margin-bottom:10px; color:#94a3b8; font-size:18px;">
                                        <i class="fa-solid fa-comment-sms"></i>
                                    </div>
                                    <div style="font-weight:700; color:#334155; font-size:14px;">No SMS logs found</div>
                                    <div style="font-size:12px; color:#94a3b8; margin-top:3px;">Click "Run Engine Dispatch Now" to evaluate and trigger alerts.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($logs as $log): ?>
                                <?php
                                    $tier = $log['reminder_type'] ?? 'manual';
                                    $tierBadgeClass = 'bg-blue-light';
                                    $tierLabel = 'MANUAL DISPATCH';
                                    if ($tier === '7_day') {
                                        $tierBadgeClass = 'bg-green-light';
                                        $tierLabel = '7-DAY ADVANCE';
                                    } elseif ($tier === '3_day') {
                                        $tierBadgeClass = 'bg-blue-light';
                                        $tierLabel = '3-DAY NOTICE';
                                    } elseif ($tier === '1_day') {
                                        $tierBadgeClass = 'bg-amber-light';
                                        $tierLabel = '24-HOUR CALL';
                                    } elseif ($tier === '2_hour') {
                                        $tierBadgeClass = 'bg-red-light';
                                        $tierLabel = '2-HOUR URGENT';
                                    }
                                ?>
                                <tr class="sms-log-row" 
                                    data-tier="<?= htmlspecialchars($tier) ?>" 
                                    data-search="<?= htmlspecialchars(strtolower(($log['guardian_name'] ?? '') . ' ' . ($log['child_name'] ?? '') . ' ' . $log['recipient_phone'] . ' ' . $log['message'])) ?>">
                                    <td>
                                        <div style="font-weight:700; color:#0f172a; font-size:13px;">
                                            <?= date('d M, Y', strtotime($log['sent_at'])) ?>
                                        </div>
                                        <div style="font-size:11.5px; color:#64748b;">
                                            <i class="fa-regular fa-clock" style="font-size:10px;"></i> <?= date('h:i A', strtotime($log['sent_at'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <div style="width:32px; height:32px; border-radius:50%; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:12px; border:1px solid #bfdbfe;">
                                                <?= strtoupper(substr($log['guardian_name'] ?: 'G', 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div style="font-weight:700; color:#0f172a; font-size:13px;">
                                                    <?= htmlspecialchars($log['guardian_name'] ?: 'Guardian') ?>
                                                </div>
                                                <div style="font-size:11px; color:#64748b;">
                                                    Child: <strong style="color:#334155;"><?= htmlspecialchars($log['child_name'] ?: 'Patient') ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-family:'Space Grotesk', monospace; font-size:12.5px; font-weight:700; color:#1e293b; background:#f8fafc; padding:3px 8px; border-radius:6px; border:1px solid #e2e8f0;">
                                            <i class="fa-solid fa-phone" style="font-size:10px; color:#64748b; margin-right:4px;"></i><?= htmlspecialchars($log['recipient_phone']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-status <?= $tierBadgeClass ?>" style="font-size:11px; font-weight:800;">
                                            <?= $tierLabel ?>
                                        </span>
                                    </td>
                                    <td style="max-width:320px;">
                                        <div style="font-size:12.5px; color:#334155; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($log['message']) ?>">
                                            <?= htmlspecialchars($log['message']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-status bg-green-light" style="font-size:11px;">
                                            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($log['status'] ?: 'Delivered') ?>
                                        </span>
                                    </td>
                                    <td style="text-align:right;">
                                        <button type="button" class="nr-btn nr-btn-sm nr-btn-outline" style="padding:4px 10px; font-size:11.5px;" 
                                                onclick="viewLogModal('<?= htmlspecialchars(addslashes($log['guardian_name'] ?: 'Guardian')) ?>', '<?= htmlspecialchars(addslashes($log['child_name'] ?: 'Child')) ?>', '<?= htmlspecialchars(addslashes($log['recipient_phone'])) ?>', '<?= $tierLabel ?>', '<?= htmlspecialchars(addslashes($log['message'])) ?>', '<?= date('d M Y, h:i A', strtotime($log['sent_at'])) ?>')">
                                            <i class="fa-regular fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- ===== QUICK MANUAL SMS COMPOSER MODAL ===== -->
    <div class="modal-overlay" id="quickSMSModal">
        <div class="modal-box">
            <button class="modal-close" onclick="closeQuickSMSModal()" title="Close (Esc)"><i class="fa-solid fa-xmark"></i></button>
            
            <div style="display:flex; align-items:center; gap:14px; margin-bottom:20px;">
                <div style="width:46px; height:46px; border-radius:14px; background:linear-gradient(135deg, #10b981, #059669); color:#fff; display:flex; align-items:center; justify-content:center; font-size:20px; box-shadow:0 6px 16px rgba(16,185,129,0.3); flex-shrink:0;">
                    <i class="fa-solid fa-paper-plane"></i>
                </div>
                <div>
                    <h3 style="font-size:19px; font-weight:800; color:#0f172a; margin:0; letter-spacing:-0.3px;">Dispatch Direct SMS</h3>
                    <p style="font-size:12.5px; color:#64748b; margin:3px 0 0 0;">Transmit immediate customized notification to parent mobile</p>
                </div>
            </div>

            <form method="POST" action="">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="quick_sms">

                <div class="field-group" style="margin-bottom:16px;">
                    <label style="font-size:12px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:0.5px; display:block; margin-bottom:6px;">
                        Quick Recipient from Scheduled Queue (Optional)
                    </label>
                    <select id="quick_select_appt" class="input-control" style="font-weight:600;" onchange="onSelectRecipientPreset(this)">
                        <option value="" data-phone="" data-child="" data-date="">-- Or Enter Custom Phone Below --</option>
                        <?php foreach($scheduled_recipients as $sr): ?>
                            <option value="<?= $sr['id'] ?>" data-phone="<?= htmlspecialchars($sr['guardian_phone']) ?>" data-child="<?= htmlspecialchars($sr['child_name']) ?>" data-date="<?= date('M d, h:i A', strtotime($sr['appointment_date'] . ' ' . $sr['appointment_time'])) ?>">
                                <?= htmlspecialchars($sr['guardian_name']) ?> (<?= htmlspecialchars($sr['child_name']) ?>) &bull; <?= htmlspecialchars($sr['guardian_phone']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field-group" style="margin-bottom:16px;">
                    <label style="font-size:12px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:0.5px; display:block; margin-bottom:6px;">
                        Guardian Phone Number <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="text" name="phone" id="quick_phone_input" class="input-control" required placeholder="e.g. 0971234567 or +260971234567" style="font-weight:700; font-family:'Space Grotesk', monospace;">
                    <div style="font-size:11px; color:#64748b; margin-top:4px;">Zambian format supported (097xxxxxxx will auto-prefix with +260)</div>
                </div>

                <div class="field-group" style="margin-bottom:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <label style="font-size:12px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:0.5px;">
                            Message Content <span style="color:#ef4444;">*</span>
                        </label>
                        <span style="font-size:11.5px; color:#94a3b8; font-weight:600;" id="charCounter">0 / 160 chars (1 SMS)</span>
                    </div>
                    <textarea name="message" id="quick_msg_input" rows="4" class="input-control" required placeholder="Type SMS message..." oninput="updateCharCount(this)" style="resize:vertical; font-size:13.5px;"></textarea>
                    
                    <!-- Quick Message Templates -->
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                        <span class="symptom-tag" onclick="applyTemplate('Reminder: Child vaccination session is ready today. Please check in at Pediatrics.')">+ Ready Notice</span>
                        <span class="symptom-tag" onclick="applyTemplate('Urgent: Please bring your child\'s health record passport to clinic today.')">+ Health Passport</span>
                        <span class="symptom-tag" onclick="applyTemplate('VaxiCare Notice: Appointment rescheduled successfully. See you at the ward.')">+ Reschedule Notice</span>
                    </div>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end; align-items: center; margin-top:20px;">
                    <button type="button" onclick="closeQuickSMSModal()" class="nr-btn nr-btn-outline">Cancel</button>
                    <button type="submit" class="nr-btn nr-btn-emerald" style="padding:10px 24px; font-weight:800;">
                        <i class="fa-solid fa-paper-plane"></i> Transmit SMS Now
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== LOG VIEW PAYLOAD MODAL ===== -->
    <div class="modal-overlay" id="viewLogModal">
        <div class="modal-box">
            <button class="modal-close" onclick="closeViewLogModal()"><i class="fa-solid fa-xmark"></i></button>
            <h3 style="font-size: 19px; font-weight: 800; color: #0F172A; margin-bottom: 4px;">SMS Transmission Details</h3>
            <p style="font-size: 13px; color: #64748B; margin-bottom: 18px;">Detailed payload record from Africa's Talking API log</p>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:16px; margin-bottom:16px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; font-size:13px;">
                    <div><span style="color:#64748b;">Recipient Guardian:</span> <strong id="modal_view_guardian" style="color:#0f172a; display:block;"></strong></div>
                    <div><span style="color:#64748b;">Child:</span> <strong id="modal_view_child" style="color:#0f172a; display:block;"></strong></div>
                    <div><span style="color:#64748b;">Mobile:</span> <strong id="modal_view_phone" style="color:#0f172a; font-family:'Space Grotesk', monospace; display:block;"></strong></div>
                    <div><span style="color:#64748b;">Sent Time:</span> <strong id="modal_view_time" style="color:#0f172a; display:block;"></strong></div>
                </div>
                <div>
                    <span style="color:#64748b; font-size:12px;">Reminder Tier:</span>
                    <div style="margin-top:4px;"><span class="badge-status bg-blue-light" id="modal_view_tier"></span></div>
                </div>
            </div>

            <div class="field-group">
                <label style="font-size:12px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; display:block;">Full Transmitted Message Payload</label>
                <div id="modal_view_msg" style="background:#ffffff; border:1.5px solid #cbd5e1; border-radius:12px; padding:14px; font-size:13.5px; color:#0f172a; line-height:1.6; word-break:break-word;">
                </div>
            </div>

            <div style="margin-top: 20px; display: flex; justify-content: flex-end;">
                <button type="button" onclick="closeViewLogModal()" class="nr-btn nr-btn-outline" style="padding:9px 20px;">
                    Close
                </button>
            </div>
        </div>
    </div>

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

        // Live Filtering for Logs Table
        let currentTier = 'all';

        function filterByTier(tier, btn) {
            currentTier = tier;
            document.querySelectorAll('.premium-tab-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
            applyFilters();
        }

        function filterLogs() {
            applyFilters();
        }

        function applyFilters() {
            const searchVal = document.getElementById('smsLogSearch').value.toLowerCase().trim();
            const rows = document.querySelectorAll('.sms-log-row');
            let visibleCount = 0;

            rows.forEach(row => {
                const rowTier = row.getAttribute('data-tier');
                const rowSearch = row.getAttribute('data-search') || '';

                const matchesTier = (currentTier === 'all' || rowTier === currentTier);
                const matchesSearch = (searchVal === '' || rowSearch.includes(searchVal));

                if (matchesTier && matchesSearch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            const countDisplay = document.getElementById('logCountDisplay');
            if (countDisplay) {
                countDisplay.innerText = 'Showing ' + visibleCount + ' logged transmissions';
            }
        }

        // Quick SMS Modal Functions
        function openQuickSMSModal() {
            document.getElementById('quickSMSModal').classList.add('active');
        }

        function closeQuickSMSModal() {
            document.getElementById('quickSMSModal').classList.remove('active');
        }

        function onSelectRecipientPreset(sel) {
            const opt = sel.options[sel.selectedIndex];
            if (opt && opt.dataset.phone) {
                document.getElementById('quick_phone_input').value = opt.dataset.phone;
                const child = opt.dataset.child || 'your infant';
                const date = opt.dataset.date || 'upcoming session';
                document.getElementById('quick_msg_input').value = 'VaxiCare Alert: Reminder for ' + child + ' regarding scheduled vaccination (' + date + '). Please arrive on time.';
                updateCharCount(document.getElementById('quick_msg_input'));
            }
        }

        function updateCharCount(ta) {
            const len = ta.value.length;
            const segments = Math.ceil(len / 160) || 1;
            const counter = document.getElementById('charCounter');
            if (counter) {
                counter.innerText = len + ' / 160 chars (' + segments + ' SMS segment' + (segments > 1 ? 's' : '') + ')';
            }
        }

        function applyTemplate(txt) {
            const ta = document.getElementById('quick_msg_input');
            ta.value = txt;
            updateCharCount(ta);
            ta.focus();
        }

        // View Log Modal Functions
        function viewLogModal(guardian, child, phone, tier, msg, time) {
            document.getElementById('modal_view_guardian').innerText = guardian;
            document.getElementById('modal_view_child').innerText = child;
            document.getElementById('modal_view_phone').innerText = phone;
            document.getElementById('modal_view_tier').innerText = tier;
            document.getElementById('modal_view_msg').innerText = msg;
            document.getElementById('modal_view_time').innerText = time;
            document.getElementById('viewLogModal').classList.add('active');
        }

        function closeViewLogModal() {
            document.getElementById('viewLogModal').classList.remove('active');
        }

        // Escape key listener for modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeQuickSMSModal();
                closeViewLogModal();
            }
        });
    </script>
    <script src="../js/notifications.js"></script>
</body>
</html>
