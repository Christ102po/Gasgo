<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| ADMIN AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['admin_logged_in']) ||
    $_SESSION['admin_logged_in'] !== true
) {
    header('Location: login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/db_connect.php';


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
|
| This handler supports the common GasGo session-based CSRF token.
| If your csrf.php already creates $_SESSION['csrf_token'],
| this will work with it.
|
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$sessionCsrf = $_SESSION['csrf_token'];

$submittedCsrf =
    $_POST['csrf_token']
    ?? $_POST['csrf']
    ?? '';

if (
    !is_string($submittedCsrf) ||
    !hash_equals($sessionCsrf, $submittedCsrf)
) {
    header(
        'Location: dashboard.php?error=' .
        urlencode('Invalid security token. Please refresh the page and try again.')
    );
    exit;
}


/*
|--------------------------------------------------------------------------
| ONLY POST REQUESTS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$action = trim((string)($_POST['action'] ?? ''));
$requestId = filter_input(
    INPUT_POST,
    'request_id',
    FILTER_VALIDATE_INT
);

if (!$requestId) {
    $requestId = filter_input(
        INPUT_POST,
        'id',
        FILTER_VALIDATE_INT
    );
}

$requestId = (int)$requestId;


/*
|--------------------------------------------------------------------------
| VALIDATION
|--------------------------------------------------------------------------
*/

$allowedActions = [
    'approve',
    'reject',
    'needs_changes'
];

if (!in_array($action, $allowedActions, true)) {
    header(
        'Location: dashboard.php?error=' .
        urlencode('Invalid station request action.')
    );
    exit;
}

if ($requestId <= 0) {
    header(
        'Location: dashboard.php?error=' .
        urlencode('Invalid station request ID.')
    );
    exit;
}


/*
|--------------------------------------------------------------------------
| ADMIN FEEDBACK
|--------------------------------------------------------------------------
*/

$feedback = trim(
    (string)(
        $_POST['admin_feedback']
        ?? $_POST['feedback']
        ?? ''
    )
);

if ($action === 'needs_changes' && $feedback === '') {
    header(
        'Location: dashboard.php?error=' .
        urlencode('Please provide feedback describing the changes required.')
    );
    exit;
}

if ($action === 'reject' && $feedback === '') {
    $feedback = 'Station request rejected by administrator.';
}


/*
|--------------------------------------------------------------------------
| HELPER: REDIRECT WITH MESSAGE
|--------------------------------------------------------------------------
*/

function redirectWithMessage(
    string $type,
    string $message
): never {
    header(
        'Location: dashboard.php?' .
        $type .
        '=' .
        urlencode($message)
    );
    exit;
}


/*
|--------------------------------------------------------------------------
| DATABASE TRANSACTION
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | LOCK REQUEST
    |--------------------------------------------------------------------------
    |
    | FOR UPDATE prevents two admins from approving the same request
    | simultaneously.
    |
    */

    $stmt = $pdo->prepare(
        "
        SELECT
            id,
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
            admin_feedback
        FROM station_requests
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
        "
    );

    $stmt->execute([$requestId]);

    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        throw new RuntimeException(
            'Station request was not found.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PREVENT DUPLICATE APPROVAL / INVALID STATE CHANGES
    |--------------------------------------------------------------------------
    */

    $currentStatus = (string)$request['status'];

    if (
        $action === 'approve' &&
        $currentStatus === 'Approved'
    ) {
        throw new RuntimeException(
            'This station request has already been approved.'
        );
    }

    if (
        $action === 'reject' &&
        $currentStatus === 'Approved'
    ) {
        throw new RuntimeException(
            'An approved station cannot be rejected from the request page.'
        );
    }

    if (
        $action === 'needs_changes' &&
        $currentStatus === 'Approved'
    ) {
        throw new RuntimeException(
            'An approved station cannot be marked as needing changes.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | APPROVE
    |--------------------------------------------------------------------------
    */

    if ($action === 'approve') {

        /*
        |--------------------------------------------------------------------------
        | CREATE PUBLIC GAS STATION
        |--------------------------------------------------------------------------
        |
        | These column names exactly match the uploaded gasgo.sql.
        |
        */

        $insertStation = $pdo->prepare(
            "
            INSERT INTO gas_stations (
                manager_id,
                name,
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
                premium_available,
                regular_available,
                diesel_available,
                fuel_prices_updated_at,
                availability_updated_at,
                created_at,
                updated_at
            )
            VALUES (
                :manager_id,
                :name,
                :address,
                :barangay,
                :description,
                :latitude,
                :longitude,
                :operating_hours,
                :diesel_price,
                :unleaded_price,
                :premium_price,
                :lpg_available,
                :lpg_price,
                :contact_number,
                :image,
                'Approved',
                1,
                1,
                1,
                NOW(),
                NOW(),
                NOW(),
                NOW()
            )
            "
        );


        /*
        |--------------------------------------------------------------------------
        | NORMALIZE VALUES
        |--------------------------------------------------------------------------
        */

        $managerId = (int)$request['manager_id'];

        $name = trim((string)$request['station_name']);
        $address = trim((string)$request['address']);
        $barangay = trim((string)$request['barangay']);

        $description =
            $request['description'] !== null
                ? trim((string)$request['description'])
                : null;

        $latitude = (float)$request['latitude'];
        $longitude = (float)$request['longitude'];

        $operatingHours =
            $request['operating_hours'] !== null
                ? trim((string)$request['operating_hours'])
                : null;

        $dieselPrice =
            $request['diesel_price'] !== null
                ? (float)$request['diesel_price']
                : null;

        $unleadedPrice =
            $request['unleaded_price'] !== null
                ? (float)$request['unleaded_price']
                : null;

        $premiumPrice =
            $request['premium_price'] !== null
                ? (float)$request['premium_price']
                : null;

        $lpgAvailable =
            (int)$request['lpg_available'];

        $lpgPrice =
            $request['lpg_price'] !== null
                ? (float)$request['lpg_price']
                : null;

        $contactNumber =
            $request['contact_number'] !== null
                ? trim((string)$request['contact_number'])
                : null;

        $image =
            $request['image'] !== null
                ? trim((string)$request['image'])
                : null;


        /*
        |--------------------------------------------------------------------------
        | BASIC SERVER-SIDE VALIDATION
        |--------------------------------------------------------------------------
        */

        if ($name === '') {
            throw new RuntimeException(
                'Station name cannot be empty.'
            );
        }

        if ($address === '') {
            throw new RuntimeException(
                'Station address cannot be empty.'
            );
        }

        if ($barangay === '') {
            throw new RuntimeException(
                'Barangay cannot be empty.'
            );
        }

        if (
            $latitude < -90 ||
            $latitude > 90 ||
            $longitude < -180 ||
            $longitude > 180
        ) {
            throw new RuntimeException(
                'Invalid station coordinates.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | INSERT STATION
        |--------------------------------------------------------------------------
        */

        $insertStation->execute([
            ':manager_id' => $managerId,
            ':name' => $name,
            ':address' => $address,
            ':barangay' => $barangay,
            ':description' => $description,
            ':latitude' => $latitude,
            ':longitude' => $longitude,
            ':operating_hours' => $operatingHours,
            ':diesel_price' => $dieselPrice,
            ':unleaded_price' => $unleadedPrice,
            ':premium_price' => $premiumPrice,
            ':lpg_available' => $lpgAvailable,
            ':lpg_price' => $lpgPrice,
            ':contact_number' => $contactNumber,
            ':image' => $image
        ]);


        /*
        |--------------------------------------------------------------------------
        | GET NEW STATION ID
        |--------------------------------------------------------------------------
        */

        $stationId = (int)$pdo->lastInsertId();

        if ($stationId <= 0) {
            throw new RuntimeException(
                'The station was not created successfully.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | COPY LPG PRODUCTS
        |--------------------------------------------------------------------------
        |
        | Your database contains:
        |
        | station_request_lpg_products
        | station_lpg_products
        |
        */

        $getLpgProducts = $pdo->prepare(
            "
            SELECT
                kg,
                price,
                available
            FROM station_request_lpg_products
            WHERE request_id = ?
            ORDER BY kg ASC
            "
        );

        $getLpgProducts->execute([
            $requestId
        ]);

        $lpgProducts =
            $getLpgProducts->fetchAll(PDO::FETCH_ASSOC);


        /*
        |--------------------------------------------------------------------------
        | INSERT LPG PRODUCTS
        |--------------------------------------------------------------------------
        */

        if (!empty($lpgProducts)) {

            $insertLpg = $pdo->prepare(
                "
                INSERT INTO station_lpg_products (
                    station_id,
                    kg,
                    price,
                    available,
                    created_at,
                    updated_at
                )
                VALUES (
                    :station_id,
                    :kg,
                    :price,
                    :available,
                    NOW(),
                    NOW()
                )
                "
            );

            foreach ($lpgProducts as $product) {

                $kg = (float)$product['kg'];
                $price = (float)$product['price'];
                $available = (int)$product['available'];

                if ($kg <= 0) {
                    throw new RuntimeException(
                        'Invalid LPG product weight.'
                    );
                }

                if ($price < 0) {
                    throw new RuntimeException(
                        'Invalid LPG product price.'
                    );
                }

                $insertLpg->execute([
                    ':station_id' => $stationId,
                    ':kg' => $kg,
                    ':price' => $price,
                    ':available' => $available ? 1 : 0
                ]);
            }
        }


        /*
        |--------------------------------------------------------------------------
        | UPDATE REQUEST
        |--------------------------------------------------------------------------
        */

        $updateRequest = $pdo->prepare(
            "
            UPDATE station_requests
            SET
                status = 'Approved',
                admin_feedback = ?,
                updated_at = NOW()
            WHERE id = ?
            "
        );

        $updateRequest->execute([
            $feedback !== ''
                ? $feedback
                : null,
            $requestId
        ]);


        /*
        |--------------------------------------------------------------------------
        | VERIFY REQUEST UPDATE
        |--------------------------------------------------------------------------
        */

        if ($updateRequest->rowCount() !== 1) {
            throw new RuntimeException(
                'The station request status could not be updated.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | COMMIT
        |--------------------------------------------------------------------------
        */

        $pdo->commit();


        redirectWithMessage(
            'success',
            'Station approved successfully.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | NEEDS CHANGES
    |--------------------------------------------------------------------------
    */

    if ($action === 'needs_changes') {

        $updateRequest = $pdo->prepare(
            "
            UPDATE station_requests
            SET
                status = 'Needs changes',
                admin_feedback = ?,
                updated_at = NOW()
            WHERE id = ?
            "
        );

        $updateRequest->execute([
            $feedback,
            $requestId
        ]);

        if ($updateRequest->rowCount() !== 1) {
            throw new RuntimeException(
                'The request could not be updated.'
            );
        }

        $pdo->commit();

        redirectWithMessage(
            'success',
            'Station request marked as needing changes.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | REJECT
    |--------------------------------------------------------------------------
    */

    if ($action === 'reject') {

        $updateRequest = $pdo->prepare(
            "
            UPDATE station_requests
            SET
                status = 'Rejected',
                admin_feedback = ?,
                updated_at = NOW()
            WHERE id = ?
            "
        );

        $updateRequest->execute([
            $feedback,
            $requestId
        ]);

        if ($updateRequest->rowCount() !== 1) {
            throw new RuntimeException(
                'The request could not be rejected.'
            );
        }

        $pdo->commit();

        redirectWithMessage(
            'success',
            'Station request rejected.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SAFETY
    |--------------------------------------------------------------------------
    */

    throw new RuntimeException(
        'No valid action was processed.'
    );


} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }


    /*
    |--------------------------------------------------------------------------
    | PRIVATE ERROR LOG
    |--------------------------------------------------------------------------
    |
    | Do NOT expose SQL errors to users.
    |
    */

    error_log(
        '[GasGo Admin Request Action] ' .
        $e->getMessage()
    );


    /*
    |--------------------------------------------------------------------------
    | USER-FRIENDLY ERROR
    |--------------------------------------------------------------------------
    */

    redirectWithMessage(
        'error',
        $e->getMessage()
    );
}