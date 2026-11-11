<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

// Doctor Authentication Guard
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Fetch verified doctor profile
$stmt = $pdo->prepare("SELECT name, phone, email, profile_pic FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);

$doctor_name = $user_profile['name'] ?? $_SESSION['name'] ?? 'Dr. Emily Chen';
$profile_pic = (!empty($user_profile['profile_pic'])) 
    ? '../uploads/' . htmlspecialchars($user_profile['profile_pic']) 
    : "https://ui-avatars.com/api/?name=" . urlencode($doctor_name) . "&background=0284c7&color=fff";

$success_msg = '';
$error_msg = '';

// Handle Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    csrf_guard();
    $appt_id = (int)($_POST['appointment_id'] ?? 0);
    $new_status = $_POST['status'] ?? '';
    if ($appt_id > 0 && in_array($new_status, ['scheduled', 'confirmed', 'completed', 'cancelled', 'no-show'])) {
        $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?");
        if ($stmt->execute([$new_status, $appt_id])) {
            $success_msg = "Appointment status updated to " . ucfirst($new_status) . " successfully!";
        } else {
            $error_msg = "Failed to update appointment status.";
        }
    }
}

// Handle New Appointment Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new_appointment') {
    csrf_guard();
    $child_id = (int)($_POST['child_id'] ?? 0);
    $vaccine_id = (int)($_POST['vaccine_id'] ?? 0);
    $appt_date = $_POST['appointment_date'] ?? null;
    $appt_time = $_POST['appointment_time'] ?? null;

    if ($child_id > 0 && $vaccine_id > 0 && $appt_date && $appt_time) {
        // Optional mother lookup for foreign key consistency if applicable
        $mother_stmt = $pdo->prepare("SELECT id FROM mothers WHERE guardian_id = (SELECT guardian_id FROM children WHERE id = ?) LIMIT 1");
        $mother_stmt->execute([$child_id]);
        $mother_id = $mother_stmt->fetchColumn() ?: null;

        $stmt = $pdo->prepare("INSERT INTO appointments (mother_id, child_id, vaccine_id, appointment_date, appointment_time, status) VALUES (?, ?, ?, ?, ?, 'scheduled')");
        if ($stmt->execute([$mother_id, $child_id, $vaccine_id, $appt_date, $appt_time])) {
            $new_appt_id = $pdo->lastInsertId();
            $success_msg = "Appointment scheduled successfully for " . htmlspecialchars($appt_date) . "!";

            // Trigger In-System Notification
            if (function_exists('recordAppointmentNotification')) {
                recordAppointmentNotification($pdo, $new_appt_id, 'booked');
            }

            // Redirect to the newly scheduled date
            header("Location: schedule.php?date=" . urlencode($appt_date) . "&success=" . urlencode($success_msg));
            exit;
        } else {
            $error_msg = "Failed to record appointment.";
        }
    } else {
        $error_msg = "Please fill in all required appointment details.";
    }
}

if (isset($_GET['success'])) {
    $success_msg = $_GET['success'];
}

// Default to today or selected date
$selected_date = $_GET['date'] ?? date('Y-m-d');
// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}
$selected_timestamp = strtotime($selected_date);
$current_date_display = date('d M, Y', $selected_timestamp);

$current_month = date('m', $selected_timestamp);
$current_year = date('Y', $selected_timestamp);

// Top KPI Metrics
$stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ?");
$stmt->execute([$selected_date]);
$selected_date_count = (int)$stmt->fetchColumn();

$completed_today = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE AND status = 'completed'")->fetchColumn();
$pending_today = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE AND status = 'scheduled'")->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE MONTH(appointment_date) = ? AND YEAR(appointment_date) = ?");
$stmt->execute([$current_month, $current_year]);
$month_total_count = (int)$stmt->fetchColumn();

