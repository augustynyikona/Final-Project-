<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';
require_once '../config/notifications_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: ../auth/login.php");
    exit;
}
$doctor_id = (int)$_SESSION['user_id'];

// Fetch user profile from database
$stmt = $pdo->prepare("SELECT name, profile_pic FROM users WHERE id = ?");
$stmt->execute([$doctor_id]);
$user_profile = $stmt->fetch(PDO::FETCH_ASSOC);

$doctor_name = $user_profile['name'] ?? $_SESSION['name'];
$profile_pic = ($user_profile && $user_profile['profile_pic']) 
    ? '../uploads/' . $user_profile['profile_pic'] 
    : "https://ui-avatars.com/api/?name=" . urlencode($doctor_name) . "&background=0284c7&color=fff";

$child_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$child_id) {
    header("Location: patients.php");
    exit;
}

$message = '';
$error = '';
$conflict_alert = null;
$active_tab = $_GET['tab'] ?? 'overview';

// =========================================================================
// ACTION HANDLERS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_guard();
    $action = $_POST['action'];

    // 1. SAVE PRESCRIPTION
    if ($action === 'save_prescription') {
        $medication = trim($_POST['medication_name'] ?? '');
        $dosage = trim($_POST['dosage'] ?? '');
        $frequency = trim($_POST['frequency'] ?? '');
        $duration = trim($_POST['duration'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');
        $delegate_nurse = isset($_POST['delegate_to_nurse']) ? 1 : 0;
        $consult_id = !empty($_POST['consultation_id']) ? (int)$_POST['consultation_id'] : null;

        if (empty($medication) || empty($dosage) || empty($frequency) || empty($duration)) {
            $error = "Please complete all required prescription fields (Medication, Dosage, Frequency, and Duration).";
            $active_tab = 'prescriptions';
        } else {
            // Fetch guardian ID
            $g_stmt = $pdo->prepare("SELECT guardian_id FROM children WHERE id = ?");
            $g_stmt->execute([$child_id]);
            $guardian_id = (int)$g_stmt->fetchColumn();

            $stmt = $pdo->prepare("
                INSERT INTO prescriptions 
                (child_id, guardian_id, doctor_id, consultation_id, medication_name, dosage, frequency, duration, instructions, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
            ");
            $stmt->execute([$child_id, $guardian_id, $doctor_id, $consult_id, $medication, $dosage, $frequency, $duration, $instructions]);
            $rx_id = $pdo->lastInsertId();

            // If nurse delegation requested, also create a nurse task
            if ($delegate_nurse) {
                $task_desc = "Prescription Admin: {$medication} ({$dosage}, {$frequency} for {$duration}). Instructions: {$instructions}";
                $pdo->prepare("INSERT INTO nurse_tasks (child_id, doctor_id, task_description, status, created_at) VALUES (?, ?, ?, 'pending', NOW())")
                    ->execute([$child_id, $doctor_id, $task_desc]);
            }

            header("Location: patient_detail.php?id=$child_id&tab=prescriptions&rx_saved=1");
            exit;
        }
    }

    // 2. SCHEDULE NEXT REVIEW (WITH CONFLICT DETECTION)
    elseif ($action === 'schedule_review') {
        $review_date = trim($_POST['review_date'] ?? '');
        $review_time = trim($_POST['review_time'] ?? '');
        $review_type = trim($_POST['review_type'] ?? 'Clinical Review');
        $review_notes = trim($_POST['review_notes'] ?? '');

        if (empty($review_date) || empty($review_time)) {
            $error = "Please select both a review date and time.";
            $active_tab = 'reviews';
        } else {
            // Check availability conflict (30-minute buffer)
            $conflict = checkAppointmentConflict($pdo, $review_date, $review_time);
            if ($conflict['has_conflict']) {
                $conflict_alert = [
                    'message' => $conflict['message'],
                    'available_slots' => $conflict['available_slots'] ?? [],
                    'chosen_date' => $review_date
                ];
                $error = $conflict['message'];
                $active_tab = 'reviews';
            } else {
                // Save appointment to central database
                $room = "Doctor Consultation Suite 2";
                $combined_notes = $review_type . ($review_notes ? " — Note: " . $review_notes : "");
                
                $date_problem = appointment_date_problem($review_date);
                if ($date_problem !== '') {
                    $error_msg = $date_problem;
                    $stmt = null;
                } else {
                $stmt = $pdo->prepare("
                    INSERT INTO appointments (child_id, vaccine_id, appointment_date, appointment_time, status, room_number, notes, created_at)
                    VALUES (?, NULL, ?, ?, 'scheduled', ?, ?, NOW())
                ");
                $stmt->execute([$child_id, $review_date, $review_time, $room, $combined_notes]);
                $new_appt_id = $pdo->lastInsertId();
                }

                // Record into consultations table next_review_date
                $pdo->prepare("INSERT INTO consultations (child_id, doctor_id, notes, next_review_date, created_at) VALUES (?, ?, ?, ?, NOW())")
                    ->execute([$child_id, $doctor_id, "Scheduled review: $combined_notes", $review_date]);

                // Trigger in-system and mobile notification
                recordAppointmentNotification($pdo, $new_appt_id, 'booked');

                header("Location: patient_detail.php?id=$child_id&tab=reviews&review_saved=1");
                exit;
            }
        }
    }

    // 3. CANCEL APPOINTMENT (WITH REASON AND DUAL NOTIFICATIONS)
    elseif ($action === 'cancel_appointment') {
        $appt_id = (int)($_POST['appointment_id'] ?? 0);
        $reason = trim($_POST['cancellation_reason'] ?? 'Cancelled by physician upon clinical review');

        if ($appt_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE appointments 
                SET status = 'cancelled', cancellation_reason = ?, cancelled_at = NOW(), cancelled_by_id = ?
                WHERE id = ? AND child_id = ?
            ");
            $stmt->execute([$reason, $doctor_id, $appt_id, $child_id]);

            // Dispatch dual notification to patient and nurse
            recordAppointmentNotification($pdo, $appt_id, 'cancelled', [
                'reason' => $reason,
                'cancelled_by_name' => $doctor_name
            ]);

            header("Location: patient_detail.php?id=$child_id&tab=appointments&cancelled=1");
            exit;
        }
    }

    // 4. ADD CONSULTATION NOTES
    elseif ($action === 'add_consultation') {
        $notes = trim($_POST['notes'] ?? '');
        $next_review = !empty($_POST['next_review_date']) ? $_POST['next_review_date'] : null;

        if ($notes) {
            $stmt = $pdo->prepare("INSERT INTO consultations (child_id, doctor_id, notes, next_review_date, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$child_id, $doctor_id, $notes, $next_review]);

            if (isset($_POST['auto_archive']) && $_POST['auto_archive'] == '1') {
                $pdo->prepare("
                    UPDATE appointments SET is_archived = 1, archived_at = NOW()
                    WHERE child_id = ? AND status = 'completed' AND (is_archived = 0 OR is_archived IS NULL)
                ")->execute([$child_id]);
            }

            header("Location: patient_detail.php?id=$child_id&tab=overview&success=1");
            exit;
        } else {
            $error = "Consultation assessment notes cannot be empty.";
        }
    }

    // 5. ARCHIVE / UNARCHIVE
    elseif ($action === 'archive_appointment') {
        $appt_id = (int)($_POST['appointment_id'] ?? 0);
        if ($appt_id > 0) {
            $pdo->prepare("UPDATE appointments SET is_archived = 1, archived_at = NOW() WHERE id = ?")->execute([$appt_id]);
            header("Location: patient_detail.php?id=$child_id&tab=immunization&archived=1");
            exit;
        }
    } elseif ($action === 'unarchive_appointment') {
        $appt_id = (int)($_POST['appointment_id'] ?? 0);
        if ($appt_id > 0) {
            $pdo->prepare("UPDATE appointments SET is_archived = 0, archived_at = NULL WHERE id = ?")->execute([$appt_id]);
            header("Location: patient_detail.php?id=$child_id&tab=immunization&unarchived=1");
            exit;
        }
    }
}

// Check GET status messages
if (isset($_GET['rx_saved'])) $message = "Prescription successfully saved and synchronised with the Patient Mobile App.";
if (isset($_GET['review_saved'])) $message = "Clinical review scheduled and automated reminders queued.";
if (isset($_GET['cancelled'])) $message = "Appointment cancelled. The guardian and attending nurse have been automatically notified.";
if (isset($_GET['success'])) $message = "Consultation notes recorded successfully.";
if (isset($_GET['archived'])) $message = "Appointment archived successfully.";
if (isset($_GET['unarchived'])) $message = "Appointment restored from archive.";

// =========================================================================
// DATA QUERIES
// =========================================================================
// Fetch Patient & Guardian
$stmt = $pdo->prepare("
    SELECT c.*, u.name as guardian_name, u.phone as guardian_phone, u.id as guardian_user_id 
    FROM children c
    JOIN users u ON c.guardian_id = u.id
    WHERE c.id = ?
");
$stmt->execute([$child_id]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    header("Location: patients.php");
    exit;
}

function calculateAge($dob) {
    if (empty($dob)) return 'N/A';
    try {
        $bday = new DateTime($dob);
        $today = new DateTime('today');
        $diff = $today->diff($bday);
        if ($diff->y > 0) return $diff->y . ' yrs, ' . $diff->m . ' mos';
        if ($diff->m > 0) return $diff->m . ' mos';
        return $diff->d . ' days';
    } catch (Exception $e) {
        return 'N/A';
    }
}

// 1. Fetch Prescriptions (Active & Past)
$rx_stmt = $pdo->prepare("
    SELECT p.*, COALESCE(u.name, 'Dr. Emily Chen') as doctor_name
    FROM prescriptions p
    LEFT JOIN users u ON p.doctor_id = u.id
    WHERE p.child_id = ?
    ORDER BY p.created_at DESC
");
$rx_stmt->execute([$child_id]);
$prescriptions = $rx_stmt->fetchAll(PDO::FETCH_ASSOC);
$active_rx_count = count(array_filter($prescriptions, fn($r) => $r['status'] === 'active'));

// 2. Fetch All Appointments (Vaccinations and Reviews)
$all_appts_stmt = $pdo->prepare("
    SELECT a.*, 
           COALESCE(v.name, a.notes, 'Clinical Review') as display_name,
           COALESCE(v.description, a.notes, 'Doctor Scheduled Review') as display_desc
    FROM appointments a
    LEFT JOIN vaccines v ON a.vaccine_id = v.id
    WHERE a.child_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");
$all_appts_stmt->execute([$child_id]);
$all_appointments = $all_appts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Partition active vs archived vs scheduled
$upcoming_reviews = array_filter($all_appointments, fn($a) => in_array($a['status'], ['scheduled', 'confirmed']) && empty($a['vaccine_id']));
$active_vaccines = array_filter($all_appointments, fn($a) => !empty($a['vaccine_id']) && (empty($a['is_archived']) || $a['is_archived'] == 0));
$archived_visits = array_filter($all_appointments, fn($a) => !empty($a['is_archived']) && $a['is_archived'] == 1);
$arch_count = count($archived_visits);

// 3. Fetch Medical History & Timeline
$history = [];
$latest_weight = 'N/A';
$latest_temp = 'N/A';
$latest_hr = 'N/A';

$vitals_stmt = $pdo->prepare("SELECT * FROM clinical_vitals WHERE child_id = ? ORDER BY created_at DESC");
$vitals_stmt->execute([$child_id]);
$vitals = $vitals_stmt->fetchAll(PDO::FETCH_ASSOC);
if (!empty($vitals)) {
    $latest_weight = $vitals[0]['weight_kg'] ? $vitals[0]['weight_kg'] . ' kg' : 'N/A';
    $latest_temp = $vitals[0]['temperature'] ? $vitals[0]['temperature'] . '°C' : 'N/A';
    $latest_hr = $vitals[0]['heart_rate'] ? $vitals[0]['heart_rate'] . ' bpm' : 'N/A';
}

foreach ($vitals as $v) {
    $reason = "Clinical Vitals • Temp: " . ($v['temperature'] ? "{$v['temperature']}°C" : "—") .
              " • HR: " . ($v['heart_rate'] ? "{$v['heart_rate']} bpm" : "—") .
              " • Weight: " . ($v['weight_kg'] ? "{$v['weight_kg']} kg" : "—") .
              ($v['symptoms'] ? " • Symptoms: " . $v['symptoms'] : "");
    $history[] = [
        'type' => 'vitals',
        'title' => 'Triage & Clinical Vitals',
        'date' => date('d M Y, h:i A', strtotime($v['created_at'])),
        'timestamp' => strtotime($v['created_at']),
        'desc' => $reason,
        'icon' => 'fa-stethoscope',
        'color' => !empty($v['is_critical']) ? 'red' : 'blue'
    ];
}

$consults_stmt = $pdo->prepare("SELECT c.*, COALESCE(u.name, 'Dr. Emily Chen') as doc_name FROM consultations c LEFT JOIN users u ON c.doctor_id = u.id WHERE c.child_id = ? ORDER BY c.created_at DESC");
$consults_stmt->execute([$child_id]);
$consults = $consults_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($consults as $c) {
    $desc = "Assessment: " . $c['notes'];
    if (!empty($c['next_review_date'])) {
        $desc .= " • Next Scheduled Review: " . date('d M Y', strtotime($c['next_review_date']));
    }
    $history[] = [
        'type' => 'consult',
        'title' => 'Physician Consultation (' . $c['doc_name'] . ')',
        'date' => date('d M Y, h:i A', strtotime($c['created_at'])),
        'timestamp' => strtotime($c['created_at']),
        'desc' => $desc,
        'icon' => 'fa-user-doctor',
        'color' => 'green'
    ];
}

$tasks_stmt = $pdo->prepare("SELECT * FROM nurse_tasks WHERE child_id = ? ORDER BY created_at DESC");
$tasks_stmt->execute([$child_id]);
$tasks = $tasks_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($tasks as $t) {
    $history[] = [
        'type' => 'task',
        'title' => 'Nurse Delegated Care (' . ucfirst($t['status']) . ')',
        'date' => date('d M Y, h:i A', strtotime($t['created_at'])),
        'timestamp' => strtotime($t['created_at']),
        'desc' => $t['task_description'],
        'icon' => 'fa-user-nurse',
        'color' => $t['status'] === 'completed' ? 'green' : 'orange'
    ];
}

// Sort timeline descending
usort($history, fn($a, $b) => $b['timestamp'] - $a['timestamp']);

$pending_alerts = (int)$pdo->query("SELECT COUNT(*) FROM triage_referrals WHERE status = 'pending'")->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE (receiver_id = ? OR receiver_id IS NULL) AND is_read = 0");
$stmt->execute([$doctor_id]);
$unread_msg_count = (int)$stmt->fetchColumn();

$gender = strtolower($patient['gender'] ?? 'unknown');
$avatar_class = ($gender === 'female' || $gender === 'f') ? 'avatar-girl' : 'avatar-boy';
$initials = strtoupper(substr(trim($patient['name']), 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($patient['name']) ?> — Clinical File & EHR — VaxiCare</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/doctor_theme.css">
    <style>
        /* Modern Clinical File Overhaul Styles */
        .clinical-header-card {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            position: relative;
        }

        .patient-avatar-box {
            width: 72px;
            height: 72px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 800;
            flex-shrink: 0;
            text-transform: uppercase;
        }
        .avatar-boy { background: #e0f2fe; color: #0284c7; border: 2px solid #bae6fd; }
        .avatar-girl { background: #ffe4e6; color: #e11d48; border: 2px solid #fecdd3; }

        .patient-meta-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1.5px solid #f1f5f9;
        }

        .meta-stat-pill {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .meta-stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }

        /* Clinical Tabs System */
        .clinical-tab-bar {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 24px;
            overflow-x: auto;
            scrollbar-width: thin;
            padding-bottom: 2px;
        }

        .clinical-tab-btn {
            background: none;
            border: none;
            padding: 12px 18px;
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 10px 10px 0 0;
            position: relative;
            transition: all 0.2s;
            white-space: nowrap;
            text-decoration: none;
        }

        .clinical-tab-btn:hover {
            color: #0284c7;
            background: #f0f9ff;
        }

        .clinical-tab-btn.active {
            color: #0284c7;
            background: #ffffff;
            box-shadow: 0 -2px 0 0 #0284c7 inset;
        }

        .clinical-tab-btn .tab-count {
            background: #e2e8f0;
            color: #475569;
            font-size: 11px;
            padding: 2px 7px;
            border-radius: 100px;
            font-weight: 800;
        }

        .clinical-tab-btn.active .tab-count {
            background: #e0f2fe;
            color: #0284c7;
        }

        .tab-pane {
            display: none;
        }
        .tab-pane.active {
            display: block;
        }

        /* Form & Cards */
        .dr-form-card {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.03);
        }

        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 768px) {
            .form-row-2, .form-row-3 { grid-template-columns: 1fr; }
        }

        .dr-label {
            display: block;
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            margin-bottom: 6px;
        }

        .dr-input, .dr-select, .dr-textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            font-size: 13.5px;
            color: #0f172a;
            outline: none;
            background: #ffffff;
            transition: all 0.2s;
            font-family: inherit;
            box-sizing: border-box;
        }

        .dr-input:focus, .dr-select:focus, .dr-textarea:focus {
            border-color: #0284c7;
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.12);
        }

        .quick-preset-pill {
            display: inline-block;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 100px;
            padding: 4px 10px;
            font-size: 11.5px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            margin: 0 4px 6px 0;
            transition: all 0.15s;
        }
        .quick-preset-pill:hover {
            background: #e0f2fe;
            color: #0284c7;
            border-color: #93c5fd;
        }

        /* Live Preview Card */
        .rx-preview-card {
            background: #f8fafc;
            border: 1.5px dashed #0284c7;
            border-radius: 14px;
            padding: 18px 20px;
            margin-top: 18px;
        }

        /* Conflict Alert Banner */
        .conflict-banner {
            background: #fef2f2;
            border: 1.5px solid #f87171;
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 20px;
        }

        /* Modal Dialog */
        .dr-modal-backdrop {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .dr-modal-backdrop.active {
            display: flex;
        }
        .dr-modal-box {
            background: #ffffff;
            border-radius: 18px;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            animation: modalFadeIn 0.2s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
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
            <a href="patients.php" class="sidebar-item active">
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
            <a href="messages.php" class="sidebar-item">
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

        <!-- Elevated Topbar -->
        <header class="app-topbar">
            <div style="display: flex; align-items: center;">
                <button type="button" class="mobile-nav-toggle" id="sidebarToggle" aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-left" style="display: flex; flex-direction: row; align-items: center; gap: 14px; flex-wrap: wrap;">
                    <h1><?= htmlspecialchars($patient['name']) ?></h1>
                    <div class="topbar-breadcrumbs" style="display: flex; align-items: center; gap: 6px; margin: 0;">
                        <span>Doctor Station</span>
                        <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #cbd5e1;"></i>
                        <a href="patients.php" style="color: #64748b; text-decoration: none;">Patients Directory</a>
                        <i class="fa-solid fa-chevron-right" style="font-size: 9px; color: #cbd5e1;"></i>
                        <span class="current">Clinical Patient Record</span>
                    </div>
                </div>
            </div>

            <div class="topbar-center">
                <a href="patients.php" class="dr-btn dr-btn-outline dr-btn-sm" style="border-radius: 100px; padding: 7px 16px;">
                    <i class="fa-solid fa-arrow-left"></i> Back to Directory
                </a>
            </div>

            <div class="topbar-right">
                <div class="topbar-control-pill">
                    <a href="messages.php" class="topbar-action-btn" title="Consult Messages">
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

        <!-- Notification Alerts -->
        <?php if (!empty($message)): ?>
            <div style="background: #e6f8f3; border: 1.5px solid #a7f3d0; color: #047857; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-check" style="font-size: 18px; color: #05cd99;"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error) && !$conflict_alert): ?>
            <div style="background: #fef2f2; border: 1.5px solid #fecdd3; color: #991b1b; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-circle-exclamation" style="font-size: 18px; color: #ef4444;"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Conflict Alert Banner -->
        <?php if ($conflict_alert): ?>
            <div class="conflict-banner">
                <div style="display: flex; align-items: flex-start; gap: 14px;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 24px; color: #ef4444; margin-top: 2px;"></i>
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 6px 0; font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 800; color: #991b1b;">
                            Appointment Conflict Detected
                        </h4>
                        <p style="margin: 0 0 12px 0; font-size: 13.5px; color: #7f1d1d; line-height: 1.45;">
                            <?= htmlspecialchars($conflict_alert['message']) ?>
                        </p>
                        <?php if (!empty($conflict_alert['available_slots'])): ?>
                            <div style="font-size: 12px; font-weight: 800; color: #991b1b; text-transform: uppercase; margin-bottom: 6px;">
                                Recommended Alternative Slots:
                            </div>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <?php foreach ($conflict_alert['available_slots'] as $slot): ?>
                                    <button type="button" class="dr-btn dr-btn-outline dr-btn-sm" 
                                            onclick="selectAlternativeSlot('<?= $slot ?>')"
                                            style="background: #ffffff; border-color: #fca5a5; color: #991b1b; font-weight: 700;">
                                        <i class="fa-regular fa-clock"></i> <?= date('h:i A', strtotime($slot)) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Patient Clinical Header Card (Fully Responsive & Non-Clipping) -->
        <div class="clinical-header-card">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap;">
                <div style="display: flex; gap: 18px; align-items: center; flex-wrap: wrap;">
                    <div class="patient-avatar-box <?= $avatar_class ?>">
                        <?= $initials ?>
                    </div>
                    <div>
                        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                            <h2 style="font-family: 'Space Grotesk', sans-serif; font-size: 24px; font-weight: 800; color: #0f172a; margin: 0;">
                                <?= htmlspecialchars($patient['name']) ?>
                            </h2>
                            <span class="badge-status <?= ($gender === 'female' || $gender === 'f') ? 'badge-red-soft' : 'badge-blue' ?>">
                                <?= htmlspecialchars(ucfirst($gender)) ?>
                            </span>
                            <span class="badge-status badge-green">
                                PID #<?= str_pad($patient['id'], 4, '0', STR_PAD_LEFT) ?>
                            </span>
                        </div>
                        <div style="font-size: 13.5px; color: #64748b; margin-top: 5px; font-weight: 600;">
                            Age <?= calculateAge($patient['date_of_birth']) ?> &bull; Born <?= date('d M Y', strtotime($patient['date_of_birth'])) ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Action Buttons -->
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" onclick="switchTab('prescriptions')" class="dr-btn dr-btn-primary dr-btn-sm">
                        <i class="fa-solid fa-prescription"></i> New Prescription
                    </button>
                    <button type="button" onclick="switchTab('reviews')" class="dr-btn dr-btn-outline dr-btn-sm" style="background: #ffffff;">
                        <i class="fa-regular fa-calendar-plus"></i> Schedule Review
                    </button>
                </div>
            </div>

            <!-- Patient Metadata Metrics Strip -->
            <div class="patient-meta-strip">
                <div class="meta-stat-pill">
                    <div class="meta-stat-icon" style="background: #e0f2fe; color: #0284c7;">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase;">Primary Guardian</div>
                        <div style="font-size: 14px; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($patient['guardian_name']) ?></div>
                    </div>
                </div>

                <div class="meta-stat-pill">
                    <div class="meta-stat-icon" style="background: #e6f8f3; color: #05cd99;">
                        <i class="fa-solid fa-phone"></i>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase;">Guardian Phone (SMS)</div>
                        <div style="font-size: 14px; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($patient['guardian_phone']) ?></div>
                    </div>
                </div>

                <div class="meta-stat-pill">
                    <div class="meta-stat-icon" style="background: #fff4e5; color: #f59e0b;">
                        <i class="fa-solid fa-weight-scale"></i>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase;">Recorded Weight</div>
                        <div style="font-size: 14px; font-weight: 700; color: #0f172a;"><?= htmlspecialchars($latest_weight) ?></div>
                    </div>
                </div>

                <div class="meta-stat-pill">
                    <div class="meta-stat-icon" style="background: #fee2e2; color: #ef4444;">
                        <i class="fa-solid fa-heart-pulse"></i>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase;">Temperature & HR</div>
                        <div style="font-size: 14px; font-weight: 700; color: #0f172a;"><?= $latest_temp ?> &bull; <?= $latest_hr ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Clinical Tabs Navigation Bar -->
        <div class="clinical-tab-bar">
            <button type="button" class="clinical-tab-btn <?= $active_tab === 'overview' ? 'active' : '' ?>" onclick="switchTab('overview')">
                <i class="fa-solid fa-notes-medical"></i> Overview & History
            </button>
            <button type="button" class="clinical-tab-btn <?= $active_tab === 'prescriptions' ? 'active' : '' ?>" onclick="switchTab('prescriptions')">
                <i class="fa-solid fa-pills"></i> Prescriptions
                <span class="tab-count"><?= count($prescriptions) ?></span>
            </button>
            <button type="button" class="clinical-tab-btn <?= $active_tab === 'reviews' ? 'active' : '' ?>" onclick="switchTab('reviews')">
                <i class="fa-regular fa-calendar-check"></i> Schedule Review
                <span class="tab-count"><?= count($upcoming_reviews) ?></span>
            </button>
            <button type="button" class="clinical-tab-btn <?= $active_tab === 'appointments' ? 'active' : '' ?>" onclick="switchTab('appointments')">
                <i class="fa-solid fa-calendar-days"></i> Appointments & Cancellation
                <span class="tab-count"><?= count($all_appointments) ?></span>
            </button>
            <button type="button" class="clinical-tab-btn <?= $active_tab === 'immunization' ? 'active' : '' ?>" onclick="switchTab('immunization')">
                <i class="fa-solid fa-syringe"></i> Vaccine Passport
                <span class="tab-count"><?= count($active_vaccines) ?></span>
            </button>
        </div>

        <!-- =========================================================================
             TAB 1: CLINICAL OVERVIEW & HISTORY
             ========================================================================= -->
        <div id="tab-overview" class="tab-pane <?= $active_tab === 'overview' ? 'active' : '' ?>">
            <div class="dr-grid-2">
                <!-- Triage Timeline -->
                <div class="dr-card">
                    <div class="dr-card-header">
                        <div class="dr-card-title">
                            <i class="fa-solid fa-clock-rotate-left" style="color: #0284c7;"></i>
                            <span>Clinical Triage & History Timeline</span>
                        </div>
                        <span class="badge-status badge-blue"><?= count($history) ?> Events</span>
                    </div>
                    <div class="dr-card-body" style="max-height: 480px; overflow-y: auto;">
                        <?php if (empty($history)): ?>
                            <div style="text-align: center; padding: 40px 12px; color: #64748b;">
                                <i class="fa-solid fa-stethoscope" style="font-size: 36px; color: #cbd5e1; margin-bottom: 10px; display: block;"></i>
                                <div style="font-weight: 700;">No clinical events logged yet.</div>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 14px;">
                                <?php foreach ($history as $h): ?>
                                    <div style="display: flex; gap: 14px; align-items: flex-start; padding: 12px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;">
                                        <div class="activity-badge badge-<?= $h['color'] ?>-bg">
                                            <i class="fa-solid <?= $h['icon'] ?>"></i>
                                        </div>
                                        <div style="flex: 1; min-width: 0;">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                <span style="font-weight: 700; font-size: 13.5px; color: #0f172a;"><?= htmlspecialchars($h['title']) ?></span>
                                                <span style="font-size: 11px; color: #0284c7; font-weight: 700;"><?= $h['date'] ?></span>
                                            </div>
                                            <div style="font-size: 12.5px; color: #475569; line-height: 1.45;">
                                                <?= htmlspecialchars($h['desc']) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Add Consultation Notes Form -->
                <div class="dr-card">
                    <div class="dr-card-header">
                        <div class="dr-card-title">
                            <i class="fa-solid fa-file-pen" style="color: #0284c7;"></i>
                            <span>Record Physician Consultation</span>
                        </div>
                    </div>
                    <div class="dr-card-body">
                        <form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="add_consultation">
                            
                            <div style="margin-bottom: 16px;">
                                <label class="dr-label">Clinical Assessment, Diagnosis & Treatment Plan</label>
                                <textarea name="notes" class="dr-textarea" rows="4" placeholder="Document clinical findings, examination notes, differential diagnosis, and planned therapy..." required></textarea>
                            </div>

                            <div style="margin-bottom: 16px;">
                                <label class="dr-label">Target Next Review Date (Optional)</label>
                                <input type="date" name="next_review_date" class="dr-input" min="<?= date('Y-m-d') ?>">
                            </div>

                            <div style="margin-bottom: 20px;">
                                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #475569; cursor: pointer;">
                                    <input type="checkbox" name="auto_archive" value="1" checked style="width: 16px; height: 16px;">
                                    <span>Auto-archive completed immunization visits upon saving assessment</span>
                                </label>
                            </div>

                            <button type="submit" class="dr-btn dr-btn-primary" style="width: 100%; justify-content: center; padding: 11px;">
                                <i class="fa-solid fa-floppy-disk"></i> Save Consultation Assessment
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             TAB 2: PRESCRIPTION MANAGEMENT (SAVE PRESCRIPTION & MOBILE SYNC)
             ========================================================================= -->
        <div id="tab-prescriptions" class="tab-pane <?= $active_tab === 'prescriptions' ? 'active' : '' ?>">
            <div class="dr-grid-2">
                <!-- Author New Prescription -->
                <div class="dr-form-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1.5px solid #f1f5f9; padding-bottom: 12px;">
                        <h3 style="margin: 0; font-family: 'Space Grotesk', sans-serif; font-size: 17px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-prescription" style="color: #0284c7;"></i>
                            <span>Write Prescription & Sync to Mobile</span>
                        </h3>
                        <span class="badge-status badge-green"><i class="fa-solid fa-cloud-arrow-up"></i> Central DB Sync</span>
                    </div>

                    <form method="POST" id="prescriptionForm">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="save_prescription">

                        <!-- Medication Name with quick presets -->
                        <div style="margin-bottom: 14px;">
                            <label class="dr-label">Prescribed Medication <span style="color: #ef4444;">*</span></label>
                            <input type="text" name="medication_name" id="rx_medication" class="dr-input" placeholder="e.g. Paracetamol Pediatric Syrup" required oninput="updateRxPreview()">
                            <div style="margin-top: 6px;">
                                <span class="quick-preset-pill" onclick="setPreset('rx_medication', 'Paracetamol Pediatric Suspension 120mg/5ml')">+ Paracetamol 120mg/5ml</span>
                                <span class="quick-preset-pill" onclick="setPreset('rx_medication', 'Amoxicillin Oral Suspension 250mg/5ml')">+ Amoxicillin 250mg/5ml</span>
                                <span class="quick-preset-pill" onclick="setPreset('rx_medication', 'Zinc Sulfate Pediatric Tablets 20mg')">+ Zinc Sulfate 20mg</span>
                                <span class="quick-preset-pill" onclick="setPreset('rx_medication', 'Oral Rehydration Salts (ORS) Sachet 20.5g')">+ ORS Sachet</span>
                                <span class="quick-preset-pill" onclick="setPreset('rx_medication', 'Salbutamol Pediatric Syrup 2mg/5ml')">+ Salbutamol 2mg/5ml</span>
                                <span class="quick-preset-pill" onclick="setPreset('rx_medication', 'Ibuprofen Pediatric Suspension 100mg/5ml')">+ Ibuprofen 100mg</span>
                            </div>
                        </div>

                        <!-- Dosage and Frequency -->
                        <div class="form-row-2" style="margin-bottom: 14px;">
                            <div>
                                <label class="dr-label">Dosage <span style="color: #ef4444;">*</span></label>
                                <input type="text" name="dosage" id="rx_dosage" class="dr-input" placeholder="e.g. 5 ml orally" required oninput="updateRxPreview()">
                                <div style="margin-top: 4px;">
                                    <span class="quick-preset-pill" onclick="setPreset('rx_dosage', '2.5 ml')">2.5ml</span>
                                    <span class="quick-preset-pill" onclick="setPreset('rx_dosage', '5 ml')">5ml</span>
                                    <span class="quick-preset-pill" onclick="setPreset('rx_dosage', '1 tablet')">1 tab</span>
                                    <span class="quick-preset-pill" onclick="setPreset('rx_dosage', '1 sachet in 1L water')">1 sachet</span>
                                </div>
                            </div>
                            <div>
                                <label class="dr-label">Frequency of Administration <span style="color: #ef4444;">*</span></label>
                                <select name="frequency" id="rx_frequency" class="dr-select" required onchange="updateRxPreview()">
                                    <option value="">Select frequency...</option>
                                    <option value="Once daily (OD)">Once daily (OD)</option>
                                    <option value="Twice daily (BD / every 12 hrs)" selected>Twice daily (BD / every 12 hrs)</option>
                                    <option value="Three times daily (TDS / every 8 hrs)">Three times daily (TDS / every 8 hrs)</option>
                                    <option value="Four times daily (QDS / every 6 hrs)">Four times daily (QDS / every 6 hrs)</option>
                                    <option value="Every 4 to 6 hours as needed (PRN)">Every 4-6 hrs as needed (PRN)</option>
                                    <option value="Once at bedtime (Nocte)">Once at bedtime (Nocte)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Duration -->
                        <div style="margin-bottom: 14px;">
                            <label class="dr-label">Duration of Treatment <span style="color: #ef4444;">*</span></label>
                            <div class="form-row-2">
                                <input type="text" name="duration" id="rx_duration" class="dr-input" placeholder="e.g. 5 days" required oninput="updateRxPreview()">
                                <div style="display: flex; gap: 4px; align-items: center; flex-wrap: wrap;">
                                    <span class="quick-preset-pill" onclick="setPreset('rx_duration', '3 days')">3 days</span>
                                    <span class="quick-preset-pill" onclick="setPreset('rx_duration', '5 days')">5 days</span>
                                    <span class="quick-preset-pill" onclick="setPreset('rx_duration', '7 days')">7 days</span>
                                    <span class="quick-preset-pill" onclick="setPreset('rx_duration', '14 days')">14 days</span>
                                </div>
                            </div>
                        </div>

                        <!-- Patient Instructions -->
                        <div style="margin-bottom: 14px;">
                            <label class="dr-label">Instructions for Guardian & Patient</label>
                            <textarea name="instructions" id="rx_instructions" class="dr-textarea" rows="2" placeholder="e.g. Give after food. Complete the full course even if symptoms improve." oninput="updateRxPreview()"></textarea>
                        </div>

                        <!-- Nurse Delegation Checkbox -->
                        <div style="margin-bottom: 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px;">
                            <label style="display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; color: #1e293b; cursor: pointer;">
                                <input type="checkbox" name="delegate_to_nurse" value="1" style="width: 17px; height: 17px;">
                                <span>Dispatch administration or monitoring order to Attending Nurse</span>
                            </label>
                        </div>

                        <!-- Live Review Preview Box -->
                        <div class="rx-preview-card" id="rxPreviewBox">
                            <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #0284c7; letter-spacing: 0.5px; margin-bottom: 6px;">
                                <i class="fa-solid fa-eye"></i> Live Prescription Review
                            </div>
                            <div id="previewMed" style="font-size: 15px; font-weight: 800; color: #0f172a;">Paracetamol Pediatric Suspension</div>
                            <div id="previewRegimen" style="font-size: 13px; color: #0284c7; font-weight: 600; margin-top: 2px;">
                                Dosage: 5 ml • Frequency: Twice daily • Duration: 5 days
                            </div>
                            <div id="previewInst" style="font-size: 12.5px; color: #64748b; margin-top: 4px;">
                                Instructions: Give after food with plenty of water.
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="dr-btn dr-btn-primary" style="width: 100%; justify-content: center; padding: 12px; margin-top: 18px; font-size: 14px;">
                            <i class="fa-solid fa-floppy-disk"></i> Save Prescription
                        </button>
                    </form>
                </div>

                <!-- Active & Past Prescriptions Table -->
                <div class="dr-card">
                    <div class="dr-card-header">
                        <div class="dr-card-title">
                            <i class="fa-solid fa-pills" style="color: #05cd99;"></i>
                            <span>Prescriptions History (<?= count($prescriptions) ?>)</span>
                        </div>
                        <span class="badge-status badge-blue"><?= $active_rx_count ?> Active</span>
                    </div>
                    <div class="dr-card-body" style="max-height: 520px; overflow-y: auto;">
                        <?php if (empty($prescriptions)): ?>
                            <div style="text-align: center; padding: 48px 12px; color: #64748b;">
                                <i class="fa-solid fa-prescription-bottle-medical" style="font-size: 40px; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
                                <div style="font-weight: 700; font-size: 15px; color: #0f172a;">No Prescriptions on File</div>
                                <div style="font-size: 13px; margin-top: 4px;">Prescribed medications will appear here and sync automatically to the patient's mobile app under Pharmacy.</div>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 14px;">
                                <?php foreach ($prescriptions as $p): ?>
                                    <div style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.02);">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                            <div>
                                                <h4 style="margin: 0; font-size: 15px; font-weight: 800; color: #0f172a;">
                                                    <?= htmlspecialchars($p['medication_name']) ?>
                                                </h4>
                                                <div style="font-size: 12.5px; color: #0284c7; font-weight: 600; margin-top: 2px;">
                                                    <?= htmlspecialchars($p['dosage']) ?> &bull; <?= htmlspecialchars($p['frequency']) ?> &bull; <?= htmlspecialchars($p['duration']) ?>
                                                </div>
                                            </div>
                                            <span class="badge-status <?= $p['status'] === 'active' ? 'badge-green' : 'badge-blue' ?>">
                                                <?= ucfirst(htmlspecialchars($p['status'])) ?>
                                            </span>
                                        </div>

                                        <?php if (!empty($p['instructions'])): ?>
                                            <div style="background: #f8fafc; border-radius: 8px; padding: 8px 12px; font-size: 12.5px; color: #475569; margin-bottom: 10px;">
                                                <strong>Instructions:</strong> <?= htmlspecialchars($p['instructions']) ?>
                                            </div>
                                        <?php endif; ?>

                                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 8px;">
                                            <span><i class="fa-solid fa-user-doctor"></i> <?= htmlspecialchars($p['doctor_name']) ?></span>
                                            <span><i class="fa-regular fa-clock"></i> <?= date('d M Y, h:i A', strtotime($p['created_at'])) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             TAB 3: SCHEDULE NEXT REVIEW (WITH CONFLICT PREVENTION)
             ========================================================================= -->
        <div id="tab-reviews" class="tab-pane <?= $active_tab === 'reviews' ? 'active' : '' ?>">
            <div class="dr-grid-2">
                <!-- Review Form -->
                <div class="dr-form-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1.5px solid #f1f5f9; padding-bottom: 12px;">
                        <h3 style="margin: 0; font-family: 'Space Grotesk', sans-serif; font-size: 17px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                            <i class="fa-regular fa-calendar-plus" style="color: #0284c7;"></i>
                            <span>Schedule Patient's Next Review</span>
                        </h3>
                        <span class="badge-status badge-blue"><i class="fa-solid fa-shield-halved"></i> Conflict Protected</span>
                    </div>

                    <form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="schedule_review">

                        <div class="form-row-2" style="margin-bottom: 14px;">
                            <div>
                                <label class="dr-label">Review Date <span style="color: #ef4444;">*</span></label>
                                <input type="date" name="review_date" id="review_date" class="dr-input" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
                            </div>
                            <div>
                                <label class="dr-label">Review Time <span style="color: #ef4444;">*</span></label>
                                <input type="time" name="review_time" id="review_time" class="dr-input" value="10:00" required>
                            </div>
                        </div>

                        <!-- Quick Times -->
                        <div style="margin-bottom: 14px;">
                            <span class="quick-preset-pill" onclick="setPreset('review_time', '09:00')">09:00 AM</span>
                            <span class="quick-preset-pill" onclick="setPreset('review_time', '10:00')">10:00 AM</span>
                            <span class="quick-preset-pill" onclick="setPreset('review_time', '11:30')">11:30 AM</span>
                            <span class="quick-preset-pill" onclick="setPreset('review_time', '14:00')">02:00 PM</span>
                            <span class="quick-preset-pill" onclick="setPreset('review_time', '15:30')">03:30 PM</span>
                        </div>

                        <div style="margin-bottom: 14px;">
                            <label class="dr-label">Review Type or Reason <span style="color: #ef4444;">*</span></label>
                            <select name="review_type" class="dr-select" required>
                                <option value="Follow-up Clinical Assessment">Follow-up Clinical Assessment</option>
                                <option value="Post-treatment Evaluation">Post-treatment Evaluation</option>
                                <option value="Pediatric Growth & Nutrition Monitoring">Pediatric Growth & Nutrition Monitoring</option>
                                <option value="Vaccination & Passport Follow-up">Vaccination & Passport Follow-up</option>
                                <option value="Specialist Clinical Review">Specialist Clinical Review</option>
                            </select>
                        </div>

                        <div style="margin-bottom: 18px;">
                            <label class="dr-label">Clinical Instructions or Appointment Notes</label>
                            <textarea name="review_notes" class="dr-textarea" rows="3" placeholder="e.g. Fasting not required. Bring previous health card and complete oral antibiotic course prior to appointment."></textarea>
                        </div>

                        <div style="background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 12px; padding: 12px 14px; margin-bottom: 18px;">
                            <div style="font-size: 12px; font-weight: 700; color: #166534; display: flex; align-items: center; gap: 6px;">
                                <i class="fa-solid fa-bell"></i> Automated Multi-Interval Reminders Queued
                            </div>
                            <div style="font-size: 11.5px; color: #15803d; margin-top: 3px;">
                                Patient will automatically receive reminder notifications 7 days, 5 days, and 2 days before the review.
                            </div>
                        </div>

                        <button type="submit" class="dr-btn dr-btn-primary" style="width: 100%; justify-content: center; padding: 12px; font-size: 14px;">
                            <i class="fa-solid fa-calendar-check"></i> Save Review Appointment
                        </button>
                    </form>
                </div>

                <!-- Upcoming Scheduled Reviews -->
                <div class="dr-card">
                    <div class="dr-card-header">
                        <div class="dr-card-title">
                            <i class="fa-regular fa-calendar-days" style="color: #0284c7;"></i>
                            <span>Upcoming Reviews for Patient</span>
                        </div>
                        <span class="badge-status badge-blue"><?= count($upcoming_reviews) ?> Scheduled</span>
                    </div>
                    <div class="dr-card-body" style="max-height: 520px; overflow-y: auto;">
                        <?php if (empty($upcoming_reviews)): ?>
                            <div style="text-align: center; padding: 48px 12px; color: #64748b;">
                                <i class="fa-regular fa-calendar-check" style="font-size: 40px; color: #cbd5e1; margin-bottom: 12px; display: block;"></i>
                                <div style="font-weight: 700; font-size: 15px; color: #0f172a;">No Upcoming Reviews</div>
                                <div style="font-size: 13px; margin-top: 4px;">Use the form to schedule the patient's next clinical review.</div>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 14px;">
                                <?php foreach ($upcoming_reviews as $rev): ?>
                                    <div style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.02);">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                            <div>
                                                <h4 style="margin: 0; font-size: 15px; font-weight: 800; color: #0f172a;">
                                                    <?= htmlspecialchars($rev['display_name']) ?>
                                                </h4>
                                                <div style="font-size: 13px; color: #0284c7; font-weight: 700; margin-top: 3px;">
                                                    <i class="fa-regular fa-calendar"></i> <?= date('d M Y', strtotime($rev['appointment_date'])) ?> 
                                                    &bull; <i class="fa-regular fa-clock"></i> <?= date('h:i A', strtotime($rev['appointment_time'])) ?>
                                                </div>
                                            </div>
                                            <span class="badge-status badge-blue"><i class="fa-solid fa-clock"></i> Scheduled</span>
                                        </div>

                                        <?php if (!empty($rev['notes'])): ?>
                                            <div style="background: #f8fafc; border-radius: 8px; padding: 8px 12px; font-size: 12.5px; color: #475569; margin-bottom: 12px;">
                                                <?= htmlspecialchars($rev['notes']) ?>
                                            </div>
                                        <?php endif; ?>

                                        <div style="display: flex; justify-content: flex-end; gap: 8px;">
                                            <button type="button" class="dr-btn dr-btn-outline dr-btn-sm" 
                                                    style="color: #ef4444; border-color: #fca5a5;" 
                                                    onclick="openCancelModal(<?= $rev['id'] ?>, '<?= htmlspecialchars(addslashes($rev['display_name'])) ?>', '<?= date('d M Y, h:i A', strtotime($rev['appointment_date'] . ' ' . $rev['appointment_time'])) ?>')">
                                                <i class="fa-solid fa-ban"></i> Cancel Review
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- =========================================================================
             TAB 4: ALL APPOINTMENTS & CANCELLATION MANAGEMENT
             ========================================================================= -->
        <div id="tab-appointments" class="tab-pane <?= $active_tab === 'appointments' ? 'active' : '' ?>">
            <div class="dr-card">
                <div class="dr-card-header">
                    <div class="dr-card-title">
                        <i class="fa-solid fa-calendar-days" style="color: #0284c7;"></i>
                        <span>All Patient Appointments & Clinical Reviews</span>
                    </div>
                    <span class="badge-status badge-blue"><?= count($all_appointments) ?> Total Visits</span>
                </div>
                <div class="dr-table-wrap">
                    <table class="dr-table">
                        <thead>
                            <tr>
                                <th>Appointment / Service</th>
                                <th>Date & Time</th>
                                <th>Location / Suite</th>
                                <th>Status</th>
                                <th style="text-align: right;">Clinical Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_appointments)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px; color: #64748b;">
                                        No appointments on record for this patient.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_appointments as $a): 
                                    $is_active = in_array($a['status'], ['scheduled', 'confirmed']);
                                    $is_cancelled = $a['status'] === 'cancelled';
                                    $is_completed = $a['status'] === 'completed';
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 700; color: #0f172a;">
                                                <?= htmlspecialchars($a['display_name']) ?>
                                            </div>
                                            <?php if (!empty($a['cancellation_reason'])): ?>
                                                <div style="font-size: 11.5px; color: #ef4444; margin-top: 2px;">
                                                    <i class="fa-solid fa-circle-xmark"></i> Reason: <?= htmlspecialchars($a['cancellation_reason']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600; color: #1e293b;"><?= date('d M Y', strtotime($a['appointment_date'])) ?></div>
                                            <div style="font-size: 11.5px; color: #64748b;"><?= date('h:i A', strtotime($a['appointment_time'])) ?></div>
                                        </td>
                                        <td>
                                            <span style="font-size: 12.5px; color: #475569;">
                                                <i class="fa-solid fa-door-open" style="color: #94a3b8;"></i> <?= htmlspecialchars($a['room_number'] ?? 'Doctor Suite 2') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($is_completed): ?>
                                                <span class="badge-status badge-green"><i class="fa-solid fa-circle-check"></i> Completed</span>
                                            <?php elseif ($is_cancelled): ?>
                                                <span class="badge-status badge-red-soft"><i class="fa-solid fa-ban"></i> Cancelled</span>
                                            <?php else: ?>
                                                <span class="badge-status badge-blue"><i class="fa-solid fa-clock"></i> Scheduled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <?php if ($is_active): ?>
                                                <button type="button" class="dr-btn dr-btn-outline dr-btn-sm" 
                                                        style="color: #ef4444; border-color: #fca5a5; padding: 5px 12px; font-size: 11.5px;"
                                                        onclick="openCancelModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['display_name'])) ?>', '<?= date('d M Y, h:i A', strtotime($a['appointment_date'] . ' ' . $a['appointment_time'])) ?>')">
                                                    <i class="fa-solid fa-ban"></i> Cancel Visit
                                                </button>
                                            <?php elseif ($is_completed && empty($a['is_archived'])): ?>
                                                <form method="POST" style="display: inline;">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="action" value="archive_appointment">
                                                    <input type="hidden" name="appointment_id" value="<?= $a['id'] ?>">
                                                    <button type="submit" class="dr-btn dr-btn-outline dr-btn-sm" style="font-size: 11px; padding: 4px 8px;">
                                                        <i class="fa-solid fa-box-archive"></i> Archive
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 12px;">—</span>
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

        <!-- =========================================================================
             TAB 5: IMMUNIZATION PASSPORT
             ========================================================================= -->
        <div id="tab-immunization" class="tab-pane <?= $active_tab === 'immunization' ? 'active' : '' ?>">
            <div class="dr-card">
                <div class="dr-card-header">
                    <div class="dr-card-title">
                        <i class="fa-solid fa-syringe" style="color: #05cd99;"></i>
                        <span>Vaccine Doses & Passport</span>
                    </div>
                    <?php if ($arch_count > 0): ?>
                        <span class="badge-status badge-blue"><i class="fa-solid fa-box-archive"></i> <?= $arch_count ?> Archived</span>
                    <?php endif; ?>
                </div>
                <div class="dr-table-wrap">
                    <table class="dr-table">
                        <thead>
                            <tr>
                                <th>Vaccine</th>
                                <th>Scheduled Date</th>
                                <th>Status</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($active_vaccines)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 36px 12px; color: #64748b;">
                                        No active scheduled vaccines for this patient.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($active_vaccines as $vax): ?>
                                    <tr>
                                        <td style="font-weight: 700; color: #0f172a;">
                                            <?= htmlspecialchars($vax['display_name']) ?>
                                        </td>
                                        <td><?= date('d M Y', strtotime($vax['appointment_date'])) ?></td>
                                        <td>
                                            <?php if ($vax['status'] == 'completed'): ?>
                                                <span class="badge-status badge-green"><i class="fa-solid fa-circle-check"></i> Completed</span>
                                            <?php elseif ($vax['status'] == 'scheduled' || $vax['status'] == 'confirmed'): ?>
                                                <span class="badge-status badge-blue"><i class="fa-solid fa-clock"></i> Scheduled</span>
                                            <?php else: ?>
                                                <span class="badge-status badge-red-soft"><?= ucfirst(htmlspecialchars($vax['status'])) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <?php if ($vax['status'] == 'completed'): ?>
                                                <form method="POST" style="display: inline;">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                    <input type="hidden" name="action" value="archive_appointment">
                                                    <input type="hidden" name="appointment_id" value="<?= $vax['id'] ?>">
                                                    <button type="submit" class="dr-btn dr-btn-outline dr-btn-sm" style="font-size: 11px; padding: 4px 8px;">
                                                        <i class="fa-solid fa-box-archive"></i> Archive
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #cbd5e1; font-size: 12px;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (!empty($archived_visits)): ?>
                <div class="dr-card">
                    <div class="dr-card-header">
                        <div class="dr-card-title">
                            <i class="fa-solid fa-box-archive" style="color: #64748b;"></i>
                            <span>Archived Visits (<?= count($archived_visits) ?>)</span>
                        </div>
                    </div>
                    <div class="dr-table-wrap">
                        <table class="dr-table">
                            <thead>
                                <tr>
                                    <th>Vaccine</th>
                                    <th>Visit Date</th>
                                    <th>Archived At</th>
                                    <th style="text-align: right;">Restore</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($archived_visits as $arc): ?>
                                    <tr style="opacity: 0.85;">
                                        <td style="font-weight: 700; color: #475569;"><?= htmlspecialchars($arc['display_name']) ?></td>
                                        <td><?= date('d M Y', strtotime($arc['appointment_date'])) ?></td>
                                        <td style="font-size: 12px; color: #64748b;"><?= $arc['archived_at'] ? date('d M Y, h:i A', strtotime($arc['archived_at'])) : 'Archived' ?></td>
                                        <td style="text-align: right;">
                                            <form method="POST" style="display: inline;">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="unarchive_appointment">
                                                <input type="hidden" name="appointment_id" value="<?= $arc['id'] ?>">
                                                <button type="submit" class="dr-btn dr-btn-outline dr-btn-sm" style="font-size: 11px; padding: 4px 10px;">
                                                    <i class="fa-solid fa-rotate-left"></i> Restore
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Cancellation Modal Dialog -->
    <div class="dr-modal-backdrop" id="cancelModal">
        <div class="dr-modal-box">
            <div style="padding: 20px 24px; border-bottom: 1.5px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-family: 'Space Grotesk', sans-serif; font-size: 17px; font-weight: 800; color: #991b1b; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-triangle-exclamation" style="color: #ef4444;"></i>
                    <span>Cancel Patient Appointment</span>
                </h3>
                <button type="button" onclick="closeCancelModal()" style="background: none; border: none; font-size: 18px; color: #94a3b8; cursor: pointer;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <form method="POST">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="cancel_appointment">
                <input type="hidden" name="appointment_id" id="modal_appt_id" value="">

                <div style="padding: 20px 24px;">
                    <div style="background: #fef2f2; border: 1px solid #fecdd3; border-radius: 12px; padding: 12px 14px; margin-bottom: 16px;">
                        <div style="font-size: 13.5px; font-weight: 700; color: #991b1b;" id="modal_visit_name">Clinical Visit</div>
                        <div style="font-size: 12px; color: #b91c1c; margin-top: 2px;" id="modal_visit_time">Date & Time</div>
                    </div>

                    <p style="font-size: 13px; color: #475569; margin: 0 0 14px 0; line-height: 1.45;">
                        Cancelling will automatically send in-app and SMS notifications to the guardian and the attending nurse.
                    </p>

                    <label class="dr-label">Cancellation Reason <span style="color: #ef4444;">*</span></label>
                    <select name="cancellation_reason" id="modal_reason_select" class="dr-select" style="margin-bottom: 10px;" required onchange="handleReasonChange(this)">
                        <option value="Doctor unavailable due to emergency surgery">Doctor unavailable due to emergency surgery</option>
                        <option value="Physician clinical schedule adjustment">Physician clinical schedule adjustment</option>
                        <option value="Patient condition reviewed and stable - visit no longer required">Patient condition reviewed - visit no longer required</option>
                        <option value="Patient requested reschedule">Patient requested reschedule</option>
                        <option value="other">Other reason (Enter below)...</option>
                    </select>

                    <textarea name="custom_reason" id="modal_custom_reason" class="dr-textarea" rows="2" style="display: none;" placeholder="Enter specific clinical reason..."></textarea>
                </div>

                <div style="padding: 16px 24px; background: #f8fafc; border-top: 1.5px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeCancelModal()" class="dr-btn dr-btn-outline dr-btn-sm" style="background: #ffffff;">
                        Keep Appointment
                    </button>
                    <button type="submit" class="dr-btn dr-btn-primary dr-btn-sm" style="background: #ef4444; border-color: #ef4444;">
                        <i class="fa-solid fa-ban"></i> Confirm Cancellation
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        // Tab Switching
        function switchTab(tabId) {
            document.querySelectorAll('.clinical-tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));

            const targetPane = document.getElementById('tab-' + tabId);
            if (targetPane) {
                targetPane.classList.add('active');
            }

            // Find matching tab button
            document.querySelectorAll('.clinical-tab-btn').forEach(btn => {
                if (btn.getAttribute('onclick')?.includes(tabId)) {
                    btn.classList.add('active');
                }
            });

            // Update URL without reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.replaceState({}, '', url);
        }

        // Preset Helper
        function setPreset(inputId, val) {
            const input = document.getElementById(inputId);
            if (input) {
                input.value = val;
                input.dispatchEvent(new Event('input'));
                input.dispatchEvent(new Event('change'));
            }
        }

        // Live Prescription Preview
        function updateRxPreview() {
            const med = document.getElementById('rx_medication')?.value.trim() || 'Medication Name';
            const dose = document.getElementById('rx_dosage')?.value.trim() || 'Dose';
            const freq = document.getElementById('rx_frequency')?.value.trim() || 'Frequency';
            const dur = document.getElementById('rx_duration')?.value.trim() || 'Duration';
            const inst = document.getElementById('rx_instructions')?.value.trim() || 'No specific instructions entered.';

            document.getElementById('previewMed').innerText = med;
            document.getElementById('previewRegimen').innerText = `Dosage: ${dose} • Frequency: ${freq} • Duration: ${dur}`;
            document.getElementById('previewInst').innerText = `Instructions: ${inst}`;
        }

        // Slot Selector for Conflict Resolution
        function selectAlternativeSlot(time) {
            document.getElementById('review_time').value = time.substring(0, 5);
            switchTab('reviews');
        }

        // Cancellation Modal
        function openCancelModal(apptId, visitName, visitTime) {
            document.getElementById('modal_appt_id').value = apptId;
            document.getElementById('modal_visit_name').innerText = visitName;
            document.getElementById('modal_visit_time').innerText = visitTime;
            document.getElementById('cancelModal').classList.add('active');
        }

        function closeCancelModal() {
            document.getElementById('cancelModal').classList.remove('active');
        }

        function handleReasonChange(select) {
            const customBox = document.getElementById('modal_custom_reason');
            if (select.value === 'other') {
                customBox.style.display = 'block';
                customBox.required = true;
            } else {
                customBox.style.display = 'none';
                customBox.required = false;
            }
        }

        // Mobile Sidebar Toggle
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
