<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// Health checks intentionally do not require a database or expose secrets.
echo json_encode(['status' => 'ok', 'service' => 'gasgo']);
