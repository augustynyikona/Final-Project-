<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: ../auth/login.php");
    exit;
}

$nurse_id   = $_SESSION['user_id'];
$nurse_name = $_SESSION['name'] ?? 'Bupe';
$success    = '';
$error      = '';

// Fetch nurse profile from database
$stmt = $pdo->prepare("SELECT name, profile_pic FROM users WHERE id = ?");
$stmt->execute([$nurse_id]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user_profile && !empty($user_profile['name'])) {
    $nurse_name = $user_profile['name'];
}
$profile_pic = ($user_profile && !empty($user_profile['profile_pic'])) 
    ? '../uploads/' . htmlspecialchars($user_profile['profile_pic']) 
    : "https://ui-avatars.com/api/?name=" . urlencode($nurse_name) . "&background=2563eb&color=fff&bold=true";

// Handle Send Message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $send_mode      = $_POST['send_mode'] ?? 'direct';
    $subject        = trim($_POST['subject'] ?? '');
    $body           = trim($_POST['body'] ?? '');
    
    if ($send_mode === 'direct') {
        $recipient_role = 'direct';
        $receiver_id    = isset($_POST['receiver_id']) && !empty($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : NULL;
    } else {
        $recipient_role = $_POST['recipient_role'] ?? 'doctor';
        $receiver_id    = NULL;
    }

    if (empty($subject) || empty($body)) {
        $error = "Subject and message body are required.";
    } elseif ($send_mode === 'direct' && empty($receiver_id)) {
        $error = "Please select a specific recipient user for direct messaging.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, recipient_role, receiver_id, subject, body, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($stmt->execute([$nurse_id, $recipient_role, $receiver_id, $subject, $body])) {
            $success = "Clinical communication dispatched successfully!";
        } else {
            $error = "Failed to dispatch message. Please try again.";
        }
    }
}

// Mark as read
if (isset($_GET['read_id'])) {
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND (receiver_id = ? OR recipient_role = 'nurse' OR recipient_role = 'all')");
    $stmt->execute([(int)$_GET['read_id'], $nurse_id]);
    header("Location: messages.php");
    exit;
}

