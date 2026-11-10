<?php
include 'db.php';

try {
    // Add profile_pic column to users table
    $sql = "ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) NULL AFTER phone";
    $pdo->exec($sql);
    echo "Added profile_pic column to users table.\n";
} catch(PDOException $e) {
    echo "Migration failed or column already exists: " . $e->getMessage() . "\n";
}
?>
