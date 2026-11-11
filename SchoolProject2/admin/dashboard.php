<?php
session_start();
include '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['name'];
$success = '';
$error   = '';

// Fetch Staff Counts
$total_doctors     = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'doctor'")->fetchColumn();
$total_nurses      = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'nurse'")->fetchColumn();
$total_pharmacists = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'pharmacy'")->fetchColumn();
$total_guardians   = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'guardian'")->fetchColumn();
$total_children  = $pdo->query("SELECT COUNT(*) FROM children")->fetchColumn();

// Doses administered this calendar month (completed appointments)
$doses_this_month = (int)$pdo->query(
    "SELECT COUNT(*) FROM appointments
      WHERE status = 'completed'
        AND YEAR(appointment_date) = YEAR(CURDATE())
        AND MONTH(appointment_date) = MONTH(CURDATE())"
)->fetchColumn();;

$on_duty_count     = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('doctor', 'nurse', 'pharmacy') AND is_on_duty = 1")->fetchColumn();
$total_staff       = $total_doctors + $total_nurses + $total_pharmacists;

// Low stock vaccines count
$low_stock_count   = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= low_stock_threshold")->fetchColumn();

// Fetch All Staff Members
$stmt = $pdo->query("
    SELECT id, name, phone, role, is_on_duty, created_at 
    FROM users 
    WHERE role IN ('doctor', 'nurse', 'pharmacy') 
    ORDER BY is_on_duty DESC, role ASC, name ASC
");
$staff_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'admin') AND is_read = 0");
$stmt->execute([$admin_id]);
$unread_msg_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executive Admin Dashboard — VaxiCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/admin_theme.css">
    <style>
        .duty-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
        }
        .duty-badge.on { background: #e6faf5; color: #047857; border: 1px solid #a7f3d0; }
        .duty-badge.off { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }
        .duty-pulse {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #05cd99;
            box-shadow: 0 0 0 2px rgba(5, 205, 153, 0.2);
            animation: pulseDuty 2s infinite;
        }
        @keyframes pulseDuty {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(5, 205, 153, 0.5); }
            70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(5, 205, 153, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(5, 205, 153, 0); }
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
            <a href="dashboard.php" class="sidebar-item active">
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
                        <span style="color:var(--adm-text-primary);">Dashboard</span>
                    </div>
                    <h1>Executive Analytics Overview</h1>
                </div>
            </div>

            <div class="topbar-right">
                <div class="date-range">
                    <i class="fa-regular fa-calendar"></i> <?= date('d M, Y') ?> &bull; Ndola Central Ward
                </div>

                <div class="topbar-divider"></div>

                <a href="messages.php" class="topbar-action-btn" title="Broadcast Notifications">
                    <i class="fa-regular fa-bell"></i>
                    <?php if($unread_msg_count > 0): ?>
                        <span class="notif-ping"></span>
                    <?php endif; ?>
                </a>

                <a href="settings.php" class="topbar-action-btn" title="System Settings">
                    <i class="fa-solid fa-gear"></i>
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
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div style="background:#ecfdf5; border:1px solid #6ee7b7; color:#047857; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-circle-check" style="color:#05cd99; font-size:16px;"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <!-- Temporary Credentials Notification Card if created -->
        <?php if(isset($_SESSION['new_staff_credentials'])): 
            $c = $_SESSION['new_staff_credentials'];
            unset($_SESSION['new_staff_credentials']);
        ?>
            <div class="cred-box">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div>
                        <h4 style="margin:0; font-size:15px; font-weight:800; color:#1e40af;"><i class="fa-solid fa-key" style="margin-right:8px;"></i> Staff Temporary Login Credentials</h4>
                        <p style="margin:4px 0 0 0; font-size:12.5px; color:#2563eb;">Provide these credentials to the newly registered <strong><?= htmlspecialchars($c['role']) ?></strong> for initial sign-in.</p>
                    </div>
                    <button class="ph-btn ph-btn-outline ph-btn-sm" onclick="navigator.clipboard.writeText('Phone: <?= $c['phone'] ?> | Password: <?= $c['password'] ?>'); alert('Credentials copied to clipboard!');">
                        <i class="fa-solid fa-copy"></i> Copy Credentials
                    </button>
                </div>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-top:14px; background:#fff; padding:12px 16px; border-radius:10px; font-size:13px; border:1px solid #bfdbfe;">
                    <div><span style="color:#64748b; font-size:11px; text-transform:uppercase; font-weight:700; display:block;">Staff Name</span> <strong><?= htmlspecialchars($c['name']) ?></strong></div>
                    <div><span style="color:#64748b; font-size:11px; text-transform:uppercase; font-weight:700; display:block;">Phone / Login ID</span> <span style="font-family:monospace; font-weight:700; color:#1e40af;"><?= htmlspecialchars($c['phone']) ?></span></div>
                    <div><span style="color:#64748b; font-size:11px; text-transform:uppercase; font-weight:700; display:block;">Temporary Password</span> <span style="font-family:monospace; font-weight:700; color:#dc2626;"><?= htmlspecialchars($c['password']) ?></span></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ===== TOP ROW: 5 KPI METRIC CARDS ===== -->
        <div class="stats-grid" style="grid-template-columns: repeat(5, 1fr); margin-bottom: 24px;">
            
            <!-- Card 1: Doctors -->
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-blue"><i class="fa-solid fa-user-doctor"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($total_doctors) ?></div>
                        <div class="stat-label">Doctors</div>
                    </div>
                </div>
                <i class="fa-solid fa-ellipsis mar-card-options" title="Options"></i>
            </div>

            <!-- Card 2: Nurses -->
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-green"><i class="fa-solid fa-user-nurse"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($total_nurses) ?></div>
                        <div class="stat-label">Nurses</div>
                    </div>
                </div>
                <i class="fa-solid fa-ellipsis mar-card-options" title="Options"></i>
            </div>

            <!-- Card 3: Pharmacists -->
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-yellow"><i class="fa-solid fa-prescription-bottle-medical"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($total_pharmacists) ?></div>
                        <div class="stat-label">Pharmacists</div>
                    </div>
                </div>
                <i class="fa-solid fa-ellipsis mar-card-options" title="Options"></i>
            </div>

            <!-- Card 4: Staff On Duty -->
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-purple"><i class="fa-solid fa-user-clock"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($on_duty_count) ?></div>
                        <div class="stat-label">Staff On Duty</div>
                    </div>
                </div>
                <i class="fa-solid fa-ellipsis mar-card-options" title="Options"></i>
            </div>

            <!-- Card 5: Children -->
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-teal"><i class="fa-solid fa-children"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($total_children) ?></div>
                        <div class="stat-label">Children Enrolled</div>
                    </div>
                </div>
                <i class="fa-solid fa-ellipsis mar-card-options" title="Options"></i>
            </div>

        </div>

        <!-- ===== MIDDLE ROW: 3 CARDS ===== -->
        <div class="stats-grid" style="grid-template-columns: 1.25fr 1fr 1.25fr; margin-bottom: 24px;">

            <!-- Middle Card 1: Zambian Patient & Facility Origins -->
            <div class="mar-card">
                <div class="mar-card-header">
                    <div>
                        <h3 class="mar-card-title"><i class="fa-solid fa-map-location-dot" style="color:var(--adm-blue);"></i> Beneficiary Origins (Zambia)</h3>
                        <div class="mar-card-subtitle">Distribution across national pediatric catchment zones</div>
                    </div>
                    <i class="fa-solid fa-ellipsis mar-card-options"></i>
                </div>
                
                <div class="region-list">
                    <div class="region-item">
                        <div class="region-item-top">
                            <span class="region-name"><i class="fa-solid fa-location-dot" style="color:var(--adm-blue);"></i> Copperbelt (Ndola &amp; Kitwe)</span>
                            <span class="region-value">52%</span>
                        </div>
                        <div class="region-bar-bg">
                            <div class="region-bar-fill" style="width:52%; background:var(--adm-blue);"></div>
                        </div>
                    </div>
                    <div class="region-item">
                        <div class="region-item-top">
                            <span class="region-name"><i class="fa-solid fa-location-dot" style="color:var(--adm-green);"></i> Lusaka Urban &amp; Rural</span>
                            <span class="region-value">24%</span>
                        </div>
                        <div class="region-bar-bg">
                            <div class="region-bar-fill" style="width:24%; background:var(--adm-green);"></div>
                        </div>
                    </div>
                    <div class="region-item">
                        <div class="region-item-top">
                            <span class="region-name"><i class="fa-solid fa-location-dot" style="color:var(--adm-yellow);"></i> Central Province (Kabwe)</span>
                            <span class="region-value">14%</span>
                        </div>
                        <div class="region-bar-bg">
                            <div class="region-bar-fill" style="width:14%; background:var(--adm-yellow);"></div>
                        </div>
                    </div>
                    <div class="region-item">
                        <div class="region-item-top">
                            <span class="region-name"><i class="fa-solid fa-location-dot" style="color:var(--adm-purple);"></i> Southern &amp; Eastern Provinces</span>
                            <span class="region-value">10%</span>
                        </div>
                        <div class="region-bar-bg">
                            <div class="region-bar-fill" style="width:10%; background:var(--adm-purple);"></div>
                        </div>
                    </div>
                </div>
                
                <div style="font-size:11.5px; color:var(--adm-text-secondary); margin-top:16px; padding-top:12px; border-top:1px solid #f1f5f9; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-circle-check" style="color:var(--adm-green);"></i> 100% Zambian Registered Beneficiaries (MOH Verified)
                </div>
            </div>

            <!-- Middle Card 2: Patient Age Breakdown -->
            <div class="mar-card">
                <div class="mar-card-header">
                    <div>
                        <h3 class="mar-card-title"><i class="fa-solid fa-chart-pie" style="color:var(--adm-purple);"></i> Age Demographics</h3>
                        <div class="mar-card-subtitle">Cohort categorization</div>
                    </div>
                    <i class="fa-solid fa-ellipsis mar-card-options"></i>
                </div>

                <div class="donut-chart-container">
                    <div class="donut-chart-wrap">
                        <div class="donut-chart">
                            <svg width="130" height="130" viewBox="0 0 100 100">
                                <circle class="donut-bg" cx="50" cy="50" r="40" />
                                <circle class="donut-segment" cx="50" cy="50" r="40" />
                            </svg>
                        </div>
                        <div class="donut-chart-center">
                            <span class="donut-center-metric">Under-5</span>
                            <span class="donut-center-label">Cohort</span>
                        </div>
                    </div>
                    <div class="donut-legend">
                        <div class="donut-legend-item">
                            <div class="donut-dot" style="background:var(--adm-blue);"></div>
                            <span>0 - 12 Mos (Infants: 75%)</span>
                        </div>
                        <div class="donut-legend-item">
                            <div class="donut-dot" style="background:#cbd5e1;"></div>
                            <span>1 - 5 Yrs (Toddlers: 25%)</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Middle Card 3: Vaccine Dispensing Activity -->
            <div class="mar-card">
                <div class="mar-card-header">
                    <div>
                        <h3 class="mar-card-title"><i class="fa-solid fa-syringe" style="color:var(--adm-blue);"></i> Vaccine Dispensing</h3>
                        <div class="mar-card-subtitle">Total verified doses administered this month</div>
                    </div>
                    <i class="fa-solid fa-ellipsis mar-card-options"></i>
                </div>

                <div class="chart-header-metric"><?= number_format($doses_this_month) ?> Dose<?= $doses_this_month === 1 ? '' : 's' ?></div>

                <div class="line-chart">
                    <svg class="line-chart-svg" viewBox="0 0 300 90" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="chartGradient" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#4318ff" stop-opacity="0.28"/>
                                <stop offset="100%" stop-color="#4318ff" stop-opacity="0.0"/>
                            </linearGradient>
                        </defs>
                        <path d="M0,70 Q50,20 100,50 T200,30 T300,10 L300,90 L0,90 Z" fill="url(#chartGradient)"/>
                        <path d="M0,70 Q50,20 100,50 T200,30 T300,10" fill="none" stroke="#4318ff" stroke-width="3.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <div style="display:flex; justify-content:space-between; font-size:11px; font-weight:600; color:var(--adm-text-secondary); margin-top:8px;">
                    <span>Week 1</span>
                    <span>Week 2</span>
                    <span>Week 3</span>
                    <span>Week 4</span>
                    <span style="color:var(--adm-blue); font-weight:700;">Current</span>
                </div>
            </div>

        </div>

        <!-- ===== BOTTOM ROW: 3 CARDS ===== -->
        <div class="stats-grid" style="grid-template-columns: 1fr 1.15fr 1fr;">

            <!-- Bottom Card 1: Real-Time Notification & SMS Delivery Channels -->
            <div class="mar-card">
                <div class="mar-card-header">
                    <div>
                        <h3 class="mar-card-title"><i class="fa-solid fa-tower-broadcast" style="color:var(--adm-blue);"></i> Delivery Channels</h3>
                        <div class="mar-card-subtitle">Guardian reminder pipeline</div>
                    </div>
                    <i class="fa-solid fa-ellipsis mar-card-options"></i>
                </div>
                <div class="user-list">
                    <div class="user-item">
                        <div class="user-info">
                            <div class="activity-icon icon-blue"><i class="fa-solid fa-comment-sms"></i></div>
                            <div>
                                <h4>SMS Reminders (Zamtel/Airtel/MTN)</h4>
                                <p>Automated immunization schedules</p>
                            </div>
                        </div>
                        <div class="user-value" style="color:var(--adm-blue);">64%</div>
                    </div>
                    <div class="user-item">
                        <div class="user-info">
                            <div class="activity-icon icon-green"><i class="fa-brands fa-whatsapp"></i></div>
                            <div>
                                <h4>Guardian WhatsApp Alerts</h4>
                                <p>Schedule &amp; batch confirmations</p>
                            </div>
                        </div>
                        <div class="user-value" style="color:var(--adm-green);">26%</div>
                    </div>
                    <div class="user-item">
                        <div class="user-info">
                            <div class="activity-icon icon-purple"><i class="fa-solid fa-hospital-user"></i></div>
                            <div>
                                <h4>In-Clinic Triage Follow-ups</h4>
                                <p>Phone contact verification</p>
                            </div>
                        </div>
                        <div class="user-value" style="color:var(--adm-purple);">10%</div>
                    </div>
                </div>
                <div style="text-align:center; margin-top:18px; padding-top:14px; border-top:1px solid #f1f5f9;">
                    <a href="reports.php" class="ph-btn ph-btn-outline ph-btn-sm" style="width:100%;">
                        <i class="fa-solid fa-list-check"></i> View Delivery Logs
                    </a>
                </div>
            </div>

            <!-- Bottom Card 2: Hospital System Activity Stream -->
            <div class="mar-card">
                <div class="mar-card-header">
                    <div>
                        <h3 class="mar-card-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--adm-teal);"></i> System Activity Stream</h3>
                        <div class="mar-card-subtitle">Live hospital operational events</div>
                    </div>
                    <i class="fa-solid fa-ellipsis mar-card-options"></i>
                </div>
                <div class="activity-list">
                    <div class="activity-item">
                        <div class="activity-icon icon-blue"><i class="fa-solid fa-syringe"></i></div>
                        <div class="activity-details">
                            <p><strong>18 infants</strong> successfully vaccinated with <strong>BCG &amp; OPV-1</strong></p>
                            <span>15 mins ago &bull; Immunization Clinic</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon icon-green"><i class="fa-solid fa-stethoscope"></i></div>
                        <div class="activity-details">
                            <p><strong>Dr. Chileshe</strong> reviewed <strong>6 critical triage vitals</strong></p>
                            <span>42 mins ago &bull; Clinical Observation</span>
                        </div>
                    </div>
                    <div class="activity-item">
                        <div class="activity-icon icon-purple"><i class="fa-solid fa-boxes-packing"></i></div>
                        <div class="activity-details">
                            <p><strong>Pharmacy</strong> received batch restock of <strong>Pentavalent-3</strong></p>
                            <span>2 hours ago &bull; Cold Chain Storage</span>
                        </div>
                    </div>
                </div>
                <div style="text-align:center; margin-top:18px; padding-top:14px; border-top:1px solid #f1f5f9;">
                    <a href="reports.php" class="ph-btn ph-btn-outline ph-btn-sm" style="width:100%;">
                        <i class="fa-solid fa-shield-halved"></i> View Full Audit Log
                    </a>
                </div>
            </div>

            <!-- Bottom Card 3: Staff Roster List -->
            <div class="mar-card">
                <div class="mar-card-header">
                    <div>
                        <h3 class="mar-card-title"><i class="fa-solid fa-user-doctor" style="color:var(--adm-green);"></i> Active Staff Roster</h3>
                        <div class="mar-card-subtitle">On-duty medical personnel</div>
                    </div>
                    <i class="fa-solid fa-ellipsis mar-card-options"></i>
                </div>
                <div class="user-list">
                    <?php if(empty($staff_members)): ?>
                        <div style="text-align:center; padding:20px; color:var(--adm-text-muted); font-size:13px;">No clinical staff records found.</div>
                    <?php else: ?>
                        <?php foreach(array_slice($staff_members, 0, 4) as $s): ?>
                            <div class="user-item">
                                <div class="user-info">
                                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($s['name']) ?>&background=111c44&color=fff&size=72" alt="Staff">
                                    <div>
                                        <h4><?= htmlspecialchars($s['name']) ?></h4>
                                        <p><?= ucfirst($s['role']) ?> &bull; <?= htmlspecialchars($s['phone']) ?></p>
                                    </div>
                                </div>
                                <div>
                                    <?php if($s['is_on_duty']): ?>
                                        <span class="duty-badge on"><span class="duty-pulse"></span> On Duty</span>
                                    <?php else: ?>
                                        <span class="duty-badge off"><i class="fa-regular fa-circle" style="font-size:7px;"></i> Off Duty</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div style="text-align:center; margin-top:18px; padding-top:14px; border-top:1px solid #f1f5f9;">
                    <a href="add_staff.php" class="ph-btn ph-btn-green ph-btn-sm" style="width:100%;">
                        <i class="fa-solid fa-user-plus"></i> Onboard New Staff
                    </a>
                </div>
            </div>

        </div>

    </main>

    <script src="../js/notifications.js"></script>
    <script>
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
    </script>
</body>
</html>

