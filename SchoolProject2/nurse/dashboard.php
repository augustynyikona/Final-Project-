<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: ../auth/login.php");
    exit;
}

$nurse_name = $_SESSION['name'] ?? 'Bupe';

// Fetch user profile from database
$stmt = $pdo->prepare("SELECT name, profile_pic FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user_profile && !empty($user_profile['name'])) {
    $nurse_name = $user_profile['name'];
}

$profile_pic = ($user_profile && !empty($user_profile['profile_pic'])) 
    ? '../uploads/' . htmlspecialchars($user_profile['profile_pic']) 
    : "https://ui-avatars.com/api/?name=" . urlencode($nurse_name) . "&background=2563eb&color=fff&bold=true";

// Dashboard Metrics
$stmt = $pdo->query("SELECT COUNT(*) FROM children");
$total_children = (int)$stmt->fetchColumn();

// Total Vaccinations Completed Today
$stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE AND status = 'completed'");
$completed_today = (int)$stmt->fetchColumn();

// Pending Vaccinations Today (Exclude completed, cancelled, and rescheduled visits)
$stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE AND status IN ('scheduled', 'confirmed', 'arrived')");
$pending_today = (int)$stmt->fetchColumn();
$total_today = $completed_today + $pending_today;

// Fetch Today's Active Appointments (Old rescheduled or cancelled visits are automatically removed)
$stmt = $pdo->prepare("
    SELECT a.*, c.name as child_name, c.date_of_birth, u.phone as guardian_phone, u.name as guardian_name, v.name as vaccine_name 
    FROM appointments a 
    JOIN children c ON a.child_id = c.id 
    JOIN users u ON c.guardian_id = u.id
    LEFT JOIN vaccines v ON a.vaccine_id = v.id
    WHERE a.appointment_date = CURRENT_DATE
      AND a.status IN ('scheduled', 'confirmed', 'arrived')
    ORDER BY a.appointment_time ASC
");
$stmt->execute();
$today_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Pending Doctor Tasks (Prescriptions & Orders)
$stmt = $pdo->prepare("
    SELECT t.*, c.name as child_name, d.name as doctor_name
    FROM nurse_tasks t
    JOIN children c ON t.child_id = c.id
    JOIN users d ON t.doctor_id = d.id
    WHERE t.status = 'pending'
    ORDER BY t.created_at ASC
");
$stmt->execute();
$pending_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle task completion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_task') {
    $task_id = (int)$_POST['task_id'];
    $stmt = $pdo->prepare("UPDATE nurse_tasks SET status = 'completed', updated_at = NOW() WHERE id = ?");
    $stmt->execute([$task_id]);
    header("Location: dashboard.php?task_completed=1");
    exit;
}

function time_elapsed_string($datetime, $full = false) {
    if (!$datetime) return 'Never';
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array('y' => 'year', 'm' => 'month', 'w' => 'week', 'd' => 'day', 'h' => 'hour', 'i' => 'minute', 's' => 'second');
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }
    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

// Time since last patient added
$stmt = $pdo->query("SELECT created_at FROM children ORDER BY created_at DESC LIMIT 1");
$last_patient_time = $stmt->fetchColumn();
$active_patients_ago = time_elapsed_string($last_patient_time);

// Time since last vaccination completed
$stmt = $pdo->query("SELECT created_at FROM appointments WHERE status = 'completed' ORDER BY created_at DESC LIMIT 1");
$last_vaccine_time = $stmt->fetchColumn();
$vaccinations_ago = $last_vaccine_time ? time_elapsed_string($last_vaccine_time) : 'No records today';

function calculateAge($dob) {
    if (!$dob) return 'N/A';
    $bday = new DateTime($dob);
    $today = new DateTime('today');
    $diff = $today->diff($bday);
    if ($diff->y > 0) return $diff->y . 'y ' . $diff->m . 'm';
    if ($diff->m > 0) return $diff->m . 'm ' . $diff->d . 'd';
    return $diff->d . ' days';
}

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'nurse') AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_msg_count = (int)$stmt->fetchColumn();

