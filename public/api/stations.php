<?php
require_once __DIR__.'/../../config/db_connect.php';
header('Content-Type: application/json; charset=utf-8');
$stmt=$pdo->query("SELECT id,name,address,barangay,latitude,longitude,operating_hours,diesel_price,unleaded_price,premium_price,lpg_available,lpg_price,contact_number FROM gas_stations WHERE status='Approved' ORDER BY name");
echo json_encode($stmt->fetchAll(),JSON_UNESCAPED_UNICODE);