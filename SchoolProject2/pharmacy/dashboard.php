<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pharmacy') {
    header("Location: ../auth/login.php");
    exit;
}

$pharmacist_name = $_SESSION['name'];
$success = '';
$error = '';

/*
 * DISABLED 2026-11-11 (VaxiCare test drive — pharmacy portal).
 * Unreachable dead code: this page renders no <form> and ships no JavaScript that
 * posts action=place_order, so nothing can ever reach this branch. The live order
 * paths are pharmacy/inventory.php and pharmacy/orders.php.
 * Kept commented rather than deleted in case a dashboard order form is added later;
 * restore the block and add a matching CSRF-bearing form to re-enable it.
 *
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    csrf_guard();
    $vaccine_id = $_POST['vaccine_id'];
    $supplier_id = $_POST['supplier_id'];
    $quantity = (int) $_POST['quantity'];
    $order_date = $_POST['order_date'];
    $requested_delivery = $_POST['requested_delivery'];
    $notes = trim($_POST['notes']);

    if (!empty($vaccine_id) && !empty($supplier_id) && $quantity > 0) {
        $stmt = $pdo->prepare("INSERT INTO purchase_orders (vaccine_id, supplier_id, quantity, order_date, requested_delivery, notes) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$vaccine_id, $supplier_id, $quantity, $order_date, $requested_delivery, $notes])) {
            $success = "Purchase Order placed successfully!";
        } else {
            $error = "Failed to place order.";
        }
    } else {
        $error = "Please fill all required fields.";
    }
}
 */

