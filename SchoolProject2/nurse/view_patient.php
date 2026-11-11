<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: ../auth/login.php");
    exit;
}

$nurse_id = $_SESSION['user_id'];
$nurse_name = $_SESSION['name'];

// Fetch user profile from database
$stmt = $pdo->prepare("SELECT profile_pic FROM users WHERE id = ?");
$stmt->execute([$nurse_id]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);
$profile_pic = ($user_profile && $user_profile['profile_pic']) ? '../uploads/' . $user_profile['profile_pic'] : "https://ui-avatars.com/api/?name=" . urlencode($nurse_name) . "&background=3d72e7&color=fff";


if (!isset($_GET['id'])) {
    die("Patient ID required.");
}
$child_id = $_GET['id'];
$appointment_id = isset($_GET['appointment_id']) ? $_GET['appointment_id'] : null;

// Fetch Patient Info
$stmt = $pdo->prepare("SELECT c.*, u.name as guardian_name FROM children c JOIN users u ON c.guardian_id = u.id WHERE c.id = ?");
$stmt->execute([$child_id]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    die("Patient not found.");
}

// Helper to calculate age
$bday = new DateTime($patient['date_of_birth']);
$today = new DateTime('today');
$diff = $today->diff($bday);
$age_str = ($diff->y > 0) ? $diff->y . ' yrs, ' . $diff->m . ' mos' : $diff->m . ' mos, ' . $diff->d . ' days';

// Handle Recording Vaccination
$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record') {
    csrf_guard();
    $appt_id = $_POST['appointment_id'];
    $batch = trim($_POST['batch_number']);
    $site = $_POST['site'];
    $route = $_POST['route'];
    $notes = trim($_POST['notes']);
    
    if ($appt_id) {
        $stmt = $pdo->prepare("UPDATE appointments SET status = 'completed', batch_number = ?, site = ?, route = ?, notes = ?, administered_by = ? WHERE id = ?");
        if ($stmt->execute([$batch, $site, $route, $notes, $nurse_id, $appt_id])) {
            $success = "Vaccination successfully recorded!";
        } else {
            $error = "Failed to record vaccination.";
        }
    }
}

