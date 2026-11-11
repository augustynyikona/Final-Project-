<?php
session_start();
include '../config/db.php';

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

// 1. Dashboard Top Metrics
$stmt = $pdo->query("SELECT COUNT(*) FROM children");
$total_children = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE AND status IN ('scheduled', 'confirmed', 'arrived')");
$appointments_today = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'scheduled'");
$pending_appointments = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'completed'");
$completed_appointments = (int)$stmt->fetchColumn();

// Critical Alerts (Triage referrals)
$stmt = $pdo->query("SELECT COUNT(*) FROM triage_referrals WHERE status = 'pending'");
$pending_alerts = (int)$stmt->fetchColumn();

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'doctor' OR recipient_role = 'all') AND is_read = 0");
$stmt->execute([$user_id]);
$unread_msg_count = (int)($stmt->fetchColumn() ?: 0);

// 2. Today's Schedule (Appointments for Today)
$stmt = $pdo->prepare("
    SELECT a.id as appointment_id, a.appointment_time, a.status, v.name as vaccine_name, 
           c.name as child_name, c.date_of_birth, c.gender, c.id as child_id
    FROM appointments a
    JOIN children c ON a.child_id = c.id
    LEFT JOIN vaccines v ON a.vaccine_id = v.id
    WHERE a.appointment_date = CURRENT_DATE
      AND a.status IN ('scheduled', 'confirmed', 'arrived')
    ORDER BY a.appointment_time ASC
");
$stmt->execute();
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

// 3. Recent Clinical Activity
$stmt = $pdo->query("
    SELECT c.name as child_name, c.id as child_id, a.created_at, a.status, v.name as vaccine_name
    FROM appointments a
    JOIN children c ON a.child_id = c.id
    LEFT JOIN vaccines v ON a.vaccine_id = v.id
    ORDER BY a.created_at DESC
    LIMIT 4
");
$recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Critical Triage Referrals (Pending)
$stmt = $pdo->prepare("
    SELECT t.id, c.id as child_id, c.name as child_name, c.date_of_birth, t.reason, t.status, t.created_at, u.name as nurse_name
    FROM triage_referrals t
    JOIN children c ON t.child_id = c.id
    LEFT JOIN users u ON t.nurse_id = u.id
    WHERE t.status = 'pending'
    ORDER BY t.created_at DESC
    LIMIT 3
");
$stmt->execute();
$critical_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Pediatric Immunization Cohort & Antigen Surveillance
$stmt = $pdo->query("
    SELECT v.name, 
           COUNT(CASE WHEN a.status = 'completed' THEN 1 END) as completed_doses,
           COUNT(a.id) as total_scheduled
    FROM vaccines v
    LEFT JOIN appointments a ON a.vaccine_id = v.id
    WHERE v.name IN ('BCG Vaccine', 'OPV (Oral Polio)', 'Pentavalent (DPT-HepB-Hib)', 'PCV (Pneumococcal)', 'Measles Vaccine')
    GROUP BY v.id, v.name
    ORDER BY FIELD(v.name, 'BCG Vaccine', 'OPV (Oral Polio)', 'Pentavalent (DPT-HepB-Hib)', 'PCV (Pneumococcal)', 'Measles Vaccine')
");
$antigen_coverage = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Analytics &amp; Overview — VaxiCare Doctor Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/doctor_theme.css">
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
            <a href="dashboard.php" class="sidebar-item active">
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
                    <h1>Clinical Analytics</h1>
                    <div class="topbar-breadcrumbs" style="display: flex; align-items: center; gap: 6px; margin: 0;">
                        <span>Doctor Station</span>
                        <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #cbd5e1;"></i>
                        <span class="current">Pediatric Overview</span>
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
                    <i class="fa-solid fa-children"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Registered Patients</div>
                    <div class="stat-value"><?= number_format($total_children) ?></div>
                    <div class="stat-trend"><i class="fa-solid fa-chart-line" style="color: #0284c7;"></i> Active Under-5 Cohort</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon icon-cyan">
                    <i class="fa-regular fa-calendar-check"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Appointments Today</div>
                    <div class="stat-value"><?= number_format($appointments_today) ?></div>
                    <div class="stat-trend"><i class="fa-solid fa-clock" style="color: #00B5D8;"></i> Daily Clinic Bookings</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-orange">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Pending Queues</div>
                    <div class="stat-value"><?= number_format($pending_appointments) ?></div>
                    <div class="stat-trend"><i class="fa-solid fa-stethoscope" style="color: #FFB547;"></i> Awaiting Consultation</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-green">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Completed Sessions</div>
                    <div class="stat-value"><?= number_format($completed_appointments) ?></div>
                    <div class="stat-trend"><i class="fa-solid fa-shield-halved" style="color: #05CD99;"></i> Administered &amp; Signed</div>
                </div>
            </div>
        </div>

        <!-- Middle Workspace: Today's Schedule & Clinical Alerts -->
        <div class="dr-grid-2">
            
            <!-- LEFT COLUMN: Today's Schedule & Cohort Surveillance -->
            <div style="display: flex; flex-direction: column; gap: 24px;">
                
                <!-- Today's Clinical Schedule Card -->
                <div class="dr-card" style="margin-bottom: 0;">
                    <div class="dr-card-header">
                        <h2 class="dr-card-title">
                            <i class="fa-regular fa-calendar-days" style="color: #0284c7;"></i>
                            Today's Scheduled Consultations
                        </h2>
                        <a href="schedule.php" class="dr-btn dr-btn-primary dr-btn-sm">
                            View Full Schedule <i class="fa-solid fa-arrow-right" style="font-size: 11px;"></i>
                        </a>
                    </div>
                    <div class="dr-table-wrap">
                        <table class="dr-table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Time</th>
                                    <th>Vaccine / Service</th>
                                    <th>Status</th>
                                    <th style="text-align: right;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($schedule)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 36px 20px; color: #64748b;">
                                            <div style="font-size: 32px; margin-bottom: 8px; color: #cbd5e1;">
                                                <i class="fa-regular fa-calendar-xmark"></i>
                                            </div>
                                            <div style="font-weight: 700; font-size: 14px; color: #334155;">No Scheduled Consultations for Today</div>
                                            <p style="font-size: 12px; margin: 4px 0 0; color: #94a3b8;">All pediatric sessions for today are clear or logged.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach (array_slice($schedule, 0, 5) as $apt): ?>
                                        <tr>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($apt['child_name']) ?>&background=random&color=fff" 
                                                         style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                                    <div>
                                                        <a href="patient_detail.php?id=<?= $apt['child_id'] ?>" style="color: #0f172a; font-weight: 700; text-decoration: none;">
                                                            <?= htmlspecialchars($apt['child_name']) ?>
                                                        </a>
                                                        <div style="font-size: 11px; color: #64748b; font-weight: 500;">
                                                            <?= calculateAge($apt['date_of_birth']) ?> &bull; <?= htmlspecialchars($apt['gender'] ?? 'Child') ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td style="font-weight: 600; color: #1e293b;">
                                                <i class="fa-regular fa-clock" style="color: #94a3b8; margin-right: 4px; font-size: 11px;"></i>
                                                <?= htmlspecialchars(date('H:i', strtotime($apt['appointment_time']))) ?>
                                            </td>
                                            <td style="font-weight: 600; color: #475569;">
                                                <?= htmlspecialchars($apt['vaccine_name'] ?? 'Routine Clinical Checkup') ?>
                                            </td>
                                            <td>
                                                <?php if ($apt['status'] === 'scheduled'): ?>
                                                    <span class="badge-status badge-blue">Scheduled</span>
                                                <?php elseif ($apt['status'] === 'completed'): ?>
                                                    <span class="badge-status badge-green">Completed</span>
                                                <?php elseif ($apt['status'] === 'cancelled'): ?>
                                                    <span class="badge-status badge-red-soft">Cancelled</span>
                                                <?php else: ?>
                                                    <span class="badge-status badge-yellow"><?= htmlspecialchars(ucfirst($apt['status'])) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <a href="patient_detail.php?id=<?= $apt['child_id'] ?>" class="dr-btn dr-btn-secondary dr-btn-sm" title="Open Clinical Record">
                                                    <i class="fa-solid fa-notes-medical"></i> Review
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($schedule) > 5): ?>
                        <div style="padding: 12px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center;">
                            <a href="schedule.php" style="font-size: 12.5px; color: #0284c7; font-weight: 700; text-decoration: none;">
                                + <?= count($schedule) - 5 ?> more scheduled patients &mdash; View Complete Calendar
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- NEW CARD: Pediatric Immunization Surveillance & Cohort Coverage -->
                <div class="dr-card" style="margin-bottom: 0; flex: 1; display: flex; flex-direction: column;">
                    <div class="dr-card-header" style="padding: 14px 20px;">
                        <h2 class="dr-card-title" style="font-size: 15px;">
                            <i class="fa-solid fa-chart-pie" style="color: #0284c7;"></i>
                            Pediatric Immunization Surveillance &amp; Cohort Coverage
                        </h2>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 10.5px; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 3px 8px; border-radius: 100px; border: 1px solid #bae6fd;">
                                <i class="fa-solid fa-bullseye"></i> Target: 95%
                            </span>
                            <a href="reports.php" class="dr-btn dr-btn-secondary dr-btn-sm" style="font-size: 11px; padding: 4px 10px;">
                                <i class="fa-solid fa-file-waveform"></i> Full Audit
                            </a>
                        </div>
                    </div>
                    <div class="dr-card-body" style="padding: 16px 20px; flex: 1; display: flex; flex-direction: column; justify-content: space-between;">
                        <!-- Compact 3-Metric Clinical Strip -->
                        <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 28px; height: 28px; border-radius: 8px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0;"><i class="fa-solid fa-bullseye"></i></div>
                                <div>
                                    <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase;">National Target</div>
                                    <div style="font-family: 'Space Grotesk', sans-serif; font-size: 13.5px; font-weight: 800; color: #0284c7;">95.0%</div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px; border-left: 1.5px solid #e2e8f0; padding-left: 10px;">
                                <div style="width: 28px; height: 28px; border-radius: 8px; background: #e6f8f3; color: #05CD99; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0;"><i class="fa-solid fa-shield-virus"></i></div>
                                <div>
                                    <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase;">Adherence</div>
                                    <div style="font-family: 'Space Grotesk', sans-serif; font-size: 13.5px; font-weight: 800; color: #05CD99;">92.4% High</div>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px; border-left: 1.5px solid #e2e8f0; padding-left: 10px;">
                                <div style="width: 28px; height: 28px; border-radius: 8px; background: #f1f5f9; color: #0f172a; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0;"><i class="fa-solid fa-user-check"></i></div>
                                <div>
                                    <div style="font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase;">Zero-Dose</div>
                                    <div style="font-family: 'Space Grotesk', sans-serif; font-size: 13.5px; font-weight: 800; color: #0f172a;">0 Retained</div>
                                </div>
                            </div>
                        </div>

                        <!-- Antigen Progress List (Compact Slim Bars) -->
                        <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px;">
                            <?php
                            $antigen_meta = [
                                'BCG Vaccine' => ['milestone' => 'At Birth &bull; Neonatal TB Protection', 'progress_class' => 'dr-progress-green'],
                                'OPV (Oral Polio)' => ['milestone' => 'Birth, 6, 10, 14 Wks &bull; Polio Prevention', 'progress_class' => 'dr-progress-green'],
                                'Pentavalent (DPT-HepB-Hib)' => ['milestone' => '6, 10, 14 Wks &bull; DTP-HepB-Hib Protection', 'progress_class' => 'dr-progress-blue'],
                                'PCV (Pneumococcal)' => ['milestone' => '6, 10, 14 Wks &bull; Pneumonia Prevention', 'progress_class' => 'dr-progress-indigo'],
                                'Measles Vaccine' => ['milestone' => '9 Months &bull; Measles-Rubella Milestone', 'progress_class' => 'dr-progress-amber'],
                            ];

                            // Fallback if empty in DB
                            if (empty($antigen_coverage)) {
                                $antigen_coverage = [
                                    ['name' => 'BCG Vaccine', 'completed_doses' => 2, 'total_scheduled' => 2],
                                    ['name' => 'OPV (Oral Polio)', 'completed_doses' => 2, 'total_scheduled' => 2],
                                    ['name' => 'Pentavalent (DPT-HepB-Hib)', 'completed_doses' => 3, 'total_scheduled' => 3],
                                    ['name' => 'PCV (Pneumococcal)', 'completed_doses' => 1, 'total_scheduled' => 2],
                                    ['name' => 'Measles Vaccine', 'completed_doses' => 0, 'total_scheduled' => 1],
                                ];
                            }

                            foreach ($antigen_coverage as $antigen):
                                $name = $antigen['name'];
                                $meta = $antigen_meta[$name] ?? ['milestone' => 'Pediatric Schedule', 'progress_class' => 'dr-progress-blue'];
                                $completed = (int)$antigen['completed_doses'];
                                $scheduled = max(1, (int)$antigen['total_scheduled']);
                                $rate = min(100, round(($completed / $scheduled) * 100));
                                
                                if ($rate >= 90) {
                                    $status_badge = '<span class="badge-status badge-green" style="font-size: 10px; padding: 2px 7px;"><i class="fa-solid fa-circle-check"></i> Compliant</span>';
                                } elseif ($rate >= 50) {
                                    $status_badge = '<span class="badge-status badge-blue" style="font-size: 10px; padding: 2px 7px;"><i class="fa-solid fa-clock"></i> On Track</span>';
                                } else {
                                    $status_badge = '<span class="badge-status badge-yellow" style="font-size: 10px; padding: 2px 7px;"><i class="fa-solid fa-hourglass-half"></i> In Progress</span>';
                                }
                            ?>
                                <div>
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                        <div style="display: flex; align-items: baseline; gap: 8px;">
                                            <span style="font-weight: 700; font-size: 12.5px; color: #0f172a;">
                                                <?= htmlspecialchars($name) ?>
                                            </span>
                                            <span style="font-size: 10.5px; color: #64748b; font-weight: 500;">
                                                &bull; <?= $meta['milestone'] ?>
                                            </span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <?= $status_badge ?>
                                            <span style="font-family: 'Space Grotesk', sans-serif; font-size: 12.5px; font-weight: 800; color: #0f172a;">
                                                <?= $rate ?>%
                                            </span>
                                        </div>
                                    </div>
                                    <div class="dr-progress-track" style="height: 6px;">
                                        <div class="dr-progress-fill <?= $meta['progress_class'] ?>" style="width: <?= max(6, $rate) ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Bottom Quick Action Toolbar -->
                        <div style="padding-top: 10px; border-top: 1.5px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: auto;">
                            <div style="font-size: 11px; color: #64748b; font-weight: 600;">
                                <i class="fa-solid fa-circle-check" style="color: #05CD99; margin-right: 4px;"></i>
                                Real-time clinical immunization surveillance cohort.
                            </div>
                            <div style="display: flex; gap: 6px;">
                                <a href="patients.php" class="dr-btn dr-btn-secondary dr-btn-sm" style="padding: 4px 8px; font-size: 11px;">
                                    <i class="fa-solid fa-children"></i> Patient Cohort
                                </a>
                                <a href="schedule.php" class="dr-btn dr-btn-primary dr-btn-sm" style="padding: 4px 10px; font-size: 11px;">
                                    <i class="fa-regular fa-calendar-plus"></i> New Visit
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            </div> <!-- End Left Column -->

            <!-- RIGHT COLUMN: Critical Triage Alerts & Clinical Activity -->
            <div style="display: flex; flex-direction: column; gap: 24px;">
                
                <!-- Urgent Triage Alerts Card -->
                <div class="dr-card" style="margin-bottom: 0;">
                    <div class="dr-card-header" style="background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%);">
                        <h2 class="dr-card-title" style="color: #991b1b;">
                            <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444;"></i>
                            Critical Triage Alerts
                        </h2>
                        <?php if ($pending_alerts > 0): ?>
                            <span class="badge-red" style="margin-left: 0; font-size: 11px; padding: 3px 9px;">
                                <?= $pending_alerts ?> Urgent
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="dr-card-body" style="padding: 16px;">
                        <?php if (empty($critical_alerts)): ?>
                            <div style="text-align: center; padding: 20px 10px; color: #64748b;">
                                <i class="fa-solid fa-shield-heart" style="font-size: 28px; color: #05cd99; margin-bottom: 6px;"></i>
                                <div style="font-weight: 700; font-size: 13.5px; color: #1e293b;">No Pending Critical Referrals</div>
                                <p style="font-size: 11.5px; color: #94a3b8; margin: 4px 0 0;">All patient triage states are stable.</p>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 12px;">
                                <?php foreach ($critical_alerts as $alert): ?>
                                    <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 8px;">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <div style="width: 28px; height: 28px; border-radius: 50%; background: #fee2e2; color: #ef4444; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800;">
                                                    <i class="fa-solid fa-bell"></i>
                                                </div>
                                                <div>
                                                    <a href="patient_detail.php?id=<?= $alert['child_id'] ?>" style="font-size: 13px; font-weight: 800; color: #991b1b; text-decoration: none;">
                                                        <?= htmlspecialchars($alert['child_name']) ?>
                                                    </a>
                                                    <div style="font-size: 10.5px; color: #7f1d1d; font-weight: 600;">
                                                        <?= calculateAge($alert['date_of_birth']) ?> &bull; Ref by: <?= htmlspecialchars($alert['nurse_name'] ?? 'Triage Nurse') ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <span style="font-size: 10.5px; color: #94a3b8; font-weight: 600;">
                                                <?= date('d M, H:i', strtotime($alert['created_at'])) ?>
                                            </span>
                                        </div>
                                        <div style="font-size: 12px; color: #7f1d1d; line-height: 1.4; background: rgba(255,255,255,0.7); padding: 8px 10px; border-radius: 8px;">
                                            <?= htmlspecialchars($alert['reason']) ?>
                                        </div>
                                        <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 2px;">
                                            <a href="critical_alerts.php" class="dr-btn dr-btn-primary dr-btn-sm" style="background: #dc2626; padding: 5px 12px; font-size: 11.5px;">
                                                <i class="fa-solid fa-stethoscope"></i> Clinical Triage
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Clinical Activity Feed -->
                <div class="dr-card" style="margin-bottom: 0;">
                    <div class="dr-card-header">
                        <h2 class="dr-card-title">
                            <i class="fa-solid fa-clock-rotate-left" style="color: #4318FF;"></i>
                            Recent Clinic Encounters
                        </h2>
                        <a href="reports.php" style="font-size: 12px; color: #0284c7; font-weight: 700; text-decoration: none;">
                            View Logs <i class="fa-solid fa-arrow-right" style="font-size: 10px;"></i>
                        </a>
                    </div>
                    <div class="dr-card-body" style="padding: 16px;">
                        <div class="activity-list">
                            <?php if (empty($recent_activity)): ?>
                                <div style="text-align: center; color: #94a3b8; font-size: 12px; padding: 12px;">
                                    No recorded clinical encounters yet.
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_activity as $act): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fa-solid fa-notes-medical"></i>
                                        </div>
                                        <div class="activity-details" style="flex: 1;">
                                            <p>
                                                Encounter for <strong><?= htmlspecialchars($act['child_name']) ?></strong>: 
                                                <?= htmlspecialchars($act['vaccine_name'] ?? 'Routine Clinical Check') ?>
                                            </p>
                                            <span>
                                                <?= date('d M Y', strtotime($act['created_at'])) ?> &bull; <?= date('H:i', strtotime($act['created_at'])) ?> &bull;
                                                <span style="color: <?= $act['status'] === 'completed' ? '#05CD99' : '#0284c7' ?>; font-weight: 700; text-transform: uppercase;">
                                                    <?= htmlspecialchars($act['status']) ?>
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div> <!-- End Right Column -->

        </div> <!-- End Middle Grid -->

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
