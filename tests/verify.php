<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db_connect.php';

$requiredTables = [
    'managers',
    'gas_stations',
    'station_requests',
    'station_request_lpg_products',
    'station_lpg_products',
    'reviews',
    'faqs',
];

$missing = [];
foreach ($requiredTables as $table) {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    if (!$stmt->fetchColumn()) $missing[] = $table;
}

header('Content-Type: text/plain; charset=utf-8');
if ($missing) {
    http_response_code(500);
    echo 'Missing tables: ' . implode(', ', $missing);
    exit;
}

echo 'GasGo database connection and required tables are OK.';