// Fetch Immunization History (Completed)
$stmt = $pdo->prepare("
    SELECT a.*, v.name as vaccine_name 
    FROM appointments a 
    JOIN vaccines v ON a.vaccine_id = v.id 
    WHERE a.child_id = ? AND a.status = 'completed'
    ORDER BY a.appointment_date DESC
");
$stmt->execute([$child_id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Pending Appointments (Scheduled/Confirmed)
$stmt = $pdo->prepare("
    SELECT a.*, v.name as vaccine_name 
    FROM appointments a 
    JOIN vaccines v ON a.vaccine_id = v.id 
    WHERE a.child_id = ? AND a.status IN ('scheduled', 'confirmed')
    ORDER BY a.appointment_date ASC
");
$stmt->execute([$child_id]);
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'nurse' OR recipient_role = 'all') AND is_read = 0");
$stmt->execute([$nurse_id]);
$unread_msg_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Patient - VaxiCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <style>
        .patient-header-card {
            background: #1b254b;
            border-radius: 12px;
            padding: 32px;
            color: white;
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }
        .patient-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #4318FF;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            border: 4px solid rgba(255,255,255,0.2);
            z-index: 2;
        }
        .patient-info h2 { margin: 0 0 8px 0; font-size: 24px; font-weight: 800; z-index: 2; position: relative; }
        .patient-meta { display: flex; gap: 20px; font-size: 14px; color: #a3aed1; z-index: 2; position: relative; }
        .patient-meta span { display: flex; align-items: center; gap: 6px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: var(--mar-text-main); margin-bottom: 8px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid var(--mar-border); border-radius: 6px; font-family: 'Inter', sans-serif; font-size: 14px; box-sizing: border-box; }
        textarea.form-control { resize: vertical; }
    </style>
</head>
<body class="app-body">

    <aside class="sidebar">
        <div class="sidebar-brand">VaxiCare.</div>
        <nav class="sidebar-menu">
            <a href="dashboard.php" class="sidebar-item <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-border-all"></i> Dashboard
            </a>
            <a href="register_patient.php" class="sidebar-item <?= basename($_SERVER['PHP_SELF']) == 'register_patient.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-user-plus"></i> Register Patient
            </a>
            <a href="patients.php" class="sidebar-item <?= (basename($_SERVER['PHP_SELF']) == 'patients.php' || basename($_SERVER['PHP_SELF']) == 'view_patient.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-users"></i> Patient Directory
            </a>
            <a href="appointments.php" class="sidebar-item <?= basename($_SERVER['PHP_SELF']) == 'appointments.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-calendar-check"></i> Appointments
            </a>
            <a href="triage.php" class="sidebar-item <?= basename($_SERVER['PHP_SELF']) == 'triage.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-heart-pulse"></i> Triage
            </a>
            <a href="sms_reminders.php" class="sidebar-item <?= basename($_SERVER['PHP_SELF']) == 'sms_reminders.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-comment-sms"></i> SMS Reminders
            </a>
            <a href="requisition.php" class="sidebar-item <?= basename($_SERVER['PHP_SELF']) == 'requisition.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-boxes-stacked"></i> Requisitions
            </a>
            <a href="messages.php" class="sidebar-item <?= basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-envelope"></i> Messages
                <?php if($unread_msg_count > 0): ?>
                    <span class="badge-red"><?= $unread_msg_count ?></span>
                <?php endif; ?>
            </a>
            <a href="settings.php" class="sidebar-item <?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-gear"></i> Settings
            </a>
        </nav>
        <div class="sidebar-logout">
            <a href="../auth/logout.php"><i class="fa-solid fa-power-off"></i> Log out</a>
        </div>
    </aside>

    <main class="app-main">
        
        <header class="app-topbar">
            <div class="topbar-left">
                <a href="patients.php" style="color: var(--mar-text-main); text-decoration: none; font-weight: 600; font-size: 14px;"><i class="fa-solid fa-arrow-left"></i> Back to Patients</a>
            </div>
            
            <div class="topbar-right">
                <i class="fa-solid fa-magnifying-glass icon-action"></i>
                <i class="fa-regular fa-bell icon-action" id="bellIcon"></i>
                <div class="topbar-profile" style="margin-left:12px;">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($nurse_name) ?>&background=3d72e7&color=fff" alt="Profile">
                    <span>Hello, <?= htmlspecialchars(explode(' ', $nurse_name)[0] ?? 'Nurse') ?>!</span>
                </div>
            </div>
        </header>

        <?php if($success): ?>
            <div style="background: #ecfdf5; color: #10b981; padding: 16px; border-radius: 8px; font-weight: 600; margin: 24px 0;">
                <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        <?php if($error): ?>
            <div style="background: #fef2f2; color: #ef4444; padding: 16px; border-radius: 8px; font-weight: 600; margin: 24px 0;">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="patient-header-card">
            <div class="patient-avatar"><?= strtoupper(substr($patient['name'], 0, 1)) ?></div>
            <div class="patient-info">
                <h2><?= htmlspecialchars($patient['name']) ?></h2>
                <div class="patient-meta">
                    <span><i class="fa-solid fa-calendar"></i> Born: <?= date('M d, Y', strtotime($patient['date_of_birth'])) ?> (<?= $age_str ?>)</span>
                    <span><i class="fa-solid fa-venus-mars"></i> <?= ucfirst($patient['gender']) ?></span>
                    <span><i class="fa-solid fa-user-shield"></i> Guardian: <?= htmlspecialchars($patient['guardian_name']) ?></span>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
            
            <div>
                <!-- Record Vaccination Form (If appointment is selected) -->
                <?php if ($appointment_id): ?>
                    <?php
                        // Check if this appointment belongs to this child and is pending
                        $appt_stmt = $pdo->prepare("SELECT a.*, v.name as vaccine_name FROM appointments a JOIN vaccines v ON a.vaccine_id = v.id WHERE a.id = ? AND a.child_id = ? AND a.status IN ('scheduled', 'confirmed')");
                        $appt_stmt->execute([$appointment_id, $child_id]);
                        $current_appt = $appt_stmt->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <?php if ($current_appt): ?>
                        <div class="mar-card" style="margin-bottom: 24px;">
                            <div class="mar-card-header">
                                <h3 class="mar-card-title">Record Administration: <?= htmlspecialchars($current_appt['vaccine_name']) ?></h3>
                            </div>
                            <form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="record">
                                <input type="hidden" name="appointment_id" value="<?= $current_appt['id'] ?>">
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                                    <div class="form-group">
                                        <label class="form-label">Batch Number</label>
                                        <input type="text" name="batch_number" class="form-control" required placeholder="e.g. BATCH-1234">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Site of Administration</label>
                                        <select name="site" class="form-control" required>
                                            <option value="Left Deltoid">Left Deltoid</option>
                                            <option value="Right Deltoid">Right Deltoid</option>
                                            <option value="Left Thigh">Left Thigh (Anterolateral)</option>
                                            <option value="Right Thigh">Right Thigh (Anterolateral)</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Route</label>
                                        <select name="route" class="form-control" required>
                                            <option value="IM">Intramuscular (IM)</option>
                                            <option value="SC">Subcutaneous (SC)</option>
                                            <option value="Oral">Oral</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Notes / Reactions Observed</label>
                                    <textarea name="notes" class="form-control" rows="3" placeholder="Optional notes..."></textarea>
                                </div>
                                
                                <div style="margin-top: 16px;">
                                    <button type="submit" style="background: var(--mar-blue); color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer;">Save Record</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- History -->
                <div class="mar-card">
                    <div class="mar-card-header">
                        <h3 class="mar-card-title">Immunization History</h3>
                    </div>
                    <table class="mar-table">
                        <thead>
                            <tr>
                                <th>Vaccine</th>
                                <th>Date</th>
                                <th>Batch</th>
                                <th>Nurse ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--mar-text-muted); padding: 20px;">No completed vaccinations.</td></tr>
                            <?php else: ?>
                                <?php foreach ($history as $h): ?>
                                    <tr>
                                        <td style="font-weight: 600; color: var(--mar-text-main);"><i class="fa-solid fa-syringe" style="color: var(--mar-blue); margin-right: 8px;"></i> <?= htmlspecialchars($h['vaccine_name']) ?></td>
                                        <td><?= date('d M Y', strtotime($h['appointment_date'])) ?></td>
                                        <td><?= htmlspecialchars($h['batch_number']) ?></td>
                                        <td><?= $h['administered_by'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Sidebar: Upcoming -->
            <div class="mar-card">
                <div class="mar-card-header">
                    <h3 class="mar-card-title">Pending Appointments</h3>
                </div>
                <div style="padding: 16px 0;">
                    <?php if (empty($pending)): ?>
                        <div style="text-align: center; color: var(--mar-text-muted); font-size: 13px;">No upcoming appointments.</div>
                    <?php else: ?>
                        <?php foreach ($pending as $p): ?>
                            <div style="padding: 16px; border: 1px solid var(--mar-border); border-radius: 8px; margin-bottom: 12px;">
                                <div style="font-weight: 600; color: var(--mar-text-main); margin-bottom: 4px;"><?= htmlspecialchars($p['vaccine_name']) ?></div>
                                <div style="font-size: 12px; color: var(--mar-text-muted); margin-bottom: 12px;">
                                    <i class="fa-regular fa-calendar"></i> <?= date('d M Y', strtotime($p['appointment_date'])) ?> at <?= date('h:i A', strtotime($p['appointment_time'])) ?>
                                </div>
                                <a href="?id=<?= $child_id ?>&appointment_id=<?= $p['id'] ?>" style="display: block; text-align: center; background: #eef3ff; color: var(--mar-blue); padding: 8px; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none;">Administer Vaccine</a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</body>
</html>
