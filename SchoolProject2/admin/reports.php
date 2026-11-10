<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['name'];

// System statistics
$total_users        = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_appointments = $pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
$completed_appts    = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'completed'")->fetchColumn();
$missed_appts       = $pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'no-show'")->fetchColumn();
$total_invoices     = $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
$paid_invoices      = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'Paid'")->fetchColumn();

// Low stock count
$low_stock_count    = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= low_stock_threshold")->fetchColumn();

// Recent System Logs (Activity from appointments & requisitions)
$stmt = $pdo->query("
    SELECT 'Appointment Scheduled' as activity, c.name as detail, a.created_at, 'fa-calendar-check' as icon
    FROM appointments a
    JOIN children c ON a.child_id = c.id
    UNION ALL
    SELECT 'Drug Requisition Dispatched' as activity, CONCAT(ri.requested_quantity, 'x ', v.name) as detail, r.created_at, 'fa-boxes-stacked' as icon
    FROM requisitions r
    JOIN requisition_items ri ON ri.requisition_id = r.id
    JOIN vaccines v ON ri.vaccine_id = v.id
    ORDER BY created_at DESC
    LIMIT 15
");
$audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Hospital Audit Logs &amp; Analytics — VaxCare Admin</title>
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
            <a href="inventory.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-stacked"></i> Vaccine Inventory
                <?php if($low_stock_count > 0): ?>
                    <span class="badge-red"><?= $low_stock_count ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Governance</div>
            <a href="reports.php" class="sidebar-item active">
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
            <div class="topbar-left">
                <h1>Hospital Audit Logs &amp; Performance Analytics</h1>
                <div class="topbar-date-pill">
                    <i class="fa-solid fa-clock-rotate-left" style="color:var(--admin-secondary);"></i>
                    <span>Real-Time Clinical &amp; Supply Chain Event Logging</span>
                </div>
            </div>
            <div class="topbar-right" style="display:flex; align-items:center; gap:12px;">
                <div style="display:flex; align-items:center; gap:10px; background:#ffffff; padding:4px 6px; border-radius:14px; border:1px solid #e2e8f0; box-shadow:0 2px 10px rgba(112, 144, 176, 0.06);">
                    <a href="export_reports.php?format=csv" class="admin-btn admin-btn-sm" style="background:#ecfdf5; color:#047857 !important; border:1px solid #a7f3d0; border-radius:10px; font-weight:700; text-decoration:none;" title="Download Official MOH CSV Data">
                        <i class="fa-solid fa-file-csv" style="font-size:14px; color:#05cd99;"></i> Export MOH CSV
                    </a>
                    <a href="export_reports.php?format=pdf_print" target="_blank" class="admin-btn admin-btn-sm admin-btn-primary" style="border-radius:10px; font-weight:700; text-decoration:none;" title="Print Official Health Audit Document">
                        <i class="fa-solid fa-print"></i> Print Audit Report
                    </a>
                </div>
                <div class="topbar-profile" style="margin-left:4px;">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=1B254B&color=fff&bold=true" alt="Admin Avatar">
                    <div class="topbar-profile-info">
                        <span class="topbar-profile-name"><?= htmlspecialchars($admin_name) ?></span>
                        <span class="topbar-profile-role">Super Administrator</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- KPI Summary Cards -->
        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 24px;">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="fa-solid fa-users-gear"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($total_users) ?></div>
                    <div class="stat-label">Registered Accounts</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-green"><i class="fa-solid fa-calendar-check"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($completed_appts) ?> <span style="font-size:13px; font-weight:600; color:var(--admin-text-muted);">/ <?= number_format($total_appointments) ?></span></div>
                    <div class="stat-label">Completed Vaccinations</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-red"><i class="fa-solid fa-calendar-xmark"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($missed_appts) ?></div>
                    <div class="stat-label">Missed Appointments</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-yellow"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($paid_invoices) ?> <span style="font-size:13px; font-weight:600; color:var(--admin-text-muted);">/ <?= number_format($total_invoices) ?></span></div>
                    <div class="stat-label">Settled Billing Invoices</div>
                </div>
            </div>
        </div>

        <!-- Audit Log Table -->
        <div class="mar-card">
            <div class="mar-card-header">
                <div>
                    <div class="mar-card-title"><i class="fa-solid fa-shield-halved" style="color:var(--admin-primary); margin-right:8px;"></i> Hospital System Activity Stream</div>
                    <div class="mar-card-subtitle">Real-time audit log of clinical encounters, pharmacy dispatches, and administrative events</div>
                </div>
                <span class="role-badge role-badge-admin" style="font-size:11px;">
                    <i class="fa-solid fa-clock"></i> Last 15 Chronological Events
                </span>
            </div>

            <div class="table-responsive">
                <table class="mar-table">
                    <thead>
                        <tr>
                            <th>Event Category</th>
                            <th>Clinical / Supply Chain Details</th>
                            <th>Timestamp</th>
                            <th>Audit Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($audit_logs)): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state">
                                        <div class="empty-state-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                                        <div class="empty-state-title">No audit entries recorded</div>
                                        <div class="empty-state-sub">Operational events will appear here automatically as activities occur.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($audit_logs as $log): ?>
                                <tr>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:12px; font-weight:700; color:var(--admin-navy);">
                                            <div style="width:34px; height:34px; border-radius:10px; background:#e0e7ff; color:var(--admin-primary); display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0;">
                                                <i class="fa-solid <?= $log['icon'] ?>"></i>
                                            </div>
                                            <span><?= htmlspecialchars($log['activity']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:700; color:var(--admin-navy);"><?= htmlspecialchars($log['detail']) ?></div>
                                        <div class="text-xs text-muted">System transaction verified</div>
                                    </td>
                                    <td>
                                        <div style="font-weight:700; color:var(--admin-navy); font-size:13px;">
                                            <?= date('M d, Y', strtotime($log['created_at'])) ?>
                                        </div>
                                        <div class="text-xs text-muted" style="font-family:monospace;">
                                            <i class="fa-regular fa-clock" style="margin-right:2px;"></i> <?= date('H:i:s', strtotime($log['created_at'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-badge role-badge-pharmacist" style="font-size:11px;">
                                            <i class="fa-solid fa-check-double"></i> Verified Event
                                        </span>
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
