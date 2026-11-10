<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['name'];
$success = '';
$error   = '';

// Handle Duty Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_duty') {
    $user_id = (int)$_POST['user_id'];
    $current_duty = (int)$_POST['current_duty'];
    $new_duty = $current_duty ? 0 : 1;
    
    $stmt = $pdo->prepare("UPDATE users SET is_on_duty = ? WHERE id = ?");
    if ($stmt->execute([$new_duty, $user_id])) {
        $success = "Staff duty status updated successfully.";
    } else {
        $error = "Failed to update duty status.";
    }
}

// Handle Provision Staff
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_staff') {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $role = $_POST['role'];
    $temp_pass = trim($_POST['temp_password']);

    if (empty($name) || empty($phone) || empty($role) || empty($temp_pass)) {
        $error = "All fields are required.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            $error = "A user account with this phone number already exists.";
        } else {
            $hash = password_hash($temp_pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, phone, password_hash, role, is_on_duty, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
            if ($stmt->execute([$name, $phone, $hash, $role])) {
                $_SESSION['new_staff_credentials'] = [
                    'name' => $name,
                    'phone' => $phone,
                    'role' => ucfirst($role),
                    'password' => $temp_pass
                ];
                $success = "New " . ucfirst($role) . " account created successfully! Temporary credentials generated.";
            } else {
                $error = "Failed to create staff account.";
            }
        }
    }
}

