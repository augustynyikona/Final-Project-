<?php
include __DIR__ . '/db.php';

try {
    // Add dispatch_type column if not exists
    $columns = $pdo->query("SHOW COLUMNS FROM requisitions LIKE 'dispatch_type'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE requisitions ADD COLUMN dispatch_type VARCHAR(50) DEFAULT 'Immediate'");
        echo "Added dispatch_type column.\n";
    }

    // Add dispatch_time column if not exists
    $columns = $pdo->query("SHOW COLUMNS FROM requisitions LIKE 'dispatch_time'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE requisitions ADD COLUMN dispatch_time DATETIME NULL");
        echo "Added dispatch_time column.\n";
    }

    // Add dispatch_notes column if not exists
    $columns = $pdo->query("SHOW COLUMNS FROM requisitions LIKE 'dispatch_notes'")->fetchAll();
    if (empty($columns)) {
        $pdo->exec("ALTER TABLE requisitions ADD COLUMN dispatch_notes VARCHAR(255) NULL");
        echo "Added dispatch_notes column.\n";
    }

    echo "Dispatch migration completed successfully.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
