<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db_connect.php';


$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(404);
    die('Station not found.');
}


$stmt = $pdo->prepare("
    SELECT *
    FROM gas_stations
    WHERE id = ?
      AND status = 'Approved'
    LIMIT 1
");

$stmt->execute([$id]);

$s = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$s) {
    http_response_code(404);
    die('Station not found.');
}




$stationId = (int)$s['id'];

$name = (string)($s['name'] ?? 'Gas Station');

$barangay = (string)($s['barangay'] ?? '');

$address = (string)($s['address'] ?? '');

$description = (string)($s['description'] ?? '');

$operatingHours = (string)($s['operating_hours'] ?? 'Not provided');

$workingDays = trim((string)($s['working_days'] ?? ''));
if ($workingDays === '') {
    $workingDays = 'Not provided';
}

$contactNumber = (string)($s['contact_number'] ?? 'Not provided');


$image = trim((string)($s['image'] ?? ''));


function stationImageUrl(string $image): string
{
    $image = trim($image);

    if ($image === '') {
        return '';
    }

    // Already a browser URL or data URI.
    if (
        preg_match('#^(https?:)?//#i', $image) ||
        str_starts_with($image, 'data:image/')
    ) {
        return $image;
    }

    $normalized = str_replace('\\', '/', $image);
    $pathOnly = parse_url($normalized, PHP_URL_PATH) ?: $normalized;
    $basename = basename($pathOnly);

    $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

    $candidates = [
        ['file' => __DIR__ . '/' . ltrim($normalized, '/'), 'url' => $normalized],
        ['file' => __DIR__ . '/' . ltrim($pathOnly, '/'), 'url' => $pathOnly],
        ['file' => __DIR__ . '/uploads/stations/' . $basename, 'url' => 'uploads/stations/' . rawurlencode($basename)],
        ['file' => __DIR__ . '/../uploads/stations/' . $basename, 'url' => '../uploads/stations/' . rawurlencode($basename)],
        ['file' => __DIR__ . '/uploads/' . $basename, 'url' => 'uploads/' . rawurlencode($basename)],
        ['file' => __DIR__ . '/../uploads/' . $basename, 'url' => '../uploads/' . rawurlencode($basename)],
        ['file' => __DIR__ . '/assets/uploads/stations/' . $basename, 'url' => 'assets/uploads/stations/' . rawurlencode($basename)],
        ['file' => __DIR__ . '/../assets/uploads/stations/' . $basename, 'url' => '../assets/uploads/stations/' . rawurlencode($basename)],
    ];

    // If DB contains a document-root filesystem path, convert it to a URL path.
    if ($documentRoot !== '' && str_starts_with($normalized, $documentRoot . '/')) {
        $webPath = substr($normalized, strlen($documentRoot));
        if ($webPath !== '') {
            $candidates[] = [
                'file' => $normalized,
                'url' => $webPath
            ];
        }
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate['file'])) {
            return $candidate['url'];
        }
    }

    return $normalized;
}

$imageUrl = stationImageUrl($image);


$latitude = null;
$longitude = null;

if (
    isset($s['latitude']) &&
    $s['latitude'] !== '' &&
    is_numeric($s['latitude'])
) {
    $latitude = (float)$s['latitude'];
}

if (
    isset($s['longitude']) &&
    $s['longitude'] !== '' &&
    is_numeric($s['longitude'])
) {
    $longitude = (float)$s['longitude'];
}

$hasCoordinates =
    $latitude !== null &&
    $longitude !== null &&
    is_finite($latitude) &&
    is_finite($longitude) &&
    $latitude != 0 &&
    $longitude != 0;


$dieselPrice   = $s['diesel_price'] ?? null;
$unleadedPrice = $s['unleaded_price'] ?? null;
$premiumPrice  = $s['premium_price'] ?? null;

$lpgAvailable = !empty($s['lpg_available']);
$lpgPrice     = $s['lpg_price'] ?? null;

/*
 * Load the actual LPG products assigned to this station.
 * This is the source used by the manager LPG page, so the public
 * station page will stay in sync even when gas_stations.lpg_price
 * has not been updated yet.
 */
$lpgProducts = [];

