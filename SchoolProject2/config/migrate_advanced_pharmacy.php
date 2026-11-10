<?php
include __DIR__ . '/db.php';

try {
    // Suppliers table
    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        status VARCHAR(20) DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Suppliers table created.\n";

    // Insert some default suppliers
    $pdo->exec("INSERT IGNORE INTO suppliers (id, name, status) VALUES 
        (1, 'Cardinal Health', 'Active'),
        (2, 'McKesson Medical', 'Active'),
        (3, 'AmerisourceBergen', 'Active'),
        (4, 'PharmaDirect', 'Active')");

    // Vaccine Batches table
    $pdo->exec("CREATE TABLE IF NOT EXISTS vaccine_batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vaccine_id INT NOT NULL,
        batch_number VARCHAR(50) NOT NULL,
        quantity INT NOT NULL,
        expiry_date DATE NOT NULL,
        location VARCHAR(50) DEFAULT 'Main Storage',
        supplier_id INT,
        status VARCHAR(20) DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (vaccine_id) REFERENCES vaccines(id) ON DELETE CASCADE,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
    )");
    echo "Vaccine Batches table created.\n";

    // Purchase Orders table
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vaccine_id INT NOT NULL,
        supplier_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_type VARCHAR(20) DEFAULT 'Vials',
        order_date DATE NOT NULL,
        requested_delivery DATE NOT NULL,
        status VARCHAR(20) DEFAULT 'Pending',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (vaccine_id) REFERENCES vaccines(id) ON DELETE CASCADE,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
    )");
    echo "Purchase Orders table created.\n";

    // Invoices table
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        guardian_id INT NOT NULL,
        child_id INT,
        amount_due DECIMAL(10,2) NOT NULL,
        status VARCHAR(20) DEFAULT 'Pending',
        admission_date DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (guardian_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE SET NULL
    )");
    echo "Invoices table created.\n";

    // Insert dummy invoices for testing
    // Guardian with ID 1 might not exist, but let's just insert one if possible
    // We'll skip dummy invoices here to avoid constraint failures.

    echo "Advanced Pharmacy Migration completed successfully.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