// Filters
$role_filter = $_GET['role'] ?? 'all';
$duty_filter = $_GET['duty'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$query = "SELECT id, name, phone, email, role, is_on_duty, created_at FROM users WHERE role IN ('doctor', 'nurse', 'pharmacy')";
$params = [];

if ($role_filter !== 'all') {
    $query .= " AND role = ?";
    $params[] = $role_filter;
}
if ($duty_filter !== 'all') {
    $query .= " AND is_on_duty = ?";
    $params[] = ($duty_filter === 'on' ? 1 : 0);
}
if ($search !== '') {
    $query .= " AND (name LIKE ? OR phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " ORDER BY is_on_duty DESC, role ASC, name ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$staff_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts
$total_doctors     = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'doctor'")->fetchColumn();
$total_nurses      = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'nurse'")->fetchColumn();
$total_pharmacists = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'pharmacy'")->fetchColumn();
$on_duty_count     = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('doctor', 'nurse', 'pharmacy') AND is_on_duty = 1")->fetchColumn();
$low_stock_count   = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= low_stock_threshold")->fetchColumn();

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
    <title>Medical Staff Roster — VaxiCare Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
        .search-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .search-wrap i {
            position: absolute;
            left: 12px;
            color: #94a3b8;
            font-size: 13px;
        }
        .search-wrap .form-control {
            padding-left: 34px;
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
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-border-all"></i> Dashboard
            </a>

            <div class="sidebar-section-title">Administration</div>
            <a href="user_management.php" class="sidebar-item">
                <i class="fa-solid fa-users-gear"></i> User Management
            </a>
            <a href="staff.php" class="sidebar-item active">
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
            <a href="reports.php" class="sidebar-item">
                <i class="fa-solid fa-chart-pie"></i> Audit Logs
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
                        <span style="color:var(--adm-text-primary);">Staff Directory</span>
                    </div>
                    <h1>Medical Staff Directory &amp; Rosters</h1>
                </div>
            </div>

            <div class="topbar-right">
                <a href="add_staff.php" class="ph-btn ph-btn-green ph-btn-sm" style="text-decoration:none;">
                    <i class="fa-solid fa-user-plus"></i> Onboard New Staff
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

        <!-- Temporary Credentials Card if created -->
        <?php if(isset($_SESSION['new_staff_credentials'])): 
            $c = $_SESSION['new_staff_credentials'];
            unset($_SESSION['new_staff_credentials']);
        ?>
            <div class="cred-box">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div>
                        <h4 style="margin:0; font-size:15px; font-weight:800; color:#1e40af;"><i class="fa-solid fa-key" style="margin-right:8px;"></i> Staff Temporary Login Credentials</h4>
                        <p style="margin:4px 0 0 0; font-size:12.5px; color:#2563eb;">Provide these credentials to the newly registered <strong><?= htmlspecialchars($c['role']) ?></strong> for their initial sign-in.</p>
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

        <!-- KPI Row -->
        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 24px;">
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-blue"><i class="fa-solid fa-user-doctor"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($total_doctors) ?></div>
                        <div class="stat-label">Doctors</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-green"><i class="fa-solid fa-user-nurse"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($total_nurses) ?></div>
                        <div class="stat-label">Nurses</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-yellow"><i class="fa-solid fa-prescription-bottle-medical"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($total_pharmacists) ?></div>
                        <div class="stat-label">Pharmacists</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-purple"><i class="fa-solid fa-user-clock"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($on_duty_count) ?></div>
                        <div class="stat-label">Currently On Duty</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Staff Table & Filters -->
        <div class="mar-card" style="box-shadow: 0 4px 20px rgba(112, 144, 176, 0.08); border: 1px solid #e0e5f2; border-radius: 18px; padding: 24px;">
            <div class="mar-card-header" style="flex-wrap:wrap; gap:16px; border-bottom:1px solid #f1f5f9; padding-bottom:18px; margin-bottom:20px;">
                <div>
                    <h3 class="mar-card-title"><i class="fa-solid fa-hospital-user" style="color:var(--adm-blue);"></i> Hospital Medical Roster</h3>
                    <div class="mar-card-subtitle">Manage clinical personnel, duty scheduling, and active service status</div>
                </div>

                <!-- Filters -->
                <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <div class="search-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" name="search" class="form-control" style="width:210px; font-size:13px;" placeholder="Search name or phone..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    
                    <select name="role" class="form-control" style="width:auto; font-size:13px;" onchange="this.form.submit()">
                        <option value="all" <?= $role_filter==='all'?'selected':'' ?>>All Medical Roles</option>
                        <option value="doctor" <?= $role_filter==='doctor'?'selected':'' ?>>Doctors (<?= $total_doctors ?>)</option>
                        <option value="nurse" <?= $role_filter==='nurse'?'selected':'' ?>>Nurses (<?= $total_nurses ?>)</option>
                        <option value="pharmacy" <?= $role_filter==='pharmacy'?'selected':'' ?>>Pharmacists (<?= $total_pharmacists ?>)</option>
                    </select>

                    <select name="duty" class="form-control" style="width:auto; font-size:13px;" onchange="this.form.submit()">
                        <option value="all" <?= $duty_filter==='all'?'selected':'' ?>>All Duty Status</option>
                        <option value="on" <?= $duty_filter==='on'?'selected':'' ?>>On Duty Only</option>
                        <option value="off" <?= $duty_filter==='off'?'selected':'' ?>>Off Duty Only</option>
                    </select>

                    <button type="submit" class="ph-btn ph-btn-outline ph-btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
                    
                    <?php if(!empty($search) || $role_filter !== 'all' || $duty_filter !== 'all'): ?>
                        <a href="staff.php" class="ph-btn ph-btn-outline ph-btn-sm" style="color:#64748b;" title="Clear filter"><i class="fa-solid fa-xmark"></i> Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-responsive">
                <table class="mar-table">
                    <thead>
                        <tr>
                            <th>Staff Member</th>
                            <th>Clinical Role</th>
                            <th>Phone / Login ID</th>
                            <th>Email Address</th>
                            <th>Duty Status</th>
                            <th>Enrolled Date</th>
                            <th style="text-align:right;">Roster Control</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($staff_members)): ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding:48px 20px; color:#64748b;">
                                    <i class="fa-solid fa-user-slash" style="font-size:36px; color:#cbd5e1; margin-bottom:12px; display:block;"></i>
                                    <div style="font-weight:700; font-size:15px; color:#1e293b;">No clinical staff records found</div>
                                    <p style="font-size:12.5px; color:#94a3b8; margin:4px 0 0 0;">Try adjusting your search criteria or register new staff.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($staff_members as $s): ?>
                                <tr>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:12px; font-weight:700; color:#0f172a;">
                                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($s['name']) ?>&background=111c44&color=fff&size=64" style="width:34px; height:34px; border-radius:50%; border:1px solid #e2e8f0;">
                                            <div>
                                                <div><?= htmlspecialchars($s['name']) ?></div>
                                                <div style="font-size:11px; font-weight:500; color:#64748b;">ID: #<?= $s['id'] ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($s['role'] === 'doctor'): ?>
                                            <span class="role-pill role-doctor"><i class="fa-solid fa-user-doctor"></i> Doctor</span>
                                        <?php elseif($s['role'] === 'nurse'): ?>
                                            <span class="role-pill role-nurse"><i class="fa-solid fa-user-nurse"></i> Nurse</span>
                                        <?php else: ?>
                                            <span class="role-pill role-pharmacy"><i class="fa-solid fa-pills"></i> Pharmacist</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-family:monospace; font-weight:700; color:var(--adm-blue); background:#f4f2ff; padding:3px 8px; border-radius:6px; font-size:12.5px;">
                                            <?= htmlspecialchars($s['phone']) ?>
                                        </span>
                                    </td>
                                    <td style="color:#64748b; font-size:12.5px;">
                                        <?php if(!empty($s['email'])): ?>
                                            <i class="fa-regular fa-envelope" style="font-size:11px; margin-right:4px; color:#94a3b8;"></i>
                                            <?= htmlspecialchars($s['email']) ?>
                                        <?php else: ?>
                                            <span style="color:#cbd5e1;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($s['is_on_duty']): ?>
                                            <span class="duty-badge on"><span class="duty-pulse"></span> On Duty</span>
                                        <?php else: ?>
                                            <span class="duty-badge off"><i class="fa-regular fa-circle" style="font-size:7px;"></i> Off Duty</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color:#64748b; font-size:12.5px; font-weight:500;">
                                        <i class="fa-regular fa-calendar" style="font-size:11px; margin-right:4px;"></i>
                                        <?= date('M d, Y', strtotime($s['created_at'])) ?>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_duty">
                                            <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
                                            <input type="hidden" name="current_duty" value="<?= $s['is_on_duty'] ?>">
                                            <button type="submit" class="ph-btn <?= $s['is_on_duty'] ? 'ph-btn-outline' : 'ph-btn-green' ?> ph-btn-sm" style="font-size:11.5px;">
                                                <i class="fa-solid fa-power-off" style="font-size:10px;"></i>
                                                <?= $s['is_on_duty'] ? 'Set Off Duty' : 'Set On Duty' ?>
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
