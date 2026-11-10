<?php
include 'db.php';

try {
    // 1. Growth Logs Table
    $sql = "CREATE TABLE IF NOT EXISTS growth_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        child_id INT NOT NULL,
        record_date DATE NOT NULL,
        height_cm DECIMAL(5,2) NOT NULL,
        weight_kg DECIMAL(5,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "Growth Logs table created.<br>";

    // 2. Messages Table
    $sql = "CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NULL, -- NULL means sent to general clinic admin for now
        subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "Messages table created.<br>";

} catch(PDOException $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
