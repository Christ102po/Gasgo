<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db_connect.php';


$stmt = $pdo->query("
    SELECT
        id,
        name,
        address,
        barangay,
        description,
        latitude,
        longitude,
        operating_hours,
        diesel_available,
        regular_available,
        premium_available,
        diesel_price,
        unleaded_price,
        premium_price,
        lpg_available,
        lpg_price,
        contact_number,
        image,
        (SELECT ROUND(AVG(r.rating), 2) FROM reviews r WHERE r.station_id = gas_stations.id) AS avg_rating,
        (SELECT COUNT(*) FROM reviews r WHERE r.station_id = gas_stations.id) AS review_count
    FROM gas_stations
    WHERE status = 'Approved'
    ORDER BY name ASC
");

$stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Load the actual LPG products managed by station managers so the public
// dashboard and map can show the real LPG sizes and prices.
$lpgRows = $pdo->query("
    SELECT station_id, kg, price, available
    FROM station_lpg_products
    WHERE available = 1
    ORDER BY station_id ASC, kg ASC
")->fetchAll(PDO::FETCH_ASSOC);

$lpgByStation = [];
foreach ($lpgRows as $lpgRow) {
    $stationId = (int)($lpgRow['station_id'] ?? 0);
    if ($stationId <= 0) {
        continue;
    }

    $lpgByStation[$stationId][] = [
        'kg' => $lpgRow['kg'],
        'price' => $lpgRow['price'],
        'available' => (int)($lpgRow['available'] ?? 0),
    ];
}

foreach ($stations as &$publicStation) {
    $stationId = (int)($publicStation['id'] ?? 0);
    $publicStation['lpg_products'] = $lpgByStation[$stationId] ?? [];

    // Keep compatibility with the older single LPG columns. If actual LPG
    // products exist, consider LPG available and use the first product price
    // as the summary price while still exposing every product in lpg_products.
    if (!empty($publicStation['lpg_products'])) {
        $publicStation['lpg_available'] = 1;
        $publicStation['lpg_price'] = $publicStation['lpg_products'][0]['price'];
    }
}
unset($publicStation);


function stationImageUrl(?string $image): string
{
    $image = trim((string) $image);
    if ($image === '') return '';

    if (preg_match('#^(?:https?:)?//#i', $image) || str_starts_with($image, 'data:image/')) {
        return $image;
    }

    $normalized = str_replace('\\', '/', $image);
    $pathOnly = parse_url($normalized, PHP_URL_PATH) ?: $normalized;
    $basename = basename($pathOnly);

    // index.php now lives in the project root. Check the root upload folder
    // first, then the legacy public upload locations for compatibility.
    $candidates = [
        ['file' => __DIR__ . '/uploads/stations/' . $basename, 'url' => 'uploads/stations/' . rawurlencode($basename)],
        ['file' => __DIR__ . '/public/uploads/stations/' . $basename, 'url' => 'public/uploads/stations/' . rawurlencode($basename)],
        ['file' => __DIR__ . '/public/uploads/' . $basename, 'url' => 'public/uploads/' . rawurlencode($basename)],
        ['file' => __DIR__ . '/public/assets/uploads/stations/' . $basename, 'url' => 'public/assets/uploads/stations/' . rawurlencode($basename)],
    ];

    // If the DB already stores a project-root relative path, preserve it when valid.
    $relative = ltrim($pathOnly, '/');
    if ($relative !== '' && !str_starts_with($relative, '../') && !str_contains($relative, '/../')) {
        array_unshift($candidates, [
            'file' => __DIR__ . '/' . $relative,
            'url' => implode('/', array_map(
                static fn(string $part): string => rawurlencode($part),
                explode('/', $relative)
            )),
        ]);
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate['file'])) {
            return $candidate['url'];
        }
    }

    // Safe fallback for older DB values that only preserve the filename.
    return 'uploads/stations/' . rawurlencode($basename);
}

foreach ($stations as &$station) {
    $station['image_url'] = stationImageUrl($station['image'] ?? '');
}
unset($station);


$faq = $pdo->query("
    SELECT *
    FROM faqs
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);


$stationsJson = json_encode(
    $stations,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP |
    JSON_UNESCAPED_UNICODE
);

