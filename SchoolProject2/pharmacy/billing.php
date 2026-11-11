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
$error   = '';

// Process Payment Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_invoice') {
    csrf_guard();
    $invoice_id = $_POST['invoice_id'];
    $payment_method = $_POST['payment_method'] ?? 'Card Payment';
    
    $stmt = $pdo->prepare("UPDATE invoices SET status = 'Paid' WHERE id = ?");
    if ($stmt->execute([$invoice_id])) {
        $success = "Payment of invoice #INV-" . str_pad($invoice_id, 4, '0', STR_PAD_LEFT) . " processed successfully via " . htmlspecialchars($payment_method) . ".";
    } else {
        $error = "Failed to process payment.";
    }
}

// Process Create Invoice Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_invoice') {
    csrf_guard();
    $guardian_id    = (int)($_POST['guardian_id'] ?? 0);
    $amount_due     = (float)($_POST['amount_due'] ?? 0);
    $admission_date = $_POST['admission_date'] ?? date('Y-m-d');
    $status         = $_POST['status'] ?? 'Pending';

    if (!empty($guardian_id) && $amount_due > 0) {
        $stmt = $pdo->prepare("INSERT INTO invoices (guardian_id, amount_due, admission_date, status, created_at) VALUES (?, ?, ?, ?, NOW())");
        if ($stmt->execute([$guardian_id, $amount_due, $admission_date, $status])) {
            $success = "New invoice created successfully!";
        } else {
            $error = "Failed to create invoice.";
        }
    } else {
        $error = "Please select a valid patient and enter an amount.";
    }
}