// Fetch appointments for selected date
$stmt = $pdo->prepare("
    SELECT a.id as appointment_id, a.appointment_date, a.appointment_time, a.status,
           c.id as child_id, c.name as child_name, c.date_of_birth, c.gender,
           u.name as guardian_name, u.phone as guardian_phone,
           v.name as vaccine_name
    FROM appointments a
    JOIN children c ON a.child_id = c.id
    JOIN users u ON c.guardian_id = u.id
    LEFT JOIN vaccines v ON a.vaccine_id = v.id
    WHERE a.appointment_date = ?
    ORDER BY a.appointment_time ASC
");
$stmt->execute([$selected_date]);
$schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Age Helper
function calculateAge($dob) {
    if (empty($dob)) return 'N/A';
    try {
        $birthDate = new DateTime($dob);
        $today = new DateTime('today');
        $age = $birthDate->diff($today);
        if ($age->y > 0) return $age->y . 'y ' . $age->m . 'm';
        if ($age->m > 0) return $age->m . ' mos';
        return $age->d . ' days';
    } catch (Exception $e) {
        return 'N/A';
    }
}

// Fetch calendar day statuses for current month
$stmt = $pdo->prepare("
    SELECT 
        DAY(appointment_date) as day_num,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_cnt,
        SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled_cnt
    FROM appointments 
    WHERE MONTH(appointment_date) = ? AND YEAR(appointment_date) = ?
    GROUP BY DAY(appointment_date)
");
$stmt->execute([$current_month, $current_year]);
$day_statuses = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $day_statuses[$row['day_num']] = [
        'completed' => (int)$row['completed_cnt'],
        'scheduled' => (int)$row['scheduled_cnt']
    ];
}

// Sidebar Badges
$pending_alerts = (int)$pdo->query("SELECT COUNT(*) FROM triage_referrals WHERE status = 'pending'")->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'doctor') AND is_read = 0");
$stmt->execute([$user_id]);
$unread_msg_count = (int)($stmt->fetchColumn() ?: 0);

