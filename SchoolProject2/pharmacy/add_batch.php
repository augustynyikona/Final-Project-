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

// Handle updating stock (Batch insertion)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_batch') {
    csrf_guard();
    $vaccine_id   = $_POST['vaccine_id'] ?? '';
    $batch_number = trim($_POST['batch_number'] ?? '');
    $quantity     = (int)($_POST['quantity'] ?? 0);
    $expiry_date  = $_POST['expiry_date'] ?? '';
    $supplier_id  = $_POST['supplier_id'] ?? '';

    $quantity = (int)$quantity;


    $today = new DateTime('today');


    $duplicate = false;


    if ((int)$vaccine_id && $batch_number !== '') {


        $dup = $pdo->prepare("SELECT COUNT(*) FROM vaccine_batches WHERE vaccine_id = ? AND batch_number = ?");


        $dup->execute([(int)$vaccine_id, $batch_number]);


        $duplicate = $dup->fetchColumn() > 0;


    }


    if (empty($vaccine_id) || empty($batch_number) || empty($quantity) || empty($expiry_date) || empty($supplier_id)) {
        $error = "All batch details and supplier selections are required.";
    } elseif ($quantity < 1) {
        $error = "Quantity must be a whole number greater than zero.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date) || !strtotime($expiry_date)) {
        $error = "Expiry date must be a valid date in YYYY-MM-DD format.";
    } elseif (new DateTime($expiry_date) < $today) {
        $error = "Expiry date cannot be in the past — the batch would enter inventory already expired.";
    } elseif ($duplicate) {
        $error = "Batch number '" . htmlspecialchars($batch_number) . "' already exists for this vaccine. Use a unique batch number.";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO vaccine_batches (vaccine_id, batch_number, quantity, expiry_date, supplier_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$vaccine_id, $batch_number, $quantity, $expiry_date, $supplier_id]);

            $stmt = $pdo->prepare("UPDATE vaccines SET current_stock = current_stock + ? WHERE id = ?");
            $stmt->execute([$quantity, $vaccine_id]);

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
                $msg = "Vaccine " . $nurse['name'] . " has been restocked. You can now fulfill your pending requisition.";
                $ins_msg = $pdo->prepare("INSERT INTO messages (sender_id, recipient_role, receiver_id, subject, body) VALUES (?, 'nurse', ?, 'Vaccine Restocked', ?)");
                $ins_msg->execute([$_SESSION['user_id'], $nurse['nurse_id'], $msg]);
            }

            $pdo->commit();
            $success = "Medication stock updated successfully. Batch #{$batch_number} added to cold-chain inventory.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Database error while adding batch: " . $e->getMessage();
        }
    }
}