$vaccines  = $pdo->query("SELECT id, name FROM vaccines ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE status='Active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Top Summary Metrics
$stmt = $pdo->query("SELECT SUM(quantity) FROM vaccine_batches WHERE status = 'Active'");
$total_meds = $stmt->fetchColumn() ?: 0;

$stmt = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending'");
$pending_orders = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'Pending'");
$pending_requisitions = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= (low_stock_threshold + 100)");
$low_stock_count = $stmt->fetchColumn();

// Real-time Stock Monitoring (Arc Gauge Capacity Data)
$stmt = $pdo->query("
    SELECT id, name, current_stock, low_stock_threshold
    FROM vaccines 
    ORDER BY (current_stock / NULLIF(low_stock_threshold, 0)) ASC 
    LIMIT 6
");
$stock_monitored_vaccines = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Low Stock Alerts
$stmt = $pdo->query("
    SELECT id, name, current_stock, low_stock_threshold 
    FROM vaccines 
    WHERE current_stock <= (low_stock_threshold + 100)
    ORDER BY current_stock ASC LIMIT 5
");
$low_stock_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top Inventory Items
$stmt = $pdo->query("
    SELECT v.name, b.location, b.batch_number, b.expiry_date, b.status
    FROM vaccine_batches b
    JOIN vaccines v ON b.vaccine_id = v.id
    ORDER BY b.created_at DESC
    LIMIT 5
");
$top_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Activity
$stmt = $pdo->query("
    SELECT 'Requisition' as type, CONCAT(u.name, ' requested vaccines') as detail, r.created_at 
    FROM requisitions r JOIN users u ON r.nurse_id = u.id
    UNION ALL
    SELECT 'Order' as type, CONCAT('Purchase Order #', po.id, ' placed') as detail, po.created_at 
    FROM purchase_orders po
    ORDER BY created_at DESC LIMIT 5
");
$recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unread Message Count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'pharmacy' OR recipient_role = 'pharmacist') AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_msg_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Executive Dashboard — VaxiCare</title>
    <meta name="description" content="Pharmacy dashboard showing inventory levels, pending orders, and requisitions for VaxiCare.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/pharmacy_theme.css">
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
            <a href="dashboard.php" class="sidebar-item active">
                <i class="fa-solid fa-border-all"></i> Dashboard
            </a>

            <div class="sidebar-section-title">Cold Chain &amp; Stock</div>
            <a href="inventory.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-stacked"></i> Vaccine Stock
                <?php if(isset($low_stock_count) && $low_stock_count > 0): ?>
                    <span class="badge-red"><?= $low_stock_count ?></span>
                <?php endif; ?>
            </a>
            <a href="add_batch.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-packing"></i> Intake Batch
            </a>
            <a href="requisitions.php" class="sidebar-item">
                <i class="fa-solid fa-clipboard-check"></i> Requisitions
                <?php if(isset($pending_requisitions) && $pending_requisitions > 0): ?>
                    <span class="badge-red"><?= $pending_requisitions ?></span>
                <?php elseif(isset($pending_reqs) && $pending_reqs > 0): ?>
                    <span class="badge-red"><?= $pending_reqs ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Procurement &amp; Billing</div>
            <a href="orders.php" class="sidebar-item">
                <i class="fa-solid fa-truck-ramp-box"></i> Purchase Orders
                <?php if(isset($pending_orders) && $pending_orders > 0): ?>
                    <span class="badge-red"><?= $pending_orders ?></span>
                <?php endif; ?>
            </a>
            <a href="billing.php" class="sidebar-item">
                <i class="fa-solid fa-file-invoice-dollar"></i> Invoices &amp; POS
            </a>

            <div class="sidebar-section-title">Governance &amp; Comms</div>
            <a href="reports.php" class="sidebar-item">
                <i class="fa-solid fa-chart-pie"></i> Stock Analytics
            </a>
            <a href="messages.php" class="sidebar-item">
                <i class="fa-solid fa-envelope"></i> Messages
                <?php if(isset($unread_msg_count) && $unread_msg_count > 0): ?>
                    <span class="badge-red"><?= $unread_msg_count ?></span>
                <?php elseif(isset($unread_count) && $unread_count > 0): ?>
                    <span class="badge-red"><?= $unread_count ?></span>
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
                    <h1 style="margin:0 0 6px 0;">Pharmacy Overview</h1>
                    <div class="topbar-breadcrumbs" style="margin-bottom:6px;">
                        <span>Overview</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span style="color:#0f172a; font-weight:700;">Pharmacy Dashboard</span>
                    </div>
                    <div class="date-range" style="margin-top:0;">
                        <i class="fa-solid fa-snowflake" style="color:#05cd99;"></i> <?= date('d M, Y') ?> &bull; Ndola Central Hospital &bull; Cold-Chain Station
                    </div>
                </div>
            </div>

            <div class="topbar-right">
                <a href="add_batch.php" class="ph-btn ph-btn-green" style="text-decoration:none;">
                    <i class="fa-solid fa-plus"></i> Intake Stock Batch
                </a>

                <a href="messages.php" class="topbar-icon-btn" title="System Messages">
                    <i class="fa-regular fa-bell"></i>
                    <?php if($unread_msg_count > 0): ?>
                        <span class="notif-ping"></span>
                    <?php endif; ?>
                </a>

                <a href="settings.php" class="topbar-icon-btn" title="Pharmacy Settings">
                    <i class="fa-solid fa-gear"></i>
                </a>

                <div class="topbar-profile-box">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($pharmacist_name) ?>&background=05cd99&color=fff&bold=true" alt="Profile">
                    <div class="topbar-profile-meta">
                        <span class="name"><?= htmlspecialchars(explode(' ', $pharmacist_name)[0] ?? 'Pharmacist') ?></span>
                        <span class="role">Cold Chain Officer</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- ===== LARGE SUMMARY METRIC CARDS ===== -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon icon-blue"><i class="fa-solid fa-pills"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($total_meds) ?></div>
                    <div class="stat-label">Total Stock Doses</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-amber"><i class="fa-solid fa-truck"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($pending_orders) ?></div>
                    <div class="stat-label">Pending Orders</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-rose"><i class="fa-solid fa-clipboard-list"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($pending_requisitions) ?></div>
                    <div class="stat-label">Ward Requisitions</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-teal"><i class="fa-solid fa-shield-virus"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= number_format($low_stock_count) ?></div>
                    <div class="stat-label">Low Stock Alerts</div>
                </div>
            </div>
        </div>



        <!-- ===== REAL-TIME STOCK MONITORING (ARC GAUGE CARDS) ===== -->
        <div class="mar-card" style="margin-bottom: 28px;">
            <div class="mar-card-header">
                <div>
                    <div class="mar-card-title"><i class="fa-solid fa-chart-pie" style="color:#2563eb; margin-right:8px;"></i> Real-Time Stock Capacity Monitoring</div>
                    <div class="mar-card-subtitle">Live stock capacity gauges &amp; direct reorder access</div>
                </div>
                <a href="orders.php" class="ph-btn ph-btn-outline ph-btn-sm" style="text-decoration:none;">Order Management <i class="fa-solid fa-arrow-right"></i></a>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-top: 16px;">
                <?php 
                $card_letters = ['a', 'b', 'c', 'd', 'e', 'f'];
                $idx = 0;
                foreach($stock_monitored_vaccines as $stock_v): 
                    $max_cap = max($stock_v['current_stock'], $stock_v['low_stock_threshold'] * 4);
                    $pct = min(100, max(0, round(($stock_v['current_stock'] / max(1, $max_cap)) * 100)));
                    $is_critical = $stock_v['current_stock'] <= ($stock_v['low_stock_threshold'] / 2);
                    $is_low = $stock_v['current_stock'] <= $stock_v['low_stock_threshold'];
                    
                    $gauge_color = $is_critical ? '#ef4444' : ($is_low ? '#d97706' : '#059669');
                    $status_label = $is_critical ? 'CRITICAL' : ($is_low ? 'LOW' : 'OPTIMAL');
                    $letter = $card_letters[$idx % count($card_letters)];
                    $idx++;

                    if ($is_critical) {
                        $card_style = "background: #fff; border: 2px solid #ef4444; box-shadow: 0 4px 14px rgba(239, 68, 68, 0.12);";
                        $badge_bg = "rgba(239, 68, 68, 0.12)";
                        $badge_text = "#ef4444";
                        $btn_style = "background: #ef4444; color: #ffffff; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);";
                    } elseif ($is_low) {
                        $card_style = "background: #fff; border: 2px solid #f59e0b; box-shadow: 0 4px 14px rgba(245, 158, 11, 0.12);";
                        $badge_bg = "rgba(245, 158, 11, 0.12)";
                        $badge_text = "#d97706";
                        $btn_style = "background: #f59e0b; color: #ffffff; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);";
                    } else {
                        $card_style = "background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 2px 6px rgba(0,0,0,0.03);";
                        $badge_bg = "rgba(5, 150, 105, 0.12)";
                        $badge_text = "#059669";
                        $btn_style = "background: #f8fafc; color: #0f172a; border: 1.5px solid #cbd5e1;";
                    }

                    $arc_len = 172.78;
                    $dash_offset = $arc_len * (1 - ($pct / 100));
                ?>
                    <div style="<?= $card_style ?> border-radius: 18px; padding: 20px 16px; display: flex; flex-direction: column; justify-content: space-between; position: relative;">
                        <div>
                            <div style="display:flex; align-items:flex-start; gap: 12px; margin-bottom: 14px;">
                                <div style="width:34px; height:34px; border-radius:50%; background:<?= $badge_bg ?>; color:<?= $badge_text ?>; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:14px; flex-shrink:0;">
                                    <?= strtoupper($letter) ?>
                                </div>
                                <div style="flex-grow:1;">
                                    <h4 style="font-size:14px; font-weight:700; color:#0f172a; margin: 0 0 2px 0; line-height:1.2;">
                                        <?= htmlspecialchars($stock_v['name']) ?>
                                    </h4>
                                    <div style="font-size:12px; color:#64748b; font-weight:600;">
                                        Stock: <?= number_format($stock_v['current_stock']) ?> / <?= number_format($max_cap) ?>
                                    </div>
                                </div>
                            </div>

                            <div style="position: relative; width: 140px; margin: 12px auto 8px auto; text-align: center;">
                                <svg width="140" height="75" viewBox="0 0 140 75" style="display:block; margin:0 auto; overflow:visible;">
                                    <path d="M 15 65 A 55 55 0 0 1 125 65" fill="none" stroke="#e2e8f0" stroke-width="10" stroke-linecap="round" />
                                    <path d="M 15 65 A 55 55 0 0 1 125 65" fill="none" stroke="<?= $gauge_color ?>" stroke-width="10" stroke-linecap="round"
                                          stroke-dasharray="172.78" stroke-dashoffset="<?= $dash_offset ?>" style="transition: stroke-dashoffset 0.6s ease;" />
                                </svg>
                                <div style="position: absolute; bottom: 6px; left: 0; right: 0; text-align: center;">
                                    <div style="font-size: 20px; font-weight: 800; color: #0f172a; line-height: 1;"><?= $pct ?>%</div>
                                    <div style="font-size: 11px; font-weight: 700; color: <?= $gauge_color ?>; margin-top: 3px;">
                                        Level: <?= $status_label ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <a href="orders.php?vaccine_id=<?= $stock_v['id'] ?>" style="<?= $btn_style ?> width:100%; border-radius:30px; padding:9px 16px; font-size:12.5px; font-weight:700; text-align:center; text-decoration:none; display:flex; align-items:center; justify-content:center; gap:6px; margin-top:14px;">
                            <i class="fa-solid fa-rotate-right" style="font-size:12px;"></i> Reorder Stock
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ===== MIDDLE GRID: ACTIVITY & LOW STOCK ALERTS ===== -->
        <div class="middle-grid">

            <!-- Recent Activity -->
            <div class="mar-card" style="margin-bottom:0; box-shadow:0 4px 20px -2px rgba(0,0,0,0.03);">
                <div class="mar-card-header">
                    <div>
                        <div class="mar-card-title"><i class="fa-solid fa-clock-rotate-left" style="color:#2563eb; margin-right:6px;"></i> Pharmacy Stream</div>
                        <div class="mar-card-subtitle">Recent orders &amp; ward requisitions</div>
                    </div>
                    <a href="reports.php" class="ph-btn ph-btn-outline ph-btn-sm" style="text-decoration:none;">View All</a>
                </div>
                <div class="activity-list" style="display:flex; flex-direction:column; gap:10px;">
                    <?php if(empty($recent_activity)): ?>
                        <p style="color:#64748b; font-size:13px; text-align:center; padding:24px 0;">No recent activity stream logged.</p>
                    <?php else: ?>
                        <?php foreach($recent_activity as $act): 
                            $is_order = ($act['type'] === 'Order');
                            $icon_bg   = $is_order ? '#fffbe8' : '#eff6ff';
                            $icon_clr  = $is_order ? '#d97706' : '#2563eb';
                            $icon_name = $is_order ? 'fa-truck' : 'fa-clipboard-list';
                            $badge_class = $is_order ? 'bg-yellow-light' : 'bg-blue-light';
                        ?>
                            <div class="activity-item" style="display:flex; align-items:center; justify-content:space-between; padding:12px 14px; background:#ffffff; border:1px solid #eef2f6; border-radius:12px; transition:all 0.2s ease;">
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <div style="width:38px; height:38px; border-radius:10px; background:<?= $icon_bg ?>; color:<?= $icon_clr ?>; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0; border:1px solid rgba(0,0,0,0.05);">
                                        <i class="fa-solid <?= $icon_name ?>"></i>
                                    </div>
                                    <div>
                                        <div style="font-size:13.5px; font-weight:700; color:#0f172a; line-height:1.2;">
                                            <?= htmlspecialchars($act['detail']) ?>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:8px; margin-top:3px;">
                                            <span class="badge-status <?= $badge_class ?>" style="padding:1px 7px; font-size:10px; text-transform:uppercase; letter-spacing:0.4px;">
                                                <?= htmlspecialchars($act['type']) ?>
                                            </span>
                                            <span style="font-size:11.5px; color:#64748b; font-weight:500;">
                                                <i class="fa-regular fa-clock" style="font-size:10px; color:#94a3b8; margin-right:2px;"></i>
                                                <?= date('M d, H:i', strtotime($act['created_at'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <a href="<?= $is_order ? 'orders.php' : 'requisitions.php' ?>" style="width:30px; height:30px; border-radius:50%; background:#f8fafc; border:1px solid #e2e8f0; color:#64748b; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; transition:all 0.2s;" title="View Details">
                                    <i class="fa-solid fa-chevron-right" style="font-size:11px;"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Low Stock Alerts -->
            <div class="mar-card" style="margin-bottom:0; box-shadow:0 4px 20px -2px rgba(0,0,0,0.03);">
                <div class="mar-card-header">
                    <div>
                        <div class="mar-card-title"><i class="fa-solid fa-triangle-exclamation" style="color:#e11d48; margin-right:6px;"></i> Low Stock Alerts</div>
                        <div class="mar-card-subtitle">Critical thresholds</div>
                    </div>
                    <a href="inventory.php" class="ph-btn ph-btn-outline ph-btn-sm" style="text-decoration:none;">View All</a>
                </div>
                <div class="user-list" style="display:flex; flex-direction:column; gap:10px;">
                    <?php if(empty($low_stock_alerts)): ?>
                        <p style="color:#64748b; font-size:13px; text-align:center; padding:20px 0;">No low stock alerts.</p>
                    <?php else: ?>
                        <?php foreach($low_stock_alerts as $alert): ?>
                            <div class="user-item" style="display:flex; align-items:center; justify-content:space-between; padding:12px 14px; background:#ffffff; border:1px solid #fecdd3; border-left:3px solid #e11d48; border-radius:12px;">
                                <div class="user-info">
                                    <div class="activity-icon" style="background:#fff1f2; color:#e11d48;">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                    </div>
                                    <div>
                                        <h4 style="margin:0; font-size:13.5px; font-weight:700; color:#0f172a;"><?= htmlspecialchars($alert['name']) ?></h4>
                                        <p style="margin:2px 0 0 0; font-size:11.5px; color:#64748b;">Min Threshold: <?= $alert['low_stock_threshold'] ?></p>
                                    </div>
                                </div>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span class="badge-status bg-red-light" style="font-weight:700;"><?= $alert['current_stock'] ?> units</span>
                                    <a href="orders.php?vaccine_id=<?= $alert['id'] ?>" class="ph-btn ph-btn-sm ph-btn-outline" style="padding:5px 10px; font-size:11px; font-weight:700;">Reorder</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== BOTTOM: TOP INVENTORY BATCHES TABLE ===== -->
        <div class="mar-card" style="margin-top:24px; box-shadow:0 4px 20px -2px rgba(0,0,0,0.03);">
            <div class="mar-card-header">
                <div>
                    <div class="mar-card-title"><i class="fa-solid fa-boxes-packing" style="color:#059669; margin-right:6px;"></i> Top Inventory Batches</div>
                    <div class="mar-card-subtitle">Recent cold-room arrivals and expiration tracking</div>
                </div>
                <a href="inventory.php" class="ph-btn ph-btn-outline ph-btn-sm" style="text-decoration:none;">View All Inventory</a>
            </div>
            <table class="mar-table">
                <thead>
                    <tr>
                        <th>Medication Item</th>
                        <th>Location</th>
                        <th>Batch Lot</th>
                        <th>Expiry Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($top_inventory)): ?>
                        <tr><td colspan="5" style="text-align:center; color:#64748b; padding:24px;">No inventory records recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach($top_inventory as $item): ?>
                            <tr>
                                <td>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <div style="width:30px; height:30px; border-radius:8px; background:#ecfdf5; color:#05cd99; display:flex; align-items:center; justify-content:center; font-size:13px; border:1px solid #a7f3d0;">
                                            <i class="fa-solid fa-pills"></i>
                                        </div>
                                        <strong style="color:#0f172a; font-size:13.5px;"><?= htmlspecialchars($item['name']) ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <span style="display:inline-flex; align-items:center; gap:4px; font-size:12px; color:#475569; background:#f1f5f9; padding:3px 8px; border-radius:6px; font-weight:600;">
                                        <i class="fa-solid fa-snowflake" style="font-size:10px; color:#05cd99;"></i> <?= htmlspecialchars($item['location'] ?? 'Main Cold Room') ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-family:monospace; font-weight:700; color:#0f172a; background:#f8fafc; border:1px solid #e2e8f0; padding:2px 8px; border-radius:6px; font-size:12px;">
                                        <?= htmlspecialchars($item['batch_number']) ?>
                                    </span>
                                </td>
                                <td style="color:#64748b; font-size:12.5px; font-weight:600;"><?= date('M d, Y', strtotime($item['expiry_date'])) ?></td>
                                <td>
                                    <span class="badge-status <?= $item['status'] === 'Active' ? 'bg-green-light' : 'bg-red-light' ?>" style="font-size:11px; padding:3px 10px;">
                                        <i class="fa-solid <?= $item['status'] === 'Active' ? 'fa-check' : 'fa-xmark' ?>" style="font-size:10px;"></i> <?= htmlspecialchars($item['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>

    <script src="../js/notifications.js"></script>
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
    </script>
</body>
</html>