// Pre-load children & vaccines for scheduling modal
$all_children = $pdo->query("SELECT id, name, gender, date_of_birth FROM children ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_vaccines = $pdo->query("SELECT id, name FROM vaccines ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Schedule &amp; Appointment Calendar — VaxiCare Doctor Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/doctor_theme.css">
    <style>
        .schedule-layout-grid {
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 1024px) {
            .schedule-layout-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Calendar Widget Styles */
        .calendar-card {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
        }

        .calendar-nav-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

        .calendar-month-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
        }

        .calendar-nav-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #475569;
            text-decoration: none;
            transition: all 0.2s;
            font-size: 12px;
        }

        .calendar-nav-btn:hover {
            border-color: #0284c7;
            color: #0284c7;
            background: #f0f9ff;
        }

        .calendar-weekdays-row {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            text-align: center;
            font-size: 11px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .calendar-dates-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            text-align: center;
        }

        .cal-day-cell {
            position: relative;
            height: 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            border-radius: 10px;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            background: #ffffff;
            border: 1px solid transparent;
        }

        .cal-day-cell:hover {
            background: #f0f9ff;
            color: #0284c7;
            border-color: #bae6fd;
        }

        .cal-day-cell.active-day {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%) !important;
            color: #ffffff !important;
            font-weight: 800;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.35);
        }

        .cal-day-cell.active-day .cal-dot {
            background: #ffffff !important;
        }

        .cal-dots-wrap {
            display: flex;
            gap: 3px;
            position: absolute;
            bottom: 3px;
        }

        .cal-dot {
            width: 4px;
            height: 4px;
            border-radius: 50%;
        }

        .dot-green { background: #05cd99; }
        .dot-blue  { background: #0284c7; }

        .cal-legend {
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
        }

        /* Appointment Row / Card */
        .appt-card {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 14px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            transition: all 0.2s;
            margin-bottom: 12px;
        }

        .appt-card:hover {
            border-color: #93c5fd;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.06);
            transform: translateY(-1px);
        }

        .appt-time-box {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px 12px;
            text-align: center;
            min-width: 72px;
            flex-shrink: 0;
        }

        .appt-time-main {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1;
        }

        .appt-time-period {
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            margin-top: 2px;
            text-transform: uppercase;
        }

        /* Modal Architecture */
        .dr-modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .dr-modal-backdrop.active {
            display: flex;
        }

        .dr-modal-box {
            background: #ffffff;
            border-radius: 18px;
            border: 1.5px solid #cbd5e1;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            width: 100%;
            max-width: 480px;
            overflow: hidden;
            animation: modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalPop {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .dr-modal-header {
            padding: 20px 24px;
            border-bottom: 1.5px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
        }

        .dr-modal-body {
            padding: 24px;
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

        .form-input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            font-size: 13.5px;
            font-family: inherit;
            color: #0f172a;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
            transition: all 0.2s;
        }

        .form-input:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
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
            <a href="schedule.php" class="sidebar-item active">
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
            <a href="settings.php" class="sidebar-item">
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
                    <h1>Clinical Schedule</h1>
                    <div class="topbar-breadcrumbs" style="display: flex; align-items: center; gap: 6px; margin: 0;">
                        <span>Doctor Station</span>
                        <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #cbd5e1;"></i>
                        <span class="current">Appointment Calendar</span>
                    </div>
                </div>
            </div>

            <div class="topbar-center">
                <div class="date-range">
                    <i class="fa-regular fa-calendar-check"></i>
                    <span>Pediatric Clinical Station &bull; <?= date('l, d M Y') ?></span>
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
                    <a href="settings.php" class="topbar-action-btn" title="Account Settings">
                        <i class="fa-solid fa-gear"></i>
                    </a>
                    <div class="topbar-divider"></div>
                    <a href="settings.php" class="topbar-profile" title="Doctor Account">
                        <div class="topbar-avatar-wrap">
                            <img src="<?= $profile_pic ?>" alt="<?= htmlspecialchars($doctor_name) ?>">
                            <span class="avatar-status-dot"></span>
                        </div>
                        <div class="topbar-profile-info">
                            <span class="topbar-profile-name"><?= htmlspecialchars($doctor_name) ?></span>
                        </div>
                    </a>
                </div>
            </div>
        </header>

        <!-- KPI Metrics Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue">
                    <i class="fa-regular fa-calendar-days"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Selected Date</div>
                    <div class="stat-value"><?= number_format($selected_date_count) ?></div>
                    <div class="stat-trend"><i class="fa-regular fa-calendar" style="color: #0284c7;"></i> <?= date('d M Y', $selected_timestamp) ?></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon icon-cyan">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Pending Today</div>
                    <div class="stat-value"><?= number_format($pending_today) ?></div>
                    <div class="stat-trend"><i class="fa-solid fa-clock" style="color: #00B5D8;"></i> Queued Sessions</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-green">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Completed Today</div>
                    <div class="stat-value"><?= number_format($completed_today) ?></div>
                    <div class="stat-trend"><i class="fa-solid fa-shield-halved" style="color: #05CD99;"></i> Administered &amp; Signed</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-purple">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Monthly Volume</div>
                    <div class="stat-value"><?= number_format($month_total_count) ?></div>
                    <div class="stat-trend"><i class="fa-solid fa-chart-line" style="color: #7928ca;"></i> <?= date('F Y', $selected_timestamp) ?> Total</div>
                </div>
            </div>
        </div>

        <!-- Feedback Alerts -->
        <?php if ($success_msg): ?>
            <div style="background: #ecfdf5; border: 1.5px solid #a7f3d0; color: #065f46; padding: 14px 18px; border-radius: 14px; margin-bottom: 22px; font-weight: 600; font-size: 13.5px; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check" style="color: #05cd99; font-size: 16px;"></i>
                <div><?= htmlspecialchars($success_msg) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div style="background: #fef2f2; border: 1.5px solid #fca5a5; color: #991b1b; padding: 14px 18px; border-radius: 14px; margin-bottom: 22px; font-weight: 600; font-size: 13.5px; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444; font-size: 16px;"></i>
                <div><?= htmlspecialchars($error_msg) ?></div>
            </div>
        <?php endif; ?>

        <!-- Main Schedule Grid Layout -->
        <div class="schedule-layout-grid">
            
            <!-- LEFT: Monthly Interactive Calendar -->
            <div class="calendar-card">
                <div class="calendar-nav-bar">
                    <a href="?date=<?= date('Y-m-d', strtotime($selected_date . ' -1 month')) ?>" class="calendar-nav-btn" title="Previous Month">
                        <i class="fa-solid fa-chevron-left"></i>
                    </a>
                    <div class="calendar-month-title">
                        <?= date('F Y', $selected_timestamp) ?>
                    </div>
                    <a href="?date=<?= date('Y-m-d', strtotime($selected_date . ' +1 month')) ?>" class="calendar-nav-btn" title="Next Month">
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </div>

                <div class="calendar-weekdays-row">
                    <div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
                </div>

                <div class="calendar-dates-grid">
                    <?php 
                    $first_day_of_month = (int)date('w', strtotime("$current_year-$current_month-01"));
                    $days_in_month = (int)date('t', $selected_timestamp);
                    
                    // Empty cells before start of month
                    for ($i = 0; $i < $first_day_of_month; $i++): ?>
                        <div style="height: 40px;"></div>
                    <?php endfor;

                    // Month Days
                    for ($day = 1; $day <= $days_in_month; $day++):
                        $date_str = sprintf("%04d-%02d-%02d", $current_year, $current_month, $day);
                        $is_active = ($date_str === $selected_date) ? 'active-day' : '';
                        $status_info = $day_statuses[$day] ?? null;
                    ?>
                        <a href="?date=<?= $date_str ?>" class="cal-day-cell <?= $is_active ?>">
                            <span><?= $day ?></span>
                            <?php if ($status_info): ?>
                                <div class="cal-dots-wrap">
                                    <?php if ($status_info['completed'] > 0): ?>
                                        <span class="cal-dot dot-green" title="<?= $status_info['completed'] ?> Completed"></span>
                                    <?php endif; ?>
                                    <?php if ($status_info['scheduled'] > 0): ?>
                                        <span class="cal-dot dot-blue" title="<?= $status_info['scheduled'] ?> Scheduled"></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </a>
                    <?php endfor; ?>
                </div>

                <div class="cal-legend">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <span class="cal-dot dot-green" style="width: 7px; height: 7px;"></span>
                        <span>Completed Visit</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <span class="cal-dot dot-blue" style="width: 7px; height: 7px;"></span>
                        <span>Scheduled Session</span>
                    </div>
                </div>

                <div style="margin-top: 16px; text-align: center;">
                    <a href="?date=<?= date('Y-m-d') ?>" class="dr-btn dr-btn-secondary dr-btn-sm" style="width: 100%;">
                        <i class="fa-solid fa-calendar-day"></i> Jump to Today
                    </a>
                </div>
            </div>

            <!-- RIGHT: Day's Appointments List & Actions -->
            <div class="dr-card" style="margin-bottom: 0;">
                <div class="dr-card-header">
                    <h2 class="dr-card-title">
                        <i class="fa-regular fa-calendar-check" style="color: #0284c7;"></i>
                        Appointments for <?= date('l, d M Y', $selected_timestamp) ?>
                    </h2>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="badge-status badge-blue">
                            <?= count($schedule) ?> Scheduled
                        </span>
                        <button type="button" onclick="openNewApptModal()" class="dr-btn dr-btn-primary dr-btn-sm">
                            <i class="fa-solid fa-plus"></i> New Appointment
                        </button>
                    </div>
                </div>

                <div class="dr-card-body" style="padding: 22px;">
                    <?php if (empty($schedule)): ?>
                        <div style="text-align: center; padding: 48px 20px; color: #64748b;">
                            <div style="font-size: 38px; margin-bottom: 12px; color: #cbd5e1;">
                                <i class="fa-regular fa-calendar-xmark"></i>
                            </div>
                            <div style="font-weight: 800; font-size: 15px; color: #1e293b;">
                                No Appointments on <?= date('d M Y', $selected_timestamp) ?>
                            </div>
                            <p style="font-size: 12.5px; margin: 4px 0 16px; color: #94a3b8;">
                                No pediatric patient consultations or immunization visits recorded for this day.
                            </p>
                            <button type="button" onclick="openNewApptModal()" class="dr-btn dr-btn-primary dr-btn-sm">
                                <i class="fa-solid fa-calendar-plus"></i> Schedule Patient for This Date
                            </button>
                        </div>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <?php foreach ($schedule as $apt): 
                                $is_boy = ($apt['gender'] === 'Male');
                                $time_fmt = date('h:i A', strtotime($apt['appointment_time']));
                                $time_parts = explode(' ', $time_fmt);
                            ?>
                                <div class="appt-card">
                                    <!-- Time Badge -->
                                    <div class="appt-time-box">
                                        <div class="appt-time-main"><?= $time_parts[0] ?></div>
                                        <div class="appt-time-period"><?= $time_parts[1] ?></div>
                                    </div>

                                    <!-- Patient Info -->
                                    <div style="display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0;">
                                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($apt['child_name']) ?>&background=<?= $is_boy ? 'e0f2fe' : 'ffe4e6' ?>&color=<?= $is_boy ? '0284c7' : 'e11d48' ?>" 
                                             style="width: 38px; height: 38px; border-radius: 12px; flex-shrink: 0; object-fit: cover;">
                                        <div style="min-width: 0;">
                                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                                <a href="patient_detail.php?id=<?= $apt['child_id'] ?>" style="font-weight: 700; font-size: 14px; color: #0f172a; text-decoration: none;">
                                                    <?= htmlspecialchars($apt['child_name']) ?>
                                                </a>
                                                <span style="font-size: 11px; font-weight: 600; color: #0284c7; background: #e0f2fe; padding: 2px 7px; border-radius: 6px;">
                                                    <?= calculateAge($apt['date_of_birth']) ?>
                                                </span>
                                            </div>
                                            <div style="font-size: 11.5px; color: #64748b; margin-top: 3px;">
                                                <i class="fa-solid fa-syringe" style="color: #0284c7; margin-right: 3px;"></i>
                                                <strong><?= htmlspecialchars($apt['vaccine_name'] ?? 'Routine Clinical Checkup') ?></strong>
                                                &bull; Guardian: <?= htmlspecialchars($apt['guardian_name']) ?> (<?= htmlspecialchars($apt['guardian_phone']) ?>)
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Status & Quick Action Form -->
                                    <div style="display: flex; align-items: center; gap: 10px; flex-shrink: 0;">
                                        <form method="POST" style="margin: 0;">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="appointment_id" value="<?= $apt['appointment_id'] ?>">
                                            <select name="status" onchange="this.form.submit()" style="padding: 6px 10px; border-radius: 8px; font-size: 11.5px; font-weight: 700; border: 1.5px solid #cbd5e1; outline: none; cursor: pointer; background: #ffffff; text-transform: uppercase;">
                                                <option value="scheduled" <?= $apt['status'] === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                                <option value="confirmed" <?= $apt['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                                <option value="completed" <?= $apt['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                                <option value="cancelled" <?= $apt['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                                <option value="no-show" <?= $apt['status'] === 'no-show' ? 'selected' : '' ?>>No-Show</option>
                                            </select>
                                        </form>

                                        <a href="patient_detail.php?id=<?= $apt['child_id'] ?>" class="dr-btn dr-btn-secondary dr-btn-sm" title="View Patient Clinical File">
                                            <i class="fa-solid fa-notes-medical"></i> File
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div> <!-- End Schedule Layout Grid -->

    </main>

    <!-- New Appointment Modal Dialog -->
    <div class="dr-modal-backdrop" id="newApptModal">
        <div class="dr-modal-box">
            <div class="dr-modal-header">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 32px; height: 32px; border-radius: 10px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 14px;">
                        <i class="fa-solid fa-calendar-plus"></i>
                    </div>
                    <div>
                        <div style="font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 800; color: #0f172a;">New Appointment</div>
                        <div style="font-size: 11px; color: #64748b;">Schedule patient consultation or vaccine dose</div>
                    </div>
                </div>
                <button type="button" onclick="closeNewApptModal()" style="background: none; border: none; font-size: 18px; color: #94a3b8; cursor: pointer;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="POST" action="schedule.php?date=<?= htmlspecialchars($selected_date) ?>
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">">
                <input type="hidden" name="action" value="new_appointment">
                <div class="dr-modal-body">
                    
                    <div style="margin-bottom: 16px;">
                        <label class="form-label">Select Patient *</label>
                        <select name="child_id" class="form-input" required>
                            <option value="">Choose Patient...</option>
                            <?php foreach ($all_children as $ch): ?>
                                <option value="<?= $ch['id'] ?>">
                                    <?= htmlspecialchars($ch['name']) ?> (<?= calculateAge($ch['date_of_birth']) ?> &bull; <?= htmlspecialchars($ch['gender']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom: 16px;">
                        <label class="form-label">Service / Vaccine Antigen *</label>
                        <select name="vaccine_id" class="form-input" required>
                            <option value="">Select Clinical Vaccine / Reason...</option>
                            <?php foreach ($all_vaccines as $vax): ?>
                                <option value="<?= $vax['id'] ?>"><?= htmlspecialchars($vax['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 24px;">
                        <div>
                            <label class="form-label">Appointment Date *</label>
                            <input type="date" name="appointment_date" class="form-input" value="<?= htmlspecialchars($selected_date) ?>" required>
                        </div>
                        <div>
                            <label class="form-label">Time *</label>
                            <input type="time" name="appointment_time" class="form-input" value="09:00" required>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 10px;">
                        <button type="button" onclick="closeNewApptModal()" class="dr-btn dr-btn-secondary">
                            Cancel
                        </button>
                        <button type="submit" class="dr-btn dr-btn-primary">
                            <i class="fa-solid fa-calendar-check"></i> Confirm Appointment
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Client Script for Modal and Mobile Nav -->
    <script>
        function openNewApptModal() {
            document.getElementById('newApptModal').classList.add('active');
        }

        function closeNewApptModal() {
            document.getElementById('newApptModal').classList.remove('active');
        }

        // Close on backdrop click
        document.getElementById('newApptModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeNewApptModal();
            }
        });

        // Mobile Sidebar Toggle
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
