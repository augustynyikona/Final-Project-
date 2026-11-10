<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pharmacy') {
    header("Location: ../auth/login.php");
    exit;
}

$pharmacist_name = $_SESSION['name'] ?? 'Pharmacist';
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    $vaccine_id         = $_POST['vaccine_id'] ?? '';
    $supplier_id        = $_POST['supplier_id'] ?? '';
    $quantity           = (int)($_POST['quantity'] ?? 0);
    $order_date         = $_POST['order_date'] ?? date('Y-m-d');
    $requested_delivery = $_POST['requested_delivery'] ?? '';
    $notes              = trim($_POST['notes'] ?? '');

    if (empty($vaccine_id) || empty($supplier_id) || empty($quantity)) {
        $error = "Please fill all required fields.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO purchase_orders (vaccine_id, supplier_id, quantity, order_date, requested_delivery, notes) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$vaccine_id, $supplier_id, $quantity, $order_date, $requested_delivery, $notes])) {
            $success = "Purchase Order placed successfully!";

            // Notify nurses if they requested this vaccine and it was out of stock
            $req_stmt = $pdo->prepare("
                SELECT DISTINCT r.nurse_id, v.name 
                FROM requisition_items ri
                JOIN requisitions r ON ri.requisition_id = r.id
                JOIN vaccines v ON ri.vaccine_id = v.id
                WHERE ri.vaccine_id = ? AND r.status = 'Pending'
            ");
            $req_stmt->execute([$vaccine_id]);
            $requesting_nurses = $req_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($requesting_nurses as $nurse) {
                $msg = "An order has been placed for Vaccine " . $nurse['name'] . " that you requested. You will be notified when it is restocked.";
                $ins_msg = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, sender_role, recipient_role, subject, message) VALUES (?, ?, 'pharmacy', 'nurse', 'Vaccine Ordered', ?)");
                $ins_msg->execute([$_SESSION['user_id'], $nurse['nurse_id'], $msg]);
            }
        } else {
            $error = "Failed to place order.";
        }
    }
}

