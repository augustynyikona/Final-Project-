<?php
session_start();
include '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$admin_id   = (int)$_SESSION['user_id'];
$admin_name = $_SESSION['name'] ?? 'Administrator';

$search = trim($_GET['q'] ?? '');
$action_filter = trim($_GET['action'] ?? '');

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = "(action LIKE ? OR actor_name LIKE ? OR details LIKE ? OR target_type LIKE ?)";
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like);
}
if ($action_filter !== '') {
    $where[] = "action = ?";
    $params[] = $action_filter;
}

$sql = "SELECT * FROM audit_logs";
if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY created_at DESC, id DESC LIMIT 200";

$logs = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (Throwable $e) {
    $page_error = 'Audit table not available: ' . $e->getMessage();
}

$actions = [];
try {
    $actions = $pdo->query("SELECT action, COUNT(*) AS n FROM audit_logs GROUP BY action ORDER BY action")
                   ->fetchAll();
} catch (Throwable $e) { /* ignore */ }

$total_events = 0;
try {
    $total_events = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

$unread_msg_count = (int)$pdo->query(
    "SELECT COUNT(*) FROM messages WHERE (receiver_id = {$admin_id} OR recipient_role = 'admin') AND is_read = 0"
)->fetchColumn();
$low_stock_count = (int)$pdo->query(
    "SELECT COUNT(*) FROM vaccines WHERE current_stock <= low_stock_threshold"
)->fetchColumn();

/** Small coloured pill per action family. */
function action_tint(string $action): array
{
    if (str_starts_with($action, 'staff.created') || str_starts_with($action, 'admin.created')) {
        return ['#dbeafe', '#1e40af'];
    }
    if (str_starts_with($action, 'user.deleted')) {
        return ['#fee2e2', '#991b1b'];
    }
    if (str_starts_with($action, 'patient.') || str_starts_with($action, 'guardian.')) {
        return ['#dcfce7', '#166534'];
    }
    if (str_starts_with($action, 'settings.')) {
        return ['#fef3c7', '#92400e'];
    }
    return ['#e2e8f0', '#334155'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs — VaxiCare Admin</title>
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

    <aside class="sidebar" id="adminSidebar">
        <div class="sidebar-brand-box">
            <a href="dashboard.php" class="sidebar-brand-link">
                <div class="sidebar-brand-icon">
                    <i class="fa-solid fa-shield-virus"></i>
                </div>
                <div class="sidebar-brand-info">
                    <span class="sidebar-brand-title">Vax<span>Care</span></span>
                    <span class="sidebar-brand-sub">Admin Console</span>
                </div>
            </a>
        </div>

        <nav class="sidebar-menu">
            <div class="sidebar-section-title">Overview</div>
            <a href="dashboard.php" class="sidebar-item">
                <i class="fa-solid fa-table-columns"></i> Dashboard
            </a>

            <div class="sidebar-section-title">Administration</div>
            <a href="user_management.php" class="sidebar-item">
                <i class="fa-solid fa-users-gear"></i> User Management
            </a>
            <a href="staff.php" class="sidebar-item">
                <i class="fa-solid fa-user-doctor"></i> Staff Directory
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
                <?php if ($low_stock_count > 0): ?>
                    <span class="badge-red"><?= $low_stock_count ?></span>
                <?php endif; ?>
            </a>

            <div class="sidebar-section-title">Governance</div>
            <a href="audit_logs.php" class="sidebar-item active">
                <i class="fa-solid fa-clipboard-list"></i> Audit Logs
            </a>
            <a href="reports.php" class="sidebar-item">
                <i class="fa-solid fa-chart-pie"></i> Reports &amp; Analytics
            </a>
            <a href="messages.php" class="sidebar-item">
                <i class="fa-regular fa-envelope"></i> Broadcasts
                <?php if ($unread_msg_count > 0): ?>
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

    <main class="app-main">
        <header class="app-topbar">
            <div style="display:flex; align-items:center;">
                <button type="button" class="mobile-nav-toggle" onclick="toggleSidebar(true)" aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
            <div class="topbar-left">
                <h1>Audit Logs</h1>
                <div class="topbar-date-pill">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    <span><?= number_format($total_events) ?> recorded governance event<?= $total_events === 1 ? '' : 's' ?></span>
                </div>
            </div>
            <div class="topbar-right">
                <a href="messages.php" class="topbar-icon-btn" title="System Messages">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread_msg_count > 0): ?>
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

        <?php if (!empty($page_error)): ?>
            <div style="margin:18px 0; padding:14px 20px; border-radius:14px; background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; font-size:13.5px;">
                <?= htmlspecialchars($page_error) ?>
            </div>
        <?php endif; ?>

        <form method="GET" class="filter-bar" style="display:flex; gap:12px; flex-wrap:wrap; margin:18px 0;">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Search action, actor or detail…"
                   style="flex:1; min-width:220px; height:42px; border-radius:10px; border:1.5px solid #e2e8f0; padding:0 14px; font-size:13.5px;">
            <select name="action"
                    style="height:42px; border-radius:10px; border:1.5px solid #e2e8f0; padding:0 12px; font-size:13.5px;">
                <option value="">All actions</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= htmlspecialchars($a['action']) ?>"
                        <?= $action_filter === $a['action'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['action']) ?> (<?= (int)$a['n'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit"
                    style="height:42px; padding:0 22px; border:0; border-radius:10px; background:#1B254B; color:#fff; font-weight:700; font-size:13.5px; cursor:pointer;">
                Filter
            </button>
            <?php if ($search !== '' || $action_filter !== ''): ?>
                <a href="audit_logs.php"
                   style="height:42px; display:inline-flex; align-items:center; padding:0 18px; border-radius:10px; border:1.5px solid #e2e8f0; color:#475569; text-decoration:none; font-size:13.5px; font-weight:600;">
                    Clear
                </a>
            <?php endif; ?>
        </form>

        <div style="background:#fff; border:1.5px solid #e2e8f0; border-radius:16px; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; font-size:13.5px;">
                <thead>
                    <tr style="background:#f8fafc; text-align:left; color:#475569; font-size:11.5px; letter-spacing:.6px; text-transform:uppercase;">
                        <th style="padding:14px 18px;">When</th>
                        <th style="padding:14px 18px;">Actor</th>
                        <th style="padding:14px 18px;">Action</th>
                        <th style="padding:14px 18px;">Target</th>
                        <th style="padding:14px 18px;">Detail</th>
                        <th style="padding:14px 18px;">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$logs): ?>
                        <tr>
                            <td colspan="6" style="padding:34px 18px; text-align:center; color:#94a3b8;">
                                No governance events recorded yet. Actions such as onboarding staff,
                                registering patients or changing settings will appear here.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $row): ?>
                        <?php [$bg, $fg] = action_tint($row['action']); ?>
                        <tr style="border-top:1px solid #f1f5f9;">
                            <td style="padding:13px 18px; white-space:nowrap; color:#64748b;">
                                <?= htmlspecialchars(date('d M Y, H:i', strtotime($row['created_at']))) ?>
                            </td>
                            <td style="padding:13px 18px;">
                                <?= htmlspecialchars($row['actor_name'] ?? 'system') ?>
                                <?php if (!empty($row['actor_role'])): ?>
                                    <span style="color:#94a3b8; font-size:11.5px;">(<?= htmlspecialchars($row['actor_role']) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:13px 18px;">
                                <span style="display:inline-block; padding:3px 10px; border-radius:999px; font-size:11.5px; font-weight:700; background:<?= $bg ?>; color:<?= $fg ?>;">
                                    <?= htmlspecialchars($row['action']) ?>
                                </span>
                            </td>
                            <td style="padding:13px 18px; color:#475569;">
                                <?= htmlspecialchars($row['target_type'] ?? '—') ?>
                                <?= $row['target_id'] !== null ? '#' . (int)$row['target_id'] : '' ?>
                            </td>
                            <td style="padding:13px 18px; color:#475569;">
                                <?= htmlspecialchars($row['details'] ?? '—') ?>
                            </td>
                            <td style="padding:13px 18px; color:#94a3b8; font-size:12px;">
                                <?= htmlspecialchars($row['ip_address'] ?? '—') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p style="margin:14px 0 40px; color:#94a3b8; font-size:12.5px;">
            Showing the 200 most recent events. Filtered totals reflect all matching rows.
        </p>
    </main>

    <script>
        function toggleSidebar(force) {
            const sidebar = document.getElementById('adminSidebar');
            const backdrop = document.getElementById('mobileSidebarBackdrop');
            if (!sidebar) return;
            const isOpen = typeof force === 'boolean' ? force : !sidebar.classList.contains('open');
            sidebar.classList.toggle('open', isOpen);
            if (backdrop) backdrop.classList.toggle('show', isOpen);
        }

        window.addEventListener('resize', () => {
            if (window.innerWidth > 900) toggleSidebar(false);
        });
    </script>
</body>
</html>
