<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/csrf.php';

/*
|--------------------------------------------------------------------------
| MANAGER AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['manager_id']) ||
    !is_numeric($_SESSION['manager_id'])
) {
    header('Location: login.php');
    exit;
}

$managerId = (int) $_SESSION['manager_id'];


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$csrfToken = csrf_token();


/*
|--------------------------------------------------------------------------
| GET MANAGER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        full_name,
        email
    FROM managers
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$managerId]);

$manager = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$manager) {

    session_destroy();

    header('Location: login.php');

    exit;
}


/*
|--------------------------------------------------------------------------
| GET MANAGER'S STATION
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        manager_id,
        name,
        address,
        barangay,
        latitude,
        longitude,
        status
    FROM gas_stations
    WHERE manager_id = ?
    LIMIT 1
");

$stmt->execute([$managerId]);

$station = $stmt->fetch(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| HANDLE LOCATION UPDATE
|--------------------------------------------------------------------------
*/

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | CSRF VALIDATION
    |--------------------------------------------------------------------------
    */

    $submittedToken = (string)(
        $_POST['csrf_token'] ?? ''
    );

    if (
        $submittedToken === '' ||
        !hash_equals($csrfToken, $submittedToken)
    ) {

        $message = 'Invalid security token. Please refresh the page and try again.';
        $messageType = 'error';

    } elseif (!$station) {

        $message = 'You do not have an approved gasoline station.';
        $messageType = 'error';

    } else {

        $latitude = trim(
            (string)($_POST['latitude'] ?? '')
        );

        $longitude = trim(
            (string)($_POST['longitude'] ?? '')
        );


        /*
        |--------------------------------------------------------------------------
        | VALIDATE LATITUDE
        |--------------------------------------------------------------------------
        */

        if (
            $latitude === '' ||
            !is_numeric($latitude)
        ) {

            $message = 'Please enter a valid latitude.';
            $messageType = 'error';

        } elseif (
            (float)$latitude < -90 ||
            (float)$latitude > 90
        ) {

            $message = 'Latitude must be between -90 and 90.';
            $messageType = 'error';

        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATE LONGITUDE
        |--------------------------------------------------------------------------
        */

        elseif (
            $longitude === '' ||
            !is_numeric($longitude)
        ) {

            $message = 'Please enter a valid longitude.';
            $messageType = 'error';

        } elseif (
            (float)$longitude < -180 ||
            (float)$longitude > 180
        ) {

            $message = 'Longitude must be between -180 and 180.';
            $messageType = 'error';

        } else {

            try {

                $latitudeValue = (float)$latitude;
                $longitudeValue = (float)$longitude;

                /*
                |--------------------------------------------------------------------------
                | UPDATE LOCATION
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE gas_stations
                    SET
                        latitude = ?,
                        longitude = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                      AND manager_id = ?
                    LIMIT 1
                ");

                $stmt->execute([
                    $latitudeValue,
                    $longitudeValue,
                    (int)$station['id'],
                    $managerId
                ]);


                /*
                |--------------------------------------------------------------------------
                | REFRESH STATION DATA
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        manager_id,
                        name,
                        address,
                        barangay,
                        latitude,
                        longitude,
                        status
                    FROM gas_stations
                    WHERE manager_id = ?
                    LIMIT 1
                ");

                $stmt->execute([$managerId]);

                $station = $stmt->fetch(PDO::FETCH_ASSOC);


                $message = 'Station location updated successfully.';
                $messageType = 'success';

            } catch (Throwable $e) {

                $message = 'Unable to update the station location. Please try again.';
                $messageType = 'error';
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| CURRENT COORDINATES
|--------------------------------------------------------------------------
*/

$currentLatitude = '';

$currentLongitude = '';

if ($station) {

    $currentLatitude = number_format(
        (float)$station['latitude'],
        6,
        '.',
        ''
    );

    $currentLongitude = number_format(
        (float)$station['longitude'],
        6,
        '.',
        ''
    );
}


/*
|--------------------------------------------------------------------------
| GOOGLE MAP EMBED URL
|--------------------------------------------------------------------------
|
| This does not require a Google Maps API key.
|--------------------------------------------------------------------------
*/

$mapUrl = '';

if (
    $station &&
    is_numeric($station['latitude']) &&
    is_numeric($station['longitude'])
) {

    $mapLatitude = (float)$station['latitude'];
    $mapLongitude = (float)$station['longitude'];

    $mapUrl =
        'https://www.google.com/maps?q=' .
        rawurlencode(
            $mapLatitude . ',' . $mapLongitude
        ) .
        '&z=16&output=embed';
}

?>

<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Station Location - GasGo
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #fcfdfd;
            color: #181616;
            font-family: Arial, Helvetica, sans-serif;
        }


        /*
        |--------------------------------------------------------------------------
        | PAGE
        |--------------------------------------------------------------------------
        */

        .dashboard-page {
            min-height: 100vh;
            padding: 30px 20px 50px;
        }

        .dashboard-container {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto;
        }


        /*
        |--------------------------------------------------------------------------
        | HEADER
        |--------------------------------------------------------------------------
        */

        .dashboard-header {
            background: white;
            border-radius: 14px;
            padding: 25px;
            margin-bottom: 20px;

            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;

            box-shadow:
                0 4px 20px rgba(0,0,0,.07);
        }

        .dashboard-header h1 {
            margin: 0;
            font-size: 28px;
        }

        .dashboard-header p {
            margin: 6px 0 0;
            color: #666;
        }

        .manager-welcome {
            font-size: 14px;
            color: #2b5e4f !important;
            font-weight: 600;
        }

        .back-btn {
            display: inline-block;
            text-decoration: none;
            color: #333;
            font-weight: 600;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 9px;
            background: #f8f9fb;
            transition: .2s;
            white-space: nowrap;
        }

        .back-btn:hover {
            background: #eee;
        }


        /*
        |--------------------------------------------------------------------------
        | SECTION CARD
        |--------------------------------------------------------------------------
        */

        .section-card {
            background: white;
            border-radius: 14px;
            padding: 25px;
            margin-bottom: 20px;

            box-shadow:
                0 4px 20px rgba(0,0,0,.07);
        }

        .section-card h2 {
            margin-top: 0;
            margin-bottom: 8px;
        }

        .section-description {
            color: #666;
            margin-top: 0;
            margin-bottom: 22px;
            line-height: 1.6;
        }


        /*
        |--------------------------------------------------------------------------
        | NOTICE
        |--------------------------------------------------------------------------
        */

        .notice {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .success-notice {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .error-notice {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .warning-notice {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
        }


        /*
        |--------------------------------------------------------------------------
        | STATION SUMMARY
        |--------------------------------------------------------------------------
        */

        .station-summary {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 25px;
        }

        .info-box {
            background: #f8f9fb;
            border-radius: 10px;
            padding: 15px;
        }

        .info-box strong {
            display: block;
            margin-bottom: 6px;
            color: #555;
            font-size: 13px;
        }

        .info-box span {
            font-size: 15px;
        }

        .info-box.full {
            grid-column: 1 / -1;
        }


        /*
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        .station-status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
        }

        .status-approved {
            background: #dcfce7;
            color: #166534;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-needs {
            background: #dbeafe;
            color: #1d4ed8;
        }


        /*
        |--------------------------------------------------------------------------
        | FORM
        |--------------------------------------------------------------------------
        */

        .location-form {
            max-width: 700px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: 700;
            color: #333;
        }

        .form-group small {
            display: block;
            margin-top: 6px;
            color: #777;
            line-height: 1.4;
        }

        .form-control {
            width: 100%;
            padding: 13px 14px;

            border: 1px solid #d7d7d7;
            border-radius: 9px;

            background: white;
            color: #222;

            font-size: 15px;

            outline: none;

            transition: .2s;
        }

        .form-control:focus {
            border-color: #2563eb;
            box-shadow:
                0 0 0 3px rgba(37,99,235,.10);
        }

        .coordinate-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }


        /*
        |--------------------------------------------------------------------------
        | BUTTONS
        |--------------------------------------------------------------------------
        */

        .button-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 22px;
        }

        .button {
            display: inline-block;

            padding: 12px 18px;

            border-radius: 9px;

            border: 1px solid transparent;

            text-decoration: none;

            font-size: 14px;
            font-weight: 700;

            cursor: pointer;

            transition: .2s;
        }

        .button:hover {
            transform: translateY(-1px);
        }

        .button-primary {
            background: #dc2626;
            border-color: #dc2626;
            color: white;
        }

        .button-primary:hover {
            background: #b91c1c;
        }

        .button-secondary {
            background: #f3f4f6;
            border-color: #ddd;
            color: #222;
        }

        .button-secondary:hover {
            background: #e5e7eb;
        }


        /*
        |--------------------------------------------------------------------------
        | CURRENT LOCATION
        |--------------------------------------------------------------------------
        */

        .coordinates-card {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .coordinate-box {
            background: #f8f9fb;
            border-radius: 10px;
            padding: 18px;
            text-align: center;
        }

        .coordinate-box strong {
            display: block;
            color: #555;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .coordinate-value {
            font-size: 20px;
            font-weight: 700;
            color: #222;
            word-break: break-word;
        }


        /*
        |--------------------------------------------------------------------------
        | MAP
        |--------------------------------------------------------------------------
        */

        .map-container {
            width: 100%;
            height: 450px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #ddd;
            background: #f3f4f6;
        }

        #stationLocationMap {
            width: 100%;
            height: 100%;
        }

        .map-instructions {
            margin: 12px 0 0;
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }

        .leaflet-control-layers {
            border-radius: 10px;
        }

        .leaflet-popup-content {
            margin: 12px 14px;
        }


        /*
        |--------------------------------------------------------------------------
        | NO STATION
        |--------------------------------------------------------------------------
        */

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .empty-state h2 {
            color: #333;
        }


        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 700px) {

            .dashboard-page {
                padding: 15px 10px 35px;
            }

            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                padding: 20px;
            }

            .dashboard-header h1 {
                font-size: 24px;
            }

            .section-card {
                padding: 20px;
            }

            .station-summary {
                grid-template-columns: 1fr;
            }

            .info-box.full {
                grid-column: auto;
            }

            .coordinate-grid {
                grid-template-columns: 1fr;
            }

            .coordinates-card {
                grid-template-columns: 1fr;
            }

            .map-container {
                height: 350px;
            }

            .back-btn {
                width: 100%;
                text-align: center;
            }

            .button {
                width: 100%;
                text-align: center;
            }
        }

    </style>

</head>

<body>

<main class="dashboard-page">

<div class="dashboard-container">


    <!-- ==========================================================
         HEADER
    =========================================================== -->

    <div class="dashboard-header">

        <div>

            <h1>
                Station Location
            </h1>

            <p class="manager-welcome">
                Welcome, <?= e($manager['full_name']) ?>.
            </p>

            <p>
                Manage the map location of your gasoline station.
            </p>

        </div>


        <a
            href="dashboard.php"
            class="back-btn"
        >
            ← Back to Dashboard
        </a>

    </div>


    <?php if ($message !== ''): ?>

        <div
            class="notice
            <?= $messageType === 'success'
                ? 'success-notice'
                : 'error-notice'
            ?>"
        >

            <?= e($message) ?>

        </div>

    <?php endif; ?>


    <?php if (!$station): ?>

        <!-- ======================================================
             NO STATION
        ======================================================= -->

        <div class="section-card">

            <div class="empty-state">

                <h2>
                    No Approved Station
                </h2>

                <p>
                    You currently do not have an approved gasoline
                    station. Your station location can only be
                    managed after your station has been approved.
                </p>

                <a
                    href="dashboard.php"
                    class="button button-primary"
                >
                    Return to Dashboard
                </a>

            </div>

        </div>


    <?php else: ?>


        <!-- ======================================================
             STATION INFORMATION
        ======================================================= -->

        <div class="section-card">

            <h2>
                My Gasoline Station
            </h2>

            <p class="section-description">
                Review your station information before updating
                its location.
            </p>


            <div class="station-summary">


                <div class="info-box">

                    <strong>
                        Station Name
                    </strong>

                    <span>
                        <?= e($station['name']) ?>
                    </span>

                </div>


                <div class="info-box">

                    <strong>
                        Status
                    </strong>

                    <?php

                    $stationStatus = strtolower(
                        trim(
                            (string)(
                                $station['status'] ?? ''
                            )
                        )
                    );

                    $stationStatusClass =
                        'station-status';

                    if (
                        $stationStatus === 'approved'
                    ) {

                        $stationStatusClass .=
                            ' status-approved';

                    } elseif (
                        $stationStatus === 'pending'
                    ) {

                        $stationStatusClass .=
                            ' status-pending';

                    } elseif (
                        $stationStatus === 'rejected'
                    ) {

                        $stationStatusClass .=
                            ' status-rejected';

                    } elseif (
                        $stationStatus === 'needs changes'
                    ) {

                        $stationStatusClass .=
                            ' status-needs';
                    }

                    ?>

                    <span
                        class="<?= e($stationStatusClass) ?>"
                    >
                        <?= e($station['status']) ?>
                    </span>

                </div>


                <div class="info-box">

                    <strong>
                        Address
                    </strong>

                    <span>
                        <?= e($station['address']) ?>
                    </span>

                </div>


                <div class="info-box">

                    <strong>
                        Barangay
                    </strong>

                    <span>
                        <?= e($station['barangay']) ?>
                    </span>

                </div>


            </div>

        </div>


        <!-- ======================================================
             CURRENT COORDINATES
        ======================================================= -->

        <div class="section-card">

            <h2>
                Current Location
            </h2>

            <p class="section-description">
                These are the coordinates currently saved for
                your gasoline station.
            </p>


            <div class="coordinates-card">


                <div class="coordinate-box">

                    <strong>
                        Latitude
                    </strong>

                    <div class="coordinate-value">
                        <?= e($currentLatitude) ?>
                    </div>

                </div>


                <div class="coordinate-box">

                    <strong>
                        Longitude
                    </strong>

                    <div class="coordinate-value">
                        <?= e($currentLongitude) ?>
                    </div>

                </div>


            </div>

        </div>


        <!-- ======================================================
             UPDATE LOCATION
        ======================================================= -->

        <div class="section-card">

            <h2>
                Update Station Location
            </h2>

            <p class="section-description">
                Enter the exact latitude and longitude of your
                gasoline station. These coordinates will be used
                when displaying your station on the GasGo map.
            </p>


            <?php if (
                strtolower(
                    trim(
                        (string)$station['status']
                    )
                ) !== 'approved'
            ): ?>

                <div class="notice warning-notice">

                    Your station is currently
                    <strong>
                        <?= e($station['status']) ?>
                    </strong>.
                    Location updates are normally available
                    after station approval.

                </div>

            <?php else: ?>


                <form
                    method="POST"
                    class="location-form"
                    autocomplete="off"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >


                    <div class="coordinate-grid">


                        <!-- LATITUDE -->

                        <div class="form-group">

                            <label for="latitude">
                                Latitude
                            </label>

                            <input
                                type="number"
                                id="latitude"
                                name="latitude"
                                class="form-control"
                                value="<?= e($currentLatitude) ?>"
                                step="any"
                                min="-90"
                                max="90"
                                placeholder="Example: 9.834949"
                                required
                            >

                            <small>
                                Latitude must be between -90 and 90.
                            </small>

                        </div>


                        <!-- LONGITUDE -->

                        <div class="form-group">

                            <label for="longitude">
                                Longitude
                            </label>

                            <input
                                type="number"
                                id="longitude"
                                name="longitude"
                                class="form-control"
                                value="<?= e($currentLongitude) ?>"
                                step="any"
                                min="-180"
                                max="180"
                                placeholder="Example: 126.160000"
                                required
                            >

                            <small>
                                Longitude must be between -180 and 180.
                            </small>

                        </div>


                    </div>


                    <div class="button-row">

                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            Save Location
                        </button>


                        <button
                            type="button"
                            class="button button-secondary"
                            id="useCurrentLocation"
                        >
                            📍 Use My Current Location
                        </button>

                    </div>

                </form>


            <?php endif; ?>

        </div>


        <!-- ======================================================
             MAP PREVIEW
        ======================================================= -->

        <div class="section-card">

            <h2>
                Map Preview
            </h2>

            <p class="section-description">
                Click the map or drag the station marker to choose the
                exact location of your gasoline station.
            </p>


            <div class="map-container">
                <div id="stationLocationMap"></div>
            </div>

            <p class="map-instructions">
                Click anywhere on the map or drag the station marker to update
                the latitude and longitude fields above. Then click
                <strong>Save Location</strong> to save the new station position.
            </p>

        </div>


        <!-- ======================================================
             LOCATION HELP
        ======================================================= -->

        <div class="section-card">

            <h2>
                Location Tips
            </h2>

            <div class="notice">

                <strong>
                    Make sure your coordinates are accurate.
                </strong>

                <br><br>

                The station marker on GasGo will be based on the
                latitude and longitude saved here.

                <br><br>

                You can get your coordinates by opening Google Maps,
                selecting your gasoline station location, and copying
                the latitude and longitude values.

            </div>

        </div>


    <?php endif; ?>


</div>

</main>


<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>

/*
|--------------------------------------------------------------------------
| INTERACTIVE STATION MAP
|--------------------------------------------------------------------------
*/

const latitudeInput =
    document.getElementById('latitude');

const longitudeInput =
    document.getElementById('longitude');

const mapElement =
    document.getElementById('stationLocationMap');

let stationMap = null;
let stationMarker = null;

function setStationCoordinates(lat, lng, recenter = false) {

    if (
        !Number.isFinite(lat) ||
        !Number.isFinite(lng)
    ) {
        return;
    }

    if (latitudeInput) {
        latitudeInput.value = lat.toFixed(6);
    }

    if (longitudeInput) {
        longitudeInput.value = lng.toFixed(6);
    }

    if (stationMarker) {
        stationMarker.setLatLng([lat, lng]);
    }

    if (recenter && stationMap) {
        stationMap.setView([lat, lng], 17);
    }
}

if (mapElement) {

    const savedLatitude =
        <?= json_encode(
            $station && is_numeric($station['latitude'])
                ? (float)$station['latitude']
                : 9.8482
        ) ?>;

    const savedLongitude =
        <?= json_encode(
            $station && is_numeric($station['longitude'])
                ? (float)$station['longitude']
                : 126.0458
        ) ?>;

    stationMap = L.map('stationLocationMap', {
        center: [savedLatitude, savedLongitude],
        zoom: 16,
        zoomControl: true,
        scrollWheelZoom: true
    });

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

    streetLayer.addTo(stationMap);

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
    ).addTo(stationMap);

    const stationIcon = L.divIcon({
        className: '',
        html: `
            <div style="
                width:42px;
                height:42px;
                display:flex;
                align-items:center;
                justify-content:center;
                background:#dc2626;
                border:3px solid #fff;
                border-radius:50% 50% 50% 0;
                box-shadow:0 3px 10px rgba(0,0,0,.28);
                transform:rotate(-45deg);
                font-size:19px;
            ">
                <span style="transform:rotate(45deg);">⛽</span>
            </div>
        `,
        iconSize: [42, 42],
        iconAnchor: [21, 42]
    });

    stationMarker = L.marker(
        [savedLatitude, savedLongitude],
        {
            draggable: true,
            icon: stationIcon,
            title: <?= json_encode((string)($station['name'] ?? 'Gas Station')) ?>
        }
    ).addTo(stationMap);

    stationMarker.bindPopup(
        '<strong><?= e($station['name'] ?? 'Gas Station') ?></strong><br>Drag this marker to update the station location.'
    );

    stationMarker.on('dragend', function () {
        const position = stationMarker.getLatLng();
        setStationCoordinates(position.lat, position.lng, false);
    });

    stationMap.on('click', function (event) {
        setStationCoordinates(event.latlng.lat, event.latlng.lng, false);

        if (stationMarker) {
            stationMarker.openPopup();
        }
    });

    setTimeout(function () {
        stationMap.invalidateSize();
    }, 250);
}


/*
|--------------------------------------------------------------------------
| USE CURRENT LOCATION
|--------------------------------------------------------------------------
|
| Uses the browser's GPS/location permission and automatically
| fills the latitude and longitude fields.
|--------------------------------------------------------------------------
*/

const locationButton =
    document.getElementById('useCurrentLocation');

if (
    locationButton &&
    latitudeInput &&
    longitudeInput
) {

    locationButton.addEventListener(
        'click',
        function () {

            if (!navigator.geolocation) {

                alert(
                    'Geolocation is not supported by your browser.'
                );

                return;
            }


            locationButton.disabled = true;

            locationButton.textContent =
                'Getting location...';


            navigator.geolocation.getCurrentPosition(

                function (position) {

                    const latitude =
                        position.coords.latitude;

                    const longitude =
                        position.coords.longitude;


                    setStationCoordinates(
                        latitude,
                        longitude,
                        true
                    );


                    locationButton.disabled = false;

                    locationButton.textContent =
                        '📍 Use My Current Location';


                    alert(
                        'Current location detected. ' +
                        'Click "Save Location" to save it.'
                    );

                },


                function (error) {

                    locationButton.disabled = false;

                    locationButton.textContent =
                        '📍 Use My Current Location';


                    let message =
                        'Unable to get your current location.';


                    if (error.code === 1) {

                        message =
                            'Location permission was denied. ' +
                            'Please allow location access in your browser.';

                    } else if (error.code === 2) {

                        message =
                            'Your current location could not be determined.';

                    } else if (error.code === 3) {

                        message =
                            'Location request timed out. Please try again.';
                    }


                    alert(message);

                },


                {
                    enableHighAccuracy: true,
                    timeout: 15000,
                    maximumAge: 0
                }

            );

        }
    );

}

</script>

</body>

</html>