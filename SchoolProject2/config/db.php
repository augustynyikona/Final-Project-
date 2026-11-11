<?php
// Database configuration (supports environment variables or local fallback)
require_once __DIR__ . '/env.php';
$host   = vax_env('DB_HOST', '127.0.0.1');
$dbname = vax_env('DB_NAME', 'vaxicare');
$user   = vax_env('DB_USER', 'root');
$pass   = vax_env('DB_PASS', '');

try {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch(PDOException $e) {
    // Never echo the driver message: it names the DB user and host to any visitor.
    error_log('VaxiCare DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    die("The service is temporarily unavailable. Please try again shortly.");
}

require_once __DIR__ . '/notifications_helper.php';
?>
