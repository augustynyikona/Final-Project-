<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: ../auth/login.php");
    exit;
}

$nurse_name = $_SESSION['name'] ?? 'Bupe';

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

// Handle Filters
$search_query = trim($_GET['search'] ?? '');
$gender_filter = trim($_GET['gender'] ?? '');

$conditions = [];
$params = [];

if ($search_query !== '') {
    $conditions[] = "(c.name LIKE ? OR u.name LIKE ? OR u.phone LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

if ($gender_filter === 'Male' || $gender_filter === 'Female') {
    $conditions[] = "c.gender = ?";
    $params[] = $gender_filter;
}

$where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Fetch children with guardian info and completed vaccination count
$sql = "
    SELECT c.*, u.name as guardian_name, u.phone as guardian_phone, u.email as guardian_email,
           (SELECT COUNT(*) FROM appointments a WHERE a.child_id = c.id AND a.status = 'completed') as completed_doses,
           (SELECT COUNT(*) FROM appointments a WHERE a.child_id = c.id AND a.status = 'scheduled') as scheduled_doses
    FROM children c 
    JOIN users u ON c.guardian_id = u.id 
    $where_clause
    ORDER BY c.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Directory KPI Metrics
$total_patients_count = (int)$pdo->query("SELECT COUNT(*) FROM children")->fetchColumn();
$male_count = (int)$pdo->query("SELECT COUNT(*) FROM children WHERE gender = 'Male'")->fetchColumn();
$female_count = (int)$pdo->query("SELECT COUNT(*) FROM children WHERE gender = 'Female'")->fetchColumn();

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'nurse') AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_msg_count = (int)$stmt->fetchColumn();

// Pending appointments badge
$stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE AND status != 'completed' AND status != 'cancelled'");
$pending_today = (int)$stmt->fetchColumn();

function calculateAge($dob) {
    if (!$dob) return 'N/A';
    $bday = new DateTime($dob);
    $today = new DateTime('today');
    $diff = $today->diff($bday);
    if ($diff->y > 0) return $diff->y . 'y ' . $diff->m . 'm';
    if ($diff->m > 0) return $diff->m . 'm ' . $diff->d . 'd';
    return $diff->d . ' days';
}

