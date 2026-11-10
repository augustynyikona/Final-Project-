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

// Handle Onboarding New Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_admin') {
    $name     = trim($_POST['name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($name) || empty($phone) || empty($password)) {
        $error = "All fields are required to onboard a new Administrator.";
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        if ($stmt->fetch()) {
            $error = "A user account with this phone number already exists.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, phone, password_hash, role, is_on_duty, created_at) VALUES (?, ?, ?, 'admin', 1, NOW())");
            if ($stmt->execute([$name, $phone, $hash])) {
                $success = "New System Administrator ('" . htmlspecialchars($name) . "') onboarded successfully!";
            } else {
                $error = "Failed to onboard new Administrator.";
            }
        }
    }
}

// Handle Reset Password for Any User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $target_user_id = (int)$_POST['target_user_id'];
    $new_password   = trim($_POST['new_password'] ?? '');

    if (empty($new_password)) {
        $error = "New password cannot be empty.";
    } else {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        if ($stmt->execute([$hash, $target_user_id])) {
            $stmtName = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
            $stmtName->execute([$target_user_id]);
            $u_info = $stmtName->fetch();
            $success = "Password for " . htmlspecialchars($u_info['name']) . " (" . ucfirst($u_info['role']) . ") updated successfully.";
        } else {
            $error = "Failed to reset user password.";
        }
    }
}

// Handle Delete / Remove User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $target_user_id = (int)$_POST['target_user_id'];

    if ($target_user_id === $admin_id) {
        $error = "Security Restriction: You cannot remove your own active administrator account.";
    } else {
        $stmtName = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
        $stmtName->execute([$target_user_id]);
        $u_info = $stmtName->fetch();

        if ($u_info) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            if ($stmt->execute([$target_user_id])) {
                $success = "User account '" . htmlspecialchars($u_info['name']) . "' (" . ucfirst($u_info['role']) . ") removed from system.";
            } else {
                $error = "Failed to delete user account.";
            }
        }
    }
}

// Filters & Query
$role_filter = $_GET['role'] ?? 'all';
$search      = trim($_GET['search'] ?? '');

$query = "SELECT id, name, phone, role, is_on_duty, created_at FROM users WHERE 1=1";
$params = [];