if ($stationsJson === false) {
    $stationsJson = '[]';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="description"
        content="GasGo is a gasoline station locator for Siargao Island, Philippines. Find nearby fuel stations, compare fuel prices, check LPG availability, and get directions."
    >

    <meta
        name="theme-color"
        content="#DC2626"
    >

    <title>
        GasGo — Siargao Fuel Locator
    </title>

    <link
        rel="manifest"
        href="public/manifest.webmanifest"
    >

    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="GasGo">

    <link
        rel="apple-touch-icon"
        href="public/icons/apple-touch-icon.png"
    >

    <link
        rel="stylesheet"
        href="public/assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >

    <style>

        /* Homepage hero background image.
           index.php is in the project root; image is stored in:
           /public/uploads/gasgobackground.png */
        .hero {
            background:
                linear-gradient(rgba(17, 24, 39, 0.58), rgba(17, 24, 39, 0.58)),
                url('public/uploads/gasgobackground.png') center center / cover no-repeat;
            color: #ffffff;
        }
           


        /* ============================================================
           GASGO AI FLOATING CHAT ASSISTANT
        ============================================================ */
        .ai-recommend-btn {
            border: 1px solid rgba(255, 255, 255, 0.38);
            background: #111827;
            color: #ffffff;
            box-shadow: 0 10px 22px rgba(17, 24, 39, 0.20);
            white-space: nowrap;
        }

        .ai-recommend-btn:hover {
            background: #030712;
            transform: translateY(-1px);
        }

        .gasgo-ai-launcher {
            position: fixed;
            right: 22px;
            bottom: 96px;
            z-index: 10070;
            width: 58px;
            height: 58px;
            border: 0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #111827;
            color: #fff;
            cursor: pointer;
            box-shadow: 0 12px 30px rgba(17, 24, 39, .28);
            transition: transform .18s ease, background .18s ease, box-shadow .18s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .gasgo-ai-launcher:hover {
            transform: translateY(-2px) scale(1.03);
            background: #030712;
            box-shadow: 0 16px 34px rgba(17, 24, 39, .32);
        }

        .gasgo-ai-launcher svg {
            width: 28px;
            height: 28px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .gasgo-ai-launcher .ai-online-dot {
            position: absolute;
            right: 3px;
            top: 3px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #22c55e;
            border: 2px solid #fff;
        }

        .gasgo-ai-chat {
            position: fixed;
            right: 22px;
            bottom: 166px;
            z-index: 10069;
            width: min(390px, calc(100vw - 32px));
            height: min(620px, calc(100vh - 210px));
            min-height: 430px;
            display: none;
            grid-template-rows: auto 1fr auto;
            overflow: hidden;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            background: #fff;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .24);
        }

        .gasgo-ai-chat.is-open {
            display: grid;
            animation: gasgoAiOpen .18s ease-out;
        }

        @keyframes gasgoAiOpen {
            from { opacity: 0; transform: translateY(10px) scale(.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .gasgo-ai-chat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 15px;
            background: #111827;
            color: #fff;
        }

        .gasgo-ai-chat-title {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .gasgo-ai-avatar {
            flex: 0 0 auto;
            width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #dc2626;
            font-size: 18px;
        }

        .gasgo-ai-chat-title strong,
        .gasgo-ai-chat-title small {
            display: block;
        }

        .gasgo-ai-chat-title strong {
            font-size: 14px;
            line-height: 1.25;
        }

        .gasgo-ai-chat-title small {
            margin-top: 2px;
            color: #d1d5db;
            font-size: 11px;
        }

        .gasgo-ai-close {
            flex: 0 0 auto;
            width: 36px;
            height: 36px;
            border: 0;
            border-radius: 10px;
            background: rgba(255,255,255,.10);
            color: #fff;
            font-size: 23px;
            line-height: 1;
            cursor: pointer;
        }

        .gasgo-ai-messages {
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
            padding: 16px;
            background: #f8fafc;
            scroll-behavior: smooth;
        }

        .gasgo-ai-row {
            display: flex;
            margin-bottom: 12px;
        }

        .gasgo-ai-row.user { justify-content: flex-end; }
        .gasgo-ai-row.assistant { justify-content: flex-start; }

        .gasgo-ai-bubble {
            max-width: 84%;
            padding: 10px 12px;
            border-radius: 14px;
            font-size: 13px;
            line-height: 1.48;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .gasgo-ai-row.user .gasgo-ai-bubble {
            border-bottom-right-radius: 5px;
            background: #dc2626;
            color: #fff;
        }

        .gasgo-ai-row.assistant .gasgo-ai-bubble {
            border: 1px solid #e5e7eb;
            border-bottom-left-radius: 5px;
            background: #fff;
            color: #1f2937;
        }

        .gasgo-ai-row.error .gasgo-ai-bubble {
            border-color: #fecaca;
            background: #fff1f2;
            color: #991b1b;
        }

        .gasgo-ai-typing {
            display: inline-flex;
            gap: 4px;
            align-items: center;
            min-height: 18px;
        }

        .gasgo-ai-typing span {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #9ca3af;
            animation: gasgoAiTyping 1s infinite ease-in-out;
        }

        .gasgo-ai-typing span:nth-child(2) { animation-delay: .12s; }
        .gasgo-ai-typing span:nth-child(3) { animation-delay: .24s; }

        @keyframes gasgoAiTyping {
            0%, 60%, 100% { transform: translateY(0); opacity: .55; }
            30% { transform: translateY(-3px); opacity: 1; }
        }

        .gasgo-ai-composer {
            padding: 12px;
            border-top: 1px solid #e5e7eb;
            background: #fff;
        }

        .gasgo-ai-input-row {
            display: flex;
            align-items: flex-end;
            gap: 8px;
        }

        .gasgo-ai-input {
            flex: 1 1 auto;
            min-width: 0;
            max-height: 110px;
            resize: none;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 13px;
            outline: 0;
            background: #fff;
            color: #111827;
            font: inherit;
            font-size: 13px;
            line-height: 1.4;
        }

        .gasgo-ai-input:focus {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, .10);
        }

        .gasgo-ai-send {
            flex: 0 0 auto;
            width: 42px;
            height: 42px;
            border: 0;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #dc2626;
            color: #fff;
            cursor: pointer;
        }

        .gasgo-ai-send:disabled {
            opacity: .55;
            cursor: wait;
        }

        .gasgo-ai-send svg {
            width: 20px;
            height: 20px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .gasgo-ai-disclaimer {
            margin: 7px 2px 0;
            color: #6b7280;
            font-size: 10px;
            line-height: 1.35;
            text-align: center;
        }

        @media (max-width: 768px) {
            .searchbar .ai-recommend-btn { width: 100%; }

            .gasgo-ai-launcher {
                right: 16px;
                bottom: calc(68px + 84px + env(safe-area-inset-bottom));
                width: 56px;
                height: 56px;
            }

            .gasgo-ai-chat {
                left: 10px;
                right: 10px;
                bottom: calc(68px + 84px + 66px + env(safe-area-inset-bottom));
                width: auto;
                height: min(560px, calc(100vh - 240px));
                min-height: 360px;
                border-radius: 18px;
            }
        }

        @media (max-width: 480px) {
            .gasgo-ai-chat {
                left: 8px;
                right: 8px;
                bottom: calc(68px + 78px + env(safe-area-inset-bottom));
                height: min(66vh, 540px);
            }

            .gasgo-ai-launcher {
                bottom: calc(68px + 12px + env(safe-area-inset-bottom));
            }

            .pwa-install-button {
                right: 82px !important;
            }

            .gasgo-ai-bubble { max-width: 88%; }
        }

        .mobile-nav {
            display: none;
        }


        /* ============================================================
           PWA INSTALL BUTTONS
        ============================================================ */
        .pwa-install-button {
            position: fixed;
            right: 22px;
            bottom: 24px;
            z-index: 10050;

            width: 58px;
            height: 58px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            padding: 0;
            border: 0;
            border-radius: 50%;

            background: #dc2626;
            color: #ffffff;

            cursor: pointer;

            box-shadow:
                0 10px 28px rgba(220, 38, 38, 0.30),
                0 5px 14px rgba(15, 23, 42, 0.16);

            transition:
                background 0.2s ease,
                transform 0.2s ease,
                opacity 0.2s ease,
                box-shadow 0.2s ease;

            -webkit-tap-highlight-color: transparent;
        }

        .pwa-install-button:hover {
            background: #b91c1c;
            transform: translateY(-2px) scale(1.04);
            box-shadow:
                0 14px 32px rgba(220, 38, 38, 0.34),
                0 6px 16px rgba(15, 23, 42, 0.18);
        }

        .pwa-install-button:active {
            transform: scale(0.95);
        }

        .pwa-install-button svg {
            width: 27px;
            height: 27px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2.25;
            stroke-linecap: round;
            stroke-linejoin: round;
            pointer-events: none;
        }

        .pwa-install-button[hidden] {
            display: none !important;
        }

        .pwa-install-button:disabled {
            opacity: 0.58;
            cursor: wait;
            transform: none !important;
        }

        .pwa-install-button.is-ready {
            opacity: 1;
        }



        #map {
            width: 100%;
            min-height: 500px;
            border-radius: 16px;
            overflow: hidden;
            box-shadow:
                0 10px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
        }


        .leaflet-popup-content-wrapper {
            border-radius: 14px;
        }


        .leaflet-popup-content {
            margin: 14px;
            min-width: 240px;
        }


        .map-popup {
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }


        .map-popup-title {
            margin: 0 0 6px;
            color: #111827;
            font-size: 17px;
            font-weight: 800;
            line-height: 1.3;
        }


        .map-popup-location {
            margin: 0 0 10px;
            color: #6b7280;
            font-size: 13px;
            line-height: 1.5;
        }


        .map-popup-info {
            margin: 6px 0;
            color: #374151;
            font-size: 13px;
            line-height: 1.5;
        }


        .map-popup-prices {
            display: grid;
            grid-template-columns:
                repeat(3, 1fr);
            gap: 5px;
            margin: 10px 0;
        }


        .map-popup-price {
            padding: 7px 4px;
            border-radius: 8px;
            background: #fef2f2;
            text-align: center;
        }


        .map-popup-price small {
            display: block;
            color: #6b7280;
            font-size: 9px;
            font-weight: 700;
        }


        .map-popup-price strong {
            color: #dc2626;
            font-size: 12px;
        }


        .map-popup-actions {
            display: grid;
            grid-template-columns: 1fr;
            gap: 6px;
            margin-top: 10px;
        }


        .map-popup-button {
            display: block;
            width: 100%;
            padding: 9px 10px;
            border: 0;
            border-radius: 8px;
            background: #dc2626;
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
        }


        .map-popup-button:hover {
            background: #b91c1c;
        }


        .map-popup-button.secondary {
            background: #2563eb;
        }


        .map-popup-button.secondary,
        .map-popup-button.secondary:visited,
        .map-popup-button.secondary:hover,
        .map-popup-button.secondary:focus {
            color: #ffffff !important;
        }


        .map-popup-button.secondary:hover {
            background: #1d4ed8;
        }


        .map-popup-button.green {
            background: #16a34a;
        }


        .map-popup-button.green:hover {
            background: #15803d;
        }


        .map-user-location {
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #2563eb;
            border: 3px solid #ffffff;
            border-radius: 50%;
            box-shadow:
                0 0 0 4px rgba(37, 99, 235, 0.18),
                0 4px 12px rgba(0, 0, 0, 0.28);
        }


        .map-user-location svg {
            width: 18px;
            height: 18px;
            display: block;
            fill: #ffffff;
            transform: rotate(-8deg);
        }


        .map-station-icon {
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #dc2626;
            border: 3px solid #ffffff;
            box-shadow:
                0 3px 12px rgba(0, 0, 0, 0.3);
            color: #ffffff;
            font-size: 18px;
        }


        .route-status {
            display: none;
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            font-size: 13px;
            line-height: 1.5;
        }


        .route-status.show {
            display: block;
        }


        .map-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }


        .map-control-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #ffffff;
            color: #374151;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition:
                background 0.2s ease,
                transform 0.2s ease;
        }


        .map-control-button:hover {
            background: #f9fafb;
            transform: translateY(-1px);
        }


        .map-control-button.primary {
            background: #dc2626;
            border-color: #dc2626;
            color: #ffffff;
        }


        .map-control-button.primary:hover {
            background: #b91c1c;
        }


        .find-location-button {
            background: #2563eb !important;
            color: #ffffff !important;
            border-color: #2563eb !important;
        }


        .find-location-button:hover {
            background: #1d4ed8 !important;
        }


        /* ============================================================
           SEARCH SUGGESTIONS
        ============================================================ */
        .search-input-wrap {
            position: relative;
            flex: 1 1 auto;
            min-width: 0;
        }

        .search-input-wrap input {
            width: 100%;
        }

        .station-search-suggestions {
            position: absolute;
            top: calc(100% + 7px);
            left: 0;
            right: 0;
            z-index: 12000;
            display: none;
            max-height: 320px;
            overflow-y: auto;
            padding: 7px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 16px 38px rgba(15, 23, 42, 0.18);
        }

        .station-search-suggestions.show {
            display: block;
        }

        .station-search-option {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 12px;
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: #111827;
            text-align: left;
            cursor: pointer;
        }

        .station-search-option:hover,
        .station-search-option:focus-visible {
            background: #fef2f2;
            outline: none;
        }

        .station-search-option-icon {
            flex: 0 0 auto;
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #dc2626;
            color: #ffffff;
            font-size: 17px;
        }

        .station-search-option-text {
            min-width: 0;
        }

        .station-search-option-name {
            display: block;
            overflow: hidden;
            color: #111827;
            font-size: 14px;
            font-weight: 800;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .station-search-option-location {
            display: block;
            margin-top: 2px;
            overflow: hidden;
            color: #6b7280;
            font-size: 12px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .station-search-empty {
            padding: 14px 12px;
            color: #6b7280;
            font-size: 13px;
            text-align: center;
        }


        /* Floating station carousel controls. */
        .station-carousel-shell {
            position: relative;
        }


        .mobile-station-slider-controls {
            position: absolute;
            inset: 0;
            z-index: 30;
            pointer-events: none;
        }


        .mobile-station-slider-label {
            display: none;
        }


        .mobile-station-slider-button {
            position: absolute;
            top: 50%;
            width: 64px;
            height: 64px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            color: #dc2626;
            font-size: 46px;
            font-weight: 900;
            line-height: 1;
            cursor: pointer;
            box-shadow: none;
            text-shadow:
                0 1px 2px rgba(255, 255, 255, 0.95),
                0 2px 5px rgba(0, 0, 0, 0.20);
            transform: translateY(-50%);
            pointer-events: auto;
            -webkit-tap-highlight-color: transparent;
            transition:
                transform 0.2s ease,
                opacity 0.2s ease;
        }


        #stationSlidePrev {
            left: -18px;
        }


        #stationSlideNext {
            right: -18px;
        }


        .mobile-station-slider-button:hover {
            background: transparent;
            transform: translateY(-50%) scale(1.12);
            opacity: 0.82;
        }


        .mobile-station-slider-button:active {
            transform: translateY(-50%) scale(0.95);
        }


        .product-status {
            display: block;
            margin: 5px 0 6px;
            font-size: 11px;
            font-weight: 800;
            line-height: 1.25;
        }

        .product-status.is-available {
            color: #15803d;
        }

        .product-status.is-unavailable {
            color: #dc2626;
        }

        .product-price {
            display: block;
            font-size: 14px;
            font-weight: 800;
            line-height: 1.3;
        }

        .map-product-status {
            display: block;
            margin-top: 3px;
            font-size: 9px;
            font-weight: 800;
        }

        .map-product-status.available {
            color: #15803d;
        }

        .map-product-status.unavailable {
            color: #dc2626;
        }

        .station-modal-price strong.available-status,
        #stationModalLpg.available-status {
            color: #15803d;
        }

        .station-modal-price strong.unavailable-status,
        #stationModalLpg.unavailable-status {
            color: #dc2626;
        }


        .faq-modal {

            position: fixed;

            inset: 0;

            z-index: 20000;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 24px;

            visibility: hidden;

            opacity: 0;

            pointer-events: none;

            transition:
                opacity 0.2s ease,
                visibility 0.2s ease;

        }


        .faq-modal.show {

            visibility: visible;

            opacity: 1;

            pointer-events: auto;

        }


        .faq-modal-overlay {

            position: absolute;

            inset: 0;

            background:
                rgba(15, 23, 42, 0.62);

            backdrop-filter:
                blur(3px);

            -webkit-backdrop-filter:
                blur(3px);

        }


        .faq-modal-content {

            position: relative;

            z-index: 1;

            width: min(100%, 620px);

            max-height: 82vh;

            overflow: hidden;

            background: #ffffff;

            border-radius: 20px;

            box-shadow:
                0 24px 70px
                rgba(0, 0, 0, 0.25);

            transform:
                translateY(16px)
                scale(0.98);

            transition:
                transform 0.2s ease;

        }


        .faq-modal.show
        .faq-modal-content {

            transform:
                translateY(0)
                scale(1);

        }


        .faq-modal-header {

            display: flex;

            align-items: flex-start;

            justify-content: space-between;

            gap: 18px;

            padding:
                24px 24px 18px;

            border-bottom:
                1px solid #e5e7eb;

        }


        .faq-modal-header h2 {
            margin:
                4px 0 6px;
        }


        .faq-modal-header p {
            margin: 0;
        }


        .faq-modal-eyebrow {

            display: inline-block;

            font-size: 12px;

            font-weight: 700;

            color: #dc2626;

            text-transform:
                uppercase;

            letter-spacing:
                0.08em;

        }


        .faq-modal-close {

            flex: 0 0 auto;

            width: 40px;

            height: 40px;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            border: 0;

            border-radius: 999px;

            background: #f3f4f6;

            color: #374151;

            cursor: pointer;

        }


        .faq-modal-close:hover {
            background: #e5e7eb;
        }


        .faq-modal-close svg {

            width: 20px;

            height: 20px;

            fill: none;

            stroke: currentColor;

            stroke-width: 2;

            stroke-linecap: round;

        }


        .faq-modal-body {

            max-height:
                calc(82vh - 120px);

            overflow-y: auto;

            padding:
                8px 20px 20px;

            overscroll-behavior:
                contain;

        }


        .faq-item {

            border-bottom:
                1px solid #e5e7eb;

        }


        .faq-item:last-child {
            border-bottom: 0;
        }


        .faq-item summary {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 18px;

            padding:
                18px 4px;

            list-style: none;

            cursor: pointer;

            font-weight: 700;

            color: #111827;

        }


        .faq-item summary::-webkit-details-marker {
            display: none;
        }


        .faq-arrow {

            flex: 0 0 auto;

            width: 28px;

            height: 28px;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            border-radius: 999px;

            background: #fef2f2;

            color: #dc2626;

            font-size: 21px;

            line-height: 1;

        }


        .faq-answer {

            padding:
                0 4px 18px;

            color: #4b5563;

        }


        .faq-answer p {

            margin: 0;

            line-height: 1.7;

        }


        .faq-empty {
            padding: 24px 4px;
        }


        .station-modal {

            position: fixed;

            inset: 0;

            z-index: 21000;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 24px;

            visibility: hidden;

            opacity: 0;

            pointer-events: none;

            transition:
                opacity 0.2s ease,
                visibility 0.2s ease;

        }


        .station-modal.show {

            visibility: visible;

            opacity: 1;

            pointer-events: auto;

        }


        .station-modal-overlay {

            position: absolute;

            inset: 0;

            background:
                rgba(15, 23, 42, 0.65);

            backdrop-filter:
                blur(4px);

            -webkit-backdrop-filter:
                blur(4px);

        }


        .station-modal-content {

            position: relative;

            z-index: 1;

            width: min(100%, 650px);

            max-height: 88vh;

            overflow: hidden;

            background: #ffffff;

            border-radius: 22px;

            box-shadow:
                0 25px 80px
                rgba(0, 0, 0, 0.28);

            transform:
                translateY(20px)
                scale(0.97);

            transition:
                transform 0.2s ease;

        }


        .station-modal.show
        .station-modal-content {

            transform:
                translateY(0)
                scale(1);

        }


        .station-modal-header {

            display: flex;

            align-items: flex-start;

            justify-content: space-between;

            gap: 15px;

            padding:
                22px 22px 16px;

            border-bottom:
                1px solid #e5e7eb;

        }


        .station-modal-title-area {
            min-width: 0;
        }


        .station-modal-eyebrow {

            display: block;

            margin-bottom: 5px;

            color: #dc2626;

            font-size: 12px;

            font-weight: 700;

            text-transform:
                uppercase;

            letter-spacing:
                0.08em;

        }


        .station-modal-header h2 {

            margin: 0;

            color: #111827;

            font-size: 24px;

            line-height: 1.25;

        }


        .station-modal-close {

            flex: 0 0 auto;

            width: 40px;

            height: 40px;

            display: inline-flex;

            align-items: center;

            justify-content: center;

            border: 0;

            border-radius: 999px;

            background: #f3f4f6;

            color: #374151;

            cursor: pointer;

        }


        .station-modal-close:hover {
            background: #e5e7eb;
        }


        .station-modal-close svg {

            width: 20px;

            height: 20px;

            fill: none;

            stroke: currentColor;

            stroke-width: 2;

            stroke-linecap: round;

        }


        .station-modal-body {

            max-height:
                calc(88vh - 85px);

            overflow-y: auto;

            padding:
                20px 22px 22px;

        }


        /* Station card image */
        .station-image {
            width: 100%;
            height: 220px;
            margin-bottom: 18px;
            overflow: hidden;
            border-radius: 16px;
            background: #f3f4f6;
        }

        .station-image img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
        }


        .station-modal-image {

            width: 100%;

            height: 320px;

            margin-bottom: 18px;

            overflow: hidden;

            border-radius: 16px;

            background: #f3f4f6;

        }


        .station-modal-image img {

            width: 100%;

            height: 100%;

            display: block;

            object-fit: cover;

        }


        .station-modal-location {

            display: flex;

            align-items: flex-start;

            gap: 8px;

            margin-bottom: 18px;

            color: #4b5563;

            line-height: 1.5;

        }


        .station-modal-location svg {

            flex: 0 0 auto;

            width: 20px;

            height: 20px;

            margin-top: 2px;

            fill: none;

            stroke: #dc2626;

            stroke-width: 2;

            stroke-linecap: round;

            stroke-linejoin: round;

        }


        .station-modal-description {

            margin-bottom: 20px;

            color: #4b5563;

            line-height: 1.7;

        }


        .station-info-grid {

            display: grid;

            grid-template-columns:
                repeat(2, minmax(0, 1fr));

            gap: 12px;

            margin-bottom: 20px;

        }


        .station-info-box {

            padding: 14px;

            border:
                1px solid #e5e7eb;

            border-radius: 14px;

            background: #f9fafb;

        }


        .station-info-box small {

            display: block;

            margin-bottom: 5px;

            color: #6b7280;

            font-size: 11px;

            font-weight: 700;

            text-transform: uppercase;

        }


        .station-info-box strong {

            display: block;

            color: #111827;

            line-height: 1.4;

        }


        .station-modal-fuel {
            margin-bottom: 20px;
        }


        .station-modal-fuel h3 {
            margin:
                0 0 12px;
            font-size: 17px;
        }


        .station-modal-prices {

            display: grid;

            grid-template-columns:
                repeat(3, minmax(0, 1fr));

            gap: 10px;

        }


        .station-modal-price {

            padding:
                14px 10px;

            text-align: center;

            border-radius: 14px;

            background: #fef2f2;

        }


        .station-modal-price small {

            display: block;

            margin-bottom: 4px;

            color: #6b7280;

            font-size: 11px;

            font-weight: 700;

        }


        .station-modal-price strong {

            color: #dc2626;

            font-size: 17px;

        }


        .station-modal-actions {

            display: grid;

            grid-template-columns:
                1fr 1fr 1fr;

            gap: 10px;

            margin-top: 20px;

        }


        .station-modal-actions .btn {
            width: 100%;
            text-align: center;
        }


        .station-card[data-station] {

            cursor: pointer;

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;

        }


        .station-card[data-station]:hover {
            transform: translateY(-3px);
        }


        .station-card[data-station]:focus-visible {

            outline:
                3px solid
                rgba(220, 38, 38, 0.25);

            outline-offset: 3px;

        }


        /*
         * Station carousel:
         * - Desktop: exactly 4 station cards visible at a time.
         * - Phone: exactly 1 station card visible at a time (overridden below).
         */
        .station-grid {
            display: flex;
            gap: 14px;
            align-items: stretch;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-snap-type: x mandatory;
            scroll-behavior: smooth;
            overscroll-behavior-x: contain;
            -webkit-overflow-scrolling: touch;
            padding: 4px 0 14px;
            margin-left: 0;
            margin-right: 0;
            scrollbar-width: none;
        }


        .station-grid::-webkit-scrollbar {
            display: none;
        }


        .station-grid .station-card[data-station] {
            /* 3 gaps x 14px = 42px, leaving room for 4 equal cards. */
            flex: 0 0 calc((100% - 42px) / 4);
            width: calc((100% - 42px) / 4);
            min-width: 0;
            max-width: calc((100% - 42px) / 4);
            height: auto;
            scroll-snap-align: start;
            scroll-snap-stop: always;
        }


        .station-grid > .card:not([data-station]) {
            flex: 0 0 100%;
            width: 100%;
        }


        body.modal-open,
        body.faq-modal-open {
            overflow: hidden;
        }


        @media (max-width: 768px) {

            .navlinks {
                display: none !important;
            }


            .pwa-install-button {
                right: 16px;
                bottom: calc(
                    68px +
                    16px +
                    env(safe-area-inset-bottom)
                );
                width: 56px;
                height: 56px;
            }

            .pwa-install-button svg {
                width: 26px;
                height: 26px;
            }


            .mobile-nav {

                position: fixed;

                left: 0;
                right: 0;
                bottom: 0;

                z-index: 9999;

                display: grid;

                grid-template-columns:
                    repeat(4, 1fr);

                height: 68px;

                background: #ffffff;

                border-top:
                    1px solid #e5e7eb;

                box-shadow:
                    0 -4px 15px
                    rgba(0, 0, 0, 0.08);

                padding-bottom:
                    env(safe-area-inset-bottom);

            }


            .mobile-nav-item {

                display: flex;

                flex-direction: column;

                align-items: center;

                justify-content: center;

                gap: 4px;

                color: #6b7280;

                text-decoration: none;

                font-size: 11px;

                font-weight: 600;

                transition:
                    color 0.2s ease,
                    background 0.2s ease;

                -webkit-tap-highlight-color:
                    transparent;

            }


            .mobile-nav-item svg {

                width: 22px;
                height: 22px;

                stroke: currentColor;

                fill: none;

                stroke-width: 2;

                stroke-linecap: round;

                stroke-linejoin: round;

            }


            .mobile-nav-item.active {
                color: #dc2626;
            }


            .mobile-nav-item:active {
                background: #fef2f2;
            }


            body {
                padding-bottom: 78px;
            }


            .topbar {

                position: sticky;

                top: 0;

                z-index: 1000;

            }


            .brand {
                font-size: 20px;
            }


            .searchbar {
                flex-direction: column;
            }


            .searchbar input,
            .searchbar .btn {
                width: 100%;
            }


            .search-input-wrap {
                width: 100%;
            }


            .station-search-suggestions {
                max-height: 280px;
            }


            #stationSlidePrev {
                left: 4px;
            }


            #stationSlideNext {
                right: 4px;
            }


            .mobile-station-slider-button {
                width: 56px;
                height: 56px;
                font-size: 40px;
            }


            .station-grid .station-card[data-station] {
                flex: 0 0 100%;
                width: 100%;
                max-width: 100%;
            }


            #map {
                min-height: 400px;
                border-radius: 12px;
            }


            .actions {
                flex-direction: column;
            }


            .actions .btn {
                width: 100%;
            }


            .map-controls {
                flex-direction: column;
            }


            .map-control-button {
                width: 100%;
            }


            .station-modal {

                align-items: flex-end;

                padding: 0;

            }


            .station-modal-content {

                width: 100%;

                max-height: 92vh;

                border-radius:
                    20px 20px 0 0;

                transform:
                    translateY(30px);

            }


            .station-modal.show
            .station-modal-content {

                transform:
                    translateY(0);

            }


            .station-modal-header {

                padding:
                    18px 18px 14px;

            }


            .station-modal-header h2 {
                font-size: 21px;
            }


            .station-modal-body {

                max-height:
                    calc(92vh - 75px);

                padding:
                    16px 16px 24px;

            }


            .station-image {
                height: 180px;
            }

            .station-modal-image {
                height: 220px;
            }


            .station-info-grid {
                grid-template-columns: 1fr;
            }


            .station-modal-prices {
                grid-template-columns:
                    repeat(3, 1fr);
            }


            .station-modal-actions {
                grid-template-columns: 1fr;
            }


            .faq-modal {

                align-items: flex-end;

                padding: 0;

            }


            .faq-modal-content {

                width: 100%;

                max-height: 88vh;

                border-radius:
                    20px 20px 0 0;

            }

        }


        @media (max-width: 400px) {

            .mobile-nav {
                height: 64px;
            }


            .mobile-nav-item {
                font-size: 10px;
            }


            .mobile-nav-item svg {
                width: 20px;
                height: 20px;
            }

        }

    </style>


    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

