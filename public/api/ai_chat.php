<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';
$config = require __DIR__ . '/../../config/openai.php';

$apiKey = trim((string)($config['api_key'] ?? ''));
if ($apiKey === '' || str_contains($apiKey, 'PASTE_YOUR_OPENAI_API_KEY_HERE')) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'GasGo AI is not configured yet. Set OPENAI_API_KEY in your Railway web service variables.'
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request body.']);
    exit;
}

$message = trim((string)($payload['message'] ?? ''));
if ($message === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please type a question.']);
    exit;
}
if (mb_strlen($message) > 1200) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please keep your question under 1,200 characters.']);
    exit;
}

// Keep only a small amount of conversation context to control cost and latency.
$history = [];
if (isset($payload['history']) && is_array($payload['history'])) {
    foreach (array_slice($payload['history'], -8) as $item) {
        if (!is_array($item)) continue;
        $role = ($item['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
        $content = trim((string)($item['content'] ?? ''));
        if ($content === '') continue;
        $history[] = [
            'role' => $role,
            'content' => mb_substr($content, 0, 1000),
        ];
    }
}

$userLat = isset($payload['latitude']) && is_numeric($payload['latitude'])
    ? (float)$payload['latitude'] : null;
$userLng = isset($payload['longitude']) && is_numeric($payload['longitude'])
    ? (float)$payload['longitude'] : null;
if ($userLat !== null && ($userLat < -90 || $userLat > 90)) $userLat = null;
if ($userLng !== null && ($userLng < -180 || $userLng > 180)) $userLng = null;

function gasgoDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earth = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earth * (2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a))));
}

try {
    $stations = $pdo->query("\n        SELECT\n            gs.id, gs.name, gs.address, gs.barangay, gs.description,\n            gs.latitude, gs.longitude, gs.operating_hours, gs.contact_number,\n            gs.diesel_available, gs.regular_available, gs.premium_available,\n            gs.diesel_price, gs.unleaded_price, gs.premium_price,\n            gs.lpg_available, gs.lpg_price, gs.fuel_prices_updated_at, gs.availability_updated_at,\n            ROUND(AVG(r.rating), 2) AS avg_rating, COUNT(r.id) AS review_count\n        FROM gas_stations gs\n        LEFT JOIN reviews r ON r.station_id = gs.id\n        WHERE gs.status = 'Approved'\n        GROUP BY gs.id\n        ORDER BY gs.name ASC\n        LIMIT 100\n    ")->fetchAll(PDO::FETCH_ASSOC);

    $lpgRows = $pdo->query("\n        SELECT station_id, kg, price, available\n        FROM station_lpg_products\n        WHERE available = 1\n        ORDER BY station_id, kg\n    ")->fetchAll(PDO::FETCH_ASSOC);

    $lpgByStation = [];
    foreach ($lpgRows as $row) {
        $lpgByStation[(int)$row['station_id']][] = [
            'kg' => (float)$row['kg'],
            'price' => (float)$row['price'],
        ];
    }

    $stationContext = [];
    foreach ($stations as $station) {
        $lat = is_numeric($station['latitude']) ? (float)$station['latitude'] : null;
        $lng = is_numeric($station['longitude']) ? (float)$station['longitude'] : null;
        $distance = null;
        if ($userLat !== null && $userLng !== null && $lat !== null && $lng !== null) {
            $distance = round(gasgoDistanceKm($userLat, $userLng, $lat, $lng), 2);
        }

        $stationContext[] = [
            'id' => (int)$station['id'],
            'name' => $station['name'],
            'barangay' => $station['barangay'],
            'address' => $station['address'],
            'operating_hours' => $station['operating_hours'],
            'contact' => $station['contact_number'],
            'distance_km_from_user' => $distance,
            'fuel' => [
                'diesel' => [
                    'available' => (bool)$station['diesel_available'],
                    'price' => $station['diesel_price'] !== null ? (float)$station['diesel_price'] : null,
                ],
                'unleaded' => [
                    'available' => (bool)$station['regular_available'],
                    'price' => $station['unleaded_price'] !== null ? (float)$station['unleaded_price'] : null,
                ],
                'premium' => [
                    'available' => (bool)$station['premium_available'],
                    'price' => $station['premium_price'] !== null ? (float)$station['premium_price'] : null,
                ],
                'lpg' => [
                    'available' => (bool)$station['lpg_available'] || !empty($lpgByStation[(int)$station['id']]),
                    'summary_price' => $station['lpg_price'] !== null ? (float)$station['lpg_price'] : null,
                    'products' => $lpgByStation[(int)$station['id']] ?? [],
                ],
            ],
            'rating' => $station['avg_rating'] !== null ? (float)$station['avg_rating'] : null,
            'review_count' => (int)$station['review_count'],
            'prices_updated_at' => $station['fuel_prices_updated_at'],
            'availability_updated_at' => $station['availability_updated_at'],
        ];
    }

    $faqs = $pdo->query("SELECT question, answer FROM faqs ORDER BY id ASC LIMIT 50")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[GasGo AI DB] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'GasGo AI could not load station information.']);
    exit;
}

