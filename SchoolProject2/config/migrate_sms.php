<?php
include __DIR__ . '/db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS sms_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        appointment_id INT NOT NULL,
        recipient_phone VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'Sent',
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (appointment_id) REFERENCES appointments(id)
    )";
    $pdo->exec($sql);
    echo "sms_logs table created successfully.";
} catch(PDOException $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
