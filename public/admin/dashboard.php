<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/csrf.php';

/*
|--------------------------------------------------------------------------
| ADMIN AUTHENTICATION
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirectWithMessage(string $type, string $message): never
{
    $_SESSION['flash_type'] = $type;
    $_SESSION['flash_message'] = $message;

    header('Location: dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/
$csrfToken = csrf_token();

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/
$flashType = $_SESSION['flash_type'] ?? '';
$flashMessage = $_SESSION['flash_message'] ?? '';

unset(
    $_SESSION['flash_type'],
    $_SESSION['flash_message']
);

/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $formAction = $_POST['form_action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | ADD MANAGER
    |--------------------------------------------------------------------------
    */
    if ($formAction === 'add_manager') {

        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if (
            $fullName === '' ||
            $email === '' ||
            $password === ''
        ) {
            redirectWithMessage(
                'error',
                'Please complete all manager fields.'
            );
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirectWithMessage(
                'error',
                'Please enter a valid email address.'
            );
        }

        if (strlen($password) < 6) {
            redirectWithMessage(
                'error',
                'Password must be at least 6 characters.'
            );
        }

        try {

            $check = $pdo->prepare("
                SELECT id
                FROM managers
                WHERE email = ?
                LIMIT 1
            ");

            $check->execute([$email]);

            if ($check->fetch()) {
                redirectWithMessage(
                    'error',
                    'A manager with that email already exists.'
                );
            }

            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $pdo->prepare("
                INSERT INTO managers (
                    full_name,
                    email,
                    password
                )
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $fullName,
                $email,
                $hashedPassword
            ]);

            redirectWithMessage(
                'success',
                'Manager added successfully.'
            );

        } catch (Throwable $e) {

            redirectWithMessage(
                'error',
                'Unable to add manager.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE MANAGER
    |--------------------------------------------------------------------------
    */
    elseif ($formAction === 'delete_manager') {

        $managerId = (int)($_POST['manager_id'] ?? 0);

        if ($managerId <= 0) {
            redirectWithMessage(
                'error',
                'Invalid manager.'
            );
        }

        try {

            $stmt = $pdo->prepare("
                SELECT id
                FROM managers
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$managerId]);

            if (!$stmt->fetch()) {
                redirectWithMessage(
                    'error',
                    'Manager not found.'
                );
            }

            $delete = $pdo->prepare("
                DELETE FROM managers
                WHERE id = ?
            ");

            $delete->execute([$managerId]);

            redirectWithMessage(
                'success',
                'Manager deleted successfully.'
            );

        } catch (Throwable $e) {

            redirectWithMessage(
                'error',
                'Unable to delete manager. This manager may still have related records.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | APPROVE STATION REQUEST
    |--------------------------------------------------------------------------
    */
    elseif ($formAction === 'approve_request') {

        $requestId = (int)($_POST['request_id'] ?? 0);

        if ($requestId <= 0) {
            redirectWithMessage(
                'error',
                'Invalid station request.'
            );
        }

        try {

            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | GET REQUEST
            |--------------------------------------------------------------------------
            */
            $stmt = $pdo->prepare("
                SELECT *
                FROM station_requests
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$requestId]);

            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new RuntimeException(
                    'Station request not found.'
                );
            }

            $currentStatus = strtolower(
                trim((string)$request['status'])
            );

            if ($currentStatus === 'approved') {
                throw new RuntimeException(
                    'This station is already approved.'
                );
            }

            $managerId = (int)$request['manager_id'];

            /*
            |--------------------------------------------------------------------------
            | CHECK EXISTING STATION
            |--------------------------------------------------------------------------
            */
            $stationCheck = $pdo->prepare("
                SELECT id
                FROM gas_stations
                WHERE manager_id = ?
                LIMIT 1
            ");

            $stationCheck->execute([$managerId]);

            $existingStation =
                $stationCheck->fetch(PDO::FETCH_ASSOC);

            /*
            |--------------------------------------------------------------------------
            | UPDATE EXISTING STATION
            |--------------------------------------------------------------------------
            */
            if ($existingStation) {

                $stationId =
                    (int)$existingStation['id'];

                $updateStation = $pdo->prepare("
                    UPDATE gas_stations
                    SET
                        name = ?,
                        address = ?,
                        barangay = ?,
                        description = ?,
                        latitude = ?,
                        longitude = ?,
                        operating_hours = ?,
                        diesel_price = ?,
                        unleaded_price = ?,
                        premium_price = ?,
                        lpg_available = ?,
                        lpg_price = ?,
                        contact_number = ?,
                        image = ?,
                        status = 'Approved',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");

                $updateStation->execute([
                    $request['station_name'],
                    $request['address'],
                    $request['barangay'],
                    $request['description'],
                    $request['latitude'],
                    $request['longitude'],
                    $request['operating_hours'],
                    $request['diesel_price'],
                    $request['unleaded_price'],
                    $request['premium_price'],
                    (int)$request['lpg_available'],
                    $request['lpg_price'],
                    $request['contact_number'],
                    $request['image'],
                    $stationId
                ]);

            }

            /*
            |--------------------------------------------------------------------------
            | CREATE NEW STATION
            |--------------------------------------------------------------------------
            */
            else {

                $insertStation = $pdo->prepare("
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
                        status
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, 'Approved'
                    )
                ");

                $insertStation->execute([
                    $managerId,
                    $request['station_name'],
                    $request['address'],
                    $request['barangay'],
                    $request['description'],
                    $request['latitude'],
                    $request['longitude'],
                    $request['operating_hours'],
                    $request['diesel_price'],
                    $request['unleaded_price'],
                    $request['premium_price'],
                    (int)$request['lpg_available'],
                    $request['lpg_price'],
                    $request['contact_number'],
                    $request['image']
                ]);

                $stationId =
                    (int)$pdo->lastInsertId();
            }

            /*
            |--------------------------------------------------------------------------
            | GET LPG PRODUCTS
            |--------------------------------------------------------------------------
            */
            $lpgProductsStmt = $pdo->prepare("
                SELECT
                    kg,
                    price,
                    available
                FROM station_request_lpg_products
                WHERE request_id = ?
                ORDER BY kg ASC
            ");

            $lpgProductsStmt->execute([$requestId]);

            $lpgProducts =
                $lpgProductsStmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

            /*
            |--------------------------------------------------------------------------
            | DELETE OLD LPG PRODUCTS
            |--------------------------------------------------------------------------
            */
            $deleteOldLpg = $pdo->prepare("
                DELETE FROM station_lpg_products
                WHERE station_id = ?
            ");

            $deleteOldLpg->execute([
                $stationId
            ]);

            /*
            |--------------------------------------------------------------------------
            | INSERT LPG PRODUCTS
            |--------------------------------------------------------------------------
            */
            if ($lpgProducts) {

                $insertLpg = $pdo->prepare("
                    INSERT INTO station_lpg_products (
                        station_id,
                        kg,
                        price,
                        available
                    )
                    VALUES (?, ?, ?, ?)
                ");

                foreach ($lpgProducts as $product) {

                    $insertLpg->execute([
                        $stationId,
                        $product['kg'],
                        $product['price'],
                        (int)$product['available']
                    ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE REQUEST
            |--------------------------------------------------------------------------
            */
            $updateRequest = $pdo->prepare("
                UPDATE station_requests
                SET
                    status = 'Approved',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $updateRequest->execute([
                $requestId
            ]);

            $pdo->commit();

            redirectWithMessage(
                'success',
                'Station request approved successfully.'
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            redirectWithMessage(
                'error',
                $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | REJECT STATION REQUEST
    |--------------------------------------------------------------------------
    */
    elseif ($formAction === 'reject_request') {

        $requestId =
            (int)($_POST['request_id'] ?? 0);

        if ($requestId <= 0) {
            redirectWithMessage(
                'error',
                'Invalid station request.'
            );
        }

        try {

            $stmt = $pdo->prepare("
                SELECT id, status
                FROM station_requests
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $requestId
            ]);

            $request =
                $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                redirectWithMessage(
                    'error',
                    'Station request not found.'
                );
            }

            if (
                strtolower(
                    trim((string)$request['status'])
                ) === 'approved'
            ) {
                redirectWithMessage(
                    'error',
                    'An approved station cannot be rejected.'
                );
            }

            $reject = $pdo->prepare("
                UPDATE station_requests
                SET
                    status = 'Rejected',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            $reject->execute([
                $requestId
            ]);

            redirectWithMessage(
                'success',
                'Station request rejected successfully.'
            );

        } catch (Throwable $e) {

            redirectWithMessage(
                'error',
                'Unable to reject station request.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE APPROVED STATION REQUEST
    |--------------------------------------------------------------------------
    |
    | Used by DELETE button inside Review popup.
    |
    */
    elseif ($formAction === 'delete_station_request') {

        $requestId =
            (int)($_POST['request_id'] ?? 0);

        if ($requestId <= 0) {
            redirectWithMessage(
                'error',
                'Invalid station request.'
            );
        }

        try {

            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | GET REQUEST
            |--------------------------------------------------------------------------
            */
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    manager_id,
                    station_name,
                    status
                FROM station_requests
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $requestId
            ]);

            $request =
                $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                throw new RuntimeException(
                    'Station request not found.'
                );
            }

            $status =
                strtolower(
                    trim((string)$request['status'])
                );

            if ($status !== 'approved') {
                throw new RuntimeException(
                    'Only approved stations can be deleted from the review popup.'
                );
            }

            $managerId =
                (int)$request['manager_id'];

            /*
            |--------------------------------------------------------------------------
            | FIND STATION
            |--------------------------------------------------------------------------
            */
            $stationStmt = $pdo->prepare("
                SELECT id
                FROM gas_stations
                WHERE manager_id = ?
                LIMIT 1
            ");

            $stationStmt->execute([
                $managerId
            ]);

            $station =
                $stationStmt->fetch(PDO::FETCH_ASSOC);

            if (!$station) {
                throw new RuntimeException(
                    'The approved station could not be found.'
                );
            }

            $stationId =
                (int)$station['id'];

            /*
            |--------------------------------------------------------------------------
            | DELETE LPG PRODUCTS
            |--------------------------------------------------------------------------
            */
            $deleteLpg = $pdo->prepare("
                DELETE FROM station_lpg_products
                WHERE station_id = ?
            ");

            $deleteLpg->execute([
                $stationId
            ]);

            /*
            |--------------------------------------------------------------------------
            | DELETE STATION
            |--------------------------------------------------------------------------
            */
            $deleteStation = $pdo->prepare("
                DELETE FROM gas_stations
                WHERE id = ?
            ");

            $deleteStation->execute([
                $stationId
            ]);

            /*
            |--------------------------------------------------------------------------
            | DELETE REQUEST
            |--------------------------------------------------------------------------
            */
            $deleteRequest = $pdo->prepare("
                DELETE FROM station_requests
                WHERE id = ?
            ");

            $deleteRequest->execute([
                $requestId
            ]);

            $pdo->commit();

            redirectWithMessage(
                'success',
                'Station deleted successfully.'
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            redirectWithMessage(
                'error',
                $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE STATION FROM GASOLINE STATIONS PAGE
    |--------------------------------------------------------------------------
    */
    elseif ($formAction === 'delete_station') {

        $stationId =
            (int)($_POST['station_id'] ?? 0);

        if ($stationId <= 0) {
            redirectWithMessage(
                'error',
                'Invalid station.'
            );
        }

        try {

            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | CHECK STATION
            |--------------------------------------------------------------------------
            */
            $check = $pdo->prepare("
                SELECT id
                FROM gas_stations
                WHERE id = ?
                LIMIT 1
            ");

            $check->execute([
                $stationId
            ]);

            if (!$check->fetch()) {
                throw new RuntimeException(
                    'Station not found.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | DELETE LPG PRODUCTS
            |--------------------------------------------------------------------------
            */
            $deleteLpg = $pdo->prepare("
                DELETE FROM station_lpg_products
                WHERE station_id = ?
            ");

            $deleteLpg->execute([
                $stationId
            ]);

            /*
            |--------------------------------------------------------------------------
            | DELETE STATION
            |--------------------------------------------------------------------------
            */
            $deleteStation = $pdo->prepare("
                DELETE FROM gas_stations
                WHERE id = ?
            ");

            $deleteStation->execute([
                $stationId
            ]);

            $pdo->commit();

            redirectWithMessage(
                'success',
                'Station deleted successfully.'
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            redirectWithMessage(
                'error',
                'Unable to delete station.'
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| FETCH MANAGERS
|--------------------------------------------------------------------------
*/
$managers = [];

try {

    $stmt = $pdo->query("
        SELECT
            m.id,
            m.full_name,
            m.email,
            m.created_at,

            EXISTS (
                SELECT 1
                FROM gas_stations gs
                WHERE gs.manager_id = m.id
            ) AS has_shop,

            (
                SELECT gs.name
                FROM gas_stations gs
                WHERE gs.manager_id = m.id
                ORDER BY gs.id DESC
                LIMIT 1
            ) AS shop_name,

            EXISTS (
                SELECT 1
                FROM station_requests sr
                WHERE sr.manager_id = m.id
            ) AS has_request,

            (
                SELECT sr.status
                FROM station_requests sr
                WHERE sr.manager_id = m.id
                ORDER BY
                    COALESCE(sr.updated_at, sr.created_at) DESC,
                    sr.id DESC
                LIMIT 1
            ) AS latest_request_status,

            (
                SELECT sr.station_name
                FROM station_requests sr
                WHERE sr.manager_id = m.id
                ORDER BY
                    COALESCE(sr.updated_at, sr.created_at) DESC,
                    sr.id DESC
                LIMIT 1
            ) AS latest_requested_shop

        FROM managers m

        ORDER BY m.full_name ASC
    ");

    $managers =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    $managers = [];
}


/*
|--------------------------------------------------------------------------
| MANAGER SHOP STATUS
|--------------------------------------------------------------------------
*/

$managersWithShop = 0;
$managersWithRequest = 0;
$managersWithoutShopOrRequest = 0;

foreach ($managers as &$manager) {

    $hasShop =
        (int)($manager['has_shop'] ?? 0) === 1;

    $hasRequest =
        (int)($manager['has_request'] ?? 0) === 1;


    if ($hasShop) {

        $manager['shop_state'] =
            'has_shop';

        $manager['shop_status_label'] =
            'Has Shop';

        $managersWithShop++;

    } elseif ($hasRequest) {

        $manager['shop_state'] =
            'requested';

        $manager['shop_status_label'] =
            'Shop Requested';

        $managersWithRequest++;

    } else {

        $manager['shop_state'] =
            'no_request';

        $manager['shop_status_label'] =
            'No Shop / No Request';

        $managersWithoutShopOrRequest++;

    }

}

unset($manager);

/*
|--------------------------------------------------------------------------
| FETCH STATION REQUESTS
|--------------------------------------------------------------------------
*/
$requests = [];

try {

    $stmt = $pdo->query("
        SELECT
            r.id,
            r.manager_id,
            r.station_name,
            r.address,
            r.barangay,
            r.description,
            r.latitude,
            r.longitude,
            r.operating_hours,
            r.diesel_price,
            r.unleaded_price,
            r.premium_price,
            r.lpg_available,
            r.lpg_price,
            r.contact_number,
            r.image,
            r.status,
            r.admin_feedback,
            r.created_at,
            r.updated_at,

            m.full_name AS manager_name,
            m.email AS manager_email

        FROM station_requests r

        LEFT JOIN managers m
            ON m.id = r.manager_id

        ORDER BY r.created_at DESC
    ");

    $requests =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    $requests = [];
}

/*
|--------------------------------------------------------------------------
| FETCH GASOLINE STATIONS
|--------------------------------------------------------------------------
*/
$stations = [];

try {

    $stmt = $pdo->query("
        SELECT *
        FROM gas_stations
        ORDER BY name ASC
    ");

    $stations =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    $stations = [];
}

/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/
$totalManagers =
    count($managers);

$totalRequests =
    count($requests);

$totalStations =
    count($stations);

$pendingRequests = 0;
$approvedRequests = 0;
$rejectedRequests = 0;

foreach ($requests as $request) {

    $status =
        strtolower(
            trim((string)$request['status'])
        );

    if ($status === 'pending') {
        $pendingRequests++;
    }

    if ($status === 'approved') {
        $approvedRequests++;
    }

    if ($status === 'rejected') {
        $rejectedRequests++;
    }
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

<title>GasGo Admin Dashboard</title>

<style>

/*
|--------------------------------------------------------------------------
| RESET
|--------------------------------------------------------------------------
*/

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    margin: 0;
    font-family:
        Arial,
        Helvetica,
        sans-serif;

    background: #f5f7fb;
    color: #1f2937;
}

body.sidebar-open {
    overflow: hidden;
}

button,
input,
select,
textarea {
    font: inherit;
}


/*
|--------------------------------------------------------------------------
| SIDEBAR
|--------------------------------------------------------------------------
*/

.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;

    width: 250px;

    background: #111827;
    color: #ffffff;

    z-index: 1000;

    display: flex;
    flex-direction: column;

    transition:
        transform .25s ease;
}

.sidebar-brand {
    padding: 24px 20px;

    display: flex;
    align-items: center;
    gap: 12px;

    border-bottom:
        1px solid
        rgba(255,255,255,.08);
}

.sidebar-logo {
    width: 44px;
    height: 44px;

    border-radius: 12px;

    background: #16a34a;

    display: flex;
    align-items: center;
    justify-content: center;

    font-weight: 800;
}

.sidebar-brand-text strong {
    display: block;
    font-size: 18px;
}

.sidebar-brand-text span {
    display: block;

    color: #9ca3af;

    font-size: 11px;

    margin-top: 3px;
}

.sidebar-nav {
    padding: 20px 12px;

    display: flex;
    flex-direction: column;

    gap: 5px;

    flex: 1;
}

.nav-link {
    width: 100%;

    border: 0;
    background: transparent;

    color: #d1d5db;

    padding: 13px 14px;

    border-radius: 9px;

    display: flex;
    align-items: center;

    gap: 12px;

    cursor: pointer;

    text-align: left;

    font-size: 14px;
    font-weight: 600;

    text-decoration: none;

    transition:
        background .2s ease,
        color .2s ease;
}

.nav-link:hover {
    background: #1f2937;
    color: #ffffff;
}

.nav-link.active {
    background: #16a34a;
    color: #ffffff;
}

.nav-icon {
    width: 22px;

    text-align: center;

    font-size: 16px;
}

.sidebar-bottom {
    padding: 12px;
}

.logout-link {
    color: #fecaca;
}

.logout-link:hover {
    background: #7f1d1d;
    color: #ffffff;
}


/*
|--------------------------------------------------------------------------
| MOBILE SIDEBAR BUTTON
|--------------------------------------------------------------------------
*/

.sidebar-toggle {
    display: none;

    position: fixed;

    top: 15px;
    left: 15px;

    z-index: 1200;

    width: 44px;
    height: 44px;

    border: 0;

    border-radius: 10px;

    background: #111827;
    color: #ffffff;

    cursor: pointer;

    font-size: 21px;
}

.sidebar-overlay {
    display: none;

    position: fixed;
    inset: 0;

    background: rgba(0,0,0,.5);

    z-index: 900;
}


/*
|--------------------------------------------------------------------------
| MAIN
|--------------------------------------------------------------------------
*/

.main {
    margin-left: 250px;

    min-height: 100vh;
}

.topbar {
    background: #ffffff;

    border-bottom:
        1px solid #e5e7eb;

    padding: 18px 30px;

    position: sticky;
    top: 0;

    z-index: 100;
}

.topbar-content {
    display: flex;

    justify-content: space-between;
    align-items: center;

    gap: 20px;
}

.topbar-title h1 {
    margin: 0;

    font-size: 22px;
}

.topbar-title p {
    margin: 4px 0 0;

    color: #6b7280;

    font-size: 13px;
}

.admin-label {
    color: #6b7280;

    font-size: 13px;
}


/*
|--------------------------------------------------------------------------
| PAGE
|--------------------------------------------------------------------------
*/

.page {
    padding: 30px;
}

.page-section {
    display: none;
}

.page-section.active {
    display: block;
}

.page-title {
    margin-bottom: 24px;
}

.page-title h2 {
    margin: 0 0 5px;

    font-size: 28px;
}

.page-title p {
    margin: 0;

    color: #6b7280;
}


/*
|--------------------------------------------------------------------------
| ALERT
|--------------------------------------------------------------------------
*/

.alert {
    padding: 14px 16px;

    border-radius: 10px;

    margin-bottom: 20px;

    font-size: 14px;

    border: 1px solid transparent;
}

.alert-success {
    background: #ecfdf5;

    border-color: #a7f3d0;

    color: #065f46;
}

.alert-error {
    background: #fef2f2;

    border-color: #fecaca;

    color: #991b1b;
}


/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

.stats-grid {
    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 16px;

    margin-bottom: 25px;
}

.stat-card {
    background: #ffffff;

    border:
        1px solid #e5e7eb;

    border-radius: 14px;

    padding: 20px;

    box-shadow:
        0 2px 8px
        rgba(0,0,0,.03);
}


.stat-card[data-overview-action] {
    cursor: pointer;

    position: relative;

    transition:
        transform .2s ease,
        border-color .2s ease,
        box-shadow .2s ease;
}


.stat-card[data-overview-action]:hover {
    transform: translateY(-3px);

    border-color: #86efac;

    box-shadow:
        0 10px 24px
        rgba(15,23,42,.09);
}


.stat-card[data-overview-action]:focus-visible {
    outline:
        3px solid
        rgba(22,163,74,.22);

    outline-offset: 3px;
}


.stat-card::after {
    content: 'View details →';

    display: block;

    margin-top: 12px;

    color: #16a34a;

    font-size: 11px;

    font-weight: 700;
}

.stat-label {
    color: #6b7280;

    font-size: 13px;

    margin-bottom: 8px;
}

.stat-value {
    font-size: 30px;

    font-weight: 800;
}

.stat-description {
    margin-top: 5px;

    color: #9ca3af;

    font-size: 12px;
}


/*
|--------------------------------------------------------------------------
| SECTION
|--------------------------------------------------------------------------
*/

.section {
    background: #ffffff;

    border:
        1px solid #e5e7eb;

    border-radius: 14px;

    margin-bottom: 25px;

    overflow: hidden;

    box-shadow:
        0 2px 8px
        rgba(0,0,0,.03);
}

.section-header {
    padding: 20px;

    border-bottom:
        1px solid #e5e7eb;

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;
}

.section-header h3 {
    margin: 0;

    font-size: 18px;
}

.section-header p {
    margin: 4px 0 0;

    color: #6b7280;

    font-size: 13px;
}

.section-body {
    padding: 20px;
}


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

.search-wrapper {
    margin-bottom: 18px;

    position: relative;
}

.search-wrapper input {
    width: 100%;

    border:
        1px solid #d1d5db;

    border-radius: 10px;

    padding:
        12px
        14px
        12px
        42px;

    outline: none;

    background: #ffffff;
}

.search-wrapper input:focus {
    border-color: #2563eb;

    box-shadow:
        0 0 0 3px
        rgba(37,99,235,.1);
}

.search-icon {
    position: absolute;

    left: 15px;
    top: 50%;

    transform:
        translateY(-50%);

    color: #9ca3af;

    font-size: 16px;
}

.search-result-count {
    margin-top: 7px;

    color: #6b7280;

    font-size: 12px;
}


/*
|--------------------------------------------------------------------------
| MANAGER SHOP FILTERS
|--------------------------------------------------------------------------
*/

.manager-shop-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
    margin-bottom: 16px;
}

.manager-shop-filter {
    border:
        1px solid #d1d5db;

    border-radius: 999px;

    padding: 8px 12px;

    background: #ffffff;
    color: #4b5563;

    cursor: pointer;

    font-size: 12px;
    font-weight: 700;

    transition:
        background .2s ease,
        border-color .2s ease,
        color .2s ease,
        transform .2s ease;
}

.manager-shop-filter:hover {
    border-color: #86efac;
    color: #15803d;
    transform: translateY(-1px);
}

.manager-shop-filter.active {
    background: #16a34a;
    border-color: #16a34a;
    color: #ffffff;
}

.manager-shop-status {
    display: inline-flex;
    align-items: center;

    padding: 6px 9px;

    border-radius: 999px;

    font-size: 11px;
    font-weight: 800;

    white-space: nowrap;
}

.manager-shop-status.has-shop {
    background: #ecfdf5;
    color: #047857;
}

.manager-shop-status.requested {
    background: #fff7ed;
    color: #c2410c;
}

.manager-shop-status.no-request {
    background: #f3f4f6;
    color: #4b5563;
}

.manager-shop-detail {
    display: block;

    margin-top: 5px;

    max-width: 230px;

    color: #6b7280;

    font-size: 11px;
    line-height: 1.4;
}


/*
|--------------------------------------------------------------------------
| TABLE
|--------------------------------------------------------------------------
*/

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;

    border-collapse: collapse;

    min-width: 800px;
}

th,
td {
    padding: 14px 12px;

    text-align: left;

    border-bottom:
        1px solid #eef0f3;

    font-size: 14px;
}

th {
    color: #6b7280;

    font-size: 11px;

    text-transform: uppercase;

    letter-spacing: .04em;

    background: #fafafa;
}

tr:last-child td {
    border-bottom: 0;
}


/*
|--------------------------------------------------------------------------
| BUTTONS
|--------------------------------------------------------------------------
*/

.btn {
    border: 0;

    border-radius: 8px;

    padding: 9px 13px;

    cursor: pointer;

    font-weight: 600;

    font-size: 13px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    text-decoration: none;
}

.btn-small {
    padding: 8px 11px;

    font-size: 12px;
}

.btn-primary {
    background: #2563eb;

    color: #ffffff;
}

.btn-success {
    background: #16a34a;

    color: #ffffff;
}

.btn-danger {
    background: #dc2626;

    color: #ffffff;
}

.btn-secondary {
    background: #6b7280;

    color: #ffffff;
}

.btn-dark {
    background: #111827;

    color: #ffffff;
}

.btn-light {
    background: #f3f4f6;

    color: #111827;
}

.actions {
    display: flex;

    gap: 7px;

    flex-wrap: wrap;
}


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

.status {
    display: inline-flex;

    align-items: center;

    padding: 5px 9px;

    border-radius: 999px;

    font-size: 11px;

    font-weight: 700;
}

.status-pending {
    background: #fff7ed;

    color: #c2410c;
}

.status-approved {
    background: #ecfdf5;

    color: #047857;
}

.status-rejected {
    background: #fef2f2;

    color: #b91c1c;
}

.status-needs {
    background: #eff6ff;

    color: #1d4ed8;
}


/*
|--------------------------------------------------------------------------
| FORM
|--------------------------------------------------------------------------
*/

.form-grid {
    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 15px;
}

.form-group {
    display: flex;

    flex-direction: column;

    gap: 7px;
}

.form-group label {
    font-size: 13px;

    font-weight: 700;
}

.form-group input {
    width: 100%;

    border:
        1px solid #d1d5db;

    border-radius: 8px;

    padding: 10px 12px;

    outline: none;
}

.form-group input:focus {
    border-color: #2563eb;
}

.form-submit {
    display: flex;

    align-items: flex-end;
}


/*
|--------------------------------------------------------------------------
| EMPTY
|--------------------------------------------------------------------------
*/

.empty {
    text-align: center;

    padding: 40px 20px;

    color: #6b7280;
}


/*
|--------------------------------------------------------------------------
| STATION IMAGE THUMBNAIL
|--------------------------------------------------------------------------
*/

.station-thumb {
    width: 55px;
    height: 45px;

    border-radius: 7px;

    object-fit: cover;

    background: #f3f4f6;

    display: block;
}

.no-thumb {
    width: 55px;
    height: 45px;

    border-radius: 7px;

    background: #f3f4f6;

    color: #9ca3af;

    display: flex;

    align-items: center;
    justify-content: center;

    font-size: 9px;

    text-align: center;
}


/*
|--------------------------------------------------------------------------
| MODAL
|--------------------------------------------------------------------------
*/

.modal {
    display: none;

    position: fixed;

    inset: 0;

    background:
        rgba(15,23,42,.65);

    z-index: 2000;

    padding: 25px;

    overflow-y: auto;
}

.modal.show {
    display: flex;

    align-items: center;

    justify-content: center;
}

.modal-content {
    background: #ffffff;

    width:
        min(900px, 100%);

    max-height:
        calc(100vh - 50px);

    overflow-y: auto;

    border-radius: 16px;

    box-shadow:
        0 25px 60px
        rgba(0,0,0,.25);
}

.modal-header {
    padding: 20px;

    border-bottom:
        1px solid #e5e7eb;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    position: sticky;

    top: 0;

    background: #ffffff;

    z-index: 5;
}

.modal-header h3 {
    margin: 0;

    font-size: 20px;
}

.modal-close {
    width: 36px;
    height: 36px;

    border: 0;

    border-radius: 50%;

    background: #f3f4f6;

    color: #111827;

    cursor: pointer;

    font-size: 20px;
}

.modal-body {
    padding: 20px;
}


/*
|--------------------------------------------------------------------------
| STATION IMAGE IN MODAL
|--------------------------------------------------------------------------
*/

.station-image-wrapper {
    width: 100%;

    margin-bottom: 22px;
}

.station-image {
    width: 100%;

    max-height: 350px;

    object-fit: cover;

    border-radius: 12px;

    display: block;

    background: #f3f4f6;
}

.no-station-image {
    width: 100%;

    height: 220px;

    border-radius: 12px;

    background: #f3f4f6;

    display: flex;

    align-items: center;

    justify-content: center;

    color: #9ca3af;

    font-size: 14px;
}


/*
|--------------------------------------------------------------------------
| REVIEW DETAILS
|--------------------------------------------------------------------------
*/

.review-status {
    margin-bottom: 18px;
}

.details-grid {
    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 12px;
}

.detail-box {
    background: #f8fafc;

    border:
        1px solid #e5e7eb;

    border-radius: 10px;

    padding: 13px;
}

.detail-box.full {
    grid-column: 1 / -1;
}

.detail-label {
    color: #6b7280;

    font-size: 11px;

    text-transform: uppercase;

    font-weight: 700;

    margin-bottom: 5px;
}

.detail-value {
    font-size: 14px;

    font-weight: 600;

    word-break: break-word;
}


/*
|--------------------------------------------------------------------------
| MODAL FOOTER
|--------------------------------------------------------------------------
*/

.modal-footer {
    padding: 18px 20px;

    border-top:
        1px solid #e5e7eb;

    display: flex;

    justify-content: flex-end;

    gap: 10px;

    flex-wrap: wrap;

    background: #ffffff;

    position: sticky;

    bottom: 0;

    z-index: 5;
}

.modal-action-form {
    margin: 0;
}


/*
|--------------------------------------------------------------------------
| RESPONSIVE
|--------------------------------------------------------------------------
*/

@media (max-width: 1100px) {

    .stats-grid {
        grid-template-columns:
            repeat(2, 1fr);
    }

    .form-grid {
        grid-template-columns:
            repeat(2, 1fr);
    }
}


@media (max-width: 800px) {

    .sidebar {
        transform:
            translateX(-100%);
    }

    .sidebar.open {
        transform:
            translateX(0);
    }

    .sidebar-toggle {
        display: flex;

        align-items: center;
        justify-content: center;
    }

    .sidebar-overlay.show {
        display: block;
    }

    .main {
        margin-left: 0;
    }

    .topbar {
        padding:
            18px
            20px
            18px
            75px;
    }

    .page {
        padding: 25px 20px;
    }
}


@media (max-width: 650px) {

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .details-grid {
        grid-template-columns: 1fr;
    }

    .detail-box.full {
        grid-column: auto;
    }

    .modal {
        padding: 10px;
    }

    .modal-content {
        max-height:
            calc(100vh - 20px);
    }

    .modal-footer {
        flex-direction: column;
    }

    .modal-footer .btn,
    .modal-footer form,
    .modal-footer form .btn {
        width: 100%;
    }

    .topbar-content {
        align-items: flex-start;
    }

    .admin-label {
        display: none;
    }
}

</style>

</head>


<body>


<!--
|--------------------------------------------------------------------------
| MOBILE SIDEBAR TOGGLE
|--------------------------------------------------------------------------
-->

<button
    type="button"
    class="sidebar-toggle"
    id="sidebarToggle"
    aria-label="Open navigation"
>
    ☰
</button>


<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>


<!--
|--------------------------------------------------------------------------
| SIDEBAR
|--------------------------------------------------------------------------
-->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <div class="sidebar-logo">
            GG
        </div>

        <div class="sidebar-brand-text">

            <strong>GasGo</strong>

            <span>
                Administrator
            </span>

        </div>

    </div>


    <nav class="sidebar-nav">

        <button
            type="button"
            class="nav-link active"
            data-section="dashboardSection"
        >

            <span class="nav-icon">
                ▦
            </span>

            Dashboard

        </button>


        <button
            type="button"
            class="nav-link"
            data-section="managerSection"
        >

            <span class="nav-icon">
                ♙
            </span>

            Managers

        </button>


        <button
            type="button"
            class="nav-link"
            data-section="stationSection"
        >

            <span class="nav-icon">
                ⛽
            </span>

            Gasoline Stations

        </button>

    </nav>


    <div class="sidebar-bottom">

        <a
            href="logout.php"
            class="nav-link logout-link"
        >

            <span class="nav-icon">
                ↪
            </span>

            Logout

        </a>

    </div>

</aside>


<!--
|--------------------------------------------------------------------------
| MAIN
|--------------------------------------------------------------------------
-->

<main class="main">


    <!-- TOPBAR -->

    <header class="topbar">

        <div class="topbar-content">

            <div class="topbar-title">

                <h1>
                    GasGo Admin
                </h1>

                <p id="topbarDescription">
                    Overview of your GasGo system
                </p>

            </div>


            <div class="admin-label">
                Administrator
            </div>

        </div>

    </header>


    <div class="page">


        <!--
        |--------------------------------------------------------------------------
        | FLASH MESSAGE
        |--------------------------------------------------------------------------
        -->

        <?php if ($flashMessage !== ''): ?>

            <div
                class="alert <?= $flashType === 'success'
                    ? 'alert-success'
                    : 'alert-error'
                ?>"
            >

                <?= e($flashMessage) ?>

            </div>

        <?php endif; ?>


        <!--
        |--------------------------------------------------------------------------
        | DASHBOARD SECTION
        |--------------------------------------------------------------------------
        -->

        <section
            class="page-section active"
            id="dashboardSection"
        >

            <div class="page-title">

                <h2>
                    Dashboard
                </h2>

                <p>
                    Overview of station requests, managers, and gasoline stations.
                </p>

            </div>


            <!-- STATISTICS -->

            <div class="stats-grid">

                <div
                    class="stat-card"
                    data-overview-action="managers"
                    role="button"
                    tabindex="0"
                    aria-label="View registered managers"
                >

                    <div class="stat-label">
                        Total Managers
                    </div>

                    <div class="stat-value">
                        <?= $totalManagers ?>
                    </div>

                    <div class="stat-description">
                        Registered managers
                    </div>

                </div>


                <div
                    class="stat-card"
                    data-overview-action="stations"
                    role="button"
                    tabindex="0"
                    aria-label="View approved gasoline stations"
                >

                    <div class="stat-label">
                        Total Gasoline Stations
                    </div>

                    <div class="stat-value">
                        <?= $totalStations ?>
                    </div>

                    <div class="stat-description">
                        Approved stations
                    </div>

                </div>


                <div
                    class="stat-card"
                    data-overview-action="pending"
                    role="button"
                    tabindex="0"
                    aria-label="View all pending shops"
                >

                    <div class="stat-label">
                        Total Pending Shops
                    </div>

                    <div class="stat-value">
                        <?= $pendingRequests ?>
                    </div>

                    <div class="stat-description">
                        Shops waiting for admin review
                    </div>

                </div>


                <div
                    class="stat-card"
                    data-overview-action="approved"
                    role="button"
                    tabindex="0"
                    aria-label="View approved station requests"
                >

                    <div class="stat-label">
                        Approved Requests
                    </div>

                    <div class="stat-value">
                        <?= $approvedRequests ?>
                    </div>

                    <div class="stat-description">
                        Approved submissions
                    </div>

                </div>

            </div>


            <!-- STATION REQUESTS -->

            <section class="section">

                <div class="section-header">

                    <div>

                        <h3>
                            Station Requests
                        </h3>

                        <p>
                            Review station submissions from managers.
                        </p>

                    </div>

                </div>


                <div class="section-body">


                    <div
                        id="overviewRequestFilter"
                        class="alert alert-success"
                        style="display:none;"
                    ></div>


                    <div class="search-wrapper">

                        <span class="search-icon">
                            🔍
                        </span>

                        <input
                            type="search"
                            id="requestSearch"
                            placeholder="Search station requests..."
                        >

                        <div
                            class="search-result-count"
                            id="requestSearchCount"
                        ></div>

                    </div>


                    <?php if (!$requests): ?>

                        <div class="empty">
                            No station requests found.
                        </div>

                    <?php else: ?>

                        <div class="table-wrapper">

                            <table id="requestTable">

                                <thead>

                                    <tr>

                                        <th>
                                            Station
                                        </th>

                                        <th>
                                            Manager
                                        </th>

                                        <th>
                                            Barangay
                                        </th>

                                        <th>
                                            Status
                                        </th>

                                        <th>
                                            Submitted
                                        </th>

                                        <th>
                                            Action
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>

                                <?php foreach ($requests as $request): ?>

                                    <?php

                                    $status =
                                        strtolower(
                                            trim(
                                                (string)$request['status']
                                            )
                                        );

                                    $statusClass =
                                        'status-pending';

                                    if (
                                        $status === 'approved'
                                    ) {
                                        $statusClass =
                                            'status-approved';
                                    }
                                    elseif (
                                        $status === 'rejected'
                                    ) {
                                        $statusClass =
                                            'status-rejected';
                                    }
                                    elseif (
                                        $status === 'needs changes'
                                    ) {
                                        $statusClass =
                                            'status-needs';
                                    }

                                    ?>

                                    <tr
                                        class="request-row"
                                        data-request-status="<?= e($status) ?>"
                                    >

                                        <td>

                                            <strong>
                                                <?= e(
                                                    $request['station_name']
                                                ) ?>
                                            </strong>

                                        </td>


                                        <td>
                                            <?= e(
                                                $request['manager_name']
                                                    ?: 'Unknown'
                                            ) ?>
                                        </td>


                                        <td>
                                            <?= e(
                                                $request['barangay']
                                            ) ?>
                                        </td>


                                        <td>

                                            <span
                                                class="status <?= $statusClass ?>"
                                            >
                                                <?= e(
                                                    $request['status']
                                                ) ?>
                                            </span>

                                        </td>


                                        <td>

                                            <?= e(
                                                date(
                                                    'M d, Y h:i A',
                                                    strtotime(
                                                        (string)$request['created_at']
                                                    )
                                                )
                                            ) ?>

                                        </td>


                                        <td>

                                            <button
                                                type="button"
                                                class="btn btn-primary btn-small review-btn"
                                                data-request="<?= e(
                                                    json_encode(
                                                        $request,
                                                        JSON_HEX_TAG |
                                                        JSON_HEX_APOS |
                                                        JSON_HEX_AMP |
                                                        JSON_HEX_QUOT
                                                    )
                                                ) ?>"
                                            >
                                                Review
                                            </button>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

            </section>

        </section>


        <!--
        |--------------------------------------------------------------------------
        | MANAGERS SECTION
        |--------------------------------------------------------------------------
        -->

        <section
            class="page-section"
            id="managerSection"
        >

            <div class="page-title">

                <h2>
                    Managers
                </h2>

                <p>
                    Add, search, and manage gasoline station managers.
                </p>

            </div>


            <!-- ADD MANAGER -->

            <section class="section">

                <div class="section-header">

                    <div>

                        <h3>
                            Add Manager
                        </h3>

                        <p>
                            Create a new manager account.
                        </p>

                    </div>

                </div>


                <div class="section-body">

                    <form
                        method="POST"
                        autocomplete="off"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken) ?>"
                        >

                        <input
                            type="hidden"
                            name="form_action"
                            value="add_manager"
                        >


                        <div class="form-grid">

                            <div class="form-group">

                                <label for="manager_name">
                                    Full Name
                                </label>

                                <input
                                    type="text"
                                    id="manager_name"
                                    name="full_name"
                                    placeholder="Enter full name"
                                    required
                                >

                            </div>


                            <div class="form-group">

                                <label for="manager_email">
                                    Email
                                </label>

                                <input
                                    type="email"
                                    id="manager_email"
                                    name="email"
                                    placeholder="Enter email"
                                    required
                                >

                            </div>


                            <div class="form-group">

                                <label for="manager_password">
                                    Password
                                </label>

                                <input
                                    type="password"
                                    id="manager_password"
                                    name="password"
                                    minlength="6"
                                    placeholder="Minimum 6 characters"
                                    required
                                >

                            </div>


                            <div class="form-submit">

                                <button
                                    type="submit"
                                    class="btn btn-success"
                                >
                                    + Add Manager
                                </button>

                            </div>

                        </div>

                    </form>

                </div>

            </section>


            <!-- MANAGER LIST -->

            <section class="section">

                <div class="section-header">

                    <div>

                        <h3>
                            Manager List
                        </h3>

                        <p>
                            Search registered managers.
                        </p>

                    </div>

                </div>


                <div class="section-body">


                    <div
                        class="manager-shop-filters"
                        id="managerShopFilters"
                        aria-label="Filter managers by shop status"
                    >

                        <button
                            type="button"
                            class="manager-shop-filter active"
                            data-manager-filter="all"
                        >
                            All Managers (<?= $totalManagers ?>)
                        </button>

                        <button
                            type="button"
                            class="manager-shop-filter"
                            data-manager-filter="has_shop"
                        >
                            Has Shop (<?= $managersWithShop ?>)
                        </button>

                        <button
                            type="button"
                            class="manager-shop-filter"
                            data-manager-filter="requested"
                        >
                            Shop Requested (<?= $managersWithRequest ?>)
                        </button>

                        <button
                            type="button"
                            class="manager-shop-filter"
                            data-manager-filter="no_request"
                        >
                            No Shop / No Request (<?= $managersWithoutShopOrRequest ?>)
                        </button>

                    </div>


                    <div class="search-wrapper">

                        <span class="search-icon">
                            🔍
                        </span>

                        <input
                            type="search"
                            id="managerSearch"
                            placeholder="Search manager by name or email..."
                        >

                        <div
                            class="search-result-count"
                            id="managerSearchCount"
                        ></div>

                    </div>


                    <?php if (!$managers): ?>

                        <div class="empty">
                            No managers found.
                        </div>

                    <?php else: ?>

                        <div class="table-wrapper">

                            <table id="managerTable">

                                <thead>

                                    <tr>

                                        <th>
                                            Name
                                        </th>

                                        <th>
                                            Email
                                        </th>

                                        <th>
                                            Shop Status
                                        </th>

                                        <th>
                                            Created
                                        </th>

                                        <th>
                                            Action
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>

                                <?php foreach ($managers as $manager): ?>

                                    <tr
                                        class="manager-row"
                                        data-manager-shop-state="<?= e(
                                            (string)($manager['shop_state'] ?? 'no_request')
                                        ) ?>"
                                    >

                                        <td>
                                            <strong>
                                                <?= e(
                                                    $manager['full_name']
                                                ) ?>
                                            </strong>
                                        </td>


                                        <td>
                                            <?= e(
                                                $manager['email']
                                            ) ?>
                                        </td>


                                        <td>

                                            <?php
                                            $managerShopState =
                                                (string)($manager['shop_state'] ?? 'no_request');

                                            $latestRequestStatus =
                                                trim(
                                                    (string)($manager['latest_request_status'] ?? '')
                                                );
                                            ?>

                                            <?php if ($managerShopState === 'has_shop'): ?>

                                                <span class="manager-shop-status has-shop">
                                                    Has Shop
                                                </span>

                                                <?php if (!empty($manager['shop_name'])): ?>

                                                    <span class="manager-shop-detail">
                                                        <?= e(
                                                            (string)$manager['shop_name']
                                                        ) ?>
                                                    </span>

                                                <?php endif; ?>


                                            <?php elseif ($managerShopState === 'requested'): ?>

                                                <span class="manager-shop-status requested">
                                                    Shop Requested
                                                </span>

                                                <span class="manager-shop-detail">

                                                    <?php if (!empty($manager['latest_requested_shop'])): ?>

                                                        <?= e(
                                                            (string)$manager['latest_requested_shop']
                                                        ) ?>

                                                    <?php endif; ?>

                                                    <?php if ($latestRequestStatus !== ''): ?>

                                                        <?= !empty($manager['latest_requested_shop'])
                                                            ? ' — '
                                                            : '' ?>

                                                        <?= e($latestRequestStatus) ?>

                                                    <?php endif; ?>

                                                </span>


                                            <?php else: ?>

                                                <span class="manager-shop-status no-request">
                                                    No Shop / No Request
                                                </span>

                                                <span class="manager-shop-detail">
                                                    Manager has not submitted a shop request.
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <td>

                                            <?= e(
                                                date(
                                                    'M d, Y',
                                                    strtotime(
                                                        (string)$manager['created_at']
                                                    )
                                                )
                                            ) ?>

                                        </td>


                                        <td>

                                            <form
                                                method="POST"
                                                onsubmit="return confirm('Delete this manager?');"
                                                style="display:inline;"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($csrfToken) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="form_action"
                                                    value="delete_manager"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="manager_id"
                                                    value="<?= (int)$manager['id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-danger btn-small"
                                                >
                                                    Delete
                                                </button>

                                            </form>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

            </section>

        </section>


        <!--
        |--------------------------------------------------------------------------
        | GASOLINE STATIONS SECTION
        |--------------------------------------------------------------------------
        -->

        <section
            class="page-section"
            id="stationSection"
        >

            <div class="page-title">

                <h2>
                    Gasoline Stations
                </h2>

                <p>
                    Search, review, and manage registered gasoline stations.
                </p>

            </div>


            <section class="section">

                <div class="section-header">

                    <div>

                        <h3>
                            Registered Gasoline Stations
                        </h3>

                        <p>
                            Search for a station to quickly view its information.
                        </p>

                    </div>

                </div>


                <div class="section-body">


                    <div class="search-wrapper">

                        <span class="search-icon">
                            🔍
                        </span>

                        <input
                            type="search"
                            id="stationSearch"
                            placeholder="Search station by name, barangay, address, or manager ID..."
                        >

                        <div
                            class="search-result-count"
                            id="stationSearchCount"
                        ></div>

                    </div>


                    <?php if (!$stations): ?>

                        <div class="empty">
                            No gasoline stations found.
                        </div>

                    <?php else: ?>

                        <div class="table-wrapper">

                            <table id="stationTable">

                                <thead>

                                    <tr>

                                        <th>
                                            Picture
                                        </th>

                                        <th>
                                            Station
                                        </th>

                                        <th>
                                            Manager ID
                                        </th>

                                        <th>
                                            Barangay
                                        </th>

                                        <th>
                                            Status
                                        </th>

                                        <th>
                                            Action
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>

                                <?php foreach ($stations as $station): ?>

                                    <tr
                                        class="station-row"
                                    >

                                        <td>

                                            <?php if (
                                                !empty($station['image'])
                                            ): ?>

                                                <?php

                                                $stationImage =
                                                    (string)$station['image'];

                                                if (
                                                    !str_starts_with(
                                                        $stationImage,
                                                        'http://'
                                                    ) &&
                                                    !str_starts_with(
                                                        $stationImage,
                                                        'https://'
                                                    ) &&
                                                    !str_starts_with(
                                                        $stationImage,
                                                        '/'
                                                    )
                                                ) {
                                                    $stationImage =
                                                        '../../uploads/stations/' .
                                                        $stationImage;
                                                }

                                                ?>

                                                <img
                                                    src="<?= e($stationImage) ?>"
                                                    alt="Station"
                                                    class="station-thumb"
                                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                                >

                                                <div
                                                    class="no-thumb"
                                                    style="display:none;"
                                                >
                                                    No Image
                                                </div>

                                            <?php else: ?>

                                                <div class="no-thumb">
                                                    No Image
                                                </div>

                                            <?php endif; ?>

                                        </td>


                                        <td>

                                            <strong>
                                                <?= e(
                                                    $station['name']
                                                ) ?>
                                            </strong>

                                        </td>


                                        <td>
                                            <?= (int)$station['manager_id'] ?>
                                        </td>


                                        <td>
                                            <?= e(
                                                $station['barangay']
                                            ) ?>
                                        </td>


                                        <td>

                                            <span
                                                class="status status-approved"
                                            >
                                                <?= e(
                                                    $station['status']
                                                ) ?>
                                            </span>

                                        </td>


                                        <td>

                                            <div class="actions">

                                                <button
                                                    type="button"
                                                    class="btn btn-primary btn-small station-review-btn"
                                                    data-station="<?= e(
                                                        json_encode(
                                                            $station,
                                                            JSON_HEX_TAG |
                                                            JSON_HEX_APOS |
                                                            JSON_HEX_AMP |
                                                            JSON_HEX_QUOT
                                                        )
                                                    ) ?>"
                                                >
                                                    Review
                                                </button>


                                                <form
                                                    method="POST"
                                                    onsubmit="return confirm('Delete this station? This action cannot be undone.');"
                                                    style="display:inline;"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e($csrfToken) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="form_action"
                                                        value="delete_station"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="station_id"
                                                        value="<?= (int)$station['id'] ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="btn btn-danger btn-small"
                                                    >
                                                        Delete
                                                    </button>

                                                </form>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

            </section>

        </section>


    </div>

</main>


<!--
|--------------------------------------------------------------------------
| REVIEW MODAL
|--------------------------------------------------------------------------
-->

<div
    class="modal"
    id="reviewModal"
    aria-hidden="true"
>

    <div class="modal-content">


        <div class="modal-header">

            <h3>
                Station Review
            </h3>

            <button
                type="button"
                class="modal-close"
                id="closeReviewModal"
                aria-label="Close"
            >
                &times;
            </button>

        </div>


        <div class="modal-body">


            <!-- STATION IMAGE -->

            <div class="station-image-wrapper">

                <img
                    src=""
                    alt="Station picture"
                    class="station-image"
                    id="reviewStationImage"
                    style="display:none;"
                >

                <div
                    class="no-station-image"
                    id="noStationImage"
                >
                    No station picture available.
                </div>

            </div>


            <!-- STATUS -->

            <div class="review-status">

                <span
                    class="status"
                    id="reviewStatus"
                >
                    Pending
                </span>

            </div>


            <!-- DETAILS -->

            <div class="details-grid">


                <div class="detail-box">

                    <div class="detail-label">
                        Station Name
                    </div>

                    <div
                        class="detail-value"
                        id="detailStationName"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        Manager
                    </div>

                    <div
                        class="detail-value"
                        id="detailManager"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        Manager Email
                    </div>

                    <div
                        class="detail-value"
                        id="detailManagerEmail"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        Contact Number
                    </div>

                    <div
                        class="detail-value"
                        id="detailContact"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        Address
                    </div>

                    <div
                        class="detail-value"
                        id="detailAddress"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        Barangay
                    </div>

                    <div
                        class="detail-value"
                        id="detailBarangay"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        Operating Hours
                    </div>

                    <div
                        class="detail-value"
                        id="detailOperatingHours"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        Latitude
                    </div>

                    <div
                        class="detail-value"
                        id="detailLatitude"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        Longitude
                    </div>

                    <div
                        class="detail-value"
                        id="detailLongitude"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        Diesel Price
                    </div>

                    <div
                        class="detail-value"
                        id="detailDieselPrice"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        Unleaded Price
                    </div>

                    <div
                        class="detail-value"
                        id="detailUnleadedPrice"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        Premium Price
                    </div>

                    <div
                        class="detail-value"
                        id="detailPremiumPrice"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        LPG Available
                    </div>

                    <div
                        class="detail-value"
                        id="detailLpgAvailable"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box">

                    <div class="detail-label">
                        LPG Price
                    </div>

                    <div
                        class="detail-value"
                        id="detailLpgPrice"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box full">

                    <div class="detail-label">
                        Description
                    </div>

                    <div
                        class="detail-value"
                        id="detailDescription"
                    >
                        —
                    </div>

                </div>


                <div class="detail-box full">

                    <div class="detail-label">
                        Admin Feedback
                    </div>

                    <div
                        class="detail-value"
                        id="detailFeedback"
                    >
                        —
                    </div>

                </div>


            </div>

        </div>


        <!-- MODAL FOOTER -->

        <div class="modal-footer">


            <!-- CLOSE -->

            <button
                type="button"
                class="btn btn-secondary"
                id="closeModalBottom"
            >
                Close
            </button>


            <!-- REJECT -->

            <form
                method="POST"
                class="modal-action-form"
                id="rejectForm"
                onsubmit="return confirm('Reject this station request?');"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="form_action"
                    value="reject_request"
                >

                <input
                    type="hidden"
                    name="request_id"
                    id="rejectRequestId"
                    value=""
                >

                <button
                    type="submit"
                    class="btn btn-danger"
                >
                    Reject
                </button>

            </form>


            <!-- APPROVE -->

            <form
                method="POST"
                class="modal-action-form"
                id="approveForm"
                onsubmit="return confirm('Approve this station request?');"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="form_action"
                    value="approve_request"
                >

                <input
                    type="hidden"
                    name="request_id"
                    id="approveRequestId"
                    value=""
                >

                <button
                    type="submit"
                    class="btn btn-success"
                >
                    Approve
                </button>

            </form>


            <!-- DELETE APPROVED -->

            <form
                method="POST"
                class="modal-action-form"
                id="deleteRequestForm"
                onsubmit="return confirm('Delete this approved station? This will permanently remove the station and its request.');"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <input
                    type="hidden"
                    name="form_action"
                    value="delete_station_request"
                >

                <input
                    type="hidden"
                    name="request_id"
                    id="deleteRequestId"
                    value=""
                >

                <button
                    type="submit"
                    class="btn btn-danger"
                >
                    Delete
                </button>

            </form>


        </div>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| SIDEBAR
|--------------------------------------------------------------------------
*/

const sidebar =
    document.getElementById('sidebar');

const sidebarToggle =
    document.getElementById('sidebarToggle');

const sidebarOverlay =
    document.getElementById('sidebarOverlay');


function openSidebar()
{
    sidebar.classList.add('open');

    sidebarOverlay.classList.add('show');

    document.body.classList.add(
        'sidebar-open'
    );
}


function closeSidebar()
{
    sidebar.classList.remove('open');

    sidebarOverlay.classList.remove(
        'show'
    );

    document.body.classList.remove(
        'sidebar-open'
    );
}


sidebarToggle.addEventListener(
    'click',
    function() {

        if (
            sidebar.classList.contains('open')
        ) {
            closeSidebar();
        } else {
            openSidebar();
        }

    }
);


sidebarOverlay.addEventListener(
    'click',
    closeSidebar
);


/*
|--------------------------------------------------------------------------
| PAGE NAVIGATION
|--------------------------------------------------------------------------
*/

const navLinks =
    document.querySelectorAll(
        '.nav-link[data-section]'
    );

const pageSections =
    document.querySelectorAll(
        '.page-section'
    );

const topbarDescription =
    document.getElementById(
        'topbarDescription'
    );


const sectionDescriptions = {

    dashboardSection:
        'Overview of your GasGo system',

    managerSection:
        'Manage gasoline station managers',

    stationSection:
        'Manage registered gasoline stations'

};


navLinks.forEach(
    function(link) {

        link.addEventListener(
            'click',
            function() {

                const sectionId =
                    this.dataset.section;

                /*
                |----------------------------------------------------------------------
                | REMOVE ACTIVE
                |----------------------------------------------------------------------
                */

                navLinks.forEach(
                    function(item) {
                        item.classList.remove(
                            'active'
                        );
                    }
                );


                pageSections.forEach(
                    function(section) {
                        section.classList.remove(
                            'active'
                        );
                    }
                );


                /*
                |----------------------------------------------------------------------
                | ADD ACTIVE
                |----------------------------------------------------------------------
                */

                this.classList.add(
                    'active'
                );

                const section =
                    document.getElementById(
                        sectionId
                    );

                if (section) {

                    section.classList.add(
                        'active'
                    );
                }


                /*
                |----------------------------------------------------------------------
                | UPDATE TOPBAR
                |----------------------------------------------------------------------
                */

                topbarDescription.textContent =
                    sectionDescriptions[
                        sectionId
                    ] || 'GasGo Administrator Dashboard';


                /*
                |----------------------------------------------------------------------
                | CLOSE MOBILE SIDEBAR
                |----------------------------------------------------------------------
                */

                closeSidebar();


                /*
                |----------------------------------------------------------------------
                | SCROLL TOP
                |----------------------------------------------------------------------
                */

                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });

            }
        );

    }
);



/*
|--------------------------------------------------------------------------
| CLICKABLE OVERVIEW CARDS
|--------------------------------------------------------------------------
*/

const overviewCards =
    document.querySelectorAll(
        '.stat-card[data-overview-action]'
    );


function openDashboardSection(
    sectionId
) {

    const navButton =
        document.querySelector(
            '.nav-link[data-section="' +
            sectionId +
            '"]'
        );

    if (navButton) {
        navButton.click();
    }

}


function showRequestStatusFromOverview(
    status
) {

    /*
    |--------------------------------------------------------------------------
    | KEEP DASHBOARD SECTION ACTIVE
    |--------------------------------------------------------------------------
    */

    openDashboardSection(
        'dashboardSection'
    );


    const requestRows =
        document.querySelectorAll(
            '#requestTable tbody .request-row'
        );

    const requestSearch =
        document.getElementById(
            'requestSearch'
        );

    const requestSearchCount =
        document.getElementById(
            'requestSearchCount'
        );

    const filterNotice =
        document.getElementById(
            'overviewRequestFilter'
        );


    /*
    |--------------------------------------------------------------------------
    | CLEAR NORMAL TEXT SEARCH
    |--------------------------------------------------------------------------
    */

    if (requestSearch) {
        requestSearch.value = '';
    }


    let visible = 0;


    requestRows.forEach(
        function(row) {

            const rowStatus =
                (
                    row.dataset.requestStatus ||
                    ''
                ).toLowerCase();

            const shouldShow =
                status === 'all' ||
                rowStatus === status;

            row.style.display =
                shouldShow
                    ? ''
                    : 'none';

            if (shouldShow) {
                visible++;
            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | UPDATE RESULT COUNT
    |--------------------------------------------------------------------------
    */

    if (requestSearchCount) {

        requestSearchCount.textContent =
            visible +
            ' ' +
            (
                visible === 1
                    ? 'shop'
                    : 'shops'
            ) +
            ' shown';

    }


    /*
    |--------------------------------------------------------------------------
    | SHOW ACTIVE OVERVIEW FILTER
    |--------------------------------------------------------------------------
    */

    if (filterNotice) {

        const label =
            status === 'pending'
                ? 'Pending shops'
                : (
                    status === 'approved'
                        ? 'Approved station requests'
                        : 'All station requests'
                );

        filterNotice.textContent =
            label +
            ': ' +
            visible;

        filterNotice.style.display =
            'block';

    }


    /*
    |--------------------------------------------------------------------------
    | SCROLL TO STATION REQUEST INFORMATION
    |--------------------------------------------------------------------------
    */

    const requestTable =
        document.getElementById(
            'requestTable'
        );

    const target =
        requestTable
            ? requestTable.closest(
                '.section'
            )
            : null;

    if (target) {

        setTimeout(
            function() {

                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });

            },
            120
        );

    }

}


function handleOverviewCard(
    card
) {

    const action =
        card.dataset.overviewAction;


    if (action === 'managers') {

        openDashboardSection(
            'managerSection'
        );

        return;

    }


    if (action === 'stations') {

        openDashboardSection(
            'stationSection'
        );

        return;

    }


    if (action === 'pending') {

        showRequestStatusFromOverview(
            'pending'
        );

        return;

    }


    if (action === 'approved') {

        showRequestStatusFromOverview(
            'approved'
        );

    }

}


overviewCards.forEach(
    function(card) {

        card.addEventListener(
            'click',
            function() {

                handleOverviewCard(
                    this
                );

            }
        );


        card.addEventListener(
            'keydown',
            function(event) {

                if (
                    event.key === 'Enter' ||
                    event.key === ' '
                ) {

                    event.preventDefault();

                    handleOverviewCard(
                        this
                    );

                }

            }
        );

    }
);


/*
|--------------------------------------------------------------------------
| SEARCH HELPER
|--------------------------------------------------------------------------
*/

function setupTableSearch(
    inputId,
    tableId,
    rowClass,
    countId
) {

    const input =
        document.getElementById(
            inputId
        );

    const table =
        document.getElementById(
            tableId
        );

    const count =
        document.getElementById(
            countId
        );

    if (
        !input ||
        !table
    ) {
        return;
    }

    const rows =
        table.querySelectorAll(
            'tbody .' + rowClass
        );


    function performSearch()
    {
        const search =
            input.value
                .trim()
                .toLowerCase();

        let visible =
            0;


        rows.forEach(
            function(row) {

                const text =
                    row.textContent
                        .toLowerCase();

                const matches =
                    search === '' ||
                    text.includes(search);

                row.style.display =
                    matches
                        ? ''
                        : 'none';

                if (matches) {
                    visible++;
                }

            }
        );


        if (count) {

            if (search === '') {

                count.textContent =
                    rows.length +
                    ' result' +
                    (
                        rows.length === 1
                            ? ''
                            : 's'
                    );

            } else {

                count.textContent =
                    visible +
                    ' result' +
                    (
                        visible === 1
                            ? ''
                            : 's'
                    ) +
                    ' found';

            }

        }
    }


    input.addEventListener(
        'input',
        performSearch
    );


    performSearch();
}


/*
|--------------------------------------------------------------------------
| REQUEST SEARCH
|--------------------------------------------------------------------------
*/

setupTableSearch(
    'requestSearch',
    'requestTable',
    'request-row',
    'requestSearchCount'
);


const requestSearchInput =
    document.getElementById(
        'requestSearch'
    );


if (requestSearchInput) {

    requestSearchInput.addEventListener(
        'input',
        function() {

            const filterNotice =
                document.getElementById(
                    'overviewRequestFilter'
                );

            if (filterNotice) {
                filterNotice.style.display =
                    'none';
            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| MANAGER SEARCH
|--------------------------------------------------------------------------
*/

setupTableSearch(
    'managerSearch',
    'managerTable',
    'manager-row',
    'managerSearchCount'
);


/*
|--------------------------------------------------------------------------
| MANAGER SHOP STATUS FILTER
|--------------------------------------------------------------------------
*/

const managerShopFilterButtons =
    document.querySelectorAll(
        '[data-manager-filter]'
    );

const managerSearchInputForFilter =
    document.getElementById(
        'managerSearch'
    );

let activeManagerShopFilter =
    'all';


function applyManagerShopFilters()
{
    const managerTable =
        document.getElementById(
            'managerTable'
        );

    const managerSearchCount =
        document.getElementById(
            'managerSearchCount'
        );

    if (!managerTable) {
        return;
    }


    const rows =
        managerTable.querySelectorAll(
            'tbody .manager-row'
        );

    const searchTerm =
        managerSearchInputForFilter
            ? managerSearchInputForFilter.value
                .trim()
                .toLowerCase()
            : '';

    let visible = 0;


    rows.forEach(
        function(row) {

            const shopState =
                row.dataset.managerShopState ||
                'no_request';

            const matchesStatus =
                activeManagerShopFilter === 'all' ||
                shopState === activeManagerShopFilter;

            const matchesSearch =
                searchTerm === '' ||
                row.textContent
                    .toLowerCase()
                    .includes(searchTerm);

            const shouldShow =
                matchesStatus &&
                matchesSearch;

            row.style.display =
                shouldShow
                    ? ''
                    : 'none';

            if (shouldShow) {
                visible++;
            }

        }
    );


    if (managerSearchCount) {

        managerSearchCount.textContent =
            visible +
            ' manager' +
            (
                visible === 1
                    ? ''
                    : 's'
            ) +
            ' shown';

    }

}


managerShopFilterButtons.forEach(
    function(button) {

        button.addEventListener(
            'click',
            function() {

                activeManagerShopFilter =
                    this.dataset.managerFilter ||
                    'all';

                managerShopFilterButtons.forEach(
                    function(item) {
                        item.classList.remove(
                            'active'
                        );
                    }
                );

                this.classList.add(
                    'active'
                );

                applyManagerShopFilters();

            }
        );

    }
);


if (managerSearchInputForFilter) {

    managerSearchInputForFilter.addEventListener(
        'input',
        applyManagerShopFilters
    );

}


applyManagerShopFilters();


/*
|--------------------------------------------------------------------------
| GASOLINE STATION SEARCH
|--------------------------------------------------------------------------
*/

setupTableSearch(
    'stationSearch',
    'stationTable',
    'station-row',
    'stationSearchCount'
);


/*
|--------------------------------------------------------------------------
| REVIEW MODAL
|--------------------------------------------------------------------------
*/

const reviewModal =
    document.getElementById(
        'reviewModal'
    );

const closeReviewModalButton =
    document.getElementById(
        'closeReviewModal'
    );

const closeModalBottom =
    document.getElementById(
        'closeModalBottom'
    );


const reviewStationImage =
    document.getElementById(
        'reviewStationImage'
    );

const noStationImage =
    document.getElementById(
        'noStationImage'
    );


const reviewStatus =
    document.getElementById(
        'reviewStatus'
    );


const approveForm =
    document.getElementById(
        'approveForm'
    );

const rejectForm =
    document.getElementById(
        'rejectForm'
    );

const deleteRequestForm =
    document.getElementById(
        'deleteRequestForm'
    );


const approveRequestId =
    document.getElementById(
        'approveRequestId'
    );

const rejectRequestId =
    document.getElementById(
        'rejectRequestId'
    );

const deleteRequestId =
    document.getElementById(
        'deleteRequestId'
    );


/*
|--------------------------------------------------------------------------
| DETAIL ELEMENTS
|--------------------------------------------------------------------------
*/

const detailStationName =
    document.getElementById(
        'detailStationName'
    );

const detailManager =
    document.getElementById(
        'detailManager'
    );

const detailManagerEmail =
    document.getElementById(
        'detailManagerEmail'
    );

const detailContact =
    document.getElementById(
        'detailContact'
    );

const detailAddress =
    document.getElementById(
        'detailAddress'
    );

const detailBarangay =
    document.getElementById(
        'detailBarangay'
    );

const detailOperatingHours =
    document.getElementById(
        'detailOperatingHours'
    );

const detailLatitude =
    document.getElementById(
        'detailLatitude'
    );

const detailLongitude =
    document.getElementById(
        'detailLongitude'
    );

const detailDieselPrice =
    document.getElementById(
        'detailDieselPrice'
    );

const detailUnleadedPrice =
    document.getElementById(
        'detailUnleadedPrice'
    );

const detailPremiumPrice =
    document.getElementById(
        'detailPremiumPrice'
    );

const detailLpgAvailable =
    document.getElementById(
        'detailLpgAvailable'
    );

const detailLpgPrice =
    document.getElementById(
        'detailLpgPrice'
    );

const detailDescription =
    document.getElementById(
        'detailDescription'
    );

const detailFeedback =
    document.getElementById(
        'detailFeedback'
    );


/*
|--------------------------------------------------------------------------
| DISPLAY VALUE
|--------------------------------------------------------------------------
*/

function displayValue(value)
{
    if (
        value === null ||
        value === undefined ||
        String(value).trim() === ''
    ) {
        return '—';
    }

    return String(value);
}


/*
|--------------------------------------------------------------------------
| FORMAT PRICE
|--------------------------------------------------------------------------
*/

function formatPrice(value)
{
    if (
        value === null ||
        value === undefined ||
        String(value).trim() === ''
    ) {
        return '—';
    }

    const number =
        Number(value);

    if (
        Number.isNaN(number)
    ) {
        return String(value);
    }

    return '₱' +
        number.toFixed(2);
}


/*
|--------------------------------------------------------------------------
| IMAGE URL
|--------------------------------------------------------------------------
*/

const IMAGE_FOLDER =
    '../../uploads/stations/';


function getStationImageUrl(image)
{
    if (!image) {
        return '';
    }

    image =
        String(image).trim();

    if (image === '') {
        return '';
    }

    if (
        image.startsWith('http://') ||
        image.startsWith('https://') ||
        image.startsWith('/')
    ) {
        return image;
    }

    return IMAGE_FOLDER +
        encodeURIComponent(image);
}


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

function setReviewStatus(status)
{
    const normalized =
        String(
            status || 'Pending'
        )
        .trim()
        .toLowerCase();


    reviewStatus.textContent =
        status || 'Pending';


    reviewStatus.className =
        'status';


    if (
        normalized === 'approved'
    ) {

        reviewStatus.classList.add(
            'status-approved'
        );

    }
    else if (
        normalized === 'rejected'
    ) {

        reviewStatus.classList.add(
            'status-rejected'
        );

    }
    else if (
        normalized === 'needs changes'
    ) {

        reviewStatus.classList.add(
            'status-needs'
        );

    }
    else {

        reviewStatus.classList.add(
            'status-pending'
        );
    }
}


/*
|--------------------------------------------------------------------------
| SET STATION IMAGE
|--------------------------------------------------------------------------
*/

function setReviewImage(image)
{
    const imageUrl =
        getStationImageUrl(
            image
        );


    if (imageUrl !== '') {

        reviewStationImage.src =
            imageUrl;

        reviewStationImage.style.display =
            'block';

        noStationImage.style.display =
            'none';


        reviewStationImage.onerror =
            function() {

                reviewStationImage.style.display =
                    'none';

                noStationImage.style.display =
                    'flex';

            };

    }
    else {

        reviewStationImage.removeAttribute(
            'src'
        );

        reviewStationImage.style.display =
            'none';

        noStationImage.style.display =
            'flex';
    }
}


/*
|--------------------------------------------------------------------------
| FILL REVIEW INFORMATION
|--------------------------------------------------------------------------
*/

function fillReviewInformation(data)
{
    detailStationName.textContent =
        displayValue(
            data.station_name ||
            data.name
        );

    detailManager.textContent =
        displayValue(
            data.manager_name
        );

    detailManagerEmail.textContent =
        displayValue(
            data.manager_email
        );

    detailContact.textContent =
        displayValue(
            data.contact_number
        );

    detailAddress.textContent =
        displayValue(
            data.address
        );

    detailBarangay.textContent =
        displayValue(
            data.barangay
        );

    detailOperatingHours.textContent =
        displayValue(
            data.operating_hours
        );

    detailLatitude.textContent =
        displayValue(
            data.latitude
        );

    detailLongitude.textContent =
        displayValue(
            data.longitude
        );

    detailDieselPrice.textContent =
        formatPrice(
            data.diesel_price
        );

    detailUnleadedPrice.textContent =
        formatPrice(
            data.unleaded_price
        );

    detailPremiumPrice.textContent =
        formatPrice(
            data.premium_price
        );

    detailLpgAvailable.textContent =
        Number(
            data.lpg_available
        ) === 1
            ? 'Yes'
            : 'No';

    detailLpgPrice.textContent =
        formatPrice(
            data.lpg_price
        );

    detailDescription.textContent =
        displayValue(
            data.description
        );

    detailFeedback.textContent =
        displayValue(
            data.admin_feedback
        );
}


/*
|--------------------------------------------------------------------------
| OPEN REQUEST REVIEW
|--------------------------------------------------------------------------
*/

function openRequestReview(request)
{
    fillReviewInformation(
        request
    );


    setReviewStatus(
        request.status
    );


    /*
    |--------------------------------------------------------------------------
    | IMAGE
    |--------------------------------------------------------------------------
    */

    setReviewImage(
        request.image
    );


    /*
    |--------------------------------------------------------------------------
    | REQUEST IDS
    |--------------------------------------------------------------------------
    */

    approveRequestId.value =
        request.id || '';

    rejectRequestId.value =
        request.id || '';

    deleteRequestId.value =
        request.id || '';


    /*
    |--------------------------------------------------------------------------
    | STATUS
    |--------------------------------------------------------------------------
    */

    const status =
        String(
            request.status || 'Pending'
        )
        .trim()
        .toLowerCase();


    /*
    |--------------------------------------------------------------------------
    | PENDING
    |--------------------------------------------------------------------------
    |
    | Show:
    | Approve
    | Reject
    | Close
    |
    */

    if (status === 'pending') {

        approveForm.style.display =
            'inline-block';

        rejectForm.style.display =
            'inline-block';

        deleteRequestForm.style.display =
            'none';

    }


    /*
    |--------------------------------------------------------------------------
    | APPROVED
    |--------------------------------------------------------------------------
    |
    | Show:
    | Delete
    | Close
    |
    */

    else if (status === 'approved') {

        approveForm.style.display =
            'none';

        rejectForm.style.display =
            'none';

        deleteRequestForm.style.display =
            'inline-block';

    }


    /*
    |--------------------------------------------------------------------------
    | REJECTED
    |--------------------------------------------------------------------------
    |
    | Show:
    | Close only
    |
    */

    else {

        approveForm.style.display =
            'none';

        rejectForm.style.display =
            'none';

        deleteRequestForm.style.display =
            'none';
    }


    /*
    |--------------------------------------------------------------------------
    | OPEN
    |--------------------------------------------------------------------------
    */

    reviewModal.classList.add(
        'show'
    );

    reviewModal.setAttribute(
        'aria-hidden',
        'false'
    );

    document.body.style.overflow =
        'hidden';
}


/*
|--------------------------------------------------------------------------
| OPEN GASOLINE STATION REVIEW
|--------------------------------------------------------------------------
|
| This is used by the Review button inside the Gasoline Stations page.
|
*/

function openStationReview(station)
{
    fillReviewInformation(
        station
    );


    /*
    |----------------------------------------------------------------------
    | Approved stations are already registered.
    |----------------------------------------------------------------------
    */

    setReviewStatus(
        station.status || 'Approved'
    );


    /*
    |----------------------------------------------------------------------
    | IMAGE
    |----------------------------------------------------------------------
    */

    setReviewImage(
        station.image
    );


    /*
    |----------------------------------------------------------------------
    | Hide request action buttons.
    |----------------------------------------------------------------------
    */

    approveForm.style.display =
        'none';

    rejectForm.style.display =
        'none';

    /*
    |----------------------------------------------------------------------
    | The station page uses its own delete button.
    | The modal only has Close.
    |----------------------------------------------------------------------
    */

    deleteRequestForm.style.display =
        'none';


    /*
    |----------------------------------------------------------------------
    | SHOW
    |----------------------------------------------------------------------
    */

    reviewModal.classList.add(
        'show'
    );

    reviewModal.setAttribute(
        'aria-hidden',
        'false'
    );

    document.body.style.overflow =
        'hidden';
}


/*
|--------------------------------------------------------------------------
| CLOSE MODAL
|--------------------------------------------------------------------------
*/

function closeReviewModal()
{
    reviewModal.classList.remove(
        'show'
    );

    reviewModal.setAttribute(
        'aria-hidden',
        'true'
    );

    document.body.style.overflow =
        '';
}


/*
|--------------------------------------------------------------------------
| REQUEST REVIEW BUTTONS
|--------------------------------------------------------------------------
*/

document.querySelectorAll(
    '.review-btn'
).forEach(
    function(button) {

        button.addEventListener(
            'click',
            function() {

                try {

                    const request =
                        JSON.parse(
                            this.dataset.request
                        );

                    openRequestReview(
                        request
                    );

                }
                catch (error) {

                    console.error(
                        error
                    );

                    alert(
                        'Unable to open this station request.'
                    );
                }

            }
        );

    }
);


/*
|--------------------------------------------------------------------------
| GASOLINE STATION REVIEW BUTTONS
|--------------------------------------------------------------------------
*/

document.querySelectorAll(
    '.station-review-btn'
).forEach(
    function(button) {

        button.addEventListener(
            'click',
            function() {

                try {

                    const station =
                        JSON.parse(
                            this.dataset.station
                        );

                    openStationReview(
                        station
                    );

                }
                catch (error) {

                    console.error(
                        error
                    );

                    alert(
                        'Unable to open this gasoline station.'
                    );
                }

            }
        );

    }
);


/*
|--------------------------------------------------------------------------
| CLOSE BUTTONS
|--------------------------------------------------------------------------
*/

closeReviewModalButton.addEventListener(
    'click',
    closeReviewModal
);


closeModalBottom.addEventListener(
    'click',
    closeReviewModal
);


/*
|--------------------------------------------------------------------------
| CLOSE OUTSIDE MODAL
|--------------------------------------------------------------------------
*/

reviewModal.addEventListener(
    'click',
    function(event) {

        if (
            event.target === reviewModal
        ) {
            closeReviewModal();
        }

    }
);


/*
|--------------------------------------------------------------------------
| ESC KEY
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'keydown',
    function(event) {

        if (
            event.key === 'Escape' &&
            reviewModal.classList.contains('show')
        ) {

            closeReviewModal();

        }

    }
);

</script>

</body>

</html>