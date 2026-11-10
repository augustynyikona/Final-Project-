<?php
include 'db.php';

try {
    // Add recipient_role to messages table
    $sql = "ALTER TABLE messages ADD COLUMN recipient_role VARCHAR(50) DEFAULT 'nurse' AFTER sender_id";
    $pdo->exec($sql);
    echo "Added recipient_role to messages table.<br>";
} catch(PDOException $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
