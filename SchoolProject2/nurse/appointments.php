<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: ../auth/login.php");
    exit;
}

$nurse_name = $_SESSION['name'] ?? 'Bupe';
$success = '';
$error = '';

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

// Handle Booking New Appointment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_booking') {
    csrf_guard();
    $child_id   = (int)($_POST['child_id'] ?? 0);
    $vaccine_id = (int)($_POST['vaccine_id'] ?? 0);
    $appt_date  = $_POST['appointment_date'] ?? '';
    $appt_time  = $_POST['appointment_time'] ?? '09:00:00';
    $notes      = trim($_POST['notes'] ?? '');

    if ($child_id > 0 && $vaccine_id > 0 && !empty($appt_date)) {
        $stmt = $pdo->prepare("INSERT INTO appointments (child_id, vaccine_id, appointment_date, appointment_time, status, notes) VALUES (?, ?, ?, ?, 'scheduled', ?)");
        if ($stmt->execute([$child_id, $vaccine_id, $appt_date, $appt_time, $notes])) {
            $new_appt_id = $pdo->lastInsertId();
            $success = "New vaccine appointment booked successfully!";
            
            // In-System Notification
            if (function_exists('recordAppointmentNotification')) {
                recordAppointmentNotification($pdo, $new_appt_id, 'booked');
            }
        } else {
            $error = "Failed to book appointment.";
        }
    } else {
        $error = "Please fill in all required booking details.";
    }
}

// Handle Reschedule Appointment — Smart Shift to Next Free Day
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reschedule') {
    csrf_guard();
    $appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $new_date       = $_POST['new_date'] ?? '';
    $new_time       = $_POST['new_time'] ?? '09:00:00';
    $use_smart      = isset($_POST['use_smart_date']) && $_POST['use_smart_date'] === '1';

    if ($use_smart) {
        $check = new DateTime('tomorrow');
        for ($i = 0; $i < 30; $i++) {
            $dayNum = (int)$check->format('N'); // 1=Mon, 7=Sun
            if ($dayNum <= 5) {
                $checkDate = $check->format('Y-m-d');
                $s2 = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status NOT IN ('cancelled','no-show')");
                $s2->execute([$checkDate]);
                $cnt = (int)$s2->fetchColumn();
                if ($cnt < 8) {
                    $new_date = $checkDate;
                    break;
                }
            }
            $check->modify('+1 day');
        }
    }

    if ($appointment_id > 0 && !empty($new_date)) {
        $stmt = $pdo->prepare("SELECT child_id, vaccine_id, notes FROM appointments WHERE id = ?");
        $stmt->execute([$appointment_id]);
        $old_appt = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($old_appt) {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE appointments SET status = 'rescheduled' WHERE id = ?");
                $stmt->execute([$appointment_id]);

                $stmt = $pdo->prepare("INSERT INTO appointments (child_id, vaccine_id, appointment_date, appointment_time, status, notes) VALUES (?, ?, ?, ?, 'scheduled', ?)");
                if ($stmt->execute([$old_appt['child_id'], $old_appt['vaccine_id'], $new_date, $new_time, $old_appt['notes']])) {
                    $new_appt_id = $pdo->lastInsertId();

                    // Ensure any other previous uncompleted appointments for this child and milestone are marked rescheduled so only the newly shifted slot shows
                    if (!empty($old_appt['vaccine_id'])) {
                        $stmtClean = $pdo->prepare("UPDATE appointments SET status = 'rescheduled' WHERE child_id = ? AND vaccine_id = ? AND status IN ('scheduled', 'confirmed') AND id != ?");
                        $stmtClean->execute([$old_appt['child_id'], $old_appt['vaccine_id'], $new_appt_id]);
                    } else {
                        $stmtClean = $pdo->prepare("UPDATE appointments SET status = 'rescheduled' WHERE child_id = ? AND vaccine_id IS NULL AND status IN ('scheduled', 'confirmed') AND id != ?");
                        $stmtClean->execute([$old_appt['child_id'], $new_appt_id]);
                    }

                    $pdo->commit();
                    $success = "Appointment rescheduled successfully to " . date('D, M d Y', strtotime($new_date)) . " at " . date('h:i A', strtotime($new_time)) . "!";
                    
                    // In-System Notification
                    if (function_exists('recordAppointmentNotification')) {
                        recordAppointmentNotification($pdo, $new_appt_id, 'rescheduled');
                    }
                } else {
                    $pdo->rollBack();
                    $error = "Failed to create new rescheduled appointment.";
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Failed to reschedule: " . $e->getMessage();
            }
        } else {
            $error = "Appointment record not found.";
        }
    }
}

