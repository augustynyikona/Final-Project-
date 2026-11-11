<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user profile from database
$stmt = $pdo->prepare("SELECT name, profile_pic FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);

$doctor_name = $user_profile['name'] ?? $_SESSION['name'];
$profile_pic = ($user_profile && $user_profile['profile_pic']) 
    ? '../uploads/' . $user_profile['profile_pic'] 
    : "https://ui-avatars.com/api/?name=" . urlencode($doctor_name) . "&background=0284c7&color=fff";

// Stats
$stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status IN ('arrived', 'confirmed')");
$patients_seen = (int)$stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM appointments");
$total_appts = (int)$stmt->fetchColumn();
$compliance = $total_appts > 0 ? round(($patients_seen / $total_appts) * 100, 1) : 0;

$stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'no-show'");
$missed_appts = (int)$stmt->fetchColumn();

$pending_alerts = (int)$pdo->query("SELECT COUNT(*) FROM triage_referrals WHERE status = 'pending'")->fetchColumn();

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'doctor' OR recipient_role = 'all') AND is_read = 0");
$stmt->execute([$user_id]);
$unread_msg_count = (int)$stmt->fetchColumn();

// Chart Data (Last 7 days)
$chart_data = [];
$max_visits = 0;
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $day_label = date('D, M d', strtotime($date));
    $day_short = date('D', strtotime($date));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ?");
    $stmt->execute([$date]);
    $count = (int)$stmt->fetchColumn();
    $chart_data[] = [
        'label' => $day_label,
        'short' => $day_short,
        'count' => $count,
        'is_today' => ($i == 0)
    ];
    if ($count > $max_visits) $max_visits = $count;
}
if ($max_visits == 0) $max_visits = 1;

