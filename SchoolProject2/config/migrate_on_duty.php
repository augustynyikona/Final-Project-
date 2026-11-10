<?php
include __DIR__ . '/db.php';

try {
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_on_duty'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_on_duty TINYINT(1) DEFAULT 1 AFTER role");
        echo "Added is_on_duty column to users table.\n";
    } else {
        echo "is_on_duty column already exists.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
