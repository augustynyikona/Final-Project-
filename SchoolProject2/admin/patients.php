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

// Search / Filter
$search = trim($_GET['search'] ?? '');

// ---- Register a new patient (guardian + child) ----
$reg_success = $reg_error = $reg_temp_pass = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_patient') {
    csrf_guard();
    $g_name   = trim($_POST['guardian_name'] ?? '');
    $g_phone  = preg_replace('/\D/', '', $_POST['guardian_phone'] ?? '');
    $g_email  = trim($_POST['guardian_email'] ?? '');
    $c_name   = trim($_POST['child_name'] ?? '');
    $c_dob    = trim($_POST['date_of_birth'] ?? '');
    $c_gender = $_POST['gender'] ?? '';

    if ($g_name === '' || $g_phone === '' || $c_name === '' || $c_dob === ''
        || !in_array($c_gender, ['Male', 'Female'], true)) {
        $reg_error = 'Guardian name, phone, child name, date of birth and gender are all required.';
    } elseif (!preg_match('/^\d{10}$/', $g_phone)) {
        $reg_error = 'Guardian phone must be exactly 10 digits (e.g. 0970000101).';
    } elseif ($g_email !== '' && !filter_var($g_email, FILTER_VALIDATE_EMAIL)) {
        $reg_error = 'Guardian email address is not valid.';
    } elseif (strtotime($c_dob) === false || strtotime($c_dob) > strtotime('today')) {
        $reg_error = 'Child date of birth must be a valid date in the past.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id, role FROM users WHERE phone = ?');
            $stmt->execute([$g_phone]);
            $guardian = $stmt->fetch();

            if ($guardian && $guardian['role'] !== 'guardian') {
                throw new RuntimeException('That phone number already belongs to a '
                    . $guardian['role'] . ' account. Use a different number.');
            }

            if ($guardian) {
                $guardian_id = (int)$guardian['id'];
            } else {
                $reg_temp_pass = 'VC' . random_int(100000, 999999);
                $ins = $pdo->prepare(
                    "INSERT INTO users (name, phone, email, password_hash, role)
                     VALUES (?, ?, ?, ?, 'guardian')"
                );
                $ins->execute([
                    $g_name,
                    $g_phone,
                    $g_email !== '' ? $g_email : null,
                    password_hash($reg_temp_pass, PASSWORD_DEFAULT),
                ]);
                $guardian_id = (int)$pdo->lastInsertId();
                audit_log($pdo, 'guardian.created', 'user', $guardian_id, $g_name . ' (' . $g_phone . ')');
            }

            $insc = $pdo->prepare(
                'INSERT INTO children (guardian_id, name, date_of_birth, gender) VALUES (?, ?, ?, ?)'
            );
            $insc->execute([$guardian_id, $c_name, $c_dob, $c_gender]);
            $child_id = (int)$pdo->lastInsertId();

            audit_log($pdo, 'patient.registered', 'child', $child_id,
                $c_name . ' (guardian #' . $guardian_id . ')');

            $pdo->commit();
            $reg_success = "Registered {$c_name} under guardian {$g_name}.";
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $reg_error = $e->getMessage();
        }
    }
}


$query = "
    SELECT c.id, c.name as child_name, c.date_of_birth, c.gender, u.name as guardian_name, u.phone as guardian_phone,
           (SELECT COUNT(*) FROM appointments WHERE child_id = c.id AND status = 'completed') as total_vaccines,
           (SELECT COUNT(*) FROM appointments WHERE child_id = c.id AND status = 'scheduled') as upcoming_vaccines
    FROM children c
    JOIN users u ON c.guardian_id = u.id
";
$params = [];

if (!empty($search)) {
    $query .= " WHERE c.name LIKE ? OR u.name LIKE ? OR u.phone LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$query .= " ORDER BY c.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$children = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts
$total_children   = $pdo->query("SELECT COUNT(*) FROM children")->fetchColumn();
$total_guardians  = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'guardian'")->fetchColumn();
$low_stock_count  = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= low_stock_threshold")->fetchColumn();

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'admin' OR recipient_role = 'all') AND is_read = 0");
$stmt->execute([$admin_id]);
$unread_msg_count = $stmt->fetchColumn();

