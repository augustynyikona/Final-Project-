<?php
require_once __DIR__ . '/config/db.php';

$test_passwords = ['password', 'password123', 'admin', 'admin123', '123456', '1234', 'Augustine', 'augustine', 'secret', 'pass123'];

$stmt = $pdo->query("SELECT id, name, phone, email, role, password_hash FROM users WHERE role = 'guardian'");
while ($row = $stmt->fetch()) {
    echo "User: " . $row['name'] . " (Phone: " . $row['phone'] . ")\n";
    $found = false;
    foreach ($test_passwords as $p) {
        if (password_verify($p, $row['password_hash'])) {
            echo "  --> MATCHED PASSWORD: '$p'\n";
            $found = true;
            break;
        }
    }
    if (!$found) {
        echo "  --> None of common passwords matched. Hash is: " . $row['password_hash'] . "\n";
    }
}
