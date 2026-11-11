<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../auth/login.php");
    exit;
}

$doctor_id   = $_SESSION['user_id'];

// Fetch user profile from database
$stmt = $pdo->prepare("SELECT name, profile_pic FROM users WHERE id = ?");
$stmt->execute([$doctor_id]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);

$doctor_name = $user_profile['name'] ?? $_SESSION['name'];
$profile_pic = ($user_profile && $user_profile['profile_pic']) 
    ? '../uploads/' . $user_profile['profile_pic'] 
    : "https://ui-avatars.com/api/?name=" . urlencode($doctor_name) . "&background=0284c7&color=fff";

$success = '';
$error   = '';

// Handle Send Message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    csrf_guard();
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
        if ($stmt->execute([$doctor_id, $recipient_role, $receiver_id, $subject, $body])) {
            $success = "Clinical message transmitted successfully!";
        } else {
            $error = "Failed to transmit message.";
        }
    }
}

// Mark as read
if (isset($_GET['read_id'])) {
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ? AND (receiver_id = ? OR recipient_role = 'doctor')");
    $stmt->execute([(int)$_GET['read_id'], $doctor_id]);
    header("Location: messages.php");
    exit;
}

// Fetch Inbox
$stmt = $pdo->prepare("
    SELECT m.*, u.name as sender_name, u.role as sender_role
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.receiver_id = ? OR m.recipient_role = 'doctor' OR m.recipient_role = 'all'
    ORDER BY m.created_at DESC
");
$stmt->execute([$doctor_id]);
$inbox = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Sent
$stmt = $pdo->prepare("
    SELECT m.*, u.name as receiver_name
    FROM messages m
    LEFT JOIN users u ON m.receiver_id = u.id
    WHERE m.sender_id = ?
    ORDER BY m.created_at DESC
");
$stmt->execute([$doctor_id]);
$sent = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recipients list for direct messaging
$all_users = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('nurse', 'pharmacy', 'guardian', 'admin') ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$unread_count = count(array_filter($inbox, fn($m) => !$m['is_read']));

// Triage Referrals count for sidebar badge
$pending_alerts = (int)$pdo->query("SELECT COUNT(*) FROM triage_referrals WHERE status = 'pending'")->fetchColumn();
$unread_msg_count = $unread_count;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consult Messages — VaxiCare Doctor Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/doctor_theme.css">
    <style>
        .msg-layout {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 24px;
            align-items: start;
        }

        @media (max-width: 1024px) {
            .msg-layout {
                grid-template-columns: 1fr;
            }
        }

        .tab-btn {
            padding: 8px 18px;
            border-radius: 100px;
            font-size: 13px;
            font-weight: 700;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .tab-btn.active {
            background: #0284c7;
            color: #ffffff;
            border-color: #0284c7;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
        }

        .msg-card {
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 14px;
            transition: all 0.2s;
            background: #ffffff;
        }

        .msg-card.unread {
            background: #f0f9ff;
            border-color: #bae6fd;
            box-shadow: 0 2px 10px rgba(2, 132, 199, 0.06);
        }

        .msg-card:hover {
            border-color: #93c5fd;
        }

        .msg-body-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13px;
            color: #334155;
            line-height: 1.5;
            margin: 10px 0;
        }

        .msg-card.unread .msg-body-box {
            background: #ffffff;
            border-color: #e0f2fe;
        }

        .role-pill {
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 2px 8px;
            border-radius: 100px;
            letter-spacing: 0.5px;
        }

        .role-nurse { background: #e0f2fe; color: #0284c7; }
        .role-pharmacy { background: #e6f8f3; color: #05cd99; }
        .role-guardian { background: #f5e8ff; color: #7928ca; }
        .role-admin { background: #fee2e2; color: #ef4444; }
        .role-doctor { background: #fef3c7; color: #d97706; }

        .form-label {
            display: block;
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #475569;
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            font-size: 13.5px;
            color: #0f172a;
            background: #ffffff;
            transition: all 0.2s;
            outline: none;
            font-family: inherit;
        }

        .form-control:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
        }
    </style>
</head>
<body class="app-body">

    <!-- Canonical Doctor Sidebar -->
    <aside class="sidebar" id="doctorSidebar">
        <div class="sidebar-brand-box">
            <a href="dashboard.php" class="sidebar-brand-link">
                <div class="sidebar-brand-icon">
                    <i class="fa-solid fa-user-doctor"></i>
                </div>
                <div class="sidebar-brand-info">
                    <div class="sidebar-brand-title">Vaxi<span>Care</span></div>
                    <div class="sidebar-brand-sub">Clinical Pediatrics</div>
                </div>
            </a>
        </div>

        <nav class="sidebar-menu">
            <div class="sidebar-section-title">Clinical Management</div>
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-chart-line"></i>
                <span>Clinical Overview</span>
            </a>
            <a href="patients.php" class="sidebar-item">
                <i class="fa-solid fa-children"></i>
                <span>Patients Directory</span>
            </a>
            <a href="schedule.php" class="sidebar-item">
                <i class="fa-regular fa-calendar-check"></i>
                <span>Clinical Schedule</span>
            </a>
            <a href="critical_alerts.php" class="sidebar-item">
                <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444;"></i>
                <span>Critical Alerts</span>
                <?php if ($pending_alerts > 0): ?>
                    <span class="badge-red"><?= $pending_alerts ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Clinical Intelligence</div>
            <a href="reports.php" class="sidebar-item">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Coverage Reports</span>
            </a>
            <a href="messages.php" class="sidebar-item active">
                <i class="fa-regular fa-envelope"></i>
                <span>Consult Messages</span>
                <?php if ($unread_msg_count > 0): ?>
                    <span class="badge-red"><?= $unread_msg_count ?></span>
                <?php endif; ?>
            </a>
            <a href="settings.php" class="sidebar-item">
                <i class="fa-solid fa-gear"></i>
                <span>Doctor Settings</span>
            </a>
        </nav>

        <div class="sidebar-logout">
            <a href="../auth/logout.php">
                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                <span>Log Out</span>
            </a>
        </div>
    </aside>

    <!-- Mobile Overlay Backdrop -->
    <div class="mobile-sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Main Workspace Content -->
    <main class="app-main">

        <!-- Canonical Elevated Topbar -->
        <header class="app-topbar">
            <div style="display: flex; align-items: center;">
                <button type="button" class="mobile-nav-toggle" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px; flex-wrap: wrap;">
                    <h1>Clinical Consult Messages</h1>
                    <div class="topbar-breadcrumbs" style="display: flex; align-items: center; gap: 6px; margin: 0;">
                        <span>Doctor Station</span>
                        <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #cbd5e1;"></i>
                        <span class="current">Multidisciplinary Communication</span>
                    </div>
                </div>
            </div>

            <div class="topbar-center">
                <div class="date-range">
                    <i class="fa-regular fa-comments"></i>
                    <span>Internal Hospital Comms &bull; <?= $unread_count ?> Unread</span>
                </div>
            </div>

            <div class="topbar-right">
                <div class="topbar-control-pill">
                    <a href="messages.php" class="topbar-action-btn active" title="Consult Messages">
                        <i class="fa-regular fa-bell"></i>
                        <?php if ($unread_msg_count > 0): ?>
                            <span class="notif-ping"></span>
                        <?php endif; ?>
                    </a>
                    <a href="settings.php" class="topbar-action-btn" title="Account Settings">
                        <i class="fa-solid fa-gear"></i>
                    </a>
                    <div class="topbar-divider"></div>
                    <a href="settings.php" class="topbar-profile" title="Doctor Account">
                        <div class="topbar-avatar-wrap">
                            <img src="<?= $profile_pic ?>" alt="<?= htmlspecialchars($doctor_name) ?>">
                            <span class="avatar-status-dot"></span>
                        </div>
                        <div class="topbar-profile-info">
                            <span class="topbar-profile-name"><?= htmlspecialchars($doctor_name) ?></span>
                        </div>
                    </a>
                </div>
            </div>
        </header>

        <?php if (!empty($error)): ?>
            <div style="background: #fef2f2; border: 1.5px solid #fecdd3; color: #991b1b; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div style="background: #e6f8f3; border: 1.5px solid #a7f3d0; color: #05cd99; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <!-- Messages Grid -->
        <div class="msg-layout">

            <!-- Left: Messages Tabs & Feeds -->
            <div class="dr-card">
                <div class="dr-card-header">
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="tab-btn active" id="tabBtnInbox" onclick="showTab('inbox')">
                            <i class="fa-solid fa-inbox"></i> Inbox
                            <?php if ($unread_count > 0): ?>
                                <span class="badge-red" style="font-size: 9px; padding: 1px 6px;"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <button type="button" class="tab-btn" id="tabBtnSent" onclick="showTab('sent')">
                            <i class="fa-solid fa-paper-plane"></i> Sent Messages
                        </button>
                    </div>
                </div>

                <div class="dr-card-body">
                    <!-- Inbox View -->
                    <div id="tab-inbox">
                        <?php if (empty($inbox)): ?>
                            <div style="text-align: center; padding: 48px 20px; color: #64748b;">
                                <i class="fa-regular fa-envelope-open" style="font-size: 38px; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
                                <div style="font-weight: 700; font-size: 15px; color: #0f172a;">No Messages in Inbox</div>
                                <div style="font-size: 13px; margin-top: 4px;">You are all caught up with communications from nurses, pharmacists, and staff.</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($inbox as $msg): 
                                $s_role = strtolower($msg['sender_role'] ?? 'user');
                                $role_class = 'role-' . $s_role;
                            ?>
                                <div class="msg-card <?= $msg['is_read'] ? '' : 'unread' ?>">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                                        <div style="font-weight: 800; color: #0f172a; font-size: 14px;">
                                            <?php if (!$msg['is_read']): ?>
                                                <span style="display: inline-block; width: 7px; height: 7px; background: #0284c7; border-radius: 50%; margin-right: 6px;"></span>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($msg['subject']) ?>
                                        </div>
                                        <span style="font-size: 11.5px; font-weight: 600; color: #64748b; white-space: nowrap;">
                                            <?= date('M d, h:i A', strtotime($msg['created_at'])) ?>
                                        </span>
                                    </div>

                                    <div style="display: flex; align-items: center; gap: 8px; margin: 6px 0 10px 0;">
                                        <span style="font-size: 12px; color: #475569;">From: <strong><?= htmlspecialchars($msg['sender_name']) ?></strong></span>
                                        <span class="role-pill <?= $role_class ?>"><?= ucfirst($s_role) ?></span>
                                    </div>

                                    <div class="msg-body-box">
                                        <?= nl2br(htmlspecialchars($msg['body'])) ?>
                                    </div>

                                    <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 10px;">
                                        <?php if (!$msg['is_read']): ?>
                                            <a href="messages.php?read_id=<?= $msg['id'] ?>" class="dr-btn dr-btn-outline dr-btn-sm" style="font-size: 12px; padding: 6px 12px;">
                                                <i class="fa-regular fa-circle-check"></i> Mark Read
                                            </a>
                                        <?php endif; ?>
                                        <button type="button" class="dr-btn dr-btn-primary dr-btn-sm" style="font-size: 12px; padding: 6px 12px;" onclick="replyToMessage(<?= $msg['sender_id'] ?>, '<?= htmlspecialchars(addslashes($msg['sender_name'])) ?>', '<?= htmlspecialchars(addslashes($msg['subject'])) ?>')">
                                            <i class="fa-solid fa-reply"></i> Reply
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Sent View -->
                    <div id="tab-sent" style="display: none;">
                        <?php if (empty($sent)): ?>
                            <div style="text-align: center; padding: 48px 20px; color: #64748b;">
                                <i class="fa-regular fa-paper-plane" style="font-size: 38px; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
                                <div style="font-weight: 700; font-size: 15px; color: #0f172a;">No Sent Messages</div>
                                <div style="font-size: 13px; margin-top: 4px;">Outbound clinical consult messages will appear here.</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($sent as $msg): ?>
                                <div class="msg-card">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
                                        <div style="font-weight: 800; color: #0f172a; font-size: 14px;">
                                            <?= htmlspecialchars($msg['subject']) ?>
                                        </div>
                                        <span style="font-size: 11.5px; font-weight: 600; color: #64748b; white-space: nowrap;">
                                            <?= date('M d, h:i A', strtotime($msg['created_at'])) ?>
                                        </span>
                                    </div>

                                    <div style="font-size: 12px; color: #475569; margin: 6px 0 10px 0;">
                                        To: <strong><?= $msg['receiver_name'] ? htmlspecialchars($msg['receiver_name']) : 'All ' . ucfirst($msg['recipient_role']) . 's' ?></strong>
                                    </div>

                                    <div class="msg-body-box">
                                        <?= nl2br(htmlspecialchars($msg['body'])) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right: Compose Communication -->
            <div class="dr-card">
                <div class="dr-card-header">
                    <div class="dr-card-title">
                        <i class="fa-solid fa-paper-plane" style="color: #0284c7;"></i>
                        <span>New Clinical Message</span>
                    </div>
                </div>

                <div class="dr-card-body">
                    <form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="send_message">
                        <input type="hidden" name="send_mode" id="sendModeInput" value="direct">

                        <!-- Mode Switcher -->
                        <div style="display: flex; background: #f1f5f9; padding: 4px; border-radius: 12px; margin-bottom: 18px; border: 1.5px solid #cbd5e1;">
                            <button type="button" id="modeDirectBtn" onclick="setSendMode('direct')" style="flex: 1; padding: 8px 12px; border: none; border-radius: 9px; font-size: 12.5px; font-weight: 700; cursor: pointer; transition: all 0.2s; background: #ffffff; color: #0284c7; box-shadow: 0 2px 6px rgba(0,0,0,0.06);">
                                <i class="fa-solid fa-user"></i> Direct User
                            </button>
                            <button type="button" id="modeGroupBtn" onclick="setSendMode('group')" style="flex: 1; padding: 8px 12px; border: none; border-radius: 9px; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: all 0.2s; background: transparent; color: #64748b;">
                                <i class="fa-solid fa-bullhorn"></i> Role Broadcast
                            </button>
                        </div>

                        <!-- Direct Recipient -->
                        <div id="directSelectContainer" style="margin-bottom: 16px;">
                            <label class="form-label">Recipient Staff / User</label>
                            <select name="receiver_id" id="receiverSelectInput" class="form-control">
                                <option value="">-- Choose Recipient Person --</option>
                                <?php foreach ($all_users as $u): ?>
                                    <option value="<?= $u['id'] ?>">
                                        <?= htmlspecialchars($u['name']) ?> (<?= ucfirst($u['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Role Broadcast -->
                        <div id="groupSelectContainer" style="display: none; margin-bottom: 16px;">
                            <label class="form-label">Target Broadcast Group</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                <label style="display: flex; align-items: center; gap: 8px; padding: 10px 12px; border: 1.5px solid #0284c7; background: #f0f9ff; border-radius: 10px; cursor: pointer;">
                                    <input type="radio" name="recipient_role" value="nurse" checked style="accent-color: #0284c7;">
                                    <span style="font-size: 12.5px; font-weight: 700; color: #0284c7;"><i class="fa-solid fa-user-nurse"></i> All Nurses</span>
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; padding: 10px 12px; border: 1.5px solid #cbd5e1; background: #ffffff; border-radius: 10px; cursor: pointer;">
                                    <input type="radio" name="recipient_role" value="pharmacy" style="accent-color: #0284c7;">
                                    <span style="font-size: 12.5px; font-weight: 700; color: #05cd99;"><i class="fa-solid fa-pills"></i> Pharmacy</span>
                                </label>
                            </div>
                        </div>

                        <!-- Subject -->
                        <div style="margin-bottom: 16px;">
                            <label class="form-label">Subject Line</label>
                            <input type="text" name="subject" class="form-control" placeholder="Clinical consultation summary..." required>
                        </div>

                        <!-- Body -->
                        <div style="margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label class="form-label" style="margin: 0;">Message Content</label>
                                <span id="charCounter" style="font-size: 11px; color: #94a3b8; font-weight: 600;">0 chars</span>
                            </div>
                            <textarea name="body" id="msgBodyInput" class="form-control" rows="5" placeholder="Enter clinical notes, instructions, or queries..." required oninput="document.getElementById('charCounter').innerText = this.value.length + ' chars'"></textarea>
                        </div>

                        <button type="submit" class="dr-btn dr-btn-primary" style="width: 100%; border-radius: 10px; padding: 12px;">
                            <i class="fa-solid fa-paper-plane"></i> <span id="submitBtnText">Transmit Direct Message</span>
                        </button>
                    </form>
                </div>
            </div>

        </div>

    </main>

    <!-- Scripts -->
    <script>
        function showTab(tab) {
            document.getElementById('tab-inbox').style.display = tab === 'inbox' ? 'block' : 'none';
            document.getElementById('tab-sent').style.display  = tab === 'sent'  ? 'block' : 'none';
            document.getElementById('tabBtnInbox').className = tab === 'inbox' ? 'tab-btn active' : 'tab-btn';
            document.getElementById('tabBtnSent').className  = tab === 'sent'  ? 'tab-btn active' : 'tab-btn';
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
                directBtn.style.color = '#0284c7';
                directBtn.style.boxShadow = '0 2px 6px rgba(0,0,0,0.06)';
                directBtn.style.fontWeight = '700';

                groupBtn.style.background = 'transparent';
                groupBtn.style.color = '#64748b';
                groupBtn.style.boxShadow = 'none';
                groupBtn.style.fontWeight = '600';

                directContainer.style.display = 'block';
                groupContainer.style.display = 'none';
                submitText.innerText = 'Transmit Direct Message';
            } else {
                groupBtn.style.background = '#ffffff';
                groupBtn.style.color = '#0284c7';
                groupBtn.style.boxShadow = '0 2px 6px rgba(0,0,0,0.06)';
                groupBtn.style.fontWeight = '700';

                directBtn.style.background = 'transparent';
                directBtn.style.color = '#64748b';
                directBtn.style.boxShadow = 'none';
                directBtn.style.fontWeight = '600';

                groupContainer.style.display = 'block';
                directContainer.style.display = 'none';
                submitText.innerText = 'Dispatch Broadcast';
            }
        }

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

        const sidebarToggle = document.getElementById('sidebarToggle');
        const doctorSidebar = document.getElementById('doctorSidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');

        if (sidebarToggle && doctorSidebar && sidebarBackdrop) {
            sidebarToggle.addEventListener('click', () => {
                doctorSidebar.classList.toggle('mobile-open');
                sidebarBackdrop.classList.toggle('active');
            });
            sidebarBackdrop.addEventListener('click', () => {
                doctorSidebar.classList.remove('mobile-open');
                sidebarBackdrop.classList.remove('active');
            });
        }
    </script>
</body>
</html>