// Demand predictions
$stmt = $pdo->query("
    SELECT v.id, v.name, v.current_stock, (v.low_stock_threshold * 3) as required_stock,
           s.name as default_supplier
    FROM vaccines v
    LEFT JOIN suppliers s ON s.id = (SELECT supplier_id FROM vaccine_batches WHERE vaccine_id = v.id ORDER BY created_at DESC LIMIT 1)
    WHERE v.current_stock <= (v.low_stock_threshold + 50)
");
$predictions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$vaccines = $pdo->query("
    SELECT v.id, v.name, v.current_stock, 
           (SELECT COUNT(*) FROM requisition_items ri 
            JOIN requisitions r ON ri.requisition_id = r.id 
            WHERE ri.vaccine_id = v.id AND r.status = 'Pending') as pending_requests
    FROM vaccines v 
    ORDER BY v.name ASC
")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE status='Active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Active orders
$stmt = $pdo->query("
    SELECT o.*, v.name as vaccine_name, s.name as supplier_name
    FROM purchase_orders o
    JOIN vaccines v ON o.vaccine_id = v.id
    JOIN suppliers s ON o.supplier_id = s.id
    ORDER BY o.created_at DESC
");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sidebar counts
$pending_orders       = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending'")->fetchColumn();
$pending_requisitions = $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'Pending'")->fetchColumn();
$low_stock_count      = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= 20")->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'pharmacy' OR recipient_role = 'pharmacist') AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_msg_count = $stmt->fetchColumn();

$preselected_vaccine_id = (int)($_GET['vaccine_id'] ?? 0);
$chemopharm_orders_count = $pdo->query("SELECT COUNT(*) FROM purchase_orders po JOIN suppliers s ON po.supplier_id = s.id WHERE s.name LIKE '%Chemopharm%'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders &amp; Procurement — VaxCare Pharmacy</title>
    <meta name="description" content="Predict vaccine demand and place purchase orders with suppliers in VaxCare.">
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

        /* KPI Quick Grid */
        .order-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }
        @media (max-width: 1024px) {
            .order-kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 640px) {
            .order-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
        .order-kpi-card {
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
        .order-kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .order-kpi-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .order-kpi-title {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .order-kpi-val {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin-top: 2px;
            line-height: 1.1;
        }

        /* Card Section */
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

        /* Modern Tables */
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
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Procurement &amp; Billing</div>
            <a href="orders.php" class="sidebar-item active">
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
                    <h1 style="margin:0 0 6px 0;">Vaccine Procurement &amp; Purchase Orders</h1>
                    <div class="topbar-breadcrumbs" style="margin-bottom:6px;">
                        <span>Procurement &amp; Billing</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span style="color:#0f172a; font-weight:700;">Purchase Orders</span>
                    </div>
                    <div class="date-range" style="margin-top:0;">
                        <i class="fa-solid fa-snowflake" style="color:#05cd99;"></i> <?= date('d M, Y') ?> &bull; Ndola Central Hospital &bull; Cold-Chain Station
                    </div>
                </div>
            </div>

            <div class="topbar-right">
                <button class="ph-btn ph-btn-green" onclick="openPlaceOrderModal()" style="padding: 6px 11px; font-size: 12px; border-radius: 8px; gap: 5px; height: 34px; white-space: nowrap;">
                    <i class="fa-solid fa-plus" style="font-size: 11px;"></i> Place Order
                </button>
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
                    <div style="font-weight:700;">Procurement Action Error</div>
                    <div style="font-size:12.5px; font-weight:500; opacity:0.95;"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div style="background:#ecfdf5; border:1.5px solid #a7f3d0; color:#065f46; padding:16px 20px; border-radius:14px; margin-bottom:24px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 6px rgba(16,185,129,0.06);">
                <i class="fa-solid fa-circle-check" style="font-size:18px; color:#05cd99;"></i>
                <div>
                    <div style="font-weight:700;">Purchase Order Generated</div>
                    <div style="font-size:12.5px; font-weight:500; opacity:0.95;"><?= htmlspecialchars($success) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- KPI Summary Cards -->
        <div class="order-kpi-grid">
            <div class="order-kpi-card">
                <div class="order-kpi-icon" style="background:#eff6ff; color:#2563eb;">
                    <i class="fa-solid fa-truck-ramp-box"></i>
                </div>
                <div>
                    <div class="order-kpi-title">Total POs Logged</div>
                    <div class="order-kpi-val"><?= count($orders) ?></div>
                </div>
            </div>

            <div class="order-kpi-card">
                <div class="order-kpi-icon" style="background:#fef3c7; color:#d97706;">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div>
                    <div class="order-kpi-title">Pending Delivery</div>
                    <div class="order-kpi-val"><?= $pending_orders ?></div>
                </div>
            </div>

            <div class="order-kpi-card">
                <div class="order-kpi-icon" style="background:#fef2f2; color:#ef4444;">
                    <i class="fa-solid fa-chart-line-down"></i>
                </div>
                <div>
                    <div class="order-kpi-title">Demand Shortfalls</div>
                    <div class="order-kpi-val"><?= count($predictions) ?></div>
                </div>
            </div>

            <div class="order-kpi-card">
                <div class="order-kpi-icon" style="background:#ecfdf5; color:#05cd99;">
                    <i class="fa-solid fa-handshake-angle"></i>
                </div>
                <div>
                    <div class="order-kpi-title">Chemopharm Orders</div>
                    <div class="order-kpi-val"><?= $chemopharm_orders_count ?></div>
                </div>
            </div>
        </div>

        <!-- Featured Chemopharm Supplier Banner -->
        <div style="background: linear-gradient(135deg, #0d1527 0%, #16243d 50%, #0d1527 100%); border-radius: 20px; padding: 26px 30px; margin-bottom: 28px; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 20px; position: relative; overflow: hidden; border:1px solid rgba(255,255,255,0.08); box-shadow:0 8px 24px rgba(13,21,39,0.12);">
            <div style="position:absolute; top:-30px; right:-30px; width:180px; height:180px; border-radius:50%; background:rgba(5,205,153,0.08); pointer-events:none;"></div>
            
            <div style="display:flex; align-items:center; gap:20px; position:relative; flex: 1 1 320px;">
                <div style="width:58px; height:58px; border-radius:16px; background:linear-gradient(135deg, rgba(5,205,153,0.2) 0%, rgba(5,205,153,0.05) 100%); border:1px solid rgba(5,205,153,0.3); display:flex; align-items:center; justify-content:center; font-size:24px; flex-shrink: 0; color:#05cd99;">
                    <i class="fa-solid fa-pills"></i>
                </div>
                <div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:11px; font-weight:700; color:#05cd99; text-transform:uppercase; letter-spacing:1px;">Accredited National Distributor</span>
                        <span style="background:rgba(5,205,153,0.15); color:#05cd99; font-size:10px; font-weight:800; padding:2px 8px; border-radius:20px;">WHO-PQS</span>
                    </div>
                    <div style="font-size:21px; font-weight:800; color:#FFFFFF; margin-top:2px;">Chemopharm (Zambia) Ltd</div>
                    <div style="font-size:13px; color:#94A3B8; margin-top:4px;">Direct refrigerated transit &bull; Validated continuous cold-chain datalogging &bull; Primary hospital supplier</div>
                </div>
            </div>

            <div style="display:flex; flex-wrap: wrap; gap:14px; position:relative; flex: 1 1 auto; justify-content: flex-end; align-items:center;">
                <div style="text-align:center; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:12px; padding:10px 20px;">
                    <div style="font-size:22px; font-weight:800; color:#05CD99;"><?= $chemopharm_orders_count ?></div>
                    <div style="font-size:11px; color:#94A3B8; font-weight:600;">Active &amp; Past Orders</div>
                </div>
                <button type="button" onclick="openChemoPharmOrder()" class="ph-btn ph-btn-green" style="padding:12px 24px; font-size:13.5px; font-weight:700;">
                    <i class="fa-solid fa-truck-fast"></i> Order Directly from Chemopharm
                </button>
            </div>
        </div>

        <!-- Demand Predictions Card -->
        <div class="content-card" style="border-top: 3px solid #ef4444;">
            <div class="content-card-header">
                <div>
                    <h2 class="content-card-title">
                        <i class="fa-solid fa-chart-line" style="color:#ef4444;"></i>
                        Vaccine Demand Prediction (30-Day Deficit Forecast)
                    </h2>
                    <div class="content-card-sub">Items forecasted to hit critical depletion based on clinical burn rates and minimum inventory thresholds</div>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <?php if(!empty($predictions)): ?>
                        <span style="display:inline-flex; align-items:center; gap:6px; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; font-size:12px; font-weight:700; padding:5px 12px; border-radius:20px;">
                            <span style="width:6px; height:6px; border-radius:50%; background:#ef4444;"></span>
                            <?= count($predictions) ?> Shortfalls Forecasted
                        </span>
                    <?php endif; ?>
                    <a href="inventory.php" class="ph-btn ph-btn-outline" style="padding:6px 14px; font-size:12px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-boxes-stacked"></i> View All
                    </a>
                </div>
            </div>

            <?php if(empty($predictions)): ?>
                <div style="color:#047857; background:#ecfdf5; font-size:13.5px; font-weight:600; text-align:center; padding:24px 20px; border-radius:12px; margin:20px;">
                    <i class="fa-solid fa-circle-check" style="color:#05cd99; margin-right:8px; font-size:16px;"></i>
                    No immediate vaccine shortfalls predicted. All formulary medications meet the 30-day safety stock buffer.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="ph-modern-table">
                        <thead>
                            <tr>
                                <th>Medication / Vaccine</th>
                                <th>Current Inventory</th>
                                <th>Recommended 30D Stock</th>
                                <th>Primary Supplier</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($predictions as $p): ?>
                                <tr>
                                    <td>
                                        <strong style="color:#0f172a; font-size:13.5px;"><?= htmlspecialchars($p['name']) ?></strong>
                                    </td>
                                    <td>
                                        <span style="display:inline-flex; align-items:center; gap:5px; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; font-size:12px; font-weight:700; padding:3px 9px; border-radius:6px;">
                                            <i class="fa-solid fa-triangle-exclamation"></i> <?= number_format($p['current_stock']) ?> doses
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight:700; color:#0f172a;"><?= number_format($p['required_stock']) ?> doses</span>
                                        <span style="font-size:11.5px; color:#64748b; margin-left:4px;">(Deficit: <?= number_format(max(0, $p['required_stock'] - $p['current_stock'])) ?>)</span>
                                    </td>
                                    <td style="color:#475569; font-weight:500;">
                                        <?= htmlspecialchars($p['default_supplier'] ?: 'Accredited Supplier') ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <button type="button" class="ph-btn ph-btn-green" style="padding:6px 14px; font-size:12px;" onclick="openPlaceOrderModal(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">
                                            <i class="fa-solid fa-cart-plus"></i> Order Stock
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Purchase Order History Table -->
        <div class="content-card">
            <div class="content-card-header">
                <div>
                    <h2 class="content-card-title">
                        <i class="fa-solid fa-clock-rotate-left" style="color:#05cd99;"></i>
                        Purchase Order Registry &amp; Inbound Shipments
                    </h2>
                    <div class="content-card-sub">Real-time status tracking of all supplier procurements and scheduled shipments</div>
                </div>
                <button type="button" class="ph-btn ph-btn-outline" onclick="openPlaceOrderModal()">
                    <i class="fa-solid fa-plus"></i> New PO
                </button>
            </div>

            <?php if(empty($orders)): ?>
                <div style="color:#64748b; font-size:13px; text-align:center; padding:40px 0;">
                    No purchase orders logged in system yet.
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="ph-modern-table">
                        <thead>
                            <tr>
                                <th>Order Ref</th>
                                <th>Target Vaccine</th>
                                <th>Supplier / Distributor</th>
                                <th>Quantity (Vials)</th>
                                <th>Order Date</th>
                                <th>Requested Delivery</th>
                                <th>Fulfillment Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($orders as $o): ?>
                                <tr>
                                    <td>
                                        <span class="lot-code-pill">ORD-<?= str_pad($o['id'], 4, '0', STR_PAD_LEFT) ?></span>
                                    </td>
                                    <td>
                                        <strong style="color:#0f172a; font-size:13.5px;"><?= htmlspecialchars($o['vaccine_name']) ?></strong>
                                    </td>
                                    <td>
                                        <span style="display:inline-flex; align-items:center; gap:6px; color:#334155; font-weight:600;">
                                            <i class="fa-solid fa-building" style="color:#94a3b8; font-size:12px;"></i>
                                            <?= htmlspecialchars($o['supplier_name']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight:700; color:#047857; background:#ecfdf5; padding:3px 9px; border-radius:6px; border:1px solid #a7f3d0;">
                                            <?= number_format($o['quantity']) ?> vials
                                        </span>
                                    </td>
                                    <td>
                                        <span style="color:#475569; font-size:12.5px;"><?= date('d M, Y', strtotime($o['order_date'])) ?></span>
                                    </td>
                                    <td>
                                        <span style="color:#475569; font-size:12.5px;">
                                            <?= $o['requested_delivery'] ? date('d M, Y', strtotime($o['requested_delivery'])) : '—' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($o['status'] === 'Pending'): ?>
                                            <span style="display:inline-flex; align-items:center; gap:5px; background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:20px;">
                                                <i class="fa-solid fa-hourglass-half"></i> Pending Delivery
                                            </span>
                                        <?php else: ?>
                                            <span style="display:inline-flex; align-items:center; gap:5px; background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:20px;">
                                                <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($o['status']) ?>
                                            </span>
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

    <!-- ===== MODERN PLACE ORDER MODAL ===== -->
    <div class="modal-overlay" id="placeOrderModal" style="<?= $preselected_vaccine_id ? 'display:flex;' : 'display:none;' ?> position:fixed; inset:0; background:rgba(15, 23, 42, 0.65); backdrop-filter:blur(8px); z-index:1000; align-items:center; justify-content:center;">
        <div class="modal" style="background:#ffffff; border-radius:20px; width:520px; max-width:92vw; box-shadow:0 25px 50px -12px rgba(0, 0, 0, 0.3); border:1px solid #e2e8f0; overflow:hidden;">
            
            <!-- Header -->
            <div style="background:linear-gradient(135deg, #0d1527 0%, #16243d 100%); padding:22px 24px; color:#ffffff; position:relative; border-bottom:1px solid rgba(255,255,255,0.08);">
                <button type="button" onclick="closePlaceOrderModal()" style="position:absolute; right:18px; top:18px; background:rgba(255,255,255,0.15); border:none; color:#ffffff; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg, #05cd99 0%, #047857 100%); display:flex; align-items:center; justify-content:center; color:#fff; font-size:20px;">
                        <i class="fa-solid fa-truck-ramp-box"></i>
                    </div>
                    <div>
                        <h3 style="margin:0; font-size:18px; font-weight:800; color:#ffffff;">Place Purchase Order</h3>
                        <p style="margin:2px 0 0 0; font-size:12px; color:#94a3b8;">Issue procurement order to accredited medical supplier</p>
                    </div>
                </div>
            </div>

            <!-- Body -->
            <div style="padding:24px;">
                <form method="POST" id="modalOrderForm" action="orders.php">
                    <input type="hidden" name="action" value="place_order">

                    <!-- Vaccine Name -->
                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">Medication / Vaccine <span style="color:#ef4444;">*</span></label>
                        <select name="vaccine_id" id="modalVaccineSelect" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; background:#fff; outline:none; box-sizing:border-box;" required>
                            <option value="">Select medication...</option>
                            <?php foreach($vaccines as $v): 
                                $requested_text = ($v['pending_requests'] > 0 && $v['current_stock'] <= 0) ? ' — ⚠️ Requested by Nurse' : '';
                            ?>
                                <option value="<?= $v['id'] ?>" <?= $v['id'] == $preselected_vaccine_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($v['name']) ?> (Stock: <?= number_format($v['current_stock']) ?>)<?= $requested_text ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Supplier -->
                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">Accredited Supplier <span style="color:#ef4444;">*</span></label>
                        <select name="supplier_id" id="modalSupplierSelect" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; background:#fff; outline:none; box-sizing:border-box;" required>
                            <option value="">Select supplier...</option>
                            <?php foreach($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= stripos($s['name'], 'chemopharm') !== false ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['name']) ?>
                                    <?php if(stripos($s['name'], 'chemopharm') !== false): ?> ⭐ Preferred Partner<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size:11.5px; color:#059669; font-weight:600; margin-top:6px; display:flex; align-items:center; gap:5px;">
                            <i class="fa-solid fa-circle-check"></i> Chemopharm (Zambia) Ltd is our priority cold-chain partner
                        </div>
                    </div>

                    <!-- Quantity -->
                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">Quantity (Vials / Doses) <span style="color:#ef4444;">*</span></label>
                        <input type="number" name="quantity" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; background:#fff; outline:none; box-sizing:border-box;" placeholder="e.g. 1000" min="1" required>
                    </div>

                    <!-- Dates Grid -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">Order Date <span style="color:#ef4444;">*</span></label>
                            <input type="date" name="order_date" value="<?= date('Y-m-d') ?>" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none; box-sizing:border-box;" required>
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">Delivery By <span style="color:#ef4444;">*</span></label>
                            <input type="date" name="requested_delivery" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none; box-sizing:border-box;" required>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div style="margin-bottom:24px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">Procurement Notes (Optional)</label>
                        <textarea name="notes" rows="2" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none; box-sizing:border-box; line-height:1.4;" placeholder="Specific instructions regarding temperature requirements..."></textarea>
                    </div>

                    <div style="display:flex; gap:12px; border-top:1px solid #f1f5f9; padding-top:18px;">
                        <button type="button" class="ph-btn ph-btn-outline" onclick="closePlaceOrderModal()" style="flex:1; justify-content:center; padding:12px;">
                            Cancel
                        </button>
                        <button type="submit" class="ph-btn ph-btn-green" style="flex:2; justify-content:center; padding:12px; font-weight:700;">
                            <i class="fa-solid fa-paper-plane" style="margin-right:6px;"></i> Issue Purchase Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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

        function openPlaceOrderModal(vaccineId, vaccineName) {
            if (vaccineId) {
                var sel = document.getElementById('modalVaccineSelect');
                if (sel) sel.value = vaccineId;
            }
            document.getElementById('placeOrderModal').style.display = 'flex';
        }

        function openChemoPharmOrder() {
            var supSel = document.getElementById('modalSupplierSelect');
            if (supSel) {
                for (var i = 0; i < supSel.options.length; i++) {
                    if (supSel.options[i].text.toLowerCase().includes('chemopharm')) {
                        supSel.selectedIndex = i;
                        break;
                    }
                }
            }
            document.getElementById('placeOrderModal').style.display = 'flex';
        }

        function closePlaceOrderModal() {
            document.getElementById('placeOrderModal').style.display = 'none';
        }
    </script>
</body>
</html>