</head>


<body>


<header class="topbar">

    <div class="container nav">

        <a
            class="brand"
            href="index.php"
            aria-label="GasGo Home"
        >
            ⛽ GasGo
        </a>


        <nav
            class="navlinks"
            aria-label="Main navigation"
        >

            <a href="#home">
                Home
            </a>

            <a href="#stations">
                Stations
            </a>

            <a href="#map-section">
                Map
            </a>

            <a
                href="#"
                data-faq-open
            >
                FAQ
            </a>

            <a href="public/manager/login.php">
                Manager Login
            </a>

        </nav>

    </div>

</header>


<main id="home">


<section class="hero">

    <div class="container">

        <h1>
            Find Fuel Stations Across Siargao
        </h1>

        <p>
            Locate nearby gasoline stations, compare fuel
            prices, check LPG availability, and get directions.
        </p>


        <div class="searchbar">

            <div class="search-input-wrap">

                <input
                    type="search"
                    id="stationSearch"
                    name="stationSearch"
                    placeholder="Search station, barangay, or address"
                    autocomplete="off"
                    aria-label="Search gasoline stations"
                    aria-autocomplete="list"
                    aria-controls="stationSearchSuggestions"
                    aria-expanded="false"
                >

                <div
                    id="stationSearchSuggestions"
                    class="station-search-suggestions"
                    role="listbox"
                    aria-label="Gasoline station suggestions"
                ></div>

            </div>


            <a
                class="btn btn-primary"
                href="#stations"
            >
                Find Stations
            </a>

            <button
                type="button"
                class="btn ai-recommend-btn"
                id="aiRecommendButton"
                aria-label="Open GasGo AI chat"
            >
                ✨ Ask GasGo AI
            </button>

        </div>

    </div>

</section>


<section
    class="section"
    id="map-section"
>

    <div class="container">

        <div class="section-heading">

            <div>

                <h2>
                    Siargao Station Map
                </h2>

                <p class="muted">
                    All approved gasoline stations are displayed
                    on the map. Click a marker to view station
                    information and get directions.
                </p>

            </div>

        </div>


        <div
            id="map"
            aria-label="GasGo gasoline station map"
        ></div>




        <div
            id="routeStatus"
            class="route-status"
        ></div>

    </div>

</section>


<section
    class="section"
    id="stations"
