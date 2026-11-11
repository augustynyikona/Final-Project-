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

// Fetch nurse profile
$stmt = $pdo->prepare("SELECT name, profile_pic FROM users WHERE id = ?");
$stmt->execute([$nurse_id]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user_profile && !empty($user_profile['name'])) {
    $nurse_name = $user_profile['name'];
}

$profile_pic = ($user_profile && !empty($user_profile['profile_pic']))
    ? '../uploads/' . htmlspecialchars($user_profile['profile_pic'])
    : "https://ui-avatars.com/api/?name=" . urlencode($nurse_name) . "&background=2563eb&color=fff&bold=true";

$message = '';
$error = '';

// Handle Manual Referral to Doctor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refer_patient') {
    csrf_guard();
    $child_id = (int) $_POST['child_id'];
    $reason = trim($_POST['referral_reason']);

    if ($child_id && !empty($reason)) {
        // Find Doctor on duty
        $doc_stmt = $pdo->query("SELECT id, name FROM users WHERE role = 'doctor' AND is_on_duty = 1 LIMIT 1");
        $doctor = $doc_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doctor) {
            // Fallback to any doctor
            $doc_stmt = $pdo->query("SELECT id, name FROM users WHERE role = 'doctor' LIMIT 1");
            $doctor = $doc_stmt->fetch(PDO::FETCH_ASSOC);
        }

        $doc_id = $doctor ? $doctor['id'] : null;
        $doc_name = $doctor ? $doctor['name'] : 'On-Duty Physician';

        $stmt = $pdo->prepare("INSERT INTO triage_referrals (child_id, nurse_id, doctor_id, reason, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
        if ($stmt->execute([$child_id, $nurse_id, $doc_id, $reason])) {
            $message = "Patient successfully referred to {$doc_name} for immediate evaluation!";
        } else {
            $error = "Failed to send referral to doctor.";
        }
    } else {
        $error = "Please provide a clinical reason for doctor referral.";
    }
}

// Handle Log Vitals Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_vitals') {
    csrf_guard();
    $child_id = (int) ($_POST['child_id'] ?? 0);
    $temperature = floatval($_POST['temperature'] ?? 0);
    $heart_rate = intval($_POST['heart_rate'] ?? 0);
    $weight = floatval($_POST['weight'] ?? 0);
    $symptoms = trim($_POST['symptoms'] ?? '');

    $db_temp = $temperature > 0 ? $temperature : null;
    $db_hr = $heart_rate > 0 ? $heart_rate : null;
    $db_weight = $weight > 0 ? $weight : null;

    if ($child_id && $db_temp !== null) {
        $is_critical = 0;
        $reasons = [];

        if ($db_temp >= 38.0) {
            $is_critical = 1;
            $reasons[] = "High fever ({$db_temp}&deg;C)";
        }
        if ($db_hr > 160 || ($db_hr < 60 && $db_hr > 0)) {
            $is_critical = 1;
            $reasons[] = "Abnormal heart rate ({$db_hr} bpm)";
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO clinical_vitals (child_id, recorded_by_id, temperature, heart_rate, weight_kg, symptoms, is_critical) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$child_id, $nurse_id, $db_temp, $db_hr, $db_weight, $symptoms, $is_critical]);

            if ($is_critical) {
                $reason_str = implode(", ", $reasons);
                if ($symptoms)
                    $reason_str .= " | Symptoms: " . $symptoms;

                $doc_stmt = $pdo->query("SELECT id, name FROM users WHERE role = 'doctor' AND is_on_duty = 1 LIMIT 1");
                $doctor = $doc_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$doctor) {
                    $doc_stmt = $pdo->query("SELECT id, name FROM users WHERE role = 'doctor' LIMIT 1");
                    $doctor = $doc_stmt->fetch(PDO::FETCH_ASSOC);
                }

                $doc_id = $doctor ? $doctor['id'] : null;
                $doc_name = $doctor ? $doctor['name'] : 'Doctor';

                $alert_stmt = $pdo->prepare("INSERT INTO triage_referrals (child_id, nurse_id, doctor_id, reason, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
                $alert_stmt->execute([$child_id, $nurse_id, $doc_id, $reason_str]);

                $message = "Vitals recorded. CRITICAL ALERT triggered and automatically routed to {$doc_name}!";
            } else {
                $message = "Vitals successfully logged. Patient condition evaluated as nominal.";
            }

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Database Error: " . $e->getMessage();
        }
    } else {
        $error = "Please select a patient and provide body temperature.";
    }
}

