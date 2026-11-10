<?php
include 'db.php';

try {
    // 1. Consultations Table
    $sql = "CREATE TABLE IF NOT EXISTS consultations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        child_id INT NOT NULL,
        doctor_id INT NOT NULL,
        notes TEXT NOT NULL,
        next_review_date DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "Consultations table created.\n";

} catch(PDOException $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