// Progress Widget (Most Common Vaccines)
$stmt = $pdo->query("
    SELECT v.name, COUNT(a.id) as count 
    FROM appointments a 
    JOIN vaccines v ON a.vaccine_id = v.id 
    GROUP BY v.id 
    ORDER BY count DESC 
    LIMIT 5
");
$vaccines_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_vaccines_given = array_sum(array_column($vaccines_stats, 'count'));
if ($total_vaccines_given == 0) $total_vaccines_given = 1;

// Additional Clinic Breakdown
$stmt = $pdo->query("SELECT status, COUNT(*) as count FROM appointments GROUP BY status");
$status_counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Coverage Reports — VaxiCare Doctor Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/doctor_theme.css">
    <style>
        .report-grid {
            display: grid;
            grid-template-columns: 1.25fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        @media (max-width: 1024px) {
            .report-grid {
                grid-template-columns: 1fr;
            }
        }

        .bar-chart {
            display: flex;
            align-items: flex-end;
            justify-content: space-around;
            height: 220px;
            padding-top: 24px;
            border-bottom: 1.5px solid #e2e8f0;
            margin-bottom: 16px;
            gap: 12px;
        }

        .bar-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            height: 100%;
            gap: 10px;
            flex: 1;
            position: relative;
        }

        .bar {
            width: 80%;
            max-width: 42px;
            background: linear-gradient(180deg, #38bdf8 0%, #0284c7 100%);
            border-radius: 8px 8px 0 0;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        .bar.today {
            background: linear-gradient(180deg, #34d399 0%, #05cd99 100%);
            box-shadow: 0 4px 14px rgba(5, 205, 153, 0.35);
        }

        .bar:hover {
            transform: scaleY(1.05);
            filter: brightness(1.1);
        }

        .bar-value {
            font-size: 11px;
            font-weight: 800;
            color: #0f172a;
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
        }

        .bar-label {
            font-size: 11.5px;
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .progress-list {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .progress-item {
            width: 100%;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            font-size: 13.5px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .progress-track {
            width: 100%;
            height: 10px;
            background: #f1f5f9;
            border-radius: 100px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .progress-fill {
            height: 100%;
            border-radius: 100px;
            transition: width 0.6s ease;
        }

        @media print {
            .sidebar, .app-topbar, .dr-btn, .mobile-nav-toggle { display: none !important; }
            .app-main { padding: 0 !important; }
            body { background: white !important; }
            .dr-card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; break-inside: avoid; }
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
            <a href="reports.php" class="sidebar-item active">
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
                    <h1>Clinical Reports & Coverage</h1>
                    <div class="topbar-breadcrumbs" style="display: flex; align-items: center; gap: 6px; margin: 0;">
                        <span>Doctor Station</span>
                        <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #cbd5e1;"></i>
                        <span class="current">Pediatric Intelligence Analytics</span>
                    </div>
                </div>
            </div>

            <div class="topbar-center">
                <div class="date-range">
                    <i class="fa-regular fa-calendar-check"></i>
                    <span>Clinic Reporting &bull; <?= date('F Y') ?></span>
                </div>
            </div>

            <div class="topbar-right">
                <button type="button" onclick="window.print()" class="dr-btn dr-btn-outline dr-btn-sm" style="border-radius: 100px; padding: 7px 16px;">
                    <i class="fa-solid fa-print"></i> Export / Print
                </button>

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
                    <i class="fa-solid fa-hospital-user"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Patients Attended</div>
                    <div class="stat-value"><?= number_format($patients_seen) ?></div>
                    <div class="stat-trend" style="color: #0284c7;">
                        <i class="fa-solid fa-arrow-trend-up"></i> Successful Consultations
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-indigo">
                    <i class="fa-solid fa-calendar-check"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Total Appointments</div>
                    <div class="stat-value"><?= number_format($total_appts) ?></div>
                    <div class="stat-trend">
                        <i class="fa-solid fa-list-check"></i> Scheduled Volume
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-green">
                    <i class="fa-solid fa-shield-virus"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Coverage Rate</div>
                    <div class="stat-value" style="color: #05cd99;"><?= $compliance ?>%</div>
                    <div class="stat-trend" style="color: #05cd99;">
                        <i class="fa-solid fa-circle-check"></i> Clinic Compliance Index
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-red">
                    <i class="fa-solid fa-user-xmark"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Missed Visits</div>
                    <div class="stat-value" style="color: #ef4444;"><?= number_format($missed_appts) ?></div>
                    <div class="stat-trend" style="color: #ef4444;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Follow-Up Required
                    </div>
                </div>
            </div>
        </div>

        <div class="report-grid">
            
            <!-- Patient Visits Chart Widget -->
            <div class="dr-card">
                <div class="dr-card-header">
                    <div class="dr-card-title">
                        <i class="fa-solid fa-chart-column" style="color: #0284c7;"></i>
                        <span>Patient Visits Frequency (Last 7 Days)</span>
                    </div>
                    <span class="badge-status badge-blue">Peak: <?= $max_visits ?> Visits/day</span>
                </div>
                
                <div class="dr-card-body">
                    <div class="bar-chart">
                        <?php foreach ($chart_data as $day_data): 
                            $height_pct = ($day_data['count'] / $max_visits) * 100;
                            if ($height_pct < 8) $height_pct = 8;
                            $is_today = $day_data['is_today'];
                        ?>
                        <div class="bar-col" title="<?= $day_data['label'] ?>: <?= $day_data['count'] ?> patient appointments">
                            <div class="bar <?= $is_today ? 'today' : '' ?>" style="height: <?= $height_pct ?>%;">
                                <span class="bar-value"><?= $day_data['count'] ?></span>
                            </div>
                            <span class="bar-label"><?= $day_data['short'] ?><?= $is_today ? ' (Today)' : '' ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #64748b; padding-top: 4px;">
                        <span><i class="fa-solid fa-circle" style="color: #0284c7; font-size: 8px;"></i> Historical Days</span>
                        <span><i class="fa-solid fa-circle" style="color: #05cd99; font-size: 8px;"></i> Active Day (Today)</span>
                    </div>
                </div>
            </div>

            <!-- Most Administered Vaccines Progress Widget -->
            <div class="dr-card">
                <div class="dr-card-header">
                    <div class="dr-card-title">
                        <i class="fa-solid fa-syringe" style="color: #05cd99;"></i>
                        <span>Top Administered Vaccines</span>
                    </div>
                    <span class="badge-status badge-green"><?= number_format($total_vaccines_given) ?> Doses</span>
                </div>
                
                <div class="dr-card-body">
                    <div class="progress-list">
                        <?php if (empty($vaccines_stats)): ?>
                            <div style="color: #64748b; font-size: 13px; text-align: center; padding: 24px;">No vaccine administration data available yet.</div>
                        <?php else: ?>
                            <?php 
                            $bar_colors = [
                                'linear-gradient(90deg, #0284c7 0%, #38bdf8 100%)',
                                'linear-gradient(90deg, #05cd99 0%, #34d399 100%)',
                                'linear-gradient(90deg, #4318ff 0%, #818cf8 100%)',
                                'linear-gradient(90deg, #f59e0b 0%, #fbbf24 100%)',
                                'linear-gradient(90deg, #ec4899 0%, #f472b6 100%)'
                            ];
                            foreach ($vaccines_stats as $index => $stat): 
                                $percent = round(($stat['count'] / $total_vaccines_given) * 100);
                                $color = $bar_colors[$index % count($bar_colors)];
                            ?>
                            <div class="progress-item">
                                <div class="progress-header">
                                    <span><?= htmlspecialchars($stat['name']) ?></span>
                                    <span><?= number_format($stat['count']) ?> doses (<?= $percent ?>%)</span>
                                </div>
                                <div class="progress-track">
                                    <div class="progress-fill" style="width: <?= $percent ?>%; background: <?= $color ?>;"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

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
