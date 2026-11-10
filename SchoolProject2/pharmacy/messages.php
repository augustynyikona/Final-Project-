<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'pharmacy') {
    header("Location: ../auth/login.php");
    exit;
}

$pharmacist_id   = $_SESSION['user_id'];
$pharmacist_name = $_SESSION['name'] ?? 'Pharmacist';
$success         = '';
$error           = '';

// Handle Send Message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $send_mode      = $_POST['send_mode'] ?? 'direct';
    $subject        = trim($_POST['subject'] ?? '');
    $body           = trim($_POST['body'] ?? '');
    
    if ($send_mode === 'direct') {
        $recipient_role = 'direct';
        $receiver_id    = isset($_POST['receiver_id']) && !empty($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : NULL;
    } else {
        $recipient_role = $_POST['recipient_role'] ?? 'nurse';
        $receiver_id    = NULL;
    }

    if (empty($subject) || empty($body)) {
        $error = "Subject and message body are required.";
    } elseif ($send_mode === 'direct' && empty($receiver_id)) {
        $error = "Please select a specific recipient user for direct messaging.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, recipient_role, receiver_id, subject, body, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($stmt->execute([$pharmacist_id, $recipient_role, $receiver_id, $subject, $body])) {
            $success = "Message sent successfully!";
        } else {
            $error = "Failed to send message.";
        }
    }
}

// Mark as read
if (isset($_GET['read_id'])) {
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND (receiver_id = ? OR recipient_role = 'pharmacist' OR recipient_role = 'pharmacy')");
    $stmt->execute([$_GET['read_id'], $pharmacist_id]);
    header("Location: messages.php");
    exit;
}

