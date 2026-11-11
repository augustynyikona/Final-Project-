<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: ../auth/login.php");
    exit;
}

$nurse_id = $_SESSION['user_id'];
$nurse_name = $_SESSION['name'] ?? 'Bupe';

// Fetch user profile from database
$stmt = $pdo->prepare("SELECT name, profile_pic FROM users WHERE id = ?");
$stmt->execute([$nurse_id]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user_profile && !empty($user_profile['name'])) {
    $nurse_name = $user_profile['name'];
}
$profile_pic = ($user_profile && $user_profile['profile_pic']) ? '../uploads/' . $user_profile['profile_pic'] : "https://ui-avatars.com/api/?name=" . urlencode($nurse_name) . "&background=3d72e7&color=fff";

$success = '';
$error = '';

// Handle Submit Requisition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_requisition') {
    csrf_guard();
    $shift_date = date('Y-m-d');
    
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO requisitions (nurse_id, shift_date, status) VALUES (?, ?, 'Pending')");
        $stmt->execute([$nurse_id, $shift_date]);
        $req_id = $pdo->lastInsertId();
        
        $items_inserted = 0;
        if (isset($_POST['req_qty']) && is_array($_POST['req_qty'])) {
            $stmt = $pdo->prepare("INSERT INTO requisition_items (requisition_id, vaccine_id, requested_quantity, notes) VALUES (?, ?, ?, ?)");
            foreach ($_POST['req_qty'] as $v_id => $qty) {
                $q = (int)$qty;
                if ($q > 0) {
                    $note = isset($_POST['out_of_stock_flag'][$v_id]) ? 'Urgent: Requested regardless of current stock status' : 'Routine shift requisition';
                    $stmt->execute([$req_id, (int)$v_id, $q, $note]);
                    $items_inserted++;
                }
            }
        }
        
        if ($items_inserted > 0) {
            $pdo->commit();
            $success = "Requisition #REQ-" . str_pad($req_id, 4, '0', STR_PAD_LEFT) . " successfully submitted to Central Pharmacy! Dispatch team notified.";
        } else {
            $pdo->rollBack();
            $error = "Please enter a requested quantity greater than zero for at least one vaccine.";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Failed to submit requisition: " . $e->getMessage();
    }
}

