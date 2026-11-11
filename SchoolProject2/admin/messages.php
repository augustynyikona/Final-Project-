<?php
session_start();
include '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit;
}

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['name'];
$success = '';
$error   = '';

// Handle Send Broadcast or Direct Message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    csrf_guard();
    $recipient_role = $_POST['recipient_role'] ?? 'all';
    $receiver_id    = isset($_POST['receiver_id']) && !empty($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : NULL;
    $subject        = trim($_POST['subject'] ?? '');
    $body           = trim($_POST['body'] ?? '');

    if (empty($subject) || empty($body)) {
        $error = "Subject and message body are required.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, recipient_role, receiver_id, subject, body, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if ($stmt->execute([$admin_id, $recipient_role, $receiver_id, $subject, $body])) {
            $success = "Broadcast message sent successfully!";
        } else {
            $error = "Failed to send message.";
        }
    }
}

// Fetch Inbox
$stmt = $pdo->prepare("
    SELECT m.*, u.name as sender_name, u.role as sender_role
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.receiver_id = ? OR m.recipient_role = 'admin'
    ORDER BY m.created_at DESC
");
$stmt->execute([$admin_id]);
$inbox = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Sent
$stmt = $pdo->prepare("
    SELECT m.*, u.name as receiver_name
    FROM messages m
    LEFT JOIN users u ON m.receiver_id = u.id
    WHERE m.sender_id = ?
    ORDER BY m.created_at DESC
");
$stmt->execute([$admin_id]);
$sent = $stmt->fetchAll(PDO::FETCH_ASSOC);

// User list for direct recipient select
$all_users = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('doctor', 'nurse', 'pharmacy', 'guardian') ORDER BY role ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

$low_stock_count = $pdo->query("SELECT COUNT(*) FROM vaccines WHERE current_stock <= low_stock_threshold")->fetchColumn();
$unread_count    = count(array_filter($inbox, fn($m) => !$m['is_read']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Broadcasts &amp; Messaging — VaxCare Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/admin_theme.css">
</head>
<body class="app-body">

    <!-- Mobile Backdrop Overlay -->
    <div class="mobile-sidebar-backdrop" id="mobileSidebarBackdrop" onclick="toggleSidebar(false)"></div>

    <!-- ===== SIDEBAR (ELEVATED VAXICARE ADMIN) ===== -->
    <aside class="sidebar" id="adminSidebar">
        <div class="sidebar-brand-box">
            <a href="dashboard.php" class="sidebar-brand-link">
                <div class="sidebar-brand-icon"><i class="fa-solid fa-shield-heart"></i></div>
                <div class="sidebar-brand-info">
                    <span class="sidebar-brand-title">Vax<span>Care</span></span>
                    <span class="sidebar-brand-sub">Admin Console</span>
                </div>
            </a>
        </div>
        
        <nav class="sidebar-menu">
            <div class="sidebar-section-title">Overview</div>
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-border-all"></i> Dashboard
            </a>

            <div class="sidebar-section-title">Administration</div>
            <a href="user_management.php" class="sidebar-item">
                <i class="fa-solid fa-users-gear"></i> User Management
            </a>
            <a href="staff.php" class="sidebar-item">
                <i class="fa-solid fa-users"></i> Staff Directory
            </a>
            <a href="add_staff.php" class="sidebar-item">
                <i class="fa-solid fa-user-plus"></i> Onboard Staff
            </a>

            <div class="sidebar-section-title">Clinical &amp; Stock</div>
            <a href="patients.php" class="sidebar-item">
                <i class="fa-solid fa-child-reaching"></i> Master Patients
            </a>
            <a href="inventory.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-stacked"></i> Vaccine Inventory
                <?php if($low_stock_count > 0): ?>
                    <span class="badge-red"><?= $low_stock_count ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Governance</div>
            <a href="audit_logs.php" class="sidebar-item">
                <i class="fa-solid fa-clipboard-list"></i> Audit Logs
            </a>
            <a href="reports.php" class="sidebar-item">
                <i class="fa-solid fa-chart-pie"></i> Reports &amp; Analytics
            </a>
            <a href="messages.php" class="sidebar-item active">
                <i class="fa-regular fa-envelope"></i> Broadcasts
                <?php if($unread_count > 0): ?>
                    <span class="badge-red"><?= $unread_count ?></span>
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

        <!-- Topbar -->
        <header class="app-topbar">
            <div class="topbar-left">
                <h1>Hospital Broadcasts &amp; Communications</h1>
                <div class="topbar-date-pill">
                    <i class="fa-solid fa-bullhorn" style="color:var(--admin-secondary);"></i>
                    <span>System Announcements, Direct Staff Messages &amp; Guardian Notifications</span>
                </div>
            </div>
            <div class="topbar-right">
                <a href="messages.php" class="topbar-icon-btn" title="System Messages">
                    <i class="fa-regular fa-bell"></i>
                    <?php if($unread_count > 0): ?>
                        <span class="badge-dot"></span>
                    <?php endif; ?>
                </a>
                <div class="topbar-profile">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=1B254B&color=fff&bold=true" alt="Admin Avatar">
                    <div class="topbar-profile-info">
                        <span class="topbar-profile-name"><?= htmlspecialchars($admin_name) ?></span>
                        <span class="topbar-profile-role">Super Administrator</span>
                    </div>
                </div>
            </div>
        </header>

        <?php if($error): ?>
            <div class="admin-alert" style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="admin-alert" style="background:#ecfdf5; border:1px solid #a7f3d0; color:#047857; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <div class="broadcast-grid">

            <!-- Left: Messages Inbox & Sent Feed -->
            <div class="mar-card" style="box-shadow:var(--adm-shadow-sm); border:1px solid var(--adm-border);">
                <div class="mar-card-header" style="border-bottom: 1px solid var(--adm-border); padding-bottom: 14px; margin-bottom: 18px; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div class="mar-card-title" style="display:flex; align-items:center; gap:8px;">
                            <div style="width:32px; height:32px; border-radius:8px; background:var(--adm-blue-light); color:var(--adm-blue); display:flex; align-items:center; justify-content:center; font-size:14px;">
                                <i class="fa-solid fa-inbox"></i>
                            </div>
                            <span>Communication Threads</span>
                        </div>
                        <div class="mar-card-subtitle" style="margin-top:2px;">Official notifications sent to and received from clinical staff</div>
                    </div>
                    <div class="segmented-toggle" style="width:auto;">
                        <button class="segmented-btn active" id="tabBtnInbox" onclick="showTab('inbox')">
                            <i class="fa-solid fa-inbox"></i> Inbox 
                            <?php if($unread_count > 0): ?>
                                <span class="badge-red" style="font-size:10px; padding:2px 6px;"><?= $unread_count ?></span>
                            <?php endif; ?>
                        </button>
                        <button class="segmented-btn" id="tabBtnSent" onclick="showTab('sent')">
                            <i class="fa-solid fa-paper-plane"></i> Sent
                        </button>
                    </div>
                </div>

                <!-- Inbox Feed -->
                <div id="tab-inbox">
                    <?php if(empty($inbox)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fa-regular fa-envelope-open"></i></div>
                            <div class="empty-state-title">Your inbox is clear</div>
                            <div class="empty-state-sub">Incoming messages from doctors, nurses, and guardians will appear here.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach($inbox as $msg): ?>
                            <div class="thread-card <?= !$msg['is_read'] ? 'unread' : '' ?>">
                                <div class="thread-header">
                                    <div class="thread-subject">
                                        <?php if(!$msg['is_read']): ?>
                                            <span class="thread-unread-dot" title="Unread Notice"></span>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($msg['subject']) ?>
                                    </div>
                                    <span class="text-xs text-muted" style="font-weight:600; white-space:nowrap;">
                                        <i class="fa-regular fa-clock" style="margin-right:3px;"></i> <?= date('M d, H:i', strtotime($msg['created_at'])) ?>
                                    </span>
                                </div>
                                <div class="thread-meta">
                                    <span>From: <strong style="color:var(--adm-navy);"><?= htmlspecialchars($msg['sender_name']) ?></strong></span>
                                    <span class="role-badge role-badge-<?= strtolower($msg['sender_role'] ?? 'admin') ?>" style="font-size:10px; padding:2px 8px;">
                                        <?= ucfirst($msg['sender_role'] ?? 'user') ?>
                                    </span>
                                </div>
                                <div class="thread-body">
                                    <?= nl2br(htmlspecialchars($msg['body'])) ?>
                                </div>
                                <div style="margin-top:12px; display:flex; gap:8px; justify-content:flex-end;">
                                    <button class="admin-btn admin-btn-outline admin-btn-sm" onclick="replyToMessage(<?= $msg['sender_id'] ?>, '<?= htmlspecialchars(addslashes($msg['sender_name'])) ?>', '<?= htmlspecialchars(addslashes($msg['subject'])) ?>')">
                                        <i class="fa-solid fa-reply"></i> Direct Reply
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Sent Feed -->
                <div id="tab-sent" style="display:none;">
                    <?php if(empty($sent)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="fa-regular fa-paper-plane"></i></div>
                            <div class="empty-state-title">No sent broadcasts</div>
                            <div class="empty-state-sub">Broadcasts and direct messages you send will be recorded in this outbox.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach($sent as $msg): ?>
                            <div class="thread-card" style="border-left:4px solid var(--adm-green);">
                                <div class="thread-header">
                                    <div class="thread-subject">
                                        <?= htmlspecialchars($msg['subject']) ?>
                                    </div>
                                    <span class="text-xs text-muted" style="font-weight:600; white-space:nowrap;">
                                        <i class="fa-regular fa-clock" style="margin-right:3px;"></i> <?= date('M d, H:i', strtotime($msg['created_at'])) ?>
                                    </span>
                                </div>
                                <div class="thread-meta">
                                    <span>Delivered To: <strong style="color:var(--adm-navy);"><?= $msg['receiver_name'] ? htmlspecialchars($msg['receiver_name']) : 'Department: ' . ucfirst($msg['recipient_role']) ?></strong></span>
                                    <span class="role-badge" style="background:var(--adm-green-light); color:var(--adm-green); border:1px solid var(--adm-green-border); font-size:10px; padding:2px 8px;">
                                        <i class="fa-solid fa-check"></i> Delivered
                                    </span>
                                </div>
                                <div class="thread-body">
                                    <?= nl2br(htmlspecialchars($msg['body'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Right: Compose Broadcast / Direct Message -->
            <div class="mar-card" style="box-shadow:var(--adm-shadow-md); border:1px solid var(--adm-border);">
                <div class="mar-card-header" style="border-bottom: 1px solid var(--adm-border); padding-bottom: 14px; margin-bottom: 18px;">
                    <div>
                        <div class="mar-card-title" style="display:flex; align-items:center; gap:8px;">
                            <div style="width:32px; height:32px; border-radius:8px; background:var(--adm-blue-light); color:var(--adm-blue); display:flex; align-items:center; justify-content:center; font-size:14px;">
                                <i class="fa-solid fa-paper-plane"></i>
                            </div>
                            <span>Dispatch Communication</span>
                        </div>
                        <div class="mar-card-subtitle" style="margin-top:2px;">Broadcast to departments or message a specific user</div>
                    </div>
                </div>

                <form method="POST" id="broadcastForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="send_message">
                    <input type="hidden" name="send_mode" id="sendModeInput" value="group">
                    
                    <!-- Segmented Mode Switcher -->
                    <div class="segmented-toggle" style="margin-bottom:20px;">
                        <button type="button" id="modeGroupBtn" class="segmented-btn active" onclick="setSendMode('group')">
                            <i class="fa-solid fa-bullhorn"></i> Role Broadcast
                        </button>
                        <button type="button" id="modeDirectBtn" class="segmented-btn" onclick="setSendMode('direct')">
                            <i class="fa-solid fa-user"></i> Direct User
                        </button>
                    </div>

                    <!-- Target Group Radio Cards (Group Mode) -->
                    <div id="groupSelectContainer" style="margin-bottom:20px;">
                        <label class="form-label" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--adm-text-secondary); margin-bottom:10px; display:block;">
                            Broadcast Target Group
                        </label>
                        <div class="audience-grid">
                            <label class="audience-card selected" id="card-role-all">
                                <input type="radio" name="recipient_role" value="all" checked onchange="updateAudienceCards(this)">
                                <div class="audience-icon" style="background:#e0e7ff; color:#4318ff;">
                                    <i class="fa-solid fa-globe"></i>
                                </div>
                                <div class="audience-info">
                                    <span class="audience-title">All Users</span>
                                    <span class="audience-sub">Entire Facility</span>
                                </div>
                            </label>
                            
                            <label class="audience-card" id="card-role-doctor">
                                <input type="radio" name="recipient_role" value="doctor" onchange="updateAudienceCards(this)">
                                <div class="audience-icon" style="background:#e0f2fe; color:#0284c7;">
                                    <i class="fa-solid fa-user-doctor"></i>
                                </div>
                                <div class="audience-info">
                                    <span class="audience-title">Doctors</span>
                                    <span class="audience-sub">Clinical Staff</span>
                                </div>
                            </label>

                            <label class="audience-card" id="card-role-nurse">
                                <input type="radio" name="recipient_role" value="nurse" onchange="updateAudienceCards(this)">
                                <div class="audience-icon" style="background:#fef3c7; color:#d97706;">
                                    <i class="fa-solid fa-user-nurse"></i>
                                </div>
                                <div class="audience-info">
                                    <span class="audience-title">Nurses</span>
                                    <span class="audience-sub">Vaccine Team</span>
                                </div>
                            </label>

                            <label class="audience-card" id="card-role-pharmacy">
                                <input type="radio" name="recipient_role" value="pharmacy" onchange="updateAudienceCards(this)">
                                <div class="audience-icon" style="background:#dcfce7; color:#05cd99;">
                                    <i class="fa-solid fa-pills"></i>
                                </div>
                                <div class="audience-info">
                                    <span class="audience-title">Pharmacists</span>
                                    <span class="audience-sub">Cold Chain &amp; Stock</span>
                                </div>
                            </label>

                            <label class="audience-card" id="card-role-guardian" style="grid-column: span 2;">
                                <input type="radio" name="recipient_role" value="guardian" onchange="updateAudienceCards(this)">
                                <div class="audience-icon" style="background:#f3e8ff; color:#7c3aed;">
                                    <i class="fa-solid fa-hospital-user"></i>
                                </div>
                                <div class="audience-info">
                                    <span class="audience-title">Patient Guardians</span>
                                    <span class="audience-sub">Parents &amp; Primary Child Caregivers</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Direct Message Dropdown (Direct Mode) -->
                    <div id="directSelectContainer" style="display:none; margin-bottom:20px;">
                        <label class="form-label" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--adm-text-secondary); margin-bottom:8px; display:block;">
                            Select Recipient
                        </label>
                        <div class="input-field-group">
                            <select name="receiver_id" id="receiverSelectInput" class="modern-field" style="height:44px; padding-left:14px;">
                                <option value="">-- Choose Staff Member or Guardian --</option>
                                <?php foreach($all_users as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?> (<?= ucfirst($u['role']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Subject Input -->
                    <div class="form-group" style="margin-bottom:18px;">
                        <label class="form-label" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--adm-text-secondary); margin-bottom:8px; display:block;">
                            Subject Line
                        </label>
                        <div class="input-field-group">
                            <input type="text" name="subject" class="modern-field" placeholder="e.g. Clinical Policy Update, Cold Chain Audit..." required>
                            <i class="fa-solid fa-heading field-icon"></i>
                        </div>
                    </div>

                    <!-- Message Body Textarea -->
                    <div class="form-group" style="margin-bottom:22px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <label class="form-label" style="font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:var(--adm-text-secondary); margin:0;">
                                Message Content
                            </label>
                            <span id="charCounter" style="font-size:11px; color:var(--adm-text-muted); font-weight:700;">0 chars</span>
                        </div>
                        <textarea name="body" id="msgBodyInput" class="filter-input" rows="5" placeholder="Type official broadcast instructions..." required style="width:100%; height:auto; padding:12px 14px; line-height:1.6; border-radius:11px; border:1.5px solid var(--adm-border);" oninput="document.getElementById('charCounter').innerText = this.value.length + ' chars'"></textarea>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" id="submitBtn" class="admin-btn admin-btn-primary" style="width:100%; justify-content:center; padding:13px; font-size:14px; font-weight:700; border-radius:12px;">
                        <i class="fa-solid fa-paper-plane"></i> <span id="submitBtnText">Dispatch Broadcast</span>
                    </button>
                </form>
            </div>

        </div>

    </main>

    <script>
        function showTab(tab) {
            document.getElementById('tab-inbox').style.display = tab === 'inbox' ? 'block' : 'none';
            document.getElementById('tab-sent').style.display  = tab === 'sent'  ? 'block' : 'none';
            
            const btnInbox = document.getElementById('tabBtnInbox');
            const btnSent  = document.getElementById('tabBtnSent');
            
            if (tab === 'inbox') {
                btnInbox.classList.add('active');
                btnSent.classList.remove('active');
            } else {
                btnSent.classList.add('active');
                btnInbox.classList.remove('active');
            }
        }

        function setSendMode(mode) {
            document.getElementById('sendModeInput').value = mode;
            const groupBtn = document.getElementById('modeGroupBtn');
            const directBtn = document.getElementById('modeDirectBtn');
            const groupContainer = document.getElementById('groupSelectContainer');
            const directContainer = document.getElementById('directSelectContainer');
            const submitText = document.getElementById('submitBtnText');

            if (mode === 'group') {
                groupBtn.classList.add('active');
                directBtn.classList.remove('active');
                groupContainer.style.display = 'block';
                directContainer.style.display = 'none';
                submitText.innerText = 'Dispatch Broadcast';
            } else {
                directBtn.classList.add('active');
                groupBtn.classList.remove('active');
                groupContainer.style.display = 'none';
                directContainer.style.display = 'block';
                submitText.innerText = 'Send Direct Message';
            }
        }

        function updateAudienceCards(radio) {
            document.querySelectorAll('.audience-card').forEach(card => card.classList.remove('selected'));
            if (radio && radio.checked) {
                const parent = radio.closest('.audience-card');
                if (parent) parent.classList.add('selected');
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
    </script>
    <script src="../js/notifications.js"></script>
</body>
</html>
