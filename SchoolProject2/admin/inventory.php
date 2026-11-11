<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['name'];

// Fetch all vaccines
$stmt = $pdo->query("
    SELECT v.*, 
           (SELECT COUNT(*) FROM vaccine_batches WHERE vaccine_id = v.id) as batch_count
    FROM vaccines v 
    ORDER BY (v.current_stock <= v.low_stock_threshold) DESC, v.name ASC
");
$vaccines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts
$total_types      = count($vaccines);
$low_stock_count  = count(array_filter($vaccines, fn($v) => $v['current_stock'] <= $v['low_stock_threshold']));
$total_vials      = array_sum(array_column($vaccines, 'current_stock'));

// Pending purchase orders
$pending_pos      = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending'")->fetchColumn();

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'admin' OR recipient_role = 'all') AND is_read = 0");
$stmt->execute([$admin_id]);
$unread_msg_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vaccine Inventory &amp; Cold Chain — VaxCare Admin</title>
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
            <a href="patients.php" class="sidebar-item">
                <i class="fa-solid fa-child-reaching"></i> Master Patients
            </a>
            <a href="inventory.php" class="sidebar-item active">
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
                <h1>Vaccine Inventory &amp; Cold Chain</h1>
                <div class="topbar-date-pill">
                    <i class="fa-solid fa-snowflake" style="color:var(--admin-secondary);"></i>
                    <span>Cold Chain Storage &bull; National Formulary Oversight</span>
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
                <div class="stat-icon icon-blue"><i class="fa-solid fa-prescription-bottle-medical"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= $total_types ?></div>
                    <div class="stat-label">Vaccine Formulations</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green"><i class="fa-solid fa-vials"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($total_vials) ?></div>
                    <div class="stat-label">Total In-Stock Vials</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-red"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= $low_stock_count ?></div>
                    <div class="stat-label">Low Stock Alerts</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-yellow"><i class="fa-solid fa-truck-ramp-box"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= $pending_pos ?></div>
                    <div class="stat-label">Pending Re-supply Orders</div>
                </div>
            </div>
        </div>

        <!-- Inventory Master Table -->
        <div class="mar-card">
            <div class="mar-card-header">
                <div>
                    <div class="mar-card-title"><i class="fa-solid fa-boxes-packing" style="color:var(--admin-primary); margin-right:8px;"></i> Hospital Stock Registry</div>
                    <div class="mar-card-subtitle">Real-time stock monitoring, buffer thresholds, and cold-chain capacity tracking</div>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <?php if($low_stock_count > 0): ?>
                        <span class="role-badge" style="background:#fee2e2; color:#b91c1c;">
                            <i class="fa-solid fa-triangle-exclamation"></i> <?= $low_stock_count ?> Require Reorder
                        </span>
                    <?php endif; ?>
                    <span class="role-badge role-badge-admin" style="font-size:11px;">
                        <i class="fa-solid fa-shield-virus"></i> <?= $total_types ?> Formulations
                    </span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="mar-table">
                    <thead>
                        <tr>
                            <th>Vaccine Formulation</th>
                            <th>Target Cohort / Schedule</th>
                            <th>Current Inventory</th>
                            <th>Buffer Threshold</th>
                            <th>Active Batches</th>
                            <th>Stock Capacity Gauge</th>
                            <th>Logistics Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($vaccines)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <div class="empty-state-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                                        <div class="empty-state-title">No vaccine records available</div>
                                        <div class="empty-state-sub">No vaccines have been entered into the national registry yet.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($vaccines as $v): 
                                $threshold = $v['low_stock_threshold'] ?: 100;
                                $pct = min(100, round(($v['current_stock'] / ($threshold * 3)) * 100));
                                $is_low = $v['current_stock'] <= $threshold;
                            ?>
                                <tr>
                                    <td>
                                        <div class="patient-cell">
                                            <div class="patient-avatar-box" style="background:<?= $is_low ? '#fee2e2' : '#e0e7ff' ?>; color:<?= $is_low ? '#dc2626' : 'var(--admin-primary)' ?>;">
                                                <i class="fa-solid fa-syringe"></i>
                                            </div>
                                            <div>
                                                <div class="patient-name"><?= htmlspecialchars($v['name']) ?></div>
                                                <div class="patient-sub"><?= htmlspecialchars($v['target_disease'] ?? 'Pediatric Immunization') ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-badge role-badge-nurse" style="font-size:11px;">
                                            <i class="fa-regular fa-calendar-check"></i> <?= htmlspecialchars(($v['recommended_age'] ?? null) ?: 'National Schedule') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-size:15px; font-weight:800; color:<?= $is_low ? '#dc2626':'var(--admin-navy)' ?>;">
                                            <?= number_format($v['current_stock']) ?> <span style="font-size:11px; font-weight:600; color:var(--admin-text-muted);">vials</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size:13px; font-weight:600; color:var(--admin-text-muted);">
                                            <?= number_format($threshold) ?> vials
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-badge role-badge-doctor" style="font-size:11px;">
                                            <i class="fa-solid fa-layer-group"></i> <?= $v['batch_count'] ?> Batches
                                        </span>
                                    </td>
                                    <td style="min-width:180px;">
                                        <div style="display:flex; justify-content:space-between; font-size:11px; font-weight:700; margin-bottom:4px;">
                                            <span style="color:var(--admin-text-muted);">Capacity</span>
                                            <span style="color:<?= $is_low ? '#dc2626':'#05CD99' ?>;"><?= $pct ?>%</span>
                                        </div>
                                        <div style="background:#e2e8f0; height:8px; border-radius:999px; overflow:hidden;">
                                            <div style="width:<?= $pct ?>%; height:100%; background:<?= $is_low ? '#ef4444':'linear-gradient(90deg, #4318FF, #05CD99)' ?>; border-radius:999px;"></div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if($is_low): ?>
                                            <span class="role-badge" style="background:#fee2e2; color:#b91c1c; font-size:11px;">
                                                <i class="fa-solid fa-circle-exclamation"></i> Low Stock
                                            </span>
                                        <?php else: ?>
                                            <span class="role-badge role-badge-pharmacist" style="font-size:11px;">
                                                <i class="fa-solid fa-circle-check"></i> Optimal Level
                                            </span>
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