// Calculate Requirements & Stock Status (Showing all vaccines regardless of stock)
$stmt = $pdo->prepare("
    SELECT 
        v.id as vaccine_id, 
        v.name as vaccine_name,
        v.current_stock,
        v.low_stock_threshold,
        SUM(CASE WHEN a.appointment_date = CURRENT_DATE AND a.status IN ('scheduled', 'confirmed') THEN 1 ELSE 0 END) as scheduled_count
    FROM vaccines v
    LEFT JOIN appointments a ON a.vaccine_id = v.id
    GROUP BY v.id, v.name, v.current_stock, v.low_stock_threshold
    ORDER BY v.name ASC
");
$stmt->execute();
$requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate scheduled doses required today across all vaccines
$total_scheduled_today = 0;
$total_catalog_vaccines = count($requirements);
foreach ($requirements as $r) {
    $total_scheduled_today += (int)$r['scheduled_count'];
}

// Fetch Requisition History
$stmt = $pdo->prepare("
    SELECT r.*, 
        (SELECT SUM(ri.requested_quantity) FROM requisition_items ri WHERE ri.requisition_id = r.id) as total_doses,
        (SELECT COUNT(*) FROM requisition_items ri WHERE ri.requisition_id = r.id) as item_count
    FROM requisitions r 
    WHERE nurse_id = ? 
    ORDER BY created_at DESC LIMIT 15
");
$stmt->execute([$nurse_id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count pending & approved requisitions for this nurse
$pending_reqs_count = 0;
$approved_reqs_count = 0;
foreach ($history as $h) {
    if ($h['status'] === 'Pending') $pending_reqs_count++;
    if ($h['status'] === 'Approved' || $h['status'] === 'Fulfilled') $approved_reqs_count++;
}

// Fetch items for recent requisitions to power the breakdown modal
$req_ids = array_column($history, 'id');
$items_by_req = [];
if (!empty($req_ids)) {
    $in = implode(',', array_map('intval', $req_ids));
    $item_rows = $pdo->query("
        SELECT ri.*, v.name as vaccine_name 
        FROM requisition_items ri 
        JOIN vaccines v ON ri.vaccine_id = v.id 
        WHERE ri.requisition_id IN ($in)
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($item_rows as $ir) {
        $items_by_req[$ir['requisition_id']][] = $ir;
    }
}

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'nurse' OR recipient_role = 'all') AND is_read = 0");
$stmt->execute([$nurse_id]);
$unread_msg_count = (int)$stmt->fetchColumn();

// Today date string
$current_date = date('d M, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Requisitions &amp; Cold-Chain Restock — VaxiCare</title>
    <meta name="description" content="Pediatric immunization vaccine requisitions desk, shift stock replenishment, and delivery schedule tracking from Central Pharmacy.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/nurse_theme.css">
    <style>
        .requisition-grid {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 24px;
        }

        @media (max-width: 1024px) {
            .requisition-grid {
                grid-template-columns: 1fr;
            }
        }

        .req-qty-wrap {
            display: inline-flex;
            align-items: center;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            overflow: hidden;
            background: #ffffff;
            transition: all 0.2s;
        }

        .req-qty-wrap:focus-within {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .req-qty-btn {
            background: #f8fafc;
            border: none;
            padding: 8px 11px;
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s;
        }

        .req-qty-btn:hover {
            background: #eff6ff;
            color: #2563eb;
        }

        .req-qty-input {
            width: 54px;
            border: none;
            padding: 7px 4px;
            text-align: center;
            font-weight: 800;
            font-size: 14px;
            font-family: 'Space Grotesk', 'Inter', sans-serif;
            color: #0f172a;
            outline: none;
            background: #ffffff;
        }

        .search-mini-wrap {
            position: relative;
            max-width: 280px;
            width: 100%;
        }

        .search-mini-wrap input {
            width: 100%;
            padding: 8px 14px 8px 34px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 12.5px;
            background: #f8fafc;
            color: #0f172a;
            outline: none;
            transition: all 0.2s;
        }

        .search-mini-wrap input:focus {
            background: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .search-mini-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 12px;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11, 19, 43, 0.78);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            z-index: 999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: modalFadeIn 0.22s ease-out;
        }

        .modal-overlay.active {
            display: flex;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-box {
            background: #ffffff;
            border-radius: 26px;
            width: 100%;
            max-width: 600px;
            padding: 32px 36px;
            box-shadow: 0 30px 65px -15px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(226, 232, 240, 0.8);
            position: relative;
            max-height: 92vh;
            overflow-y: auto;
            border: 1px solid rgba(255, 255, 255, 0.9);
            animation: modalSlideUp 0.28s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalSlideUp {
            from { transform: translateY(22px) scale(0.98); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }

        .modal-close {
            position: absolute;
            top: 24px;
            right: 24px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .modal-close:hover {
            background: #fee2e2;
            color: #dc2626;
            border-color: #fca5a5;
            transform: rotate(90deg) scale(1.05);
        }
    </style>
</head>
<body class="app-body">
    
    <!-- Mobile Backdrop Overlay -->
    <div class="mobile-sidebar-backdrop" id="mobileSidebarBackdrop" onclick="toggleSidebar(false)"></div>

    <!-- ===== CANONICAL NURSE SIDEBAR ===== -->
    <aside class="sidebar" id="nurseSidebar">
        <div class="sidebar-brand-box">
            <a href="dashboard.php" class="sidebar-brand-link">
                <div class="sidebar-brand-icon"><i class="fa-solid fa-user-nurse"></i></div>
                <div class="sidebar-brand-info">
                    <span class="sidebar-brand-title">Vax<span>Care</span></span>
                    <span class="sidebar-brand-sub">Clinical Station</span>
                </div>
            </a>
        </div>
        
        <nav class="sidebar-menu">
            <div class="sidebar-section-title">Overview</div>
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-border-all"></i> Dashboard
            </a>

            <div class="sidebar-section-title">Pediatric Care</div>
            <a href="register_patient.php" class="sidebar-item">
                <i class="fa-solid fa-user-plus"></i> Register Patient
            </a>
            <a href="patients.php" class="sidebar-item">
                <i class="fa-solid fa-users"></i> Patient Directory
            </a>
            <a href="appointments.php" class="sidebar-item">
                <i class="fa-solid fa-calendar-check"></i> Appointments
            </a>
            <a href="triage.php" class="sidebar-item">
                <i class="fa-solid fa-heart-pulse"></i> Triage Vitals
            </a>

            <div class="sidebar-section-title">Supplies &amp; Comms</div>
            <a href="sms_reminders.php" class="sidebar-item">
                <i class="fa-solid fa-comment-sms"></i> SMS Reminders
            </a>
            <a href="requisition.php" class="sidebar-item active">
                <i class="fa-solid fa-boxes-stacked"></i> Requisitions
                <?php if($pending_reqs_count > 0): ?>
                    <span class="badge-red" style="background:#f59e0b;"><?= $pending_reqs_count ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Governance &amp; Profile</div>
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
        
        <div class="sidebar-logout">
            <a href="../auth/logout.php">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
            </a>
        </div>
    </aside>

    <!-- ===== MAIN CONTENT ===== -->
    <main class="app-main">
        
        <!-- ===== TOPBAR (WITH INLINE BREADCRUMBS & CENTERED DATE) ===== -->
        <header class="app-topbar">
            <div style="display:flex; align-items:center;">
                <button type="button" class="mobile-nav-toggle" onclick="toggleSidebar(true)" aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-left" style="display:flex; flex-direction:row; align-items:center; gap:14px; flex-wrap:wrap;">
                    <h1>Pharmacy Requisitions</h1>
                    <div class="topbar-breadcrumbs" style="display:flex; align-items:center; gap:6px; margin:0;">
                        <span>Clinical Overview</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span class="current">Vaccine Requisitions</span>
                    </div>
                </div>
            </div>

            <!-- Centered Date & Ward Context -->
            <div class="topbar-center" style="display:flex; align-items:center; justify-content:center;">
                <div class="date-range">
                    <i class="fa-regular fa-calendar"></i> <?= $current_date ?> &bull; Pediatrics &amp; Immunization Ward
                </div>
            </div>

            <!-- Right Controls -->
            <div class="topbar-right">
                <a href="#createRequisitionSection" class="nr-btn nr-btn-primary" style="padding:8px 18px; font-size:13px; text-decoration:none;">
                    <i class="fa-solid fa-plus"></i> New Requisition
                </a>
                <div class="topbar-control-pill">
                    <a href="messages.php" class="topbar-action-btn" title="Messages">
                        <i class="fa-regular fa-bell"></i>
                        <?php if($unread_msg_count > 0): ?>
                            <span class="notif-ping"></span>
                        <?php endif; ?>
                    </a>

                    <a href="settings.php" class="topbar-action-btn" title="Settings">
                        <i class="fa-solid fa-gear"></i>
                    </a>

                    <div class="topbar-divider"></div>

                    <div class="topbar-profile">
                        <div class="topbar-avatar-wrap">
                            <img src="<?= htmlspecialchars($profile_pic) ?>" alt="<?= htmlspecialchars($nurse_name) ?>">
                            <span class="avatar-status-dot"></span>
                        </div>
                        <div class="topbar-profile-info">
                            <span class="topbar-profile-name"><?= htmlspecialchars($nurse_name) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- System Response Feedback Banners -->
        <?php if($success): ?>
            <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:16px 20px; border-radius:14px; font-weight:700; margin-bottom:24px; display:flex; align-items:center; gap:12px; box-shadow:0 4px 12px rgba(16,185,129,0.08);">
                <i class="fa-solid fa-circle-check" style="font-size:18px; color:#10b981;"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>
        <?php if($error): ?>
            <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:16px 20px; border-radius:14px; font-weight:700; margin-bottom:24px; display:flex; align-items:center; gap:12px; box-shadow:0 4px 12px rgba(239,68,68,0.08);">
                <i class="fa-solid fa-triangle-exclamation" style="font-size:18px; color:#ef4444;"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- ===== ENLARGED TOP STATS GRID ===== -->
        <div class="stats-grid">
            <!-- Stat 1: Today Patient Demand -->
            <div class="stat-card card-blue">
                <div class="stat-icon icon-blue">
                    <i class="fa-solid fa-syringe"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Today Shift Doses Needed</div>
                    <div class="stat-value"><?= number_format($total_scheduled_today) ?></div>
                    <div class="stat-trend trend-up">
                        <i class="fa-solid fa-users"></i> Scheduled Patients Today
                    </div>
                </div>
            </div>

            <!-- Stat 2: Pending Orders -->
            <div class="stat-card card-amber">
                <div class="stat-icon icon-amber">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Pending Requisitions</div>
                    <div class="stat-value"><?= number_format($pending_reqs_count) ?></div>
                    <div class="stat-trend trend-neutral">
                        <i class="fa-solid fa-hourglass-half"></i> Awaiting Pharmacy
                    </div>
                </div>
            </div>

            <!-- Stat 3: Approved & Dispatched -->
            <div class="stat-card card-green">
                <div class="stat-icon icon-green">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Approved &amp; Ready</div>
                    <div class="stat-value"><?= number_format($approved_reqs_count) ?></div>
                    <div class="stat-trend trend-up">
                        <i class="fa-solid fa-boxes-packing"></i> Dispatched to Ward
                    </div>
                </div>
            </div>

            <!-- Stat 4: Catalog Types -->
            <div class="stat-card card-purple">
                <div class="stat-icon icon-purple">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Available Catalog Vaccines</div>
                    <div class="stat-value"><?= number_format($total_catalog_vaccines) ?></div>
                    <div class="stat-trend trend-neutral">
                        <i class="fa-solid fa-shield-virus"></i> Clinical Formulary
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== 2-COLUMN CLINICAL REQUISITION LAYOUT ===== -->
        <div class="requisition-grid" id="createRequisitionSection">
            
            <!-- LEFT COLUMN: Create Requisition Table Form -->
            <div class="mar-card" style="margin-bottom:0;">
                <div class="mar-card-header" style="flex-wrap:wrap; gap:12px;">
                    <div>
                        <h3 class="mar-card-title">
                            <i class="fa-solid fa-dolly" style="color:var(--nr-primary);"></i>
                            Create Shift Vaccine Requisition
                        </h3>
                        <p style="font-size:12px; color:var(--nr-text-muted); margin-top:2px;">
                            Request cold-chain vaccines &amp; biologics from Central Pharmacy
                        </p>
                    </div>

                    <div class="search-mini-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="vaccineFilterInput" placeholder="Filter vaccines..." oninput="filterVaccines(this.value)">
                    </div>
                </div>

                <!-- Info Notice Banner -->
                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:12px 16px; margin-bottom:18px; display:flex; align-items:center; gap:12px; font-size:12.5px; color:#1e40af;">
                    <i class="fa-solid fa-circle-info" style="font-size:16px; color:#2563eb; flex-shrink:0;"></i>
                    <span>Quantities are pre-filled based on today's appointment arrivals. Restock requests are accepted even for depleted stock to alert procurement.</span>
                </div>

                <form method="POST" action="" onsubmit="return validateRequisitionForm()">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="action" value="submit_requisition">

                    <div style="overflow-x:auto;">
                        <table class="mar-table" id="requisitionTable">
                            <thead>
                                <tr>
                                    <th>Vaccine Formulation</th>
                                    <th>Pharmacy Stock</th>
                                    <th style="text-align:right;">Request Doses</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($requirements)): ?>
                                    <tr>
                                        <td colspan="3" style="text-align:center; padding:36px; color:var(--nr-text-muted);">
                                            No vaccines registered in clinic inventory.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($requirements as $req): 
                                        $stock = (int)$req['current_stock'];
                                        $is_out = ($stock <= 0);
                                        $is_low = ($stock > 0 && $stock <= (int)$req['low_stock_threshold']);
                                        $default_val = max(0, (int)$req['scheduled_count']);
                                    ?>
                                        <tr class="vaccine-req-row" data-name="<?= htmlspecialchars(strtolower($req['vaccine_name'])) ?>">
                                            <td>
                                                <div style="display:flex; align-items:center; gap:10px;">
                                                    <div style="width:34px; height:34px; border-radius:10px; background:#eff6ff; color:#2563eb; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0;">
                                                        <i class="fa-solid fa-vial"></i>
                                                    </div>
                                                    <div>
                                                        <div style="font-weight:700; color:#0f172a; font-size:13.5px;">
                                                            <?= htmlspecialchars($req['vaccine_name']) ?>
                                                        </div>
                                                        <?php if($req['scheduled_count'] > 0): ?>
                                                            <div style="font-size:11.5px; color:#059669; font-weight:700; margin-top:2px;">
                                                                <i class="fa-solid fa-calendar-check"></i> <?= $req['scheduled_count'] ?> patients scheduled today
                                                            </div>
                                                        <?php else: ?>
                                                            <div style="font-size:11px; color:#94a3b8; margin-top:2px;">
                                                                No scheduled demand today
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if($is_out): ?>
                                                    <span class="badge-status bg-red-light" style="font-size:11px;">
                                                        <i class="fa-solid fa-triangle-exclamation"></i> Out of Stock (0)
                                                    </span>
                                                <?php elseif($is_low): ?>
                                                    <span class="badge-status bg-amber-light" style="font-size:11px;">
                                                        <i class="fa-solid fa-triangle-exclamation"></i> Low (<?= $stock ?>)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge-status bg-green-light" style="font-size:11px;">
                                                        <i class="fa-solid fa-box"></i> <?= $stock ?> in Cold-Chain
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:right;">
                                                <div style="display:inline-flex; align-items:center; gap:8px;">
                                                    <div class="req-qty-wrap">
                                                        <button type="button" class="req-qty-btn" onclick="stepQty(<?= $req['vaccine_id'] ?>, -1)">
                                                            <i class="fa-solid fa-minus"></i>
                                                        </button>
                                                        <input type="number" 
                                                               id="qty_input_<?= $req['vaccine_id'] ?>" 
                                                               name="req_qty[<?= $req['vaccine_id'] ?>]" 
                                                               value="<?= $default_val ?>" 
                                                               min="0" 
                                                               class="req-qty-input" 
                                                               oninput="calculateTotalRequested()">
                                                        <button type="button" class="req-qty-btn" onclick="stepQty(<?= $req['vaccine_id'] ?>, 1)">
                                                            <i class="fa-solid fa-plus"></i>
                                                        </button>
                                                    </div>
                                                    <?php if($is_out): ?>
                                                        <input type="hidden" name="out_of_stock_flag[<?= $req['vaccine_id'] ?>]" value="1">
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Footer Action Bar -->
                    <div style="display:flex; justify-content:space-between; align-items:center; padding-top:20px; border-top:1px solid #f1f5f9; margin-top:16px; flex-wrap:wrap; gap:14px;">
                        <div style="font-size:13px; color:#475569; font-weight:600;">
                            Total Requested: <strong id="totalRequestedBadge" style="font-size:16px; color:#2563eb; font-family:'Space Grotesk', sans-serif;">0</strong> doses
                        </div>
                        <button type="submit" class="nr-btn nr-btn-primary" style="padding:11px 26px; font-weight:800; font-size:13.5px; box-shadow:0 4px 14px rgba(37,99,235,0.35);">
                            <i class="fa-solid fa-paper-plane"></i> Submit Requisition to Pharmacy
                        </button>
                    </div>
                </form>
            </div>

            <!-- RIGHT COLUMN: Requisition History & Dispatch Schedule -->
            <div class="mar-card" style="margin-bottom:0;">
                <div class="mar-card-header">
                    <div>
                        <h3 class="mar-card-title">
                            <i class="fa-solid fa-clock-rotate-left" style="color:var(--nr-primary);"></i>
                            Recent Requisition Orders
                        </h3>
                        <p style="font-size:12px; color:var(--nr-text-muted); margin-top:2px;">
                            Delivery status, approvals, and pharmacy dispatch timeline
                        </p>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table class="mar-table">
                        <thead>
                            <tr>
                                <th>Shift Date</th>
                                <th>Doses</th>
                                <th>Status</th>
                                <th style="text-align:right;">Delivery Schedule</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($history)): ?>
                                <tr>
                                    <td colspan="4" style="text-align:center; padding:36px 16px; color:var(--nr-text-muted);">
                                        <div style="width:40px; height:40px; border-radius:50%; background:#f1f5f9; display:inline-flex; align-items:center; justify-content:center; margin-bottom:8px; color:#94a3b8; font-size:16px;">
                                            <i class="fa-solid fa-boxes-stacked"></i>
                                        </div>
                                        <div style="font-weight:700; color:#334155; font-size:13.5px;">No requisitions submitted yet</div>
                                        <div style="font-size:11.5px; color:#94a3b8; margin-top:3px;">Select vaccines on the left to place a shift request.</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($history as $h): ?>
                                    <?php 
                                        $reqId = $h['id'];
                                        $itemsJson = isset($items_by_req[$reqId]) ? json_encode($items_by_req[$reqId]) : '[]';
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight:700; color:#0f172a; font-size:13px;">
                                                <?= date('d M, Y', strtotime($h['shift_date'])) ?>
                                            </div>
                                            <div style="font-size:11px; color:#64748b; margin-top:2px;">
                                                #REQ-<?= str_pad($h['id'], 4, '0', STR_PAD_LEFT) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <button type="button" class="nr-btn nr-btn-sm nr-btn-outline" style="padding:3px 8px; font-size:11.5px;" onclick='openItemsModal(<?= $h['id'] ?>, <?= htmlspecialchars($itemsJson, ENT_QUOTES, 'UTF-8') ?>, "<?= htmlspecialchars($h['status']) ?>")'>
                                                <i class="fa-solid fa-syringe" style="color:#2563eb;"></i> 
                                                <strong><?= (int)($h['total_doses'] ?? 0) ?></strong> doses (<?= (int)($h['item_count'] ?? 0) ?> types)
                                            </button>
                                        </td>
                                        <td>
                                            <?php if($h['status'] === 'Approved'): ?>
                                                <span class="badge-status bg-green-light" style="font-size:11px;">
                                                    <i class="fa-solid fa-circle-check"></i> Approved
                                                </span>
                                            <?php elseif($h['status'] === 'Pending'): ?>
                                                <span class="badge-status bg-amber-light" style="font-size:11px;">
                                                    <i class="fa-solid fa-hourglass-half"></i> Pending
                                                </span>
                                            <?php elseif($h['status'] === 'Rejected'): ?>
                                                <span class="badge-status bg-red-light" style="font-size:11px;">
                                                    <i class="fa-solid fa-circle-xmark"></i> Rejected
                                                </span>
                                            <?php else: ?>
                                                <span class="badge-status bg-blue-light" style="font-size:11px;">
                                                    <?= htmlspecialchars($h['status']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <?php if($h['status'] === 'Approved' && !empty($h['dispatch_type'])): ?>
                                                <?php if($h['dispatch_type'] === 'Immediate'): ?>
                                                    <div style="display:inline-flex; flex-direction:column; align-items:flex-end; gap:2px;">
                                                        <span class="badge-status bg-green-light" style="font-size:10.5px; padding:2px 7px;">
                                                            <i class="fa-solid fa-bolt"></i> Immediate
                                                        </span>
                                                        <span style="font-size:11px; color:#64748b;">
                                                            <?= $h['dispatch_time'] ? date('h:i A', strtotime($h['dispatch_time'])) : 'Dispatched' ?>
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <div style="display:inline-flex; flex-direction:column; align-items:flex-end; gap:2px;">
                                                        <span class="badge-status bg-blue-light" style="font-size:10.5px; padding:2px 7px;">
                                                            <i class="fa-solid fa-calendar-day"></i> Scheduled
                                                        </span>
                                                        <span style="font-size:11px; color:#64748b;">
                                                            <?= $h['dispatch_time'] ? date('d M, h:i A', strtotime($h['dispatch_time'])) : 'Pending' ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            <?php elseif($h['status'] === 'Pending'): ?>
                                                <span style="font-size:11.5px; color:#94a3b8; font-weight:600;">
                                                    <i class="fa-solid fa-clock"></i> In Pharmacy Queue
                                                </span>
                                            <?php else: ?>
                                                <span style="font-size:11.5px; color:#94a3b8;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </main>

    <!-- ===== REQUISITION ITEMS DETAIL MODAL ===== -->
    <div class="modal-overlay" id="itemsDetailModal">
        <div class="modal-box">
            <button class="modal-close" onclick="closeItemsModal()"><i class="fa-solid fa-xmark"></i></button>
            
            <div style="display:flex; align-items:center; gap:14px; margin-bottom:18px;">
                <div style="width:46px; height:46px; border-radius:14px; background:linear-gradient(135deg, #2563eb, #3b82f6); color:#fff; display:flex; align-items:center; justify-content:center; font-size:20px; box-shadow:0 6px 16px rgba(37,99,235,0.3); flex-shrink:0;">
                    <i class="fa-solid fa-boxes-packing"></i>
                </div>
                <div>
                    <h3 style="font-size:19px; font-weight:800; color:#0f172a; margin:0;" id="modal_req_title">Requisition Breakdown</h3>
                    <p style="font-size:12.5px; color:#64748b; margin:2px 0 0 0;" id="modal_req_sub">Itemized vaccine quantities &amp; fulfillment status</p>
                </div>
            </div>

            <div style="overflow-x:auto;">
                <table class="mar-table">
                    <thead>
                        <tr>
                            <th>Vaccine</th>
                            <th>Requested</th>
                            <th>Fulfilled</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody id="modal_items_tbody">
                    </tbody>
                </table>
            </div>

            <div style="margin-top:20px; display:flex; justify-content:flex-end;">
                <button type="button" onclick="closeItemsModal()" class="nr-btn nr-btn-outline" style="padding:9px 20px;">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar(open) {
            const sidebar = document.getElementById('nurseSidebar');
            const backdrop = document.getElementById('mobileSidebarBackdrop');
            if (open) {
                sidebar.classList.add('open');
                backdrop.classList.add('show');
            } else {
                sidebar.classList.remove('open');
                backdrop.classList.remove('show');
            }
        }

        // Stepper for quantity input
        function stepQty(id, delta) {
            const inp = document.getElementById('qty_input_' + id);
            if (inp) {
                let val = parseInt(inp.value, 10) || 0;
                val += delta;
                if (val < 0) val = 0;
                inp.value = val;
                calculateTotalRequested();
            }
        }

        function calculateTotalRequested() {
            let total = 0;
            document.querySelectorAll('.req-qty-input').forEach(inp => {
                total += parseInt(inp.value, 10) || 0;
            });
            const badge = document.getElementById('totalRequestedBadge');
            if (badge) badge.innerText = total;
        }

        // Filter vaccines table live
        function filterVaccines(query) {
            const q = query.toLowerCase().trim();
            document.querySelectorAll('.vaccine-req-row').forEach(row => {
                const name = row.getAttribute('data-name') || '';
                if (q === '' || name.includes(q)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function validateRequisitionForm() {
            let total = 0;
            document.querySelectorAll('.req-qty-input').forEach(inp => {
                total += parseInt(inp.value, 10) || 0;
            });
            if (total <= 0) {
                alert('Please enter at least 1 dose for any vaccine before submitting.');
                return false;
            }
            return confirm('Submit requisition for ' + total + ' total doses to Central Pharmacy?');
        }

        // Requisition Items Breakdown Modal
        function openItemsModal(reqId, items, status) {
            document.getElementById('modal_req_title').innerText = 'Requisition #REQ-' + String(reqId).padStart(4, '0');
            document.getElementById('modal_req_sub').innerText = 'Order status: ' + status;
            
            const tbody = document.getElementById('modal_items_tbody');
            tbody.innerHTML = '';

            if (!items || items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:#94a3b8;">No itemized data available.</td></tr>';
            } else {
                items.forEach(it => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="font-weight:700; color:#0f172a;">${it.vaccine_name}</td>
                        <td><span class="badge-status bg-blue-light" style="font-size:11px;">${it.requested_quantity} doses</span></td>
                        <td><span class="badge-status ${parseInt(it.fulfilled_quantity) > 0 ? 'bg-green-light' : 'bg-amber-light'}" style="font-size:11px;">${it.fulfilled_quantity || 0} doses</span></td>
                        <td style="font-size:12px; color:#64748b;">${it.notes || 'Routine requisition'}</td>
                    `;
                    tbody.appendChild(tr);
                });
            }

            document.getElementById('itemsDetailModal').classList.add('active');
        }

        function closeItemsModal() {
            document.getElementById('itemsDetailModal').classList.remove('active');
        }

        // Initialize total requested on page load
        document.addEventListener('DOMContentLoaded', function() {
            calculateTotalRequested();
        });

        // Close modal on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeItemsModal();
            }
        });
    </script>
    <script src="../js/notifications.js"></script>
</body>
</html>
