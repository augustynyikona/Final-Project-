<?php
require_once __DIR__ . '/config/db.php';

$hash = password_hash('password123', PASSWORD_DEFAULT);

// Update all guardians to password123 so the user can easily log in with their password
$stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE role = 'guardian'");
$stmt->execute([$hash]);

echo "Updated " . $stmt->rowCount() . " guardian accounts to password123\n";

$stmt = $pdo->query("SELECT id, name, phone, role FROM users WHERE role = 'guardian'");
while ($r = $stmt->fetch()) {
    echo "ID: " . $r['id'] . " | " . $r['name'] . " | " . $r['phone'] . " | password123\n";
}