try {
    $lpgStmt = $pdo->prepare("
        SELECT kg, price, available
        FROM station_lpg_products
        WHERE station_id = ?
          AND available = 1
        ORDER BY kg ASC
    ");

    $lpgStmt->execute([$stationId]);
    $lpgProducts = $lpgStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($lpgProducts)) {
        $lpgAvailable = true;

        if ($lpgPrice === null || $lpgPrice === '') {
            $lpgPrice = $lpgProducts[0]['price'] ?? null;
        }
    }
} catch (PDOException $e) {
    // Keep backwards compatibility with the existing summary columns.
    $lpgProducts = [];
}


$googleDirectionsUrl = '';

if ($hasCoordinates) {

    $googleDirectionsUrl =
        'https://www.google.com/maps/dir/?api=1' .
        '&destination=' .
        rawurlencode($latitude . ',' . $longitude) .
        '&travelmode=driving';
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

    <title>
        <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> — GasGo
    </title>

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

    <?php if ($hasCoordinates): ?>


        <link
            rel="stylesheet"
            href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        >

    <?php endif; ?>


    <style>


        .station-page {
            min-height: 100vh;
            background: #f5f6f8;
            padding-bottom: 60px;
        }

        .station-container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 30px 20px;
        }


        .station-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;

            margin-bottom: 20px;

            color: #374151;
            text-decoration: none;

            font-weight: 600;
        }

        .station-back:hover {
            color: #dc2626;
        }


        .station-main-card {
            overflow: hidden;

            background: #ffffff;

            border-radius: 16px;

            box-shadow:
                0 4px 18px rgba(0, 0, 0, 0.07);
        }


        .station-image-wrapper {
            width: 100%;
            background: #e5e7eb;
        }

        .station-image-wrapper img {
            display: block;

            width: 100%;
            height: 390px;

            object-fit: cover;
            object-position: center;
        }

        .station-image-placeholder {
            width: 100%;
            height: 300px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: #f3f4f6;

            color: #9ca3af;

            font-size: 70px;
        }


        .station-content {
            padding: 30px;
        }

        .station-tag {
            display: inline-block;

            padding: 6px 12px;

            border-radius: 999px;

            background: #fee2e2;

            color: #b91c1c;

            font-size: 13px;
            font-weight: 700;

            margin-bottom: 12px;
        }

        .station-title {
            margin: 0 0 10px;

            font-size: 34px;
            line-height: 1.2;

            color: #111827;
        }

        .station-address {
            margin: 0 0 20px;

            color: #4b5563;

            font-size: 16px;
            line-height: 1.6;
        }

        .station-description {
            margin-bottom: 25px;

            color: #4b5563;

            line-height: 1.7;
        }


        .station-info-grid {
            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 15px;

            margin-bottom: 25px;
        }

        .station-info-box {
            padding: 18px;

            background: #fafafa;

            border: 1px solid #e5e7eb;

            border-radius: 12px;
        }

        .station-info-label {
            display: block;

            margin-bottom: 6px;

            color: #6b7280;

            font-size: 13px;
            font-weight: 600;
        }

        .station-info-value {
            color: #111827;

            font-weight: 600;
        }


        .station-section-title {
            margin: 28px 0 14px;

            color: #111827;

            font-size: 21px;
        }


        .fuel-grid {
            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 14px;
        }

        .fuel-card {
            padding: 20px;

            text-align: center;

            background: #ffffff;

            border: 1px solid #e5e7eb;

            border-radius: 12px;
        }

        .fuel-name {
            display: block;

            margin-bottom: 8px;

            color: #6b7280;

            font-size: 14px;
        }

        .fuel-price {
            color: #111827;

            font-size: 22px;
            font-weight: 800;
        }


        .lpg-box {
            padding: 18px;

            background: #ffffff;

            border: 1px solid #e5e7eb;

            border-radius: 12px;
        }

        .lpg-available {
            color: #15803d;

            font-weight: 700;
        }

        .lpg-unavailable {
            color: #dc2626;

            font-weight: 700;
        }


        .station-actions {
            display: flex;

            flex-wrap: wrap;

            gap: 12px;

            margin-top: 30px;
        }

        .station-btn {
            display: inline-flex;

            align-items: center;
            justify-content: center;

            gap: 8px;

            padding: 12px 18px;

            border: none;

            border-radius: 10px;

            font-size: 15px;
            font-weight: 700;

            text-decoration: none;

            cursor: pointer;

            transition:
                background 0.2s ease,
                transform 0.2s ease;
        }

        .station-btn:hover {
            transform: translateY(-1px);
        }

        .station-btn-primary {
            background: #dc2626;

            color: #ffffff;
        }

        .station-btn-primary:hover {
            background: #b91c1c;
        }

        .station-btn-secondary {
            background: #2563eb;

            color: #ffffff;
        }

        .station-btn-secondary:hover {
            background: #1d4ed8;
        }

        .station-btn:disabled {
            opacity: 0.6;

            cursor: not-allowed;

            transform: none;
        }


        .station-map-section {
            margin-top: 30px;

            padding: 20px;

            background: #ffffff;

            border-radius: 16px;

            box-shadow:
                0 4px 18px rgba(0, 0, 0, 0.06);
        }

        .station-map-section h2 {
            margin-top: 0;
        }

        #stationMap {
            width: 100%;

            height: 450px;

            border-radius: 14px;

            overflow: hidden;

            border: 1px solid #e5e7eb;
        }


        .station-popup {
            width: 230px;
        }

        .station-popup-image {
            width: 100%;

            height: 125px;

            object-fit: cover;

            border-radius: 8px;

            margin-bottom: 10px;
        }

        .station-popup-placeholder {
            width: 100%;

            height: 100px;

            display: flex;

            align-items: center;
            justify-content: center;

            background: #f3f4f6;

            border-radius: 8px;

            font-size: 40px;

            margin-bottom: 10px;
        }

        .station-popup h3 {
            margin: 0 0 6px;

            color: #111827;

            font-size: 17px;
        }

        .station-popup p {
            margin: 4px 0;

            color: #4b5563;

            font-size: 13px;

            line-height: 1.4;
        }

        .popup-directions {
            display: block;

            margin-top: 12px;

            padding: 9px 12px;

            background: #dc2626;

            color: #ffffff;

            text-align: center;

            text-decoration: none;

            border-radius: 8px;

            font-size: 13px;

            font-weight: 700;
        }

        .popup-directions:hover {
            background: #b91c1c;
        }


        .gas-station-marker {
            width: 40px;

            height: 40px;

            display: flex;

            align-items: center;
            justify-content: center;

            background: #dc2626;

            border: 3px solid #ffffff;

            border-radius: 50% 50% 50% 0;

            box-shadow:
                0 3px 10px rgba(0, 0, 0, 0.3);

            transform: rotate(-45deg);
        }

        .gas-station-marker span {
            display: block;

            transform: rotate(45deg);

            font-size: 18px;
        }


        .user-location-marker {
            width: 18px;

            height: 18px;

            background: #2563eb;

            border: 3px solid #ffffff;

            border-radius: 50%;

            box-shadow:
                0 0 0 5px rgba(37, 99, 235, 0.20);
        }



        /* ============================================================
           GASGO CURRENT UI ALIGNMENT
           Matches the latest public homepage styling.
        ============================================================ */

        body {
            background: #f8fafc;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.96);
            border-bottom: 1px solid #e5e7eb;
            box-shadow: 0 2px 12px rgba(15, 23, 42, 0.05);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .station-page {
            background:
                linear-gradient(
                    180deg,
                    #fff7f7 0,
                    #f8fafc 230px,
                    #f8fafc 100%
                );
        }

        .station-container {
            max-width: 1120px;
            padding-top: 34px;
        }

        .station-back {
            min-height: 42px;
            padding: 10px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #ffffff;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.05);
            transition:
                color 0.2s ease,
                border-color 0.2s ease,
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .station-back:hover {
            border-color: #fecaca;
            color: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
        }

        .station-main-card,
        .station-map-section {
            border: 1px solid #e5e7eb;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.07);
        }

        .station-main-card {
            border-radius: 18px;
        }

        .station-image-wrapper {
            min-height: 280px;
            background: #f3f4f6;
        }

        .station-image-wrapper img {
            height: 390px;
        }

        .station-content {
            padding: 30px;
        }

        .station-tag {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .station-title {
            color: #111827;
            font-weight: 800;
        }

        .station-info-box,
        .fuel-card,
        .lpg-box {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.035);
        }

        .station-info-box {
            min-width: 0;
        }

        .station-info-label {
            color: #6b7280;
            font-weight: 700;
        }

        .station-info-value {
            display: block;
            color: #111827;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .fuel-price {
            color: #dc2626;
        }

        .station-btn {
            min-height: 46px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
        }

        .station-btn-primary {
            background: #dc2626;
            color: #ffffff;
        }

        .station-btn-primary:hover {
            background: #b91c1c;
        }

        .station-btn-secondary {
            background: #2563eb;
            color: #ffffff;
        }

        .station-btn-secondary:hover {
            background: #1d4ed8;
        }

        .station-map-section {
            padding: 22px;
            border-radius: 18px;
        }

        #stationMap {
            border-radius: 16px;
            box-shadow: inset 0 0 0 1px rgba(229, 231, 235, 0.6);
        }

        .leaflet-popup-content-wrapper {
            border-radius: 14px;
        }

        .popup-directions {
            background: #dc2626;
        }

        .popup-directions:hover {
            background: #b91c1c;
        }


        @media (max-width: 768px) {

            .station-container {
                padding: 18px 14px 34px;
            }

            .station-back {
                margin-bottom: 14px;
                padding: 9px 12px;
            }

            .station-main-card {
                border-radius: 16px;
            }

            .station-image-wrapper {
                min-height: 220px;
            }

            .station-image-wrapper img {
                height: 250px;
            }

            .station-image-placeholder {
                height: 230px;
            }

            .station-content {
                padding: 20px 17px 22px;
            }

            .station-title {
                font-size: 27px;
            }

            .station-info-grid {
                grid-template-columns: 1fr;
            }

            .fuel-grid {
                grid-template-columns: 1fr;
            }

            .station-actions {
                flex-direction: column;
            }

            .station-btn {
                width: 100%;
            }

            #stationMap {
                height: 350px;
            }

        }


        @media (max-width: 420px) {

            .station-title {
                font-size: 24px;
            }

            .station-address {
                font-size: 14px;
            }

            .station-info-box,
            .fuel-card,
            .lpg-box {
                padding: 15px;
            }

            .station-map-section {
                padding: 14px;
            }

            #stationMap {
                height: 320px;
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
            href="../index.php"
        >
            ⛽ GasGo
        </a>

    </div>

</header>


<main class="station-page">

    <div class="station-container">


        <a
            href="../index.php"
            class="station-back"
        >
            ← Back to Dashboard
        </a>


        <article class="station-main-card">


            <?php if ($image !== ''): ?>

                <div class="station-image-wrapper">

                    <img
                        src="<?= htmlspecialchars(
                            $imageUrl,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        alt="<?= htmlspecialchars(
                            $name,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        loading="lazy"
                        onerror="this.parentElement.innerHTML='<div class=&quot;station-image-placeholder&quot;>⛽</div>';"
                    >

                </div>

            <?php else: ?>

                <div class="station-image-placeholder">
                    ⛽
                </div>

            <?php endif; ?>


            <div class="station-content">


                <?php if ($barangay !== ''): ?>

                    <span class="station-tag">

                        <?= htmlspecialchars(
                            $barangay,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </span>

                <?php endif; ?>


                <h1 class="station-title">

                    <?= htmlspecialchars(
                        $name,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </h1>


                <p class="station-address">

                    📍

                    <?= htmlspecialchars(
                        $address,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </p>


                <?php if ($description !== ''): ?>

                    <div class="station-description">

                        <?= nl2br(
                            htmlspecialchars(
                                $description,
                                ENT_QUOTES,
                                'UTF-8'
                            )
                        ) ?>

                    </div>

                <?php endif; ?>


                <div class="station-info-grid">


                    <div class="station-info-box">

                        <span class="station-info-label">
                            📅 Working Days
                        </span>

                        <span class="station-info-value">

                            <?= htmlspecialchars(
                                $workingDays,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </span>

                    </div>


                    <div class="station-info-box">

                        <span class="station-info-label">
                            🕐 Operating Hours
                        </span>

                        <span class="station-info-value">

                            <?= htmlspecialchars(
                                $operatingHours,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </span>

                    </div>


                    <div class="station-info-box">

                        <span class="station-info-label">
                            📞 Contact Number
                        </span>

                        <span class="station-info-value">

                            <?= htmlspecialchars(
                                $contactNumber,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </span>

                    </div>


                </div>


                <h2 class="station-section-title">
                    Fuel Prices
                </h2>


                <div class="fuel-grid">


                    <div class="fuel-card">

                        <span class="fuel-name">
                            Diesel
                        </span>

                        <span class="fuel-price">

                            ₱<?= htmlspecialchars(
                                (
                                    $dieselPrice !== null &&
                                    $dieselPrice !== ''
                                )
                                    ? (string)$dieselPrice
                                    : 'N/A',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </span>

                    </div>


                    <div class="fuel-card">

                        <span class="fuel-name">
                            Unleaded
                        </span>

                        <span class="fuel-price">

                            ₱<?= htmlspecialchars(
                                (
                                    $unleadedPrice !== null &&
                                    $unleadedPrice !== ''
                                )
                                    ? (string)$unleadedPrice
                                    : 'N/A',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </span>

                    </div>


                    <div class="fuel-card">

                        <span class="fuel-name">
                            Premium
                        </span>

                        <span class="fuel-price">

                            ₱<?= htmlspecialchars(
                                (
                                    $premiumPrice !== null &&
                                    $premiumPrice !== ''
                                )
                                    ? (string)$premiumPrice
                                    : 'N/A',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                        </span>

                    </div>


                </div>


                <h2 class="station-section-title">
                    LPG Availability
                </h2>


                <div class="lpg-box">

                    <?php if ($lpgAvailable): ?>

                        <span class="lpg-available">
                            ✓ LPG Available
                        </span>

                        <br><br>

                        <?php if (!empty($lpgProducts)): ?>

                            <div style="display:grid; gap:8px; margin-top:4px;">

                                <?php foreach ($lpgProducts as $lpgProduct): ?>

                                    <div>
                                        <strong>
                                            <?= htmlspecialchars(
                                                rtrim(rtrim(number_format((float)$lpgProduct['kg'], 2, '.', ''), '0'), '.'),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?> kg
                                        </strong>
                                        —
                                        ₱<?= number_format((float)$lpgProduct['price'], 2) ?>
                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php else: ?>

                            <span>

                                Price:

                                ₱<?= htmlspecialchars(
                                    (
                                        $lpgPrice !== null &&
                                        $lpgPrice !== ''
                                    )
                                        ? (string)$lpgPrice
                                        : 'N/A',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>

                            </span>

                        <?php endif; ?>

                    <?php else: ?>

                        <span class="lpg-unavailable">
                            ✕ LPG Not Available
                        </span>

                    <?php endif; ?>

                </div>


                <div class="station-actions">


                    <?php if ($hasCoordinates): ?>


                        <a
                            class="station-btn station-btn-primary"
                            href="<?= htmlspecialchars(
                                $googleDirectionsUrl,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            🧭 Get Directions
                        </a>


                        <button
                            type="button"
                            class="station-btn station-btn-secondary"
                            id="routeButton"
                        >
                            📍 Route From My Location
                        </button>


                    <?php else: ?>

                        <button
                            type="button"
                            class="station-btn"
                            disabled
                        >
                            📍 Location Unavailable
                        </button>

                    <?php endif; ?>


                </div>


            </div>

        </article>


        <?php if ($hasCoordinates): ?>

            <section class="station-map-section">

                <h2 class="station-section-title">
                    Station Location
                </h2>

                <div id="stationMap"></div>

            </section>

        <?php endif; ?>


    </div>

</main>


<?php if ($hasCoordinates): ?>

    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    ></script>


    <script>

        document.addEventListener(
            'DOMContentLoaded',
            function () {


                const stationLat =
                    <?= json_encode($latitude) ?>;

                const stationLng =
                    <?= json_encode($longitude) ?>;

                const stationName =
                    <?= json_encode($name) ?>;

                const stationAddress =
                    <?= json_encode($address) ?>;

                const stationBarangay =
                    <?= json_encode($barangay) ?>;


                const stationImage =
                    <?= json_encode($imageUrl) ?>;

                const stationLpgAvailable =
                    <?= json_encode($lpgAvailable) ?>;

                const stationLpgProducts =
                    <?= json_encode(array_map(
                        static function (array $product): array {
                            return [
                                'kg' => (float)($product['kg'] ?? 0),
                                'price' => (float)($product['price'] ?? 0),
                            ];
                        },
                        $lpgProducts
                    )) ?>;

                const stationLpgFallbackPrice =
                    <?= json_encode(
                        ($lpgPrice !== null && $lpgPrice !== '')
                            ? (float)$lpgPrice
                            : null
                    ) ?>;


                const map =
                    L.map(
                        'stationMap',
                        {
                            center: [
                                stationLat,
                                stationLng
                            ],

                            zoom: 16,

                            zoomControl: true,

                            scrollWheelZoom: true,

                            doubleClickZoom: true,

                            dragging: true,

                            touchZoom: true,

                            fadeAnimation: true,

                            zoomAnimation: true,

                            markerZoomAnimation: true
                        }
                    );


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

                streetLayer.addTo(map);

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
                ).addTo(map);


                const stationIcon =
                    L.divIcon({

                        className: '',

                        html: `
                            <div class="gas-station-marker">
                                <span>⛽</span>
                            </div>
                        `,

                        iconSize: [
                            40,
                            40
                        ],

                        iconAnchor: [
                            20,
                            40
                        ],

                        popupAnchor: [
                            0,
                            -40
                        ]

                    });


                let popupImage = '';


                if (
                    stationImage &&
                    String(
                        stationImage
                    ).trim() !== ''
                ) {

                    popupImage = `

                        <img
                            class="station-popup-image"
                            src="${escapeHtml(stationImage)}"
                            alt="${escapeHtml(stationName)}"
                            onerror="this.style.display='none';"
                        >

                    `;

                } else {

                    popupImage = `

                        <div class="station-popup-placeholder">
                            ⛽
                        </div>

                    `;

                }


                const stationMarker =
                    L.marker(
                        [
                            stationLat,
                            stationLng
                        ],
                        {
                            icon:
                                stationIcon,

                            title:
                                stationName,

                            alt:
                                stationName
                        }
                    )
                    .addTo(map);


                stationMarker.bindPopup(`

                    <div class="station-popup">

                        ${popupImage}

                        <h3>
                            ${escapeHtml(
                                stationName
                            )}
                        </h3>

                        ${
                            stationBarangay
                                ? `
                                    <p>
                                        📍
                                        ${escapeHtml(
                                            stationBarangay
                                        )}
                                    </p>
                                `
                                : ''
                        }

                        ${
                            stationAddress
                                ? `
                                    <p>
                                        ${escapeHtml(
                                            stationAddress
                                        )}
                                    </p>
                                `
                                : ''
                        }

                        ${
                            stationLpgAvailable
                                ? `
                                    <div style="margin-top:10px;padding-top:9px;border-top:1px solid #e5e7eb;">
                                        <p style="margin:0 0 5px;font-weight:700;color:#15803d;">
                                            🔥 LPG Available
                                        </p>
                                        ${
                                            Array.isArray(stationLpgProducts) &&
                                            stationLpgProducts.length
                                                ? stationLpgProducts.map(
                                                    function (item) {
                                                        const kg = Number(item.kg);
                                                        const price = Number(item.price);

                                                        return `
                                                            <p style="margin:3px 0;">
                                                                <strong>${escapeHtml(
                                                                    Number.isInteger(kg)
                                                                        ? String(kg)
                                                                        : String(kg)
                                                                )} kg</strong>
                                                                — ₱${escapeHtml(
                                                                    Number.isFinite(price)
                                                                        ? price.toFixed(2)
                                                                        : 'N/A'
                                                                )}
                                                            </p>
                                                        `;
                                                    }
                                                ).join('')
                                                : `
                                                    <p style="margin:3px 0;">
                                                        Price: ₱${escapeHtml(
                                                            stationLpgFallbackPrice !== null
                                                                ? Number(stationLpgFallbackPrice).toFixed(2)
                                                                : 'N/A'
                                                        )}
                                                    </p>
                                                `
                                        }
                                    </div>
                                `
                                : ''
                        }

                        <a
                            class="popup-directions"
                            href="https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(stationLat + ',' + stationLng)}&travelmode=driving"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            🧭 Get Directions
                        </a>

                    </div>

                `);


                stationMarker.openPopup();


                const routeButton =
                    document.getElementById(
                        'routeButton'
                    );


                let userMarker =
                    null;

                let routeLayer =
                    null;


                if (routeButton) {

                    routeButton.addEventListener(
                        'click',
                        function () {


                            if (
                                !navigator.geolocation
                            ) {

                                alert(
                                    'Your browser does not support location services.'
                                );

                                return;

                            }


                            routeButton.disabled =
                                true;

                            routeButton.textContent =
                                '📍 Getting your location...';


                            navigator.geolocation.getCurrentPosition(

                                function (position) {


                                    const userLat =
                                        position.coords.latitude;

                                    const userLng =
                                        position.coords.longitude;


                                    const userIcon =
                                        L.divIcon({

                                            className:
                                                '',

                                            html: `
                                                <div class="user-location-marker"></div>
                                            `,

                                            iconSize: [
                                                18,
                                                18
                                            ],

                                            iconAnchor: [
                                                9,
                                                9
                                            ]

                                        });


                                    if (
                                        userMarker
                                    ) {

                                        map.removeLayer(
                                            userMarker
                                        );

                                    }


                                    userMarker =
                                        L.marker(
                                            [
                                                userLat,
                                                userLng
                                            ],
                                            {
                                                icon:
                                                    userIcon
                                            }
                                        )
                                        .addTo(map)
                                        .bindPopup(
                                            '📍 You are here'
                                        );


                                    routeButton.textContent =
                                        '🛣️ Creating route...';


                                    const routeUrl =

                                        'https://router.project-osrm.org/route/v1/driving/' +

                                        userLng +
                                        ',' +
                                        userLat +

                                        ';' +

                                        stationLng +
                                        ',' +
                                        stationLat +

                                        '?overview=full&geometries=geojson';


                                    fetch(
                                        routeUrl
                                    )

                                    .then(
                                        function (
                                            response
                                        ) {

                                            if (
                                                !response.ok
                                            ) {

                                                throw new Error(
                                                    'Routing failed.'
                                                );

                                            }

                                            return response.json();

                                        }
                                    )

                                    .then(
                                        function (
                                            data
                                        ) {


                                            if (
                                                !data.routes ||
                                                !data.routes.length
                                            ) {

                                                throw new Error(
                                                    'No route found.'
                                                );

                                            }


                                            if (
                                                routeLayer
                                            ) {

                                                map.removeLayer(
                                                    routeLayer
                                                );

                                            }


                                            routeLayer =
                                                L.geoJSON(
                                                    data.routes[0].geometry,
                                                    {
                                                        style: {

                                                            color:
                                                                '#dc2626',

                                                            weight:
                                                                6,

                                                            opacity:
                                                                0.9,

                                                            lineCap:
                                                                'round',

                                                            lineJoin:
                                                                'round'

                                                        }
                                                    }
                                                )
                                                .addTo(map);


                                            const bounds =
                                                routeLayer.getBounds();


                                            map.fitBounds(
                                                bounds,
                                                {
                                                    padding:
                                                        [
                                                            50,
                                                            50
                                                        ],

                                                    maxZoom:
                                                        17
                                                }
                                            );


                                            routeButton.disabled =
                                                false;

                                            routeButton.textContent =
                                                '🔄 Recalculate Route';

                                        }
                                    )

                                    .catch(
                                        function () {


                                            routeButton.disabled =
                                                false;

                                            routeButton.textContent =
                                                '📍 Route From My Location';


                                            const googleMapsUrl =

                                                'https://www.google.com/maps/dir/?api=1' +

                                                '&origin=' +

                                                encodeURIComponent(
                                                    userLat +
                                                    ',' +
                                                    userLng
                                                ) +

                                                '&destination=' +

                                                encodeURIComponent(
                                                    stationLat +
                                                    ',' +
                                                    stationLng
                                                ) +

                                                '&travelmode=driving';


                                            window.open(
                                                googleMapsUrl,
                                                '_blank'
                                            );

                                        }
                                    );

                                },


                                function () {


                                    routeButton.disabled =
                                        false;

                                    routeButton.textContent =
                                        '📍 Route From My Location';


                                    alert(
                                        'Unable to get your location. Please allow location access in your browser.'
                                    );

                                },


                                {
                                    enableHighAccuracy:
                                        true,

                                    timeout:
                                        10000,

                                    maximumAge:
                                        0
                                }

                            );

                        }
                    );

                }


                function escapeHtml(
                    value
                ) {

                    return String(
                        value
                    )

                    .replace(
                        /&/g,
                        '&amp;'
                    )

                    .replace(
                        /</g,
                        '&lt;'
                    )

                    .replace(
                        />/g,
                        '&gt;'
                    )

                    .replace(
                        /"/g,
                        '&quot;'
                    )

                    .replace(
                        /'/g,
                        '&#039;'
                    );

                }


                setTimeout(
                    function () {

                        map.invalidateSize();

                    },
                    300
                );


            }
        );

    </script>

<?php endif; ?>



<script>
(function () {
    'use strict';

    window.alert = function (message) {
        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                icon: 'info',
                title: 'GasGo',
                text: String(message ?? ''),
                confirmButtonText: 'OK',
                confirmButtonColor: '#dc2626'
            });
            return;
        }

        console.warn('GasGo:', message);
    };
})();
</script>

<script src="assets/js/app.js"></script>


</body>
</html>