<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

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

// Handle status update
$flash_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alert_id']) && isset($_POST['action'])) {
    csrf_guard();
    $alert_id = (int)$_POST['alert_id'];
    $action = $_POST['action'];
    if ($action === 'resolve') {
        $update_stmt = $pdo->prepare("UPDATE triage_referrals SET status = 'resolved' WHERE id = ?");
        $update_stmt->execute([$alert_id]);
        $_SESSION['flash_msg'] = "Critical alert #$alert_id marked as resolved successfully.";
        header("Location: critical_alerts.php");
        exit;
    }
}

if (isset($_SESSION['flash_msg'])) {
    $flash_msg = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

// Summary Metrics
$total_alerts = (int)$pdo->query("SELECT COUNT(*) FROM triage_referrals")->fetchColumn();
$pending_alerts = (int)$pdo->query("SELECT COUNT(*) FROM triage_referrals WHERE status = 'pending'")->fetchColumn();
$resolved_alerts = (int)$pdo->query("SELECT COUNT(*) FROM triage_referrals WHERE status = 'resolved'")->fetchColumn();
$today_alerts = (int)$pdo->query("SELECT COUNT(*) FROM triage_referrals WHERE DATE(created_at) = CURRENT_DATE")->fetchColumn();

// Filter & Search
$status_filter = $_GET['status'] ?? 'all';
$search_query = trim($_GET['search'] ?? '');

$sql = "
    SELECT t.id, c.id as child_id, c.name as child_name, c.date_of_birth, c.gender,
           t.reason, t.status, t.created_at, u.name as nurse_name
    FROM triage_referrals t
    JOIN children c ON t.child_id = c.id
    LEFT JOIN users u ON t.nurse_id = u.id
    WHERE 1=1
";
$params = [];

if ($status_filter === 'pending') {
    $sql .= " AND t.status = 'pending'";
} elseif ($status_filter === 'resolved') {
    $sql .= " AND t.status = 'resolved'";
}

if (!empty($search_query)) {
    $sql .= " AND (c.name LIKE ? OR t.reason LIKE ? OR u.name LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$sql .= " ORDER BY CASE WHEN t.status = 'pending' THEN 0 ELSE 1 END, t.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unread messages for topbar
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR receiver_id IS NULL) AND is_read = 0");
$stmt->execute([$user_id]);
$unread_msg_count = (int)$stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Critical Alerts & Triage — VaxiCare Doctor Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/doctor_theme.css">
    <style>
        .filter-panel {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.02);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .filter-search-box {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 2px 14px;
            flex: 1;
            min-width: 280px;
            transition: all 0.2s;
        }

        .filter-search-box:focus-within {
            border-color: #0284c7;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
        }

        .filter-search-box input {
            border: none;
            background: transparent;
            padding: 10px 8px;
            font-size: 13.5px;
            width: 100%;
            outline: none;
            color: #0f172a;
            font-family: inherit;
        }

        .filter-select {
            padding: 10px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            outline: none;
            cursor: pointer;
            transition: all 0.2s;
            font-family: inherit;
        }

        .filter-select:focus {
            border-color: #0284c7;
        }

        .patient-avatar {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
            flex-shrink: 0;
            text-transform: uppercase;
        }

        .avatar-boy {
            background: #e0f2fe;
            color: #0284c7;
            border: 1.5px solid #bae6fd;
        }

        .avatar-girl {
            background: #ffe4e6;
            color: #e11d48;
            border: 1.5px solid #fecdd3;
        }

        .alert-banner {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 13.5px;
            font-weight: 600;
            background: #e6f8f3;
            color: #05cd99;
            border: 1.5px solid #a7f3d0;
        }

        .triage-reason-box {
            background: #fff1f2;
            border-left: 3px solid #e11d48;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
            color: #9f1239;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .triage-reason-box.resolved {
            background: #f1f5f9;
            border-left-color: #94a3b8;
            color: #475569;
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
            <a href="critical_alerts.php" class="sidebar-item active">
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
                    <h1>Critical Alerts & Triage</h1>
                    <div class="topbar-breadcrumbs" style="display: flex; align-items: center; gap: 6px; margin: 0;">
                        <span>Doctor Station</span>
                        <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #cbd5e1;"></i>
                        <span class="current">Pediatric Triage Referrals</span>
                    </div>
                </div>
            </div>

            <div class="topbar-center">
                <div class="date-range">
                    <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444;"></i>
                    <span>Triage Monitoring &bull; <?= $pending_alerts ?> Pending Escalation<?= $pending_alerts !== 1 ? 's' : '' ?></span>
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

        <?php if (!empty($flash_msg)): ?>
            <div class="alert-banner">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($flash_msg) ?></span>
            </div>
        <?php endif; ?>

        <!-- KPI Metrics Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-red">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Total Referrals</div>
                    <div class="stat-value"><?= number_format($total_alerts) ?></div>
                    <div class="stat-trend" style="color: #ef4444;">
                        <i class="fa-solid fa-notes-medical"></i> All Recorded Alerts
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-orange">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Pending Action</div>
                    <div class="stat-value" style="color: #ea580c;"><?= number_format($pending_alerts) ?></div>
                    <div class="stat-trend" style="color: #ea580c;">
                        <i class="fa-solid fa-bell"></i> Requires Doctor Attention
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-green">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Resolved Cases</div>
                    <div class="stat-value" style="color: #05cd99;"><?= number_format($resolved_alerts) ?></div>
                    <div class="stat-trend" style="color: #05cd99;">
                        <i class="fa-solid fa-shield-halved"></i> Cleared by Doctor
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-blue">
                    <i class="fa-solid fa-calendar-day"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Reported Today</div>
                    <div class="stat-value"><?= number_format($today_alerts) ?></div>
                    <div class="stat-trend">
                        <i class="fa-solid fa-hospital-user"></i> Clinic Triage Today
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="filter-panel">
            <form method="GET" action="critical_alerts.php" style="display: flex; gap: 14px; flex: 1; flex-wrap: wrap;">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="filter-search-box">
                    <i class="fa-solid fa-magnifying-glass" style="color: #94a3b8; font-size: 14px;"></i>
                    <input type="text" name="search" placeholder="Search by patient name, alert reason, or attending nurse..." value="<?= htmlspecialchars($search_query) ?>">
                </div>

                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending Review (<?= $pending_alerts ?>)</option>
                    <option value="resolved" <?= $status_filter === 'resolved' ? 'selected' : '' ?>>Resolved Cases</option>
                </select>

                <button type="submit" class="dr-btn dr-btn-primary dr-btn-sm" style="border-radius: 10px;">
                    <i class="fa-solid fa-filter"></i> Apply
                </button>

                <?php if (!empty($search_query) || $status_filter !== 'all'): ?>
                    <a href="critical_alerts.php" class="dr-btn dr-btn-outline dr-btn-sm" style="border-radius: 10px;">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Critical Alerts Clinical Card -->
        <div class="dr-card">
            <div class="dr-card-header">
                <div class="dr-card-title">
                    <i class="fa-solid fa-stethoscope" style="color: #ef4444;"></i>
                    <span>Triage Referrals Log</span>
                </div>
                <span class="badge-status <?= $pending_alerts > 0 ? 'badge-red-soft' : 'badge-green' ?>">
                    <?= count($alerts) ?> Record<?= count($alerts) !== 1 ? 's' : '' ?> Found
                </span>
            </div>

            <div class="dr-table-wrap">
                <table class="dr-table">
                    <thead>
                        <tr>
                            <th>Patient Profile</th>
                            <th>Age / Sex</th>
                            <th>Alert Reason & Clinical Symptoms</th>
                            <th>Referred By</th>
                            <th>Reported Date</th>
                            <th>Triage Status</th>
                            <th style="text-align: right;">Clinical Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($alerts)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 48px 20px; color: #64748b;">
                                    <div style="font-size: 36px; color: #cbd5e1; margin-bottom: 12px;">
                                        <i class="fa-solid fa-shield-heart"></i>
                                    </div>
                                    <div style="font-weight: 700; font-size: 15px; color: #0f172a;">No Critical Alerts Found</div>
                                    <div style="font-size: 13px; margin-top: 4px;">There are currently no matching triage referral cases for this filter.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($alerts as $alert): 
                                $is_pending = ($alert['status'] === 'pending');
                                $gender = strtolower($alert['gender'] ?? 'unknown');
                                $avatar_class = ($gender === 'female' || $gender === 'f') ? 'avatar-girl' : 'avatar-boy';
                                $initials = strtoupper(substr(trim($alert['child_name']), 0, 1));
                            ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div class="patient-avatar <?= $avatar_class ?>">
                                                <?= $initials ?>
                                            </div>
                                            <div>
                                                <a href="patient_detail.php?id=<?= $alert['child_id'] ?>" style="font-weight: 700; color: #0f172a; text-decoration: none; font-size: 14px;">
                                                    <?= htmlspecialchars($alert['child_name']) ?>
                                                </a>
                                                <div style="font-size: 11.5px; color: #64748b; margin-top: 2px;">
                                                    PID #<?= str_pad($alert['child_id'], 4, '0', STR_PAD_LEFT) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 700; color: #334155;">
                                            <?= calculateAge($alert['date_of_birth'] ?? null) ?>
                                        </div>
                                        <div style="font-size: 11px; text-transform: uppercase; color: #94a3b8; font-weight: 700;">
                                            <?= htmlspecialchars($alert['gender'] ?? 'N/A') ?>
                                        </div>
                                    </td>
                                    <td style="max-width: 320px;">
                                        <div class="triage-reason-box <?= $is_pending ? '' : 'resolved' ?>">
                                            <i class="fa-solid <?= $is_pending ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
                                            <span><?= htmlspecialchars($alert['reason']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #334155; font-size: 13px; display: flex; align-items: center; gap: 6px;">
                                            <i class="fa-solid fa-user-nurse" style="color: #0284c7;"></i>
                                            <?= htmlspecialchars($alert['nurse_name'] ?? 'Triage Staff') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #0f172a; font-size: 12.5px;">
                                            <?= date('d M Y', strtotime($alert['created_at'])) ?>
                                        </div>
                                        <div style="font-size: 11px; color: #64748b;">
                                            <?= date('h:i A', strtotime($alert['created_at'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($is_pending): ?>
                                            <span class="badge-status badge-red-soft">
                                                <i class="fa-solid fa-clock"></i> Pending Review
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status badge-green">
                                                <i class="fa-solid fa-circle-check"></i> Resolved
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; align-items: center; gap: 8px;">
                                            <a href="patient_detail.php?id=<?= $alert['child_id'] ?>" class="dr-btn dr-btn-outline dr-btn-sm" title="View Patient Details">
                                                <i class="fa-solid fa-notes-medical"></i> Profile
                                            </a>
                                            <?php if ($is_pending): ?>
                                                <form method="POST" style="margin: 0; display: inline;" onsubmit="return confirm('Confirm resolving this critical alert for <?= htmlspecialchars(addslashes($alert['child_name'])) ?>
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">?');">
                                                    <input type="hidden" name="alert_id" value="<?= $alert['id'] ?>">
                                                    <input type="hidden" name="action" value="resolve">
                                                    <button type="submit" class="dr-btn dr-btn-primary dr-btn-sm" style="background: #05cd99; border-color: #05cd99; color: white;">
                                                        <i class="fa-solid fa-check"></i> Resolve
                                                    </button>
                                                </form>
                                            <?php endif; ?>
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
