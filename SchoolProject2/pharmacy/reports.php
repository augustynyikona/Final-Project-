<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pharmacy') {
    header("Location: ../auth/login.php");
    exit;
}

$pharmacist_name = $_SESSION['name'] ?? 'Pharmacist';

// ---- CHART DATA: Stock Levels per vaccine ----
$stmt = $pdo->query("SELECT name, current_stock, low_stock_threshold FROM vaccines ORDER BY current_stock DESC");
$all_stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stock_labels = [];
$stock_values = [];
$threshold_values = [];
foreach ($all_stocks as $s) {
    $stock_labels[]     = $s['name'];
    $stock_values[]     = (int)$s['current_stock'];
    $threshold_values[] = (int)$s['low_stock_threshold'];
}

// ---- CHART DATA: 30-Day Dispense Trend (last 30 days daily) ----
$stmt = $pdo->query("
    SELECT DATE(r.created_at) as day, SUM(ri.requested_quantity) as doses
    FROM requisition_items ri
    JOIN requisitions r ON ri.requisition_id = r.id
    WHERE r.status IN ('Approved','Confirmed') AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(r.created_at)
    ORDER BY day ASC
");
$trend_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
$trend_labels = [];
$trend_values = [];
foreach ($trend_raw as $t) {
    $trend_labels[] = date('M d', strtotime($t['day']));
    $trend_values[] = (int)$t['doses'];
}

// ---- CHART DATA: Status Distribution donut ----
$status_rows = $pdo->query("
    SELECT 
        SUM(CASE WHEN current_stock > low_stock_threshold THEN 1 ELSE 0 END) as optimal,
        SUM(CASE WHEN current_stock > 0 AND current_stock <= low_stock_threshold THEN 1 ELSE 0 END) as low,
        SUM(CASE WHEN current_stock = 0 THEN 1 ELSE 0 END) as out_of_stock
    FROM vaccines
")->fetch(PDO::FETCH_ASSOC);

// ---- CHART DATA: Top Dispensed Vaccines (donut) ----
$stmt = $pdo->query("
    SELECT v.name as vaccine_name, SUM(ri.requested_quantity) as total_dispensed
    FROM requisition_items ri
    JOIN requisitions r ON ri.requisition_id = r.id
    JOIN vaccines v ON ri.vaccine_id = v.id
    WHERE r.status IN ('Approved','Confirmed') AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY v.id ORDER BY total_dispensed DESC LIMIT 6
");
$dispense_report = $stmt->fetchAll(PDO::FETCH_ASSOC);
$disp_labels = array_column($dispense_report, 'vaccine_name');
$disp_values = array_column($dispense_report, 'total_dispensed');

// ---- All-Time Requisition Log ----
$stmt = $pdo->query("
    SELECT r.id, r.shift_date, r.status, r.approved_at, r.created_at, u.name as nurse_name,
           SUM(ri.requested_quantity) as total_doses,
           COUNT(DISTINCT ri.vaccine_id) as item_count
    FROM requisitions r
    JOIN users u ON r.nurse_id = u.id
    LEFT JOIN requisition_items ri ON r.id = ri.requisition_id
    GROUP BY r.id
    ORDER BY r.created_at DESC LIMIT 50
");
$req_log = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sidebar counts
$pending_orders       = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending'")->fetchColumn();
$pending_requisitions = $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'Pending'")->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'pharmacy' OR recipient_role = 'pharmacist') AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_msg_count = $stmt->fetchColumn();
$low_stock_count  = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= low_stock_threshold")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Analytics &amp; Intelligence — VaxCare Pharmacy</title>
    <meta name="description" content="Interactive graphical stock analysis, dispense trends, and vaccine coverage for VaxCare pharmacy.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/pharmacy_theme.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .topbar-breadcrumbs {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 4px;
        }
        .topbar-breadcrumbs a {
            color: #64748b;
            text-decoration: none;
        }
        .topbar-breadcrumbs a:hover {
            color: #05cd99;
        }
        .topbar-icon-btn {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 11px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #475569;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .topbar-icon-btn:hover {
            color: #05cd99;
            border-color: #05cd99;
            background: #f0fdf4;
            transform: translateY(-1px);
        }
        .notif-ping {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ef4444;
            border: 2px solid #ffffff;
        }
        .topbar-profile-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #ffffff;
            padding: 5px 14px 5px 6px;
            border-radius: 30px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
        }
        .topbar-profile-box img {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            object-fit: cover;
        }
        .topbar-profile-meta {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        .topbar-profile-meta .name {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }
        .topbar-profile-meta .role {
            font-size: 10.5px;
            font-weight: 600;
            color: #05cd99;
        }

        /* KPI Quick Grid */
        .analytics-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }
        @media (max-width: 1024px) {
            .analytics-kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 640px) {
            .analytics-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
        .analytics-kpi-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .analytics-kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .analytics-kpi-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .analytics-kpi-title {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .analytics-kpi-val {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin-top: 2px;
            line-height: 1.1;
        }

        /* Chart Layout Grids */
        .charts-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        .charts-row-3 {
            display: grid;
            grid-template-columns: 1.7fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 992px) {
            .charts-row-2, .charts-row-3 {
                grid-template-columns: 1fr;
            }
        }

        .chart-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 22px 24px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
        }
        .chart-card-title {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }
        .chart-card-sub {
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
            margin-bottom: 18px;
        }

        .status-legend {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-top: 14px;
            justify-content: center;
        }
        .legend-dot {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
        }
        .legend-dot span {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }

        /* Modern Table */
        .ph-modern-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 13px;
        }
        .ph-modern-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 12px 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        .ph-modern-table td {
            padding: 15px 20px;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
            vertical-align: middle;
        }
        .ph-modern-table tr:hover td {
            background: #fafbfc;
        }

        .lot-code-pill {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            font-weight: 700;
            background: #f1f5f9;
            color: #0f172a;
            padding: 3px 8px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
        }

        /* Responsive Mobile Drawer */
        .mobile-sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 95;
        }
        .mobile-sidebar-backdrop.show {
            display: block;
        }
        .mobile-nav-toggle {
            display: none;
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: #0f172a;
            align-items: center;
            justify-content: center;
            margin-right: 14px;
            cursor: pointer;
        }
        @media (max-width: 1024px) {
            .mobile-nav-toggle {
                display: flex;
            }
            .sidebar {
                position: fixed;
                left: -280px;
                top: 0;
                bottom: 0;
                transition: left 0.3s ease;
                z-index: 100;
            }
            .sidebar.open {
                left: 0;
            }
        }
    </style>
</head>
<body class="app-body">

    <!-- Mobile Backdrop Overlay -->
    <div class="mobile-sidebar-backdrop" id="mobileSidebarBackdrop" onclick="toggleSidebar(false)"></div>

    <!-- ===== SIDEBAR (ELEVATED PHARMACY CONSOLE) ===== -->
    <aside class="sidebar" id="pharmacySidebar">
        <div class="sidebar-brand-box">
            <a href="dashboard.php" class="sidebar-brand-link">
                <div class="sidebar-brand-icon"><i class="fa-solid fa-prescription-bottle-medical"></i></div>
                <div class="sidebar-brand-info">
                    <span class="sidebar-brand-title">Vax<span>Care</span></span>
                    <span class="sidebar-brand-sub">Pharmacy Console</span>
                </div>
            </a>
        </div>
        
        <nav class="sidebar-menu">
            <div class="sidebar-section-title">Overview</div>
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-border-all"></i> Dashboard
            </a>

            <div class="sidebar-section-title">Cold Chain &amp; Stock</div>
            <a href="inventory.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-stacked"></i> Vaccine Stock
                <?php if($low_stock_count > 0): ?>
                    <span class="badge-red"><?= $low_stock_count ?></span>
                <?php endif; ?>
            </a>
            <a href="add_batch.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-packing"></i> Intake Batch
            </a>
            <a href="requisitions.php" class="sidebar-item">
                <i class="fa-solid fa-clipboard-check"></i> Requisitions
                <?php if($pending_requisitions > 0): ?>
                    <span class="badge-red"><?= $pending_requisitions ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Procurement &amp; Billing</div>
            <a href="orders.php" class="sidebar-item">
                <i class="fa-solid fa-truck-ramp-box"></i> Purchase Orders
                <?php if($pending_orders > 0): ?>
                    <span class="badge-red"><?= $pending_orders ?></span>
                <?php endif; ?>
            </a>
            <a href="billing.php" class="sidebar-item">
                <i class="fa-solid fa-file-invoice-dollar"></i> Invoices &amp; POS
            </a>

            <div class="sidebar-section-title">Governance &amp; Comms</div>
            <a href="reports.php" class="sidebar-item active">
                <i class="fa-solid fa-chart-pie"></i> Stock Analytics
            </a>
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
        
        <div class="sidebar-logout" style="padding:14px; margin-top:auto; border-top:1px solid rgba(255,255,255,0.08);">
            <a href="../auth/logout.php" style="display:flex; align-items:center; gap:12px; padding:11px 16px; color:#8fa0bd; text-decoration:none; font-size:13.5px; font-weight:600; border-radius:11px; transition:all 0.2s;">
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
                    <h1 style="margin:0 0 6px 0;">Stock Analytics &amp; Clinical Intelligence</h1>
                    <div class="topbar-breadcrumbs" style="margin-bottom:6px;">
                        <span>Governance &amp; Comms</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span style="color:#0f172a; font-weight:700;">Stock Analytics</span>
                    </div>
                    <div class="date-range" style="margin-top:0;">
                        <i class="fa-solid fa-snowflake" style="color:#05cd99;"></i> <?= date('d M, Y') ?> &bull; Ndola Central Hospital &bull; Analytics Intelligence Console
                    </div>
                </div>
            </div>

            <div class="topbar-right">
                <a href="orders.php" class="ph-btn ph-btn-green" style="padding: 6px 12px; font-size: 12px; border-radius: 8px; gap: 5px; height: 34px; text-decoration:none;">
                    <i class="fa-solid fa-cart-plus" style="font-size: 11px;"></i> Restock Orders
                </a>
                <a href="messages.php" class="topbar-icon-btn" title="Messages">
                    <i class="fa-regular fa-envelope"></i>
                    <?php if($unread_msg_count > 0): ?>
                        <span class="notif-ping"></span>
                    <?php endif; ?>
                </a>
                <div class="topbar-profile-box">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($pharmacist_name) ?>&background=05cd99&color=fff&bold=true" alt="Profile">
                    <div class="topbar-profile-meta">
                        <span class="name"><?= htmlspecialchars($pharmacist_name) ?></span>
                        <span class="role">Chief Pharmacist</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- KPI Summary Cards -->
        <div class="analytics-kpi-grid">
            <div class="analytics-kpi-card">
                <div class="analytics-kpi-icon" style="background:#eff6ff; color:#2563eb;">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </div>
                <div>
                    <div class="analytics-kpi-title">Total Vaccine Types</div>
                    <div class="analytics-kpi-val"><?= count($all_stocks) ?></div>
                </div>
            </div>

            <div class="analytics-kpi-card">
                <div class="analytics-kpi-icon" style="background:#ecfdf5; color:#05cd99;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="analytics-kpi-title">Optimal Stock</div>
                    <div class="analytics-kpi-val" style="color:#047857;"><?= $status_rows['optimal'] ?? 0 ?></div>
                </div>
            </div>

            <div class="analytics-kpi-card">
                <div class="analytics-kpi-icon" style="background:#fef3c7; color:#d97706;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <div class="analytics-kpi-title">Low Stock Warnings</div>
                    <div class="analytics-kpi-val" style="color:#b45309;"><?= $status_rows['low'] ?? 0 ?></div>
                </div>
            </div>

            <div class="analytics-kpi-card">
                <div class="analytics-kpi-icon" style="background:#fef2f2; color:#ef4444;">
                    <i class="fa-solid fa-circle-xmark"></i>
                </div>
                <div>
                    <div class="analytics-kpi-title">Out of Stock</div>
                    <div class="analytics-kpi-val" style="color:#b91c1c;"><?= $status_rows['out_of_stock'] ?? 0 ?></div>
                </div>
            </div>
        </div>

        <!-- ROW 1: Bar Chart (Stock Levels vs Threshold) + Donut (Health Status) -->
        <div class="charts-row-3">

            <!-- Stock Level Bar Chart -->
            <div class="chart-card">
                <div class="chart-card-title">
                    <i class="fa-solid fa-chart-column" style="color:#2563eb;"></i> Live Inventory vs Minimum Safety Threshold
                </div>
                <div class="chart-card-sub">Real-time doses in cold rooms compared against reorder thresholds</div>
                <div style="position:relative; height:290px;">
                    <canvas id="stockBarChart"></canvas>
                </div>
            </div>

            <!-- Status Donut -->
            <div class="chart-card" style="display:flex; flex-direction:column; align-items:center; justify-content:center;">
                <div class="chart-card-title" style="justify-content:center;">
                    <i class="fa-solid fa-chart-pie" style="color:#05cd99;"></i> Inventory Health
                </div>
                <div class="chart-card-sub" style="text-align:center;">Proportion of vaccines by safe stock buffer</div>
                <div style="position:relative; height:200px; width:200px;">
                    <canvas id="statusDonutChart"></canvas>
                </div>
                <div class="status-legend">
                    <div class="legend-dot"><span style="background:#059669;"></span>Optimal</div>
                    <div class="legend-dot"><span style="background:#d97706;"></span>Low Stock</div>
                    <div class="legend-dot"><span style="background:#dc2626;"></span>Depleted</div>
                </div>
            </div>
        </div>

        <!-- ROW 2: Trend Line + Top Dispensed Donut -->
        <div class="charts-row-2">

            <!-- 30-Day Dispense Trend -->
            <div class="chart-card">
                <div class="chart-card-title">
                    <i class="fa-solid fa-chart-line" style="color:#05cd99;"></i> 30-Day Requisition Burn Rate Trend
                </div>
                <div class="chart-card-sub">Daily volume of doses fulfilled via approved requisitions</div>
                <div style="position:relative; height:230px;">
                    <canvas id="trendLineChart"></canvas>
                </div>
            </div>

            <!-- Top Dispensed Donut -->
            <div class="chart-card" style="display:flex; flex-direction:column; align-items:center; justify-content:center;">
                <div class="chart-card-title" style="justify-content:center;">
                    <i class="fa-solid fa-syringe" style="color:#f59e0b;"></i> Top Requested Vaccines (30 Days)
                </div>
                <div class="chart-card-sub" style="text-align:center;">Clinical medications in highest demand</div>
                <?php if (empty($disp_labels)): ?>
                    <div style="text-align:center; padding:32px; color:#64748b; font-size:13px;">
                        <i class="fa-solid fa-chart-pie" style="font-size:32px; opacity:0.3; display:block; margin-bottom:8px;"></i>
                        No dispensing transactions recorded in the last 30 days.
                    </div>
                <?php else: ?>
                    <div style="position:relative; height:200px; width:200px;">
                        <canvas id="dispenseDonutChart"></canvas>
                    </div>
                    <div class="status-legend" style="margin-top:14px; max-width:320px;">
                        <?php
                        $colors = ['#2563eb','#05cd99','#f59e0b','#ef4444','#8b5cf6','#0ea5e9'];
                        foreach($disp_labels as $i => $lbl):
                        ?>
                            <div class="legend-dot">
                                <span style="background:<?= $colors[$i % count($colors)] ?>;"></span>
                                <?= htmlspecialchars(substr($lbl, 0, 18)) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Requisition History Log Table -->
        <div class="chart-card" style="margin-bottom:28px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:12px;">
                <div>
                    <h2 class="chart-card-title" style="margin:0; font-size:16px;">
                        <i class="fa-solid fa-clipboard-list" style="color:#2563eb;"></i> Requisition History Log
                    </h2>
                    <div class="chart-card-sub" style="margin:2px 0 0 0;">All-time ward requisitions processed through pharmacy fulfillment</div>
                </div>
                <a href="requisitions.php" class="ph-btn ph-btn-outline" style="padding:6px 14px; font-size:12px; text-decoration:none;">
                    Manage Requisitions <i class="fa-solid fa-arrow-right" style="margin-left:4px;"></i>
                </a>
            </div>

            <?php if(empty($req_log)): ?>
                <p style="color:#64748b; font-size:13px; text-align:center; padding:24px 0;">No requisition logs found.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="ph-modern-table">
                        <thead>
                            <tr>
                                <th>Requisition Ref</th>
                                <th>Requesting Nurse</th>
                                <th>Doses Requested</th>
                                <th>Fulfillment Status</th>
                                <th>Logged Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($req_log as $log): ?>
                                <tr>
                                    <td>
                                        <span class="lot-code-pill">REQ-<?= str_pad($log['id'], 4, '0', STR_PAD_LEFT) ?></span>
                                    </td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($log['nurse_name']) ?>&background=eff6ff&color=2563eb&bold=true" style="width:28px; height:28px; border-radius:50%;">
                                            <span style="font-weight:600; color:#1e293b;"><?= htmlspecialchars($log['nurse_name']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <strong style="color:#0f172a;"><?= number_format($log['total_doses'] ?: 0) ?> doses</strong>
                                        <span style="font-size:11px; color:#64748b; display:block;"><?= $log['item_count'] ?> vaccine type<?= $log['item_count'] > 1 ? 's' : '' ?></span>
                                    </td>
                                    <td>
                                        <?php if(in_array($log['status'], ['Approved', 'Confirmed'])): ?>
                                            <span style="display:inline-flex; align-items:center; gap:5px; background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:20px;">
                                                <i class="fa-solid fa-circle-check"></i> Approved
                                            </span>
                                        <?php elseif($log['status'] === 'Rejected'): ?>
                                            <span style="display:inline-flex; align-items:center; gap:5px; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:20px;">
                                                <i class="fa-solid fa-circle-xmark"></i> Rejected
                                            </span>
                                        <?php else: ?>
                                            <span style="display:inline-flex; align-items:center; gap:5px; background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:20px;">
                                                <i class="fa-solid fa-clock"></i> Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="color:#475569; font-size:12.5px;"><?= date('d M Y, H:i', strtotime($log['created_at'])) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- ===== CHART.JS INITIALIZATION ===== -->
    <script>
    function toggleSidebar(open) {
        const sidebar = document.getElementById('pharmacySidebar');
        const backdrop = document.getElementById('mobileSidebarBackdrop');
        if (open) {
            sidebar.classList.add('open');
            backdrop.classList.add('show');
        } else {
            sidebar.classList.remove('open');
            backdrop.classList.remove('show');
        }
    }

    // ---- Data from PHP ----
    const stockLabels    = <?= json_encode($stock_labels) ?>;
    const stockValues    = <?= json_encode($stock_values) ?>;
    const thresholdVals  = <?= json_encode($threshold_values) ?>;
    const trendLabels    = <?= json_encode($trend_labels) ?>;
    const trendValues    = <?= json_encode($trend_values) ?>;
    const statusData     = [<?= (int)($status_rows['optimal']??0) ?>, <?= (int)($status_rows['low']??0) ?>, <?= (int)($status_rows['out_of_stock']??0) ?>];
    const dispLabels     = <?= json_encode($disp_labels) ?>;
    const dispValues     = <?= json_encode(array_map('intval', $disp_values)) ?>;

    const PALETTE = ['#2563eb','#05cd99','#f59e0b','#ef4444','#8b5cf6','#0ea5e9','#ec4899','#14b8a6'];

    // Global Chart.js defaults
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.plugins.legend.display = false;
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15,23,42,0.92)';
    Chart.defaults.plugins.tooltip.cornerRadius = 10;
    Chart.defaults.plugins.tooltip.padding = 12;
    Chart.defaults.plugins.tooltip.titleFont = { size: 13, weight: '700' };
    Chart.defaults.plugins.tooltip.bodyFont  = { size: 12 };

    // ---- 1. Stock Bar Chart ----
    const barColors = stockValues.map((v, i) => {
        if (v === 0) return '#ef4444';
        if (v <= thresholdVals[i]) return '#f59e0b';
        return '#05cd99';
    });

    new Chart(document.getElementById('stockBarChart'), {
        type: 'bar',
        data: {
            labels: stockLabels,
            datasets: [
                {
                    label: 'Current Stock',
                    data: stockValues,
                    backgroundColor: barColors.map(c => c + 'DD'),
                    borderColor: barColors,
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                },
                {
                    label: 'Min Reorder Threshold',
                    data: thresholdVals,
                    backgroundColor: 'rgba(239,68,68,0.12)',
                    borderColor: '#ef4444',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    type: 'line',
                    pointRadius: 0,
                    tension: 0,
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: 'top', labels: { boxWidth: 12, font: { size: 12, weight: '600' } } },
                tooltip: { mode: 'index', intersect: false }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10.5, weight: '600' }, maxRotation: 35 } },
                y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 11 } }, beginAtZero: true }
            }
        }
    });

    // ---- 2. Status Donut ----
    new Chart(document.getElementById('statusDonutChart'), {
        type: 'doughnut',
        data: {
            labels: ['Optimal', 'Low Stock', 'Depleted'],
            datasets: [{ data: statusData, backgroundColor: ['#059669','#d97706','#dc2626'], borderWidth: 3, borderColor: '#fff', hoverOffset: 8 }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw} vaccines` } }
            }
        }
    });

    // ---- 3. 30-Day Trend Line ----
    new Chart(document.getElementById('trendLineChart'), {
        type: 'line',
        data: {
            labels: trendLabels.length ? trendLabels : ['No Data'],
            datasets: [{
                label: 'Doses Dispensed',
                data: trendValues.length ? trendValues : [0],
                borderColor: '#05cd99',
                backgroundColor: 'rgba(5,205,153,0.12)',
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: '#05cd99',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                fill: true,
                tension: 0.35
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { tooltip: { mode: 'index', intersect: false } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10.5 }, maxRotation: 45, maxTicksLimit: 10 } },
                y: { grid: { color: '#f1f5f9' }, beginAtZero: true, ticks: { font: { size: 11 } } }
            }
        }
    });

    // ---- 4. Top Dispensed Donut ----
    if (dispLabels.length && document.getElementById('dispenseDonutChart')) {
        new Chart(document.getElementById('dispenseDonutChart'), {
            type: 'doughnut',
            data: {
                labels: dispLabels,
                datasets: [{ data: dispValues, backgroundColor: PALETTE.slice(0, dispLabels.length), borderWidth: 3, borderColor: '#fff', hoverOffset: 8 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '65%',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw} doses` } }
                }
            }
        });
    }
    </script>
</body>
</html>