// Fetch Inbox
$stmt = $pdo->prepare("
    SELECT m.*, u.name as sender_name, u.role as sender_role
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.receiver_id = ? OR m.recipient_role = 'pharmacy' OR m.recipient_role = 'pharmacist' OR m.recipient_role = 'all'
    ORDER BY m.created_at DESC
");
$stmt->execute([$pharmacist_id]);
$inbox = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Sent
$stmt = $pdo->prepare("
    SELECT m.*, u.name as receiver_name
    FROM messages m
    LEFT JOIN users u ON m.receiver_id = u.id
    WHERE m.sender_id = ?
    ORDER BY m.created_at DESC
");
$stmt->execute([$pharmacist_id]);
$sent = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recipients list for direct messaging
$all_users = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('doctor', 'nurse', 'guardian', 'admin') ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$unread_count = count(array_filter($inbox, fn($m) => !$m['is_read']));

// Low stock and pending counts
$low_stock_count      = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= low_stock_threshold")->fetchColumn();
$pending_requisitions = $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'Pending'")->fetchColumn();
$pending_orders       = $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending'")->fetchColumn();
$unread_msg_count     = $unread_count;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Communications &amp; Dispatches — VaxCare</title>
    <meta name="description" content="Official clinical communications between pharmacy staff, nursing units, physicians, and guardians.">
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

        /* 2-Column Messages Grid */
        .msg-layout-grid {
            display: grid;
            grid-template-columns: 1.6fr 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 1080px) {
            .msg-layout-grid {
                grid-template-columns: 1fr;
            }
        }

        .content-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
            overflow: hidden;
            padding: 24px;
        }

        /* Tabs */
        .msg-tab-nav {
            display: flex;
            gap: 10px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        .msg-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: 1.5px solid transparent;
            transition: all 0.2s ease;
        }
        .msg-tab-btn.active {
            background: #ecfdf5;
            color: #047857;
            border-color: #a7f3d0;
        }
        .msg-tab-btn.inactive {
            background: #f8fafc;
            color: #64748b;
            border-color: #e2e8f0;
        }
        .msg-tab-btn.inactive:hover {
            color: #0f172a;
            background: #f1f5f9;
        }

        /* Message Card Item */
        .msg-bubble-card {
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 16px;
            transition: all 0.2s ease;
        }
        .msg-bubble-card.unread {
            background: #f0fdf4;
            border: 1.5px solid #a7f3d0;
            box-shadow: 0 2px 6px rgba(5,205,153,0.06);
        }
        .msg-bubble-card.read {
            background: #ffffff;
            border: 1px solid #e2e8f0;
        }
        .msg-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
            gap: 12px;
        }
        .msg-sender-meta {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .msg-sender-meta img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
        }
        .msg-body-box {
            font-size: 13.5px;
            color: #334155;
            line-height: 1.55;
            background: #fafbfc;
            padding: 14px;
            border-radius: 10px;
            border: 1px solid #f1f5f9;
            margin-top: 10px;
        }

        /* Role Pill */
        .role-pill {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 2px 8px;
            border-radius: 20px;
            letter-spacing: 0.04em;
        }
        .role-pill-nurse    { background: #eff6ff; color: #2563eb; }
        .role-pill-doctor   { background: #fdf2f8; color: #db2777; }
        .role-pill-admin    { background: #fef3c7; color: #b45309; }
        .role-pill-guardian { background: #f3e8ff; color: #7c3aed; }

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
            <a href="reports.php" class="sidebar-item">
                <i class="fa-solid fa-chart-pie"></i> Stock Analytics
            </a>
            <a href="messages.php" class="sidebar-item active">
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
                    <h1 style="margin:0 0 6px 0;">Pharmacy Communications &amp; Stock Notices</h1>
                    <div class="topbar-breadcrumbs" style="margin-bottom:6px;">
                        <span>Governance &amp; Comms</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span style="color:#0f172a; font-weight:700;">Messages</span>
                    </div>
                    <div class="date-range" style="margin-top:0;">
                        <i class="fa-solid fa-snowflake" style="color:#05cd99;"></i> <?= date('d M, Y') ?> &bull; Ndola Central Hospital &bull; Clinical Messaging Switchboard
                    </div>
                </div>
            </div>

            <div class="topbar-right">
                <a href="inventory.php" class="ph-btn ph-btn-outline" style="padding: 6px 12px; font-size: 12px; border-radius: 8px; gap: 5px; height: 34px; text-decoration:none;">
                    <i class="fa-solid fa-boxes-stacked"></i> Stock Status
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
                    <div style="font-weight:700;">Communication Error</div>
                    <div style="font-size:12.5px; font-weight:500; opacity:0.95;"><?= htmlspecialchars($error) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div style="background:#ecfdf5; border:1.5px solid #a7f3d0; color:#065f46; padding:16px 20px; border-radius:14px; margin-bottom:24px; font-weight:600; font-size:13.5px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 6px rgba(16,185,129,0.06);">
                <i class="fa-solid fa-circle-check" style="font-size:18px; color:#05cd99;"></i>
                <div>
                    <div style="font-weight:700;">Message Dispatched</div>
                    <div style="font-size:12.5px; font-weight:500; opacity:0.95;"><?= htmlspecialchars($success) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Messages 2-Column Workarea -->
        <div class="msg-layout-grid">

            <!-- Left: Messages Thread Box -->
            <div class="content-card">
                <div class="msg-tab-nav">
                    <button type="button" class="msg-tab-btn active" id="tabBtnInbox" onclick="showTab('inbox')">
                        <i class="fa-solid fa-inbox"></i> Inbox
                        <?php if($unread_count > 0): ?>
                            <span style="background:#ef4444; color:#fff; font-size:11px; padding:2px 7px; border-radius:20px; font-weight:800;"><?= $unread_count ?></span>
                        <?php endif; ?>
                    </button>
                    <button type="button" class="msg-tab-btn inactive" id="tabBtnSent" onclick="showTab('sent')">
                        <i class="fa-solid fa-paper-plane"></i> Sent Messages (<?= count($sent) ?>)
                    </button>
                </div>

                <!-- Inbox List -->
                <div id="tab-inbox">
                    <?php if(empty($inbox)): ?>
                        <div style="text-align:center; padding:50px 20px; color:#64748b;">
                            <i class="fa-regular fa-envelope-open" style="font-size:36px; color:#cbd5e1; margin-bottom:12px; display:block;"></i>
                            <div style="font-size:15px; font-weight:700; color:#0f172a;">Pharmacy Inbox is Clear</div>
                            <div style="font-size:13px; color:#64748b; margin-top:4px;">Incoming clinical requests and doctor stock inquiries will appear here.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach($inbox as $msg): 
                            $role_class = 'role-pill-' . strtolower($msg['sender_role'] ?? 'nurse');
                        ?>
                            <div class="msg-bubble-card <?= $msg['is_read'] ? 'read' : 'unread' ?>">
                                <div class="msg-header-row">
                                    <div class="msg-sender-meta">
                                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($msg['sender_name']) ?>&background=eff6ff&color=2563eb&bold=true" alt="Sender">
                                        <div>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <strong style="color:#0f172a; font-size:13.5px;"><?= htmlspecialchars($msg['sender_name']) ?></strong>
                                                <span class="role-pill <?= $role_class ?>"><?= ucfirst($msg['sender_role']) ?></span>
                                                <?php if(!$msg['is_read']): ?>
                                                    <span style="background:#05cd99; color:#fff; font-size:10px; font-weight:800; padding:1px 6px; border-radius:4px;">NEW</span>
                                                <?php endif; ?>
                                            </div>
                                            <div style="font-size:11.5px; color:#64748b; margin-top:2px; font-weight:600;">
                                                <?= htmlspecialchars($msg['subject']) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <span style="font-size:11.5px; color:#94a3b8; font-weight:600;">
                                        <?= date('M d, H:i', strtotime($msg['created_at'])) ?>
                                    </span>
                                </div>

                                <div class="msg-body-box">
                                    <?= nl2br(htmlspecialchars($msg['body'])) ?>
                                </div>

                                <div style="margin-top:12px; display:flex; gap:10px; justify-content:flex-end;">
                                    <?php if(!$msg['is_read']): ?>
                                        <a href="messages.php?read_id=<?= $msg['id'] ?>" class="ph-btn ph-btn-outline" style="padding:6px 12px; font-size:12px; text-decoration:none;">
                                            <i class="fa-regular fa-circle-check"></i> Mark as Read
                                        </a>
                                    <?php endif; ?>
                                    <button type="button" class="ph-btn ph-btn-green" style="padding:6px 14px; font-size:12px;"
                                        onclick="replyToMessage(<?= $msg['sender_id'] ?>, '<?= htmlspecialchars(addslashes($msg['sender_name'])) ?>', '<?= htmlspecialchars(addslashes($msg['subject'])) ?>')">
                                        <i class="fa-solid fa-reply"></i> Reply
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Sent List -->
                <div id="tab-sent" style="display:none;">
                    <?php if(empty($sent)): ?>
                        <div style="text-align:center; padding:50px 20px; color:#64748b;">
                            <i class="fa-regular fa-paper-plane" style="font-size:36px; color:#cbd5e1; margin-bottom:12px; display:block;"></i>
                            <div style="font-size:15px; font-weight:700; color:#0f172a;">No Dispatched Messages Yet</div>
                            <div style="font-size:13px; color:#64748b; margin-top:4px;">Outbound clinical messages or broadcasts will appear in this history.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach($sent as $msg): ?>
                            <div class="msg-bubble-card read">
                                <div class="msg-header-row">
                                    <div>
                                        <strong style="color:#0f172a; font-size:13.5px;"><?= htmlspecialchars($msg['subject']) ?></strong>
                                        <div style="font-size:12px; color:#64748b; margin-top:2px;">
                                            Dispatched to: <strong style="color:#05cd99;"><?= $msg['receiver_name'] ? htmlspecialchars($msg['receiver_name']) : ucfirst($msg['recipient_role']) . 's' ?></strong>
                                        </div>
                                    </div>
                                    <span style="font-size:11.5px; color:#94a3b8; font-weight:600;">
                                        <?= date('M d, H:i', strtotime($msg['created_at'])) ?>
                                    </span>
                                </div>
                                <div class="msg-body-box">
                                    <?= nl2br(htmlspecialchars($msg['body'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Right: Compose Message Panel -->
            <div class="content-card">
                <div style="border-bottom: 1px solid #f1f5f9; padding-bottom: 14px; margin-bottom: 20px;">
                    <h2 style="font-size:16px; font-weight:800; color:#0f172a; margin:0; display:flex; align-items:center; gap:8px;">
                        <i class="fa-solid fa-paper-plane" style="color:#05cd99;"></i> Dispatch Communication
                    </h2>
                    <div style="font-size:12px; color:#64748b; margin-top:3px;">
                        Direct messaging to doctors, nurses, or broadcast stock replenishment notices
                    </div>
                </div>

                <form method="POST" action="messages.php">
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="send_mode" id="sendModeInput" value="direct">

                    <!-- Mode Switcher -->
                    <div style="display:flex; background:#f1f5f9; padding:4px; border-radius:12px; margin-bottom:20px; border:1px solid #e2e8f0;">
                        <button type="button" id="modeDirectBtn" onclick="setSendMode('direct')" style="flex:1; padding:9px 12px; border:none; border-radius:9px; font-size:12.5px; font-weight:700; cursor:pointer; transition:all 0.2s; background:#ffffff; color:#05cd99; box-shadow:0 2px 4px rgba(0,0,0,0.06);">
                            <i class="fa-solid fa-user" style="margin-right:6px;"></i> Direct User
                        </button>
                        <button type="button" id="modeGroupBtn" onclick="setSendMode('group')" style="flex:1; padding:9px 12px; border:none; border-radius:9px; font-size:12.5px; font-weight:600; cursor:pointer; transition:all 0.2s; background:transparent; color:#64748b;">
                            <i class="fa-solid fa-bullhorn" style="margin-right:6px;"></i> Role Broadcast
                        </button>
                    </div>

                    <!-- Direct Message Selection -->
                    <div id="directSelectContainer" style="margin-bottom:18px;">
                        <label style="font-weight:700; font-size:12px; color:#1e293b; margin-bottom:6px; display:block;">
                            Recipient Person <span style="color:#ef4444;">*</span>
                        </label>
                        <select name="receiver_id" id="receiverSelectInput" style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; background:#fff; outline:none; box-sizing:border-box; color:#0f172a; font-weight:600;">
                            <option value="">-- Choose Recipient Staff / Guardian --</option>
                            <?php foreach($all_users as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= ucfirst($u['role']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Role Broadcast Radio Cards -->
                    <div id="groupSelectContainer" style="display:none; margin-bottom:18px;">
                        <label style="font-weight:700; font-size:12px; color:#1e293b; margin-bottom:8px; display:block;">
                            Target Broadcast Group <span style="color:#ef4444;">*</span>
                        </label>
                        <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:8px;">
                            <label class="role-card-opt" style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1.5px solid #05cd99; background:#ecfdf5; border-radius:10px; cursor:pointer; transition:all 0.2s;">
                                <input type="radio" name="recipient_role" value="nurse" checked style="accent-color:#05cd99;">
                                <span style="font-size:12px; font-weight:700; color:#047857;"><i class="fa-solid fa-user-nurse" style="color:#d97706; margin-right:4px;"></i> All Nurses</span>
                            </label>
                            <label class="role-card-opt" style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1.5px solid #e2e8f0; background:#fff; border-radius:10px; cursor:pointer; transition:all 0.2s;">
                                <input type="radio" name="recipient_role" value="doctor" style="accent-color:#05cd99;">
                                <span style="font-size:12px; font-weight:600; color:#334155;"><i class="fa-solid fa-user-doctor" style="color:#2563eb; margin-right:4px;"></i> All Doctors</span>
                            </label>
                            <label class="role-card-opt" style="display:flex; align-items:center; gap:8px; padding:10px 12px; border:1.5px solid #e2e8f0; background:#fff; border-radius:10px; cursor:pointer; transition:all 0.2s; grid-column: span 2;">
                                <input type="radio" name="recipient_role" value="guardian" style="accent-color:#05cd99;">
                                <span style="font-size:12px; font-weight:600; color:#334155;"><i class="fa-solid fa-hospital-user" style="color:#7c3aed; margin-right:4px;"></i> All Patient Guardians</span>
                            </label>
                        </div>
                    </div>

                    <!-- Subject Input -->
                    <div style="margin-bottom:16px;">
                        <label style="font-weight:700; font-size:12px; color:#1e293b; margin-bottom:6px; display:block;">
                            Subject Line <span style="color:#ef4444;">*</span>
                        </label>
                        <input type="text" name="subject" class="form-control" placeholder="e.g. Vaccine Restocked / Cold-Chain Notice..." required style="width:100%; padding:10px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none; box-sizing:border-box;">
                    </div>

                    <!-- Body Textarea -->
                    <div style="margin-bottom:20px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label style="font-weight:700; font-size:12px; color:#1e293b; margin:0;">
                                Message Body <span style="color:#ef4444;">*</span>
                            </label>
                            <span id="charCounter" style="font-size:11px; color:#94a3b8; font-weight:600;">0 chars</span>
                        </div>
                        <textarea name="body" id="msgBodyInput" rows="5" placeholder="Write pharmacy dispatch or clinical notice..." required style="width:100%; padding:12px 14px; border:1.5px solid #cbd5e1; border-radius:10px; font-size:13px; outline:none; box-sizing:border-box; line-height:1.5;" oninput="document.getElementById('charCounter').innerText = this.value.length + ' chars'"></textarea>
                    </div>

                    <button type="submit" class="ph-btn ph-btn-green" style="width:100%; justify-content:center; padding:12px; font-size:13.5px; font-weight:700;">
                        <i class="fa-solid fa-paper-plane" style="margin-right:6px;"></i> <span id="submitBtnText">Send Direct Message</span>
                    </button>
                </form>
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

        function showTab(tab) {
            document.getElementById('tab-inbox').style.display = tab === 'inbox' ? 'block' : 'none';
            document.getElementById('tab-sent').style.display  = tab === 'sent'  ? 'block' : 'none';
            
            const btnInbox = document.getElementById('tabBtnInbox');
            const btnSent  = document.getElementById('tabBtnSent');

            if (tab === 'inbox') {
                btnInbox.className = 'msg-tab-btn active';
                btnSent.className  = 'msg-tab-btn inactive';
            } else {
                btnInbox.className = 'msg-tab-btn inactive';
                btnSent.className  = 'msg-tab-btn active';
            }
        }

        function setSendMode(mode) {
            document.getElementById('sendModeInput').value = mode;
            const directBtn = document.getElementById('modeDirectBtn');
            const groupBtn = document.getElementById('modeGroupBtn');
            const directContainer = document.getElementById('directSelectContainer');
            const groupContainer = document.getElementById('groupSelectContainer');
            const submitText = document.getElementById('submitBtnText');

            if (mode === 'direct') {
                directBtn.style.background = '#ffffff';
                directBtn.style.color = '#05cd99';
                directBtn.style.boxShadow = '0 2px 4px rgba(0,0,0,0.06)';
                directBtn.style.fontWeight = '700';

                groupBtn.style.background = 'transparent';
                groupBtn.style.color = '#64748b';
                groupBtn.style.boxShadow = 'none';
                groupBtn.style.fontWeight = '600';

                directContainer.style.display = 'block';
                groupContainer.style.display = 'none';
                submitText.innerText = 'Send Direct Message';
            } else {
                groupBtn.style.background = '#ffffff';
                groupBtn.style.color = '#05cd99';
                groupBtn.style.boxShadow = '0 2px 4px rgba(0,0,0,0.06)';
                groupBtn.style.fontWeight = '700';

                directBtn.style.background = 'transparent';
                directBtn.style.color = '#64748b';
                directBtn.style.boxShadow = 'none';
                directBtn.style.fontWeight = '600';

                groupContainer.style.display = 'block';
                directContainer.style.display = 'none';
                submitText.innerText = 'Dispatch Broadcast Notice';
            }
        }

        // Highlight selected role card
        document.querySelectorAll('input[name="recipient_role"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.role-card-opt').forEach(card => {
                    card.style.borderColor = '#e2e8f0';
                    card.style.background = '#ffffff';
                    const span = card.querySelector('span');
                    if (span) span.style.color = '#334155';
                });
                const parent = this.closest('.role-card-opt');
                if (parent) {
                    parent.style.borderColor = '#05cd99';
                    parent.style.background = '#ecfdf5';
                    const span = parent.querySelector('span');
                    if (span) span.style.color = '#047857';
                }
            });
        });

        function replyToMessage(senderId, senderName, subject) {
            setSendMode('direct');
            const receiverSelect = document.getElementById('receiverSelectInput');
            if (receiverSelect) {
                let found = false;
                for (let i = 0; i < receiverSelect.options.length; i++) {
                    if (receiverSelect.options[i].value == senderId) {
                        receiverSelect.selectedIndex = i;
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    const opt = new Option(senderName, senderId, true, true);
                    receiverSelect.add(opt);
                }
            }
            const subjectInput = document.querySelector('input[name="subject"]');
            if (subjectInput) {
                subjectInput.value = subject.startsWith('Re:') ? subject : 'Re: ' + subject;
            }
            const bodyInput = document.getElementById('msgBodyInput');
            if (bodyInput) {
                bodyInput.focus();
                bodyInput.scrollIntoView({ behavior: 'smooth' });
            }
        }
    </script>
</body>
</html>