// Handle Status Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appointment_id']) && isset($_POST['new_status']) && !isset($_POST['action'])) {
    csrf_guard();
    $appointment_id = (int)$_POST['appointment_id'];
    $new_status = $_POST['new_status'];
    
    $allowed_statuses = ['scheduled', 'confirmed', 'cancelled', 'arrived', 'no-show', 'completed', 'rescheduled'];
    if (in_array($new_status, $allowed_statuses)) {
        $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE id = ?");
        if ($stmt->execute([$new_status, $appointment_id])) {
            $success = "Appointment status updated to " . ucfirst($new_status) . ".";
        } else {
            $error = "Failed to update appointment status.";
        }
    }
}

// Search and Filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search_query  = trim($_GET['search'] ?? '');

$sql = "
    SELECT a.*, c.name as child_name, c.date_of_birth, u.name as guardian_name, u.phone as guardian_phone, v.name as vaccine_name 
    FROM appointments a 
    JOIN children c ON a.child_id = c.id 
    JOIN users u ON c.guardian_id = u.id
    LEFT JOIN vaccines v ON a.vaccine_id = v.id 
    WHERE 1=1
";
$params = [];

if ($status_filter !== 'all') {
    $sql .= " AND a.status = ?";
    $params[] = $status_filter;
}

if (!empty($search_query)) {
    $sql .= " AND (c.name LIKE ? OR u.name LIKE ? OR v.name LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$sql .= " ORDER BY a.appointment_date DESC, a.appointment_time ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$today_scheduled = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE AND status IN ('scheduled','confirmed')")->fetchColumn();
$today_arrived   = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE AND status = 'arrived'")->fetchColumn();
$today_completed = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE AND status = 'completed'")->fetchColumn();
$total_pending   = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'scheduled'")->fetchColumn();

// Fetch Children for Booking Modal
$stmt = $pdo->query("SELECT c.id, c.name, u.name as guardian_name FROM children c JOIN users u ON c.guardian_id = u.id ORDER BY c.name ASC");
$all_children = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Vaccines for Booking Modal
$stmt = $pdo->query("SELECT id, name FROM vaccines ORDER BY name ASC");
$all_vaccines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'nurse') AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_msg_count = (int)$stmt->fetchColumn();

