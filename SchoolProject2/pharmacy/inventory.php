<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pharmacy') {
    header("Location: ../auth/login.php");
    exit;
}

$pharmacist_name = $_SESSION['name'];
$success = '';
$error = '';

// Handle updating stock (Batch insertion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_batch') {
    $vaccine_id = $_POST['vaccine_id'];
    $batch_number = trim($_POST['batch_number']);
    $quantity = (int) $_POST['quantity'];
    $expiry_date = $_POST['expiry_date'];
    $supplier_id = $_POST['supplier_id'];

    if (empty($vaccine_id) || empty($batch_number) || empty($quantity) || empty($expiry_date)) {
        $error = "All batch details are required.";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO vaccine_batches (vaccine_id, batch_number, quantity, expiry_date, supplier_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$vaccine_id, $batch_number, $quantity, $expiry_date, $supplier_id]);
            $stmt = $pdo->prepare("UPDATE vaccines SET current_stock = current_stock + ? WHERE id = ?");
            $stmt->execute([$quantity, $vaccine_id]);
            $pdo->commit();
            $success = "Medication stock updated. Batch added successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Database error while adding batch.";
        }
    }
}

// Handle placing an order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    $vaccine_id = $_POST['vaccine_id'];
    $supplier_id = $_POST['supplier_id'];
    $quantity = (int) $_POST['quantity'];
    $order_date = $_POST['order_date'];
    $requested_delivery = $_POST['requested_delivery'];
    $notes = trim($_POST['notes']);

    if (empty($vaccine_id) || empty($supplier_id) || empty($quantity)) {
        $error = "Please fill all required fields.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO purchase_orders (vaccine_id, supplier_id, quantity, order_date, requested_delivery, notes) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$vaccine_id, $supplier_id, $quantity, $order_date, $requested_delivery, $notes])) {
            $success = "Purchase Order placed successfully!";
        } else {
            $error = "Failed to place order.";
        }
    }
}