function calculateAge($dob) {
    $birthDate = new DateTime($dob);
    $today = new DateTime('today');
    $age = $birthDate->diff($today);
    if ($age->y > 0) return $age->y . ' yrs';
    if ($age->m > 0) return $age->m . ' mos';
    return $age->d . ' days';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Patient Registry — VaxCare Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/admin_theme.css">
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
            <a href="staff.php" class="sidebar-item">
                <i class="fa-solid fa-users"></i> Staff Directory
            </a>
            <a href="add_staff.php" class="sidebar-item">
                <i class="fa-solid fa-user-plus"></i> Onboard Staff
            </a>

            <div class="sidebar-section-title">Clinical &amp; Stock</div>
            <a href="patients.php" class="sidebar-item active">
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
            <div class="topbar-left">
                <h1>Master Patient Registry</h1>
                <div class="topbar-date-pill">
                    <i class="fa-solid fa-children"></i>
                    <span>National Immunization Cohort &bull; Pediatric Database</span>
                </div>
            </div>
            <div class="topbar-right">
                <a href="messages.php" class="topbar-icon-btn" title="System Messages">
                    <i class="fa-regular fa-bell"></i>
                    <?php if($unread_msg_count > 0): ?>
                        <span class="badge-dot"></span>
                    <?php endif; ?>
                </a>
                <div class="topbar-profile">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=1B254B&color=fff&bold=true" alt="Admin Avatar">
                    <div class="topbar-profile-info">
                        <span class="topbar-profile-name"><?= htmlspecialchars($admin_name) ?></span>
                        <span class="topbar-profile-role">Super Administrator</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- KPI Grid -->
        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 24px;">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="fa-solid fa-child"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($total_children) ?></div>
                    <div class="stat-label">Total Pediatric Patients</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green"><i class="fa-solid fa-users"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($total_guardians) ?></div>
                    <div class="stat-label">Registered Guardians</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-cyan"><i class="fa-solid fa-baby"></i></div>
                <div class="stat-info">
                    <?php
                        // Calculate quick Under-5 count
                        $u5_count = 0;
                        foreach ($children as $c) {
                            $b = new DateTime($c['date_of_birth']);
                            if ($b->diff(new DateTime('today'))->y < 5) $u5_count++;
                        }
                    ?>
                    <div class="stat-value"><?= count($children) > 0 ? $u5_count : 0 ?></div>
                    <div class="stat-label">Under-5 in Active Filter</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-purple"><i class="fa-solid fa-database"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= count($children) ?></div>
                    <div class="stat-label">Filtered Results</div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar (Modern Premium) -->
        <div class="filter-card" style="padding:18px 24px; border-radius:18px; box-shadow:0 4px 20px rgba(112, 144, 176, 0.08); border:1px solid #e0e5f2; margin-bottom:24px;">
            <!-- Register new patient -->
        <?php if ($reg_success || $reg_error): ?>
        <div style="margin-bottom:18px; padding:14px 20px; border-radius:14px; font-size:13.5px; font-weight:600;
                    border:1px solid <?= $reg_error ? '#fca5a5' : '#86efac' ?>;
                    background:<?= $reg_error ? '#fef2f2' : '#f0fdf4' ?>;
                    color:<?= $reg_error ? '#991b1b' : '#166534' ?>;">
            <?= htmlspecialchars($reg_error ?: $reg_success) ?>
            <?php if ($reg_temp_pass): ?>
                <div style="font-weight:500; margin-top:6px;">
                    Temporary guardian password:
                    <strong><?= htmlspecialchars($reg_temp_pass) ?></strong>
                    &mdash; share it once; it is not shown again.
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <details style="margin-bottom:18px; background:#fff; border:1.5px solid #e2e8f0; border-radius:14px;">
            <summary style="cursor:pointer; padding:16px 20px; font-weight:700; font-size:14px; color:#1B254B; list-style:none;">
                <i class="fa-solid fa-user-plus" style="color:#2563eb; margin-right:8px;"></i>
                Register New Patient (guardian + child)
            </summary>
            <form method="POST" style="padding:4px 20px 20px; display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:14px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_patient">

                <label style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.4px;">
                    Guardian Full Name
                    <input type="text" name="guardian_name" required placeholder="e.g. Grace Banda"
                           style="width:100%; margin-top:6px; height:42px; border-radius:10px; border:1.5px solid #e2e8f0; padding:0 12px; font-size:13.5px;">
                </label>

                <label style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.4px;">
                    Guardian Phone
                    <input type="tel" name="guardian_phone" required placeholder="0970000101" pattern="\d{10}"
                           style="width:100%; margin-top:6px; height:42px; border-radius:10px; border:1.5px solid #e2e8f0; padding:0 12px; font-size:13.5px;">
                </label>

                <label style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.4px;">
                    Guardian Email (optional)
                    <input type="email" name="guardian_email" placeholder="name@example.com"
                           style="width:100%; margin-top:6px; height:42px; border-radius:10px; border:1.5px solid #e2e8f0; padding:0 12px; font-size:13.5px;">
                </label>

                <label style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.4px;">
                    Child Full Name
                    <input type="text" name="child_name" required placeholder="e.g. Tawonga Banda"
                           style="width:100%; margin-top:6px; height:42px; border-radius:10px; border:1.5px solid #e2e8f0; padding:0 12px; font-size:13.5px;">
                </label>

                <label style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.4px;">
                    Date of Birth
                    <input type="date" name="date_of_birth" required max="<?= date('Y-m-d') ?>"
                           style="width:100%; margin-top:6px; height:42px; border-radius:10px; border:1.5px solid #e2e8f0; padding:0 12px; font-size:13.5px;">
                </label>

                <label style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.4px;">
                    Gender
                    <select name="gender" required
                            style="width:100%; margin-top:6px; height:42px; border-radius:10px; border:1.5px solid #e2e8f0; padding:0 12px; font-size:13.5px;">
                        <option value="">Select…</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </label>

                <div style="grid-column:1/-1; display:flex; justify-content:flex-end;">
                    <button type="submit"
                            style="height:44px; padding:0 26px; border:0; border-radius:11px; background:#1B254B; color:#fff; font-weight:700; font-size:13.5px; cursor:pointer;">
                        <i class="fa-solid fa-check"></i> Register Patient
                    </button>
                </div>
            </form>
        </details>

        <form method="GET" class="filter-bar" style="display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:12px; flex:1; min-width:300px;">
                    <div class="filter-input-wrap" style="flex:1;">
                        <i class="fa-solid fa-magnifying-glass" style="left:16px; font-size:14px; color:#94a3b8;"></i>
                        <input type="text" name="search" class="filter-input" placeholder="Search by child name, guardian name, or phone number..." value="<?= htmlspecialchars($search) ?>" style="height:46px; border-radius:14px; font-size:13.5px; padding-left:46px; border:1.5px solid #e2e8f0; background:#f8fafc;">
                    </div>
                    <button type="submit" class="admin-btn admin-btn-primary" style="height:46px; padding:0 22px; border-radius:14px; font-size:13.5px; font-weight:700;">
                        <i class="fa-solid fa-filter"></i> Apply Filter
                    </button>
                    <?php if($search): ?>
                        <a href="patients.php" class="admin-btn admin-btn-secondary" style="height:46px; padding:0 18px; border-radius:14px; font-size:13.5px; font-weight:600;">
                            <i class="fa-solid fa-rotate-left"></i> Reset
                        </a>
                    <?php endif; ?>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span class="role-badge role-badge-admin" style="padding:8px 14px; font-size:12px; border-radius:10px;">
                        <i class="fa-solid fa-users-viewfinder"></i> Matching: <?= count($children) ?> of <?= $total_children ?> Records
                    </span>
                </div>
            </form>
        </div>

        <!-- Directory Table -->
        <div class="mar-card">
            <div class="mar-card-header">
                <div>
                    <div class="mar-card-title"><i class="fa-solid fa-address-book" style="color:var(--admin-primary); margin-right:8px;"></i> Hospital Patient Registry</div>
                    <div class="mar-card-subtitle">Comprehensive pediatric records linked to verified guardian accounts</div>
                </div>
                <span class="role-badge role-badge-admin" style="font-size:11px;">
                    <i class="fa-solid fa-fingerprint"></i> <?= count($children) ?> Registered Children
                </span>
            </div>

            <div class="table-responsive">
                <table class="mar-table">
                    <thead>
                        <tr>
                            <th>Child Name</th>
                            <th>Age / DOB</th>
                            <th>Demographic</th>
                            <th>Guardian Name</th>
                            <th>Guardian Contact</th>
                            <th>Administered Doses</th>
                            <th>Pipeline</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($children)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <div class="empty-state-icon"><i class="fa-solid fa-child-reaching"></i></div>
                                        <div class="empty-state-title">No pediatric records found</div>
                                        <div class="empty-state-sub">Try searching with another name or clearing the current search filter.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($children as $c): 
                                $dob_dt = new DateTime($c['date_of_birth']);
                                $is_under_5 = $dob_dt->diff(new DateTime('today'))->y < 5;
                            ?>
                                <tr>
                                    <td>
                                        <div class="patient-cell">
                                            <div class="patient-avatar-box">
                                                <i class="fa-solid fa-baby"></i>
                                            </div>
                                            <div>
                                                <div class="patient-name"><?= htmlspecialchars($c['child_name']) ?></div>
                                                <div class="patient-sub">ID: #C-<?= str_pad($c['id'], 5, '0', STR_PAD_LEFT) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:700; color:var(--admin-navy);">
                                            <?= calculateAge($c['date_of_birth']) ?>
                                            <?php if($is_under_5): ?>
                                                <span class="badge-status bg-blue-light" style="font-size:10px; margin-left:4px; padding:2px 6px;">U-5</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-xs text-muted"><?= date('M d, Y', strtotime($c['date_of_birth'])) ?></div>
                                    </td>
                                    <td>
                                        <span class="role-badge <?= strtolower($c['gender']) === 'male' ? 'role-badge-doctor' : 'role-badge-nurse' ?>">
                                            <i class="fa-solid <?= strtolower($c['gender']) === 'male' ? 'fa-mars' : 'fa-venus' ?>"></i>
                                            <?= htmlspecialchars($c['gender'] ?: 'Unspecified') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight:700; color:var(--admin-navy);"><?= htmlspecialchars($c['guardian_name']) ?></div>
                                        <div class="text-xs text-muted"><i class="fa-solid fa-shield-halved" style="color:var(--admin-success); margin-right:3px;"></i> Verified Guardian</div>
                                    </td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <span style="font-family:'SF Pro Text', monospace; font-weight:700; color:var(--admin-navy); letter-spacing:0.5px;">
                                                <?= htmlspecialchars($c['guardian_phone']) ?>
                                            </span>
                                            <?php if(!empty($c['guardian_phone'])): ?>
                                                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $c['guardian_phone']) ?>" target="_blank" class="ph-btn ph-btn-outline ph-btn-sm" style="padding:2px 6px; font-size:11px; border-radius:6px; color:#25D366; border-color:#25D366;" title="Chat via WhatsApp">
                                                    <i class="fa-brands fa-whatsapp"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-badge role-badge-pharmacist" style="font-size:11px;">
                                            <i class="fa-solid fa-syringe"></i> <?= $c['total_vaccines'] ?> Doses Completed
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($c['upcoming_vaccines'] > 0): ?>
                                            <span class="role-badge role-badge-doctor" style="font-size:11px;">
                                                <i class="fa-regular fa-clock"></i> <?= $c['upcoming_vaccines'] ?> Scheduled
                                            </span>
                                        <?php else: ?>
                                            <span class="text-xs text-muted" style="font-weight:600;">None Pending</span>
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

    <script src="../js/notifications.js"></script>
</body>
</html>