$current_date = date('d M, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Appointments Queue — VaxiCare</title>
    <meta name="description" content="Manage pediatric vaccination appointments, patient check-ins, status workflow, and intelligent rescheduling.">
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
            gap: 7px;
            padding: 8px 16px;
            border-radius: 9px;
            font-size: 12.5px;
            font-weight: 700;
            text-decoration: none;
            color: #64748b;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        .premium-tab-btn:hover {
            color: #0f172a;
            background: rgba(255, 255, 255, 0.6);
        }

        .premium-tab-btn.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
        }

        .premium-tab-btn.active .tab-icon {
            transform: scale(1.1);
        }

        .premium-tab-btn .tab-icon {
            font-size: 12px;
            transition: transform 0.2s;
        }

        .status-pill-select {
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            font-size: 12px;
            font-weight: 600;
            background: #ffffff;
            color: #334155;
            outline: none;
            cursor: pointer;
            transition: all 0.15s;
        }

        .status-pill-select:focus {
            border-color: #2563eb;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(5px);
            z-index: 999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-overlay.active { 
            display: flex; 
        }

        .modal-box {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 540px;
            padding: 32px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: #f1f5f9;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
        }

        .modal-close:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .time-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11.5px;
            color: #64748b;
            font-weight: 600;
            background: #f1f5f9;
            padding: 2px 7px;
            border-radius: 6px;
            margin-top: 3px;
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
            <a href="appointments.php" class="sidebar-item active">
                <i class="fa-solid fa-calendar-check"></i> Appointments
                <?php if($today_scheduled > 0): ?>
                    <span class="badge-red" style="background:#2563eb;"><?= $today_scheduled ?></span>
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
                    <h1>Clinical Appointments</h1>
                    <div class="topbar-breadcrumbs" style="display:flex; align-items:center; gap:6px; margin:0;">
                        <span>Pediatric Care</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span class="current">Appointments Queue</span>
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
                <button type="button" class="nr-btn nr-btn-primary nr-btn-sm" onclick="openCreateModal()">
                    <i class="fa-solid fa-calendar-plus"></i> + Book Appointment
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

        <!-- ===== KPI METRIC CARDS (ENLARGED & PROMINENT) ===== -->
        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 26px;">
            <div class="stat-card card-blue" style="padding: 22px 24px; min-height: 110px;">
                <div class="stat-icon icon-blue" style="width: 52px; height: 52px; font-size: 22px; border-radius: 14px;"><i class="fa-solid fa-calendar-day"></i></div>
                <div class="stat-info">
                    <div class="stat-label" style="font-size: 12.5px; font-weight: 700;">Scheduled Today</div>
                    <div class="stat-value" style="font-size: 28px; line-height: 1.15;"><?= number_format($today_scheduled) ?></div>
                    <div class="stat-trend trend-neutral" style="font-size: 12px; margin-top: 4px;">
                        <i class="fa-regular fa-calendar-check"></i> Booked for today
                    </div>
                </div>
            </div>

            <div class="stat-card card-amber" style="padding: 22px 24px; min-height: 110px;">
                <div class="stat-icon icon-amber" style="width: 52px; height: 52px; font-size: 22px; border-radius: 14px;"><i class="fa-solid fa-user-check"></i></div>
                <div class="stat-info">
                    <div class="stat-label" style="font-size: 12.5px; font-weight: 700;">Arrived at Ward</div>
                    <div class="stat-value" style="font-size: 28px; line-height: 1.15;"><?= number_format($today_arrived) ?></div>
                    <div class="stat-trend" style="font-size: 12px; margin-top: 4px; color: #d97706; font-weight: 600;">
                        <i class="fa-solid fa-hourglass-half"></i> In waiting triage
                    </div>
                </div>
            </div>

            <div class="stat-card card-green" style="padding: 22px 24px; min-height: 110px;">
                <div class="stat-icon icon-green" style="width: 52px; height: 52px; font-size: 22px; border-radius: 14px;"><i class="fa-solid fa-circle-check"></i></div>
                <div class="stat-info">
                    <div class="stat-label" style="font-size: 12.5px; font-weight: 700;">Completed Today</div>
                    <div class="stat-value" style="font-size: 28px; line-height: 1.15;"><?= number_format($today_completed) ?></div>
                    <div class="stat-trend trend-up" style="font-size: 12px; margin-top: 4px;">
                        <i class="fa-solid fa-circle-check"></i> Doses administered
                    </div>
                </div>
            </div>

            <div class="stat-card card-purple" style="padding: 22px 24px; min-height: 110px;">
                <div class="stat-icon icon-purple" style="width: 52px; height: 52px; font-size: 22px; border-radius: 14px;"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div class="stat-info">
                    <div class="stat-label" style="font-size: 12.5px; font-weight: 700;">Total Pipeline</div>
                    <div class="stat-value" style="font-size: 28px; line-height: 1.15;"><?= number_format($total_pending) ?></div>
                    <div class="stat-trend trend-neutral" style="font-size: 12px; margin-top: 4px;">
                        <i class="fa-solid fa-layer-group"></i> Active queue
                    </div>
                </div>
            </div>
        </div>

        <!-- Success & Error Alerts -->
        <?php if($success): ?>
            <div style="background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 14px 20px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check" style="color:#10b981; font-size:16px;"></i>
                <div><?= htmlspecialchars($success) ?></div>
            </div>
        <?php endif; ?>
        <?php if($error): ?>
            <div style="background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: 14px 20px; border-radius: 12px; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-exclamation" style="color:#ef4444; font-size:16px;"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- ===== SEARCH & FILTER TOOLBAR ===== -->
        <div class="filter-bar">
            <form method="GET" action="" style="display:flex; align-items:center; gap:12px; flex:1; flex-wrap:wrap;">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="search-box-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="search" placeholder="Search by patient, guardian, vaccine lot..." value="<?= htmlspecialchars($search_query) ?>">
                </div>

                <?php if($status_filter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                <?php endif; ?>

                <button type="submit" class="nr-btn nr-btn-primary" style="padding: 11px 20px; font-size:13.5px;">
                    <i class="fa-solid fa-magnifying-glass"></i> Search
                </button>

                <?php if($search_query !== '' || $status_filter !== 'all'): ?>
                    <a href="appointments.php" class="nr-btn nr-btn-outline" style="padding: 11px 16px; font-size:13.5px; text-decoration:none;">
                        <i class="fa-solid fa-xmark"></i> Clear Filters
                    </a>
                <?php endif; ?>
            </form>

            <div class="premium-tab-track">
                <a href="appointments.php<?= $search_query ? '?search=' . urlencode($search_query) : '' ?>" class="premium-tab-btn <?= $status_filter === 'all' ? 'active' : '' ?>">
                    <i class="fa-solid fa-layer-group tab-icon" style="color:#64748b;"></i> All
                </a>
                <a href="appointments.php?status=scheduled<?= $search_query ? '&search=' . urlencode($search_query) : '' ?>" class="premium-tab-btn <?= $status_filter === 'scheduled' ? 'active' : '' ?>">
                    <i class="fa-regular fa-clock tab-icon" style="color:#2563eb;"></i> Scheduled
                </a>
                <a href="appointments.php?status=arrived<?= $search_query ? '&search=' . urlencode($search_query) : '' ?>" class="premium-tab-btn <?= $status_filter === 'arrived' ? 'active' : '' ?>">
                    <i class="fa-solid fa-user-check tab-icon" style="color:#d97706;"></i> Arrived
                </a>
                <a href="appointments.php?status=completed<?= $search_query ? '&search=' . urlencode($search_query) : '' ?>" class="premium-tab-btn <?= $status_filter === 'completed' ? 'active' : '' ?>">
                    <i class="fa-solid fa-circle-check tab-icon" style="color:#10b981;"></i> Completed
                </a>
                <a href="appointments.php?status=rescheduled<?= $search_query ? '&search=' . urlencode($search_query) : '' ?>" class="premium-tab-btn <?= $status_filter === 'rescheduled' ? 'active' : '' ?>">
                    <i class="fa-solid fa-clock-rotate-left tab-icon" style="color:#8b5cf6;"></i> Rescheduled
                </a>
            </div>
        </div>

        <!-- ===== APPOINTMENTS TABLE CARD ===== -->
        <div class="mar-card" style="padding: 20px;">
            <div class="mar-card-header" style="margin-bottom: 16px;">
                <h3 class="mar-card-title">
                    <i class="fa-solid fa-calendar-check" style="color:var(--nr-primary);"></i>
                    Pediatric Appointments Roster
                    <span style="font-size:12px; font-weight:600; color:#64748b; margin-left:8px;">(<?= count($appointments) ?> records)</span>
                </h3>
            </div>

            <div style="overflow-x:auto;">
                <table class="mar-table">
                    <thead>
                        <tr>
                            <th>Infant / Patient</th>
                            <th>Parent / Guardian</th>
                            <th>Schedule Date &amp; Slot</th>
                            <th>Vaccine Requested</th>
                            <th>Status</th>
                            <th>Update Workflow</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($appointments)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; color: var(--nr-text-muted); padding: 48px 16px;">
                                    <div style="width:48px; height:48px; border-radius:50%; background:#f1f5f9; display:inline-flex; align-items:center; justify-content:center; margin-bottom:10px; color:#94a3b8; font-size:20px;">
                                        <i class="fa-solid fa-calendar-xmark"></i>
                                    </div>
                                    <div style="font-weight:700; color:#334155; font-size:14px;">No appointments matching criteria</div>
                                    <div style="font-size:12px; color:#94a3b8; margin-top:4px;">Try modifying search terms or book a new appointment</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($appointments as $app): ?>
                                <tr>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:12px;">
                                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($app['child_name']) ?>&background=eff6ff&color=1d4ed8&bold=true" style="width:34px; height:34px; border-radius:50%; border:1px solid #dbeafe;">
                                            <div>
                                                <a href="view_patient.php?id=<?= $app['child_id'] ?>" style="font-weight:700; color:#0f172a; text-decoration:none; font-size:13.5px; display:block;">
                                                    <?= htmlspecialchars($app['child_name']) ?>
                                                </a>
                                                <span style="font-size:11px; color:#64748b; font-weight:600;">ID #C-<?= str_pad($app['child_id'], 4, '0', STR_PAD_LEFT) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; color:#334155; font-size:13px;"><?= htmlspecialchars($app['guardian_name']) ?></div>
                                        <a href="tel:<?= htmlspecialchars($app['guardian_phone']) ?>" style="font-size:11.5px; color:#2563eb; text-decoration:none;">
                                            <i class="fa-solid fa-phone" style="font-size:10px;"></i> <?= htmlspecialchars($app['guardian_phone']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div style="font-weight:700; color:#0f172a; font-size:13px;">
                                            <?= date('d M, Y', strtotime($app['appointment_date'])) ?>
                                        </div>
                                        <div class="time-badge">
                                            <i class="fa-regular fa-clock"></i> <?= date('h:i A', strtotime($app['appointment_time'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($app['vaccine_name']): ?>
                                            <span style="background: #eff6ff; color: #1d4ed8; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; border: 1px solid #dbeafe; display: inline-flex; align-items: center; gap: 5px;">
                                                <i class="fa-solid fa-syringe" style="font-size:11px;"></i> <?= htmlspecialchars($app['vaccine_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 12px;">Standard Clinical Check</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($app['status'] === 'completed'): ?>
                                            <span class="badge-status bg-green-light"><i class="fa-solid fa-check"></i> Completed</span>
                                        <?php elseif ($app['status'] === 'arrived'): ?>
                                            <span class="badge-status bg-amber-light"><i class="fa-solid fa-bell"></i> Arrived</span>
                                        <?php elseif ($app['status'] === 'scheduled' || $app['status'] === 'confirmed'): ?>
                                            <span class="badge-status bg-blue-light"><i class="fa-solid fa-clock"></i> <?= ucfirst($app['status']) ?></span>
                                        <?php elseif ($app['status'] === 'rescheduled'): ?>
                                            <span class="badge-status bg-purple-light"><i class="fa-solid fa-clock-rotate-left"></i> Rescheduled</span>
                                        <?php elseif ($app['status'] === 'cancelled' || $app['status'] === 'no-show'): ?>
                                            <span class="badge-status bg-red-light"><i class="fa-solid fa-xmark"></i> <?= ucfirst($app['status']) ?></span>
                                        <?php else: ?>
                                            <span class="badge-status bg-blue-light"><?= ucfirst(htmlspecialchars($app['status'])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <!-- Quick Status Form -->
                                        <form method="POST" style="display: flex; gap: 6px; align-items: center;">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="appointment_id" value="<?= $app['id'] ?>">
                                            <select name="new_status" class="status-pill-select">
                                                <option value="scheduled" <?= $app['status'] == 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                                <option value="confirmed" <?= $app['status'] == 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                                <option value="arrived" <?= $app['status'] == 'arrived' ? 'selected' : '' ?>>Arrived</option>
                                                <option value="completed" <?= $app['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                                                <option value="rescheduled" <?= $app['status'] == 'rescheduled' ? 'selected' : '' ?>>Rescheduled</option>
                                                <option value="no-show" <?= $app['status'] == 'no-show' ? 'selected' : '' ?>>No-Show</option>
                                                <option value="cancelled" <?= $app['status'] == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                            </select>
                                            <button type="submit" class="nr-btn nr-btn-sm nr-btn-outline" style="padding: 5px 9px;" title="Save Status">
                                                <i class="fa-solid fa-check"></i>
                                            </button>
                                        </form>
                                    </td>
                                    <td style="text-align:right;">
                                        <div style="display:inline-flex; align-items:center; gap:6px;">
                                            <button type="button" onclick="openRescheduleModal(<?= $app['id'] ?>, '<?= htmlspecialchars(addslashes($app['child_name'])) ?>', '<?= $app['appointment_date'] ?>')" class="nr-btn nr-btn-sm nr-btn-outline" style="padding: 5px 10px; font-size:12px;">
                                                <i class="fa-solid fa-calendar-days"></i> Reschedule
                                            </button>
                                            <a href="view_patient.php?id=<?= $app['child_id'] ?>" class="nr-btn nr-btn-sm nr-btn-primary" style="padding: 5px 9px;" title="View Infant Record">
                                                <i class="fa-solid fa-arrow-right"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- ===== CREATE APPOINTMENT MODAL ===== -->
    <div class="modal-overlay" id="createModal">
        <div class="modal-box">
            <button class="modal-close" onclick="closeCreateModal()"><i class="fa-solid fa-xmark"></i></button>
            <h3 style="font-size: 18px; font-weight: 800; color: #0F172A; margin-bottom: 4px;">Book Vaccine Appointment</h3>
            <p style="font-size: 13px; color: #64748B; margin-bottom: 20px;">Schedule an infant's visit with slot allotment.</p>

            <form method="POST" action="">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="create_booking">

                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px;">Select Pediatric Infant *</label>
                        <select name="child_id" required class="input-control">
                            <option value="">-- Choose Enrolled Patient --</option>
                            <?php foreach($all_children as $ch): ?>
                                <option value="<?= $ch['id'] ?>"><?= htmlspecialchars($ch['name']) ?> (Guardian: <?= htmlspecialchars($ch['guardian_name']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px;">Select Vaccine Dose *</label>
                        <select name="vaccine_id" required class="input-control">
                            <option value="">-- Choose Vaccine --</option>
                            <?php foreach($all_vaccines as $vc): ?>
                                <option value="<?= $vc['id'] ?>"><?= htmlspecialchars($vc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px;">Date *</label>
                            <input type="date" name="appointment_date" min="<?= date('Y-m-d') ?>" required class="input-control">
                        </div>

                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px;">Time Slot *</label>
                            <select name="appointment_time" required class="input-control">
                                <option value="08:30:00">08:30 AM (Morning Session)</option>
                                <option value="10:00:00" selected>10:00 AM (Mid-Morning)</option>
                                <option value="11:30:00">11:30 AM (Late Morning)</option>
                                <option value="14:00:00">02:00 PM (Afternoon Clinic)</option>
                                <option value="15:30:00">03:30 PM (Late Afternoon)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px;">Clinical Notes / Instructions</label>
                        <input type="text" name="notes" placeholder="e.g. 6-week routine immunization checkup" class="input-control">
                    </div>

                    <div style="margin-top: 10px; display: flex; gap: 12px; justify-content: flex-end;">
                        <button type="button" onclick="closeCreateModal()" class="nr-btn nr-btn-outline">Cancel</button>
                        <button type="submit" class="nr-btn nr-btn-primary">Confirm Booking</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== RESCHEDULE MODAL ===== -->
    <div class="modal-overlay" id="nurseRescheduleModal">
        <div class="modal-box">
            <button class="modal-close" onclick="closeRescheduleModal()"><i class="fa-solid fa-xmark"></i></button>
            <h3 style="font-size: 18px; font-weight: 800; color: #0F172A; margin-bottom: 4px;">Smart Reschedule Booking</h3>
            <p style="font-size: 13px; color: #64748B; margin-bottom: 16px;" id="rescheduleChildName">Shift appointment to a free open day.</p>

            <!-- Smart Next Free Day Banner -->
            <div id="smartDayBanner" style="background: linear-gradient(135deg,#eff6ff,#ecfdf5); border: 1px solid #bfdbfe; border-radius: 12px; padding: 14px 16px; margin-bottom: 18px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:36px; height:36px; border-radius:10px; background:#2563eb; color:#fff; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                    </div>
                    <div>
                        <div style="font-size:11px; font-weight:800; color:#2563eb; text-transform:uppercase; letter-spacing:0.5px;">Recommended Free Slot</div>
                        <div style="font-size:13px; font-weight:800; color:#0F172A;"><span id="nextFreeDayLabel">—</span></div>
                    </div>
                </div>
                <button type="button" onclick="applyNextFreeDay()" class="nr-btn nr-btn-sm nr-btn-primary" style="padding:6px 14px;">
                    Use This Date <i class="fa-solid fa-arrow-right"></i>
                </button>
            </div>

            <form method="POST" action="">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reschedule">
                <input type="hidden" name="appointment_id" id="reschedule_appointment_id" value="">
                <input type="hidden" name="use_smart_date" id="use_smart_date_flag" value="0">

                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px;">Select New Date *</label>
                        <input type="date" name="new_date" id="reschedule_new_date" min="<?= date('Y-m-d', strtotime('tomorrow')) ?>" required class="input-control">
                        <span style="font-size: 11.5px; color: #64748B; margin-top: 4px; display: block;">Or click "Use This Date" to auto-fill the next open clinic weekday.</span>
                    </div>

                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px;">Time Slot *</label>
                        <select name="new_time" required class="input-control">
                            <option value="08:30:00">08:30 AM (Morning Session)</option>
                            <option value="10:00:00" selected>10:00 AM (Mid-Morning)</option>
                            <option value="11:30:00">11:30 AM (Late Morning)</option>
                            <option value="14:00:00">02:00 PM (Afternoon Clinic)</option>
                            <option value="15:30:00">03:30 PM (Late Afternoon)</option>
                        </select>
                    </div>

                    <div style="margin-top: 10px; display: flex; gap: 12px; justify-content: flex-end;">
                        <button type="button" onclick="closeRescheduleModal()" class="nr-btn nr-btn-outline">Cancel</button>
                        <button type="submit" class="nr-btn nr-btn-primary">Save Reschedule</button>
                    </div>
                </div>
            </form>
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

        const bookedDates = <?= json_encode(array_column($appointments, 'appointment_date')) ?>;

        function getNextFreeDay() {
            let d = new Date();
            d.setDate(d.getDate() + 1);
            for (let i = 0; i < 30; i++) {
                const dayOfWeek = d.getDay();
                if (dayOfWeek >= 1 && dayOfWeek <= 5) {
                    const iso = d.toISOString().split('T')[0];
                    const count = bookedDates.filter(bd => bd === iso).length;
                    if (count < 8) return iso;
                }
                d.setDate(d.getDate() + 1);
            }
            return null;
        }

        let _nextFreeDay = getNextFreeDay();

        function openCreateModal() {
            document.getElementById('createModal').classList.add('active');
        }
        function closeCreateModal() {
            document.getElementById('createModal').classList.remove('active');
        }

        function openRescheduleModal(id, childName, currentDate) {
            document.getElementById('reschedule_appointment_id').value = id;
            document.getElementById('reschedule_new_date').value = currentDate;
            document.getElementById('use_smart_date_flag').value = '0';
            document.getElementById('rescheduleChildName').innerText = 'Shifting booking for: ' + childName;

            if (_nextFreeDay) {
                const opts = { weekday:'long', year:'numeric', month:'long', day:'numeric' };
                const label = new Date(_nextFreeDay + 'T12:00:00').toLocaleDateString('en-GB', opts);
                document.getElementById('nextFreeDayLabel').innerText = label;
                document.getElementById('smartDayBanner').style.display = 'flex';
            } else {
                document.getElementById('smartDayBanner').style.display = 'none';
            }

            document.getElementById('nurseRescheduleModal').classList.add('active');
        }

        function closeRescheduleModal() {
            document.getElementById('nurseRescheduleModal').classList.remove('active');
        }

        function applyNextFreeDay() {
            if (_nextFreeDay) {
                document.getElementById('reschedule_new_date').value = _nextFreeDay;
                document.getElementById('use_smart_date_flag').value = '1';
                const df = document.getElementById('reschedule_new_date');
                df.style.borderColor = '#2563eb';
                df.style.boxShadow = '0 0 0 3px rgba(37,99,235,0.15)';
            }
        }
    </script>
    <script src="../js/notifications.js"></script>
</body>
</html>