// Fetch all batches
$search = $_GET['search'] ?? '';
$searchSql = $search ? "WHERE (v.name LIKE ? OR b.batch_number LIKE ?)" : "";
$stmt = $pdo->prepare("
    SELECT b.*, v.name as vaccine_name, s.name as supplier_name
    FROM vaccine_batches b
    JOIN vaccines v ON b.vaccine_id = v.id
    LEFT JOIN suppliers s ON b.supplier_id = s.id
    $searchSql
    ORDER BY b.created_at DESC
");
if ($search) {
    $stmt->execute(["%$search%", "%$search%"]);
} else {
    $stmt->execute();
}
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch dropdown data
$vaccines  = $pdo->query("SELECT id, name FROM vaccines ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE status='Active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Unread message count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'pharmacy' OR recipient_role = 'pharmacist') AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_msg_count = $stmt->fetchColumn();

// Pending counts for sidebar badges
$pending_orders      = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending'")->fetchColumn();
$pending_requisitions = $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'Pending'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management — VaxiCare</title>
    <meta name="description" content="Manage vaccine and medication batches in the VaxiCare pharmacy inventory.">
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
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-border-all"></i> Dashboard
            </a>

            <div class="sidebar-section-title">Cold Chain &amp; Stock</div>
            <a href="inventory.php" class="sidebar-item active">
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
                    <h1 style="margin:0 0 6px 0;">Cold-Chain Stock &amp; Inventory</h1>
                    <div class="topbar-breadcrumbs" style="margin-bottom:6px;">
                        <span>Cold Chain &amp; Stock</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span style="color:#0f172a; font-weight:700;">Vaccine Stock</span>
                    </div>
                    <div class="date-range" style="margin-top:0;">
                        <i class="fa-solid fa-snowflake" style="color:#05cd99;"></i> <?= date('d M, Y') ?> &bull; Ndola Central Hospital &bull; Cold-Chain Station
                    </div>
                </div>
            </div>

            <div class="topbar-right">
                <button type="button" class="ph-btn ph-btn-outline" onclick="openPlaceOrderModal()">
                    <i class="fa-solid fa-truck"></i> Place Order
                </button>
                <button type="button" class="ph-btn ph-btn-green" onclick="openAddBatchModal()">
                    <i class="fa-solid fa-plus"></i> Update Stock
                </button>

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

        <?php if($error): ?>
            <div class="ph-alert ph-alert-error" style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="ph-alert ph-alert-success" style="background:#ecfdf5; border:1px solid #a7f3d0; color:#047857; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php
        // Predictive 7-Day Demand Forecasting Query
        $stmtDemand = $pdo->query("
            SELECT 
                v.id as vaccine_id,
                v.name as vaccine_name,
                v.current_stock,
                v.low_stock_threshold,
                COUNT(a.id) as projected_7day_demand
            FROM vaccines v
            LEFT JOIN appointments a ON v.id = a.vaccine_id 
                AND a.status IN ('Scheduled', 'Arrived')
                AND a.appointment_date >= CURDATE() 
                AND a.appointment_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            GROUP BY v.id, v.name, v.current_stock, v.low_stock_threshold
            ORDER BY projected_7day_demand DESC, v.name ASC
        ");
        $demand_forecast = $stmtDemand->fetchAll(PDO::FETCH_ASSOC);
        ?>

        <!-- Predictive Stock Demand & Pre-Emptive Forecast Widget -->
        <div class="mar-card" style="margin-bottom:24px; border-left:4px solid #2563eb; box-shadow:0 4px 20px rgba(0,0,0,0.04); border-radius:16px;">
            <div class="mar-card-header" style="border-bottom:1px solid #f1f5f9; padding-bottom:14px; margin-bottom:16px;">
                <div>
                    <div class="mar-card-title" style="font-size:16px; font-weight:700; color:#0f172a;">
                        <i class="fa-solid fa-chart-line-up" style="color:#2563eb; margin-right:8px;"></i> Predictive 7-Day Stock Demand Engine
                    </div>
                    <div class="mar-card-subtitle" style="font-size:12px; color:#64748b; margin-top:2px;">
                        Pre-emptive inventory alignment comparing upcoming scheduled pediatric cohorts against cold-chain stock
                    </div>
                </div>
                <span class="badge-status bg-blue-light" style="padding:6px 12px; border-radius:20px; font-size:11px; font-weight:700;">
                    <i class="fa-solid fa-brain" style="color:#2563eb;"></i> Automated Forecast Active
                </span>
            </div>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:14px;">
                <?php foreach($demand_forecast as $df):
                    $current_stock = (int)$df['current_stock'];
                    $demand = (int)$df['projected_7day_demand'];
                    $surplus = $current_stock - $demand;
                    $isDeficit = ($surplus < 0);
                    $isLowStock = ($current_stock <= (int)$df['low_stock_threshold']);
                ?>
                    <div style="background:<?= $isDeficit ? '#fef2f2' : ($isLowStock ? '#fffbe8' : '#f8fafc') ?>; border:1px solid <?= $isDeficit ? '#fca5a5' : ($isLowStock ? '#fde68a' : '#e2e8f0') ?>; border-radius:12px; padding:14px;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                            <div style="font-weight:700; font-size:13px; color:#0f172a;"><?= htmlspecialchars($df['vaccine_name']) ?></div>
                            <?php if($isDeficit): ?>
                                <span class="badge-status bg-red-light" style="font-size:10px; padding:2px 6px;"><i class="fa-solid fa-triangle-exclamation"></i> Deficit</span>
                            <?php elseif($isLowStock): ?>
                                <span class="badge-status bg-yellow-light" style="font-size:10px; padding:2px 6px;"><i class="fa-solid fa-clock"></i> Low Stock</span>
                            <?php else: ?>
                                <span class="badge-status bg-green-light" style="font-size:10px; padding:2px 6px;"><i class="fa-solid fa-shield-check"></i> Optimal</span>
                            <?php endif; ?>
                        </div>

                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-bottom:10px;">
                            <div>
                                <div style="font-size:10px; font-weight:700; text-transform:uppercase; color:#64748b;">Current Stock</div>
                                <div style="font-size:15px; font-weight:800; color:#0f172a;"><?= number_format($current_stock) ?> <span style="font-size:10px; font-weight:600;">vials</span></div>
                            </div>
                            <div>
                                <div style="font-size:10px; font-weight:700; text-transform:uppercase; color:#64748b;">7-Day Demand</div>
                                <div style="font-size:15px; font-weight:800; color:#2563eb;"><?= number_format($demand) ?> <span style="font-size:10px; font-weight:600;">doses</span></div>
                            </div>
                        </div>

                        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px dashed #cbd5e1; padding-top:8px;">
                            <div style="font-size:11px; font-weight:700; color:<?= $isDeficit ? '#b91c1c' : '#047857' ?>;">
                                <?= $isDeficit ? 'Deficit: ' . abs($surplus) . ' vials needed' : 'Est. Surplus: +' . $surplus . ' vials' ?>
                            </div>
                            <?php if($isDeficit || $isLowStock): ?>
                                <a href="orders.php" class="ph-btn ph-btn-sm <?= $isDeficit ? 'ph-btn-red' : 'ph-btn-yellow' ?>" style="text-decoration:none; padding:4px 8px; font-size:10px;">
                                    <i class="fa-solid fa-truck"></i> Reorder
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Inventory Card -->
        <div class="mar-card" style="box-shadow:0 4px 20px -2px rgba(0,0,0,0.03);">
            <div class="mar-card-header" style="border-bottom:1px solid #f1f5f9; padding-bottom:16px; margin-bottom:18px;">
                <div>
                    <div class="mar-card-title" style="display:flex; align-items:center; gap:8px;">
                        <div style="width:32px; height:32px; border-radius:8px; background:rgba(5,205,153,0.12); color:#05cd99; display:flex; align-items:center; justify-content:center; font-size:14px;">
                            <i class="fa-solid fa-boxes-stacked"></i>
                        </div>
                        <span>Cold-Chain Medication Batches</span>
                    </div>
                    <div class="mar-card-subtitle" style="margin-top:2px;">All tracked cold-room vaccine batches and lot numbers across the facility</div>
                </div>
                <div style="display:flex; gap:10px;">
                    <button class="ph-btn ph-btn-outline" onclick="openPlaceOrderModal()">
                        <i class="fa-solid fa-truck"></i> Place Order
                    </button>
                    <button class="ph-btn ph-btn-green" onclick="openAddBatchModal()">
                        <i class="fa-solid fa-plus"></i> Update Stock
                    </button>
                </div>
            </div>

            <!-- Search & Filter Bar -->
            <form method="GET" action="inventory.php" style="margin-bottom:20px;">
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <div style="position:relative; flex-grow:1; min-width:260px;">
                        <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px;"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by vaccine name or batch number..." style="width:100%; height:42px; padding:10px 14px 10px 40px; border-radius:10px; border:1.5px solid #e2e8f0; font-size:13px; outline:none; transition:all 0.2s;" onfocus="this.style.borderColor='#05cd99';" onblur="this.style.borderColor='#e2e8f0';">
                    </div>
                    <button type="submit" class="ph-btn ph-btn-primary" style="height:42px; padding:0 20px; font-size:13px;">
                        <i class="fa-solid fa-filter"></i> Apply Filter
                    </button>
                    <?php if($search): ?>
                        <a href="inventory.php" class="ph-btn ph-btn-outline" style="height:42px; display:inline-flex; align-items:center; padding:0 16px; font-size:13px;">
                            <i class="fa-solid fa-xmark"></i> Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if(empty($batches)): ?>
                <div style="text-align:center; padding:60px 20px; color:#94a3b8;">
                    <i class="fa-solid fa-boxes-stacked" style="font-size:42px; margin-bottom:12px; display:block; opacity:0.35;"></i>
                    <h4 style="margin:0; font-size:15px; font-weight:700; color:#0f172a;">No medication batches found</h4>
                    <p style="margin:4px 0 0 0; font-size:12.5px; color:#64748b;">Try adjusting your search criteria or intake a new stock batch.</p>
                </div>
            <?php else: ?>
                <table class="mar-table">
                    <thead>
                        <tr>
                            <th>Medication Name</th>
                            <th>Batch Lot</th>
                            <th>Accredited Supplier</th>
                            <th>Quantity (Vials)</th>
                            <th>Expiry Date</th>
                            <th>Lot Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($batches as $b): ?>
                            <?php 
                                $isExpired = strtotime($b['expiry_date']) < time(); 
                                $isNearExpiry = !$isExpired && (strtotime($b['expiry_date']) < strtotime('+60 days'));
                            ?>
                            <tr>
                                <td>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <div style="width:32px; height:32px; border-radius:8px; background:<?= $isExpired ? '#fef2f2' : '#ecfdf5' ?>; color:<?= $isExpired ? '#e11d48' : '#05cd99' ?>; display:flex; align-items:center; justify-content:center; font-size:13px; border:1px solid <?= $isExpired ? '#fca5a5' : '#a7f3d0' ?>;">
                                            <i class="fa-solid fa-pills"></i>
                                        </div>
                                        <strong style="color:#0f172a; font-size:13.5px;"><?= htmlspecialchars($b['vaccine_name']) ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <span style="font-family:monospace; font-weight:700; color:#0f172a; background:#f8fafc; border:1px solid #e2e8f0; padding:3px 9px; border-radius:6px; font-size:12px;">
                                        <?= htmlspecialchars($b['batch_number']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size:12.5px; color:#475569; font-weight:600;">
                                        <i class="fa-solid fa-truck-field" style="color:#94a3b8; margin-right:4px; font-size:11px;"></i>
                                        <?= htmlspecialchars($b['supplier_name'] ?: 'National Medical Stores') ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size:14px; font-weight:800; color:#0f172a;">
                                        <?= number_format($b['quantity']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size:12.5px; color:#64748b; font-weight:600;">
                                        <?= date('M d, Y', strtotime($b['expiry_date'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if($isExpired): ?>
                                        <span class="badge-status bg-red-light" style="font-size:11px; padding:3px 10px;">
                                            <i class="fa-solid fa-triangle-exclamation"></i> Expired
                                        </span>
                                    <?php elseif($isNearExpiry): ?>
                                        <span class="badge-status bg-yellow-light" style="font-size:11px; padding:3px 10px;">
                                            <i class="fa-solid fa-clock"></i> Expiring Soon
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-status bg-green-light" style="font-size:11px; padding:3px 10px;">
                                            <i class="fa-solid fa-check"></i> Active
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <button class="ph-btn ph-btn-outline ph-btn-sm" onclick="openPlaceOrderModal(<?= $b['vaccine_id'] ?>)" title="Reorder Medication">
                                        <i class="fa-solid fa-rotate-right"></i> Reorder
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </main>

    <!-- ===== ADD BATCH POP-UP MODAL ===== -->
    <div class="modal-overlay" id="addBatchModal" style="display:none;">
        <div class="payment-modal-card" style="max-width:550px;">
            <div class="modal-header-secure">
                <h3><i class="fa-solid fa-boxes-packing" style="color:#05cd99;"></i> Update Medication Stock</h3>
                <i class="fa-solid fa-xmark modal-close-icon" onclick="closeAddBatchModal()"></i>
            </div>
            <div class="modal-body-pad">
                <form method="POST" action="inventory.php">
                    <input type="hidden" name="action" value="add_batch">
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                        <!-- Vaccine Selection -->
                        <div style="grid-column: span 2;">
                            <label style="display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#475569; margin-bottom:6px;">Medication / Vaccine Name</label>
                            <select name="vaccine_id" style="width:100%; height:42px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none;" required>
                                <option value="">Select medication...</option>
                                <?php foreach($vaccines as $v): ?>
                                    <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Batch Number -->
                        <div>
                            <label style="display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#475569; margin-bottom:6px;">Batch Number</label>
                            <input type="text" name="batch_number" style="width:100%; height:42px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; font-family:monospace; outline:none;" placeholder="e.g. B240501A" required>
                        </div>

                        <!-- Quantity -->
                        <div>
                            <label style="display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#475569; margin-bottom:6px;">Quantity Received</label>
                            <input type="number" name="quantity" style="width:100%; height:42px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none;" placeholder="e.g. 500" min="1" required>
                        </div>

                        <!-- Expiration Date -->
                        <div>
                            <label style="display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#475569; margin-bottom:6px;">Expiration Date</label>
                            <input type="date" name="expiry_date" style="width:100%; height:42px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none;" required>
                        </div>

                        <!-- Supplier -->
                        <div>
                            <label style="display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#475569; margin-bottom:6px;">Accredited Supplier</label>
                            <select name="supplier_id" style="width:100%; height:42px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none;" required>
                                <option value="">Select supplier...</option>
                                <?php foreach($suppliers as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="ph-btn ph-btn-green" style="width:100%; justify-content:center; padding:13px; font-size:14px; border-radius:11px;">
                        <i class="fa-solid fa-circle-check"></i> Submit &amp; Update Stock
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== MODERN PLACE ORDER POP-UP WINDOW MODAL FORM ===== -->
    <div class="modal-overlay" id="placeOrderModal" style="display:none;">
        <div class="payment-modal-card" style="max-width:520px;">
            <div class="modal-header-secure">
                <h3><i class="fa-solid fa-truck-ramp-box" style="color:#05cd99;"></i> Place Purchase Order</h3>
                <i class="fa-solid fa-xmark modal-close-icon" onclick="closePlaceOrderModal()"></i>
            </div>
            <div class="modal-body-pad">
                <form method="POST" id="modalOrderForm">
                    <input type="hidden" name="action" value="place_order">

                    <!-- Item Name -->
                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#475569; margin-bottom:6px;">Item Name</label>
                        <select name="vaccine_id" id="modalVaccineSelect" style="width:100%; height:42px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none;" required>
                            <option value="">Select item...</option>
                            <?php foreach($vaccines as $v): ?>
                                <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Supplier -->
                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#475569; margin-bottom:6px;">Supplier</label>
                        <select name="supplier_id" style="width:100%; height:42px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none;" required>
                            <option value="">Select supplier...</option>
                            <?php foreach($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Quantity (Vials) -->
                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#475569; margin-bottom:6px;">Quantity (Vials)</label>
                        <input type="number" name="quantity" style="width:100%; height:42px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none;" placeholder="e.g. 1000" min="1" required>
                    </div>

                    <!-- Dates Grid -->
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:14px;">
                        <div>
                            <label style="display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#475569; margin-bottom:6px;">Order Date</label>
                            <input type="date" name="order_date" style="width:100%; height:42px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none;" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label style="display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#475569; margin-bottom:6px;">Delivery By</label>
                            <input type="date" name="requested_delivery" style="width:100%; height:42px; padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none;" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div style="margin-bottom:18px;">
                        <label style="display:block; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:#475569; margin-bottom:6px;">Notes</label>
                        <textarea name="notes" style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none;" rows="3" placeholder="Optional notes for supplier..."></textarea>
                    </div>

                    <button type="submit" class="ph-btn ph-btn-green" style="width:100%; justify-content:center; padding:13px; font-size:14px; border-radius:11px;">
                        <i class="fa-solid fa-truck"></i> Place Order
                    </button>
                </form>
            </div>
        </div>
    </div>

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

        function openPlaceOrderModal(vaccineId, vaccineName) {
            if (vaccineId) {
                var sel = document.getElementById('modalVaccineSelect');
                if (sel) sel.value = vaccineId;
            }
            document.getElementById('placeOrderModal').style.display = 'flex';
        }

        function closePlaceOrderModal() {
            document.getElementById('placeOrderModal').style.display = 'none';
        }

        function openAddBatchModal() {
            document.getElementById('addBatchModal').style.display = 'flex';
        }

        function closeAddBatchModal() {
            document.getElementById('addBatchModal').style.display = 'none';
        }
    </script>
</body>
</html>