>

    <div class="container">

        <div class="section-heading">

            <div>

                <h2>
                    Gasoline Stations
                </h2>

                <p class="muted">
                    Browse approved gasoline stations
                    across Siargao Island.
                </p>

            </div>


            <span id="stationCount">

                <?= count($stations) ?>

                <?= count($stations) === 1
                    ? 'station'
                    : 'stations'
                ?>

            </span>

        </div>


        <div class="station-carousel-shell">

            <?php if (count($stations) > 1): ?>

                <div
                    class="mobile-station-slider-controls"
                    id="mobileStationSliderControls"
                    aria-label="Browse gasoline stations"
                >

                    <button
                        type="button"
                        class="mobile-station-slider-button"
                        id="stationSlidePrev"
                        aria-label="Previous gasoline station"
                    >
                        &lt;
                    </button>

                    <button
                        type="button"
                        class="mobile-station-slider-button"
                        id="stationSlideNext"
                        aria-label="Next gasoline station"
                    >
                        &gt;
                    </button>

                </div>

            <?php endif; ?>


            <div
                class="station-grid"
                id="stationList"
            >

            <?php if (empty($stations)): ?>

                <div class="card">

                    <h3>
                        No stations available
                    </h3>

                    <p class="muted">
                        There are currently no approved
                        gasoline stations available.
                    </p>

                </div>

            <?php else: ?>


                <?php foreach ($stations as $station): ?>

                    <?php

                    $id = (int) (
                        $station['id'] ?? 0
                    );


                    $name = htmlspecialchars(
                        (string) (
                            $station['name']
                            ?? 'Gas Station'
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    );


                    $barangay = htmlspecialchars(
                        (string) (
                            $station['barangay']
                            ?? ''
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    );


                    $address = htmlspecialchars(
                        (string) (
                            $station['address']
                            ?? ''
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    );


                    $description = htmlspecialchars(
                        (string) (
                            $station['description']
                            ?? ''
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    );


                    $operatingHours = htmlspecialchars(
                        (string) (
                            $station['operating_hours']
                            ?? 'Hours not provided'
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    );


                    $contactNumber = htmlspecialchars(
                        (string) (
                            $station['contact_number']
                            ?? ''
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    );


                    $image = htmlspecialchars(
                        (string) (
                            $station['image_url']
                            ?? ''
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    );


                    $dieselAvailable =
                        (int) (
                            $station['diesel_available']
                            ?? 0
                        ) === 1;


                    $unleadedAvailable =
                        (int) (
                            $station['regular_available']
                            ?? 0
                        ) === 1;


                    $premiumAvailable =
                        (int) (
                            $station['premium_available']
                            ?? 0
                        ) === 1;


                    $dieselPrice =
                        $station['diesel_price']
                        ?? null;


                    $unleadedPrice =
                        $station['unleaded_price']
                        ?? null;


                    $premiumPrice =
                        $station['premium_price']
                        ?? null;


                    $lpgPrice =
                        $station['lpg_price']
                        ?? null;


                    $lpgAvailable =
                        (bool) (
                            $station['lpg_available']
                            ?? false
                        );

                    $lpgProducts =
                        is_array($station['lpg_products'] ?? null)
                            ? $station['lpg_products']
                            : [];

                    ?>


                    <article
                        class="card station-card"
                        data-station
                        data-station-id="<?= $id ?>"
                        data-name="<?= $name ?>"
                        data-barangay="<?= $barangay ?>"
                        data-address="<?= $address ?>"
                        tabindex="0"
                        role="button"
                        aria-label="View details for <?= $name ?>"
                    >


                        <?php if (!empty($image)): ?>

                            <div class="station-image">

                                <img
                                    src="<?= $image ?>"
                                    alt="<?= $name ?>"
                                    loading="lazy"
                                    onerror="this.closest('.station-image').style.display='none';"
                                >

                            </div>

                        <?php endif; ?>


                        <?php if (!empty($barangay)): ?>

                            <span class="tag">
                                <?= $barangay ?>
                            </span>

                        <?php endif; ?>


                        <h3>
                            <?= $name ?>
                        </h3>


                        <?php if (!empty($address)): ?>

                            <p class="muted">
                                <?= $address ?>
                            </p>

                        <?php endif; ?>


                        <?php if (!empty($description)): ?>

                            <p>
                                <?= $description ?>
                            </p>

                        <?php endif; ?>


                        <p>

                            <strong>
                                Operating Hours:
                            </strong>

                            <?= $operatingHours ?>

                        </p>


                        <div class="price-row">


                            <div class="price">

                                <small>
                                    Diesel
                                </small>

                                <span class="product-status <?= $dieselAvailable ? 'is-available' : 'is-unavailable' ?>">
                                    <?= $dieselAvailable ? 'Available' : 'Not Available' ?>
                                </span>

                                <strong class="product-price">
                                    <?php if (
                                        $dieselAvailable &&
                                        $dieselPrice !== null &&
                                        $dieselPrice !== ''
                                    ): ?>

                                        ₱<?= htmlspecialchars(
                                            (string) $dieselPrice,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    <?php elseif ($dieselAvailable): ?>

                                        Price N/A

                                    <?php else: ?>

                                        Unavailable

                                    <?php endif; ?>
                                </strong>

                            </div>


                            <div class="price">

                                <small>
                                    Unleaded
                                </small>

                                <span class="product-status <?= $unleadedAvailable ? 'is-available' : 'is-unavailable' ?>">
                                    <?= $unleadedAvailable ? 'Available' : 'Not Available' ?>
                                </span>

                                <strong class="product-price">
                                    <?php if (
                                        $unleadedAvailable &&
                                        $unleadedPrice !== null &&
                                        $unleadedPrice !== ''
                                    ): ?>

                                        ₱<?= htmlspecialchars(
                                            (string) $unleadedPrice,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    <?php elseif ($unleadedAvailable): ?>

                                        Price N/A

                                    <?php else: ?>

                                        Unavailable

                                    <?php endif; ?>
                                </strong>

                            </div>


                            <div class="price">

                                <small>
                                    Premium
                                </small>

                                <span class="product-status <?= $premiumAvailable ? 'is-available' : 'is-unavailable' ?>">
                                    <?= $premiumAvailable ? 'Available' : 'Not Available' ?>
                                </span>

                                <strong class="product-price">
                                    <?php if (
                                        $premiumAvailable &&
                                        $premiumPrice !== null &&
                                        $premiumPrice !== ''
                                    ): ?>

                                        ₱<?= htmlspecialchars(
                                            (string) $premiumPrice,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                    <?php elseif ($premiumAvailable): ?>

                                        Price N/A

                                    <?php else: ?>

                                        Unavailable

                                    <?php endif; ?>
                                </strong>

                            </div>

                        </div>


                        <p>

                            <strong>
                                LPG:
                            </strong>

                            <?php if ($lpgAvailable): ?>

                                <span class="product-status is-available">
                                    Available
                                </span>

                                <?php if (!empty($lpgProducts)): ?>
                                    <br>
                                    <?php foreach ($lpgProducts as $index => $lpgProduct): ?>
                                        <?php if ($index > 0): ?> · <?php endif; ?>
                                        <?= htmlspecialchars(rtrim(rtrim(number_format((float)$lpgProduct['kg'], 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8') ?>kg
                                        — ₱<?= htmlspecialchars(number_format((float)$lpgProduct['price'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>
                                    <?php endforeach; ?>
                                <?php elseif ($lpgPrice !== null && $lpgPrice !== ''): ?>
                                    — ₱<?= htmlspecialchars((string)$lpgPrice, ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>

                            <?php else: ?>

                                <span class="product-status is-unavailable">
                                    Not Available
                                </span>

                            <?php endif; ?>

                        </p>


                        <?php if (!empty($contactNumber)): ?>

                            <p>

                                <strong>
                                    Contact:
                                </strong>

                                <?= $contactNumber ?>

                            </p>

                        <?php endif; ?>


                        <div class="actions">

                            <a
                                class="btn btn-primary"
                                href="public/station.php?id=<?= $id ?>"
                                onclick="event.stopPropagation();"
                            >
                                Full Details
                            </a>


                            <button
                                type="button"
                                class="btn find-location-button"
                                data-find-location="<?= $id ?>"
                                onclick="event.stopPropagation();"
                            >
                                📍 Find Location
                            </button>

                        </div>


                    </article>

                <?php endforeach; ?>

            <?php endif; ?>

            </div>

        </div>

    </div>

</section>


<div
    id="faqModal"
    class="faq-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="faqModalTitle"
    aria-hidden="true"
>

    <div
        class="faq-modal-overlay"
        data-faq-close
    ></div>


    <div
        class="faq-modal-content"
        role="document"
    >

        <div class="faq-modal-header">

            <div>

                <span class="faq-modal-eyebrow">
                    GasGo Help
                </span>

                <h2 id="faqModalTitle">
                    Frequently Asked Questions
                </h2>

                <p class="muted">
                    Find answers about GasGo and using
                    the station locator.
                </p>

            </div>


            <button
                type="button"
                class="faq-modal-close"
                data-faq-close
                aria-label="Close frequently asked questions"
            >

                <svg
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >

                    <path d="M6 6l12 12"></path>

                    <path d="M18 6L6 18"></path>

                </svg>

            </button>

        </div>


        <div class="faq-modal-body">

            <!-- Built-in GasGo FAQs -->
            <details class="faq-item">
                <summary>
                    <span>
                        How do I find the nearest gas station using GasGo?
                    </span>
                    <span class="faq-arrow" aria-hidden="true">+</span>
                </summary>
                <div class="faq-answer">
                    <p>
                        Open the map and tap a gasoline station marker to
                        view its information. Choose <strong>Get Route</strong>
                        and allow location access when prompted so GasGo can
                        route you from your current location to that station.
                    </p>
                </div>
            </details>

            <details class="faq-item">
                <summary>
                    <span>
                        How can I check the fuel prices of a station?
                    </span>
                    <span class="faq-arrow" aria-hidden="true">+</span>
                </summary>
                <div class="faq-answer">
                    <p>
                        Open a gasoline station from the station list or map.
                        GasGo displays the available prices for Diesel,
                        Unleaded, and Premium fuel.
                    </p>
                </div>
            </details>

            <details class="faq-item">
                <summary>
                    <span>
                        How do I know if a station has LPG available?
                    </span>
                    <span class="faq-arrow" aria-hidden="true">+</span>
                </summary>
                <div class="faq-answer">
                    <p>
                        Check the LPG information shown on the station card
                        or station details. GasGo will show whether LPG is
                        available and its price when the station provides it.
                    </p>
                </div>
            </details>

            <details class="faq-item">
                <summary>
                    <span>
                        How can I get directions to a gasoline station?
                    </span>
                    <span class="faq-arrow" aria-hidden="true">+</span>
                </summary>
                <div class="faq-answer">
                    <p>
                        Select a gasoline station and use
                        <strong>Get Route</strong>. Allow location access when
                        prompted so GasGo can create a route from your current
                        location to the selected station.
                    </p>
                </div>
            </details>

            <details class="faq-item">
                <summary>
                    <span>
                        Why is a gasoline station not showing on GasGo?
                    </span>
                    <span class="faq-arrow" aria-hidden="true">+</span>
                </summary>
                <div class="faq-answer">
                    <p>
                        GasGo only displays gasoline stations that have been
                        approved in the system. A station may not appear if
                        its information is still pending approval or has not
                        yet been added to the platform.
                    </p>
                </div>
            </details>

            <?php if (empty($faq)): ?>

                <div class="faq-empty">

                    <p class="muted">
                        No FAQs are available at the moment.
                    </p>

                </div>

            <?php else: ?>


                <?php foreach ($faq as $item): ?>

                    <?php

                    $question = htmlspecialchars(
                        (string) (
                            $item['question']
                            ?? ''
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    );


                    $answer = htmlspecialchars(
                        (string) (
                            $item['answer']
                            ?? ''
                        ),
                        ENT_QUOTES,
                        'UTF-8'
                    );

                    ?>


                    <details class="faq-item">

                        <summary>

                            <span>
                                <?= $question ?>
                            </span>

                            <span
                                class="faq-arrow"
                                aria-hidden="true"
                            >
                                +
                            </span>

                        </summary>


                        <div class="faq-answer">

                            <p>
                                <?= nl2br($answer) ?>
                            </p>

                        </div>

                    </details>


                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>

</div>


<div
    id="stationModal"
    class="station-modal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="stationModalTitle"
    aria-hidden="true"
>

    <div
        class="station-modal-overlay"
        data-station-close
    ></div>


    <div
        class="station-modal-content"
        role="document"
    >


        <div class="station-modal-header">

            <div class="station-modal-title-area">

                <span class="station-modal-eyebrow">
                    GasGo Station
                </span>

                <h2 id="stationModalTitle">
                    Station
                </h2>

            </div>


            <button
                type="button"
                class="station-modal-close"
                data-station-close
                aria-label="Close station details"
            >

                <svg
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >

                    <path d="M6 6l12 12"></path>

                    <path d="M18 6L6 18"></path>

                </svg>

            </button>

        </div>


        <div class="station-modal-body">


            <div
                id="stationModalImage"
                class="station-modal-image"
                style="display:none;"
            >

                <img
                    id="stationModalImageElement"
                    src=""
                    alt=""
                >

            </div>


            <div class="station-modal-location">

                <svg
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >

                    <path
                        d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"
                    />

                    <circle
                        cx="12"
                        cy="10"
                        r="2.5"
                    />

                </svg>


                <div>

                    <strong id="stationModalBarangay"></strong>

                    <br>

                    <span id="stationModalAddress"></span>

                </div>

            </div>


            <div
                id="stationModalDescription"
                class="station-modal-description"
            ></div>


            <div class="station-info-grid">


                <div class="station-info-box">

                    <small>
                        Operating Hours
                    </small>

                    <strong id="stationModalHours">
                        Not provided
                    </strong>

                </div>


                <div class="station-info-box">

                    <small>
                        Contact
                    </small>

                    <strong id="stationModalContact">
                        Not provided
                    </strong>

                </div>


                <div class="station-info-box">

                    <small>
                        LPG
                    </small>

                    <strong id="stationModalLpg">
                        Not available
                    </strong>

                </div>


                <div class="station-info-box">

                    <small>
                        Location
                    </small>

                    <strong id="stationModalCoordinates">
                        Coordinates unavailable
                    </strong>

                </div>

            </div>


            <div class="station-modal-fuel">

                <h3>
                    Fuel Prices
                </h3>


                <div class="station-modal-prices">


                    <div class="station-modal-price">

                        <small>
                            Diesel
                        </small>

                        <strong id="stationModalDiesel">
                            N/A
                        </strong>

                    </div>


                    <div class="station-modal-price">

                        <small>
                            Unleaded
                        </small>

                        <strong id="stationModalUnleaded">
                            N/A
                        </strong>

                    </div>


                    <div class="station-modal-price">

                        <small>
                            Premium
                        </small>

                        <strong id="stationModalPremium">
                            N/A
                        </strong>

                    </div>

                </div>

            </div>


            <div class="station-modal-actions">


                <button
                    type="button"
                    class="btn btn-primary"
                    id="stationViewMap"
                >
                    📍 Find Location
                </button>


                <button
                    type="button"
                    class="btn"
                    id="stationGetRoute"
                >
                    🚗 Get Route
                </button>


                <a
                    href="#"
                    class="btn"
                    id="stationFullDetails"
                >
                    Full Details
                </a>


            </div>

        </div>

    </div>

</div>


</main>


<footer class="footer">

    <div class="container">

        <p>

            &copy; <?= date('Y') ?> GasGo —
            Siargao Island, Philippines

        </p>

    </div>

</footer>



<button
    type="button"
    class="pwa-install-button"
    data-install-app
    aria-label="Install GasGo app"
    title="Install GasGo"
>
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 3v11"></path>
        <path d="m7.5 10 4.5 4.5 4.5-4.5"></path>
        <path d="M5 20h14"></path>
    </svg>
</button>

<button
    type="button"
    class="gasgo-ai-launcher"
    id="gasgoAiLauncher"
    aria-label="Open GasGo AI chat"
    aria-controls="gasgoAiChat"
    aria-expanded="false"
    title="Ask GasGo AI"
>
    <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"></path>
        <path d="M8 9h8"></path>
        <path d="M8 13h5"></path>
    </svg>
    <span class="ai-online-dot" aria-hidden="true"></span>
</button>

<section
    class="gasgo-ai-chat"
    id="gasgoAiChat"
    role="dialog"
    aria-modal="false"
    aria-label="GasGo AI assistant"
    aria-hidden="true"
>
    <header class="gasgo-ai-chat-header">
        <div class="gasgo-ai-chat-title">
            <span class="gasgo-ai-avatar" aria-hidden="true">✨</span>
            <div>
                <strong>GasGo AI</strong>
                <small>Ask about stations, prices, LPG & GasGo</small>
            </div>
        </div>
        <button type="button" class="gasgo-ai-close" id="gasgoAiClose" aria-label="Close GasGo AI">×</button>
    </header>

    <div class="gasgo-ai-messages" id="gasgoAiMessages" aria-live="polite">
        <div class="gasgo-ai-row assistant">
            <div class="gasgo-ai-bubble">Hi! I’m GasGo AI. Ask me anything about GasGo, fuel stations, prices, LPG availability, or which station may fit your needs.</div>
        </div>
    </div>

    <form class="gasgo-ai-composer" id="gasgoAiForm">
        <div class="gasgo-ai-input-row">
            <textarea
                class="gasgo-ai-input"
                id="gasgoAiInput"
                rows="1"
                maxlength="1200"
                placeholder="Ask GasGo AI..."
                aria-label="Message GasGo AI"
            ></textarea>
            <button type="submit" class="gasgo-ai-send" id="gasgoAiSend" aria-label="Send message">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="m22 2-7 20-4-9-9-4Z"></path>
                    <path d="M22 2 11 13"></path>
                </svg>
            </button>
        </div>
        <p class="gasgo-ai-disclaimer">AI answers use current GasGo database information. Fuel prices and availability can change.</p>
    </form>
</section>

<nav
    class="mobile-nav"
    aria-label="Mobile navigation"
>


    <a
        href="#home"
        class="mobile-nav-item active"
        data-section="home"
        aria-label="Home"
    >

        <svg
            viewBox="0 0 24 24"
            aria-hidden="true"
        >

            <path d="M3 10.5L12 3l9 7.5"></path>

            <path d="M5 9.5V21h14V9.5"></path>

            <path d="M9 21v-6h6v6"></path>

        </svg>


        <span>
            Home
        </span>

    </a>


    <a
        href="#stations"
        class="mobile-nav-item"
        data-section="stations"
        aria-label="Stations"
    >

        <svg
            viewBox="0 0 24 24"
            aria-hidden="true"
        >

            <path
                d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"
            ></path>

            <circle
                cx="12"
                cy="10"
                r="2.5"
            ></circle>

        </svg>


        <span>
            Stations
        </span>

    </a>


    <a
        href="#map-section"
        class="mobile-nav-item"
        data-section="map-section"
        aria-label="Map"
    >

        <svg
            viewBox="0 0 24 24"
            aria-hidden="true"
        >

            <path d="M9 18l-6 3V6l6-3 6 3 6-3v15l-6 3-6-3Z"></path>

            <path d="M9 3v15"></path>

            <path d="M15 6v15"></path>

        </svg>


        <span>
            Map
        </span>

    </a>


    <a
        href="#"
        class="mobile-nav-item"
        data-faq-open
        aria-label="FAQ"
    >

        <svg
            viewBox="0 0 24 24"
            aria-hidden="true"
        >

            <circle
                cx="12"
                cy="12"
                r="9"
            ></circle>

            <path
                d="M9.5 9a2.5 2.5 0 1 1 4.4 1.6c-.8.8-1.9 1.2-1.9 2.4"
            ></path>

            <path
                d="M12 16.5h.01"
            ></path>

        </svg>


        <span>
            FAQ
        </span>

    </a>

</nav>


<script>

const stations =
    <?= $stationsJson ?>;

</script>


<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
></script>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {


        const mapElement =
            document.getElementById('map');


        if (!mapElement) {
            return;
        }


        const defaultCenter = [
            9.8487,
            126.0458
        ];


        const gasMap =
            L.map(
                'map',
                {
                    zoomControl: true
                }
            );


        window.gasGoMap = gasMap;


        const streetLayer = L.tileLayer(
            'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            {
                maxNativeZoom: 19,
                maxZoom: 21,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors'
            }
        );

        const satelliteLayer = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            {
                maxNativeZoom: 19,
                maxZoom: 21,
                attribution: 'Imagery &copy; Esri and contributors'
            }
        );

        const terrainLayer = L.tileLayer(
            'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
            {
                maxNativeZoom: 17,
                maxZoom: 21,
                attribution: 'Map data &copy; OpenStreetMap contributors, SRTM | Map style &copy; OpenTopoMap'
            }
        );

        const humanitarianLayer = L.tileLayer(
            'https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png',
            {
                maxNativeZoom: 19,
                maxZoom: 21,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors, Tiles style by HOT'
            }
        );

        streetLayer.addTo(gasMap);

        L.control.layers(
            {
                'Street': streetLayer,
                'Satellite': satelliteLayer,
                'Terrain': terrainLayer,
                'Humanitarian': humanitarianLayer
            },
            null,
            {
                position: 'topright',
                collapsed: true
            }
        ).addTo(gasMap);


        gasMap.setView(
            defaultCenter,
            11
        );


        const stationMarkers = {};


        const stationIcon =
            L.divIcon({
                className: '',
                html:
                    '<div class="map-station-icon">⛽</div>',
                iconSize: [
                    38,
                    38
                ],
                iconAnchor: [
                    19,
                    19
                ],
                popupAnchor: [
                    0,
                    -20
                ]
            });


        let userLocationMarker = null;
        let userAccuracyCircle = null;

        let currentUserLocation = null;

        let routeLine = null;


        function escapeHtml(value) {

            const div =
                document.createElement('div');

            div.textContent =
                value === null ||
                value === undefined
                    ? ''
                    : String(value);

            return div.innerHTML;
        }


        function formatPrice(price) {

            if (
                price === null ||
                price === undefined ||
                price === ''
            ) {

                return 'N/A';

            }

            return '₱' +
                escapeHtml(price);

        }


        function validCoordinates(station) {

            const latitude =
                parseFloat(
                    station.latitude
                );

            const longitude =
                parseFloat(
                    station.longitude
                );


            return (
                Number.isFinite(latitude) &&
                Number.isFinite(longitude) &&
                latitude >= -90 &&
                latitude <= 90 &&
                longitude >= -180 &&
                longitude <= 180
            );

        }


        function getStationById(id) {

            return stations.find(
                function (station) {

                    return String(
                        station.id
                    ) === String(id);

                }
            );

        }


        function buildStationPopup(station) {

            const name =
                escapeHtml(
                    station.name ||
                    'Gas Station'
                );


            const barangay =
                escapeHtml(
                    station.barangay ||
                    'Siargao Island'
                );


            const address =
                escapeHtml(
                    station.address ||
                    'Address not provided'
                );


            const hours =
                escapeHtml(
                    station.operating_hours ||
                    'Hours not provided'
                );


            const contact =
                escapeHtml(
                    station.contact_number ||
                    'Not provided'
                );


            const lpgAvailable =
                String(
                    station.lpg_available
                ) === '1' ||
                String(
                    station.lpg_available
                ).toLowerCase() ===
                    'true';


            const lpgProducts =
                Array.isArray(station.lpg_products)
                    ? station.lpg_products.filter(function (product) {
                        return String(product.available) === '1' || product.available === true;
                    })
                    : [];

            const lpgText =
                lpgAvailable
                    ? (
                        lpgProducts.length > 0
                            ? lpgProducts.map(function (product) {
                                const kg = parseFloat(product.kg);
                                const kgText = Number.isFinite(kg)
                                    ? String(kg).replace(/\.0+$/, '') + 'kg'
                                    : '';
                                return kgText + ' — ₱' + escapeHtml(product.price);
                            }).join('<br>')
                            : (
                                station.lpg_price !== null &&
                                station.lpg_price !== undefined &&
                                station.lpg_price !== ''
                                    ? 'Available — ₱' + escapeHtml(station.lpg_price)
                                    : 'Available'
                            )
                    )
                    : 'Not available';


            return `

                <div class="map-popup">

                    <h3 class="map-popup-title">
                        ${name}
                    </h3>


                    <p class="map-popup-location">
                        📍 ${barangay}<br>
                        ${address}
                    </p>


                    <p class="map-popup-info">
                        <strong>Hours:</strong>
                        ${hours}
                    </p>


                    <p class="map-popup-info">
                        <strong>Contact:</strong>
                        ${contact}
                    </p>


                    <div class="map-popup-prices">

                        <div class="map-popup-price">

                            <small>
                                Diesel
                            </small>

                            <span class="map-product-status ${isProductAvailable(station.diesel_available) ? 'available' : 'unavailable'}">
                                ${isProductAvailable(station.diesel_available) ? 'Available' : 'Not Available'}
                            </span>

                            <strong>
                                ${isProductAvailable(station.diesel_available)
                                    ? formatPrice(station.diesel_price)
                                    : 'Unavailable'}
                            </strong>

                        </div>


                        <div class="map-popup-price">

                            <small>
                                Unleaded
                            </small>

                            <span class="map-product-status ${isProductAvailable(station.regular_available) ? 'available' : 'unavailable'}">
                                ${isProductAvailable(station.regular_available) ? 'Available' : 'Not Available'}
                            </span>

                            <strong>
                                ${isProductAvailable(station.regular_available)
                                    ? formatPrice(station.unleaded_price)
                                    : 'Unavailable'}
                            </strong>

                        </div>


                        <div class="map-popup-price">

                            <small>
                                Premium
                            </small>

                            <span class="map-product-status ${isProductAvailable(station.premium_available) ? 'available' : 'unavailable'}">
                                ${isProductAvailable(station.premium_available) ? 'Available' : 'Not Available'}
                            </span>

                            <strong>
                                ${isProductAvailable(station.premium_available)
                                    ? formatPrice(station.premium_price)
                                    : 'Unavailable'}
                            </strong>

                        </div>

                    </div>


                    <p class="map-popup-info">
                        <strong>LPG:</strong>
                        ${lpgText}
                    </p>


                    <div class="map-popup-actions">

                        <button
                            type="button"
                            class="map-popup-button"
                            data-map-route="${escapeHtml(station.id)}"
                        >
                            🚗 Get Route
                        </button>


                        <a
                            href="public/station.php?id=${encodeURIComponent(station.id)}"
                            class="map-popup-button secondary"
                        >
                            Full Details
                        </a>

                    </div>

                </div>

            `;
        }


        const validStations = [];


        stations.forEach(
            function (station) {

                if (
                    !validCoordinates(
                        station
                    )
                ) {

                    return;

                }


                const latitude =
                    parseFloat(
                        station.latitude
                    );


                const longitude =
                    parseFloat(
                        station.longitude
                    );


                const marker =
                    L.marker(
                        [
                            latitude,
                            longitude
                        ],
                        {
                            icon:
                                stationIcon
                        }
                    );


                marker.bindPopup(
                    buildStationPopup(
                        station
                    ),
                    {
                        maxWidth: 320
                    }
                );


                marker.addTo(
                    gasMap
                );


                stationMarkers[
                    String(station.id)
                ] = marker;


                validStations.push(
                    station
                );

            }
        );


        function showAllStations() {

            if (
                validStations.length === 0
            ) {

                gasMap.setView(
                    defaultCenter,
                    11
                );

                return;

            }


            const bounds =
                L.latLngBounds([]);


            validStations.forEach(
                function (station) {

                    bounds.extend(
                        [
                            parseFloat(
                                station.latitude
                            ),
                            parseFloat(
                                station.longitude
                            )
                        ]
                    );

                }
            );


            gasMap.fitBounds(
                bounds,
                {
                    padding: [
                        50,
                        50
                    ],
                    maxZoom: 14
                }
            );

        }


        document
            .getElementById(
                'showAllStationsButton'
            )
            ?.addEventListener(
                'click',
                function () {

                    showAllStations();

                }
            );


        function findStationOnMap(
            station,
            openPopup = true
        ) {

            if (!station) {
                return;
            }


            if (
                !validCoordinates(
                    station
                )
            ) {

                alert(
                    'This station does not have valid map coordinates.'
                );

                return;

            }


            const latitude =
                parseFloat(
                    station.latitude
                );


            const longitude =
                parseFloat(
                    station.longitude
                );


            const marker =
                stationMarkers[
                    String(
                        station.id
                    )
                ];


            document
                .getElementById(
                    'map-section'
                )
                ?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });


            setTimeout(
                function () {

                    gasMap.invalidateSize();


                    gasMap.setView(
                        [
                            latitude,
                            longitude
                        ],
                        17,
                        {
                            animate: true
                        }
                    );


                    if (
                        marker &&
                        openPopup
                    ) {

                        marker.openPopup();

                    }

                },
                350
            );

        }


        document
            .querySelectorAll(
                '[data-find-location]'
            )
            .forEach(
                function (button) {

                    button.addEventListener(
                        'click',
                        function (event) {

                            event.preventDefault();
                            event.stopPropagation();


                            const station =
                                getStationById(
                                    this.dataset.findLocation
                                );


                            findStationOnMap(
                                station
                            );

                        }
                    );

                }
            );


        function showUserLocation(
            latitude,
            longitude,
            accuracy = 0
        ) {

            currentUserLocation = {
                latitude:
                    latitude,
                longitude:
                    longitude
            };


            const userLatLng =
                [
                    latitude,
                    longitude
                ];


            if (
                userLocationMarker
            ) {

                userLocationMarker.setLatLng(
                    userLatLng
                );

            } else {

                const userIcon =
                    L.divIcon({
                        className: '',
                        html:
                            '<div class="map-user-location" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20.5 3.5 3.8 10.2c-.9.4-.9 1.7.1 2l6.3 1.6 1.6 6.3c.2 1 1.6 1 2 .1l6.7-16.7Z"></path></svg></div>',
                        iconSize: [
                            34,
                            34
                        ],
                        iconAnchor: [
                            17,
                            17
                        ]
                    });


                userLocationMarker =
                    L.marker(
                        userLatLng,
                        {
                            icon:
                                userIcon,
                            zIndexOffset:
                                1000
                        }
                    )
                    .addTo(
                        gasMap
                    );


                userLocationMarker.bindPopup(
                    '<strong>Your Current Location</strong>'
                );

            }


            if (
                userAccuracyCircle
            ) {

                userAccuracyCircle.setLatLng(
                    userLatLng
                );

                userAccuracyCircle.setRadius(
                    accuracy
                );

            } else {

                userAccuracyCircle =
                    L.circle(
                        userLatLng,
                        {
                            radius:
                                accuracy,
                            color:
                                '#2563eb',
                            fillColor:
                                '#2563eb',
                            fillOpacity:
                                0.08,
                            weight:
                                1
                        }
                    )
                    .addTo(
                        gasMap
                    );

            }

        }


        function getCurrentLocation(
            callback
        ) {

            if (
                !navigator.geolocation
            ) {

                alert(
                    'Geolocation is not supported by your browser.'
                );

                return;

            }


            const routeStatus =
                document.getElementById(
                    'routeStatus'
                );


            if (routeStatus) {

                routeStatus.textContent =
                    'Getting your current location...';

                routeStatus.classList.add(
                    'show'
                );

            }


            navigator.geolocation.getCurrentPosition(

                function (position) {

                    const latitude =
                        position.coords.latitude;


                    const longitude =
                        position.coords.longitude;


                    const accuracy =
                        position.coords.accuracy ||
                        0;


                    showUserLocation(
                        latitude,
                        longitude,
                        accuracy
                    );


                    if (routeStatus) {

                        routeStatus.textContent =
                            'Your current location has been detected.';

                    }


                    if (
                        typeof callback ===
                        'function'
                    ) {

                        callback({
                            latitude:
                                latitude,
                            longitude:
                                longitude
                        });

                    }

                },


                function (error) {

                    let message =
                        'Unable to get your current location.';


                    if (
                        error.code ===
                        error.PERMISSION_DENIED
                    ) {

                        message =
                            'Location permission was denied. Please allow location access in your browser.';

                    } else if (
                        error.code ===
                        error.POSITION_UNAVAILABLE
                    ) {

                        message =
                            'Your current location is unavailable.';

                    } else if (
                        error.code ===
                        error.TIMEOUT
                    ) {

                        message =
                            'Getting your location took too long. Please try again.';

                    }


                    if (routeStatus) {

                        routeStatus.textContent =
                            message;

                        routeStatus.classList.add(
                            'show'
                        );

                    }


                    alert(message);

                },


                {
                    enableHighAccuracy:
                        true,

                    timeout:
                        15000,

                    maximumAge:
                        0
                }

            );

        }


        document
            .getElementById(
                'locateMeButton'
            )
            ?.addEventListener(
                'click',
                function () {

                    getCurrentLocation(
                        function (location) {

                            gasMap.setView(
                                [
                                    location.latitude,
                                    location.longitude
                                ],
                                15,
                                {
                                    animate:
                                        true
                                }
                            );

                        }
                    );

                }
            );


        function routeToStation(
            station
        ) {

            if (!station) {
                return;
            }


            if (
                !validCoordinates(
                    station
                )
            ) {

                alert(
                    'This station does not have valid map coordinates.'
                );

                return;

            }


            function calculateRoute(
                location
            ) {

                const startLatitude =
                    location.latitude;


                const startLongitude =
                    location.longitude;


                const destinationLatitude =
                    parseFloat(
                        station.latitude
                    );


                const destinationLongitude =
                    parseFloat(
                        station.longitude
                    );


                const routeStatus =
                    document.getElementById(
                        'routeStatus'
                    );


                if (routeStatus) {

                    routeStatus.textContent =
                        'Finding the best route to ' +
                        (station.name ||
                            'the station') +
                        '...';

                    routeStatus.classList.add(
                        'show'
                    );

                }


                if (routeLine) {

                    gasMap.removeLayer(
                        routeLine
                    );

                    routeLine = null;

                }


                const url =
                    'https://router.project-osrm.org/route/v1/driving/' +
                    startLongitude +
                    ',' +
                    startLatitude +
                    ';' +
                    destinationLongitude +
                    ',' +
                    destinationLatitude +
                    '?overview=full&geometries=geojson';


                fetch(url)

                    .then(
                        function (response) {

                            if (
                                !response.ok
                            ) {

                                throw new Error(
                                    'Routing service unavailable.'
                                );

                            }

                            return response.json();

                        }
                    )

                    .then(
                        function (data) {

                            if (
                                data.code !==
                                'Ok' ||
                                !data.routes ||
                                !data.routes.length
                            ) {

                                throw new Error(
                                    'No route was found.'
                                );

                            }


                            const route =
                                data.routes[0];


                            const coordinates =
                                route.geometry.coordinates;


                            const leafletCoordinates =
                                coordinates.map(
                                    function (coordinate) {

                                        return [
                                            coordinate[1],
                                            coordinate[0]
                                        ];

                                    }
                                );


                            routeLine =
                                L.polyline(
                                    leafletCoordinates,
                                    {
                                        color:
                                            '#dc2626',
                                        weight:
                                            6,
                                        opacity:
                                            0.85
                                    }
                                )
                                .addTo(
                                    gasMap
                                );


                            gasMap.fitBounds(
                                routeLine.getBounds(),
                                {
                                    padding:
                                        [
                                            50,
                                            50
                                        ]
                                }
                            );


                            const distanceKm =
                                (
                                    route.distance /
                                    1000
                                );


                            const distanceText =
                                distanceKm >= 1
                                    ? distanceKm.toFixed(1) +
                                      ' km'
                                    : Math.round(
                                        route.distance
                                    ) +
                                      ' m';


                            const durationMinutes =
                                Math.round(
                                    route.duration /
                                    60
                                );


                            let durationText =
                                'less than 1 minute';


                            if (
                                durationMinutes >=
                                60
                            ) {

                                const hours =
                                    Math.floor(
                                        durationMinutes /
                                        60
                                    );


                                const minutes =
                                    durationMinutes %
                                    60;


                                durationText =
                                    hours +
                                    ' hr ' +
                                    minutes +
                                    ' min';

                            } else if (
                                durationMinutes > 0
                            ) {

                                durationText =
                                    durationMinutes +
                                    ' min';

                            }


                            if (routeStatus) {

                                routeStatus.innerHTML =
                                    '<strong>Route to ' +
                                    escapeHtml(
                                        station.name
                                    ) +
                                    '</strong><br>' +
                                    distanceText +
                                    ' • approximately ' +
                                    durationText;

                            }


                            const marker =
                                stationMarkers[
                                    String(
                                        station.id
                                    )
                                ];


                            if (marker) {

                                marker.openPopup();

                            }

                        }
                    )

                    .catch(
                        function (error) {

                            console.error(
                                error
                            );


                            if (routeStatus) {

                                routeStatus.textContent =
                                    'Unable to calculate the route. Please try again or use the Full Details page for directions.';

                            }

                            alert(
                                'Unable to calculate the route right now. Please try again.'
                            );

                        }
                    );

            }


            if (
                currentUserLocation
            ) {

                calculateRoute(
                    currentUserLocation
                );

            } else {

                getCurrentLocation(
                    calculateRoute
                );

            }

        }


        mapElement.addEventListener(
            'click',
            function (event) {

                const routeButton =
                    event.target.closest(
                        '[data-map-route]'
                    );


                if (routeButton) {

                    const station =
                        getStationById(
                            routeButton.dataset.mapRoute
                        );


                    if (station) {

                        routeToStation(
                            station
                        );

                    }

                }

            }
        );


        const stationModal =
            document.getElementById(
                'stationModal'
            );


        const stationCards =
            document.querySelectorAll(
                '[data-station]'
            );


        const stationCloseButtons =
            document.querySelectorAll(
                '[data-station-close]'
            );


        const stationViewMap =
            document.getElementById(
                'stationViewMap'
            );


        const stationGetRoute =
            document.getElementById(
                'stationGetRoute'
            );


        const stationFullDetails =
            document.getElementById(
                'stationFullDetails'
            );


        let selectedStation =
            null;


        let lastStationFocusedElement =
            null;


        function isProductAvailable(
            value
        ) {

            return (
                String(value) === '1' ||
                String(value).toLowerCase() === 'true'
            );

        }


        function formatProductStatus(
            available,
            price
        ) {

            if (!isProductAvailable(available)) {
                return 'Not Available';
            }

            if (
                price === null ||
                price === undefined ||
                price === ''
            ) {
                return 'Available — Price N/A';
            }

            return 'Available — ₱' + price;

        }


        function formatModalPrice(
            price
        ) {

            if (
                price === null ||
                price === undefined ||
                price === ''
            ) {

                return 'N/A';

            }


            return '₱' +
                price;

        }


        function openStationModal(
            station
        ) {

            if (
                !stationModal ||
                !station
            ) {

                return;

            }


            selectedStation =
                station;


            lastStationFocusedElement =
                document.activeElement;


            document.getElementById(
                'stationModalTitle'
            ).textContent =
                station.name ||
                'Gas Station';


            document.getElementById(
                'stationModalBarangay'
            ).textContent =
                station.barangay ||
                'Siargao Island';


            document.getElementById(
                'stationModalAddress'
            ).textContent =
                station.address ||
                'Address not provided';


            document.getElementById(
                'stationModalDescription'
            ).textContent =
                station.description ||
                'No description available.';


            document.getElementById(
                'stationModalHours'
            ).textContent =
                station.operating_hours ||
                'Hours not provided';


            document.getElementById(
                'stationModalContact'
            ).textContent =
                station.contact_number ||
                'Not provided';


            const lpgElement =
                document.getElementById(
                    'stationModalLpg'
                );


            const lpgAvailable =
                String(
                    station.lpg_available
                ) === '1' ||
                String(
                    station.lpg_available
                ).toLowerCase() ===
                    'true';


            if (lpgAvailable) {

                lpgElement.className =
                    'available-status';

                const modalLpgProducts =
                    Array.isArray(station.lpg_products)
                        ? station.lpg_products.filter(function (product) {
                            return String(product.available) === '1' || product.available === true;
                        })
                        : [];

                if (modalLpgProducts.length > 0) {
                    lpgElement.textContent =
                        modalLpgProducts.map(function (product) {
                            const kg = parseFloat(product.kg);
                            const kgText = Number.isFinite(kg)
                                ? String(kg).replace(/\.0+$/, '') + 'kg'
                                : 'LPG';
                            return kgText + ' — ₱' + product.price;
                        }).join(' · ');
                } else if (
                    station.lpg_price !== null &&
                    station.lpg_price !== undefined &&
                    station.lpg_price !== ''
                ) {

                    lpgElement.textContent =
                        'Available — ₱' +
                        station.lpg_price;

                } else {

                    lpgElement.textContent =
                        'Available';

                }

            } else {

                lpgElement.className =
                    'unavailable-status';

                lpgElement.textContent =
                    'Not Available';

            }


            const latitude =
                parseFloat(
                    station.latitude
                );


            const longitude =
                parseFloat(
                    station.longitude
                );


            if (
                Number.isFinite(latitude) &&
                Number.isFinite(longitude)
            ) {

                document.getElementById(
                    'stationModalCoordinates'
                ).textContent =
                    latitude.toFixed(5) +
                    ', ' +
                    longitude.toFixed(5);

            } else {

                document.getElementById(
                    'stationModalCoordinates'
                ).textContent =
                    'Coordinates unavailable';

            }


            const modalDiesel =
                document.getElementById(
                    'stationModalDiesel'
                );

            const modalUnleaded =
                document.getElementById(
                    'stationModalUnleaded'
                );

            const modalPremium =
                document.getElementById(
                    'stationModalPremium'
                );


            modalDiesel.textContent =
                formatProductStatus(
                    station.diesel_available,
                    station.diesel_price
                );

            modalDiesel.className =
                isProductAvailable(
                    station.diesel_available
                )
                    ? 'available-status'
                    : 'unavailable-status';


            modalUnleaded.textContent =
                formatProductStatus(
                    station.regular_available,
                    station.unleaded_price
                );

            modalUnleaded.className =
                isProductAvailable(
                    station.regular_available
                )
                    ? 'available-status'
                    : 'unavailable-status';


            modalPremium.textContent =
                formatProductStatus(
                    station.premium_available,
                    station.premium_price
                );

            modalPremium.className =
                isProductAvailable(
                    station.premium_available
                )
                    ? 'available-status'
                    : 'unavailable-status';


            const imageContainer =
                document.getElementById(
                    'stationModalImage'
                );


            const imageElement =
                document.getElementById(
                    'stationModalImageElement'
                );


            const stationImage =
                station.image_url ||
                station.image ||
                '';

            if (
                String(stationImage).trim() !== ''
            ) {

                imageElement.src =
                    stationImage;


                imageElement.alt =
                    station.name ||
                    'Gas station';


                imageContainer.style.display =
                    'block';

            } else {

                imageElement.src = '';

                imageElement.alt = '';

                imageContainer.style.display =
                    'none';

            }


            stationFullDetails.href =
                'public/station.php?id=' +
                encodeURIComponent(
                    station.id
                );


            stationModal.classList.add(
                'show'
            );


            stationModal.setAttribute(
                'aria-hidden',
                'false'
            );


            document.body.classList.add(
                'modal-open'
            );


            const closeButton =
                stationModal.querySelector(
                    '.station-modal-close'
                );


            if (closeButton) {

                setTimeout(
                    function () {

                        closeButton.focus();

                    },
                    50
                );

            }

        }


        function closeStationModal() {

            if (
                !stationModal ||
                !stationModal.classList.contains(
                    'show'
                )
            ) {

                return;

            }


            stationModal.classList.remove(
                'show'
            );


            stationModal.setAttribute(
                'aria-hidden',
                'true'
            );


            document.body.classList.remove(
                'modal-open'
            );


            if (
                lastStationFocusedElement &&
                typeof lastStationFocusedElement.focus ===
                    'function'
            ) {

                lastStationFocusedElement.focus();

            }


            selectedStation =
                null;

        }


        stationCards.forEach(
            function (card) {

                const stationId =
                    card.dataset.stationId;


                card.addEventListener(
                    'click',
                    function (event) {

                        if (
                            event.target.closest(
                                'a, button'
                            )
                        ) {

                            return;

                        }


                        const station =
                            getStationById(
                                stationId
                            );


                        if (station) {

                            openStationModal(
                                station
                            );

                        }

                    }
                );


                card.addEventListener(
                    'keydown',
                    function (event) {

                        if (
                            event.key !== 'Enter' &&
                            event.key !== ' '
                        ) {

                            return;

                        }


                        event.preventDefault();


                        const station =
                            getStationById(
                                stationId
                            );


                        if (station) {

                            openStationModal(
                                station
                            );

                        }

                    }
                );

            }
        );


        stationCloseButtons.forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    closeStationModal
                );

            }
        );


        stationModal.addEventListener(
            'click',
            function (event) {

                if (
                    event.target.classList.contains(
                        'station-modal-overlay'
                    )
                ) {

                    closeStationModal();

                }

            }
        );


        stationViewMap.addEventListener(
            'click',
            function () {

                if (!selectedStation) {
                    return;
                }


                const station =
                    selectedStation;


                closeStationModal();


                findStationOnMap(
                    station
                );

            }
        );


        stationGetRoute.addEventListener(
            'click',
            function () {

                if (!selectedStation) {
                    return;
                }


                const station =
                    selectedStation;


                closeStationModal();


                document
                    .getElementById(
                        'map-section'
                    )
                    ?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });


                setTimeout(
                    function () {

                        routeToStation(
                            station
                        );

                    },
                    400
                );

            }
        );


        const faqModal =
            document.getElementById(
                'faqModal'
            );


        const faqOpenButtons =
            document.querySelectorAll(
                '[data-faq-open]'
            );


        const faqCloseButtons =
            document.querySelectorAll(
                '[data-faq-close]'
            );


        let lastFaqFocusedElement =
            null;


        function openFaqModal(
            event
        ) {

            if (event) {
                event.preventDefault();
            }


            lastFaqFocusedElement =
                document.activeElement;


            faqModal.classList.add(
                'show'
            );


            faqModal.setAttribute(
                'aria-hidden',
                'false'
            );


            document.body.classList.add(
                'faq-modal-open'
            );


            const closeButton =
                faqModal.querySelector(
                    '.faq-modal-close'
                );


            if (closeButton) {

                setTimeout(
                    function () {

                        closeButton.focus();

                    },
                    50
                );

            }

        }


        function closeFaqModal() {

            if (
                !faqModal.classList.contains(
                    'show'
                )
            ) {

                return;

            }


            faqModal.classList.remove(
                'show'
            );


            faqModal.setAttribute(
                'aria-hidden',
                'true'
            );


            document.body.classList.remove(
                'faq-modal-open'
            );


            if (
                lastFaqFocusedElement &&
                typeof lastFaqFocusedElement.focus ===
                    'function'
            ) {

                lastFaqFocusedElement.focus();

            }

        }


        faqOpenButtons.forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    openFaqModal
                );

            }
        );


        faqCloseButtons.forEach(
            function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        if (
                            this.closest(
                                '#faqModal'
                            )
                        ) {

                            closeFaqModal();

                        }

                    }
                );

            }
        );


        faqModal.addEventListener(
            'click',
            function (event) {

                if (
                    event.target.classList.contains(
                        'faq-modal-overlay'
                    )
                ) {

                    closeFaqModal();

                }

            }
        );


        faqModal
            .querySelectorAll(
                '.faq-item'
            )
            .forEach(
                function (item) {

                    item.addEventListener(
                        'toggle',
                        function () {

                            const icon =
                                item.querySelector(
                                    '.faq-arrow'
                                );


                            if (icon) {

                                icon.textContent =
                                    item.open
                                        ? '−'
                                        : '+';

                            }

                        }
                    );

                }
            );


        const searchInput =
            document.getElementById(
                'stationSearch'
            );


        const searchSuggestions =
            document.getElementById(
                'stationSearchSuggestions'
            );


        const stationCount =
            document.getElementById(
                'stationCount'
            );


        function filterStationCards(search) {

            let visibleCount = 0;


            stationCards.forEach(
                function (card) {

                    const name =
                        (
                            card.dataset.name ||
                            ''
                        ).toLowerCase();


                    const barangay =
                        (
                            card.dataset.barangay ||
                            ''
                        ).toLowerCase();


                    const address =
                        (
                            card.dataset.address ||
                            ''
                        ).toLowerCase();


                    const matches =
                        !search ||
                        name.includes(search) ||
                        barangay.includes(search) ||
                        address.includes(search);


                    card.style.display =
                        matches
                            ? ''
                            : 'none';


                    if (matches) {
                        visibleCount++;
                    }

                }
            );


            if (stationCount) {

                stationCount.textContent =
                    visibleCount +
                    (
                        visibleCount === 1
                            ? ' station'
                            : ' stations'
                    );

            }


            const stationList =
                document.getElementById(
                    'stationList'
                );


            if (stationList) {
                stationList.scrollLeft = 0;
            }

        }


        function getMatchingStationSuggestions(search) {

            return stations.filter(
                function (station) {

                    if (!search) {
                        return true;
                    }


                    const name =
                        String(
                            station.name ||
                            ''
                        ).toLowerCase();


                    const barangay =
                        String(
                            station.barangay ||
                            ''
                        ).toLowerCase();


                    const address =
                        String(
                            station.address ||
                            ''
                        ).toLowerCase();


                    return (
                        name.includes(search) ||
                        barangay.includes(search) ||
                        address.includes(search)
                    );

                }
            );

        }


        function hideStationSuggestions() {

            if (!searchSuggestions) {
                return;
            }


            searchSuggestions.classList.remove(
                'show'
            );


            if (searchInput) {
                searchInput.setAttribute(
                    'aria-expanded',
                    'false'
                );
            }

        }


        function selectStationSuggestion(
            station
        ) {

            if (!station) {
                return;
            }


            if (searchInput) {

                searchInput.value =
                    station.name ||
                    '';


                filterStationCards(
                    searchInput.value
                        .trim()
                        .toLowerCase()
                );

            }


            hideStationSuggestions();


            findStationOnMap(
                station,
                true
            );

        }


        function renderStationSuggestions(
            search = ''
        ) {

            if (!searchSuggestions) {
                return;
            }


            const matchingStations =
                getMatchingStationSuggestions(
                    search
                );


            searchSuggestions.innerHTML = '';


            if (matchingStations.length === 0) {

                const empty =
                    document.createElement(
                        'div'
                    );


                empty.className =
                    'station-search-empty';


                empty.textContent =
                    'No gasoline stations found.';


                searchSuggestions.appendChild(
                    empty
                );

            } else {

                matchingStations.forEach(
                    function (station) {

                        const option =
                            document.createElement(
                                'button'
                            );


                        option.type =
                            'button';


                        option.className =
                            'station-search-option';


                        option.setAttribute(
                            'role',
                            'option'
                        );


                        const icon =
                            document.createElement(
                                'span'
                            );


                        icon.className =
                            'station-search-option-icon';


                        icon.textContent =
                            '⛽';


                        const text =
                            document.createElement(
                                'span'
                            );


                        text.className =
                            'station-search-option-text';


                        const name =
                            document.createElement(
                                'span'
                            );


                        name.className =
                            'station-search-option-name';


                        name.textContent =
                            station.name ||
                            'Gas Station';


                        const location =
                            document.createElement(
                                'span'
                            );


                        location.className =
                            'station-search-option-location';


                        const locationParts = [
                            station.barangay || '',
                            station.address || ''
                        ].filter(Boolean);


                        location.textContent =
                            locationParts.join(' • ') ||
                            'Siargao Island';


                        text.appendChild(name);
                        text.appendChild(location);

                        option.appendChild(icon);
                        option.appendChild(text);


                        option.addEventListener(
                            'click',
                            function () {

                                selectStationSuggestion(
                                    station
                                );

                            }
                        );


                        searchSuggestions.appendChild(
                            option
                        );

                    }
                );

            }


            searchSuggestions.classList.add(
                'show'
            );


            if (searchInput) {
                searchInput.setAttribute(
                    'aria-expanded',
                    'true'
                );
            }

        }


        if (searchInput) {

            searchInput.addEventListener(
                'focus',
                function () {

                    const search =
                        this.value
                            .trim()
                            .toLowerCase();


                    renderStationSuggestions(
                        search
                    );

                }
            );


            searchInput.addEventListener(
                'click',
                function () {

                    const search =
                        this.value
                            .trim()
                            .toLowerCase();


                    renderStationSuggestions(
                        search
                    );

                }
            );


            searchInput.addEventListener(
                'input',
                function () {

                    const search =
                        this.value
                            .trim()
                            .toLowerCase();


                    filterStationCards(
                        search
                    );


                    renderStationSuggestions(
                        search
                    );

                }
            );


            searchInput.addEventListener(
                'keydown',
                function (event) {

                    if (event.key === 'Escape') {

                        hideStationSuggestions();
                        return;

                    }


                    if (
                        event.key === 'Enter' &&
                        searchSuggestions?.classList.contains(
                            'show'
                        )
                    ) {

                        const search =
                            this.value
                                .trim()
                                .toLowerCase();


                        const firstMatch =
                            getMatchingStationSuggestions(
                                search
                            )[0];


                        if (firstMatch) {

                            event.preventDefault();

                            selectStationSuggestion(
                                firstMatch
                            );

                        }

                    }

                }
            );

        }


        document.addEventListener(
            'click',
            function (event) {

                if (
                    searchSuggestions &&
                    searchInput &&
                    !searchSuggestions.contains(
                        event.target
                    ) &&
                    event.target !== searchInput
                ) {

                    hideStationSuggestions();

                }

            }
        );


        const stationList =
            document.getElementById(
                'stationList'
            );


        const stationSlidePrev =
            document.getElementById(
                'stationSlidePrev'
            );


        const stationSlideNext =
            document.getElementById(
                'stationSlideNext'
            );


        function getStationCarouselMaxScroll() {

            if (!stationList) {
                return 0;
            }

            return Math.max(
                0,
                stationList.scrollWidth -
                stationList.clientWidth
            );

        }


        function getStationSlideDistance() {

            if (!stationList) {
                return 0;
            }


            const firstVisibleCard =
                stationList.querySelector(
                    '.station-card[data-station]:not([style*="display: none"])'
                ) ||
                stationList.querySelector(
                    '.station-card[data-station]'
                );


            const gap = 14;


            return firstVisibleCard
                ? firstVisibleCard.getBoundingClientRect().width + gap
                : stationList.clientWidth;

        }


        function slideStationCards(
            direction
        ) {

            if (!stationList) {
                return;
            }


            const maxScroll =
                getStationCarouselMaxScroll();


            if (maxScroll <= 1) {
                return;
            }


            const edgeThreshold = 6;


            /*
             * Infinite/circular carousel:
             * Previous from the first card goes to the end.
             * Next from the last card goes back to the beginning.
             */
            if (
                direction < 0 &&
                stationList.scrollLeft <= edgeThreshold
            ) {

                stationList.scrollTo({
                    left: maxScroll,
                    behavior: 'smooth'
                });

                return;
            }


            if (
                direction > 0 &&
                stationList.scrollLeft >=
                    maxScroll - edgeThreshold
            ) {

                stationList.scrollTo({
                    left: 0,
                    behavior: 'smooth'
                });

                return;
            }


            const distance =
                getStationSlideDistance();


            stationList.scrollBy({
                left:
                    direction *
                    distance,
                behavior: 'smooth'
            });

        }


        stationSlidePrev
            ?.addEventListener(
                'click',
                function () {
                    slideStationCards(-1);
                }
            );


        stationSlideNext
            ?.addEventListener(
                'click',
                function () {
                    slideStationCards(1);
                }
            );


        /*
         * Touch wrap for phones/tablets.
         * Native swiping is kept for normal movement. If the user tries
         * to swipe beyond either end, the carousel continues from the
         * opposite side instead of feeling stuck.
         */
        let stationTouchStartX = null;
        let stationTouchStartedAtBeginning = false;
        let stationTouchStartedAtEnd = false;


        stationList
            ?.addEventListener(
                'touchstart',
                function (event) {

                    if (
                        !event.touches ||
                        event.touches.length !== 1
                    ) {
                        return;
                    }


                    stationTouchStartX =
                        event.touches[0].clientX;


                    const maxScroll =
                        getStationCarouselMaxScroll();

                    const edgeThreshold = 8;


                    stationTouchStartedAtBeginning =
                        stationList.scrollLeft <=
                        edgeThreshold;


                    stationTouchStartedAtEnd =
                        stationList.scrollLeft >=
                        maxScroll - edgeThreshold;

                },
                {
                    passive: true
                }
            );


        stationList
            ?.addEventListener(
                'touchend',
                function (event) {

                    if (
                        stationTouchStartX === null ||
                        !event.changedTouches ||
                        event.changedTouches.length !== 1
                    ) {

                        stationTouchStartX = null;
                        return;
                    }


                    const touchEndX =
                        event.changedTouches[0].clientX;


                    const swipeDistance =
                        touchEndX -
                        stationTouchStartX;


                    const minimumSwipe = 45;


                    if (
                        stationTouchStartedAtBeginning &&
                        swipeDistance > minimumSwipe
                    ) {

                        stationList.scrollTo({
                            left:
                                getStationCarouselMaxScroll(),
                            behavior: 'smooth'
                        });

                    } else if (
                        stationTouchStartedAtEnd &&
                        swipeDistance < -minimumSwipe
                    ) {

                        stationList.scrollTo({
                            left: 0,
                            behavior: 'smooth'
                        });

                    }


                    stationTouchStartX = null;
                    stationTouchStartedAtBeginning =
                        false;
                    stationTouchStartedAtEnd =
                        false;

                },
                {
                    passive: true
                }
            );


        const navItems =
            document.querySelectorAll(
                '.mobile-nav-item'
            );


        const navSections = {

            home:
                document.getElementById(
                    'home'
                ),

            stations:
                document.getElementById(
                    'stations'
                ),

            'map-section':
                document.getElementById(
                    'map-section'
                )

        };


        function setActive(
            sectionName
        ) {

            navItems.forEach(
                function (item) {

                    item.classList.remove(
                        'active'
                    );

                }
            );


            const activeItem =
                document.querySelector(
                    '.mobile-nav-item[data-section="' +
                    sectionName +
                    '"]'
                );


            if (activeItem) {

                activeItem.classList.add(
                    'active'
                );

            }

        }


        navItems.forEach(
            function (item) {

                item.addEventListener(
                    'click',
                    function () {

                        if (
                            this.hasAttribute(
                                'data-faq-open'
                            )
                        ) {

                            return;

                        }


                        const section =
                            this.dataset.section;


                        if (section) {

                            setActive(
                                section
                            );

                        }

                    }
                );

            }
        );


        const observer =
            new IntersectionObserver(
                function (entries) {

                    const visibleSections =
                        entries
                            .filter(
                                function (entry) {

                                    return entry.isIntersecting;

                                }
                            )
                            .sort(
                                function (a, b) {

                                    return (
                                        b.intersectionRatio -
                                        a.intersectionRatio
                                    );

                                }
                            );


                    if (
                        visibleSections.length > 0
                    ) {

                        const id =
                            visibleSections[0]
                                .target
                                .id;


                        if (
                            id === 'home' ||
                            id === 'stations' ||
                            id === 'map-section'
                        ) {

                            setActive(
                                id
                            );

                        }

                    }

                },
                {
                    threshold:
                        0.25
                }
            );


        Object.values(
            navSections
        ).forEach(
            function (section) {

                if (section) {

                    observer.observe(
                        section
                    );

                }

            }
        );


        setActive('home');



        /* ============================================================
           GASGO AI FLOATING CHAT
           Free-form OpenAI chat grounded in current GasGo database data.
        ============================================================ */
        const aiRecommendButton = document.getElementById('aiRecommendButton');
        const gasgoAiLauncher = document.getElementById('gasgoAiLauncher');
        const gasgoAiChat = document.getElementById('gasgoAiChat');
        const gasgoAiClose = document.getElementById('gasgoAiClose');
        const gasgoAiMessages = document.getElementById('gasgoAiMessages');
        const gasgoAiForm = document.getElementById('gasgoAiForm');
        const gasgoAiInput = document.getElementById('gasgoAiInput');
        const gasgoAiSend = document.getElementById('gasgoAiSend');
        const gasgoAiHistory = [];
        let gasgoAiBusy = false;

        function setGasGoAiOpen(open) {
            if (!gasgoAiChat || !gasgoAiLauncher) return;
            gasgoAiChat.classList.toggle('is-open', open);
            gasgoAiChat.setAttribute('aria-hidden', open ? 'false' : 'true');
            gasgoAiLauncher.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open && gasgoAiInput) {
                window.setTimeout(function () { gasgoAiInput.focus(); }, 80);
            }
        }

        function addGasGoAiMessage(role, text, extraClass) {
            if (!gasgoAiMessages) return null;
            const row = document.createElement('div');
            row.className = 'gasgo-ai-row ' + role + (extraClass ? ' ' + extraClass : '');
            const bubble = document.createElement('div');
            bubble.className = 'gasgo-ai-bubble';
            bubble.textContent = text;
            row.appendChild(bubble);
            gasgoAiMessages.appendChild(row);
            gasgoAiMessages.scrollTop = gasgoAiMessages.scrollHeight;
            return row;
        }

        function addGasGoAiTyping() {
            if (!gasgoAiMessages) return null;
            const row = document.createElement('div');
            row.className = 'gasgo-ai-row assistant';
            row.innerHTML = '<div class="gasgo-ai-bubble"><span class="gasgo-ai-typing" aria-label="GasGo AI is typing"><span></span><span></span><span></span></span></div>';
            gasgoAiMessages.appendChild(row);
            gasgoAiMessages.scrollTop = gasgoAiMessages.scrollHeight;
            return row;
        }

        function resizeGasGoAiInput() {
            if (!gasgoAiInput) return;
            gasgoAiInput.style.height = 'auto';
            gasgoAiInput.style.height = Math.min(gasgoAiInput.scrollHeight, 110) + 'px';
        }

        function gasGoAiLocationPayload() {
            if (currentUserLocation &&
                Number.isFinite(Number(currentUserLocation.latitude)) &&
                Number.isFinite(Number(currentUserLocation.longitude))) {
                return {
                    latitude: Number(currentUserLocation.latitude),
                    longitude: Number(currentUserLocation.longitude)
                };
            }
            return {};
        }

        async function sendGasGoAiMessage(message) {
            if (gasgoAiBusy || !message) return;
            gasgoAiBusy = true;
            if (gasgoAiSend) gasgoAiSend.disabled = true;

            addGasGoAiMessage('user', message);
            const requestHistory = gasgoAiHistory.slice(-8);
            gasgoAiHistory.push({ role: 'user', content: message });
            const typingRow = addGasGoAiTyping();

            try {
                const response = await fetch('public/api/ai_chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(Object.assign({
                        message: message,
                        history: requestHistory
                    }, gasGoAiLocationPayload()))
                });

                let data = null;
                try {
                    data = await response.json();
                } catch (error) {
                    data = null;
                }

                if (typingRow) typingRow.remove();

                if (!response.ok || !data || data.ok !== true) {
                    const errorMessage = data && data.error
                        ? data.error
                        : 'GasGo AI is temporarily unavailable. Please try again.';
                    addGasGoAiMessage('assistant', errorMessage, 'error');
                    return;
                }

                const answer = String(data.answer || '').trim();
                if (!answer) {
                    addGasGoAiMessage('assistant', 'I could not generate an answer. Please try asking again.', 'error');
                    return;
                }

                gasgoAiHistory.push({ role: 'assistant', content: answer });
                if (gasgoAiHistory.length > 16) {
                    gasgoAiHistory.splice(0, gasgoAiHistory.length - 16);
                }
                addGasGoAiMessage('assistant', answer);
            } catch (error) {
                if (typingRow) typingRow.remove();
                addGasGoAiMessage('assistant', 'I could not connect to GasGo AI. Please check your internet connection and try again.', 'error');
            } finally {
                gasgoAiBusy = false;
                if (gasgoAiSend) gasgoAiSend.disabled = false;
                if (gasgoAiInput) gasgoAiInput.focus();
            }
        }

        if (gasgoAiLauncher) {
            gasgoAiLauncher.addEventListener('click', function () {
                setGasGoAiOpen(!(gasgoAiChat && gasgoAiChat.classList.contains('is-open')));
            });
        }

        if (aiRecommendButton) {
            aiRecommendButton.addEventListener('click', function (event) {
                event.preventDefault();
                setGasGoAiOpen(true);
            });
        }

        if (gasgoAiClose) {
            gasgoAiClose.addEventListener('click', function () { setGasGoAiOpen(false); });
        }

        if (gasgoAiInput) {
            gasgoAiInput.addEventListener('input', resizeGasGoAiInput);
            gasgoAiInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    if (gasgoAiForm) gasgoAiForm.requestSubmit();
                }
            });
        }

        if (gasgoAiForm) {
            gasgoAiForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!gasgoAiInput || gasgoAiBusy) return;
                const message = gasgoAiInput.value.trim();
                if (!message) return;
                gasgoAiInput.value = '';
                resizeGasGoAiInput();
                sendGasGoAiMessage(message);
            });
        }

        document.addEventListener(
            'keydown',
            function (event) {

                if (
                    event.key !== 'Escape'
                ) {

                    return;

                }


                if (
                    stationModal &&
                    stationModal.classList.contains(
                        'show'
                    )
                ) {

                    closeStationModal();

                    return;

                }


                if (
                    faqModal &&
                    faqModal.classList.contains(
                        'show'
                    )
                ) {

                    closeFaqModal();

                }

            }
        );


        if (
            validStations.length > 0
        ) {

            showAllStations();

        }

    }
);

