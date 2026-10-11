<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/csrf.php';

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
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
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| GET MANAGER
|--------------------------------------------------------------------------
*/

try {
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
        session_unset();
        session_destroy();

        header('Location: login.php');
        exit;
    }
} catch (PDOException $e) {

    error_log(
        'GasGo request.php manager lookup error: ' .
        $e->getMessage()
    );

    die('Unable to load your manager account.');
}

/*
|--------------------------------------------------------------------------
| CHECK APPROVED STATION
|
| IMPORTANT:
| gas_stations uses "name", NOT "station_name".
|--------------------------------------------------------------------------
*/

try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            name
        FROM gas_stations
        WHERE manager_id = ?
        LIMIT 1
    ");

    $stmt->execute([$managerId]);

    $existingStation = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log(
        'GasGo request.php station lookup error: ' .
        $e->getMessage()
    );

    /*
     * If this error appears, your db_connect.php may be pointing
     * to a different database than the one containing the GasGo tables.
     */
    die(
        'Unable to check your station information. ' .
        'Please verify that db_connect.php is connected to the correct GasGo database.'
    );
}

/*
|--------------------------------------------------------------------------
| CHECK ACTIVE REQUEST
|--------------------------------------------------------------------------
*/

try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            station_name,
            status,
            admin_feedback,
            created_at,
            updated_at
        FROM station_requests
        WHERE manager_id = ?
          AND status IN ('Pending', 'Needs changes')
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([$managerId]);

    $activeRequest = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    error_log(
        'GasGo request.php active request lookup error: ' .
        $e->getMessage()
    );

    die('Unable to check your station request.');
}

/*
|--------------------------------------------------------------------------
| APPROVED STATION ALREADY EXISTS
|--------------------------------------------------------------------------
*/