$current_date = date('d M, Y');
$completion_rate = $total_today > 0 ? round(($completed_today / $total_today) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nurse Clinical Dashboard — VaxiCare</title>
    <meta name="description" content="Pediatric immunization workflow, appointments, triage vitals, and physician tasks for the Nursing Station.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/nurse_theme.css">
    <style>
        .task-alert-banner {
            background: linear-gradient(135deg, #eff6ff 0%, #e0f2fe 100%);
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .task-alert-banner .content {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #1e40af;
            font-size: 13.5px;
            font-weight: 600;
        }
        .task-alert-banner .badge {
            background: #2563eb;
            color: #ffffff;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }
        .progress-bar-container {
            width: 100%;
            height: 7px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 8px;
        }
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #059669);
            border-radius: 10px;
            transition: width 0.4s ease;
        }
        .dose-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #eff6ff;
            color: #1d4ed8;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid #dbeafe;
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
            <a href="dashboard.php" class="sidebar-item active">
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

    <!-- ===== MAIN CLINICAL CONTENT ===== -->
    <main class="app-main">
        
        <!-- ===== TOPBAR (MATCHING OTHER DEPARTMENTS' CANONICAL HIERARCHY) ===== -->
        <header class="app-topbar">
            <div style="display:flex; align-items:center;">
                <button type="button" class="mobile-nav-toggle" onclick="toggleSidebar(true)" aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-left" style="display:flex; flex-direction:row; align-items:center; gap:14px; flex-wrap:wrap;">
                    <h1>Nursing Station Overview</h1>
                    <div class="topbar-breadcrumbs" style="display:flex; align-items:center; gap:6px; margin:0;">
                        <span>Clinical Overview</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span class="current">Nurse Dashboard</span>
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
                <a href="register_patient.php" class="nr-btn nr-btn-primary nr-btn-sm" style="text-decoration:none;">
                    <i class="fa-solid fa-user-plus"></i> Register Infant
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

        <!-- Doctor Task Alert Banner (If pending tasks exist) -->
        <?php if(!empty($pending_tasks)): ?>
            <div class="task-alert-banner">
                <div class="content">
                    <i class="fa-solid fa-clipboard-list" style="font-size:18px;"></i>
                    <span>You have <strong><?= count($pending_tasks) ?> pending clinical task(s)</strong> assigned by attending physicians requiring nursing execution.</span>
                </div>
                <a href="#doctorTasksSection" class="nr-btn nr-btn-sm nr-btn-primary" style="white-space:nowrap;">
                    <i class="fa-solid fa-arrow-down"></i> Review Tasks
                </a>
            </div>
        <?php endif; ?>

        <!-- ===== KPI SUMMARY CARDS ===== -->
        <div class="stats-grid">
            <div class="stat-card card-blue">
                <div class="stat-icon icon-blue">
                    <i class="fa-solid fa-children"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Registered Children</div>
                    <div class="stat-value"><?= number_format($total_children) ?></div>
                    <div class="stat-trend trend-neutral">
                        <i class="fa-regular fa-clock"></i> Last added <?= $active_patients_ago ?>
                    </div>
                </div>
            </div>
            
            <div class="stat-card card-green">
                <div class="stat-icon icon-green">
                    <i class="fa-solid fa-syringe"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Vaccinations Given</div>
                    <div class="stat-value"><?= number_format($completed_today) ?></div>
                    <div class="stat-trend trend-up">
                        <i class="fa-solid fa-circle-check"></i> Completed today
                    </div>
                </div>
            </div>
            
            <div class="stat-card card-amber">
                <div class="stat-icon icon-amber">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Pending Today</div>
                    <div class="stat-value"><?= number_format($pending_today) ?></div>
                    <div class="stat-trend <?= $pending_today > 0 ? 'trend-down' : 'trend-up' ?>">
                        <i class="fa-solid fa-bell"></i> <?= $pending_today > 0 ? 'Awaiting administration' : 'All cleared' ?>
                    </div>
                </div>
            </div>
            
            <div class="stat-card card-purple">
                <div class="stat-icon icon-purple">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Total Appointments</div>
                    <div class="stat-value"><?= number_format($total_today) ?></div>
                    <div class="stat-trend trend-neutral">
                        <i class="fa-solid fa-percent"></i> <?= $completion_rate ?>% daily completion
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== 2-COLUMN MAIN LAYOUT ===== -->
        <div style="display:grid; grid-template-columns: 2.2fr 1fr; gap: 24px; margin-bottom: 24px;">
            
            <!-- LEFT: Today's Appointments -->
            <div class="mar-card" style="margin-bottom:0;">
                <div class="mar-card-header">
                    <div>
                        <h3 class="mar-card-title">
                            <i class="fa-solid fa-calendar-day" style="color:var(--nr-primary);"></i>
                            Today's Immunization Queue
                        </h3>
                        <p style="font-size:12px; color:var(--nr-text-muted); margin-top:2px;">Scheduled pediatric visits and vaccine administrations for <?= $current_date ?></p>
                    </div>
                    <a href="appointments.php" class="nr-btn nr-btn-sm nr-btn-outline" style="font-size:12px;">
                        Full Schedule <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
                
                <div style="overflow-x:auto;">
                    <table class="mar-table">
                        <thead>
                            <tr>
                                <th>Infant / Patient</th>
                                <th>Age</th>
                                <th>Vaccine</th>
                                <th>Slot Time</th>
                                <th>Guardian</th>
                                <th>Status</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($today_appointments)): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; color: var(--nr-text-muted); padding: 48px 16px;">
                                        <div style="width:48px; height:48px; border-radius:50%; background:#f1f5f9; display:inline-flex; align-items:center; justify-content:center; margin-bottom:10px; color:#94a3b8; font-size:20px;">
                                            <i class="fa-solid fa-calendar-xmark"></i>
                                        </div>
                                        <div style="font-weight:700; color:#334155; font-size:14px;">No appointments scheduled for today</div>
                                        <div style="font-size:12px; color:#94a3b8; margin-top:4px;">New walk-ins can be registered or triaged anytime</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($today_appointments as $app): ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($app['child_name']) ?>&background=eff6ff&color=1d4ed8&bold=true" style="width: 34px; height: 34px; border-radius: 50%; border: 1px solid #dbeafe;">
                                                <div>
                                                    <a href="view_patient.php?id=<?= $app['child_id'] ?>" style="font-weight: 700; color: #0f172a; text-decoration: none; font-size:13.5px; display:block;">
                                                        <?= htmlspecialchars($app['child_name']) ?>
                                                    </a>
                                                    <span style="font-size:11px; color:#64748b;">ID #C-<?= str_pad($app['child_id'], 4, '0', STR_PAD_LEFT) ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="font-weight:600; font-size:12.5px; color:#475569;">
                                            <?= calculateAge($app['date_of_birth']) ?>
                                        </td>
                                        <td>
                                            <?php if ($app['vaccine_name']): ?>
                                                <span class="dose-pill">
                                                    <i class="fa-solid fa-syringe" style="font-size:11px;"></i>
                                                    <?= htmlspecialchars($app['vaccine_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size:12px;">Standard Review</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-weight: 600; color:#1e293b; font-size:13px;">
                                            <i class="fa-regular fa-clock" style="color:#94a3b8; margin-right:4px;"></i>
                                            <?= date('h:i A', strtotime($app['appointment_time'])) ?>
                                        </td>
                                        <td>
                                            <div style="font-size:12.5px; font-weight:600; color:#334155;"><?= htmlspecialchars($app['guardian_name'] ?? 'Parent') ?></div>
                                            <div style="font-size:11px; color:#64748b;"><?= htmlspecialchars($app['guardian_phone'] ?? '-') ?></div>
                                        </td>
                                        <td>
                                            <?php if ($app['status'] === 'completed'): ?>
                                                <span class="badge-status bg-green-light"><i class="fa-solid fa-check"></i> Completed</span>
                                            <?php elseif ($app['status'] === 'scheduled'): ?>
                                                <span class="badge-status bg-blue-light"><i class="fa-solid fa-clock"></i> Scheduled</span>
                                            <?php elseif ($app['status'] === 'cancelled'): ?>
                                                <span class="badge-status bg-red-light"><i class="fa-solid fa-xmark"></i> Cancelled</span>
                                            <?php else: ?>
                                                <span class="badge-status bg-amber-light"><?= ucfirst(htmlspecialchars($app['status'])) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <a href="view_patient.php?id=<?= $app['child_id'] ?>" class="nr-btn nr-btn-sm nr-btn-outline" title="Examine Patient Record" style="padding:4px 8px;">
                                                <i class="fa-solid fa-arrow-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- RIGHT: Station Controls & Quick Links -->
            <div style="display:flex; flex-direction:column; gap:20px;">
                
                <!-- Station Day Progress -->
                <div class="mar-card" style="margin-bottom:0;">
                    <h3 class="mar-card-title" style="margin-bottom:12px;">
                        <i class="fa-solid fa-chart-pie" style="color:var(--nr-emerald);"></i>
                        Ward Flow Status
                    </h3>
                    <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:600; color:#334155;">
                        <span>Immunization Target</span>
                        <span style="color:#059669; font-weight:700;"><?= $completed_today ?> / <?= $total_today ?> (<?= $completion_rate ?>%)</span>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" style="width: <?= $completion_rate ?>%;"></div>
                    </div>
                    
                    <div style="margin-top:16px; padding:12px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0; font-size:12px; color:#475569; display:flex; align-items:center; gap:10px;">
                        <i class="fa-solid fa-snowflake" style="color:#0284c7; font-size:16px;"></i>
                        <div>
                            <strong style="color:#0f172a; display:block;">Cold-Chain Status: Nominal</strong>
                            Fridge monitored at +4.2&deg;C WHO target.
                        </div>
                    </div>
                </div>

                <!-- Quick Action Hub -->
                <div class="mar-card" style="margin-bottom:0;">
                    <div class="mar-card-header" style="margin-bottom:14px;">
                        <h3 class="mar-card-title">
                            <i class="fa-solid fa-bolt" style="color:#f59e0b;"></i>
                            Quick Nursing Tasks
                        </h3>
                    </div>

                    <a href="triage.php" class="quick-action-link">
                        <div style="background: #eff6ff; color: #2563eb; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-right: 14px; font-size: 18px; flex-shrink: 0;">
                            <i class="fa-solid fa-heart-pulse"></i>
                        </div>
                        <div>
                            <h4 style="margin: 0 0 2px 0; font-size: 13.5px; font-weight: 700; color: #0f172a;">Log Vitals (Triage)</h4>
                            <p style="margin: 0; font-size: 11.5px; color: #64748b;">Record temperature, weight, MUAC</p>
                        </div>
                    </a>

                    <a href="register_patient.php" class="quick-action-link">
                        <div style="background: #ecfdf5; color: #10b981; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-right: 14px; font-size: 18px; flex-shrink: 0;">
                            <i class="fa-solid fa-user-plus"></i>
                        </div>
                        <div>
                            <h4 style="margin: 0 0 2px 0; font-size: 13.5px; font-weight: 700; color: #0f172a;">Register New Child</h4>
                            <p style="margin: 0; font-size: 11.5px; color: #64748b;">Enroll infant &amp; link guardian</p>
                        </div>
                    </a>

                    <a href="requisition.php" class="quick-action-link">
                        <div style="background: #fffbeb; color: #d97706; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-right: 14px; font-size: 18px; flex-shrink: 0;">
                            <i class="fa-solid fa-boxes-stacked"></i>
                        </div>
                        <div>
                            <h4 style="margin: 0 0 2px 0; font-size: 13.5px; font-weight: 700; color: #0f172a;">Request Vaccines</h4>
                            <p style="margin: 0; font-size: 11.5px; color: #64748b;">Order lots from Pharmacy station</p>
                        </div>
                    </a>

                    <a href="sms_reminders.php" class="quick-action-link" style="margin-bottom:0;">
                        <div style="background: #f5f3ff; color: #8b5cf6; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-right: 14px; font-size: 18px; flex-shrink: 0;">
                            <i class="fa-solid fa-comment-sms"></i>
                        </div>
                        <div>
                            <h4 style="margin: 0 0 2px 0; font-size: 13.5px; font-weight: 700; color: #0f172a;">Dispatch Reminders</h4>
                            <p style="margin: 0; font-size: 11.5px; color: #64748b;">Broadcast SMS to parents due soon</p>
                        </div>
                    </a>
                </div>

            </div>
        </div>

        <!-- ===== BOTTOM: PENDING DOCTOR TASKS & CLINICAL INSTRUCTIONS ===== -->
        <div class="mar-card" id="doctorTasksSection">
            <div class="mar-card-header">
                <div>
                    <h3 class="mar-card-title">
                        <i class="fa-solid fa-user-doctor" style="color:#0284c7;"></i>
                        Physician Instructions &amp; Pending Ward Tasks
                    </h3>
                    <p style="font-size:12px; color:var(--nr-text-muted); margin-top:2px;">Special instructions, medication orders, and observation requests assigned to nursing staff</p>
                </div>
                <?php if(!empty($pending_tasks)): ?>
                    <span class="badge-red" style="font-size:11px; padding:4px 10px;"><?= count($pending_tasks) ?> Incomplete</span>
                <?php endif; ?>
            </div>
            
            <div style="overflow-x:auto;">
                <table class="mar-table">
                    <thead>
                        <tr>
                            <th>Patient / Infant</th>
                            <th>Attending Physician</th>
                            <th>Clinical Instruction / Task</th>
                            <th>Logged At</th>
                            <th style="text-align:right;">Nurse Execution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pending_tasks)): ?>
                            <tr>
                                <td colspan="5" style="text-align:center; color: var(--nr-text-muted); padding: 36px 16px;">
                                    <div style="width:40px; height:40px; border-radius:50%; background:#ecfdf5; display:inline-flex; align-items:center; justify-content:center; margin-bottom:8px; color:#10b981; font-size:18px;">
                                        <i class="fa-solid fa-circle-check"></i>
                                    </div>
                                    <div style="font-weight:700; color:#1e293b; font-size:13.5px;">All Physician Tasks Completed</div>
                                    <div style="font-size:12px; color:#64748b; margin-top:3px;">No outstanding doctor prescriptions or observations pending execution.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pending_tasks as $task): ?>
                                <tr>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <div style="width:32px; height:32px; border-radius:50%; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px;">
                                                <i class="fa-solid fa-baby"></i>
                                            </div>
                                            <div>
                                                <a href="view_patient.php?id=<?= $task['child_id'] ?>" style="font-weight: 700; color: #0f172a; text-decoration: none; font-size:13.5px;">
                                                    <?= htmlspecialchars($task['child_name']) ?>
                                                </a>
                                                <div style="font-size:11px; color:#64748b;">Record #<?= $task['child_id'] ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="display:inline-flex; align-items:center; gap:6px; font-weight:600; color:#334155; font-size:13px;">
                                            <i class="fa-solid fa-stethoscope" style="color:#0284c7; font-size:11px;"></i>
                                            <?= htmlspecialchars($task['doctor_name']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="background:#fff1f2; border:1px solid #ffe4e6; padding:8px 12px; border-radius:8px; color:#9f1239; font-size:12.5px; font-weight:500; max-width:420px; line-height:1.4;">
                                            <?= nl2br(htmlspecialchars($task['task_description'])) ?>
                                        </div>
                                    </td>
                                    <td style="font-size:12px; color:#64748b; white-space:nowrap;">
                                        <i class="fa-regular fa-clock" style="margin-right:4px;"></i>
                                        <?= date('h:i A', strtotime($task['created_at'])) ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <form method="POST" action="" onsubmit="return confirm('Confirm that this clinical order has been completed?');" style="display:inline;">
                                            <input type="hidden" name="action" value="complete_task">
                                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                            <button type="submit" class="nr-btn nr-btn-sm nr-btn-emerald">
                                                <i class="fa-solid fa-check"></i> Mark Executed
                                            </button>
                                        </form>
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
    </script>
    <script src="../js/notifications.js"></script>
</body>
</html>