// Fetch Inbox
$stmt = $pdo->prepare("
    SELECT m.*, u.name as sender_name, u.role as sender_role, u.profile_pic as sender_pic
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.receiver_id = ? OR m.recipient_role = 'nurse' OR m.recipient_role = 'all'
    ORDER BY m.created_at DESC
");
$stmt->execute([$nurse_id]);
$inbox = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Sent
$stmt = $pdo->prepare("
    SELECT m.*, u.name as receiver_name, u.role as receiver_role
    FROM messages m
    LEFT JOIN users u ON m.receiver_id = u.id
    WHERE m.sender_id = ?
    ORDER BY m.created_at DESC
");
$stmt->execute([$nurse_id]);
$sent = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recipients list for direct messaging
$all_users = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('doctor', 'pharmacy', 'guardian', 'admin') ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$unread_count = count(array_filter($inbox, fn($m) => !$m['is_read']));
$unread_msg_count = $unread_count;

// Sidebar counts
$pending_reqs_count = (int)$pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'Pending'")->fetchColumn();
$current_date = date('l, d F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Messages &amp; Communications &mdash; VaxiCare</title>
    
    <!-- Google Fonts: Inter & Space Grotesk -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Canonical Nurse Station Stylesheet -->
    <link rel="stylesheet" href="../css/nurse_theme.css">

    <style>
        /* Dedicated Messaging Enhancements */
        .nr-card {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
        }

        #composeSection {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 16px;
            box-shadow: 0 6px 24px rgba(15, 23, 42, 0.05);
        }

        .msg-grid-container {
            display: grid;
            grid-template-columns: 1.6fr 1.1fr;
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 1100px) {
            .msg-grid-container {
                grid-template-columns: 1fr;
            }
        }

        .msg-card-feed {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .message-bubble-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
            transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        .message-bubble-card:hover {
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.08);
            border-color: #cbd5e1;
            transform: translateY(-2px);
        }

        .message-bubble-card.is-unread {
            background: #f8fbff;
            border-color: #bfdbfe;
            border-left: 4px solid #2563eb;
        }

        .msg-sender-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .msg-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 1.5px solid #e2e8f0;
            flex-shrink: 0;
        }

        .msg-header-text {
            flex: 1;
            min-width: 0;
        }

        .msg-author-name {
            font-size: 13.5px;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .role-pill-badge {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 2px 8px;
            border-radius: 6px;
        }

        .role-doctor { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .role-pharmacy { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .role-guardian { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }
        .role-admin { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .role-nurse { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }

        .msg-time-badge {
            font-size: 11.5px;
            font-weight: 500;
            color: #64748b;
            white-space: nowrap;
        }

        .msg-subject-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 14.5px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .msg-body-content {
            font-size: 13px;
            line-height: 1.6;
            color: #334155;
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 10px;
            padding: 14px 16px;
            word-break: break-word;
        }

        .message-bubble-card.is-unread .msg-body-content {
            background: #ffffff;
            border-color: #e2e8f0;
        }

        .msg-card-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 14px;
        }

        /* Pill Tabs */
        .msg-tabs-nav {
            display: flex;
            gap: 10px;
            background: #f1f5f9;
            padding: 5px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .msg-tab-btn {
            flex: 1;
            padding: 9px 16px;
            border-radius: 9px;
            border: none;
            background: transparent;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .msg-tab-btn.active {
            background: #ffffff;
            color: #2563eb;
            font-weight: 700;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        }

        /* Segmented Mode Switcher */
        .segmented-mode-switch {
            display: flex;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            margin-bottom: 18px;
        }

        .segmented-mode-btn {
            flex: 1;
            padding: 8px 12px;
            border: none;
            border-radius: 8px;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            background: transparent;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .segmented-mode-btn.active {
            background: #ffffff;
            color: #2563eb;
            font-weight: 700;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }

        /* Role cards for broadcast */
        .broadcast-roles-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .broadcast-role-card {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border: 1.5px solid #e2e8f0;
            background: #ffffff;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .broadcast-role-card:hover {
            border-color: #93c5fd;
            background: #f8fbff;
        }

        .broadcast-role-card.selected {
            border-color: #2563eb;
            background: #eff6ff;
        }

        .broadcast-role-card.span-2 {
            grid-column: span 2;
        }

        .broadcast-role-card input[type="radio"] {
            accent-color: #2563eb;
        }

        .unread-indicator-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #2563eb;
            display: inline-block;
            box-shadow: 0 0 0 2px #bfdbfe;
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
            <a href="requisition.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-stacked"></i> Requisitions
                <?php if($pending_reqs_count > 0): ?>
                    <span class="badge-red" style="background:#f59e0b;"><?= $pending_reqs_count ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Governance &amp; Profile</div>
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
                    <h1>Clinical Messages</h1>
                    <div class="topbar-breadcrumbs" style="display:flex; align-items:center; gap:6px; margin:0;">
                        <span>Clinical Station</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span class="current">Communications</span>
                    </div>
                </div>
            </div>

            <!-- Centered Date & Hub Context -->
            <div class="topbar-center" style="display:flex; align-items:center; justify-content:center;">
                <div class="date-range">
                    <i class="fa-regular fa-comments"></i> Clinical Comms Hub &bull; <?= $current_date ?>
                </div>
            </div>

            <!-- Right Controls -->
            <div class="topbar-right">
                <a href="#composeSection" class="nr-btn nr-btn-primary" style="padding:8px 18px; font-size:13px; text-decoration:none;">
                    <i class="fa-solid fa-paper-plane"></i> Compose Message
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

        <!-- ===== ENLARGED TOP KPI METRIC CARDS ===== -->
        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 26px;">
            <div class="stat-card card-blue">
                <div class="stat-icon icon-blue">
                    <i class="fa-solid fa-envelope-open-text"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Unread Messages</div>
                    <div class="stat-value"><?= number_format($unread_count) ?></div>
                    <div class="stat-trend trend-neutral" style="color: <?= $unread_count > 0 ? '#2563eb' : '#64748b' ?>;">
                        <i class="fa-solid fa-inbox"></i> <?= $unread_count > 0 ? 'Requires attention' : 'All messages caught up' ?>
                    </div>
                </div>
            </div>

            <div class="stat-card card-orange">
                <div class="stat-icon icon-orange">
                    <i class="fa-solid fa-envelope"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Inbox Received</div>
                    <div class="stat-value"><?= number_format(count($inbox)) ?></div>
                    <div class="stat-trend trend-neutral">
                        <i class="fa-regular fa-clock"></i> Inbound clinical archive
                    </div>
                </div>
            </div>

            <div class="stat-card card-green">
                <div class="stat-icon icon-green">
                    <i class="fa-solid fa-paper-plane"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Outbound Dispatched</div>
                    <div class="stat-value"><?= number_format(count($sent)) ?></div>
                    <div class="stat-trend trend-neutral">
                        <i class="fa-solid fa-share"></i> Nursing transmissions
                    </div>
                </div>
            </div>

            <div class="stat-card card-purple">
                <div class="stat-icon icon-purple">
                    <i class="fa-solid fa-users-line"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Clinical Directory</div>
                    <div class="stat-value"><?= number_format(count($all_users)) ?></div>
                    <div class="stat-trend trend-neutral">
                        <i class="fa-solid fa-hospital-user"></i> Doctors, Pharmacy &amp; Guardians
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== MAIN 2-COLUMN MESSAGING WORKSPACE ===== -->
        <div class="msg-grid-container">

            <!-- LEFT: Messages Inbox & Sent Feed -->
            <div class="nr-card" style="padding: 24px; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05);">
                
                <!-- Card Header with Navigation Tabs & Live Search -->
                <div style="display:flex; flex-direction:column; gap:16px; margin-bottom:20px; border-bottom:1px solid #f1f5f9; padding-bottom:18px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                        <div>
                            <h2 style="font-family:'Space Grotesk',sans-serif; font-size:18px; font-weight:700; color:#0f172a; margin:0 0 4px 0; display:flex; align-items:center; gap:8px;">
                                <i class="fa-solid fa-comments" style="color:#2563eb;"></i> Clinical Communications Feed
                            </h2>
                            <p style="font-size:12.5px; color:#64748b; margin:0;">
                                Direct consultations, laboratory notices, and ward coordination threads
                            </p>
                        </div>

                        <!-- Pill Tabs -->
                        <div class="msg-tabs-nav" style="min-width: 260px;">
                            <button type="button" class="msg-tab-btn active" id="tabBtnInbox" onclick="showTab('inbox')">
                                <i class="fa-solid fa-inbox"></i> Inbox
                                <?php if($unread_count > 0): ?>
                                    <span class="badge-red" style="font-size:10px; padding:2px 7px;"><?= $unread_count ?></span>
                                <?php endif; ?>
                            </button>
                            <button type="button" class="msg-tab-btn" id="tabBtnSent" onclick="showTab('sent')">
                                <i class="fa-solid fa-paper-plane"></i> Sent (<?= count($sent) ?>)
                            </button>
                        </div>
                    </div>

                    <!-- Instant Search Bar -->
                    <div style="position:relative; width:100%;">
                        <i class="fa-solid fa-magnifying-glass" style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px;"></i>
                        <input type="text" id="msgSearchInput" placeholder="Filter messages by sender, role, or subject keyword..." 
                               oninput="filterMessages()"
                               style="width:100%; padding:10px 14px 10px 38px; border-radius:10px; border:1.5px solid #e2e8f0; font-size:13px; background:#f8fafc; transition:all 0.2s;"
                               onfocus="this.style.background='#fff'; this.style.borderColor='#2563eb';"
                               onblur="this.style.background='#f8fafc'; this.style.borderColor='#e2e8f0';">
                    </div>
                </div>

                <!-- TAB 1: INBOX -->
                <div id="tab-inbox" class="msg-card-feed">
                    <?php if(empty($inbox)): ?>
                        <div style="text-align:center; padding:50px 20px; color:#64748b; background:#f8fafc; border-radius:14px; border:1px dashed #cbd5e1;">
                            <i class="fa-regular fa-envelope-open" style="font-size:42px; color:#94a3b8; margin-bottom:12px; display:block;"></i>
                            <div style="font-weight:700; color:#334155; font-size:15px; margin-bottom:4px;">Nurse Inbox is Empty</div>
                            <p style="font-size:13px; margin:0;">There are currently no inbound messages for the clinical ward station.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($inbox as $msg): 
                            $role_class = 'role-' . htmlspecialchars($msg['sender_role']);
                            $avatar_url = (!empty($msg['sender_pic'])) 
                                ? '../uploads/' . htmlspecialchars($msg['sender_pic']) 
                                : "https://ui-avatars.com/api/?name=" . urlencode($msg['sender_name']) . "&background=2563eb&color=fff&bold=true";
                        ?>
                            <div class="message-bubble-card <?= !$msg['is_read'] ? 'is-unread' : '' ?> msg-item" 
                                 data-sender="<?= htmlspecialchars(strtolower($msg['sender_name'])) ?>"
                                 data-role="<?= htmlspecialchars(strtolower($msg['sender_role'])) ?>"
                                 data-subject="<?= htmlspecialchars(strtolower($msg['subject'])) ?>"
                                 data-body="<?= htmlspecialchars(strtolower($msg['body'])) ?>">
                                
                                <div class="msg-sender-meta">
                                    <img src="<?= $avatar_url ?>" alt="<?= htmlspecialchars($msg['sender_name']) ?>" class="msg-avatar">
                                    <div class="msg-header-text">
                                        <div class="msg-author-name">
                                            <span><?= htmlspecialchars($msg['sender_name']) ?></span>
                                            <span class="role-pill-badge <?= $role_class ?>"><?= ucfirst(htmlspecialchars($msg['sender_role'])) ?></span>
                                            <?php if(!$msg['is_read']): ?>
                                                <span style="font-size:10px; font-weight:800; color:#2563eb; background:#dbeafe; padding:2px 7px; border-radius:6px; display:inline-flex; align-items:center; gap:4px;">
                                                    <span class="unread-indicator-dot"></span> NEW
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="msg-time-badge">
                                        <i class="fa-regular fa-clock" style="margin-right:3px;"></i> <?= date('M d, Y &bull; H:i', strtotime($msg['created_at'])) ?>
                                    </div>
                                </div>

                                <div class="msg-subject-title">
                                    <i class="fa-regular fa-comment-dots" style="color:#2563eb; font-size:13px;"></i>
                                    <span><?= htmlspecialchars($msg['subject']) ?></span>
                                </div>

                                <div class="msg-body-content">
                                    <?= nl2br(htmlspecialchars($msg['body'])) ?>
                                </div>

                                <div class="msg-card-actions">
                                    <?php if(!$msg['is_read']): ?>
                                        <a href="messages.php?read_id=<?= $msg['id'] ?>" class="nr-btn nr-btn-sm nr-btn-secondary" style="padding:6px 14px; font-size:12px; text-decoration:none;">
                                            <i class="fa-regular fa-circle-check" style="color:#10b981;"></i> Mark Read
                                        </a>
                                    <?php endif; ?>
                                    <button type="button" class="nr-btn nr-btn-sm nr-btn-primary" style="padding:6px 14px; font-size:12px;"
                                            onclick="replyToMessage(<?= $msg['sender_id'] ?>, '<?= htmlspecialchars(addslashes($msg['sender_name'])) ?>', '<?= htmlspecialchars(addslashes($msg['subject'])) ?>')">
                                        <i class="fa-solid fa-reply"></i> Reply
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- TAB 2: SENT -->
                <div id="tab-sent" class="msg-card-feed" style="display:none;">
                    <?php if(empty($sent)): ?>
                        <div style="text-align:center; padding:50px 20px; color:#64748b; background:#f8fafc; border-radius:14px; border:1px dashed #cbd5e1;">
                            <i class="fa-regular fa-paper-plane" style="font-size:42px; color:#94a3b8; margin-bottom:12px; display:block;"></i>
                            <div style="font-weight:700; color:#334155; font-size:15px; margin-bottom:4px;">No Outbound Dispatches</div>
                            <p style="font-size:13px; margin:0;">You haven't dispatched any messages from this clinical workstation yet.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($sent as $msg): 
                            $receiver_display = $msg['receiver_name'] ? htmlspecialchars($msg['receiver_name']) : ucfirst(htmlspecialchars($msg['recipient_role'])) . ' Team (Broadcast)';
                            $role_class = 'role-' . htmlspecialchars($msg['receiver_role'] ?? $msg['recipient_role']);
                        ?>
                            <div class="message-bubble-card msg-item"
                                 data-sender="<?= htmlspecialchars(strtolower($receiver_display)) ?>"
                                 data-role="<?= htmlspecialchars(strtolower($msg['recipient_role'])) ?>"
                                 data-subject="<?= htmlspecialchars(strtolower($msg['subject'])) ?>"
                                 data-body="<?= htmlspecialchars(strtolower($msg['body'])) ?>">
                                
                                <div class="msg-sender-meta">
                                    <div style="width:36px; height:36px; border-radius:50%; background:#eff6ff; display:flex; align-items:center; justify-content:center; color:#2563eb; font-weight:700; font-size:13px; border:1.5px solid #dbeafe;">
                                        <i class="fa-solid fa-paper-plane"></i>
                                    </div>
                                    <div class="msg-header-text">
                                        <div class="msg-author-name">
                                            <span>To: <?= $receiver_display ?></span>
                                            <span class="role-pill-badge <?= $role_class ?>"><?= ucfirst(htmlspecialchars($msg['recipient_role'])) ?></span>
                                        </div>
                                    </div>
                                    <div class="msg-time-badge">
                                        <i class="fa-regular fa-clock" style="margin-right:3px;"></i> <?= date('M d, Y &bull; H:i', strtotime($msg['created_at'])) ?>
                                    </div>
                                </div>

                                <div class="msg-subject-title">
                                    <i class="fa-regular fa-envelope" style="color:#64748b; font-size:13px;"></i>
                                    <span><?= htmlspecialchars($msg['subject']) ?></span>
                                </div>

                                <div class="msg-body-content">
                                    <?= nl2br(htmlspecialchars($msg['body'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>

            <!-- RIGHT: Compose Clinical Communication -->
            <div class="nr-card" id="composeSection" style="padding: 24px; position: sticky; top: 88px; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 16px; box-shadow: 0 4px 24px rgba(15, 23, 42, 0.06);">
                <div style="border-bottom: 1px solid #f1f5f9; padding-bottom: 16px; margin-bottom: 20px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <div style="width:32px; height:32px; border-radius:8px; background:#eff6ff; display:flex; align-items:center; justify-content:center; color:#2563eb;">
                            <i class="fa-solid fa-feather-pointed"></i>
                        </div>
                        <div>
                            <h3 style="font-family:'Space Grotesk',sans-serif; font-size:16px; font-weight:700; color:#0f172a; margin:0;">
                                Dispatch Communication
                            </h3>
                            <div style="font-size:12px; color:#64748b; margin-top:2px;">
                                Direct consultation or ward role broadcast
                            </div>
                        </div>
                    </div>
                </div>

                <form method="POST" id="composeMessageForm">
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="send_mode" id="sendModeInput" value="direct">
                    
                    <!-- Segmented Mode Switcher -->
                    <div class="segmented-mode-switch">
                        <button type="button" id="modeDirectBtn" class="segmented-mode-btn active" onclick="setSendMode('direct')">
                            <i class="fa-solid fa-user-doctor"></i> Direct Recipient
                        </button>
                        <button type="button" id="modeGroupBtn" class="segmented-mode-btn" onclick="setSendMode('group')">
                            <i class="fa-solid fa-bullhorn"></i> Role Broadcast
                        </button>
                    </div>

                    <!-- Direct Message Dropdown -->
                    <div id="directSelectContainer" class="form-group" style="margin-bottom: 18px;">
                        <label class="form-label" style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#475569; display:block; margin-bottom:6px;">
                            Recipient Contact <span style="color:#ef4444;">*</span>
                        </label>
                        <select name="receiver_id" id="receiverSelectInput" class="form-control" style="width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid #cbd5e1; font-size:13px; background:#ffffff;">
                            <option value="">&mdash; Select Clinical Contact &mdash;</option>
                            <?php 
                            $grouped_users = [];
                            foreach($all_users as $u) {
                                $grouped_users[$u['role']][] = $u;
                            }
                            ?>
                            <?php foreach($grouped_users as $role_key => $users_list): ?>
                                <optgroup label="<?= ucfirst($role_key) ?>s">
                                    <?php foreach($users_list as $u): ?>
                                        <option value="<?= $u['id'] ?>">
                                            <?= htmlspecialchars($u['name']) ?> (<?= ucfirst($u['role']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Role Broadcast Selectors -->
                    <div id="groupSelectContainer" class="form-group" style="display:none; margin-bottom: 18px;">
                        <label class="form-label" style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#475569; display:block; margin-bottom:8px;">
                            Target Team Broadcast <span style="color:#ef4444;">*</span>
                        </label>
                        <div class="broadcast-roles-grid">
                            <label class="broadcast-role-card selected" id="roleCardDoctor">
                                <input type="radio" name="recipient_role" value="doctor" checked onchange="highlightRoleCard(this)">
                                <div>
                                    <div style="font-size:12.5px; font-weight:700; color:#1e293b;"><i class="fa-solid fa-user-doctor" style="color:#2563eb; margin-right:4px;"></i> Doctors</div>
                                    <div style="font-size:11px; color:#64748b;">Attending physicians</div>
                                </div>
                            </label>

                            <label class="broadcast-role-card" id="roleCardPharmacy">
                                <input type="radio" name="recipient_role" value="pharmacy" onchange="highlightRoleCard(this)">
                                <div>
                                    <div style="font-size:12.5px; font-weight:700; color:#1e293b;"><i class="fa-solid fa-pills" style="color:#059669; margin-right:4px;"></i> Pharmacy</div>
                                    <div style="font-size:11px; color:#64748b;">Dispensary team</div>
                                </div>
                            </label>

                            <label class="broadcast-role-card span-2" id="roleCardGuardian">
                                <input type="radio" name="recipient_role" value="guardian" onchange="highlightRoleCard(this)">
                                <div>
                                    <div style="font-size:12.5px; font-weight:700; color:#1e293b;"><i class="fa-solid fa-hospital-user" style="color:#7c3aed; margin-right:4px;"></i> All Patient Guardians</div>
                                    <div style="font-size:11px; color:#64748b;">Community broadcast to registered parents</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Subject Input -->
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label" style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#475569; display:block; margin-bottom:6px;">
                            Subject Line <span style="color:#ef4444;">*</span>
                        </label>
                        <input type="text" name="subject" id="subjectInput" class="form-control" placeholder="e.g. Ward 4 Vaccine dosage confirmation..." required 
                               style="width:100%; padding:10px 14px; border-radius:10px; border:1.5px solid #cbd5e1; font-size:13px;">
                    </div>

                    <!-- Message Body -->
                    <div class="form-group" style="margin-bottom: 20px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label class="form-label" style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#475569; margin:0;">
                                Clinical Notes <span style="color:#ef4444;">*</span>
                            </label>
                            <span id="charCounter" style="font-size:11px; color:#94a3b8; font-weight:600;">0 characters</span>
                        </div>
                        <textarea name="body" id="msgBodyInput" class="form-control" rows="5" placeholder="Enter clinical instruction, patient referral response, or requisition notes..." required 
                                  style="width:100%; padding:12px 14px; border-radius:10px; border:1.5px solid #cbd5e1; font-size:13px; line-height:1.6;" 
                                  oninput="document.getElementById('charCounter').innerText = this.value.length + ' characters'"></textarea>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" id="dispatchSubmitBtn" class="nr-btn nr-btn-primary" style="width:100%; padding:13px; font-size:14px; justify-content:center; border-radius:10px; border: 1.5px solid #1d4ed8; box-shadow: 0 4px 14px rgba(37,99,235,0.25);">
                        <i class="fa-solid fa-paper-plane"></i> <span id="submitBtnText">Dispatch Message</span>
                    </button>
                </form>
            </div>

        </div>

    </main>

    <script>
        // Tab switching
        function showTab(tab) {
            document.getElementById('tab-inbox').style.display = tab === 'inbox' ? 'flex' : 'none';
            document.getElementById('tab-sent').style.display  = tab === 'sent'  ? 'flex' : 'none';
            
            const btnInbox = document.getElementById('tabBtnInbox');
            const btnSent  = document.getElementById('tabBtnSent');
            
            if (tab === 'inbox') {
                btnInbox.classList.add('active');
                btnSent.classList.remove('active');
            } else {
                btnSent.classList.add('active');
                btnInbox.classList.remove('active');
            }
            filterMessages();
        }

        // Mode Switching (Direct vs Role Broadcast)
        function setSendMode(mode) {
            document.getElementById('sendModeInput').value = mode;
            const directBtn = document.getElementById('modeDirectBtn');
            const groupBtn = document.getElementById('modeGroupBtn');
            const directContainer = document.getElementById('directSelectContainer');
            const groupContainer = document.getElementById('groupSelectContainer');
            const submitText = document.getElementById('submitBtnText');

            if (mode === 'direct') {
                directBtn.classList.add('active');
                groupBtn.classList.remove('active');
                directContainer.style.display = 'block';
                groupContainer.style.display = 'none';
                submitText.innerText = 'Dispatch Message';
            } else {
                groupBtn.classList.add('active');
                directBtn.classList.remove('active');
                groupContainer.style.display = 'block';
                directContainer.style.display = 'none';
                submitText.innerText = 'Dispatch Team Broadcast';
            }
        }

        // Highlight broadcast card
        function highlightRoleCard(radioInput) {
            document.querySelectorAll('.broadcast-role-card').forEach(card => card.classList.remove('selected'));
            const parent = radioInput.closest('.broadcast-role-card');
            if (parent) {
                parent.classList.add('selected');
            }
        }

        // Quick Reply Auto-Populate
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

            const subjectInput = document.getElementById('subjectInput');
            if (subjectInput) {
                subjectInput.value = subject.startsWith('Re:') ? subject : 'Re: ' + subject;
            }

            const bodyInput = document.getElementById('msgBodyInput');
            if (bodyInput) {
                bodyInput.focus();
                document.getElementById('composeSection').scrollIntoView({ behavior: 'smooth' });
            }
        }

        // Client-side Instant Filter
        function filterMessages() {
            const query = (document.getElementById('msgSearchInput').value || '').toLowerCase().trim();
            const activeTabId = document.getElementById('tabBtnInbox').classList.contains('active') ? 'tab-inbox' : 'tab-sent';
            const cards = document.querySelectorAll('#' + activeTabId + ' .msg-item');

            cards.forEach(card => {
                if (!query) {
                    card.style.display = 'block';
                    return;
                }
                const sender = card.getAttribute('data-sender') || '';
                const role = card.getAttribute('data-role') || '';
                const subject = card.getAttribute('data-subject') || '';
                const body = card.getAttribute('data-body') || '';

                if (sender.includes(query) || role.includes(query) || subject.includes(query) || body.includes(query)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Mobile Sidebar Toggle
        function toggleSidebar(open) {
            const sidebar = document.getElementById('nurseSidebar');
            const backdrop = document.getElementById('mobileSidebarBackdrop');
            if (sidebar && backdrop) {
                if (open) {
                    sidebar.classList.add('mobile-open');
                    backdrop.classList.add('active');
                } else {
                    sidebar.classList.remove('mobile-open');
                    backdrop.classList.remove('active');
                }
            }
        }
    </script>
</body>
</html>
