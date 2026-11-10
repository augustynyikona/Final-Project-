<?php
require_once __DIR__ . '/config/db.php';

$stmt = $pdo->prepare("SELECT id, name, phone, password_hash FROM users WHERE phone = '0963912783'");
$stmt->execute();
$u = $stmt->fetch();

$candidates = [
    'password123', 'password', '123456', '1234', '12345678', 'admin', 'admin123',
    'Augustine', 'augustine', 'Yikona', 'yikona', 'Augustine123', 'augustine123',
    '0963912783', '0971725900', 'Vaxicare', 'vaxicare', 'vaxicare123'
];

foreach ($candidates as $c) {
    if (password_verify($c, $u['password_hash'])) {
        echo "FOUND MATCH FOR 0963912783: '$c'\n";
        exit;
    }
}
echo "No match found among candidate passwords for 0963912783\n";
