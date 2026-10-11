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
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];


/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function cleanString(
    mixed $value,
    int $maxLength = 255
): string {

    $value = trim((string) $value);

    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
}


function validatePrice(
    mixed $value,
    string $fieldName
): ?float {

    $value = trim((string) $value);

    /*
     * Empty price is allowed.
     */
    if ($value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        throw new RuntimeException(
            $fieldName . ' must be a valid number.'
        );
    }

    $price = (float) $value;

    if ($price < 0) {
        throw new RuntimeException(
            $fieldName . ' cannot be negative.'
        );
    }

    if ($price > 999999.99) {
        throw new RuntimeException(
            $fieldName . ' is too large.'
        );
    }

    return round($price, 2);
}


/*
|--------------------------------------------------------------------------
| GET CURRENT MANAGER'S STATION
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM gas_stations
    WHERE manager_id = ?
    LIMIT 1
");

$stmt->execute([
    $managerId
]);

$station = $stmt->fetch(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| NO STATION
|--------------------------------------------------------------------------
*/

if (!$station) {

    die(
        '<!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>No Station - GasGo</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    background:#f5f7fa;
                    display:flex;
                    align-items:center;
                    justify-content:center;
                    min-height:100vh;
                    margin:0;
                }

                .box {
                    background:#fff;
                    padding:30px;
                    border-radius:15px;
                    box-shadow:0 5px 25px rgba(0,0,0,.08);
                    text-align:center;
                    max-width:450px;
                }

                a {
                    display:inline-block;
                    margin-top:15px;
                    text-decoration:none;
                    background:#dc2626;
                    color:#fff;
                    padding:12px 20px;
                    border-radius:8px;
                    font-weight:bold;
                }
            </style>
        </head>
        <body>
            <div class="box">
                <h2>No approved station found.</h2>
                <p>
                    You currently do not have an approved gas station
                    assigned to your manager account.
                </p>
                <a href="dashboard.php">Back to Dashboard</a>
            </div>
        </body>
        </html>'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| MESSAGE VARIABLES
|--------------------------------------------------------------------------
*/

$message = '';
$error = '';


/*
|--------------------------------------------------------------------------
| FORM SUBMISSION
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | CSRF VALIDATION
    |--------------------------------------------------------------------------
    */

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($submittedToken) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $submittedToken
        )
    ) {

        $error =
            'Invalid security token. Please refresh the page and try again.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | CLEAN FORM DATA
        |--------------------------------------------------------------------------
        */

        $stationName = cleanString(
            $_POST['station_name'] ?? '',
            150
        );

        $address = cleanString(
            $_POST['address'] ?? '',
            255
        );

        $barangay = cleanString(
            $_POST['barangay'] ?? '',
            120
        );

        $description = cleanString(
            $_POST['description'] ?? '',
            1000
        );

        $workingDays = cleanString(
            $_POST['working_days'] ?? '',
            120
        );

        $operatingHours = cleanString(
            $_POST['operating_hours'] ?? '',
            120
        );

        $contactNumber = cleanString(
            $_POST['contact_number'] ?? '',
            40
        );

        $latitudeRaw = trim((string)($_POST['latitude'] ?? ''));
        $longitudeRaw = trim((string)($_POST['longitude'] ?? ''));
        $latitude = null;
        $longitude = null;


        /*
        |--------------------------------------------------------------------------
        | VALIDATE REQUIRED FIELDS
        |--------------------------------------------------------------------------
        */

        if ($stationName === '') {

            $error =
                'Station name is required.';

        } elseif ($address === '') {

            $error =
                'Address is required.';

        } elseif ($barangay === '') {

            $error =
                'Barangay is required.';

        } elseif ($workingDays === '') {

            $error =
                'Working days are required.';

        } elseif ($operatingHours === '') {

            $error =
                'Operating hours are required.';

        } elseif ($latitudeRaw === '' || !is_numeric($latitudeRaw)) {

            $error = 'Please choose a valid station location on the map.';

        } elseif ($longitudeRaw === '' || !is_numeric($longitudeRaw)) {

            $error = 'Please choose a valid station location on the map.';

        } else {

            $latitude = round((float)$latitudeRaw, 7);
            $longitude = round((float)$longitudeRaw, 7);

            if ($latitude < -90 || $latitude > 90) {
                $error = 'Latitude must be between -90 and 90.';
            } elseif ($longitude < -180 || $longitude > 180) {
                $error = 'Longitude must be between -180 and 180.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | VALIDATE FUEL PRICES
        |--------------------------------------------------------------------------
        */

        $dieselPrice = null;
        $unleadedPrice = null;
        $premiumPrice = null;

        if ($error === '') {

            try {

                $dieselPrice = validatePrice(
                    $_POST['diesel_price'] ?? '',
                    'Diesel price'
                );

                $unleadedPrice = validatePrice(
                    $_POST['unleaded_price'] ?? '',
                    'Unleaded price'
                );

                $premiumPrice = validatePrice(
                    $_POST['premium_price'] ?? '',
                    'Premium price'
                );

            } catch (Throwable $e) {

                $error = $e->getMessage();
            }
        }


        /*
        |--------------------------------------------------------------------------
        | IMAGE VARIABLES
        |--------------------------------------------------------------------------
        */

        $newImageName = null;
        $newImagePath = null;

        $oldImageName =
            trim((string)($station['image'] ?? ''));


        /*
        |--------------------------------------------------------------------------
        | IMAGE UPLOAD
        |--------------------------------------------------------------------------
        */

        if ($error === '') {

            if (
                isset($_FILES['image']) &&
                is_array($_FILES['image'])
            ) {

                $uploadError =
                    $_FILES['image']['error'] ??
                    UPLOAD_ERR_NO_FILE;


                /*
                |--------------------------------------------------------------------------
                | NO NEW IMAGE
                |--------------------------------------------------------------------------
                */

                if (
                    $uploadError !==
                    UPLOAD_ERR_NO_FILE
                ) {

                    /*
                    |--------------------------------------------------------------------------
                    | UPLOAD ERROR
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $uploadError !==
                        UPLOAD_ERR_OK
                    ) {

                        $error =
                            'There was a problem uploading the station image.';

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | FILE SIZE
                        |--------------------------------------------------------------------------
                        */

                        $fileSize =
                            (int)(
                                $_FILES['image']['size'] ??
                                0
                            );

                        if (
                            $fileSize <= 0
                        ) {

                            $error =
                                'The uploaded image is empty.';

                        } elseif (
                            $fileSize >
                            5 * 1024 * 1024
                        ) {

                            $error =
                                'The station image must not exceed 5 MB.';

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | TEMPORARY FILE
                        |--------------------------------------------------------------------------
                        */

                        $tmpName =
                            $_FILES['image']['tmp_name'] ??
                            '';

                        if (
                            $error === '' &&
                            (
                                $tmpName === '' ||
                                !is_uploaded_file(
                                    $tmpName
                                )
                            )
                        ) {

                            $error =
                                'Invalid image upload.';
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | MIME VALIDATION
                        |--------------------------------------------------------------------------
                        */

                        if ($error === '') {

                            $finfo =
                                new finfo(
                                    FILEINFO_MIME_TYPE
                                );

                            $mimeType =
                                $finfo->file(
                                    $tmpName
                                );

                            $allowedMimeTypes = [
                                'image/jpeg' => 'jpg',
                                'image/png'  => 'png',
                                'image/webp' => 'webp'
                            ];

                            if (
                                !isset(
                                    $allowedMimeTypes[
                                        $mimeType
                                    ]
                                )
                            ) {

                                $error =
                                    'Only JPG, PNG, and WEBP images are allowed.';

                            } else {

                                /*
                                |--------------------------------------------------------------------------
                                | IMAGE CONTENT VALIDATION
                                |--------------------------------------------------------------------------
                                */

                                $imageInfo =
                                    @getimagesize(
                                        $tmpName
                                    );

                                if (
                                    $imageInfo === false
                                ) {

                                    $error =
                                        'The uploaded file is not a valid image.';

                                } else {

                                    /*
                                    |--------------------------------------------------------------------------
                                    | CREATE UPLOAD DIRECTORY
                                    |--------------------------------------------------------------------------
                                    */

                                    $uploadDirectory =
                                        __DIR__ .
                                        '/../../uploads/stations/';

                                    if (
                                        !is_dir(
                                            $uploadDirectory
                                        )
                                    ) {

                                        if (
                                            !mkdir(
                                                $uploadDirectory,
                                                0755,
                                                true
                                            )
                                        ) {

                                            $error =
                                                'Unable to create the station image directory.';
                                        }
                                    }


                                    /*
                                    |--------------------------------------------------------------------------
                                    | GENERATE NEW IMAGE NAME
                                    |--------------------------------------------------------------------------
                                    */

                                    if ($error === '') {

                                        $extension =
                                            $allowedMimeTypes[
                                                $mimeType
                                            ];

                                        try {

                                            $newImageName =
                                                bin2hex(
                                                    random_bytes(20)
                                                ) .
                                                '.' .
                                                $extension;

                                        } catch (Throwable $e) {

                                            $error =
                                                'Unable to generate a secure image filename.';
                                        }
                                    }


                                    /*
                                    |--------------------------------------------------------------------------
                                    | MOVE IMAGE
                                    |--------------------------------------------------------------------------
                                    */

                                    if (
                                        $error === '' &&
                                        $newImageName !== null
                                    ) {

                                        $newImagePath =
                                            $uploadDirectory .
                                            $newImageName;

                                        if (
                                            !move_uploaded_file(
                                                $tmpName,
                                                $newImagePath
                                            )
                                        ) {

                                            $error =
                                                'Unable to save the uploaded station image.';

                                            $newImageName =
                                                null;

                                            $newImagePath =
                                                null;

                                        } else {

                                            @chmod(
                                                $newImagePath,
                                                0644
                                            );
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | UPDATE DATABASE
        |--------------------------------------------------------------------------
        */

        if ($error === '') {

            try {

                $pdo->beginTransaction();


                /*
                |--------------------------------------------------------------------------
                | LOCK AND VERIFY STATION OWNERSHIP
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM gas_stations
                    WHERE id = ?
                      AND manager_id = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([
                    (int)$station['id'],
                    $managerId
                ]);

                $lockedStation =
                    $stmt->fetch(PDO::FETCH_ASSOC);


                if (!$lockedStation) {

                    throw new RuntimeException(
                        'You are not authorized to edit this station.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | DETERMINE IMAGE VALUE
                |--------------------------------------------------------------------------
                */

                if ($newImageName !== null) {

                    $imageValue =
                        $newImageName;

                } else {

                    /*
                    * Keep the existing image.
                    */
                    $imageValue =
                        $lockedStation['image'] ?? null;
                }


                /*
                |--------------------------------------------------------------------------
                | UPDATE STATION
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE gas_stations
                    SET
                        name = ?,
                        address = ?,
                        barangay = ?,
                        description = ?,
                        working_days = ?,
                        operating_hours = ?,
                        contact_number = ?,
                        diesel_price = ?,
                        unleaded_price = ?,
                        premium_price = ?,
                        latitude = ?,
                        longitude = ?,
                        image = ?
                    WHERE id = ?
                      AND manager_id = ?
                ");

                $stmt->execute([
                    $stationName,

                    $address,

                    $barangay,

                    $description !== ''
                        ? $description
                        : null,

                    $workingDays,

                    $operatingHours,

                    $contactNumber !== ''
                        ? $contactNumber
                        : null,

                    $dieselPrice,

                    $unleadedPrice,

                    $premiumPrice,

                    $latitude,

                    $longitude,

                    $imageValue,

                    (int)$lockedStation['id'],

                    $managerId
                ]);


                /*
                |--------------------------------------------------------------------------
                | COMMIT
                |--------------------------------------------------------------------------
                */

                $pdo->commit();


                /*
                |--------------------------------------------------------------------------
                | DELETE OLD IMAGE AFTER SUCCESSFUL DATABASE UPDATE
                |--------------------------------------------------------------------------
                */

                if (
                    $newImageName !== null &&
                    $oldImageName !== '' &&
                    $oldImageName !== $newImageName
                ) {

                    /*
                    * Only use the basename so a database value
                    * cannot escape the upload directory.
                    */
                    $safeOldImage =
                        basename(
                            $oldImageName
                        );

                    $oldImagePath =
                        __DIR__ .
                        '/../../uploads/stations/' .
                        $safeOldImage;

                    if (
                        is_file(
                            $oldImagePath
                        )
                    ) {

                        @unlink(
                            $oldImagePath
                        );
                    }
                }


                /*
                |--------------------------------------------------------------------------
                | UPDATE LOCAL STATION DATA
                |--------------------------------------------------------------------------
                */

                $station['name'] =
                    $stationName;

                $station['address'] =
                    $address;

                $station['barangay'] =
                    $barangay;

                $station['description'] =
                    $description;

                $station['working_days'] =
                    $workingDays;

                $station['operating_hours'] =
                    $operatingHours;

                $station['contact_number'] =
                    $contactNumber;

                $station['diesel_price'] =
                    $dieselPrice;

                $station['unleaded_price'] =
                    $unleadedPrice;

                $station['premium_price'] =
                    $premiumPrice;

                $station['latitude'] =
                    $latitude;

                $station['longitude'] =
                    $longitude;

                $station['image'] =
                    $imageValue;


                $message =
                    'Station information, map location, working days, operating hours, prices, and image updated successfully.';


            } catch (Throwable $e) {

                /*
                |--------------------------------------------------------------------------
                | ROLLBACK
                |--------------------------------------------------------------------------
                */

                if (
                    $pdo->inTransaction()
                ) {

                    $pdo->rollBack();
                }


                /*
                |--------------------------------------------------------------------------
                | DELETE NEW IMAGE IF DATABASE UPDATE FAILED
                |--------------------------------------------------------------------------
                */

                if (
                    $newImagePath !== null &&
                    is_file(
                        $newImagePath
                    )
                ) {

                    @unlink(
                        $newImagePath
                    );
                }


                error_log(
                    'GasGo station update error: ' .
                    $e->getMessage()
                );

                $error =
                    'Unable to update the station. Please try again.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | DELETE NEW IMAGE IF VALIDATION FAILED
        |--------------------------------------------------------------------------
        */

        if (
            $error !== '' &&
            $newImagePath !== null &&
            is_file($newImagePath)
        ) {

            @unlink(
                $newImagePath
            );
        }
    }
}


/*
|--------------------------------------------------------------------------
| CURRENT IMAGE URL
|--------------------------------------------------------------------------
*/

$currentImage = '';

if (
    !empty($station['image'])
) {

    $safeImage =
        basename(
            (string)$station['image']
        );

    $currentImage =
        '../../uploads/stations/' .
        rawurlencode($safeImage);
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

<title>Manage Station - GasGo</title>

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
    background: #f5f7fa;
    color: #1f2937;
    font-family: Arial, Helvetica, sans-serif;
}

.page {
    max-width: 900px;
    margin: auto;
    padding: 30px 20px 50px;
}

.back {
    display: inline-block;
    margin-bottom: 20px;
    text-decoration: none;
    color: #333;
    font-weight: 700;
}

.card {
    background: #fff;
    padding: 30px;
    border-radius: 16px;
    box-shadow: 0 5px 25px rgba(0,0,0,.07);
}

h1 {
    margin-top: 0;
    margin-bottom: 8px;
}

.subtitle {
    color: #6b7280;
    margin-top: 0;
    margin-bottom: 25px;
}

.form-section {
    margin-top: 30px;
    padding-top: 25px;
    border-top: 1px solid #e5e7eb;
}

.form-section h2 {
    margin-top: 0;
    font-size: 20px;
}

.form-group {
    margin-bottom: 18px;
}

label {
    display: block;
    margin-bottom: 7px;
    font-weight: 700;
}

input,
textarea {
    width: 100%;
    padding: 12px 13px;
    border: 1px solid #d1d5db;
    border-radius: 9px;
    font-family: inherit;
    font-size: 15px;
    outline: none;
}

input:focus,
textarea:focus {
    border-color: #dc2626;
    box-shadow: 0 0 0 3px rgba(220,38,38,.1);
}

textarea {
    min-height: 120px;
    resize: vertical;
}

.price-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.price-box {
    background: #f9fafb;
    padding: 15px;
    border-radius: 10px;
}

.price-box label {
    font-size: 14px;
}

.price-input {
    position: relative;
}

.price-input span {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6b7280;
    font-weight: 700;
}

.price-input input {
    padding-left: 30px;
}

.current-image {
    margin-bottom: 18px;
}

.current-image-label {
    font-weight: 700;
    margin-bottom: 8px;
}

.image-preview {
    width: 100%;
    max-width: 420px;
    height: 240px;
    object-fit: cover;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    display: block;
    background: #f3f4f6;
}

.no-image {
    width: 100%;
    max-width: 420px;
    height: 240px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f3f4f6;
    color: #6b7280;
    border-radius: 12px;
    border: 1px dashed #d1d5db;
}

.file-help {
    display: block;
    margin-top: 7px;
    color: #6b7280;
    font-size: 13px;
}

.message {
    padding: 13px 15px;
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
    border-radius: 9px;
    margin-bottom: 20px;
}

.error {
    padding: 13px 15px;
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
    border-radius: 9px;
    margin-bottom: 20px;
}

.save-button {
    width: 100%;
    padding: 14px 18px;
    border: none;
    border-radius: 9px;
    background: #dc2626;
    color: white;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    margin-top: 10px;
}

.save-button:hover {
    background: #b91c1c;
}

.note {
    padding: 12px 14px;
    background: #f3f4f6;
    border-radius: 9px;
    color: #4b5563;
    font-size: 14px;
    margin-top: 15px;
}

.station-location-map {
    width: 100%;
    height: 390px;
    border-radius: 12px;
    border: 1px solid #d1d5db;
    overflow: hidden;
    margin-top: 10px;
}

.coordinate-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-top: 15px;
}

.coordinate-grid input {
    background: #f9fafb;
}

@media (max-width: 700px) {

    .card {
        padding: 20px;
    }

    .price-grid,
    .coordinate-grid {
        grid-template-columns: 1fr;
    }

    .station-location-map {
        height: 320px;
    }

    .image-preview,
    .no-image {
        max-width: 100%;
        height: 210px;
    }

}

</style>

</head>

<body>

<div class="page">

<a
    href="dashboard.php"
    class="back"
>
    ← Back to Dashboard
</a>


<div class="card">

<h1>
    Manage My Station
</h1>

<p class="subtitle">
    Update your station information, fuel prices, and station image.
</p>


<?php if ($message !== ''): ?>

<div class="message">
    <?= htmlspecialchars($message) ?>
</div>

<?php endif; ?>


<?php if ($error !== ''): ?>

<div class="error">
    <?= htmlspecialchars($error) ?>
</div>

<?php endif; ?>


<form
    method="post"
    enctype="multipart/form-data"
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= htmlspecialchars($csrfToken) ?>"
>


<!--
|--------------------------------------------------------------------------
| STATION INFORMATION
|--------------------------------------------------------------------------
-->

<div class="form-section" style="border-top:none; padding-top:0;">

<h2>
    Station Information
</h2>


<div class="form-group">

<label for="station_name">
    Station Name
</label>

<input
    type="text"
    id="station_name"
    name="station_name"
    maxlength="150"
    required
    value="<?= htmlspecialchars(
        (string)($station['name'] ?? '')
    ) ?>"
>

</div>


<div class="form-group">

<label for="address">
    Address
</label>

<input
    type="text"
    id="address"
    name="address"
    maxlength="255"
    required
    value="<?= htmlspecialchars(
        (string)($station['address'] ?? '')
    ) ?>"
>

</div>


<div class="form-group">

<label for="barangay">
    Barangay
</label>

<input
    type="text"
    id="barangay"
    name="barangay"
    maxlength="120"
    required
    value="<?= htmlspecialchars(
        (string)($station['barangay'] ?? '')
    ) ?>"
>

</div>


<div class="form-group">

<label for="description">
    Description
</label>

<textarea
    id="description"
    name="description"
    maxlength="1000"
><?= htmlspecialchars(
    (string)($station['description'] ?? '')
) ?></textarea>

</div>


<div class="form-group">

<label for="working_days">
    Working Days
</label>

<input
    type="text"
    id="working_days"
    name="working_days"
    maxlength="120"
    required
    placeholder="Example: Monday-Saturday"
    value="<?= htmlspecialchars(
        (string)($station['working_days'] ?? '')
    ) ?>"
>

<span class="file-help">
    Example: Monday-Friday, Monday-Saturday, or Monday-Sunday.
</span>

</div>


<div class="form-group">

<label for="operating_hours">
    Operating Hours
</label>

<input
    type="text"
    id="operating_hours"
    name="operating_hours"
    maxlength="120"
    required
    placeholder="Example: 6:00 AM - 10:00 PM"
    value="<?= htmlspecialchars(
        (string)($station['operating_hours'] ?? '')
    ) ?>"
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
    value="<?= htmlspecialchars(
        (string)($station['contact_number'] ?? '')
    ) ?>"
>

</div>

</div>


<!--
|--------------------------------------------------------------------------
| STATION LOCATION
|--------------------------------------------------------------------------
-->

<div class="form-section">

<h2>
    Station Location
</h2>

<p class="subtitle">
    Click anywhere on the map or drag the marker to the exact gasoline station location.
</p>

<div
    id="stationLocationMap"
    class="station-location-map"
    aria-label="Edit gasoline station location"
></div>

<div class="coordinate-grid">
    <div class="form-group">
        <label for="latitude">Latitude</label>
        <input
            type="text"
            id="latitude"
            name="latitude"
            readonly
            required
            value="<?= htmlspecialchars((string)($station['latitude'] ?? '')) ?>"
        >
    </div>

    <div class="form-group">
        <label for="longitude">Longitude</label>
        <input
            type="text"
            id="longitude"
            name="longitude"
            readonly
            required
            value="<?= htmlspecialchars((string)($station['longitude'] ?? '')) ?>"
        >
    </div>
</div>

<div class="note">
    The saved marker location is what users will see on the GasGo public map.
</div>

</div>


<!--
|--------------------------------------------------------------------------
| FUEL PRICES
|--------------------------------------------------------------------------
-->

<div class="form-section">

<h2>
    Fuel Prices
</h2>

<p class="subtitle">
    Update the current selling price of each fuel type.
</p>


<div class="price-grid">


<div class="price-box">

<label for="diesel_price">
    Diesel
</label>

<div class="price-input">

<span>₱</span>

<input
    type="number"
    id="diesel_price"
    name="diesel_price"
    min="0"
    max="999999.99"
    step="0.01"
    placeholder="0.00"
    value="<?= (
        $station['diesel_price'] !== null &&
        $station['diesel_price'] !== ''
    )
        ? htmlspecialchars(
            number_format(
                (float)$station['diesel_price'],
                2,
                '.',
                ''
            )
        )
        : ''
    ?>"
>

</div>

</div>


<div class="price-box">

<label for="unleaded_price">
    Unleaded
</label>

<div class="price-input">

<span>₱</span>

<input
    type="number"
    id="unleaded_price"
    name="unleaded_price"
    min="0"
    max="999999.99"
    step="0.01"
    placeholder="0.00"
    value="<?= (
        $station['unleaded_price'] !== null &&
        $station['unleaded_price'] !== ''
    )
        ? htmlspecialchars(
            number_format(
                (float)$station['unleaded_price'],
                2,
                '.',
                ''
            )
        )
        : ''
    ?>"
>

</div>

</div>


<div class="price-box">

<label for="premium_price">
    Premium
</label>

<div class="price-input">

<span>₱</span>

<input
    type="number"
    id="premium_price"
    name="premium_price"
    min="0"
    max="999999.99"
    step="0.01"
    placeholder="0.00"
    value="<?= (
        $station['premium_price'] !== null &&
        $station['premium_price'] !== ''
    )
        ? htmlspecialchars(
            number_format(
                (float)$station['premium_price'],
                2,
                '.',
                ''
            )
        )
        : ''
    ?>"
>

</div>

</div>


</div>

<div class="note">
    Updating prices here changes the prices displayed for your station.
    Fuel availability is not changed by this page.
</div>

</div>


<!--
|--------------------------------------------------------------------------
| STATION IMAGE
|--------------------------------------------------------------------------
-->

<div class="form-section">

<h2>
    Station Picture
</h2>


<div class="current-image">

<div class="current-image-label">
    Current Station Picture
</div>


<?php if ($currentImage !== ''): ?>

<img
    src="<?= htmlspecialchars($currentImage) ?>"
    alt="Current station picture"
    class="image-preview"
    id="imagePreview"
>

<?php else: ?>

<div
    class="no-image"
    id="noImage"
>
    No station picture uploaded.
</div>

<img
    src=""
    alt=""
    class="image-preview"
    id="imagePreview"
    style="display:none;"
>

<?php endif; ?>

</div>


<div class="form-group">

<label for="image">
    Replace Station Picture
</label>

<input
    type="file"
    id="image"
    name="image"
    accept="image/jpeg,image/png,image/webp"
>

<span class="file-help">
    Accepted formats: JPG, PNG, WEBP. Maximum size: 5 MB.
</span>

</div>


</div>


<button
    type="submit"
    class="save-button"
>
    Save Station Changes
</button>


</form>

</div>

</div>


<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>

/*
|--------------------------------------------------------------------------
| EDITABLE STATION LOCATION MAP
|--------------------------------------------------------------------------
*/

const locationMapElement = document.getElementById('stationLocationMap');
const latitudeInput = document.getElementById('latitude');
const longitudeInput = document.getElementById('longitude');

if (locationMapElement && latitudeInput && longitudeInput && typeof L !== 'undefined') {
    const fallbackCenter = [9.8487, 126.0458];
    const savedLat = parseFloat(latitudeInput.value);
    const savedLng = parseFloat(longitudeInput.value);
    const hasSavedLocation = Number.isFinite(savedLat) && Number.isFinite(savedLng);
    const initialPosition = hasSavedLocation ? [savedLat, savedLng] : fallbackCenter;

    const locationMap = L.map('stationLocationMap', { zoomControl: true }).setView(
        initialPosition,
        hasSavedLocation ? 16 : 11
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

    streetLayer.addTo(locationMap);

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
    ).addTo(locationMap);

    const locationMarker = L.marker(initialPosition, { draggable: true }).addTo(locationMap);

    function saveMarkerPosition(latlng) {
        latitudeInput.value = Number(latlng.lat).toFixed(7);
        longitudeInput.value = Number(latlng.lng).toFixed(7);
        locationMarker.setLatLng(latlng);
    }

    locationMarker.on('dragend', function (event) {
        saveMarkerPosition(event.target.getLatLng());
    });

    locationMap.on('click', function (event) {
        saveMarkerPosition(event.latlng);
    });

    setTimeout(function () { locationMap.invalidateSize(); }, 100);
}

/*
|--------------------------------------------------------------------------
| IMAGE PREVIEW
|--------------------------------------------------------------------------
*/

const imageInput =
    document.getElementById('image');

const imagePreview =
    document.getElementById('imagePreview');

const noImage =
    document.getElementById('noImage');


if (imageInput) {

    imageInput.addEventListener(
        'change',
        function () {

            const file =
                this.files &&
                this.files[0];

            if (!file) {
                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Client-side size check
            |--------------------------------------------------------------------------
            */

            if (
                file.size >
                5 * 1024 * 1024
            ) {

                alert(
                    'The station image must not exceed 5 MB.'
                );

                this.value = '';

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Client-side type check
            |--------------------------------------------------------------------------
            */

            const allowedTypes = [
                'image/jpeg',
                'image/png',
                'image/webp'
            ];

            if (
                !allowedTypes.includes(
                    file.type
                )
            ) {

                alert(
                    'Only JPG, PNG, and WEBP images are allowed.'
                );

                this.value = '';

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Show preview
            |--------------------------------------------------------------------------
            */

            const reader =
                new FileReader();

            reader.onload =
                function (event) {

                    if (imagePreview) {

                        imagePreview.src =
                            event.target.result;

                        imagePreview.style.display =
                            'block';
                    }

                    if (noImage) {

                        noImage.style.display =
                            'none';
                    }
                };

            reader.readAsDataURL(file);
        }
    );
}

</script>

</body>

</html>