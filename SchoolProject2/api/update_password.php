<?php
session_start();
include '../config/db.php';
require_once __DIR__ . '/../config/security.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please sign in.']);
    exit;
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // JSON-aware CSRF check: never emit an HTML 419 page to a fetch() caller.
    $tok = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($tok) || !hash_equals(csrf_token(), $tok)) {
        echo json_encode(['success' => false, 'message' => 'Security token expired. Reload and try again.']);
        exit;
    }
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass     = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
        echo json_encode(['success' => false, 'message' => 'All password fields are required.']);
        exit;
    }

    if (($pw_problem = password_problem($new_pass)) !== null) {
    echo json_encode(['success' => false, 'message' => $pw_problem]);
    exit;
  }

    if ($new_pass !== $confirm_pass) {
        echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match.']);
        exit;
    }

    // Verify current password
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $current_hash = $stmt->fetchColumn();

    if (!$current_hash || !password_verify($current_pass, $current_hash)) {
        echo json_encode(['success' => false, 'message' => 'Current password entered is incorrect.']);
        exit;
    }

    // Update to new password
    $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
    $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    if ($updateStmt->execute([$new_hash, $user_id])) {
        echo json_encode(['success' => true, 'message' => 'Password updated successfully in real time!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error while updating password.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
