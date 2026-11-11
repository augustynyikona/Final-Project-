<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pharmacy') {
    header("Location: ../auth/login.php");
    exit;
}

$pharmacist_name = $_SESSION['name'] ?? 'Pharmacist';
$success = '';
$error = '';

// Handle fulfilling requisitions with dispatch scheduling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve') {
    csrf_guard();
    $req_id = $_POST['requisition_id'];
    $dispatch_type  = $_POST['dispatch_type'] ?? 'Immediate';
    $dispatch_time  = !empty($_POST['dispatch_time']) ? $_POST['dispatch_time'] : null;
    $dispatch_notes = trim($_POST['dispatch_notes'] ?? '');

    // If immediate, set dispatch_time to NOW
    if ($dispatch_type === 'Immediate') {
        $dispatch_time = date('Y-m-d H:i:s');
    }

    $stmt = $pdo->prepare("SELECT status FROM requisitions WHERE id = ?");
    $stmt->execute([$req_id]);
    $req = $stmt->fetch();

    if ($req && $req['status'] === 'Pending') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT vaccine_id, requested_quantity FROM requisition_items WHERE requisition_id = ?");
            $stmt->execute([$req_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $can_fulfill = true;
            foreach ($items as $item) {
                $stmtStock = $pdo->prepare("SELECT current_stock FROM vaccines WHERE id = ? FOR UPDATE");
                $stmtStock->execute([$item['vaccine_id']]);
                $stock = $stmtStock->fetchColumn();
                if ($stock < $item['requested_quantity']) {
                    $can_fulfill = false;
                    $error = "Insufficient stock to fulfill requisition #REQ-" . str_pad($req_id, 4, '0', STR_PAD_LEFT);
                    break;
                }
            }

            if ($can_fulfill) {
                $stmtDeduct = $pdo->prepare("UPDATE vaccines SET current_stock = current_stock - ? WHERE id = ?");
                foreach ($items as $item) {
                    $stmtDeduct->execute([$item['requested_quantity'], $item['vaccine_id']]);
                }
                $pdo->prepare("UPDATE requisitions SET status = 'Approved', approved_at = NOW(), dispatch_type = ?, dispatch_time = ?, dispatch_notes = ? WHERE id = ?")
                    ->execute([$dispatch_type, $dispatch_time, $dispatch_notes, $req_id]);
                $pdo->commit();

                $dispatch_label = ($dispatch_type === 'Immediate') ? 'dispatched immediately' : 'scheduled for ' . date('D, M d \a\t h:i A', strtotime($dispatch_time));
                $success = "Requisition #REQ-" . str_pad($req_id, 4, '0', STR_PAD_LEFT) . " approved — drugs $dispatch_label.";
            } else {
                $pdo->rollBack();
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Database error while processing requisition.";
        }
    }
}

