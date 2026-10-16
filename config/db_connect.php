<?php
declare(strict_types=1);

/**
 * GasGo: Aiven MySQL connection for Render (PHP PDO + verified TLS).
 * Configure credentials in Render Environment, never in source code.
 */
$host = trim((string) (getenv('DB_HOST') ?: ''));
$port = (string) (getenv('DB_PORT') ?: '3306');
$dbname = trim((string) (getenv('DB_DATABASE') ?: ''));
$username = trim((string) (getenv('DB_USERNAME') ?: ''));
$password = getenv('DB_PASSWORD');
$caPath = (string) (getenv('DB_SSL_CA') ?: '/var/www/html/config/aiven-ca.pem');

if ($host === '' || $dbname === '' || $username === '' || $password === false ||
    !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
    error_log('[GasGo DB] Missing or invalid DB environment variables.');
    http_response_code(503);
    exit('Database configuration is incomplete.');
}

if (!is_file($caPath) || !is_readable($caPath)) {
    error_log('[GasGo DB] Aiven CA certificate not found/readable at: ' . $caPath);
    http_response_code(503);
    exit('Database TLS certificate is not configured.');
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
            PDO::MYSQL_ATTR_SSL_CA => $caPath,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ]
    );
} catch (PDOException $e) {
    error_log('[GasGo DB] Connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Database connection failed. Check Render logs and Aiven connection settings.');
}