$systemInstructions = <<<PROMPT
You are GasGo AI, the built-in assistant for GasGo, a gasoline-station information system for Siargao Island, Philippines.

Your job is to answer users' free-form questions about GasGo and the live approved station data supplied below. You may explain how GasGo works, help users compare stations, fuel prices, fuel availability, LPG sizes/prices, operating hours, contact information, ratings, and location-aware choices.

Rules:
- Treat the supplied GASGO LIVE DATA as the source of truth for station-specific facts. Never invent a station, price, availability status, LPG size, rating, contact number, or operating hour.
- If the user asks for the cheapest, nearest, best-rated, or best-overall option, compare the supplied data and explain the basis briefly. Do not claim "nearest" if user distance data is unavailable.
- Price/availability data can change. When useful, remind the user to verify before traveling, especially when timestamps are missing or old.
- If a user asks about a station not present in the live data, say you cannot confirm it in the current GasGo database.
- If the question is unrelated to GasGo, fuel stations, LPG, travel-to-station decisions, or using the GasGo system, politely steer the conversation back to GasGo rather than pretending to be a general-purpose assistant.
- Do not claim to place calls, make purchases, reserve fuel, or perform actions GasGo cannot actually perform.
- Keep answers concise and mobile-friendly. Use simple bullets only when they improve readability.
- You may respond in English, Cebuano/Bisaya, Tagalog, or the language used by the user.
- When referring to a station that exists in the live data, you may include its exact station name and useful details from the data.
PROMPT;

$context = [
    'user_location_available' => $userLat !== null && $userLng !== null,
    'approved_station_count' => count($stationContext),
    'stations' => $stationContext,
    'faqs' => $faqs,
];

$systemInstructions .= "\n\nGASGO LIVE DATA (JSON):\n" .
    json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$input = [];
foreach ($history as $item) {
    $input[] = $item;
}
$input[] = ['role' => 'user', 'content' => $message];

$requestBody = [
    'model' => (string)($config['model'] ?? 'gpt-4.1-mini'),
    'instructions' => $systemInstructions,
    'input' => $input,
    'max_output_tokens' => 700,
];

$encodedBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$responseBody = false;
$status = 0;
$transportError = '';

if (function_exists('curl_init')) {
    $ch = curl_init((string)$config['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $encodedBody,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => (int)($config['timeout'] ?? 35),
    ]);
    $responseBody = curl_exec($ch);
    $transportError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
} else {
    // Shared-hosting fallback for PHP installations without the cURL extension.
    $httpContext = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$apiKey}\r\nContent-Type: application/json\r\n",
            'content' => $encodedBody,
            'timeout' => (int)($config['timeout'] ?? 35),
            'ignore_errors' => true,
        ],
    ]);
    $responseBody = @file_get_contents((string)$config['endpoint'], false, $httpContext);
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $headerLine, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }
    }
    if ($responseBody === false) {
        $lastError = error_get_last();
        $transportError = (string)($lastError['message'] ?? 'HTTP request failed');
    }
}

if ($responseBody === false || $transportError !== '') {
    error_log('[GasGo AI transport] ' . $transportError);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'GasGo AI could not connect to the AI service.']);
    exit;
}

$response = json_decode($responseBody, true);
if ($status < 200 || $status >= 300 || !is_array($response)) {
    $apiMessage = is_array($response) ? (string)($response['error']['message'] ?? '') : '';
    error_log('[GasGo AI API] HTTP ' . $status . ' ' . $apiMessage);
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => $status === 401
            ? 'GasGo AI API key was rejected. Check OPENAI_API_KEY in Railway Variables.'
            : 'GasGo AI is temporarily unavailable. Please try again.'
    ]);
    exit;
}

$answer = trim((string)($response['output_text'] ?? ''));
if ($answer === '' && isset($response['output']) && is_array($response['output'])) {
    $chunks = [];
    foreach ($response['output'] as $item) {
        if (!is_array($item) || ($item['type'] ?? '') !== 'message') continue;
        foreach (($item['content'] ?? []) as $content) {
            if (!is_array($content)) continue;
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                $chunks[] = (string)$content['text'];
            }
        }
    }
    $answer = trim(implode("\n", $chunks));
}

if ($answer === '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'GasGo AI returned an empty response. Please try again.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'answer' => $answer,
    'model' => (string)($config['model'] ?? 'gpt-4.1-mini'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