// Pending Requisitions
$stmt = $pdo->query("
    SELECT r.*, u.name as nurse_name,
        (SELECT COUNT(*) FROM requisition_items WHERE requisition_id = r.id) as item_count,
        (SELECT SUM(requested_quantity) FROM requisition_items WHERE requisition_id = r.id) as total_doses
    FROM requisitions r
    JOIN users u ON r.nurse_id = u.id
    WHERE r.status = 'Pending'
    ORDER BY r.created_at ASC
");
$pending_reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// History
$stmt = $pdo->query("
    SELECT r.*, u.name as nurse_name,
        (SELECT COUNT(*) FROM requisition_items WHERE requisition_id = r.id) as item_count,
        (SELECT SUM(requested_quantity) FROM requisition_items WHERE requisition_id = r.id) as total_doses
    FROM requisitions r
    JOIN users u ON r.nurse_id = u.id
    WHERE r.status != 'Pending'
    ORDER BY r.created_at DESC
    LIMIT 20
");
$history_reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts & metrics
$pending_orders       = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending'")->fetchColumn();
$pending_requisitions = count($pending_reqs);
$total_pending_doses  = array_sum(array_column($pending_reqs, 'total_doses')) ?: 0;
$total_approved_count = $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'Approved'")->fetchColumn();
$low_stock_count      = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= 20")->fetchColumn();

// Messages unread
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'pharmacy' OR recipient_role = 'pharmacist') AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_msg_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Drug Requisitions — VaxCare Pharmacy</title>
    <meta name="description" content="Review, verify stock, and authorize daily vaccine requisitions from ward nursing staff.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
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

        /* KPI Quick Cards */
        .req-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }
        @media (max-width: 1024px) {
            .req-kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 640px) {
            .req-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
        .req-kpi-card {
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
        .req-kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .req-kpi-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .req-kpi-title {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .req-kpi-val {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin-top: 2px;
            line-height: 1.1;
        }

        /* Requisition Cards */
        .content-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
            margin-bottom: 28px;
            overflow: hidden;
        }
        .content-card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .content-card-title {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .content-card-sub {
            font-size: 12.5px;
            color: #64748b;
            margin-top: 2px;
        }

        /* Elevated Tables */
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
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
            vertical-align: middle;
        }
        .ph-modern-table tr:hover td {
            background: #fafbfc;
        }

        /* Pill badges */
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
        .nurse-meta-box {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .nurse-meta-box img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 1.5px solid #e2e8f0;
        }

        /* Items checklist inside table row */
        .items-chip-list {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .item-chip {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 12px;
            max-width: 380px;
        }
        .item-chip.stock-ok {
            border-left: 3px solid #05cd99;
        }
        .item-chip.stock-low {
            border-left: 3px solid #ef4444;
            background: #fff5f5;
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
                <?php if(isset($low_stock_count) && $low_stock_count > 0): ?>
                    <span class="badge-red"><?= $low_stock_count ?></span>
                <?php endif; ?>
            </a>
            <a href="add_batch.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-packing"></i> Intake Batch
            </a>
            <a href="requisitions.php" class="sidebar-item active">
                <i class="fa-solid fa-clipboard-check"></i> Requisitions
                <?php if(isset($pending_requisitions) && $pending_requisitions > 0): ?>
                    <span class="badge-red"><?= $pending_requisitions ?></span>
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
                    <h1 style="margin:0 0 6px 0;">Requisition Fulfillment &amp; Dispatch</h1>
                    <div class="topbar-breadcrumbs" style="margin-bottom:6px;">
                        <span>Cold Chain &amp; Stock</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span style="color:#0f172a; font-weight:700;">Clinical Requisitions</span>
                    </div>
                    <div class="date-range" style="margin-top:0;">
                        <i class="fa-solid fa-snowflake" style="color:#05cd99;"></i> <?= date('d M, Y') ?> &bull; Ndola Central Hospital &bull; Cold-Chain Station
                    </div>
                </div>
            </div>

            <div class="topbar-right">
                <a href="inventory.php" class="ph-btn ph-btn-outline" style="text-decoration:none;">
                    <i class="fa-solid fa-boxes-stacked"></i> Live Stock Inventory
                </a>
                <a href="messages.php" class="topbar-icon-btn" title="Messages">
                    <i class="fa-regular fa-envelope"></i>
                    <?php if(isset($unread_msg_count) && $unread_msg_count > 0): ?>
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

        <!-- Feedback Alerts -->
        <?php if($error): ?>
            <div style="background:#fef2f2; border:1.5px solid #fecaca; color:#b91c1c; padding:16px 20px; border-radius:14px; margin-bottom:24px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 6px rgba(239,68,68,0.06);">
                <i class="fa-solid fa-triangle-exclamation" style="font-size:18px;"></i>
                <div>
                    <div style="font-weight:700;">Requisition Action Error</div>
                    <div style="font-size:12.5px; font-weight:500; opacity:0.95;"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div style="background:#ecfdf5; border:1.5px solid #a7f3d0; color:#065f46; padding:16px 20px; border-radius:14px; margin-bottom:24px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 6px rgba(16,185,129,0.06);">
                <i class="fa-solid fa-circle-check" style="font-size:18px; color:#05cd99;"></i>
                <div>
                    <div style="font-weight:700;">Dispatch Confirmed</div>
                    <div style="font-size:12.5px; font-weight:500; opacity:0.95;"><?= htmlspecialchars($success) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- KPI Summary Cards -->
        <div class="req-kpi-grid">
            <div class="req-kpi-card">
                <div class="req-kpi-icon" style="background:#fef3c7; color:#d97706;">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="req-kpi-title">Pending Action</div>
                    <div class="req-kpi-val"><?= $pending_requisitions ?></div>
                </div>
            </div>

            <div class="req-kpi-card">
                <div class="req-kpi-icon" style="background:#eff6ff; color:#2563eb;">
                    <i class="fa-solid fa-prescription-bottle"></i>
                </div>
                <div>
                    <div class="req-kpi-title">Doses In Request</div>
                    <div class="req-kpi-val"><?= number_format($total_pending_doses) ?></div>
                </div>
            </div>

            <div class="req-kpi-card">
                <div class="req-kpi-icon" style="background:#ecfdf5; color:#05cd99;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="req-kpi-title">Total Approved</div>
                    <div class="req-kpi-val"><?= number_format($total_approved_count) ?></div>
                </div>
            </div>

            <div class="req-kpi-card">
                <div class="req-kpi-icon" style="background:#f3e8ff; color:#7c3aed;">
                    <i class="fa-solid fa-bolt-lightning"></i>
                </div>
                <div>
                    <div class="req-kpi-title">Dispatch Mode</div>
                    <div class="req-kpi-val" style="font-size:18px; color:#7c3aed;">Immediate / Cold</div>
                </div>
            </div>
        </div>

        <!-- Pending Requisitions Section -->
        <div class="content-card" style="border-top: 3px solid #f59e0b;">
            <div class="content-card-header">
                <div>
                    <h2 class="content-card-title">
                        <i class="fa-solid fa-clock-rotate-left" style="color:#f59e0b;"></i>
                        Pending Clinical Requisitions
                    </h2>
                    <div class="content-card-sub">Daily drug lots requested by nursing staff requiring pharmacy stock audit and release</div>
                </div>
                <?php if(!empty($pending_reqs)): ?>
                    <span style="display:inline-flex; align-items:center; gap:6px; background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:12px; font-weight:700; padding:5px 12px; border-radius:20px;">
                        <span style="width:6px; height:6px; border-radius:50%; background:#b45309;"></span>
                        <?= count($pending_reqs) ?> Order(s) Awaiting Handoff
                    </span>
                <?php endif; ?>
            </div>

            <?php if(empty($pending_reqs)): ?>
                <div style="text-align:center; padding:50px 20px; color:#64748b;">
                    <i class="fa-solid fa-circle-check" style="font-size:36px; color:#05cd99; margin-bottom:12px; display:block;"></i>
                    <div style="font-size:15px; font-weight:700; color:#0f172a;">All Clinical Requisitions Fulfilled</div>
                    <div style="font-size:13px; color:#64748b; margin-top:4px;">No outstanding medication requisitions from nursing stations at this time.</div>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="ph-modern-table">
                        <thead>
                            <tr>
                                <th>Requisition Ref</th>
                                <th>Requesting Nurse</th>
                                <th>Shift Date</th>
                                <th>Requested Medication &amp; Stock Availability</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($pending_reqs as $req):
                                $stmtItems = $pdo->prepare("
                                    SELECT v.name, v.current_stock, ri.requested_quantity 
                                    FROM requisition_items ri 
                                    JOIN vaccines v ON ri.vaccine_id = v.id 
                                    WHERE ri.requisition_id = ?
                                ");
                                $stmtItems->execute([$req['id']]);
                                $req_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                                $all_in_stock = true;
                                foreach($req_items as $itm) {
                                    if ($itm['current_stock'] < $itm['requested_quantity']) {
                                        $all_in_stock = false;
                                        break;
                                    }
                                }
                            ?>
                                <tr>
                                    <td>
                                        <span class="lot-code-pill">REQ-<?= str_pad($req['id'], 4, '0', STR_PAD_LEFT) ?></span>
                                    </td>
                                    <td>
                                        <div class="nurse-meta-box">
                                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($req['nurse_name']) ?>&background=eff6ff&color=2563eb&bold=true" alt="Nurse">
                                            <div>
                                                <div style="font-weight:700; color:#0f172a;"><?= htmlspecialchars($req['nurse_name']) ?></div>
                                                <div style="font-size:11.5px; color:#64748b;">Clinical Nurse</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; color:#334155;"><?= date('D, M d, Y', strtotime($req['shift_date'])) ?></div>
                                        <div style="font-size:11px; color:#64748b;">Logged: <?= date('h:i A', strtotime($req['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div style="font-size:12px; font-weight:700; color:#0f172a; margin-bottom:8px;">
                                            <?= $req['item_count'] ?> Medication Type(s) &bull; <?= number_format($req['total_doses']) ?> Total Doses
                                        </div>
                                        <div class="items-chip-list">
                                            <?php foreach($req_items as $item): 
                                                $has_stock = $item['current_stock'] >= $item['requested_quantity'];
                                            ?>
                                                <div class="item-chip <?= $has_stock ? 'stock-ok' : 'stock-low' ?>">
                                                    <span>
                                                        <strong><?= htmlspecialchars($item['name']) ?></strong>: 
                                                        <span style="color:#0f172a; font-weight:700;"><?= $item['requested_quantity'] ?> doses</span>
                                                    </span>
                                                    <?php if($has_stock): ?>
                                                        <span style="font-size:10.5px; font-weight:700; color:#047857; background:#ecfdf5; padding:2px 6px; border-radius:4px;">
                                                            Stock: <?= number_format($item['current_stock']) ?> (OK)
                                                        </span>
                                                    <?php else: ?>
                                                        <span style="font-size:10.5px; font-weight:700; color:#b91c1c; background:#fef2f2; padding:2px 6px; border-radius:4px;">
                                                            Shortage: <?= number_format($item['current_stock']) ?> left
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td style="text-align:right;">
                                        <button type="button" class="ph-btn ph-btn-green"
                                            onclick="openDispatchModal(<?= $req['id'] ?>, '<?= htmlspecialchars(addslashes($req['nurse_name'])) ?>', <?= $req['total_doses'] ?>, <?= $req['item_count'] ?>)">
                                            <i class="fa-solid fa-truck-fast"></i> Authorize &amp; Dispatch
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Fulfillment History Section -->
        <div class="content-card">
            <div class="content-card-header">
                <div>
                    <h2 class="content-card-title">
                        <i class="fa-solid fa-clock-rotate-left" style="color:#05cd99;"></i>
                        Fulfillment &amp; Dispatch History
                    </h2>
                    <div class="content-card-sub">Recently authorized drug dispatches and temperature handover audits</div>
                </div>
                <span style="font-size:12px; color:#64748b; font-weight:600;">Showing last 20 releases</span>
            </div>

            <?php if(empty($history_reqs)): ?>
                <div style="color:#64748b; font-size:13px; text-align:center; padding:36px 0;">
                    No requisition fulfillment history logged yet.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="ph-modern-table">
                        <thead>
                            <tr>
                                <th>Requisition Ref</th>
                                <th>Requesting Nurse</th>
                                <th>Dose Summary</th>
                                <th>Status</th>
                                <th>Dispatch Logistics</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($history_reqs as $req): ?>
                                <tr>
                                    <td>
                                        <span class="lot-code-pill">REQ-<?= str_pad($req['id'], 4, '0', STR_PAD_LEFT) ?></span>
                                    </td>
                                    <td>
                                        <div class="nurse-meta-box">
                                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($req['nurse_name']) ?>&background=f1f5f9&color=475569&bold=true" alt="Nurse">
                                            <span style="font-weight:600; color:#1e293b;"><?= htmlspecialchars($req['nurse_name']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-size:12.5px; font-weight:600; color:#334155;">
                                            <?= $req['item_count'] ?> type(s) &bull; <?= number_format($req['total_doses']) ?> doses
                                        </span>
                                    </td>
                                    <td>
                                        <span style="display:inline-flex; align-items:center; gap:5px; background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; padding:4px 10px; border-radius:20px; font-size:11.5px; font-weight:700;">
                                            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($req['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if(!empty($req['dispatch_type'])): ?>
                                            <?php if($req['dispatch_type'] === 'Immediate'): ?>
                                                <div style="display:flex; align-items:center; gap:8px;">
                                                    <span style="display:inline-flex; align-items:center; gap:4px; background:#ecfdf5; color:#047857; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:700;">
                                                        <i class="fa-solid fa-bolt"></i> Immediate
                                                    </span>
                                                    <span style="font-size:11.5px; color:#64748b;">
                                                        <?= $req['dispatch_time'] ? date('D, M d \a\t h:i A', strtotime($req['dispatch_time'])) : '' ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <div style="display:flex; flex-direction:column; gap:2px;">
                                                    <span style="display:inline-flex; align-items:center; gap:4px; background:#eff6ff; color:#2563eb; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:700; width:fit-content;">
                                                        <i class="fa-solid fa-calendar-check"></i> Scheduled
                                                    </span>
                                                    <span style="font-size:11.5px; font-weight:600; color:#0f172a;">
                                                        <i class="fa-regular fa-clock" style="color:#2563eb; margin-right:3px;"></i>
                                                        <?= $req['dispatch_time'] ? date('D, M d, Y \a\t h:i A', strtotime($req['dispatch_time'])) : 'TBD' ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if(!empty($req['dispatch_notes'])): ?>
                                                <div style="font-size:11px; color:#64748b; margin-top:4px;">
                                                    <i class="fa-solid fa-message" style="margin-right:3px;"></i> <?= htmlspecialchars($req['dispatch_notes']) ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#94a3b8; font-size:12px;">Standard handoff</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- ===== DISPATCH SCHEDULE MODAL ===== -->
    <div class="modal-overlay" id="dispatchModal" style="display:none; position:fixed; inset:0; background:rgba(15, 23, 42, 0.65); backdrop-filter:blur(8px); z-index:1000; align-items:center; justify-content:center;">
        <div class="modal" style="background:#ffffff; border-radius:20px; width:520px; max-width:92vw; box-shadow:0 25px 50px -12px rgba(0, 0, 0, 0.3); border:1px solid #e2e8f0; overflow:hidden; animation: modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
            
            <!-- Header -->
            <div style="background:linear-gradient(135deg, #065f46 0%, #047857 100%); padding:24px; color:#ffffff; position:relative;">
                <button type="button" onclick="closeDispatchModal()" style="position:absolute; right:18px; top:18px; background:rgba(255,255,255,0.15); border:none; color:#ffffff; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <i class="fa-solid fa-xmark" style="font-size:16px;"></i>
                </button>
                
                <div style="display:flex; align-items:center; gap:14px;">
                    <div style="width:48px; height:48px; background:rgba(255,255,255,0.2); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:22px; backdrop-filter:blur(4px);">
                        <i class="fa-solid fa-truck-ramp-box"></i>
                    </div>
                    <div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <h3 style="margin:0; font-size:18px; font-weight:800; letter-spacing:-0.2px;">Fulfill &amp; Dispatch Requisition</h3>
                            <span id="dispReqId" style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:6px; font-size:11px; font-family:monospace; font-weight:700;">#REQ-0000</span>
                        </div>
                        <p style="margin:4px 0 0 0; font-size:12px; opacity:0.9;">Authorize cold-chain drug stock transfer to ward station</p>
                    </div>
                </div>
            </div>

            <div style="padding:24px;">

                <!-- Requisition Snapshot Card -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:14px; padding:16px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <div style="width:40px; height:40px; background:#eff6ff; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#2563eb; font-size:16px; font-weight:700; border:1px solid #bfdbfe;">
                            <i class="fa-solid fa-user-nurse"></i>
                        </div>
                        <div>
                            <div style="font-size:11px; font-weight:700; text-transform:uppercase; color:#64748b; letter-spacing:0.5px;">Requesting Staff</div>
                            <div style="font-size:14px; font-weight:700; color:#0f172a; margin-top:1px;" id="dispNurseName">Nurse</div>
                        </div>
                    </div>
                    
                    <div style="text-align:right;">
                        <div style="font-size:16px; font-weight:800; color:#047857;" id="dispDoses">0 Doses</div>
                        <div style="font-size:11px; font-weight:600; color:#64748b; margin-top:1px;" id="dispItems">0 Types</div>
                    </div>
                </div>

                <form method="POST" id="dispatchForm" action="requisitions.php">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="requisition_id" id="dispFormReqId">

                    <!-- Dispatch Mode Selector -->
                    <label style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#475569; margin-bottom:10px; display:block;">
                        Logistics Dispatch Strategy
                    </label>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:20px;">
                        <!-- Immediate Option -->
                        <label style="cursor:pointer;" onclick="selectDispatchType('Immediate')">
                            <input type="radio" name="dispatch_type" value="Immediate" checked style="display:none;">
                            <div class="dispatch-card active" id="optImmediate" style="border:2px solid #047857; background:#ecfdf5; border-radius:14px; padding:14px; text-align:left; transition:all 0.2s; position:relative;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                    <i class="fa-solid fa-bolt" style="font-size:18px; color:#047857;"></i>
                                    <span class="dispatch-badge" style="background:#047857; color:#fff; font-size:10px; font-weight:800; padding:2px 6px; border-radius:4px;">ACTIVE</span>
                                </div>
                                <div style="font-weight:700; font-size:13px; color:#065f46;">Immediate Dispatch</div>
                                <div style="font-size:11px; color:#047857; margin-top:2px; line-height:1.3;">Stock available now in pharmacy storage</div>
                            </div>
                        </label>

                        <!-- Scheduled Option -->
                        <label style="cursor:pointer;" onclick="selectDispatchType('Scheduled')">
                            <input type="radio" name="dispatch_type" value="Scheduled" style="display:none;">
                            <div class="dispatch-card" id="optScheduled" style="border:2px solid #cbd5e1; background:#ffffff; border-radius:14px; padding:14px; text-align:left; transition:all 0.2s; position:relative;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                    <i class="fa-solid fa-calendar-check" style="font-size:18px; color:#64748b;"></i>
                                    <span class="dispatch-badge" style="display:none; background:#2563eb; color:#fff; font-size:10px; font-weight:800; padding:2px 6px; border-radius:4px;">ACTIVE</span>
                                </div>
                                <div style="font-weight:700; font-size:13px; color:#334155;">Scheduled Batch</div>
                                <div style="font-size:11px; color:#64748b; margin-top:2px; line-height:1.3;">Specify future date &amp; delivery window</div>
                            </div>
                        </label>
                    </div>

                    <!-- Scheduled Date/Time Input -->
                    <div id="scheduledFields" style="display:none; margin-bottom:20px;">
                        <label style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#475569; margin-bottom:6px; display:block;">
                            Scheduled Delivery Window
                        </label>
                        <div style="position:relative;">
                            <i class="fa-regular fa-clock" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:14px;"></i>
                            <input type="datetime-local" name="dispatch_time" class="form-control" id="dispatchTimeInput" value="<?= date('Y-m-d\TH:i') ?>" style="padding-left:40px; border-radius:10px; padding-top:10px; padding-bottom:10px; font-size:13px; font-weight:600; border:1.5px solid #cbd5e1; width:100%; box-sizing:border-box;">
                        </div>
                    </div>

                    <!-- Notes -->
                    <div style="margin-bottom:20px;">
                        <label style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#475569; margin-bottom:6px; display:block;">
                            Logistics / Ward Notes <span style="font-weight:400; color:#94a3b8; text-transform:none;">(optional)</span>
                        </label>
                        <textarea name="dispatch_notes" class="form-control" rows="2" placeholder="e.g. Cold box #2 assigned to Ward 3 station desk..." style="border-radius:10px; padding:10px 14px; font-size:13px; border:1.5px solid #cbd5e1; width:100%; line-height:1.4; box-sizing:border-box;"></textarea>
                    </div>

                    <!-- Immediate Info Banner -->
                    <div id="immediateNotice" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; padding:12px 16px; border-radius:12px; font-size:12px; font-weight:600; margin-bottom:20px; display:flex; align-items:center; gap:10px;">
                        <i class="fa-solid fa-circle-info" style="font-size:16px; color:#2563eb;"></i>
                        <div>Cold-chain vaccines are reserved and marked for immediate handoff to requesting nurse.</div>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex; gap:12px; border-top:1px solid #f1f5f9; padding-top:18px;">
                        <button type="button" class="ph-btn ph-btn-outline" onclick="closeDispatchModal()" style="flex:1; justify-content:center; padding:12px; border-radius:10px; font-weight:600; border-color:#cbd5e1;">
                            Cancel
                        </button>
                        <button type="submit" class="ph-btn ph-btn-green" style="flex:2; justify-content:center; padding:12px; border-radius:10px; font-weight:700;">
                            <i class="fa-solid fa-circle-check" style="margin-right:6px;"></i> Authorize &amp; Dispatch
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        @keyframes modalPop {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
    </style>

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

        function openDispatchModal(reqId, nurseName, totalDoses, itemCount) {
            document.getElementById('dispReqId').innerText = '#REQ-' + String(reqId).padStart(4, '0');
            document.getElementById('dispNurseName').innerText = nurseName;
            document.getElementById('dispDoses').innerText = Number(totalDoses).toLocaleString() + ' Vials / Doses';
            document.getElementById('dispItems').innerText = itemCount + ' Vaccine Type' + (itemCount > 1 ? 's' : '');
            document.getElementById('dispFormReqId').value = reqId;

            // Reset to Immediate
            selectDispatchType('Immediate');
            document.getElementById('dispatchModal').style.display = 'flex';
        }

        function closeDispatchModal() {
            document.getElementById('dispatchModal').style.display = 'none';
        }

        function selectDispatchType(type) {
            const isScheduled = (type === 'Scheduled');
            const radioImmediate = document.querySelector('input[name="dispatch_type"][value="Immediate"]');
            const radioScheduled = document.querySelector('input[name="dispatch_type"][value="Scheduled"]');

            if (radioScheduled && radioImmediate) {
                if (isScheduled) {
                    radioScheduled.checked = true;
                } else {
                    radioImmediate.checked = true;
                }
            }

            const cardImmediate = document.getElementById('optImmediate');
            const cardScheduled = document.getElementById('optScheduled');

            const badgeImmediate = cardImmediate.querySelector('.dispatch-badge');
            const badgeScheduled = cardScheduled.querySelector('.dispatch-badge');

            if (isScheduled) {
                cardScheduled.style.borderColor = '#2563eb';
                cardScheduled.style.background = '#eff6ff';
                cardScheduled.querySelector('i').style.color = '#2563eb';
                cardScheduled.querySelector('div:nth-child(2)').style.color = '#1e40af';
                badgeScheduled.style.display = 'inline-block';

                cardImmediate.style.borderColor = '#cbd5e1';
                cardImmediate.style.background = '#ffffff';
                cardImmediate.querySelector('i').style.color = '#64748b';
                cardImmediate.querySelector('div:nth-child(2)').style.color = '#334155';
                badgeImmediate.style.display = 'none';

                document.getElementById('scheduledFields').style.display = 'block';
                document.getElementById('immediateNotice').style.display = 'none';
                document.getElementById('dispatchTimeInput').required = true;
            } else {
                cardImmediate.style.borderColor = '#047857';
                cardImmediate.style.background = '#ecfdf5';
                cardImmediate.querySelector('i').style.color = '#047857';
                cardImmediate.querySelector('div:nth-child(2)').style.color = '#065f46';
                badgeImmediate.style.display = 'inline-block';

                cardScheduled.style.borderColor = '#cbd5e1';
                cardScheduled.style.background = '#ffffff';
                cardScheduled.querySelector('i').style.color = '#64748b';
                cardScheduled.querySelector('div:nth-child(2)').style.color = '#334155';
                badgeScheduled.style.display = 'none';

                document.getElementById('scheduledFields').style.display = 'none';
                document.getElementById('immediateNotice').style.display = 'block';
                document.getElementById('dispatchTimeInput').required = false;
            }
        }
    </script>
</body>
</html>