</script>




<script>
(function () {
    'use strict';

    let deferredInstallPrompt = null;
    let serviceWorkerReady = false;

    const installButtons = Array.from(
        document.querySelectorAll('[data-install-app]')
    );

    function swalFire(options) {
        if (window.Swal && typeof window.Swal.fire === 'function') {
            return window.Swal.fire(options);
        }

        // Last-resort fallback only if the SweetAlert CDN itself cannot load.
        console.warn('SweetAlert2 is unavailable.', options);
        return Promise.resolve({ isConfirmed: false });
    }

    /*
     * Make existing GasGo alert() calls use SweetAlert2 too.
     * This converts location/errors and other homepage alerts without
     * changing every existing function one-by-one.
     */
    window.alert = function (message) {
        swalFire({
            icon: 'info',
            title: 'GasGo',
            text: String(message ?? ''),
            confirmButtonText: 'OK',
            confirmButtonColor: '#dc2626'
        });
    };

    function isStandalone() {
        return (
            window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true
        );
    }

    function isIosDevice() {
        return /iphone|ipad|ipod/i.test(
            window.navigator.userAgent
        );
    }

    function isChromiumBrowser() {
        const ua = window.navigator.userAgent.toLowerCase();

        return (
            ua.includes('chrome') ||
            ua.includes('chromium') ||
            ua.includes('edg/') ||
            ua.includes('crios')
        );
    }

    function getInstallLabel(button) {
        return button.querySelector('span');
    }

    function setInstallButtonsHidden(hidden) {
        installButtons.forEach(function (button) {
            button.hidden = hidden;
        });
    }

    function setInstallButtonsState(state) {
        installButtons.forEach(function (button) {
            const label = getInstallLabel(button);

            button.classList.remove('is-ready');

            if (state === 'ready') {
                button.disabled = false;
                button.classList.add('is-ready');

                if (label) {
                    label.textContent =
                        false
                            ? 'Install'
                            : 'Install App';
                }

                return;
            }

            if (state === 'ios') {
                button.disabled = false;
                button.classList.add('is-ready');

                if (label) {
                    label.textContent =
                        false
                            ? 'Install'
                            : 'Install App';
                }

                return;
            }

            button.disabled = true;

            if (label) {
                label.textContent =
                    false
                        ? 'Preparing'
                        : 'Preparing App...';
            }
        });
    }

    async function showInstallNotReady() {
        const secure = window.isSecureContext;
        const hasServiceWorker =
            'serviceWorker' in window.navigator;

        let message = '';

        if (!secure) {
            message =
                'GasGo must be opened using HTTPS before the browser can install it as an app.';
        } else if (!hasServiceWorker) {
            message =
                'This browser does not support service workers, so it cannot install this PWA.';
        } else if (!isChromiumBrowser()) {
            message =
                'This browser has not exposed the automatic PWA installer. Try Chrome or Edge, or use your browser’s Add to Home Screen option.';
        } else {
            message =
                'GasGo is preparing the browser installer. Refresh the page once, then wait a few seconds for the Install App button to become ready.';
        }

        const result = await swalFire({
            icon: secure ? 'info' : 'warning',
            title: 'GasGo installation is not ready yet',
            text: message,
            showCancelButton: true,
            confirmButtonText: 'Refresh & Check',
            cancelButtonText: 'Close',
            confirmButtonColor: '#dc2626'
        });

        if (result && result.isConfirmed) {
            window.location.reload();
        }
    }

    async function installGasGo() {
        if (isStandalone()) {
            setInstallButtonsHidden(true);

            await swalFire({
                icon: 'success',
                title: 'GasGo is already installed',
                text: 'You can open GasGo from your home screen or app list.',
                confirmButtonColor: '#dc2626'
            });

            return;
        }

        if (isIosDevice()) {
            await swalFire({
                icon: 'info',
                title: 'Install GasGo on iPhone/iPad',
                html:
                    'Open GasGo in <strong>Safari</strong>, tap the <strong>Share</strong> button, then choose <strong>Add to Home Screen</strong>.',
                confirmButtonText: 'Got it',
                confirmButtonColor: '#dc2626'
            });

            return;
        }

        if (!deferredInstallPrompt) {
            await showInstallNotReady();
            return;
        }

        const confirmation = await swalFire({
            icon: 'question',
            title: 'Install GasGo?',
            text: 'GasGo will be added to your device and can open like an app.',
            showCancelButton: true,
            confirmButtonText: 'Install',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626'
        });

        if (!confirmation || !confirmation.isConfirmed) {
            return;
        }

        const promptEvent = deferredInstallPrompt;
        deferredInstallPrompt = null;

        /*
         * This is the real browser/native PWA installation prompt.
         * Browsers do not allow websites to silently install an app.
         */
        promptEvent.prompt();

        try {
            const choice = await promptEvent.userChoice;

            if (choice.outcome === 'accepted') {
                setInstallButtonsHidden(true);

                await swalFire({
                    icon: 'success',
                    title: 'GasGo installed',
                    text: 'GasGo was added to your device.',
                    confirmButtonColor: '#dc2626'
                });
            } else {
                setInstallButtonsState('ready');
            }
        } catch (error) {
            console.error('GasGo installation prompt failed:', error);
            setInstallButtonsState('ready');

            await swalFire({
                icon: 'error',
                title: 'Installation could not start',
                text: 'Please refresh GasGo and try the Install App button again.',
                confirmButtonColor: '#dc2626'
            });
        }
    }

    /*
     * Redirect accidental HTTP visits to HTTPS because PWA installation
     * and service workers require a secure context on real domains.
     */
    if (
        window.location.protocol === 'http:' &&
        window.location.hostname !== 'localhost' &&
        window.location.hostname !== '127.0.0.1'
    ) {
        window.location.replace(
            'https://' +
            window.location.host +
            window.location.pathname +
            window.location.search +
            window.location.hash
        );
        return;
    }

    if (isStandalone()) {
        setInstallButtonsHidden(true);
    } else if (isIosDevice()) {
        setInstallButtonsHidden(false);
        setInstallButtonsState('ios');
    } else {
        setInstallButtonsHidden(false);
        setInstallButtonsState('preparing');
    }

    window.addEventListener(
        'beforeinstallprompt',
        function (event) {
            event.preventDefault();
            deferredInstallPrompt = event;

            setInstallButtonsHidden(false);
            setInstallButtonsState('ready');
        }
    );

    installButtons.forEach(function (button) {
        button.addEventListener('click', installGasGo);
    });

    window.addEventListener('appinstalled', function () {
        deferredInstallPrompt = null;
        setInstallButtonsHidden(true);

        swalFire({
            icon: 'success',
            title: 'GasGo installed',
            text: 'You can now open GasGo from your home screen or app list.',
            confirmButtonColor: '#dc2626'
        });
    });

    async function registerGasGoServiceWorker() {
        if (!('serviceWorker' in window.navigator)) {
            setInstallButtonsState('ready');
            return;
        }

        try {
            const registration =
                await window.navigator.serviceWorker.register(
                    'sw.js',
                    { scope: '/' }
                );

            await window.navigator.serviceWorker.ready;
            serviceWorkerReady = true;

            console.log(
                'GasGo service worker ready:',
                registration.scope
            );

            /*
             * On a first-ever service worker install some browsers need
             * one controlled reload before they evaluate PWA installability.
             * Reload at most once per tab/session.
             */
            if (
                !window.navigator.serviceWorker.controller &&
                !window.sessionStorage.getItem(
                    'gasgo-pwa-controlled-reload'
                )
            ) {
                window.sessionStorage.setItem(
                    'gasgo-pwa-controlled-reload',
                    '1'
                );

                window.setTimeout(function () {
                    window.location.reload();
                }, 600);

                return;
            }

            /*
             * If Chrome still has not fired beforeinstallprompt, keep the
             * button visible but allow SweetAlert diagnostics on click.
             */
            window.setTimeout(function () {
                if (
                    !deferredInstallPrompt &&
                    !isStandalone() &&
                    !isIosDevice()
                ) {
                    setInstallButtonsState('ready');
                }
            }, 3500);

        } catch (error) {
            console.error(
                'GasGo service worker registration failed:',
                error
            );

            setInstallButtonsState('ready');

            await swalFire({
                icon: 'error',
                title: 'PWA setup error',
                text:
                    'GasGo could not register its service worker. Make sure sw.js is uploaded directly inside htdocs.',
                confirmButtonColor: '#dc2626'
            });
        }
    }

    registerGasGoServiceWorker();
})();
</script>


</body>

</html>