if ($role_filter !== 'all') {
    $query .= " AND role = ?";
    $params[] = $role_filter;
}
if ($search !== '') {
    $query .= " AND (name LIKE ? OR phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counters
$cnt_admin     = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$cnt_doctor    = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'doctor'")->fetchColumn();
$cnt_nurse     = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'nurse'")->fetchColumn();
$cnt_pharmacy  = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'pharmacy'")->fetchColumn();
$cnt_guardian  = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'guardian'")->fetchColumn();
$cnt_total     = $cnt_admin + $cnt_doctor + $cnt_nurse + $cnt_pharmacy + $cnt_guardian;

$low_stock_count  = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= low_stock_threshold")->fetchColumn();
$stmtMsg = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'admin') AND is_read = 0");
$stmtMsg->execute([$admin_id]);
$unread_msg_count = $stmtMsg->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management &amp; Governance — VaxiCare Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/admin_theme.css">
    <style>
        .role-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.2px;
        }
        .role-admin    { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .role-doctor   { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .role-nurse    { background: #fffbe8; color: #b45309; border: 1px solid #fde68a; }
        .role-pharmacy { background: #ecfdf5; color: #047857; border: 1px solid #6ee7b7; }
        .role-guardian { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }

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
            <a href="user_management.php" class="sidebar-item active">
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
                        <span style="color:var(--adm-text-primary);">User Management</span>
                    </div>
                    <h1>User Access Controls &amp; Governance</h1>
                </div>
            </div>

            <div class="topbar-right">
                <button class="ph-btn ph-btn-blue ph-btn-sm" onclick="document.getElementById('createAdminModal').style.display='flex'">
                    <i class="fa-solid fa-user-shield"></i> Onboard Administrator
                </button>

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

        <!-- KPI Grid -->
        <div class="stats-grid" style="grid-template-columns: repeat(5, 1fr); margin-bottom: 24px;">
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-blue"><i class="fa-solid fa-users"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($cnt_total) ?></div>
                        <div class="stat-label">Total Accounts</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-blue"><i class="fa-solid fa-user-shield"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($cnt_admin) ?></div>
                        <div class="stat-label">Administrators</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-green"><i class="fa-solid fa-user-doctor"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($cnt_doctor) ?></div>
                        <div class="stat-label">Doctors</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-yellow"><i class="fa-solid fa-user-nurse"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($cnt_nurse) ?></div>
                        <div class="stat-label">Nurses</div>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="stat-icon icon-purple"><i class="fa-solid fa-hospital-user"></i></div>
                    <div class="stat-info">
                        <div class="stat-value"><?= number_format($cnt_guardian) ?></div>
                        <div class="stat-label">Guardians</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Directory Table & Controls -->
        <div class="mar-card" style="box-shadow: 0 4px 20px rgba(112, 144, 176, 0.08); border: 1px solid #e0e5f2; border-radius: 18px; padding: 24px;">
            <div class="mar-card-header" style="flex-wrap:wrap; gap:16px; border-bottom:1px solid #f1f5f9; padding-bottom:18px; margin-bottom:20px;">
                <div>
                    <h3 class="mar-card-title"><i class="fa-solid fa-users-gear" style="color:var(--adm-blue);"></i> System Accounts Directory</h3>
                    <div class="mar-card-subtitle">Manage access privileges, reset staff credentials, and enforce role permissions</div>
                </div>

                <!-- Filter Form -->
                <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <div class="search-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" name="search" class="form-control" style="width:230px; font-size:13px;" placeholder="Search name or phone..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    
                    <select name="role" class="form-control" style="width:auto; font-size:13px;" onchange="this.form.submit()">
                        <option value="all" <?= $role_filter==='all'?'selected':'' ?>>All Roles (<?= $cnt_total ?>)</option>
                        <option value="admin" <?= $role_filter==='admin'?'selected':'' ?>>Administrators (<?= $cnt_admin ?>)</option>
                        <option value="doctor" <?= $role_filter==='doctor'?'selected':'' ?>>Doctors (<?= $cnt_doctor ?>)</option>
                        <option value="nurse" <?= $role_filter==='nurse'?'selected':'' ?>>Nurses (<?= $cnt_nurse ?>)</option>
                        <option value="pharmacy" <?= $role_filter==='pharmacy'?'selected':'' ?>>Pharmacists (<?= $cnt_pharmacy ?>)</option>
                        <option value="guardian" <?= $role_filter==='guardian'?'selected':'' ?>>Guardians (<?= $cnt_guardian ?>)</option>
                    </select>

                    <button type="submit" class="ph-btn ph-btn-outline ph-btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
                    
                    <?php if(!empty($search) || $role_filter !== 'all'): ?>
                        <a href="user_management.php" class="ph-btn ph-btn-outline ph-btn-sm" style="color:#64748b;" title="Clear filter"><i class="fa-solid fa-xmark"></i> Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-responsive">
                <table class="mar-table">
                    <thead>
                        <tr>
                            <th>Account Name</th>
                            <th>Role / Department</th>
                            <th>Phone / Login ID</th>
                            <th>Registration Date</th>
                            <th style="text-align:right;">Governance Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($users_list)): ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:48px 20px; color:#64748b;">
                                    <i class="fa-solid fa-user-slash" style="font-size:36px; color:#cbd5e1; margin-bottom:12px; display:block;"></i>
                                    <div style="font-weight:700; font-size:15px; color:#1e293b;">No user accounts found</div>
                                    <p style="font-size:12.5px; color:#94a3b8; margin:4px 0 0 0;">Try adjusting your search terms or filter selection.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($users_list as $u): ?>
                                <tr>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:12px; font-weight:700; color:#0f172a;">
                                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($u['name']) ?>&background=111c44&color=fff&size=64" style="width:34px; height:34px; border-radius:50%; border:1px solid #e2e8f0;">
                                            <div>
                                                <div><?= htmlspecialchars($u['name']) ?></div>
                                                <?php if($u['id'] == $admin_id): ?>
                                                    <span style="font-size:9.5px; background:#eff6ff; color:#2563eb; padding:1px 6px; border-radius:4px; font-weight:800; border:1px solid #bfdbfe;">CURRENT SESSION</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-pill role-<?= $u['role'] ?>">
                                            <?php if($u['role']==='admin'): ?><i class="fa-solid fa-user-shield"></i> Admin
                                            <?php elseif($u['role']==='doctor'): ?><i class="fa-solid fa-user-doctor"></i> Doctor
                                            <?php elseif($u['role']==='nurse'): ?><i class="fa-solid fa-user-nurse"></i> Nurse
                                            <?php elseif($u['role']==='pharmacy'): ?><i class="fa-solid fa-pills"></i> Pharmacist
                                            <?php else: ?><i class="fa-solid fa-hospital-user"></i> Guardian<?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-family:monospace; font-weight:700; color:var(--adm-blue); background:#f4f2ff; padding:3px 8px; border-radius:6px; font-size:12.5px;">
                                            <?= htmlspecialchars($u['phone']) ?>
                                        </span>
                                    </td>
                                    <td style="color:#64748b; font-size:12.5px; font-weight:500;">
                                        <i class="fa-regular fa-calendar" style="font-size:11px; margin-right:4px;"></i>
                                        <?= date('M d, Y', strtotime($u['created_at'])) ?>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <!-- Reset Password Button -->
                                        <button class="ph-btn ph-btn-outline ph-btn-sm" style="font-size:11.5px; margin-right:4px;" onclick="openResetModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name'])) ?>', '<?= $u['role'] ?>')">
                                            <i class="fa-solid fa-key" style="color:var(--adm-blue);"></i> Reset
                                        </button>
                                        
                                        <!-- Delete / Remove User Button -->
                                        <?php if($u['id'] != $admin_id): ?>
                                            <button class="ph-btn ph-btn-red ph-btn-sm" style="font-size:11.5px;" onclick="openDeleteModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name'])) ?>', '<?= $u['role'] ?>')">
                                                <i class="fa-solid fa-trash-can"></i> Remove
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- ===== CREATE ADMIN MODAL ===== -->
    <div class="modal-overlay" id="createAdminModal">
        <div class="modal-card" style="width:460px;">
            <div class="modal-header-bar">
                <h3>
                    <div class="modal-icon-wrap" style="background:#eff6ff; color:#2563eb;">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    Onboard New Administrator
                </h3>
                <button type="button" class="modal-close-btn" onclick="document.getElementById('createAdminModal').style.display='none'">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="POST" class="modal-body-pad">
                <input type="hidden" name="action" value="create_admin">
                
                <div class="modal-field">
                    <label>Full Administrator Name</label>
                    <input type="text" name="name" class="modal-input" placeholder="e.g. Director Chileshe" required>
                </div>
                
                <div class="modal-field">
                    <label>Phone / Login ID</label>
                    <input type="tel" name="phone" class="modal-input" placeholder="e.g. 0970000099" required>
                </div>
                
                <div class="modal-field">
                    <label>Initial Security Password</label>
                    <input type="password" name="password" class="modal-input" placeholder="Create strong admin password" required>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #f1f5f9; padding-top:18px; margin-top:8px;">
                    <button type="button" class="ph-btn ph-btn-outline" onclick="document.getElementById('createAdminModal').style.display='none'">Cancel</button>
                    <button type="submit" class="ph-btn ph-btn-blue"><i class="fa-solid fa-user-plus"></i> Create Admin Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== RESET PASSWORD MODAL ===== -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-card" style="width:460px;">
            <div class="modal-header-bar">
                <h3>
                    <div class="modal-icon-wrap" style="background:#eff6ff; color:#2563eb;">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    Reset Account Password
                </h3>
                <button type="button" class="modal-close-btn" onclick="document.getElementById('resetModal').style.display='none'">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="modal-body-pad">
                <div class="modal-info-box">
                    <i class="fa-solid fa-circle-info" style="font-size:16px; margin-top:2px;"></i>
                    <div>
                        <div style="font-weight:700; color:#1e40af;">Target Account</div>
                        <div style="font-size:13.5px; font-weight:800; color:#0f172a; margin-top:2px;" id="resetUserName">User</div>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="target_user_id" id="resetTargetUserId">
                    
                    <div class="modal-field">
                        <label>New Assigned Password</label>
                        <div style="display:flex; gap:8px;">
                            <input type="text" name="new_password" id="newPassInput" class="modal-input" value="password" required style="font-family:monospace; font-weight:700;">
                            <button type="button" class="ph-btn ph-btn-outline" onclick="document.getElementById('newPassInput').value='VX-2026-Reset'" style="white-space:nowrap; font-size:12px;">Generate</button>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #f1f5f9; padding-top:18px; margin-top:16px;">
                        <button type="button" class="ph-btn ph-btn-outline" onclick="document.getElementById('resetModal').style.display='none'">Cancel</button>
                        <button type="submit" class="ph-btn ph-btn-green"><i class="fa-solid fa-floppy-disk"></i> Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== DELETE USER MODAL ===== -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal-card" style="width:450px;">
            <div class="modal-header-bar">
                <h3 style="color:#b91c1c;">
                    <div class="modal-icon-wrap" style="background:#fef2f2; color:#dc2626;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    Confirm Account Removal
                </h3>
                <button type="button" class="modal-close-btn" onclick="document.getElementById('deleteModal').style.display='none'">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="modal-body-pad">
                <div style="background:#fef2f2; border:1px solid #fca5a5; padding:16px; border-radius:12px; color:#991b1b; font-size:13px; line-height:1.5; margin-bottom:20px;">
                    <strong>Warning:</strong> You are about to permanently remove user account:
                    <div style="font-weight:800; font-size:14px; margin-top:6px; color:#7f1d1d;" id="deleteUserName">User</div>
                    <div style="font-size:12px; color:#b91c1c; margin-top:4px;">This action revokes immediate access and cannot be undone.</div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="target_user_id" id="deleteTargetUserId">

                    <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #f1f5f9; padding-top:18px;">
                        <button type="button" class="ph-btn ph-btn-outline" onclick="document.getElementById('deleteModal').style.display='none'">Cancel</button>
                        <button type="submit" class="ph-btn ph-btn-red"><i class="fa-solid fa-trash-can"></i> Confirm Deletion</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openResetModal(userId, userName, role) {
            document.getElementById('resetTargetUserId').value = userId;
            document.getElementById('resetUserName').innerText = userName + ' (' + role.toUpperCase() + ')';
            document.getElementById('resetModal').style.display = 'flex';
        }

        function openDeleteModal(userId, userName, role) {
            document.getElementById('deleteTargetUserId').value = userId;
            document.getElementById('deleteUserName').innerText = userName + ' (' + role.toUpperCase() + ')';
            document.getElementById('deleteModal').style.display = 'flex';
        }

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
