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

// Sidebar & Notif Counts
$pending_alerts = (int)$pdo->query("SELECT COUNT(*) FROM triage_referrals WHERE status = 'pending'")->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'doctor' OR recipient_role = 'all') AND is_read = 0");
$stmt->execute([$user_id]);
$unread_msg_count = (int)($stmt->fetchColumn() ?: 0);

// Search & Filtering Parameters
$search = trim($_GET['search'] ?? '');
$gender_filter = trim($_GET['gender'] ?? 'all');
$age_filter = trim($_GET['age'] ?? 'all');

// Build Filtered Patient Query
$query = "
    SELECT c.*, u.name as guardian_name, u.phone as guardian_phone, u.email as guardian_email,
           COUNT(CASE WHEN a.status = 'completed' THEN 1 END) as completed_vaccines,
           COUNT(CASE WHEN a.status = 'scheduled' THEN 1 END) as upcoming_appointments,
           MAX(CASE WHEN a.status = 'completed' THEN a.appointment_date END) as last_visit_date
    FROM children c
    JOIN users u ON c.guardian_id = u.id
    LEFT JOIN appointments a ON a.child_id = c.id
    WHERE 1=1
";
$params = [];

if ($search !== '') {
    $query .= " AND (c.name LIKE ? OR u.name LIKE ? OR u.phone LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($gender_filter === 'Male' || $gender_filter === 'Female') {
    $query .= " AND c.gender = ?";
    $params[] = $gender_filter;
}

if ($age_filter === 'infant') {
    // Under 12 months
    $query .= " AND c.date_of_birth >= DATE_SUB(CURRENT_DATE, INTERVAL 1 YEAR)";
} elseif ($age_filter === 'toddler') {
    // 1 to 2 years
    $query .= " AND c.date_of_birth < DATE_SUB(CURRENT_DATE, INTERVAL 1 YEAR) AND c.date_of_birth >= DATE_SUB(CURRENT_DATE, INTERVAL 2 YEAR)";
} elseif ($age_filter === 'preschool') {
    // 2 to 5 years
    $query .= " AND c.date_of_birth < DATE_SUB(CURRENT_DATE, INTERVAL 2 YEAR)";
}

