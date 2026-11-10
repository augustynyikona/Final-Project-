<?php
include 'config/db.php';

$name = "Dr. Emily Chen";
$phone = "12345";
$password = "password123";
$role = "doctor";

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
$stmt->execute([$phone]);

if ($stmt->rowCount() > 0) {
    echo "Doctor user with phone '12345' already exists.\n";
} else {
    $stmt = $pdo->prepare("INSERT INTO users (name, phone, password_hash, role) VALUES (?, ?, ?, ?)");
    if ($stmt->execute([$name, $phone, $hash, $role])) {
        echo "Successfully created doctor user!\n";
        echo "Phone: $phone\n";
        echo "Password: $password\n";
    } else {
        echo "Failed to create doctor user.\n";
    }
}
?>
