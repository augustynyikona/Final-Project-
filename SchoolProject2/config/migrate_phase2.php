<?php
include 'db.php';

try {
    // 1. Children Table
    $sql = "CREATE TABLE IF NOT EXISTS children (
        id INT AUTO_INCREMENT PRIMARY KEY,
        guardian_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        date_of_birth DATE NOT NULL,
        gender ENUM('Male', 'Female') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (guardian_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "Children table created.<br>";

    // 2. Vaccines Table
    $sql = "CREATE TABLE IF NOT EXISTS vaccines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        current_stock INT DEFAULT 500,
        low_stock_threshold INT DEFAULT 50
    )";
    $pdo->exec($sql);
    echo "Vaccines table created.<br>";

    // 3. Appointments Table
    $sql = "CREATE TABLE IF NOT EXISTS appointments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        child_id INT NOT NULL,
        vaccine_id INT NOT NULL,
        appointment_date DATE NOT NULL,
        appointment_time TIME NOT NULL,
        status ENUM('scheduled', 'confirmed', 'cancelled', 'arrived', 'no-show') DEFAULT 'scheduled',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
        FOREIGN KEY (vaccine_id) REFERENCES vaccines(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "Appointments table created.<br>";

    // 4. Seed Vaccines if empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM vaccines");
    if ($stmt->fetchColumn() == 0) {
        $vaccines = [
            ['BCG (Tuberculosis)', 'Given at birth or shortly after.'],
            ['OPV (Oral Polio Vaccine)', 'Protects against polio.'],
            ['DPT (Diphtheria, Pertussis, Tetanus)', 'Combination vaccine.'],
            ['Measles', 'Given at 9 months.']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO vaccines (name, description, current_stock) VALUES (?, ?, 500)");
        foreach ($vaccines as $vac) {
            $stmt->execute($vac);
        }
        echo "Default vaccines seeded.<br>";
    }

} catch(PDOException $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
