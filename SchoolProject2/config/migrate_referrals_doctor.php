<?php
include __DIR__ . '/db.php';

try {
    // Add doctor_id to triage_referrals if not exists
    $pdo->exec("ALTER TABLE triage_referrals ADD COLUMN doctor_id INT NULL AFTER nurse_id");
    echo "Added doctor_id column to triage_referrals successfully.\n";
} catch(PDOException $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "doctor_id column already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