$current_date = date('d M, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Directory — VaxiCare Clinical Station</title>
    <meta name="description" content="Pediatric immunization records, guardian profiles, and patient directory for nursing staff.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/nurse_theme.css">
    <style>
        .filter-toolbar {
            background: #ffffff;
            border: 1px solid var(--nr-border);
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .search-box-wrap {
            position: relative;
            flex: 1;
            min-width: 260px;
            max-width: 440px;
        }

        .search-box-wrap input {
            width: 100%;
            padding: 10px 16px 10px 38px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            font-size: 13.5px;
            color: #0f172a;
            outline: none;
            transition: all 0.2s;
        }

        .search-box-wrap input:focus {
            background: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .search-box-wrap i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 14px;
        }

        .filter-chips {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-chip {
            padding: 7px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            color: #64748b;
            background: #f1f5f9;
            border: 1px solid transparent;
            transition: all 0.2s;
        }

        .filter-chip:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .filter-chip.active {
            background: #eff6ff;
            color: #2563eb;
            border-color: #bfdbfe;
        }

        .patient-avatar-box {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: 1.5px solid #dbeafe;
            object-fit: cover;
            flex-shrink: 0;
        }

        .gender-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 700;
        }

        .gender-male {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #dbeafe;
        }

        .gender-female {
            background: #fdf2f8;
            color: #be185d;
            border: 1px solid #fbcfe8;
        }

        .dose-count-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
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
            <a href="patients.php" class="sidebar-item active">
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
                    <h1>Patient Directory</h1>
                    <div class="topbar-breadcrumbs" style="display:flex; align-items:center; gap:6px; margin:0;">
                        <span>Pediatric Care</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span class="current">Patient Directory</span>
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

        <!-- ===== KPI STRIP (ENLARGED & PROMINENT) ===== -->
        <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 26px;">
            <div class="stat-card card-blue" style="padding: 22px 26px; min-height: 110px;">
                <div class="stat-icon icon-blue" style="width: 52px; height: 52px; font-size: 22px; border-radius: 14px;"><i class="fa-solid fa-children"></i></div>
                <div class="stat-info">
                    <div class="stat-label" style="font-size: 12.5px; font-weight: 700;">Total Enrolled</div>
                    <div class="stat-value" style="font-size: 28px; line-height: 1.15;"><?= number_format($total_patients_count) ?></div>
                    <div class="stat-trend trend-neutral" style="font-size: 12px; margin-top: 4px;">
                        <i class="fa-solid fa-id-card"></i> Registered pediatric infants
                    </div>
                </div>
            </div>

            <div class="stat-card card-purple" style="padding: 22px 26px; min-height: 110px;">
                <div class="stat-icon icon-blue" style="width: 52px; height: 52px; font-size: 22px; border-radius: 14px; background: #eff6ff; color: #2563eb;"><i class="fa-solid fa-mars"></i></div>
                <div class="stat-info">
                    <div class="stat-label" style="font-size: 12.5px; font-weight: 700;">Boys (Male)</div>
                    <div class="stat-value" style="font-size: 28px; line-height: 1.15;"><?= number_format($male_count) ?></div>
                    <div class="stat-trend" style="font-size: 12px; margin-top: 4px; color: #2563eb; font-weight: 600;">
                        <i class="fa-solid fa-chart-pie"></i> <?= $total_patients_count > 0 ? round(($male_count / $total_patients_count) * 100) : 0 ?>% of roster
                    </div>
                </div>
            </div>

            <div class="stat-card card-amber" style="padding: 22px 26px; min-height: 110px;">
                <div class="stat-icon icon-rose" style="width: 52px; height: 52px; font-size: 22px; border-radius: 14px;"><i class="fa-solid fa-venus"></i></div>
                <div class="stat-info">
                    <div class="stat-label" style="font-size: 12.5px; font-weight: 700;">Girls (Female)</div>
                    <div class="stat-value" style="font-size: 28px; line-height: 1.15;"><?= number_format($female_count) ?></div>
                    <div class="stat-trend" style="font-size: 12px; margin-top: 4px; color: #be185d; font-weight: 600;">
                        <i class="fa-solid fa-chart-pie"></i> <?= $total_patients_count > 0 ? round(($female_count / $total_patients_count) * 100) : 0 ?>% of roster
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== SEARCH & FILTER TOOLBAR ===== -->
        <div class="filter-toolbar">
            <form method="GET" action="" style="display:flex; align-items:center; gap:12px; flex:1; flex-wrap:wrap;">
                <div class="search-box-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="search" placeholder="Search by infant name, guardian or phone..." value="<?= htmlspecialchars($search_query) ?>">
                </div>

                <?php if($gender_filter): ?>
                    <input type="hidden" name="gender" value="<?= htmlspecialchars($gender_filter) ?>">
                <?php endif; ?>

                <button type="submit" class="nr-btn nr-btn-sm nr-btn-primary">
                    Search
                </button>
                
                <?php if($search_query !== '' || $gender_filter !== ''): ?>
                    <a href="patients.php" class="nr-btn nr-btn-sm nr-btn-outline" style="text-decoration:none;">
                        <i class="fa-solid fa-xmark"></i> Clear Filters
                    </a>
                <?php endif; ?>
            </form>

            <div class="filter-chips">
                <span style="font-size:12px; font-weight:700; color:#94a3b8; margin-right:4px;">Filter:</span>
                <a href="patients.php<?= $search_query ? '?search=' . urlencode($search_query) : '' ?>" class="filter-chip <?= empty($gender_filter) ? 'active' : '' ?>">All</a>
                <a href="patients.php?gender=Male<?= $search_query ? '&search=' . urlencode($search_query) : '' ?>" class="filter-chip <?= $gender_filter === 'Male' ? 'active' : '' ?>"><i class="fa-solid fa-mars"></i> Boys</a>
                <a href="patients.php?gender=Female<?= $search_query ? '&search=' . urlencode($search_query) : '' ?>" class="filter-chip <?= $gender_filter === 'Female' ? 'active' : '' ?>"><i class="fa-solid fa-venus"></i> Girls</a>
            </div>
        </div>

        <!-- ===== PATIENT DIRECTORY TABLE CARD ===== -->
        <div class="mar-card" style="padding: 20px;">
            <div class="mar-card-header" style="margin-bottom: 16px;">
                <h3 class="mar-card-title">
                    <i class="fa-solid fa-address-book" style="color:var(--nr-primary);"></i>
                    Pediatric Roster Records
                    <span style="font-size:12px; font-weight:600; color:#64748b; margin-left:8px;">(<?= count($patients) ?> results)</span>
                </h3>
            </div>

            <div style="overflow-x:auto;">
                <table class="mar-table">
                    <thead>
                        <tr>
                            <th>Infant / Patient</th>
                            <th>Age &amp; DOB</th>
                            <th>Gender</th>
                            <th>Parent / Guardian</th>
                            <th>Phone Contact</th>
                            <th>Vaccinations</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($patients)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; color: var(--nr-text-muted); padding: 48px 16px;">
                                    <div style="width:48px; height:48px; border-radius:50%; background:#f1f5f9; display:inline-flex; align-items:center; justify-content:center; margin-bottom:10px; color:#94a3b8; font-size:20px;">
                                        <i class="fa-solid fa-user-slash"></i>
                                    </div>
                                    <div style="font-weight:700; color:#334155; font-size:14px;">No patients match the search criteria</div>
                                    <div style="font-size:12px; color:#94a3b8; margin-top:4px;">Try searching with a different name or clear current filters</div>
                                    <div style="margin-top:16px;">
                                        <a href="register_patient.php" class="nr-btn nr-btn-sm nr-btn-primary" style="text-decoration:none;">
                                            <i class="fa-solid fa-user-plus"></i> Register New Infant
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($patients as $p): ?>
                                <tr>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:12px;">
                                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($p['name']) ?>&background=<?= $p['gender'] === 'Female' ? 'fdf2f8&color=be185d' : 'eff6ff&color=1d4ed8' ?>&bold=true" class="patient-avatar-box" alt="<?= htmlspecialchars($p['name']) ?>">
                                            <div>
                                                <a href="view_patient.php?id=<?= $p['id'] ?>" style="font-weight:700; color:#0f172a; text-decoration:none; font-size:13.5px; display:block;">
                                                    <?= htmlspecialchars($p['name']) ?>
                                                </a>
                                                <span style="font-size:11px; color:#64748b; font-weight:600;">ID #C-<?= str_pad($p['id'], 4, '0', STR_PAD_LEFT) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:700; color:#1e293b; font-size:13px;"><?= calculateAge($p['date_of_birth']) ?></div>
                                        <div style="font-size:11px; color:#64748b;"><?= date('d M, Y', strtotime($p['date_of_birth'])) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($p['gender'] === 'Female'): ?>
                                            <span class="gender-badge gender-female"><i class="fa-solid fa-venus"></i> Female</span>
                                        <?php else: ?>
                                            <span class="gender-badge gender-male"><i class="fa-solid fa-mars"></i> Male</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; color:#334155; font-size:13px;"><?= htmlspecialchars($p['guardian_name']) ?></div>
                                        <?php if (!empty($p['guardian_email'])): ?>
                                            <div style="font-size:11px; color:#64748b;"><?= htmlspecialchars($p['guardian_email']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="tel:<?= htmlspecialchars($p['guardian_phone']) ?>" style="font-size:12.5px; font-weight:600; color:#2563eb; text-decoration:none;">
                                            <i class="fa-solid fa-phone" style="font-size:11px; margin-right:3px;"></i> <?= htmlspecialchars($p['guardian_phone']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="dose-count-chip">
                                            <i class="fa-solid fa-syringe"></i> <?= (int)$p['completed_doses'] ?> doses given
                                        </span>
                                    </td>
                                    <td style="text-align:right;">
                                        <div style="display:inline-flex; align-items:center; gap:6px;">
                                            <a href="triage.php?child_id=<?= $p['id'] ?>" class="nr-btn nr-btn-sm nr-btn-outline" title="Record Triage Vitals" style="padding:5px 9px; font-size:12px;">
                                                <i class="fa-solid fa-heart-pulse"></i>
                                            </a>
                                            <a href="view_patient.php?id=<?= $p['id'] ?>" class="nr-btn nr-btn-sm nr-btn-primary" style="padding:5px 12px; font-size:12px; text-decoration:none;">
                                                View Passport <i class="fa-solid fa-arrow-right" style="font-size:10px;"></i>
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