// Get arrived patients for today awaiting triage
$stmt = $pdo->prepare("
    SELECT a.id as appointment_id, c.id as child_id, c.name, c.date_of_birth, v.name as vaccine_name, a.status as appt_status
    FROM appointments a
    JOIN children c ON a.child_id = c.id
    LEFT JOIN vaccines v ON a.vaccine_id = v.id
    WHERE a.appointment_date = CURRENT_DATE AND a.status IN ('arrived', 'confirmed', 'scheduled')
    ORDER BY a.appointment_time ASC
");
$stmt->execute();
$patients_today = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent vitals logged today
$stmt = $pdo->prepare("
    SELECT cv.*, c.name as child_name, c.id as child_id, c.date_of_birth
    FROM clinical_vitals cv
    JOIN children c ON cv.child_id = c.id
    WHERE DATE(cv.created_at) = CURRENT_DATE
    ORDER BY cv.created_at DESC
");
$stmt->execute();
$recent_vitals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPI metrics
$total_triaged_today = count($recent_vitals);
$critical_count = 0;
$normal_count = 0;
foreach ($recent_vitals as $rv) {
    if ($rv['is_critical']) {
        $critical_count++;
    } else {
        $normal_count++;
    }
}
$referrals_today = (int) $pdo->query("SELECT COUNT(*) FROM triage_referrals WHERE DATE(created_at) = CURRENT_DATE")->fetchColumn();

// Active Doctor on duty
$on_duty_doctor = $pdo->query("SELECT name FROM users WHERE role = 'doctor' AND is_on_duty = 1 LIMIT 1")->fetchColumn();
if (!$on_duty_doctor) {
    $on_duty_doctor = $pdo->query("SELECT name FROM users WHERE role = 'doctor' LIMIT 1")->fetchColumn() ?: 'Dr. On Duty';
}

// Unread messages
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR recipient_role = 'nurse') AND is_read = 0");
$stmt->execute([$nurse_id]);
$unread_msg_count = (int) $stmt->fetchColumn();

// Pending appointments badge
$stmt = $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURRENT_DATE AND status != 'completed' AND status != 'cancelled'");
$pending_today = (int) $stmt->fetchColumn();

function calculateAge($dob)
{
    if (!$dob)
        return 'N/A';
    $bday = new DateTime($dob);
    $today = new DateTime('today');
    $diff = $today->diff($bday);
    if ($diff->y > 0)
        return $diff->y . 'y ' . $diff->m . 'm';
    if ($diff->m > 0)
        return $diff->m . 'm ' . $diff->d . 'd';
    return $diff->d . ' days';
}

$current_date = date('d M, Y');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Triage &amp; Vitals Assessment — VaxiCare</title>
    <meta name="description"
        content="Pediatric clinical vitals logging, physiological evaluation, fever detection, and direct physician referral desk.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/mar_tech.css">
    <link rel="stylesheet" href="../css/nurse_theme.css">
    <style>
        .triage-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        @media (max-width: 1024px) {
            .triage-grid {
                grid-template-columns: 1fr;
            }
        }

        .temp-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 800;
        }

        .temp-normal {
            background: #ecfdf5;
            color: #059669;
            border: 1px solid #a7f3d0;
        }

        .temp-fever {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fca5a5;
            animation: pulseFever 2s infinite;
        }

        @keyframes pulseFever {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.75;
            }
        }

        .doctor-on-duty-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            font-size: 12.5px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 20px;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(6px);
            z-index: 999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeIn 0.2s ease-out;
        }

        .modal-overlay.active {
            display: flex;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal-box {
            background: #ffffff;
            border-radius: 22px;
            width: 100%;
            max-width: 660px;
            padding: 30px 34px;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
            position: relative;
            max-height: 92vh;
            overflow-y: auto;
            border: 1px solid rgba(226, 232, 240, 0.8);
            animation: slideUp 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from {
                transform: translateY(16px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .vital-sign-card {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px 18px;
            transition: all 0.2s;
        }

        .vital-sign-card:focus-within {
            background: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .vital-input-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        .vital-input-unit {
            position: absolute;
            right: 14px;
            font-size: 13px;
            font-weight: 700;
            color: #64748b;
            pointer-events: none;
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 6px;
        }

        .stepper-btn-group {
            display: inline-flex;
            align-items: center;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            overflow: hidden;
            background: #ffffff;
        }

        .stepper-btn {
            background: #ffffff;
            border: none;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            cursor: pointer;
            transition: background 0.15s;
        }

        .stepper-btn:hover {
            background: #eff6ff;
            color: #2563eb;
        }

        .stepper-btn:active {
            background: #dbeafe;
        }

        .stepper-divider {
            width: 1px;
            height: 16px;
            background: #e2e8f0;
        }

        .symptom-tag {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 6px 13px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s cubic-bezier(0.16, 1, 0.3, 1);
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .symptom-tag:hover {
            background: #eff6ff;
            color: #2563eb;
            border-color: #93c5fd;
            transform: translateY(-1px);
        }

        .symptom-tag.active {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
        }

        .temp-preset-pill {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 4px 11px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 700;
            color: #334155;
            cursor: pointer;
            transition: all 0.15s;
        }

        .temp-preset-pill:hover {
            border-color: #2563eb;
            color: #2563eb;
            background: #eff6ff;
        }

        .temp-preset-pill.preset-fever {
            color: #dc2626;
            border-color: #fca5a5;
            background: #fef2f2;
        }

        .temp-preset-pill.preset-fever:hover {
            background: #fee2e2;
        }

        .modal-close {
            position: absolute;
            top: 22px;
            right: 22px;
            background: #f1f5f9;
            border: none;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: #fee2e2;
            color: #dc2626;
            transform: rotate(90deg);
        }

        transition: all 0.15s;
        }

        .modal-close:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .field-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 16px;
        }

        .field-group label {
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
                <?php if ($pending_today > 0): ?>
                    <span class="badge-red" style="background:#2563eb;"><?= $pending_today ?></span>
                <?php endif; ?>
            </a>
            <a href="triage.php" class="sidebar-item active">
                <i class="fa-solid fa-heart-pulse"></i> Triage Vitals
            </a>

            <div class="sidebar-section-title">Supplies &amp; Comms</div>
            <a href="sms_reminders.php" class="sidebar-item">
                <i class="fa-solid fa-comment-sms"></i> SMS Reminders
            </a>
            <a href="requisition.php" class="sidebar-item">
                <i class="fa-solid fa-boxes-stacked"></i> Requisitions
            </a>

            <div class="sidebar-section-title">Governance &amp; Profile</div>
            <a href="messages.php" class="sidebar-item">
                <i class="fa-solid fa-envelope"></i> Messages
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

    <!-- ===== MAIN CONTENT ===== -->
    <main class="app-main">

        <!-- ===== TOPBAR (WITH INLINE BREADCRUMBS & CENTERED DATE) ===== -->
        <header class="app-topbar">
            <div style="display:flex; align-items:center;">
                <button type="button" class="mobile-nav-toggle" onclick="toggleSidebar(true)"
                    aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-left"
                    style="display:flex; flex-direction:row; align-items:center; gap:14px; flex-wrap:wrap;">
                    <h1>Clinical Triage</h1>
                    <div class="topbar-breadcrumbs" style="display:flex; align-items:center; gap:6px; margin:0;">
                        <span>Pediatric Care</span>
                        <i class="fa-solid fa-chevron-right" style="font-size:9px;"></i>
                        <span class="current">Vitals &amp; Referrals</span>
                    </div>
                </div>
            </div>

            <!-- Centered Date & Ward Context -->
            <div class="topbar-center" style="display:flex; align-items:center; justify-content:center;">
                <div class="date-range">
                    <i class="fa-regular fa-calendar"></i> <?= $current_date ?> &bull; Pediatrics &amp; Immunization
                    Ward
                </div>
            </div>

            <div class="topbar-right">
                <div class="doctor-on-duty-pill">
                    <i class="fa-solid fa-stethoscope" style="color:#2563eb;"></i>
                    <span>Doctor on Duty: <strong><?= htmlspecialchars($on_duty_doctor) ?></strong></span>
                </div>

                <button type="button" class="nr-btn nr-btn-primary nr-btn-sm" onclick="openVitalsModal()">
                    <i class="fa-solid fa-heart-pulse"></i> + Log Vitals
                </button>

                <div class="topbar-control-pill">
                    <a href="messages.php" class="topbar-action-btn" title="Messages">
                        <i class="fa-regular fa-bell"></i>
                        <?php if ($unread_msg_count > 0): ?>
                            <span class="notif-ping"></span>
                        <?php endif; ?>
                    </a>

                    <a href="settings.php" class="topbar-action-btn" title="Settings">
                        <i class="fa-solid fa-gear"></i>
                    </a>

                    <div class="topbar-divider"></div>

                    <div class="topbar-profile">
                        <div class="topbar-avatar-wrap">
                            <img src="<?= $profile_pic ?>" alt="<?= htmlspecialchars($nurse_name) ?>">
                            <span class="avatar-status-dot"></span>
                        </div>
                        <div class="topbar-profile-info">
                            <span class="topbar-profile-name"><?= htmlspecialchars($nurse_name) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- ===== ENLARGED TOP KPI CARDS ===== -->
        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 26px;">
            <div class="stat-card card-blue" style="padding: 22px 24px; min-height: 110px;">
                <div class="stat-icon icon-blue"
                    style="width: 52px; height: 52px; font-size: 22px; border-radius: 14px;"><i
                        class="fa-solid fa-stethoscope"></i></div>
                <div class="stat-info">
                    <div class="stat-label" style="font-size: 12.5px; font-weight: 700;">Triaged Today</div>
                    <div class="stat-value" style="font-size: 28px; line-height: 1.15;">
                        <?= number_format($total_triaged_today) ?></div>
                    <div class="stat-trend trend-neutral" style="font-size: 12px; margin-top: 4px;">
                        <i class="fa-solid fa-chart-line"></i> Total vitals logged
                    </div>
                </div>
            </div>

            <div class="stat-card card-green" style="padding: 22px 24px; min-height: 110px;">
                <div class="stat-icon icon-green"
                    style="width: 52px; height: 52px; font-size: 22px; border-radius: 14px;"><i
                        class="fa-solid fa-circle-check"></i></div>
                <div class="stat-info">
                    <div class="stat-label" style="font-size: 12.5px; font-weight: 700;">Cleared for Vaccine</div>
                    <div class="stat-value" style="font-size: 28px; line-height: 1.15;">
                        <?= number_format($normal_count) ?></div>
                    <div class="stat-trend trend-up" style="font-size: 12px; margin-top: 4px;">
                        <i class="fa-solid fa-temperature-arrow-down"></i> Normal physiology
                    </div>
                </div>
            </div>

            <div class="stat-card card-amber" style="padding: 22px 24px; min-height: 110px;">
                <div class="stat-icon icon-rose"
                    style="width: 52px; height: 52px; font-size: 22px; border-radius: 14px;"><i
                        class="fa-solid fa-triangle-exclamation"></i></div>
                <div class="stat-info">
                    <div class="stat-label" style="font-size: 12.5px; font-weight: 700;">Fever / Critical Alerts</div>
                    <div class="stat-value" style="font-size: 28px; line-height: 1.15;">
                        <?= number_format($critical_count) ?></div>
                    <div class="stat-trend <?= $critical_count > 0 ? 'trend-down' : 'trend-up' ?>"
                        style="font-size: 12px; margin-top: 4px;">
                        <i class="fa-solid fa-fire"></i>
                        <?= $critical_count > 0 ? 'Urgent review flagged' : 'Zero criticals' ?>
                    </div>
                </div>
            </div>

            <div class="stat-card card-purple" style="padding: 22px 24px; min-height: 110px;">
                <div class="stat-icon icon-purple"
                    style="width: 52px; height: 52px; font-size: 22px; border-radius: 14px;"><i
                        class="fa-solid fa-user-doctor"></i></div>
                <div class="stat-info">
                    <div class="stat-label" style="font-size: 12.5px; font-weight: 700;">Doctor Referrals</div>
                    <div class="stat-value" style="font-size: 28px; line-height: 1.15;">
                        <?= number_format($referrals_today) ?></div>
                    <div class="stat-trend trend-neutral" style="font-size: 12px; margin-top: 4px;">
                        <i class="fa-solid fa-paper-plane"></i> Sent to physician
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Notices -->
        <?php if ($message): ?>
            <div
                style="background: <?= strpos($message, 'CRITICAL') !== false ? '#fee2e2' : '#ecfdf5' ?>; border: 1px solid <?= strpos($message, 'CRITICAL') !== false ? '#fca5a5' : '#a7f3d0' ?>; color: <?= strpos($message, 'CRITICAL') !== false ? '#991b1b' : '#065f46' ?>; padding: 16px 20px; border-radius: 14px; font-weight: 600; margin-bottom: 24px; display: flex; align-items: center; gap: 12px;">
                <i class="fa-solid <?= strpos($message, 'CRITICAL') !== false ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"
                    style="font-size: 18px;"></i>
                <div><?= htmlspecialchars($message) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div
                style="background: #fef2e2; border: 1px solid #fca5a5; color: #991b1b; padding: 16px 20px; border-radius: 14px; font-weight: 600; margin-bottom: 24px; display: flex; align-items: center; gap: 12px;">
                <i class="fa-solid fa-circle-exclamation" style="color:#ef4444; font-size: 18px;"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- ===== MASTER 2-COLUMN CLINICAL LAYOUT ===== -->
        <div class="triage-grid">

            <!-- LEFT: Arrived Infants Waiting for Triage -->
            <div class="mar-card" style="margin-bottom:0;">
                <div class="mar-card-header">
                    <div>
                        <h3 class="mar-card-title">
                            <i class="fa-solid fa-hourglass-half" style="color:var(--nr-amber);"></i>
                            Arrived Infants Awaiting Triage
                        </h3>
                        <p style="font-size:12px; color:var(--nr-text-muted); margin-top:2px;">Patients checked in for
                            today's vaccination queue</p>
                    </div>
                    <span class="badge-status bg-amber-light"><?= count($patients_today) ?> in queue</span>
                </div>

                <div style="overflow-x:auto;">
                    <table class="mar-table">
                        <thead>
                            <tr>
                                <th>Infant</th>
                                <th>Age</th>
                                <th>Vaccine</th>
                                <th style="text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($patients_today)): ?>
                                <tr>
                                    <td colspan="4"
                                        style="text-align:center; padding: 36px 16px; color: var(--nr-text-muted);">
                                        <div
                                            style="width:40px; height:40px; border-radius:50%; background:#f1f5f9; display:inline-flex; align-items:center; justify-content:center; margin-bottom:8px; color:#94a3b8; font-size:16px;">
                                            <i class="fa-solid fa-user-check"></i>
                                        </div>
                                        <div style="font-weight:700; color:#334155; font-size:13.5px;">No arrived patients
                                            in waiting queue</div>
                                        <div style="font-size:11.5px; color:#94a3b8; margin-top:3px;">Patients will show up
                                            when marked as arrived in appointments.</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($patients_today as $pt): ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex; align-items:center; gap:10px;">
                                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($pt['name']) ?>&background=eff6ff&color=1d4ed8&bold=true"
                                                    style="width:32px; height:32px; border-radius:50%; border:1px solid #dbeafe;">
                                                <div>
                                                    <a href="view_patient.php?id=<?= $pt['child_id'] ?>"
                                                        style="font-weight:700; color:#0f172a; text-decoration:none; font-size:13px;">
                                                        <?= htmlspecialchars($pt['name']) ?>
                                                    </a>
                                                    <span
                                                        style="font-size:11px; color:#64748b; display:block;">#C-<?= str_pad($pt['child_id'], 4, '0', STR_PAD_LEFT) ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="font-size:12px; font-weight:600; color:#475569;">
                                            <?= calculateAge($pt['date_of_birth']) ?>
                                        </td>
                                        <td>
                                            <span
                                                style="background:#eff6ff; color:#1d4ed8; padding:3px 8px; border-radius:6px; font-size:11.5px; font-weight:700;">
                                                <?= htmlspecialchars($pt['vaccine_name'] ?: 'General Check') ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right;">
                                            <button type="button"
                                                onclick="openVitalsModal(<?= $pt['child_id'] ?>, '<?= htmlspecialchars(addslashes($pt['name'])) ?>')"
                                                class="nr-btn nr-btn-sm nr-btn-primary"
                                                style="padding:5px 12px; font-size:12px;">
                                                <i class="fa-solid fa-stethoscope"></i> Assess Vitals
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- RIGHT: Recent Vitals Logged Today -->
            <div class="mar-card" style="margin-bottom:0;">
                <div class="mar-card-header">
                    <div>
                        <h3 class="mar-card-title">
                            <i class="fa-solid fa-clock-rotate-left" style="color:var(--nr-primary);"></i>
                            Recent Vitals Logged Today
                        </h3>
                        <p style="font-size:12px; color:var(--nr-text-muted); margin-top:2px;">Physiological status
                            &amp; physician referral actions</p>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table class="mar-table">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Time</th>
                                <th>Temp (&deg;C)</th>
                                <th>Heart Rate</th>
                                <th>Status</th>
                                <th style="text-align:right;">Referral</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_vitals)): ?>
                                <tr>
                                    <td colspan="6"
                                        style="text-align:center; padding: 36px 16px; color: var(--nr-text-muted);">
                                        <div
                                            style="width:40px; height:40px; border-radius:50%; background:#f1f5f9; display:inline-flex; align-items:center; justify-content:center; margin-bottom:8px; color:#94a3b8; font-size:16px;">
                                            <i class="fa-solid fa-heart-pulse"></i>
                                        </div>
                                        <div style="font-weight:700; color:#334155; font-size:13.5px;">No vitals recorded
                                            yet today</div>
                                        <div style="font-size:11.5px; color:#94a3b8; margin-top:3px;">Click "+ Log Vitals"
                                            to perform an evaluation.</div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_vitals as $v): ?>
                                    <tr>
                                        <td>
                                            <a href="view_patient.php?id=<?= $v['child_id'] ?>"
                                                style="font-weight:700; color:#0f172a; text-decoration:none; font-size:13px;">
                                                <?= htmlspecialchars($v['child_name']) ?>
                                            </a>
                                        </td>
                                        <td style="font-size:11.5px; color:#64748b;">
                                            <?= date('h:i A', strtotime($v['created_at'])) ?>
                                        </td>
                                        <td>
                                            <?php if ($v['temperature'] >= 38.0): ?>
                                                <span class="temp-badge temp-fever">
                                                    <i class="fa-solid fa-fire"></i>
                                                    <?= htmlspecialchars($v['temperature']) ?>&deg;C
                                                </span>
                                            <?php else: ?>
                                                <span class="temp-badge temp-normal">
                                                    <?= htmlspecialchars($v['temperature']) ?>&deg;C
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td
                                            style="font-weight:600; font-size:12.5px; <?= ($v['heart_rate'] > 160 || ($v['heart_rate'] < 60 && $v['heart_rate'] > 0)) ? 'color:#dc2626; font-weight:800;' : 'color:#475569;' ?>">
                                            <?= $v['heart_rate'] ? htmlspecialchars($v['heart_rate']) . ' bpm' : '-' ?>
                                        </td>
                                        <td>
                                            <?php if ($v['is_critical']): ?>
                                                <span class="badge-status bg-red-light"><i
                                                        class="fa-solid fa-triangle-exclamation"></i> Critical</span>
                                            <?php else: ?>
                                                <span class="badge-status bg-green-light"><i class="fa-solid fa-check"></i>
                                                    Cleared</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <button type="button" class="nr-btn nr-btn-sm nr-btn-outline"
                                                style="color:#dc2626; border-color:#fca5a5; padding:4px 9px; font-size:11.5px;"
                                                onclick="openReferModal(<?= $v['child_id'] ?>, '<?= htmlspecialchars(addslashes($v['child_name'])) ?>', '<?= htmlspecialchars(addslashes($v['symptoms'] ? $v['symptoms'] : 'Temp: ' . $v['temperature'] . '°C')) ?>')">
                                                <i class="fa-solid fa-user-doctor"></i> Refer
                                            </button>
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

    <!-- ===== LOG VITALS MODAL (ELEVATED CLINICAL INTERACTION) ===== -->
    <div class="modal-overlay" id="vitalsModal">
        <div class="modal-box">
            <button class="modal-close" onclick="closeVitalsModal()" title="Close (Esc)"><i
                    class="fa-solid fa-xmark"></i></button>

            <div style="display:flex; align-items:center; gap:14px; margin-bottom:20px;">
                <div
                    style="width:46px; height:46px; border-radius:14px; background:linear-gradient(135deg, #2563eb, #0284c7); color:#fff; display:flex; align-items:center; justify-content:center; font-size:20px; box-shadow:0 6px 16px rgba(37,99,235,0.3); flex-shrink:0;">
                    <i class="fa-solid fa-heart-pulse"></i>
                </div>
                <div>
                    <h3 style="font-size:19px; font-weight:800; color:#0f172a; margin:0; letter-spacing:-0.3px;">Record
                        Pediatric Clinical Vitals</h3>
                    <p style="font-size:12.5px; color:#64748b; margin:3px 0 0 0;">Pre-vaccination physiological triage
                        &amp; fever detection screening</p>
                </div>
            </div>

            <!-- Dynamic Selected Patient Banner -->
            <div id="modal_patient_banner"
                style="display:none; background:linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%); border:1px solid #bfdbfe; border-radius:12px; padding:12px 16px; margin-bottom:18px; align-items:center; justify-content:space-between; gap:12px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:36px; height:36px; border-radius:50%; background:#2563eb; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:14px;"
                        id="banner_avatar">
                        P
                    </div>
                    <div>
                        <div style="font-weight:800; color:#0f172a; font-size:14px;" id="banner_name">Patient Name</div>
                        <div style="font-size:12px; color:#64748b;" id="banner_meta">Age: 6 mos • Target: DTP-HepB-Hib 2
                        </div>
                    </div>
                </div>
                <span class="badge-status bg-blue-light" id="banner_queue_tag" style="font-size:11px;">Queue
                    Ready</span>
            </div>

            <form method="POST" action="" id="vitalsForm">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="log_vitals">

                <div class="field-group" style="margin-bottom:18px;">
                    <label
                        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; font-weight:700; color:#0f172a; font-size:13px;">
                        <span>Select Infant in Waiting Queue <span style="color:#ef4444;">*</span></span>
                        <span style="font-size:11.5px; color:#64748b; font-weight:500;"><?= count($patients_today) ?>
                            awaiting triage today</span>
                    </label>
                    <select name="child_id" id="modal_select_child" class="input-control" required
                        style="font-weight:700; height:44px;" onchange="onSelectPatientChange(this)">
                        <option value="" data-name="" data-age="" data-vaccine="">-- Choose Infant Awaiting Triage --
                        </option>
                        <?php foreach ($patients_today as $p): ?>
                            <option value="<?= $p['child_id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>"
                                data-age="<?= calculateAge($p['date_of_birth']) ?>"
                                data-vaccine="<?= htmlspecialchars($p['vaccine_name'] ?: 'General Assessment') ?>">
                                <?= htmlspecialchars($p['name']) ?> (<?= calculateAge($p['date_of_birth']) ?>) &bull;
                                <?= htmlspecialchars($p['vaccine_name'] ?: 'General Assessment') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Temperature Card with Stepper Controls, Presets & Real-time Live Evaluator -->
                <div class="vital-sign-card" style="margin-bottom:16px;">
                    <div
                        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
                        <label
                            style="font-size:12px; font-weight:700; color:#1e293b; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:6px;">
                            <i class="fa-solid fa-temperature-three-quarters" style="color:#2563eb;"></i> Body
                            Temperature (&deg;C) <span style="color:#ef4444;">*</span>
                        </label>
                        <div style="display:flex; gap:5px; flex-wrap:wrap;">
                            <button type="button" class="temp-preset-pill" onclick="setTempPreset(36.6)">36.6&deg;C
                                Normal</button>
                            <button type="button" class="temp-preset-pill" onclick="setTempPreset(37.2)">37.2&deg;C
                                Mild</button>
                            <button type="button" class="temp-preset-pill preset-fever" onclick="setTempPreset(38.2)"><i
                                    class="fa-solid fa-fire"></i> 38.2&deg;C Fever</button>
                            <button type="button" class="temp-preset-pill preset-fever"
                                onclick="setTempPreset(39.0)">39.0&deg;C High</button>
                        </div>
                    </div>

                    <div style="display:flex; align-items:center; gap:10px;">
                        <div class="vital-input-wrap" style="flex:1;">
                            <input type="number" step="0.1" name="temperature" id="input_temperature"
                                class="input-control" required placeholder="e.g. 36.8" oninput="evaluateVitalsLive()"
                                style="font-size:18px; font-weight:800; font-family:'Space Grotesk', 'Inter', sans-serif; padding-right:48px;">
                            <span class="vital-input-unit">&deg;C</span>
                        </div>
                        <div class="stepper-btn-group">
                            <button type="button" class="stepper-btn" onclick="adjustTemp(-0.1)"
                                title="Decrease 0.1°C"><i class="fa-solid fa-minus"></i> 0.1</button>
                            <div class="stepper-divider"></div>
                            <button type="button" class="stepper-btn" onclick="adjustTemp(0.1)"
                                title="Increase 0.1°C"><i class="fa-solid fa-plus"></i> 0.1</button>
                        </div>
                    </div>

                    <!-- Dynamic Live Status Banner -->
                    <div id="temp_feedback_box"
                        style="margin-top:10px; padding:10px 14px; border-radius:10px; display:none; align-items:center; gap:10px; font-size:12.5px; font-weight:700;">
                        <i id="temp_feedback_icon" class="fa-solid fa-circle-check"></i>
                        <span id="temp_feedback_text"></span>
                    </div>
                </div>

                <!-- Duo Vitals: Heart Rate & Weight -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                    <!-- Heart Rate Card -->
                    <div class="vital-sign-card">
                        <div
                            style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <label
                                style="font-size:12px; font-weight:700; color:#1e293b; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:4px;">
                                <i class="fa-solid fa-heart-pulse" style="color:#e11d48;"></i> Heart Rate
                            </label>
                            <div style="display:flex; gap:4px;">
                                <button type="button" class="temp-preset-pill"
                                    style="padding:2px 7px; font-size:10.5px;" onclick="setHRPreset(110)">110</button>
                                <button type="button" class="temp-preset-pill"
                                    style="padding:2px 7px; font-size:10.5px;" onclick="setHRPreset(135)">135</button>
                            </div>
                        </div>
                        <div class="vital-input-wrap">
                            <input type="number" name="heart_rate" id="input_heart_rate" class="input-control"
                                placeholder="e.g. 115" oninput="evaluateVitalsLive()"
                                style="font-weight:700; font-size:16px; padding-right:58px;">
                            <span class="vital-input-unit">bpm</span>
                        </div>
                        <div id="hr_feedback_sub"
                            style="font-size:11px; color:#64748b; margin-top:5px; display:flex; align-items:center; gap:4px;">
                            <i class="fa-solid fa-circle-info" style="font-size:10px;"></i> Nominal baseline: 100 - 160
                            bpm
                        </div>
                    </div>

                    <!-- Weight Card -->
                    <div class="vital-sign-card">
                        <div
                            style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <label
                                style="font-size:12px; font-weight:700; color:#1e293b; text-transform:uppercase; letter-spacing:0.5px; display:flex; align-items:center; gap:4px;">
                                <i class="fa-solid fa-weight-scale" style="color:#059669;"></i> Weight (kg)
                            </label>
                            <div class="stepper-btn-group" style="scale:0.9; transform-origin:right center;">
                                <button type="button" class="stepper-btn" onclick="adjustWeight(-0.1)"
                                    title="Decrease 0.1kg"><i class="fa-solid fa-minus"></i></button>
                                <div class="stepper-divider"></div>
                                <button type="button" class="stepper-btn" onclick="adjustWeight(0.1)"
                                    title="Increase 0.1kg"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>
                        <div class="vital-input-wrap">
                            <input type="number" step="0.1" name="weight" id="input_weight" class="input-control"
                                placeholder="e.g. 6.2" style="font-weight:700; font-size:16px; padding-right:48px;">
                            <span class="vital-input-unit">kg</span>
                        </div>
                        <div
                            style="font-size:11px; color:#64748b; margin-top:5px; display:flex; align-items:center; gap:4px;">
                            <i class="fa-solid fa-chart-line" style="font-size:10px;"></i> Growth chart milestone
                            tracking
                        </div>
                    </div>
                </div>

                <!-- Observations & Interactive Clickable Symptom Tag Chips -->
                <div class="field-group" style="margin-bottom:18px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <label style="font-size:13px; font-weight:700; color:#0f172a;">Clinical Observations &amp;
                            Symptoms</label>
                        <span style="font-size:11px; color:#94a3b8; font-weight:600;">Click tags to toggle:</span>
                    </div>
                    <div style="display:flex; gap:7px; flex-wrap:wrap; margin-bottom:10px;" id="symptom_chip_container">
                        <span class="symptom-tag" onclick="toggleSymptom(this, 'Alert & Healthy')"><i
                                class="fa-solid fa-check-circle" style="font-size:11px;"></i> Alert &amp; Healthy</span>
                        <span class="symptom-tag" onclick="toggleSymptom(this, 'Mild Cough')"><i
                                class="fa-solid fa-plus" style="font-size:11px;"></i> Mild Cough</span>
                        <span class="symptom-tag" onclick="toggleSymptom(this, 'Nasal Congestion')"><i
                                class="fa-solid fa-plus" style="font-size:11px;"></i> Nasal Congestion</span>
                        <span class="symptom-tag" onclick="toggleSymptom(this, 'Mild Skin Rash')"><i
                                class="fa-solid fa-plus" style="font-size:11px;"></i> Mild Skin Rash</span>
                        <span class="symptom-tag" onclick="toggleSymptom(this, 'Irritable / Crying')"><i
                                class="fa-solid fa-plus" style="font-size:11px;"></i> Irritable</span>
                        <span class="symptom-tag" onclick="toggleSymptom(this, 'Acute Distress / Lethargic')"><i
                                class="fa-solid fa-triangle-exclamation" style="font-size:11px; color:#e11d48;"></i>
                            Lethargic</span>
                        <span class="symptom-tag" onclick="toggleSymptom(this, 'Poor Feeding / Refusal')"><i
                                class="fa-solid fa-plus" style="font-size:11px;"></i> Poor Feeding</span>
                    </div>
                    <textarea name="symptoms" id="input_symptoms" rows="3" class="input-control"
                        style="resize:vertical; font-size:13px; font-family:'Inter', sans-serif;"
                        placeholder="Document clinical observations, caregiver comments, or injection site inspections..."
                        oninput="syncSymptomTagsWithText()"></textarea>
                </div>

                <!-- Live Dynamic Clinical Readiness Verdict Card -->
                <div id="verdict_card"
                    style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; gap:12px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div id="verdict_icon"
                            style="width:34px; height:34px; border-radius:50%; background:#dcfce7; color:#15803d; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0;">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <div style="font-size:12.5px; font-weight:800; color:#0f172a;" id="verdict_title">
                                Immunization Readiness: Awaiting Inputs</div>
                            <div style="font-size:11.5px; color:#64748b;" id="verdict_desc">Enter temperature and vitals
                                to calculate clinical clearance.</div>
                        </div>
                    </div>
                    <span id="verdict_badge" class="badge-status bg-blue-light" style="font-size:11px;">Pre-check</span>
                </div>

                <div style="display: flex; gap: 12px; justify-content: flex-end; align-items: center;">
                    <button type="button" onclick="closeVitalsModal()" class="nr-btn nr-btn-outline"
                        style="padding:10px 18px;">
                        Cancel
                    </button>
                    <button type="submit" class="nr-btn nr-btn-primary"
                        style="padding:11px 26px; font-weight:800; font-size:13.5px; box-shadow:0 4px 14px rgba(37,99,235,0.35);">
                        <i class="fa-solid fa-stethoscope"></i> Save &amp; Evaluate Vitals
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== REFERRAL MODAL ===== -->
    <div class="modal-overlay" id="referModal">
        <div class="modal-box">
            <button class="modal-close" onclick="closeReferModal()" title="Close (Esc)"><i
                    class="fa-solid fa-xmark"></i></button>
            <h3 style="font-size: 18px; font-weight: 800; color: #0F172A; margin-bottom: 4px;">Refer Patient to Doctor
            </h3>
            <p style="font-size: 13px; color: #64748B; margin-bottom: 18px;">Route clinical alert directly to attending
                medical officer.</p>

            <div
                style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:12px 16px; margin-bottom:18px; font-size:13px; color:#1e40af;">
                <div>Patient: <strong id="modal_refer_child_name" style="color:#0f172a;"></strong></div>
                <div>Attending Doctor: <strong><?= htmlspecialchars($on_duty_doctor) ?></strong></div>
            </div>

            <form method="POST" action="">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="refer_patient">
                <input type="hidden" name="child_id" id="modal_refer_child_id">

                <div class="field-group">
                    <label>Referral Reason / Clinical Notes *</label>
                    <textarea name="referral_reason" id="modal_referral_reason" rows="4" class="input-control"
                        style="resize:vertical;" required
                        placeholder="Explain symptoms and reason for physician evaluation..."></textarea>
                </div>

                <div style="margin-top: 10px; display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeReferModal()" class="nr-btn nr-btn-outline">Cancel</button>
                    <button type="submit" class="nr-btn" style="background:#dc2626; color:#fff;">
                        <i class="fa-solid fa-paper-plane"></i> Dispatch Immediate Referral
                    </button>
                </div>
            </form>
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

        function onSelectPatientChange(sel) {
            const banner = document.getElementById('modal_patient_banner');
            const opt = sel.options[sel.selectedIndex];
            if (sel.value && opt && opt.dataset.name) {
                document.getElementById('banner_avatar').innerText = opt.dataset.name.charAt(0).toUpperCase();
                document.getElementById('banner_name').innerText = opt.dataset.name;
                document.getElementById('banner_meta').innerText = 'Age: ' + (opt.dataset.age || 'N/A') + ' • Target: ' + (opt.dataset.vaccine || 'General');
                banner.style.display = 'flex';
            } else {
                banner.style.display = 'none';
            }
        }

        function openVitalsModal(childId = null, childName = null) {
            const sel = document.getElementById('modal_select_child');
            if (childId) {
                sel.value = childId;
            }
            onSelectPatientChange(sel);
            document.getElementById('vitalsModal').classList.add('active');

            // Focus temperature input after brief animation
            setTimeout(() => {
                const tempInp = document.getElementById('input_temperature');
                if (tempInp) tempInp.focus();
            }, 100);
        }

        function closeVitalsModal() {
            document.getElementById('vitalsModal').classList.remove('active');
        }

        function openReferModal(childId, childName, defaultReason) {
            document.getElementById('modal_refer_child_id').value = childId;
            document.getElementById('modal_refer_child_name').innerText = childName;
            document.getElementById('modal_referral_reason').value = defaultReason
                ? 'Clinical evaluation recommended: ' + defaultReason
                : 'Urgent physician evaluation requested prior to immunization.';
            document.getElementById('referModal').classList.add('active');
        }

        function closeReferModal() {
            document.getElementById('referModal').classList.remove('active');
        }

        // Stepper functions for Temperature
        function adjustTemp(delta) {
            const inp = document.getElementById('input_temperature');
            let current = parseFloat(inp.value);
            if (isNaN(current)) current = 36.8;
            current = Math.round((current + delta) * 10) / 10;
            inp.value = current.toFixed(1);
            evaluateVitalsLive();
        }

        function setTempPreset(val) {
            const inp = document.getElementById('input_temperature');
            inp.value = Number(val).toFixed(1);
            evaluateVitalsLive();
        }

        // Stepper functions for Heart Rate and Weight
        function setHRPreset(val) {
            const inp = document.getElementById('input_heart_rate');
            inp.value = val;
            evaluateVitalsLive();
        }

        function adjustWeight(delta) {
            const inp = document.getElementById('input_weight');
            let current = parseFloat(inp.value);
            if (isNaN(current)) current = 6.0;
            current = Math.round((current + delta) * 10) / 10;
            if (current < 1.0) current = 1.0;
            inp.value = current.toFixed(1);
        }

        // Interactive toggle for symptom chips
        function toggleSymptom(chip, text) {
            const ta = document.getElementById('input_symptoms');
            let currentVal = ta.value;
            let items = currentVal.split(',').map(s => s.trim()).filter(Boolean);

            if (items.includes(text)) {
                // Remove
                items = items.filter(s => s !== text);
                chip.classList.remove('active');
                chip.querySelector('i').className = 'fa-solid fa-plus';
            } else {
                // Add
                items.push(text);
                chip.classList.add('active');
                chip.querySelector('i').className = 'fa-solid fa-check';
            }

            ta.value = items.join(', ');
            evaluateVitalsLive();
        }

        function syncSymptomTagsWithText() {
            const ta = document.getElementById('input_symptoms');
            const val = ta.value.toLowerCase();
            const chips = document.querySelectorAll('#symptom_chip_container .symptom-tag');
            chips.forEach(chip => {
                const tagText = chip.textContent.trim().toLowerCase();
                if (val.includes(tagText)) {
                    chip.classList.add('active');
                    chip.querySelector('i').className = 'fa-solid fa-check';
                } else {
                    chip.classList.remove('active');
                    chip.querySelector('i').className = 'fa-solid fa-plus';
                }
            });
            evaluateVitalsLive();
        }

        // Live comprehensive vitals evaluation
        function evaluateVitalsLive() {
            const tempVal = document.getElementById('input_temperature').value;
            const hrVal = document.getElementById('input_heart_rate').value;
            const symptomsVal = document.getElementById('input_symptoms').value;

            const t = parseFloat(tempVal);
            const hr = parseInt(hrVal, 10);

            const tempBox = document.getElementById('temp_feedback_box');
            const tempIcon = document.getElementById('temp_feedback_icon');
            const tempText = document.getElementById('temp_feedback_text');

            const hrSub = document.getElementById('hr_feedback_sub');

            const vCard = document.getElementById('verdict_card');
            const vIcon = document.getElementById('verdict_icon');
            const vTitle = document.getElementById('verdict_title');
            const vDesc = document.getElementById('verdict_desc');
            const vBadge = document.getElementById('verdict_badge');

            let isFever = false;
            let isTempAbnormal = false;
            let isHRAbnormal = false;

            // Evaluate Temperature Box
            if (!isNaN(t) && tempVal !== '') {
                tempBox.style.display = 'flex';
                if (t >= 38.0) {
                    isFever = true;
                    tempBox.style.background = '#fee2e2';
                    tempBox.style.border = '1px solid #fca5a5';
                    tempBox.style.color = '#dc2626';
                    tempIcon.className = 'fa-solid fa-fire';
                    tempText.innerText = 'High Fever Alert (' + t + '°C) — Automatic Doctor referral will trigger upon saving!';
                } else if (t >= 37.5) {
                    isTempAbnormal = true;
                    tempBox.style.background = '#fef3c7';
                    tempBox.style.border = '1px solid #fde68a';
                    tempBox.style.color = '#b45309';
                    tempIcon.className = 'fa-solid fa-triangle-exclamation';
                    tempText.innerText = 'Low-Grade Pyrexia (' + t + '°C) — Caution: Monitor infant for secondary symptoms.';
                } else if (t < 36.0) {
                    isTempAbnormal = true;
                    tempBox.style.background = '#eff6ff';
                    tempBox.style.border = '1px solid #bfdbfe';
                    tempBox.style.color = '#1d4ed8';
                    tempIcon.className = 'fa-solid fa-snowflake';
                    tempText.innerText = 'Sub-normal reading (' + t + '°C) — Check sensor positioning.';
                } else {
                    tempBox.style.background = '#ecfdf5';
                    tempBox.style.border = '1px solid #a7f3d0';
                    tempBox.style.color = '#059669';
                    tempIcon.className = 'fa-solid fa-circle-check';
                    tempText.innerText = 'Normal Physiological Range (' + t + '°C) — Patient cleared for vaccination.';
                }
            } else {
                tempBox.style.display = 'none';
            }

            // Evaluate Heart Rate
            if (!isNaN(hr) && hr > 0) {
                if (hr > 160) {
                    isHRAbnormal = true;
                    hrSub.innerHTML = '<span style="color:#dc2626; font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> Tachycardia alert (> 160 bpm)</span>';
                } else if (hr < 80) {
                    isHRAbnormal = true;
                    hrSub.innerHTML = '<span style="color:#d97706; font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> Low heart rate warning (< 80 bpm)</span>';
                } else {
                    hrSub.innerHTML = '<span style="color:#059669; font-weight:600;"><i class="fa-solid fa-check"></i> Normal pediatric range</span>';
                }
            } else {
                hrSub.innerHTML = '<i class="fa-solid fa-circle-info" style="font-size:10px;"></i> Nominal baseline: 100 - 160 bpm';
            }

            // Overall Readiness Verdict
            if (isNaN(t) && isNaN(hr)) {
                vCard.style.background = '#f8fafc';
                vCard.style.borderColor = '#e2e8f0';
                vIcon.style.background = '#f1f5f9';
                vIcon.style.color = '#64748b';
                vIcon.innerHTML = '<i class="fa-solid fa-stethoscope"></i>';
                vTitle.innerText = 'Immunization Readiness: Awaiting Inputs';
                vTitle.style.color = '#0f172a';
                vDesc.innerText = 'Enter temperature and vitals to calculate clinical clearance.';
                vBadge.className = 'badge-status bg-blue-light';
                vBadge.innerText = 'Pre-check';
            } else if (isFever || isHRAbnormal) {
                vCard.style.background = '#fef2f2';
                vCard.style.borderColor = '#fca5a5';
                vIcon.style.background = '#fee2e2';
                vIcon.style.color = '#dc2626';
                vIcon.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
                vTitle.innerText = 'Contraindicated — Doctor Referral Mandatory';
                vTitle.style.color = '#b91c1c';
                vDesc.innerText = 'Critical parameters flagged. Saving will automatically alert <?= htmlspecialchars($on_duty_doctor) ?>.';
                vBadge.className = 'badge-status bg-red-light';
                vBadge.innerText = 'Critical Alert';
            } else if (isTempAbnormal) {
                vCard.style.background = '#fffbeb';
                vCard.style.borderColor = '#fde68a';
                vIcon.style.background = '#fef3c7';
                vIcon.style.color = '#d97706';
                vIcon.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
                vTitle.innerText = 'Caution — Secondary Review Advised';
                vTitle.style.color = '#b45309';
                vDesc.innerText = 'Subtle temperature elevation. Verify caregiver history before injection.';
                vBadge.className = 'badge-status bg-amber-light';
                vBadge.innerText = 'Observation';
            } else {
                vCard.style.background = '#f0fdf4';
                vCard.style.borderColor = '#bbf7d0';
                vIcon.style.background = '#dcfce7';
                vIcon.style.color = '#15803d';
                vIcon.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
                vTitle.innerText = 'Ready For Immunization — Cleared';
                vTitle.style.color = '#15803d';
                vDesc.innerText = 'All clinical parameters within standard pediatric thresholds.';
                vBadge.className = 'badge-status bg-green-light';
                vBadge.innerText = 'Nominal';
            }
        }

        // Close on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeVitalsModal();
                closeReferModal();
            }
        });
    </script>
    <script src="../js/notifications.js"></script>
</body>

</html>