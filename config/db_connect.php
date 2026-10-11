<?php
declare(strict_types=1);

// Railway service variables: reference the MySQL service values from the web service.
$host = getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: '';
$port = getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: '3306';
$dbname = getenv('DB_DATABASE') ?: getenv('MYSQLDATABASE') ?: '';
$username = getenv('DB_USERNAME') ?: getenv('MYSQLUSER') ?: '';
$password = getenv('DB_PASSWORD');
if ($password === false) $password = getenv('MYSQLPASSWORD');

if ($host === '' || $dbname === '' || $username === '' || $password === false) {
    error_log('[GasGo DB] Missing required database environment variables.');
    http_response_code(503);
    exit('Database is not configured. Set Railway MySQL variables for the web service.');
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('[GasGo DB] ' . $e->getMessage());
    http_response_code(503);
    exit('Database connection failed. Verify Railway MySQL variables and database readiness.');
}
