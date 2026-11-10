<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../config/db.php';
require_once __DIR__ . '/../config/notifications_helper.php';

header('Content-Type: application/json');

// Allow admin, doctor, or direct cron access
$is_authorized = false;
if (isset($_SESSION['user_id']) && in_array($_SESSION['role'], ['admin', 'doctor', 'nurse'])) {
    $is_authorized = true;
}
if (php_sapi_name() === 'cli' || (isset($_GET['token']) && $_GET['token'] === 'vaxicare_cron_key_998')) {
    $is_authorized = true;
}

if (!$is_authorized) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Dispatch automated 7-day, 5-day, and 2-day appointment and review reminders
    $dispatched = dispatchAutomatedReminders($pdo);

    echo json_encode([
        'success' => true,
        'message' => "Automated reminder engine completed.",
        'dispatched_count' => $dispatched
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
