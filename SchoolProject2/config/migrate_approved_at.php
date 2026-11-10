<?php
include __DIR__ . '/db.php';

try {
    // Alter status column to VARCHAR(50) so 'Approved', 'Confirmed', 'Pending', 'Rejected' are all valid
    $pdo->exec("ALTER TABLE requisitions MODIFY COLUMN status VARCHAR(50) DEFAULT 'Pending'");
    echo "Requisitions status column modified to VARCHAR.\n";

    // Add approved_at column if not exists
    $columns = $pdo->query("SHOW COLUMNS FROM requisitions LIKE 'approved_at'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE requisitions ADD COLUMN approved_at DATETIME NULL AFTER status");
        echo "Added approved_at column to requisitions table.\n";
    }

    echo "Migration completed successfully.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
