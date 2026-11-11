<?php
/**
 * Shared security + audit helpers for VaxiCare.
 * Include AFTER session_start() — a second call is a harmless no-op.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Return (and lazily create) this session's CSRF token. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input ready to drop inside a <form>. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Constant-time token comparison. */
function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

/**
 * Abort the request when a POST arrives without a valid token.
 * Returns true when the token is good so callers can keep going.
 */
function csrf_guard(): bool
{
    if (csrf_valid()) {
        return true;
    }
    http_response_code(419);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Session expired</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:80px auto;padding:24px;'
        . 'border:1px solid #fca5a5;background:#fef2f2;border-radius:12px;color:#991b1b">'
        . '<h2 style="margin:0 0 8px">Security check failed</h2>'
        . '<p style="margin:0">This form was submitted without a valid security token. '
        . 'Reload the page and try again.</p></div>';
    exit;
}

/** Whitelist used by every role-assigning handler (matches the users.role ENUM). */
function valid_staff_role(string $role): bool
{
    return in_array($role, ['admin', 'doctor', 'nurse', 'pharmacy'], true);
}

/**
 * Minimum password policy for every account-creation and reset path.
 * Returns '' when acceptable, otherwise the reason it was rejected.
 */
function password_problem(string $password): string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    return '';
}

/**
 * Record a governance action. Never throws — auditing must not break a page.
 */
function audit_log(PDO $pdo, string $action, string $targetType = '', ?int $targetId = null, string $details = ''): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs
                (actor_id, actor_name, actor_role, action, target_type, target_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['name'] ?? ($_SESSION['user_name'] ?? null),
            $_SESSION['role'] ?? null,
            $action,
            $targetType !== '' ? $targetType : null,
            $targetId,
            $details !== '' ? mb_substr($details, 0, 490) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}
