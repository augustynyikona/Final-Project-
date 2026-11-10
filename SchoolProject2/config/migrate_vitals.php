<?php
include 'db.php';

try {
    // 1. Clinical Vitals Table
    $sql = "CREATE TABLE IF NOT EXISTS clinical_vitals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        child_id INT NOT NULL,
        recorded_by_id INT NULL, -- NULL means guardian via mobile portal
        temperature DECIMAL(4,1) NULL,
        heart_rate INT NULL,
        weight_kg DECIMAL(5,2) NULL,
        symptoms TEXT,
        is_critical BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
        FOREIGN KEY (recorded_by_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "Clinical Vitals table created.\n";

    // 2. Triage Referrals Table
    $sql = "CREATE TABLE IF NOT EXISTS triage_referrals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        child_id INT NOT NULL,
        nurse_id INT NOT NULL,
        reason TEXT NOT NULL,
        status ENUM('pending', 'reviewed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
        FOREIGN KEY (nurse_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "Triage Referrals table created.\n";

} catch(PDOException $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