if ($existingStation) {

    header(
        'Location: dashboard.php?error=' .
        urlencode(
            'You already have an approved station. You cannot submit another station.'
        )
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| ACTIVE REQUEST EXISTS
|--------------------------------------------------------------------------
*/

if ($activeRequest) {

    $status = strtolower(
        trim((string) $activeRequest['status'])
    );

    if ($status === 'needs changes') {

        header(
            'Location: dashboard.php?error=' .
            urlencode(
                'Your station request needs changes. Please edit and resubmit your existing request.'
            )
        );

    } else {

        header(
            'Location: dashboard.php?error=' .
            urlencode(
                'You already have a pending station request. Please wait for admin review.'
            )
        );
    }

    exit;
}

/*
|--------------------------------------------------------------------------
| FORM DEFAULTS
|--------------------------------------------------------------------------
*/

$form = [
    'station_name'    => '',
    'address'         => '',
    'barangay'        => '',
    'description'     => '',
    'latitude'        => '',
    'longitude'       => '',
    'operating_hours' => '',
    'diesel_price'    => '',
    'unleaded_price'  => '',
    'premium_price'   => '',
    'contact_number'  => ''
];

$error   = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Submit Station Request | GasGo</title>

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
            font-family:
                Arial,
                Helvetica,
                sans-serif;
            background: #f5f7fa;
            color: #1f2937;
        }

        .header {
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-inner {
            max-width: 1150px;
            margin: auto;
            padding: 16px 20px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 20px;
        }

        .logo {
            font-size: 24px;
            font-weight: 800;
            color: #111827;
            text-decoration: none;
        }

        .logo span {
            color: #16a34a;
        }

        .nav {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav a {
            text-decoration: none;
            color: #374151;
            padding: 9px 13px;
            border-radius: 8px;
            font-size: 14px;
        }

        .nav a:hover {
            background: #f3f4f6;
        }

        .container {
            width: min(
                1150px,
                calc(100% - 30px)
            );

            margin: 30px auto 50px;
        }

        .page-title {
            margin-bottom: 22px;
        }

        .page-title h1 {
            margin: 0 0 6px;
            font-size: 30px;
        }

        .page-title p {
            margin: 0;
            color: #6b7280;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .notice {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
        }

        .card {
            background: #ffffff;
            border-radius: 14px;
            padding: 25px;

            box-shadow:
                0 5px 20px rgba(0, 0, 0, 0.06);

            margin-bottom: 22px;
        }

        .card h2 {
            margin: 0 0 6px;
            font-size: 20px;
        }

        .card-description {
            color: #6b7280;
            font-size: 14px;
            margin: 0 0 22px;
            line-height: 1.5;
        }

        .form-grid {
            display: grid;
            grid-template-columns:
                repeat(
                    2,
                    minmax(0, 1fr)
                );

            gap: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 14px;
            font-weight: 700;
            color: #374151;
        }

        input,
        textarea {
            width: 100%;

            border: 1px solid #d1d5db;
            border-radius: 9px;

            padding: 12px 13px;

            font-size: 14px;
            outline: none;

            background: #ffffff;
        }

        input:focus,
        textarea:focus {
            border-color: #16a34a;

            box-shadow:
                0 0 0 3px
                rgba(22, 163, 74, 0.10);
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .hint {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.4;
        }

        .required {
            color: #dc2626;
        }

        .fuel-note {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;

            padding: 13px 15px;
            border-radius: 9px;

            font-size: 13px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .lpg-toggle {
            display: flex;
            align-items: center;
            gap: 10px;

            padding: 14px;

            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 10px;

            margin-top: 25px;
            margin-bottom: 18px;
        }

        .lpg-toggle input {
            width: 18px;
            height: 18px;
            accent-color: #16a34a;
        }

        .lpg-toggle label {
            cursor: pointer;
        }

        .lpg-products {
            display: none;
        }

        .lpg-products.active {
            display: block;
        }

        .lpg-products-header {
            display: flex;
            justify-content: space-between;
            align-items: center;

            gap: 15px;
            margin-bottom: 12px;
        }

        .lpg-products-title {
            font-size: 15px;
            font-weight: 700;
        }

        .lpg-product-row {
            display: grid;
            grid-template-columns:
                1fr 1fr auto;

            gap: 12px;
            align-items: end;

            padding: 15px;

            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 10px;

            margin-bottom: 10px;
        }

        .add-lpg,
        .remove-lpg,
        .map-btn,
        .btn {
            cursor: pointer;
        }

        .add-lpg {
            border: none;
            border-radius: 8px;

            padding: 10px 14px;

            background: #dcfce7;
            color: #166534;

            font-weight: 700;
        }

        .add-lpg:hover {
            background: #bbf7d0;
        }

        .remove-lpg {
            height: 44px;

            border: none;
            border-radius: 8px;

            padding: 0 14px;

            background: #fee2e2;
            color: #b91c1c;

            font-weight: 700;
        }

        .remove-lpg:hover {
            background: #fecaca;
        }

        .map-actions {
            display: flex;
            flex-wrap: wrap;

            gap: 10px;
            margin-bottom: 12px;
        }

        .map-btn {
            border: none;
            border-radius: 9px;

            padding: 11px 15px;

            font-size: 13px;
            font-weight: 700;
        }

        .current-location-btn {
            background: #16a34a;
            color: #ffffff;
        }

        .current-location-btn:hover {
            background: #15803d;
        }

        .clear-location-btn {
            background: #e5e7eb;
            color: #374151;
        }

        .clear-location-btn:hover {
            background: #d1d5db;
        }

        #map {
            width: 100%;
            height: 430px;

            border-radius: 12px;
            overflow: hidden;

            border: 1px solid #d1d5db;
        }

        .location-status {
            margin-top: 12px;

            padding: 12px 14px;

            border-radius: 9px;

            background: #eff6ff;
            color: #1e40af;

            font-size: 13px;
            line-height: 1.4;
        }

        .location-status.success {
            background: #f0fdf4;
            color: #166534;
        }

        .location-status.error {
            background: #fef2f2;
            color: #991b1b;
        }

        .coordinates {
            display: grid;
            grid-template-columns: 1fr 1fr;

            gap: 14px;
            margin-top: 15px;
        }

        .upload-box {
            border: 2px dashed #d1d5db;

            border-radius: 10px;

            padding: 18px;

            background: #fafafa;
        }

        .upload-box input {
            border: none;
            padding: 0;
            background: transparent;
        }

        .button-row {
            display: flex;
            justify-content: flex-end;

            gap: 10px;
            margin-top: 25px;
        }

        .btn {
            display: inline-flex;

            justify-content: center;
            align-items: center;

            text-decoration: none;

            border: none;
            border-radius: 9px;

            padding: 12px 20px;

            font-weight: 700;
            font-size: 14px;
        }

        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }

        .btn-primary {
            background: #16a34a;
            color: #ffffff;
        }

        .btn-primary:hover {
            background: #15803d;
        }

        @media (max-width: 750px) {

            .header-inner {
                flex-direction: column;
                align-items: flex-start;
            }

            .nav {
                width: 100%;
                flex-wrap: wrap;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .full {
                grid-column: auto;
            }

            .coordinates {
                grid-template-columns: 1fr;
            }

            .lpg-product-row {
                grid-template-columns: 1fr;
            }

            .remove-lpg {
                width: 100%;
            }

            #map {
                height: 350px;
            }

            .button-row {
                flex-direction: column-reverse;
            }

            .button-row .btn {
                width: 100%;
            }

            .map-actions {
                flex-direction: column;
            }

            .map-btn {
                width: 100%;
            }

            .lpg-products-header {
                flex-direction: column;
                align-items: stretch;
            }

            .add-lpg {
                width: 100%;
            }
        }

    </style>

</head>

<body>

<header class="header">

    <div class="header-inner">

        <a
            href="dashboard.php"
            class="logo"
        >
            Gas<span>Go</span>
        </a>

        <nav class="nav">

            <a href="dashboard.php">
                Dashboard
            </a>

            <a href="logout.php">
                Logout
            </a>

        </nav>

    </div>

</header>

<main class="container">

    <div class="page-title">

        <h1>
            Submit Station Request
        </h1>

        <p>
            Submit your gasoline station information
            for admin review.
        </p>

    </div>

    <?php if ($error !== ''): ?>

        <div class="alert alert-error">
            <?= e($error) ?>
        </div>

    <?php endif; ?>

    <?php if ($success !== ''): ?>

        <div class="alert alert-success">
            <?= e($success) ?>
        </div>

    <?php endif; ?>

    <div class="notice">

        <strong>Important:</strong>

        Make sure your station information,
        fuel prices, LPG products, and location
        are correct before submitting.

    </div>

    <form
        action="request_action.php"
        method="POST"
        enctype="multipart/form-data"
        autocomplete="off"
        id="station-form"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >

        <!-- =====================================================
             STATION INFORMATION
        ====================================================== -->

        <section class="card">

            <h2>
                Station Information
            </h2>

            <p class="card-description">
                Provide the basic information about
                your gasoline station.
            </p>

            <div class="form-grid">

                <div class="form-group full">

                    <label for="station_name">
                        Station Name
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="station_name"
                        name="station_name"
                        maxlength="150"
                        required
                        placeholder="Enter station name"
                    >

                </div>

                <div class="form-group">

                    <label for="barangay">
                        Barangay
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="barangay"
                        name="barangay"
                        maxlength="120"
                        required
                        placeholder="e.g. General Luna"
                    >

                </div>

                <div class="form-group">

                    <label for="contact_number">
                        Contact Number
                    </label>

                    <input
                        type="text"
                        id="contact_number"
                        name="contact_number"
                        maxlength="40"
                        placeholder="e.g. 09XXXXXXXXX"
                    >

                </div>

                <div class="form-group full">

                    <label for="address">
                        Complete Address
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="address"
                        name="address"
                        maxlength="255"
                        required
                        placeholder="Enter complete station address"
                    >

                </div>

                <div class="form-group full">

                    <label for="description">
                        Description
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        maxlength="1000"
                        placeholder="Enter a short description of the station"
                    ></textarea>

                </div>

                <div class="form-group full">

                    <label for="operating_hours">
                        Operating Hours
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="operating_hours"
                        name="operating_hours"
                        maxlength="120"
                        required
                        placeholder="e.g. Monday - Sunday, 6:00 AM - 9:00 PM"
                    >

                </div>

            </div>

        </section>

        <!-- =====================================================
             FUEL PRODUCTS
        ====================================================== -->

        <section class="card">

            <h2>
                Fuel Products and Prices
            </h2>

            <p class="card-description">
                Enter the current prices of the fuel products
                offered by your station.
            </p>

            <div class="fuel-note">

                <strong>Note:</strong>

                LPG prices are entered separately
                according to cylinder size.

            </div>

            <div class="form-grid">

                <div class="form-group">

                    <label for="diesel_price">
                        Diesel Price
                    </label>

                    <input
                        type="number"
                        id="diesel_price"
                        name="diesel_price"
                        min="0"
                        max="999999.99"
                        step="0.01"
                        placeholder="0.00"
                    >

                    <span class="hint">
                        Price per liter.
                    </span>

                </div>

                <div class="form-group">

                    <label for="unleaded_price">
                        Unleaded Price
                    </label>

                    <input
                        type="number"
                        id="unleaded_price"
                        name="unleaded_price"
                        min="0"
                        max="999999.99"
                        step="0.01"
                        placeholder="0.00"
                    >

                    <span class="hint">
                        Price per liter.
                    </span>

                </div>

                <div class="form-group">

                    <label for="premium_price">
                        Premium Price
                    </label>

                    <input
                        type="number"
                        id="premium_price"
                        name="premium_price"
                        min="0"
                        max="999999.99"
                        step="0.01"
                        placeholder="0.00"
                    >

                    <span class="hint">
                        Price per liter.
                    </span>

                </div>

            </div>

            <div class="lpg-toggle">

                <input
                    type="checkbox"
                    id="lpg_available"
                    name="lpg_available"
                    value="1"
                >

                <label for="lpg_available">
                    This station offers LPG products
                </label>

            </div>

            <div
                class="lpg-products"
                id="lpg-products"
            >

                <div class="lpg-products-header">

                    <div>

                        <div class="lpg-products-title">
                            LPG Products
                        </div>

                        <div class="hint">
                            Add each LPG cylinder size
                            and its corresponding price.
                        </div>

                    </div>

                    <button
                        type="button"
                        class="add-lpg"
                        id="add-lpg"
                    >
                        + Add LPG Size
                    </button>

                </div>

                <div id="lpg-product-list"></div>

                <div
                    class="hint"
                    id="lpg-empty-message"
                >
                    Add at least one LPG size if
                    your station offers LPG.
                </div>

            </div>

        </section>

        <!-- =====================================================
             LOCATION
        ====================================================== -->

        <section class="card">

            <h2>
                Station Location
            </h2>

            <p class="card-description">

                Place the marker exactly where your
                gasoline station is located.

            </p>

            <div class="map-actions">

                <button
                    type="button"
                    class="map-btn current-location-btn"
                    id="use-current-location"
                >
                    📍 Use My Current Location
                </button>

                <button
                    type="button"
                    class="map-btn clear-location-btn"
                    id="clear-location"
                >
                    Clear Location
                </button>

            </div>

            <div id="map"></div>

            <div
                class="location-status"
                id="location-status"
            >
                Click anywhere on the map to place
                your station marker.
            </div>

            <div class="coordinates">

                <div class="form-group">

                    <label for="latitude">
                        Latitude
                        <span class="required">*</span>
                    </label>

                    <input
                        type="number"
                        id="latitude"
                        name="latitude"
                        step="any"
                        readonly
                        required
                        placeholder="Select location on map"
                    >

                </div>

                <div class="form-group">

                    <label for="longitude">
                        Longitude
                        <span class="required">*</span>
                    </label>

                    <input
                        type="number"
                        id="longitude"
                        name="longitude"
                        step="any"
                        readonly
                        required
                        placeholder="Select location on map"
                    >

                </div>

            </div>

            <p class="hint">
                Click the map, drag the marker, or use
                your current location.
            </p>

        </section>

        <!-- =====================================================
             IMAGE
        ====================================================== -->

        <section class="card">

            <h2>
                Station Image
            </h2>

            <p class="card-description">
                Upload a clear photo of your gasoline station.
            </p>

            <div class="upload-box">

                <input
                    type="file"
                    id="image"
                    name="image"
                    accept="image/jpeg,image/png,image/webp"
                >

                <p class="hint">
                    Accepted formats: JPG, PNG, WEBP.
                    Maximum file size: 5 MB.
                </p>

            </div>

        </section>

        <!-- =====================================================
             BUTTONS
        ====================================================== -->

        <div class="button-row">

            <a
                href="dashboard.php"
                class="btn btn-secondary"
            >
                Cancel
            </a>

            <button
                type="submit"
                class="btn btn-primary"
            >
                Submit Station Request
            </button>

        </div>

    </form>

</main>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>

document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | MAP
    |--------------------------------------------------------------------------
    */

    const defaultLat = 9.808;
    const defaultLng = 126.132;

    const map = L.map('map').setView(
        [defaultLat, defaultLng],
        11
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

    let stationMarker = null;

    const latitudeInput =
        document.getElementById('latitude');

    const longitudeInput =
        document.getElementById('longitude');

    const locationStatus =
        document.getElementById('location-status');

    function setLocationStatus(
        message,
        type = ''
    ) {

        locationStatus.textContent = message;

        locationStatus.className =
            'location-status';

        if (type) {
            locationStatus.classList.add(type);
        }
    }

    function setCoordinates(
        lat,
        lng,
        zoomMap = true
    ) {

        latitudeInput.value =
            Number(lat).toFixed(7);

        longitudeInput.value =
            Number(lng).toFixed(7);

        if (zoomMap) {

            map.setView(
                [lat, lng],
                16
            );
        }
    }

    function setStationMarker(
        lat,
        lng,
        zoomMap = true
    ) {

        if (stationMarker) {

            stationMarker.setLatLng([
                lat,
                lng
            ]);

        } else {

            stationMarker = L.marker(
                [lat, lng],
                {
                    draggable: true
                }
            ).addTo(map);

            stationMarker.on(
                'dragend',
                function (event) {

                    const position =
                        event.target.getLatLng();

                    setCoordinates(
                        position.lat,
                        position.lng,
                        false
                    );

                    setLocationStatus(
                        'Station location updated.',
                        'success'
                    );
                }
            );
        }

        setCoordinates(
            lat,
            lng,
            zoomMap
        );

        setLocationStatus(
            'Station location selected. You can drag the marker to adjust it.',
            'success'
        );
    }

    map.on(
        'click',
        function (event) {

            setStationMarker(
                event.latlng.lat,
                event.latlng.lng,
                false
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | CURRENT LOCATION
    |--------------------------------------------------------------------------
    */

    const currentLocationButton =
        document.getElementById(
            'use-current-location'
        );

    currentLocationButton.addEventListener(
        'click',
        function () {

            if (!navigator.geolocation) {

                setLocationStatus(
                    'Geolocation is not supported by this browser.',
                    'error'
                );

                return;
            }

            currentLocationButton.disabled = true;

            currentLocationButton.textContent =
                'Getting Your Location...';

            setLocationStatus(
                'Requesting your current location. Please allow location access.'
            );

            navigator.geolocation.getCurrentPosition(

                function (position) {

                    const lat =
                        position.coords.latitude;

                    const lng =
                        position.coords.longitude;

                    setStationMarker(
                        lat,
                        lng,
                        true
                    );

                    setLocationStatus(
                        'Your current location has been selected. Make sure the marker is exactly on your station.',
                        'success'
                    );

                    currentLocationButton.disabled =
                        false;

                    currentLocationButton.textContent =
                        '📍 Use My Current Location';
                },

                function (error) {

                    let message =
                        'Unable to get your current location.';

                    if (error.code === 1) {

                        message =
                            'Location permission was denied. Please allow location access.';

                    } else if (error.code === 2) {

                        message =
                            'Your current location could not be determined.';

                    } else if (error.code === 3) {

                        message =
                            'Getting your location timed out.';
                    }

                    setLocationStatus(
                        message,
                        'error'
                    );

                    currentLocationButton.disabled =
                        false;

                    currentLocationButton.textContent =
                        '📍 Use My Current Location';
                },

                {
                    enableHighAccuracy: true,
                    timeout: 15000,
                    maximumAge: 0
                }
            );
        }
    );

    /*
    |--------------------------------------------------------------------------
    | CLEAR LOCATION
    |--------------------------------------------------------------------------
    */

    document
        .getElementById('clear-location')
        .addEventListener(
            'click',
            function () {

                if (stationMarker) {

                    map.removeLayer(
                        stationMarker
                    );

                    stationMarker = null;
                }

                latitudeInput.value = '';
                longitudeInput.value = '';

                map.setView(
                    [defaultLat, defaultLng],
                    11
                );

                setLocationStatus(
                    'Location cleared. Select your station location on the map.'
                );
            }
        );

    /*
    |--------------------------------------------------------------------------
    | LPG
    |--------------------------------------------------------------------------
    */

    const lpgCheckbox =
        document.getElementById(
            'lpg_available'
        );

    const lpgProducts =
        document.getElementById(
            'lpg-products'
        );

    const lpgProductList =
        document.getElementById(
            'lpg-product-list'
        );

    const lpgEmptyMessage =
        document.getElementById(
            'lpg-empty-message'
        );

    const addLpgButton =
        document.getElementById(
            'add-lpg'
        );

    let lpgIndex = 0;

    function updateLpgSection() {

        if (lpgCheckbox.checked) {

            lpgProducts.classList.add(
                'active'
            );

        } else {

            lpgProducts.classList.remove(
                'active'
            );
        }
    }

    function updateLpgEmptyMessage() {

        const rows =
            lpgProductList.querySelectorAll(
                '.lpg-product-row'
            );

        lpgEmptyMessage.style.display =
            rows.length === 0
                ? 'block'
                : 'none';
    }

    function addLpgProduct() {

        const row =
            document.createElement('div');

        row.className =
            'lpg-product-row';

        row.innerHTML = `

            <div class="form-group">

                <label>
                    LPG Size (kg)
                </label>

                <input
                    type="number"
                    name="lpg_products[${lpgIndex}][kg]"
                    min="0.01"
                    max="9999.99"
                    step="0.01"
                    required
                    placeholder="e.g. 11"
                >

            </div>

            <div class="form-group">

                <label>
                    Price (₱)
                </label>

                <input
                    type="number"
                    name="lpg_products[${lpgIndex}][price]"
                    min="0"
                    max="999999.99"
                    step="0.01"
                    required
                    placeholder="e.g. 770.00"
                >

            </div>

            <button
                type="button"
                class="remove-lpg"
            >
                Remove
            </button>

        `;

        row
            .querySelector('.remove-lpg')
            .addEventListener(
                'click',
                function () {

                    row.remove();

                    updateLpgEmptyMessage();
                }
            );

        lpgProductList.appendChild(row);

        lpgIndex++;

        updateLpgEmptyMessage();
    }

    lpgCheckbox.addEventListener(
        'change',
        function () {

            updateLpgSection();

            if (
                lpgCheckbox.checked &&
                lpgProductList.children.length === 0
            ) {

                addLpgProduct();
            }
        }
    );

    addLpgButton.addEventListener(
        'click',
        addLpgProduct
    );

    /*
    |--------------------------------------------------------------------------
    | FORM VALIDATION
    |--------------------------------------------------------------------------
    */

    document
        .getElementById('station-form')
        .addEventListener(
            'submit',
            function (event) {

                if (
                    latitudeInput.value === '' ||
                    longitudeInput.value === ''
                ) {

                    event.preventDefault();

                    setLocationStatus(
                        'Please select your station location on the map.',
                        'error'
                    );

                    alert(
                        'Please select your station location on the map.'
                    );

                    return;
                }

                if (
                    lpgCheckbox.checked &&
                    lpgProductList.children.length === 0
                ) {

                    event.preventDefault();

                    alert(
                        'Please add at least one LPG size and price.'
                    );

                    return;
                }

                if (lpgCheckbox.checked) {

                    const kgInputs =
                        lpgProductList.querySelectorAll(
                            'input[name*="[kg]"]'
                        );

                    const sizes =
                        new Set();

                    for (
                        const input of kgInputs
                    ) {

                        const value =
                            parseFloat(
                                input.value
                            );

                        if (
                            Number.isNaN(value)
                        ) {
                            continue;
                        }

                        const normalized =
                            value.toFixed(2);

                        if (
                            sizes.has(normalized)
                        ) {

                            event.preventDefault();

                            alert(
                                'Each LPG size can only be added once.'
                            );

                            return;
                        }

                        sizes.add(
                            normalized
                        );
                    }
                }
            }
        );

    updateLpgSection();
    updateLpgEmptyMessage();

    setTimeout(
        function () {
            map.invalidateSize();
        },
        300
    );

});

</script>

</body>
</html>