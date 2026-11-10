<?php
$host = '127.0.0.1';
$user = 'root';
$pass = '';

try {
    echo "Rebuilding vaxicare database...\n";
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("CREATE DATABASE IF NOT EXISTS vaxicare CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Re-connect to vaxicare
    $pdo = new PDO("mysql:host=$host;dbname=vaxicare;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $tables = [
        'requisition_items', 'requisitions', 'invoices', 'purchase_orders', 
        'vaccine_batches', 'suppliers', 'messages', 'growth_logs', 
        'consultations', 'triage_referrals', 'clinical_vitals', 'sms_logs', 
        'appointments', 'vaccines', 'children', 'users'
    ];

    foreach ($tables as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `$t`");
    }

    // 1. Users Table
    $pdo->exec("CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('guardian', 'nurse', 'pharmacist', 'pharmacy', 'doctor', 'admin') DEFAULT 'guardian',
        profile_pic VARCHAR(255) DEFAULT NULL,
        is_on_duty BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 2. Children Table
    $pdo->exec("CREATE TABLE children (
        id INT AUTO_INCREMENT PRIMARY KEY,
        guardian_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        date_of_birth DATE NOT NULL,
        gender ENUM('Male', 'Female') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (guardian_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 3. Vaccines Table
    $pdo->exec("CREATE TABLE vaccines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        current_stock INT DEFAULT 500,
        low_stock_threshold INT DEFAULT 50,
        manufacturer VARCHAR(100) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 4. Appointments Table
    $pdo->exec("CREATE TABLE appointments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        child_id INT NOT NULL,
        vaccine_id INT NOT NULL,
        appointment_date DATE NOT NULL,
        appointment_time TIME NOT NULL,
        status ENUM('scheduled', 'confirmed', 'cancelled', 'arrived', 'no-show', 'completed') DEFAULT 'scheduled',
        batch_number VARCHAR(50) DEFAULT NULL,
        site VARCHAR(50) DEFAULT NULL,
        route VARCHAR(50) DEFAULT NULL,
        manufacturer VARCHAR(100) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        room_number VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
        FOREIGN KEY (vaccine_id) REFERENCES vaccines(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 5. SMS Logs Table
    $pdo->exec("CREATE TABLE sms_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        appointment_id INT NOT NULL,
        recipient_phone VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'Sent',
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 6. Clinical Vitals Table
    $pdo->exec("CREATE TABLE clinical_vitals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        child_id INT NOT NULL,
        recorded_by_id INT NULL,
        temperature DECIMAL(4,1) NULL,
        heart_rate INT NULL,
        weight_kg DECIMAL(5,2) NULL,
        symptoms TEXT,
        is_critical BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
        FOREIGN KEY (recorded_by_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 7. Triage Referrals Table
    $pdo->exec("CREATE TABLE triage_referrals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        child_id INT NOT NULL,
        nurse_id INT NOT NULL,
        doctor_id INT NULL,
        reason TEXT NOT NULL,
        status ENUM('pending', 'reviewed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
        FOREIGN KEY (nurse_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 8. Consultations Table
    $pdo->exec("CREATE TABLE consultations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        child_id INT NOT NULL,
        doctor_id INT NOT NULL,
        notes TEXT NOT NULL,
        next_review_date DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 9. Growth Logs Table
    $pdo->exec("CREATE TABLE growth_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        child_id INT NOT NULL,
        record_date DATE NOT NULL,
        height_cm DECIMAL(5,2) NOT NULL,
        weight_kg DECIMAL(5,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 10. Messages Table
    $pdo->exec("CREATE TABLE messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        recipient_role VARCHAR(50) DEFAULT NULL,
        receiver_id INT NULL,
        subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 11. Suppliers Table
    $pdo->exec("CREATE TABLE suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        status VARCHAR(20) DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 12. Vaccine Batches Table
    $pdo->exec("CREATE TABLE vaccine_batches (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 13. Purchase Orders Table
    $pdo->exec("CREATE TABLE purchase_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vaccine_id INT NOT NULL,
        supplier_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_type VARCHAR(20) DEFAULT 'Vials',
        order_date DATE NOT NULL,
        requested_delivery DATE NOT NULL,
        status VARCHAR(20) DEFAULT 'Pending',
        approved_at TIMESTAMP NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (vaccine_id) REFERENCES vaccines(id) ON DELETE CASCADE,
        FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 14. Invoices Table
    $pdo->exec("CREATE TABLE invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        guardian_id INT NOT NULL,
        child_id INT,
        amount_due DECIMAL(10,2) NOT NULL,
        status VARCHAR(20) DEFAULT 'Pending',
        admission_date DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (guardian_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 15. Requisitions Table
    $pdo->exec("CREATE TABLE requisitions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nurse_id INT NOT NULL,
        shift_date DATE NOT NULL,
        status ENUM('Pending', 'Approved', 'Rejected', 'Fulfilled') DEFAULT 'Pending',
        approved_at TIMESTAMP NULL DEFAULT NULL,
        dispatch_type VARCHAR(50) DEFAULT NULL,
        dispatch_time TIMESTAMP NULL DEFAULT NULL,
        dispatch_notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (nurse_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 16. Requisition Items Table
    $pdo->exec("CREATE TABLE requisition_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requisition_id INT NOT NULL,
        vaccine_id INT NOT NULL,
        requested_quantity INT NOT NULL,
        fulfilled_quantity INT DEFAULT 0,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (requisition_id) REFERENCES requisitions(id) ON DELETE CASCADE,
        FOREIGN KEY (vaccine_id) REFERENCES vaccines(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "Database tables created successfully!\n\n";

    // Run seed_dummy_data.php
    include __DIR__ . '/seed_dummy_data.php';

} catch (PDOException $e) {
    echo "Rebuild failed: " . $e->getMessage() . "\n";
}
?>