// Fetch dropdown data. Annotate vaccines that are out of stock but requested.
$vaccines = $pdo->query("
    SELECT v.id, v.name, v.current_stock, 
           (SELECT COUNT(*) FROM requisition_items ri 
            JOIN requisitions r ON ri.requisition_id = r.id 
            WHERE ri.vaccine_id = v.id AND r.status = 'Pending') as pending_requests
    FROM vaccines v 
    ORDER BY v.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$suppliers = $pdo->query("SELECT id, name FROM suppliers WHERE status='Active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Unread message count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'pharmacy' OR recipient_role = 'pharmacist') AND is_read = 0");
$stmt->execute([$_SESSION['user_id']]);
$unread_msg_count = $stmt->fetchColumn();

// Pending counts for sidebar badges
$pending_orders       = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending'")->fetchColumn();
$pending_requisitions = $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'Pending'")->fetchColumn();

// Low stock count
$low_stock_count = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= 20")->fetchColumn();

// Fetch recent batches for audit view
$recent_batches = $pdo->query("
    SELECT vb.*, v.name as vaccine_name, s.name as supplier_name 
    FROM vaccine_batches vb
    JOIN vaccines v ON vb.vaccine_id = v.id
    LEFT JOIN suppliers s ON vb.supplier_id = s.id
    ORDER BY vb.created_at DESC 
    LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intake Medication Batch — VaxCare Pharmacy</title>
    <meta name="description" content="Register incoming medication shipments, log batch lots, and update cold-chain levels in VaxCare.">
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

        /* Layout Grid */
        .intake-layout-grid {
            display: grid;
            grid-template-columns: 1.55fr 1fr;
            gap: 26px;
            align-items: start;
        }
        @media (max-width: 1150px) {
            .intake-layout-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Form Card */
        .intake-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }
        .intake-card-header {
            padding: 22px 26px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(180deg, #ffffff 0%, #fafbfc 100%);
        }
        .intake-card-title {
            font-size: 17px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .intake-card-sub {
            font-size: 13px;
            color: #64748b;
            margin-top: 3px;
        }
        .intake-card-body {
            padding: 26px;
        }

        /* Form Controls */
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-full {
            grid-column: span 2;
        }
        .form-group label {
            display: block;
            font-size: 12.5px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 7px;
        }
        .form-group label span.req {
            color: #ef4444;
            margin-left: 2px;
        }
        .form-input-wrap {
            position: relative;
        }
        .form-control-input, .form-control-select {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 11px;
            font-size: 13.5px;
            outline: none;
            background: #ffffff;
            color: #0f172a;
            font-family: inherit;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }
        .form-control-input:focus, .form-control-select:focus {
            border-color: #05cd99;
            box-shadow: 0 0 0 3px rgba(5, 205, 153, 0.15);
        }
        .form-control-input.code-font {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 600;
            letter-spacing: 0.04em;
        }
        .form-hint {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Side Cards */
        .side-panel-stack {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .qa-card {
            background: linear-gradient(145deg, #111c44 0%, #1a2956 100%);
            border-radius: 18px;
            padding: 24px;
            color: #ffffff;
            box-shadow: 0 4px 16px rgba(17, 28, 68, 0.15);
            border: 1px solid rgba(255,255,255,0.08);
        }
        .qa-title {
            font-size: 15px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ffffff;
            margin-bottom: 6px;
        }
        .qa-sub {
            font-size: 12.5px;
            color: #8c9eb9;
            margin-bottom: 18px;
            line-height: 1.4;
        }
        .qa-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .qa-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 10px 12px;
            background: rgba(255,255,255,0.05);
            border-radius: 11px;
            border: 1px solid rgba(255,255,255,0.07);
        }
        .qa-icon {
            color: #05cd99;
            font-size: 15px;
            margin-top: 2px;
        }
        .qa-text-head {
            font-size: 13px;
            font-weight: 700;
            color: #f1f5f9;
        }
        .qa-text-desc {
            font-size: 11.5px;
            color: #8c9eb9;
            margin-top: 2px;
        }

        /* Recent batches card */
        .recent-box {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }
        .recent-head {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .recent-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .recent-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        .recent-batch-lot {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            font-weight: 700;
            background: #f1f5f9;
            color: #0f172a;
            padding: 3px 8px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
        }
        .recent-name {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }
        .recent-meta {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 2px;
        }
        .recent-qty {
            font-size: 13px;
            font-weight: 700;
            color: #047857;
            background: #ecfdf5;
            padding: 4px 10px;
            border-radius: 8px;
            border: 1px solid #a7f3d0;
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
            <a href="add_batch.php" class="sidebar-item active">
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
                    <h1 style="margin:0 0 6px 0;">Intake Medication Batch</h1>
                    <div class="topbar-breadcrumbs" style="margin-bottom:6px;">
                        <span>Cold Chain &amp; Stock</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span style="color:#0f172a; font-weight:700;">Intake Batch</span>
                    </div>
                    <div class="date-range" style="margin-top:0;">
                        <i class="fa-solid fa-snowflake" style="color:#05cd99;"></i> <?= date('d M, Y') ?> &bull; Ndola Central Hospital &bull; Cold-Chain Station
                    </div>
                </div>
            </div>

            <div class="topbar-right">
                <a href="inventory.php" class="ph-btn ph-btn-outline" style="text-decoration:none;">
                    <i class="fa-solid fa-arrow-left"></i> View Stock Inventory
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
                    <div style="font-weight:700;">Submission Encountered an Issue</div>
                    <div style="font-size:12.5px; font-weight:500; opacity:0.95;"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div style="background:#ecfdf5; border:1.5px solid #a7f3d0; color:#065f46; padding:16px 20px; border-radius:14px; margin-bottom:24px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 6px rgba(16,185,129,0.06);">
                <i class="fa-solid fa-circle-check" style="font-size:18px; color:#05cd99;"></i>
                <div>
                    <div style="font-weight:700;">Stock Intake Successful</div>
                    <div style="font-size:12.5px; font-weight:500; opacity:0.95;"><?= htmlspecialchars($success) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Two Column Main Workarea -->
        <div class="intake-layout-grid">
            
            <!-- Left Column: Registration Form -->
            <div class="intake-card">
                <div class="intake-card-header">
                    <div>
                        <h2 class="intake-card-title">
                            <i class="fa-solid fa-boxes-packing" style="color:#05cd99;"></i>
                            Batch Intake Registration
                        </h2>
                        <div class="intake-card-sub">Log incoming medication batches directly into real-time hospital cold-chain storage</div>
                    </div>
                    <span style="display:inline-flex; align-items:center; gap:6px; background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; font-size:12px; font-weight:700; padding:5px 12px; border-radius:20px;">
                        <i class="fa-solid fa-shield-halved"></i> Audit Verified
                    </span>
                </div>

                <div class="intake-card-body">
                    <form method="POST" action="add_batch.php" id="intakeBatchForm">
                        <input type="hidden" name="action" value="add_batch">
  <?= csrf_field() ?>

                        <div class="form-grid-2">
                            <!-- Vaccine Selection -->
                            <div class="form-group form-full">
                                <label for="vaccineSelect">Target Medication / Vaccine Name <span class="req">*</span></label>
                                <select name="vaccine_id" id="vaccineSelect" class="form-control-select" required onchange="updateStockMeta(this)">
                                    <option value="">-- Choose medication from formulary --</option>
                                    <?php foreach($vaccines as $v): 
                                        $is_requested = ($v['pending_requests'] > 0 && $v['current_stock'] <= 0);
                                        $requested_flag = $is_requested ? ' [PRIORITY: Requested by Clinical Nurse]' : '';
                                    ?>
                                        <option value="<?= $v['id'] ?>" data-stock="<?= (int)$v['current_stock'] ?>" data-req="<?= (int)$v['pending_requests'] ?>">
                                            <?= htmlspecialchars($v['name']) ?> (Current: <?= number_format($v['current_stock']) ?> doses)<?= $requested_flag ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint" id="stockNoticeText">
                                    <i class="fa-solid fa-info-circle"></i> Select a vaccine to view its current live storage level.
                                </div>
                            </div>

                            <!-- Batch Number -->
                            <div class="form-group">
                                <label for="batchNumberInput">
                                    Lot / Batch Number <span class="req">*</span>
                                </label>
                                <div class="form-input-wrap">
                                    <input type="text" name="batch_number" id="batchNumberInput" class="form-control-input code-font" placeholder="e.g. LOT-2026-BCG4" required>
                                </div>
                                <div class="form-hint" style="display:flex; justify-content:space-between; align-items:center;">
                                    <span><i class="fa-solid fa-barcode"></i> As printed on vial package</span>
                                    <button type="button" onclick="generateLotSample()" style="background:none; border:none; color:#05cd99; font-size:11.5px; font-weight:700; cursor:pointer; text-decoration:underline;">Generate Suggestion</button>
                                </div>
                            </div>

                            <!-- Quantity Received -->
                            <div class="form-group">
                                <label for="quantityInput">Quantity Received (Doses / Vials) <span class="req">*</span></label>
                                <input type="number" name="quantity" id="quantityInput" class="form-control-input" placeholder="e.g. 500" min="1" required>
                                <div class="form-hint">
                                    <i class="fa-solid fa-cubes"></i> Total physical count being checked into cold storage.
                                </div>
                            </div>

                            <!-- Expiry Date -->
                            <div class="form-group">
                                <label for="expiryDateInput">Expiration Date <span class="req">*</span></label>
                                <input type="date" name="expiry_date" id="expiryDateInput" class="form-control-input" min="<?= date('Y-m-d') ?>" required onchange="checkExpiry(this.value)">
                                <div class="form-hint" id="expiryNotice">
                                    <i class="fa-regular fa-calendar-check"></i> Standard minimum shelf-life policy: &gt; 90 days.
                                </div>
                            </div>

                            <!-- Supplier -->
                            <div class="form-group">
                                <label for="supplierSelect">Accredited Supplier / Source <span class="req">*</span></label>
                                <select name="supplier_id" id="supplierSelect" class="form-control-select" required>
                                    <option value="">-- Select licensed distributor --</option>
                                    <?php foreach($suppliers as $s): ?>
                                        <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">
                                    <i class="fa-solid fa-truck-medical"></i> Ministry of Health or approved procurement partner.
                                </div>
                            </div>
                        </div>

                        <!-- Submit Bar -->
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:32px; padding-top:20px; border-top:1px solid #f1f5f9;">
                            <a href="inventory.php" class="ph-btn ph-btn-outline" style="text-decoration:none;">
                                <i class="fa-solid fa-xmark"></i> Cancel &amp; Back
                            </a>
                            <div style="display:flex; gap:12px;">
                                <button type="reset" class="ph-btn ph-btn-outline" style="color:#64748b;">
                                    <i class="fa-solid fa-rotate-left"></i> Reset Form
                                </button>
                                <button type="submit" class="ph-btn ph-btn-green" style="padding:10px 24px; font-size:14px;">
                                    <i class="fa-solid fa-check-double"></i> Verify &amp; Register Batch
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Column: Standards & Recent Batches -->
            <div class="side-panel-stack">
                
                <!-- Cold Chain QA Checklist Card -->
                <div class="qa-card">
                    <div class="qa-title">
                        <i class="fa-solid fa-snowflake" style="color:#05cd99;"></i>
                        Cold-Chain Quality Protocol
                    </div>
                    <div class="qa-sub">
                        Before finalizing this intake, ensure standard World Health Organization vaccine preservation checklist is verified:
                    </div>
                    
                    <div class="qa-list">
                        <div class="qa-item">
                            <div class="qa-icon"><i class="fa-solid fa-circle-check"></i></div>
                            <div>
                                <div class="qa-text-head">Vaccine Vial Monitor (VVM) Stage</div>
                                <div class="qa-text-desc">Square lighter than circle (Stage 1 or 2). Discard if square color matches or is darker than circle.</div>
                            </div>
                        </div>

                        <div class="qa-item">
                            <div class="qa-icon"><i class="fa-solid fa-circle-check"></i></div>
                            <div>
                                <div class="qa-text-head">Temperature Logger Integrity</div>
                                <div class="qa-text-desc">Confirm electronic transit datalogger was between +2°C and +8°C throughout shipment.</div>
                            </div>
                        </div>

                        <div class="qa-item">
                            <div class="qa-icon"><i class="fa-solid fa-circle-check"></i></div>
                            <div>
                                <div class="qa-text-head">Packaging &amp; Tamper Seals</div>
                                <div class="qa-text-desc">Ensure primary seals and carton seals are uncompromised with legible lot numbers.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Inflow Feed -->
                <div class="recent-box">
                    <div class="recent-head">
                        <span><i class="fa-solid fa-clock-rotate-left" style="color:#05cd99; margin-right:8px;"></i> Recent Stock Inflows</span>
                        <a href="inventory.php" style="font-size:12px; color:#05cd99; text-decoration:none; font-weight:700;">View All</a>
                    </div>

                    <?php if(empty($recent_batches)): ?>
                        <div style="text-align:center; padding:24px 10px; color:#94a3b8; font-size:13px;">
                            <i class="fa-solid fa-box-open" style="font-size:28px; margin-bottom:8px; opacity:0.5;"></i>
                            <div>No recent batches recorded yet.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach($recent_batches as $rb): ?>
                            <div class="recent-item">
                                <div>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <span class="recent-batch-lot"><?= htmlspecialchars($rb['batch_number']) ?></span>
                                        <span class="recent-name"><?= htmlspecialchars($rb['vaccine_name']) ?></span>
                                    </div>
                                    <div class="recent-meta">
                                        Supplier: <?= htmlspecialchars($rb['supplier_name'] ?? 'Direct Depot') ?> &bull; Exp: <?= date('M Y', strtotime($rb['expiry_date'])) ?>
                                    </div>
                                </div>
                                <div class="recent-qty">
                                    +<?= number_format($rb['quantity']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>

        </div>

    </main>

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

        function updateStockMeta(select) {
            const opt = select.options[select.selectedIndex];
            const notice = document.getElementById('stockNoticeText');
            if (!select.value) {
                notice.innerHTML = '<i class="fa-solid fa-info-circle"></i> Select a vaccine to view its current live storage level.';
                notice.style.color = '#64748b';
                return;
            }
            const stock = parseInt(opt.getAttribute('data-stock') || 0);
            const reqs = parseInt(opt.getAttribute('data-req') || 0);
            
            if (reqs > 0 && stock <= 0) {
                notice.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;"></i> <strong style="color:#b91c1c;">Priority Request:</strong> ' + reqs + ' nurse requisition(s) pending for this medication!';
                notice.style.color = '#b91c1c';
            } else {
                notice.innerHTML = '<i class="fa-solid fa-cubes-stacked" style="color:#05cd99;"></i> Current in stock: <strong>' + stock.toLocaleString() + ' doses</strong> across cold storage.';
                notice.style.color = '#047857';
            }
        }

        function generateLotSample() {
            const select = document.getElementById('vaccineSelect');
            const prefix = select.value ? 'LOT' + select.value.padStart(2, '0') : 'LOT';
            const year = new Date().getFullYear();
            const random = Math.floor(1000 + Math.random() * 9000);
            const generated = prefix + '-' + year + '-X' + random;
            document.getElementById('batchNumberInput').value = generated;
        }

        function checkExpiry(val) {
            if (!val) return;
            const expDate = new Date(val);
            const today = new Date();
            const diffDays = Math.ceil((expDate - today) / (1000 * 60 * 60 * 24));
            const notice = document.getElementById('expiryNotice');
            
            if (diffDays < 60) {
                notice.innerHTML = '<i class="fa-solid fa-triangle-exclamation" style="color:#ea580c;"></i> <strong style="color:#c2410c;">Short Expiry Warning:</strong> Only ' + diffDays + ' days until expiry. Requires accelerated rotation!';
                notice.style.color = '#c2410c';
            } else {
                notice.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#05cd99;"></i> Valid shelf life (' + diffDays + ' days remaining from today).';
                notice.style.color = '#047857';
            }
        }
    </script>
</body>
</html>
