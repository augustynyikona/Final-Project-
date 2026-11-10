<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

if (!headers_sent()) {
    header('Content-Type: application/json');
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

$response = [
    'unread_count' => 0,
    'items' => []
];

try {
    // 1. Unread messages & In-System Booking Alerts
    $stmt = $pdo->prepare("
        SELECT m.id, m.subject, m.body, m.created_at, COALESCE(u.name, 'Clinic System') as sender_name, 'message' as type
        FROM messages m
        LEFT JOIN users u ON m.sender_id = u.id
        WHERE (m.receiver_id = ? OR m.recipient_role = ?) AND m.is_read = 0
        ORDER BY m.created_at DESC
        LIMIT 8
    ");
    $stmt->execute([$user_id, $role]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($messages as $m) {
        $is_appointment = (stripos($m['subject'], 'Appointment') !== false || stripos($m['subject'], 'Booking') !== false || stripos($m['subject'], 'Schedule') !== false);
        $link = 'messages.php';
        if ($is_appointment) {
            $link = ($role === 'guardian') ? 'my_appointments.php' : 'appointments.php';
        }
        $response['items'][] = [
            'id' => $m['id'],
            'title' => $is_appointment ? $m['subject'] : ('Message from ' . $m['sender_name']),
            'text' => !empty($m['body']) ? (mb_strlen($m['body']) > 80 ? mb_substr($m['body'], 0, 77) . '...' : $m['body']) : $m['subject'],
            'time' => date('H:i', strtotime($m['created_at'])),
            'icon' => $is_appointment ? 'fa-calendar-check' : 'fa-envelope',
            'link' => $link
        ];
    }

    // 2. Doctor Pending Referrals
    if ($role === 'doctor') {
        $stmt = $pdo->query("
            SELECT tr.id, tr.reason, tr.created_at, c.name as child_name
            FROM triage_referrals tr
            JOIN children c ON tr.child_id = c.id
            WHERE tr.status = 'pending'
            ORDER BY tr.created_at DESC
            LIMIT 5
        ");
        $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($referrals as $r) {
            $response['items'][] = [
                'id' => $r['id'],
                'title' => 'Critical Referral: ' . $r['child_name'],
                'text' => $r['reason'],
                'time' => date('H:i', strtotime($r['created_at'])),
                'icon' => 'fa-triangle-exclamation',
                'link' => 'triage.php'
            ];
        }
    }

    // 3. Pharmacist Pending Requisitions
    if ($role === 'pharmacy') {
        $stmt = $pdo->query("
            SELECT r.id, r.quantity, r.created_at, v.name as vaccine_name
            FROM requisitions r
            JOIN vaccines v ON r.vaccine_id = v.id
            WHERE r.status = 'Pending'
            ORDER BY r.created_at DESC
            LIMIT 5
        ");
        $reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($reqs as $rq) {
            $response['items'][] = [
                'id' => $rq['id'],
                'title' => 'Requisition Request',
                'text' => $rq['quantity'] . 'x ' . $rq['vaccine_name'],
                'time' => date('H:i', strtotime($rq['created_at'])),
                'icon' => 'fa-boxes-stacked',
                'link' => 'requisitions.php'
            ];
        }
    }

    $response['unread_count'] = count($response['items']);
    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
