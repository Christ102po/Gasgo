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
| ONLY POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: request.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$sessionToken =
    $_SESSION['csrf_token'] ?? '';

$postToken =
    $_POST['csrf_token'] ?? '';

if (
    $sessionToken === '' ||
    $postToken === '' ||
    !hash_equals(
        $sessionToken,
        (string) $postToken
    )
) {

    header(
        'Location: request.php?error=' .
        urlencode(
            'Your session has expired. Please refresh the page and try again.'
        )
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function redirectError(string $message): never
{
    header(
        'Location: request.php?error=' .
        urlencode($message)
    );

    exit;
}

function redirectSuccess(string $message): never
{
    header(
        'Location: dashboard.php?success=' .
        urlencode($message)
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| BASIC DATABASE CHECK
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | CHECK MANAGER
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id
        FROM managers
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $managerId
    ]);

    $manager = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$manager) {

        session_unset();
        session_destroy();

        header('Location: login.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK APPROVED STATION
    |
    | gas_stations uses "name".
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            name
        FROM gas_stations
        WHERE manager_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $managerId
    ]);

    $existingStation =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingStation) {

        redirectError(
            'You already have an approved station. You cannot submit another station.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CHECK ACTIVE REQUEST
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            status
        FROM station_requests
        WHERE manager_id = ?
          AND status IN ('Pending', 'Needs changes')
        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $managerId
    ]);

    $activeRequest =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activeRequest) {

        $status =
            strtolower(
                trim(
                    (string) $activeRequest['status']
                )
            );

        if ($status === 'needs changes') {

            redirectError(
                'Your existing request needs changes. Please edit your existing request instead of creating another one.'
            );
        }

        redirectError(
            'You already have a pending station request. Please wait for admin review.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GET POST DATA
    |--------------------------------------------------------------------------
    */

    $stationName =
        trim(
            (string) (
                $_POST['station_name'] ?? ''
            )
        );

    $address =
        trim(
            (string) (
                $_POST['address'] ?? ''
            )
        );

    $barangay =
        trim(
            (string) (
                $_POST['barangay'] ?? ''
            )
        );

    $description =
        trim(
            (string) (
                $_POST['description'] ?? ''
            )
        );

    $operatingHours =
        trim(
            (string) (
                $_POST['operating_hours'] ?? ''
            )
        );

    $contactNumber =
        trim(
            (string) (
                $_POST['contact_number'] ?? ''
            )
        );

    /*
    |--------------------------------------------------------------------------
    | REQUIRED FIELDS
    |--------------------------------------------------------------------------
    */

    if ($stationName === '') {

        redirectError(
            'Station name is required.'
        );
    }

    if (mb_strlen($stationName) > 150) {

        redirectError(
            'Station name must not exceed 150 characters.'
        );
    }

    if ($address === '') {

        redirectError(
            'Complete address is required.'
        );
    }

    if (mb_strlen($address) > 255) {

        redirectError(
            'Address must not exceed 255 characters.'
        );
    }

    if ($barangay === '') {

        redirectError(
            'Barangay is required.'
        );
    }

    if (mb_strlen($barangay) > 120) {

        redirectError(
            'Barangay must not exceed 120 characters.'
        );
    }

    if ($operatingHours === '') {

        redirectError(
            'Operating hours are required.'
        );
    }

    if (mb_strlen($operatingHours) > 120) {

        redirectError(
            'Operating hours must not exceed 120 characters.'
        );
    }

    if (mb_strlen($description) > 10000) {

        redirectError(
            'Description is too long.'
        );
    }

    if (mb_strlen($contactNumber) > 40) {

        redirectError(
            'Contact number must not exceed 40 characters.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | COORDINATES
    |--------------------------------------------------------------------------
    */

    $latitudeRaw =
        trim(
            (string) (
                $_POST['latitude'] ?? ''
            )
        );

    $longitudeRaw =
        trim(
            (string) (
                $_POST['longitude'] ?? ''
            )
        );

    if (
        $latitudeRaw === '' ||
        $longitudeRaw === ''
    ) {

        redirectError(
            'Please select the exact location of your station on the map.'
        );
    }

    if (
        !is_numeric($latitudeRaw) ||
        !is_numeric($longitudeRaw)
    ) {

        redirectError(
            'The station coordinates are invalid.'
        );
    }

    $latitude =
        (float) $latitudeRaw;

    $longitude =
        (float) $longitudeRaw;

    /*
    |--------------------------------------------------------------------------
    | SIARGAO SERVICE AREA
    |--------------------------------------------------------------------------
    |
    | These limits are intentionally kept server-side.
    |--------------------------------------------------------------------------
    */

    $minLatitude = 9.65;
    $maxLatitude = 10.05;

    $minLongitude = 125.95;
    $maxLongitude = 126.20;

    if (
        $latitude < $minLatitude ||
        $latitude > $maxLatitude ||
        $longitude < $minLongitude ||
        $longitude > $maxLongitude
    ) {

        redirectError(
            'The selected station location must be within the GasGo Siargao service area.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FUEL PRICES
    |--------------------------------------------------------------------------
    */

    function nullablePrice(
        mixed $value,
        string $fieldName
    ): ?float {

        if (
            $value === null ||
            trim((string) $value) === ''
        ) {
            return null;
        }

        if (!is_numeric($value)) {

            redirectError(
                $fieldName .
                ' must be a valid number.'
            );
        }

        $price =
            (float) $value;

        if ($price < 0) {

            redirectError(
                $fieldName .
                ' cannot be negative.'
            );
        }

        if ($price > 999999.99) {

            redirectError(
                $fieldName .
                ' is too large.'
            );
        }

        return round(
            $price,
            2
        );
    }

    $dieselPrice =
        nullablePrice(
            $_POST['diesel_price'] ?? null,
            'Diesel price'
        );

    $unleadedPrice =
        nullablePrice(
            $_POST['unleaded_price'] ?? null,
            'Unleaded price'
        );

    $premiumPrice =
        nullablePrice(
            $_POST['premium_price'] ?? null,
            'Premium price'
        );

    /*
    |--------------------------------------------------------------------------
    | LPG
    |--------------------------------------------------------------------------
    */

    $lpgAvailable =
        isset($_POST['lpg_available']) &&
        (string) $_POST['lpg_available'] === '1';

    $lpgProducts =
        $_POST['lpg_products'] ?? [];

    if (!is_array($lpgProducts)) {
        $lpgProducts = [];
    }

    $cleanLpgProducts = [];

    if ($lpgAvailable) {

        if (count($lpgProducts) === 0) {

            redirectError(
                'Please add at least one LPG size and price.'
            );
        }

        $usedSizes = [];

        foreach (
            $lpgProducts as $index => $product
        ) {

            if (!is_array($product)) {

                redirectError(
                    'Invalid LPG product information.'
                );
            }

            $kgRaw =
                trim(
                    (string) (
                        $product['kg'] ?? ''
                    )
                );

            $priceRaw =
                trim(
                    (string) (
                        $product['price'] ?? ''
                    )
                );

            if (
                $kgRaw === '' ||
                $priceRaw === ''
            ) {

                redirectError(
                    'Every LPG product must have a size and price.'
                );
            }

            if (
                !is_numeric($kgRaw) ||
                !is_numeric($priceRaw)
            ) {

                redirectError(
                    'LPG size and price must be valid numbers.'
                );
            }

            $kg =
                round(
                    (float) $kgRaw,
                    2
                );

            $price =
                round(
                    (float) $priceRaw,
                    2
                );

            if ($kg <= 0) {

                redirectError(
                    'LPG size must be greater than zero.'
                );
            }

            if ($kg > 9999.99) {

                redirectError(
                    'LPG size is too large.'
                );
            }

            if ($price < 0) {

                redirectError(
                    'LPG price cannot be negative.'
                );
            }

            if ($price > 999999.99) {

                redirectError(
                    'LPG price is too large.'
                );
            }

            $sizeKey =
                number_format(
                    $kg,
                    2,
                    '.',
                    ''
                );

            if (
                isset(
                    $usedSizes[$sizeKey]
                )
            ) {

                redirectError(
                    'Each LPG cylinder size can only be added once.'
                );
            }

            $usedSizes[$sizeKey] = true;

            $cleanLpgProducts[] = [
                'kg'    => $kg,
                'price' => $price
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | IMAGE UPLOAD
    |--------------------------------------------------------------------------
    */

    $imageFilename = null;
    $uploadedImagePath = null;

    if (
        isset($_FILES['image']) &&
        is_array($_FILES['image']) &&
        (
            (int) (
                $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE
            )
            !== UPLOAD_ERR_NO_FILE
        )
    ) {

        $uploadError =
            (int) (
                $_FILES['image']['error']
                ?? UPLOAD_ERR_NO_FILE
            );

        if (
            $uploadError !== UPLOAD_ERR_OK
        ) {

            $uploadMessage =
                match ($uploadError) {

                    UPLOAD_ERR_INI_SIZE =>
                        'The uploaded image is larger than the server upload limit.',

                    UPLOAD_ERR_FORM_SIZE =>
                        'The uploaded image is too large.',

                    UPLOAD_ERR_PARTIAL =>
                        'The image upload was incomplete.',

                    UPLOAD_ERR_NO_TMP_DIR =>
                        'The server temporary upload directory is missing.',

                    UPLOAD_ERR_CANT_WRITE =>
                        'The server could not save the uploaded image.',

                    UPLOAD_ERR_EXTENSION =>
                        'A PHP extension stopped the image upload.',

                    default =>
                        'There was a problem uploading the station image.'
                };

            redirectError(
                $uploadMessage
            );
        }

        $tmpFile =
            (string) $_FILES['image']['tmp_name'];

        $originalSize =
            (int) $_FILES['image']['size'];

        /*
        |--------------------------------------------------------------------------
        | MAX 5 MB
        |--------------------------------------------------------------------------
        */

        if ($originalSize > 5 * 1024 * 1024) {

            redirectError(
                'Station image must not exceed 5 MB.'
            );
        }

        if (
            !is_uploaded_file($tmpFile)
        ) {

            redirectError(
                'Invalid image upload.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | VERIFY IMAGE MIME
        |--------------------------------------------------------------------------
        */

        $finfo =
            new finfo(FILEINFO_MIME_TYPE);

        $mimeType =
            $finfo->file($tmpFile);

        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp'
        ];

        if (
            !isset(
                $allowedTypes[$mimeType]
            )
        ) {

            redirectError(
                'Only JPG, PNG, and WEBP images are allowed.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | CREATE UPLOAD DIRECTORY
        |--------------------------------------------------------------------------
        */

        $uploadDirectory =
            __DIR__ . '/../../uploads/stations/';

        if (
            !is_dir($uploadDirectory)
        ) {

            if (
                !mkdir(
                    $uploadDirectory,
                    0755,
                    true
                )
            ) {

                redirectError(
                    'Unable to create the station image upload directory.'
                );
            }
        }

        if (
            !is_writable($uploadDirectory)
        ) {

            redirectError(
                'The station image upload directory is not writable.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | RANDOM SAFE FILENAME
        |--------------------------------------------------------------------------
        */

        try {

            $randomName =
                bin2hex(
                    random_bytes(24)
                );

        } catch (Throwable $e) {

            redirectError(
                'Unable to generate a secure image filename.'
            );
        }

        $imageFilename =
            $randomName .
            '.' .
            $allowedTypes[$mimeType];

        $uploadedImagePath =
            $uploadDirectory .
            $imageFilename;

        /*
        |--------------------------------------------------------------------------
        | MOVE IMAGE
        |--------------------------------------------------------------------------
        */

        if (
            !move_uploaded_file(
                $tmpFile,
                $uploadedImagePath
            )
        ) {

            $imageFilename = null;
            $uploadedImagePath = null;

            redirectError(
                'Unable to save the uploaded station image.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DATABASE TRANSACTION
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | INSERT STATION REQUEST
    |
    | IMPORTANT:
    | station_requests uses station_name.
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        INSERT INTO station_requests (
            manager_id,
            station_name,
            address,
            barangay,
            description,
            latitude,
            longitude,
            operating_hours,
            diesel_price,
            unleaded_price,
            premium_price,
            lpg_available,
            lpg_price,
            contact_number,
            image,
            status,
            admin_feedback,
            created_at,
            updated_at
        )
        VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            NULL,
            ?,
            ?,
            'Pending',
            NULL,
            NOW(),
            NOW()
        )
    ");

    $stmt->execute([

        $managerId,

        $stationName,

        $address,

        $barangay,

        $description !== ''
            ? $description
            : null,

        $latitude,

        $longitude,

        $operatingHours,

        $dieselPrice,

        $unleadedPrice,

        $premiumPrice,

        $lpgAvailable ? 1 : 0,

        $contactNumber !== ''
            ? $contactNumber
            : null,

        $imageFilename
    ]);

    $requestId =
        (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | INSERT LPG PRODUCTS
    |
    | IMPORTANT:
    | station_request_lpg_products DOES NOT HAVE updated_at.
    |--------------------------------------------------------------------------
    */

    if (
        $lpgAvailable &&
        count($cleanLpgProducts) > 0
    ) {

        $lpgStmt = $pdo->prepare("
            INSERT INTO station_request_lpg_products (
                request_id,
                kg,
                price,
                available,
                created_at
            )
            VALUES (
                ?,
                ?,
                ?,
                1,
                NOW()
            )
        ");

        foreach (
            $cleanLpgProducts as $product
        ) {

            $lpgStmt->execute([

                $requestId,

                $product['kg'],

                $product['price']
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $pdo->commit();

    redirectSuccess(
        'Your station request has been submitted successfully and is now pending admin review.'
    );

} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */

    if (
        isset($pdo) &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {

        try {
            $pdo->rollBack();
        } catch (Throwable $rollbackError) {
            error_log(
                'GasGo rollback error: ' .
                $rollbackError->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE UPLOADED IMAGE IF DATABASE INSERT FAILED
    |--------------------------------------------------------------------------
    */

    if (
        isset($uploadedImagePath) &&
        is_string($uploadedImagePath) &&
        $uploadedImagePath !== '' &&
        file_exists($uploadedImagePath)
    ) {

        @unlink(
            $uploadedImagePath
        );
    }

    /*
    |--------------------------------------------------------------------------
    | LOG REAL ERROR
    |--------------------------------------------------------------------------
    */

    error_log(
        'GasGo station request submission error: ' .
        $e->getMessage()
    );

    error_log(
        'GasGo station request submission trace: ' .
        $e->getTraceAsString()
    );

    /*
    |--------------------------------------------------------------------------
    | USER-FRIENDLY ERROR
    |--------------------------------------------------------------------------
    */

    redirectError(
        'Unable to submit your station request. Please check your information and try again.'
    );
}