$query .= " GROUP BY c.id ORDER BY c.name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top KPI Metrics (Overall Cohort Stats)
$total_children = (int)$pdo->query("SELECT COUNT(*) FROM children")->fetchColumn();
$infant_count = (int)$pdo->query("SELECT COUNT(*) FROM children WHERE date_of_birth >= DATE_SUB(CURRENT_DATE, INTERVAL 1 YEAR)")->fetchColumn();
$older_count = (int)$pdo->query("SELECT COUNT(*) FROM children WHERE date_of_birth < DATE_SUB(CURRENT_DATE, INTERVAL 1 YEAR)")->fetchColumn();
$scheduled_today_count = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE")->fetchColumn();

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pediatric Patients Directory — VaxiCare Doctor Portal</title>
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
            <a href="patients.php" class="sidebar-item active">
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
                    <h1>Pediatric Patients</h1>
                    <div class="topbar-breadcrumbs" style="display: flex; align-items: center; gap: 6px; margin: 0;">
                        <span>Doctor Station</span>
                        <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #cbd5e1;"></i>
                        <span class="current">Patients Directory</span>
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
                    <div class="stat-label">Total Registered</div>
                    <div class="stat-value"><?= number_format($total_children) ?></div>
                    <div class="stat-trend"><i class="fa-solid fa-shield-halved" style="color: #0284c7;"></i> Active Under-5 Cohort</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon icon-cyan">
                    <i class="fa-solid fa-baby"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Infants (&lt; 12 Mos)</div>
                    <div class="stat-value"><?= number_format($infant_count) ?></div>
                    <div class="stat-trend"><i class="fa-solid fa-syringe" style="color: #00B5D8;"></i> Primary EPI Schedule</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-green">
                    <i class="fa-solid fa-child-reaching"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Toddlers &amp; Older</div>
                    <div class="stat-value"><?= number_format($older_count) ?></div>
                    <div class="stat-trend"><i class="fa-solid fa-heart-pulse" style="color: #05CD99;"></i> Booster &amp; Growth Milestones</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon icon-orange">
                    <i class="fa-regular fa-calendar-check"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Visits Booked Today</div>
                    <div class="stat-value"><?= number_format($scheduled_today_count) ?></div>
                    <div class="stat-trend"><i class="fa-solid fa-clock" style="color: #FFB547;"></i> Day Clinic Consultations</div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar Panel -->
        <form method="GET" class="filter-panel">
            <div class="filter-search-box">
                <i class="fa-solid fa-magnifying-glass" style="color: #94a3b8; font-size: 14px;"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by patient name, guardian name, or phone number...">
                <?php if ($search !== ''): ?>
                    <a href="patients.php" style="color: #94a3b8; font-size: 13px; text-decoration: none; padding: 4px;" title="Clear search">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </a>
                <?php endif; ?>
            </div>

            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <select name="gender" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $gender_filter === 'all' ? 'selected' : '' ?>>All Genders</option>
                    <option value="Male" <?= $gender_filter === 'Male' ? 'selected' : '' ?>>Male (Boys)</option>
                    <option value="Female" <?= $gender_filter === 'Female' ? 'selected' : '' ?>>Female (Girls)</option>
                </select>

                <select name="age" class="filter-select" onchange="this.form.submit()">
                    <option value="all" <?= $age_filter === 'all' ? 'selected' : '' ?>>All Age Groups</option>
                    <option value="infant" <?= $age_filter === 'infant' ? 'selected' : '' ?>>Infants (&lt; 1 Year)</option>
                    <option value="toddler" <?= $age_filter === 'toddler' ? 'selected' : '' ?>>Toddlers (1-2 Years)</option>
                    <option value="preschool" <?= $age_filter === 'preschool' ? 'selected' : '' ?>>Pre-School (2-5 Years)</option>
                </select>

                <button type="submit" class="dr-btn dr-btn-primary" style="padding: 10px 18px;">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>

                <?php if ($search !== '' || $gender_filter !== 'all' || $age_filter !== 'all'): ?>
                    <a href="patients.php" class="dr-btn dr-btn-secondary" style="padding: 10px 14px;" title="Reset Filters">
                        <i class="fa-solid fa-rotate-left"></i> Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Patients Directory Card & Clinical Table -->
        <div class="dr-card">
            <div class="dr-card-header">
                <h2 class="dr-card-title">
                    <i class="fa-solid fa-users" style="color: #0284c7;"></i>
                    Registered Pediatric Cohort
                </h2>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span class="badge-status badge-blue" style="font-size: 11.5px; padding: 4px 12px;">
                        <?= count($patients) ?> Patients Found
                    </span>
                    <a href="schedule.php" class="dr-btn dr-btn-primary dr-btn-sm">
                        <i class="fa-regular fa-calendar-plus"></i> Book Consultation
                    </a>
                </div>
            </div>

            <div class="dr-table-wrap">
                <table class="dr-table">
                    <thead>
                        <tr>
                            <th>Patient Identity</th>
                            <th>Age &amp; DOB</th>
                            <th>Gender</th>
                            <th>Primary Guardian</th>
                            <th>Guardian Phone</th>
                            <th>Immunization Doses</th>
                            <th>Last Encounter</th>
                            <th style="text-align: right;">Clinical Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($patients)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 48px 20px; color: #64748b;">
                                    <div style="font-size: 36px; margin-bottom: 10px; color: #cbd5e1;">
                                        <i class="fa-solid fa-children"></i>
                                    </div>
                                    <div style="font-weight: 700; font-size: 15px; color: #1e293b;">No Pediatric Patients Found</div>
                                    <p style="font-size: 12.5px; margin: 4px 0 0; color: #94a3b8;">
                                        <?= ($search !== '' || $gender_filter !== 'all' || $age_filter !== 'all') ? 'Try modifying your search or filter criteria.' : 'No children records are registered in the clinic database yet.' ?>
                                    </p>
                                    <?php if ($search !== '' || $gender_filter !== 'all' || $age_filter !== 'all'): ?>
                                        <div style="margin-top: 14px;">
                                            <a href="patients.php" class="dr-btn dr-btn-secondary dr-btn-sm">
                                                <i class="fa-solid fa-rotate-left"></i> Clear All Filters
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($patients as $p): 
                                $is_boy = ($p['gender'] === 'Male');
                                $avatar_class = $is_boy ? 'avatar-boy' : 'avatar-girl';
                                $initials = strtoupper(substr($p['name'], 0, 1));
                            ?>
                                <tr>
                                    <!-- Patient Identity -->
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 12px;">
                                            <div class="patient-avatar <?= $avatar_class ?>">
                                                <?= $initials ?>
                                            </div>
                                            <div>
                                                <a href="patient_detail.php?id=<?= $p['id'] ?>" style="color: #0f172a; font-weight: 700; text-decoration: none; font-size: 14px;">
                                                    <?= htmlspecialchars($p['name']) ?>
                                                </a>
                                                <div style="font-size: 11px; color: #64748b; font-weight: 600; margin-top: 1px;">
                                                    ID: CH-<?= str_pad($p['id'], 5, '0', STR_PAD_LEFT) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Age & DOB -->
                                    <td>
                                        <div style="font-weight: 700; color: #0284c7; font-size: 13px;">
                                            <?= calculateAge($p['date_of_birth']) ?>
                                        </div>
                                        <div style="font-size: 11px; color: #94a3b8; font-weight: 500;">
                                            DOB: <?= date('d M Y', strtotime($p['date_of_birth'])) ?>
                                        </div>
                                    </td>

                                    <!-- Gender -->
                                    <td>
                                        <?php if ($is_boy): ?>
                                            <span class="badge-status badge-blue" style="font-size: 11px;">
                                                <i class="fa-solid fa-mars"></i> Male
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status" style="background: #ffe4e6; color: #e11d48; font-size: 11px;">
                                                <i class="fa-solid fa-venus"></i> Female
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Guardian -->
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 6px; font-weight: 600; color: #1e293b;">
                                            <i class="fa-regular fa-user" style="color: #94a3b8; font-size: 12px;"></i>
                                            <?= htmlspecialchars($p['guardian_name']) ?>
                                        </div>
                                    </td>

                                    <!-- Phone -->
                                    <td>
                                        <a href="tel:<?= htmlspecialchars($p['guardian_phone']) ?>" style="display: inline-flex; align-items: center; gap: 6px; color: #0284c7; text-decoration: none; font-weight: 600; font-size: 12.5px;">
                                            <i class="fa-solid fa-phone" style="font-size: 11px;"></i>
                                            <?= htmlspecialchars($p['guardian_phone']) ?>
                                        </a>
                                    </td>

                                    <!-- Immunization Doses -->
                                    <td>
                                        <?php if ($p['completed_vaccines'] > 0): ?>
                                            <span class="badge-status badge-green" style="font-size: 11px;">
                                                <i class="fa-solid fa-shield-virus"></i> <?= $p['completed_vaccines'] ?> Doses Completed
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-status badge-yellow" style="font-size: 11px;">
                                                <i class="fa-solid fa-clock"></i> Scheduled Initiation
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($p['upcoming_appointments'] > 0): ?>
                                            <div style="font-size: 10.5px; color: #0284c7; font-weight: 600; margin-top: 3px;">
                                                <i class="fa-regular fa-calendar"></i> <?= $p['upcoming_appointments'] ?> booked
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Last Encounter -->
                                    <td>
                                        <?php if (!empty($p['last_visit_date'])): ?>
                                            <div style="font-size: 12px; font-weight: 600; color: #334155;">
                                                <?= date('d M Y', strtotime($p['last_visit_date'])) ?>
                                            </div>
                                            <div style="font-size: 10.5px; color: #05cd99; font-weight: 600;">
                                                Logged Clinic Visit
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size: 11.5px; color: #94a3b8; font-weight: 500;">
                                                Initial Registration
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Action -->
                                    <td style="text-align: right;">
                                        <div style="display: inline-flex; gap: 6px;">
                                            <a href="patient_detail.php?id=<?= $p['id'] ?>" class="dr-btn dr-btn-primary dr-btn-sm" title="View Medical Chart &amp; Under-5 Card">
                                                <i class="fa-solid fa-notes-medical"></i> Clinical File
                                            </a>
                                            <a href="schedule.php?child_id=<?= $p['id'] ?>" class="dr-btn dr-btn-secondary dr-btn-sm" title="Schedule Consultation">
                                                <i class="fa-regular fa-calendar-plus"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Table Footer -->
            <div style="padding: 14px 24px; background: #f8fafc; border-top: 1.5px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="font-size: 12px; color: #64748b; font-weight: 600;">
                    Showing <strong><?= count($patients) ?></strong> of <strong><?= $total_children ?></strong> total registered pediatric patients in system
                </div>
                <div style="font-size: 11.5px; color: #0284c7; font-weight: 700;">
                    <i class="fa-solid fa-circle-check"></i> Standard Zambian Under-5 Clinical Cohort
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