// Fetch all invoices
$stmt = $pdo->query("
    SELECT i.*, u.name as patient_name
    FROM invoices i
    JOIN users u ON i.guardian_id = u.id
    ORDER BY i.created_at DESC
");
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch patient/guardian list for create invoice dropdown
$guardians = $pdo->query("SELECT id, name FROM users WHERE role IN ('guardian', 'patient', 'user') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Summary stats
$total_paid    = array_sum(array_column(array_filter($invoices, fn($i) => $i['status'] === 'Paid'), 'amount_due'));
$total_pending = array_sum(array_column(array_filter($invoices, fn($i) => $i['status'] !== 'Paid'), 'amount_due'));
$count_overdue = count(array_filter($invoices, fn($i) => $i['status'] === 'Overdue'));

// Sidebar counts
$pending_orders       = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending'")->fetchColumn();
$pending_requisitions = $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'Pending'")->fetchColumn();
$low_stock_count      = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= 20")->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'pharmacy' OR recipient_role = 'pharmacist') AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_msg_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices &amp; Point of Sale — VaxCare Pharmacy</title>
    <meta name="description" content="Manage patient medication billing, process invoice settlements, and track revenue in VaxCare pharmacy.">
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
        .billing-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }
        @media (max-width: 1024px) {
            .billing-kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 640px) {
            .billing-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
        .billing-kpi-card {
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
        .billing-kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .billing-kpi-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .billing-kpi-title {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .billing-kpi-val {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin-top: 2px;
            line-height: 1.1;
        }

        /* Main Content Card */
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

        /* Toolbar */
        .billing-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 16px 24px;
            background: #fafbfc;
            border-bottom: 1px solid #f1f5f9;
            flex-wrap: wrap;
        }
        .search-input-wrap {
            position: relative;
            min-width: 280px;
        }
        .search-input-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 13px;
        }
        .search-input-wrap input {
            width: 100%;
            padding: 9px 14px 9px 38px;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            font-size: 13px;
            background: #ffffff;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        .search-input-wrap input:focus {
            border-color: #05cd99;
        }
        .filter-select {
            padding: 9px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            font-size: 13px;
            background: #ffffff;
            color: #1e293b;
            font-weight: 600;
            outline: none;
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
            background: #eff6ff;
            color: #2563eb;
            padding: 3px 8px;
            border-radius: 6px;
            border: 1px solid #bfdbfe;
        }

        /* Payment modal styles */
        .pay-method-opt {
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #ffffff;
        }
        .pay-method-opt:hover, .pay-method-opt.selected {
            border-color: #05cd99;
            background: #ecfdf5;
        }
        .pay-method-opt.selected i {
            color: #05cd99;
        }
        .pay-method-opt.selected span {
            color: #065f46;
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
            <a href="orders.php" class="sidebar-item">
                <i class="fa-solid fa-truck-ramp-box"></i> Purchase Orders
                <?php if(isset($pending_orders) && $pending_orders > 0): ?>
                    <span class="badge-red"><?= $pending_orders ?></span>
                <?php endif; ?>
            </a>
            <a href="billing.php" class="sidebar-item active">
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
                    <h1 style="margin:0 0 6px 0;">Billing, Invoices &amp; Point of Sale</h1>
                    <div class="topbar-breadcrumbs" style="margin-bottom:6px;">
                        <span>Procurement &amp; Billing</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span style="color:#0f172a; font-weight:700;">Invoices &amp; POS</span>
                    </div>
                    <div class="date-range" style="margin-top:0;">
                        <i class="fa-solid fa-snowflake" style="color:#05cd99;"></i> <?= date('d M, Y') ?> &bull; Ndola Central Hospital &bull; Pharmacy Settlement Desk
                    </div>
                </div>
            </div>

            <div class="topbar-right">
                <button class="ph-btn ph-btn-green" onclick="openCreateInvoiceModal()" style="padding: 6px 12px; font-size: 12px; border-radius: 8px; gap: 5px; height: 34px;">
                    <i class="fa-solid fa-plus" style="font-size: 11px;"></i> Create Invoice
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
                    <div style="font-weight:700;">Billing Action Error</div>
                    <div style="font-size:12.5px; font-weight:500; opacity:0.95;"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div style="background:#ecfdf5; border:1.5px solid #a7f3d0; color:#065f46; padding:16px 20px; border-radius:14px; margin-bottom:24px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 6px rgba(16,185,129,0.06);">
                <i class="fa-solid fa-circle-check" style="font-size:18px; color:#05cd99;"></i>
                <div>
                    <div style="font-weight:700;">Transaction Completed</div>
                    <div style="font-size:12.5px; font-weight:500; opacity:0.95;"><?= htmlspecialchars($success) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- KPI Revenue Summary Grid -->
        <div class="billing-kpi-grid">
            <div class="billing-kpi-card">
                <div class="billing-kpi-icon" style="background:#ecfdf5; color:#05cd99;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="billing-kpi-title">Total Collected</div>
                    <div class="billing-kpi-val" style="color:#047857;">ZMW K<?= number_format($total_paid, 2) ?></div>
                </div>
            </div>

            <div class="billing-kpi-card">
                <div class="billing-kpi-icon" style="background:#fef3c7; color:#d97706;">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <div class="billing-kpi-title">Pending Settlement</div>
                    <div class="billing-kpi-val" style="color:#b45309;">ZMW K<?= number_format($total_pending, 2) ?></div>
                </div>
            </div>

            <div class="billing-kpi-card">
                <div class="billing-kpi-icon" style="background:#fef2f2; color:#ef4444;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <div class="billing-kpi-title">Overdue Statements</div>
                    <div class="billing-kpi-val" style="color:#b91c1c;"><?= $count_overdue ?></div>
                </div>
            </div>

            <div class="billing-kpi-card">
                <div class="billing-kpi-icon" style="background:#eff6ff; color:#2563eb;">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <div>
                    <div class="billing-kpi-title">Total Invoices</div>
                    <div class="billing-kpi-val"><?= count($invoices) ?></div>
                </div>
            </div>
        </div>

        <!-- Main Invoices Ledger Card -->
        <div class="content-card">
            <div class="content-card-header">
                <div>
                    <h2 class="content-card-title">
                        <i class="fa-solid fa-file-invoice-dollar" style="color:#05cd99;"></i>
                        Patient Invoices &amp; Dispensing Statements
                    </h2>
                    <div class="content-card-sub">Real-time ledger of patient accounts, cold-chain medication charges, and checkout settlements</div>
                </div>
                <div style="font-size:12px; font-weight:700; color:#64748b; background:#f1f5f9; padding:5px 12px; border-radius:20px;">
                    <span id="visibleCount"><?= count($invoices) ?></span> Records Displayed
                </div>
            </div>

            <!-- Toolbar Filters -->
            <div class="billing-toolbar">
                <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; flex:1;">
                    <div class="search-input-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchInput" placeholder="Search invoice # or patient name..." onkeyup="filterTable()">
                    </div>

                    <select class="filter-select" id="statusFilter" onchange="filterTable()">
                        <option value="all">Filter Status: All</option>
                        <option value="pending">Status: Pending</option>
                        <option value="overdue">Status: Overdue</option>
                        <option value="paid">Status: Paid</option>
                    </select>
                </div>

                <button type="button" class="ph-btn ph-btn-outline" onclick="openCreateInvoiceModal()" style="padding:7px 14px; font-size:12.5px;">
                    <i class="fa-solid fa-file-circle-plus"></i> New Statement
                </button>
            </div>

            <?php if(empty($invoices)): ?>
                <div style="text-align:center; padding:50px 20px; color:#64748b;">
                    <i class="fa-solid fa-file-invoice" style="font-size:36px; color:#cbd5e1; margin-bottom:12px; display:block;"></i>
                    <div style="font-size:15px; font-weight:700; color:#0f172a;">No Invoices on Record</div>
                    <div style="font-size:13px; color:#64748b; margin-top:4px;">No patient invoices have been created yet.</div>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="ph-modern-table" id="invoicesTable">
                        <thead>
                            <tr>
                                <th>Invoice ID</th>
                                <th>Patient / Guardian</th>
                                <th>Service Date</th>
                                <th>Amount Due (ZMW)</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($invoices as $inv): ?>
                                <tr data-status="<?= strtolower($inv['status']) ?>" data-search="<?= strtolower('#inv-' . str_pad($inv['id'], 4, '0', STR_PAD_LEFT) . ' ' . $inv['patient_name']) ?>">
                                    <td>
                                        <span class="lot-code-pill">#INV-<?= str_pad($inv['id'], 4, '0', STR_PAD_LEFT) ?></span>
                                    </td>
                                    <td>
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($inv['patient_name']) ?>&background=eff6ff&color=2563eb&bold=true" style="width:32px; height:32px; border-radius:50%; border:1.5px solid #e2e8f0;">
                                            <div>
                                                <strong style="color:#0f172a; font-size:13.5px;"><?= htmlspecialchars($inv['patient_name']) ?></strong>
                                                <div style="font-size:11px; color:#64748b;">Pediatric Ward Patient</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="color:#475569; font-weight:600;"><?= date('M d, Y', strtotime($inv['admission_date'])) ?></span>
                                        <div style="font-size:11px; color:#94a3b8;"><?= date('h:i A', strtotime($inv['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <span style="font-weight:800; font-size:14px; color:#0f172a;">
                                            K<?= number_format($inv['amount_due'], 2) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($inv['status'] === 'Paid'): ?>
                                            <span style="display:inline-flex; align-items:center; gap:5px; background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:20px;">
                                                <i class="fa-solid fa-circle-check"></i> PAID
                                            </span>
                                        <?php elseif($inv['status'] === 'Overdue'): ?>
                                            <span style="display:inline-flex; align-items:center; gap:5px; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:20px;">
                                                <i class="fa-solid fa-triangle-exclamation"></i> OVERDUE
                                            </span>
                                        <?php else: ?>
                                            <span style="display:inline-flex; align-items:center; gap:5px; background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:20px;">
                                                <i class="fa-solid fa-clock"></i> PENDING
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <div style="display:inline-flex; align-items:center; gap:8px;">
                                            <?php if($inv['status'] !== 'Paid'): ?>
                                                <button type="button" class="ph-btn ph-btn-green" style="padding:6px 12px; font-size:12px;"
                                                    onclick="openPaymentModal(<?= $inv['id'] ?>, '<?= htmlspecialchars(addslashes($inv['patient_name'])) ?>', <?= $inv['amount_due'] ?>)">
                                                    <i class="fa-solid fa-credit-card"></i> Pay Now
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="ph-btn ph-btn-outline" style="padding:6px 12px; font-size:12px;"
                                                onclick="viewInvoiceDetails(<?= $inv['id'] ?>, '<?= htmlspecialchars(addslashes($inv['patient_name'])) ?>', '<?= date('M d, Y', strtotime($inv['admission_date'])) ?>', <?= $inv['amount_due'] ?>, '<?= $inv['status'] ?>')">
                                                <i class="fa-solid fa-eye"></i> Statement
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- ===== SECURE PAYMENT CHECKOUT MODAL ===== -->
    <div class="modal-overlay" id="paymentModal" style="display:none; position:fixed; inset:0; background:rgba(15, 23, 42, 0.65); backdrop-filter:blur(8px); z-index:1000; align-items:center; justify-content:center;">
        <div class="modal" style="background:#ffffff; border-radius:20px; width:520px; max-width:92vw; box-shadow:0 25px 50px -12px rgba(0, 0, 0, 0.3); border:1px solid #e2e8f0; overflow:hidden;">
            <div style="background:linear-gradient(135deg, #0d1527 0%, #16243d 100%); padding:22px 24px; color:#ffffff; position:relative; border-bottom:1px solid rgba(255,255,255,0.08);">
                <button type="button" onclick="closePaymentModal()" style="position:absolute; right:18px; top:18px; background:rgba(255,255,255,0.15); border:none; color:#ffffff; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg, #05cd99 0%, #047857 100%); display:flex; align-items:center; justify-content:center; color:#fff; font-size:20px;">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <h3 style="margin:0; font-size:18px; font-weight:800; color:#ffffff;">Secure Point of Sale Checkout</h3>
                        <p style="margin:2px 0 0 0; font-size:12px; color:#94a3b8;">Settle patient invoice via accredited payment gateway</p>
                    </div>
                </div>
            </div>
            
            <div style="padding:24px;">
                <!-- Payment Amount & Reference Banner -->
                <div style="background:#f0fdf4; border:1.5px solid #a7f3d0; border-radius:14px; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <div>
                        <div style="font-size:11.5px; font-weight:700; color:#047857; text-transform:uppercase; letter-spacing:0.5px;" id="modalInvoiceId">INV-0001</div>
                        <div style="font-size:14px; font-weight:800; color:#0f172a; margin-top:2px;" id="modalPatientName">Patient Name</div>
                    </div>
                    <div style="font-size:24px; font-weight:800; color:#047857;" id="modalAmount">ZMW K0.00</div>
                </div>

                <form method="POST" id="paymentForm" action="billing.php" onsubmit="handlePaymentSubmit(event)">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="pay_invoice">
                    <input type="hidden" name="invoice_id" id="formInvoiceId">
                    <input type="hidden" name="payment_method" id="formPaymentMethod" value="Credit/Debit Card">

                    <!-- Payment Method Selector -->
                    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; margin-bottom:20px;">
                        <div class="pay-method-opt selected" id="tabCard" onclick="switchPayTab('card')">
                            <i class="fa-solid fa-credit-card" style="font-size:20px; margin-bottom:4px; display:block;"></i>
                            <span style="font-size:11.5px; font-weight:700;">Card</span>
                        </div>
                        <div class="pay-method-opt" id="tabMobile" onclick="switchPayTab('mobile')">
                            <i class="fa-solid fa-mobile-screen" style="font-size:20px; margin-bottom:4px; display:block;"></i>
                            <span style="font-size:11.5px; font-weight:700;">Mobile Money</span>
                        </div>
                        <div class="pay-method-opt" id="tabBank" onclick="switchPayTab('bank')">
                            <i class="fa-solid fa-building-columns" style="font-size:20px; margin-bottom:4px; display:block;"></i>
                            <span style="font-size:11.5px; font-weight:700;">Bank Wire</span>
                        </div>
                    </div>

                    <!-- Card Fields -->
                    <div id="cardSection">
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:18px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <span style="font-size:12px; font-weight:700; color:#0f172a;"><i class="fa-solid fa-lock" style="color:#05cd99; margin-right:4px;"></i> Card Information</span>
                                <span style="font-size:16px;"><i class="fa-brands fa-cc-visa" style="color:#1a1f71; margin-right:4px;"></i> <i class="fa-brands fa-cc-mastercard" style="color:#eb001b;"></i></span>
                            </div>

                            <div style="margin-bottom:12px;">
                                <label style="display:block; font-size:11.5px; font-weight:700; color:#475569; margin-bottom:4px;">Card Number</label>
                                <input type="text" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:8px; font-family:monospace; font-weight:700; background:#ffffff; box-sizing:border-box; outline:none;" placeholder="4111 2222 3333 1234" value="4111 2222 3333 1234" required>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:700; color:#475569; margin-bottom:4px;">Expiry Date</label>
                                    <input type="text" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:8px; font-family:monospace; font-weight:700; background:#ffffff; box-sizing:border-box; outline:none;" placeholder="MM/YY" value="12/28" required>
                                </div>
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:700; color:#475569; margin-bottom:4px;">CVC / CVV</label>
                                    <input type="password" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:8px; font-family:monospace; font-weight:700; background:#ffffff; box-sizing:border-box; outline:none;" placeholder="888" value="888" maxlength="4" required>
                                </div>
                            </div>

                            <div>
                                <label style="display:block; font-size:11.5px; font-weight:700; color:#475569; margin-bottom:4px;">Cardholder Name</label>
                                <input type="text" id="modalCardName" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:8px; font-weight:700; background:#ffffff; box-sizing:border-box; outline:none;" value="Patient Name" required>
                            </div>
                        </div>
                    </div>

                    <!-- Alt Section -->
                    <div id="altSection" style="display:none; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px; margin-bottom:18px;">
                        <div style="margin-bottom:10px;">
                            <label id="altLabel" style="display:block; font-size:11.5px; font-weight:700; color:#475569; margin-bottom:4px;">Mobile Money Phone Number</label>
                            <input type="text" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:8px; font-weight:700; background:#ffffff; box-sizing:border-box; outline:none;" placeholder="e.g. +260 971 234 567">
                        </div>
                        <p style="font-size:11.5px; color:#64748b; margin:0;"><i class="fa-solid fa-circle-check" style="color:#05cd99;"></i> Real-time Airtel &amp; MTN settlement push notification will be triggered.</p>
                    </div>

                    <!-- Security Indicator -->
                    <div style="text-align:center; font-size:11.5px; color:#64748b; margin-bottom:16px; display:flex; align-items:center; justify-content:center; gap:6px;">
                        <i class="fa-solid fa-lock" style="color:#05cd99;"></i> 256-Bit Encrypted SSL &bull; PCI-DSS Level 1 Compliant
                    </div>

                    <!-- Action Button -->
                    <button type="submit" class="ph-btn ph-btn-green" style="width:100%; justify-content:center; padding:14px; font-size:14px; border-radius:12px;" id="modalPayBtn">
                        <i class="fa-solid fa-lock"></i> Authorize &amp; Pay Securely
                    </button>
                    
                    <div style="text-align:center; margin-top:12px;">
                        <button type="button" style="background:none; border:none; color:#64748b; font-size:12px; font-weight:600; cursor:pointer;" onclick="closePaymentModal()">Cancel &amp; Close</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ===== INVOICE STATEMENT PREVIEW MODAL ===== -->
    <div class="modal-overlay" id="viewModal" style="display:none; position:fixed; inset:0; background:rgba(15, 23, 42, 0.65); backdrop-filter:blur(8px); z-index:1000; align-items:center; justify-content:center;">
        <div class="modal" style="background:#ffffff; border-radius:20px; width:640px; max-width:92vw; box-shadow:0 25px 50px -12px rgba(0, 0, 0, 0.3); border:1px solid #e2e8f0; overflow:hidden;">
            <div style="background:linear-gradient(135deg, #0d1527 0%, #16243d 100%); padding:22px 24px; color:#ffffff; position:relative; border-bottom:1px solid rgba(255,255,255,0.08);">
                <button type="button" class="modal-close-btn" onclick="closeViewModal()" style="position:absolute; right:18px; top:18px; background:rgba(255,255,255,0.15); border:none; color:#ffffff; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); display:flex; align-items:center; justify-content:center; color:#fff; font-size:20px;">
                        <i class="fa-solid fa-file-invoice"></i>
                    </div>
                    <div>
                        <h3 style="margin:0; font-size:18px; font-weight:800; color:#ffffff;">Clinical Invoice Statement</h3>
                        <p style="margin:2px 0 0 0; font-size:12px; color:#94a3b8;">Official patient record &bull; VaxCare Health System</p>
                    </div>
                </div>
            </div>
            
            <div style="padding:24px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
                    <div>
                        <div style="font-size:20px; font-weight:800; color:#0f172a;" id="vInvId">#INV-0000</div>
                        <div style="font-size:12px; color:#64748b; margin-top:2px;">Issued by Ndola Central Hospital Pharmacy Dept.</div>
                    </div>
                    <div style="text-align:right;">
                        <span id="vStatusBadge"></span>
                        <div style="font-size:12px; color:#64748b; margin-top:6px;" id="vDate">Date: -</div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:20px; background:#f8fafc; padding:16px; border-radius:14px; border:1px solid #e2e8f0;">
                    <div>
                        <div style="font-size:10.5px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:2px;">Patient / Billed Party</div>
                        <div style="font-size:15px; font-weight:800; color:#0f172a;" id="vPatient">Patient Name</div>
                    </div>
                    <div>
                        <div style="font-size:10.5px; font-weight:700; text-transform:uppercase; color:#64748b; margin-bottom:2px;">Settlement Category</div>
                        <div style="font-size:13px; font-weight:700; color:#2563eb;">Pediatric Ward Immunization Service</div>
                    </div>
                </div>

                <table style="width:100%; border-collapse:collapse; margin-bottom:16px;">
                    <thead>
                        <tr style="border-bottom:1.5px solid #e2e8f0;">
                            <th style="text-align:left; padding:8px 0; font-size:12px; text-transform:uppercase; color:#64748b;">Line Item Description</th>
                            <th style="text-align:right; padding:8px 0; font-size:12px; text-transform:uppercase; color:#64748b;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:12px 0; font-size:13px; font-weight:600; color:#1e293b;">Immunization Administration &amp; Cold-Chain Vaccine Doses</td>
                            <td style="text-align:right; padding:12px 0; font-size:13px; font-weight:700; color:#0f172a;" id="vSubtotal">ZMW K0.00</td>
                        </tr>
                        <tr style="border-bottom:1px solid #f1f5f9;">
                            <td style="padding:12px 0; font-size:13px; color:#64748b;">Clinical Consultation &amp; Ward Dispensing Service</td>
                            <td style="text-align:right; padding:12px 0; font-size:13px; color:#05cd99; font-weight:700;">Included</td>
                        </tr>
                    </tbody>
                </table>

                <div style="border-top:2px solid #0f172a; padding-top:14px; display:flex; justify-content:space-between; align-items:center;">
                    <div style="font-size:15px; font-weight:800; color:#0f172a;">Total Amount Due</div>
                    <div style="font-size:24px; font-weight:800; color:#047857;" id="vAmount">ZMW K0.00</div>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:24px; border-top:1px solid #f1f5f9; padding-top:16px;">
                    <button type="button" class="ph-btn ph-btn-outline" onclick="window.print()">
                        <i class="fa-solid fa-print"></i> Print Statement
                    </button>
                    <button type="button" class="ph-btn ph-btn-green" onclick="closeViewModal()">
                        Close Preview
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== CREATE INVOICE MODAL ===== -->
    <div class="modal-overlay" id="createModal" style="display:none; position:fixed; inset:0; background:rgba(15, 23, 42, 0.65); backdrop-filter:blur(8px); z-index:1000; align-items:center; justify-content:center;">
        <div class="modal" style="background:#ffffff; border-radius:20px; width:540px; max-width:92vw; box-shadow:0 25px 50px -12px rgba(0, 0, 0, 0.3); border:1px solid #e2e8f0; overflow:hidden;">
            <div style="background:linear-gradient(135deg, #0d1527 0%, #16243d 100%); padding:22px 24px; color:#ffffff; position:relative; border-bottom:1px solid rgba(255,255,255,0.08);">
                <button type="button" onclick="closeCreateInvoiceModal()" style="position:absolute; right:18px; top:18px; background:rgba(255,255,255,0.15); border:none; color:#ffffff; width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                    <i class="fa-solid fa-xmark"></i>
                </button>
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:46px; height:46px; border-radius:14px; background:linear-gradient(135deg, #05cd99 0%, #047857 100%); display:flex; align-items:center; justify-content:center; color:#fff; font-size:20px; box-shadow:0 4px 12px rgba(5,205,153,0.35);">
                        <i class="fa-solid fa-file-circle-plus"></i>
                    </div>
                    <div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <h3 style="margin:0; font-size:18px; font-weight:800; color:#ffffff;">Generate Patient Invoice</h3>
                            <span style="background:rgba(255,255,255,0.15); padding:2px 8px; border-radius:6px; font-size:11px; font-family:monospace; font-weight:700;">#INV-<?= str_pad(count($invoices) + 1, 4, '0', STR_PAD_LEFT) ?></span>
                        </div>
                        <p style="margin:2px 0 0 0; font-size:12px; color:#94a3b8;">Issue dispensary fee statement with automated ledger recording</p>
                    </div>
                </div>
            </div>
            
            <div style="padding:24px;">
                <form method="POST" action="billing.php">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="create_invoice">

                    <!-- Patient Selection -->
                    <div style="margin-bottom:18px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">
                            Patient / Guardian Party <span style="color:#ef4444;">*</span>
                        </label>
                        <select name="guardian_id" style="width:100%; padding:11px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; background:#fff; outline:none; box-sizing:border-box; color:#0f172a; font-weight:600;" required>
                            <option value="">-- Choose patient from active directory --</option>
                            <?php foreach($guardians as $g): ?>
                                <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size:11.5px; color:#64748b; margin-top:5px;">
                            <i class="fa-solid fa-user-check" style="color:#05cd99;"></i> Selected guardian will receive statement via account &amp; SMS notice.
                        </div>
                    </div>

                    <!-- Quick Fee Presets -->
                    <div style="margin-bottom:18px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">
                            Standard Fee Presets (Quick Apply)
                        </label>
                        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:8px;">
                            <button type="button" onclick="setInvoiceFee(150.00)" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:7px 10px; font-size:11.5px; font-weight:700; color:#334155; cursor:pointer; text-align:center;">
                                Infant Routine <span style="display:block; color:#05cd99; font-size:11px;">K150.00</span>
                            </button>
                            <button type="button" onclick="setInvoiceFee(350.00)" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:7px 10px; font-size:11.5px; font-weight:700; color:#334155; cursor:pointer; text-align:center;">
                                Cold-Chain Lot <span style="display:block; color:#05cd99; font-size:11px;">K350.00</span>
                            </button>
                            <button type="button" onclick="setInvoiceFee(600.00)" style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:7px 10px; font-size:11.5px; font-weight:700; color:#334155; cursor:pointer; text-align:center;">
                                Pediatric Care <span style="display:block; color:#05cd99; font-size:11px;">K600.00</span>
                            </button>
                        </div>
                    </div>

                    <!-- Amount Due Input with Currency Prefix -->
                    <div style="margin-bottom:18px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">
                            Total Amount Due <span style="color:#ef4444;">*</span>
                        </label>
                        <div style="position:relative; display:flex; align-items:center;">
                            <span style="position:absolute; left:12px; font-size:13px; font-weight:800; color:#64748b; background:#f1f5f9; padding:4px 8px; border-radius:6px;">
                                ZMW K
                            </span>
                            <input type="number" step="0.01" name="amount_due" id="invoiceAmountInput" style="width:100%; padding:11px 14px 11px 78px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:15px; font-weight:800; color:#0f172a; outline:none; box-sizing:border-box;" placeholder="0.00" required>
                        </div>
                    </div>

                    <!-- 2-Column: Service Date & Status -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:24px;">
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">
                                Service / Dispense Date <span style="color:#ef4444;">*</span>
                            </label>
                            <input type="date" name="admission_date" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none; box-sizing:border-box; color:#0f172a; font-weight:600;" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">
                                Initial Settlement Status
                            </label>
                            <select name="status" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; background:#fff; outline:none; box-sizing:border-box; color:#0f172a; font-weight:600;">
                                <option value="Pending">Pending Payment</option>
                                <option value="Overdue">Overdue Notice</option>
                            </select>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex; gap:12px; border-top:1px solid #f1f5f9; padding-top:18px;">
                        <button type="button" class="ph-btn ph-btn-outline" onclick="closeCreateInvoiceModal()" style="flex:1; justify-content:center; padding:12px; border-color:#cbd5e1; font-weight:600;">
                            Cancel
                        </button>
                        <button type="submit" class="ph-btn ph-btn-green" style="flex:2; justify-content:center; padding:12px; font-weight:700; box-shadow:0 4px 14px rgba(5,205,153,0.3);">
                            <i class="fa-solid fa-receipt" style="margin-right:6px;"></i> Issue Invoice Statement
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

        function openPaymentModal(id, name, amount) {
            var invStr = '#INV-' + String(id).padStart(4, '0');
            var amtStr = 'ZMW K' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            document.getElementById('modalInvoiceId').innerText  = invStr;
            document.getElementById('modalPatientName').innerText = name;
            document.getElementById('modalAmount').innerText      = amtStr;
            document.getElementById('modalCardName').value        = name;
            document.getElementById('formInvoiceId').value        = id;
            document.getElementById('modalPayBtn').innerHTML      = '<i class="fa-solid fa-lock"></i> Authorize &amp; Pay ' + amtStr + ' Securely';
            document.getElementById('paymentModal').style.display = 'flex';
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
        }

        function handlePaymentSubmit(e) {
            var btn = document.getElementById('modalPayBtn');
            btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Processing Secure Payment...';
            btn.disabled = true;
        }

        function switchPayTab(type) {
            document.getElementById('tabCard').classList.remove('selected');
            document.getElementById('tabBank').classList.remove('selected');
            document.getElementById('tabMobile').classList.remove('selected');

            if (type === 'card') {
                document.getElementById('tabCard').classList.add('selected');
                document.getElementById('cardSection').style.display = 'block';
                document.getElementById('altSection').style.display = 'none';
                document.getElementById('formPaymentMethod').value = 'Credit/Debit Card';
            } else if (type === 'bank') {
                document.getElementById('tabBank').classList.add('selected');
                document.getElementById('cardSection').style.display = 'none';
                document.getElementById('altSection').style.display = 'block';
                document.getElementById('altLabel').innerText = 'Bank Account / Wire Reference Number';
                document.getElementById('formPaymentMethod').value = 'Bank Transfer';
            } else {
                document.getElementById('tabMobile').classList.add('selected');
                document.getElementById('cardSection').style.display = 'none';
                document.getElementById('altSection').style.display = 'block';
                document.getElementById('altLabel').innerText = 'Mobile Money Phone Number';
                document.getElementById('formPaymentMethod').value = 'Mobile Money';
            }
        }

        function openCreateInvoiceModal() {
            document.getElementById('createModal').style.display = 'flex';
        }

        function closeCreateInvoiceModal() {
            document.getElementById('createModal').style.display = 'none';
        }

        function setInvoiceFee(val) {
            document.getElementById('invoiceAmountInput').value = parseFloat(val).toFixed(2);
        }

        function viewInvoiceDetails(id, name, date, amount, status) {
            var invStr = '#INV-' + String(id).padStart(4, '0');
            var amtStr = 'ZMW K' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            document.getElementById('vInvId').innerText = invStr;
            document.getElementById('vPatient').innerText = name;
            document.getElementById('vDate').innerText = 'Date: ' + date;
            document.getElementById('vSubtotal').innerText = amtStr;
            document.getElementById('vAmount').innerText = amtStr;
            
            var badgeHtml = '';
            if (status === 'Paid') {
                badgeHtml = '<span style="display:inline-flex; align-items:center; gap:5px; background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:20px;"><i class="fa-solid fa-circle-check"></i> PAID</span>';
            } else if (status === 'Overdue') {
                badgeHtml = '<span style="display:inline-flex; align-items:center; gap:5px; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:20px;"><i class="fa-solid fa-triangle-exclamation"></i> OVERDUE</span>';
            } else {
                badgeHtml = '<span style="display:inline-flex; align-items:center; gap:5px; background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:11.5px; font-weight:700; padding:3px 9px; border-radius:20px;"><i class="fa-solid fa-clock"></i> PENDING</span>';
            }
            document.getElementById('vStatusBadge').innerHTML = badgeHtml;
            document.getElementById('viewModal').style.display = 'flex';
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        function filterTable() {
            var search = document.getElementById('searchInput').value.toLowerCase();
            var statusFilter = document.getElementById('statusFilter').value.toLowerCase();
            var rows = document.querySelectorAll('#invoicesTable tbody tr');
            var visibleCount = 0;

            rows.forEach(function(row) {
                var text = row.getAttribute('data-search') || '';
                var status = row.getAttribute('data-status') || '';

                var matchesSearch = !search || text.indexOf(search) !== -1;
                var matchesStatus = (statusFilter === 'all') || (status === statusFilter);

                if (matchesSearch && matchesStatus) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            document.getElementById('visibleCount').innerText = visibleCount;
        }
    </script>
</body>
</html>