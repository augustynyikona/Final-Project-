<?php
include __DIR__ . '/db.php';

try {
    // Check if email column exists in users
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'email'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER phone");
        echo "Added 'email' column to users table.\n";
    } else {
        echo "'email' column already exists in users table.\n";
    }

    // Populate existing staff with sample emails if empty
    $pdo->exec("UPDATE users SET email = CONCAT(LOWER(REPLACE(name, ' ', '.')), '@vaxicare.moh.gov.zm') WHERE email IS NULL OR email = ''");
    echo "Updated existing users with default medical staff email format.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
