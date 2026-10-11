<?php

session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';

$request_id = (int)($_GET['id'] ?? 0);

if ($request_id <= 0) {
    header('Location: dashboard.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| GET REQUEST + MANAGER
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        r.*,

        m.full_name AS manager_name,
        m.email AS manager_email

    FROM station_requests r

    INNER JOIN managers m
        ON m.id = r.manager_id

    WHERE r.id = ?

    LIMIT 1
");

$stmt->execute([$request_id]);

$request = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$request) {
    http_response_code(404);
    exit('Station request not found.');
}


/*
|--------------------------------------------------------------------------
| GET LPG PRODUCTS
|--------------------------------------------------------------------------
*/

$lpgProducts = [];

try {

    $lpgStmt = $pdo->prepare("
        SELECT
            id,
            kg,
            price,
            available,
            created_at
        FROM station_request_lpg_products
        WHERE request_id = ?
        ORDER BY kg ASC
    ");

    $lpgStmt->execute([$request_id]);

    $lpgProducts = $lpgStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    /*
     * If the LPG table does not exist yet,
     * simply show no detailed LPG products.
     */
    $lpgProducts = [];

}


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function displayValue($value, string $fallback = 'Not provided'): string
{
    if (
        $value === null ||
        $value === ''
    ) {
        return e($fallback);
    }

    return e($value);
}


function moneyValue($value): string
{
    if (
        $value === null ||
        $value === ''
    ) {
        return 'N/A';
    }

    return '₱' . number_format(
        (float)$value,
        2
    );
}


$status = strtolower(
    trim($request['status'] ?? 'pending')
);

$statusClass = 'status-pending';

if ($status === 'approved') {

    $statusClass = 'status-approved';

} elseif ($status === 'rejected') {

    $statusClass = 'status-rejected';

} elseif (
    $status === 'changes' ||
    $status === 'needs changes'
) {

    $statusClass = 'status-changes';

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
        Station Request Details | GasGo Admin
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f5f7f6;
            color: #17201c;
            font-family: Arial, Helvetica, sans-serif;
        }

        .page {
            min-height: 100vh;
        }

        .header {
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
        }

        .header-inner {
            max-width: 1100px;
            margin: 0 auto;
            padding: 16px 24px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 15px;
        }

        .header-title h1 {
            margin: 0;
            font-size: 21px;
        }

        .header-title p {
            margin: 4px 0 0;
            color: #718078;
            font-size: 13px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            padding: 10px 14px;

            border-radius: 8px;

            background: #f0f4f2;
            color: #34423a;

            border: 1px solid #dfe7e2;

            text-decoration: none;

            font-size: 13px;
            font-weight: 800;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 30px 24px 60px;
        }

        .station-header {
            background: #ffffff;
            border: 1px solid #e5ebe7;
            border-radius: 15px;

            padding: 24px;

            margin-bottom: 20px;

            display: flex;
            align-items: flex-start;
            justify-content: space-between;

            gap: 20px;

            box-shadow:
                0 3px 12px rgba(0, 0, 0, 0.035);
        }

        .station-header h2 {
            margin: 0 0 7px;
            font-size: 27px;
        }

        .station-header p {
            margin: 0;
            color: #718078;
            font-size: 14px;
        }

        .status {
            display: inline-flex;
            align-items: center;

            padding: 7px 12px;

            border-radius: 999px;

            font-size: 12px;
            font-weight: 800;

            white-space: nowrap;
        }

        .status-pending {
            background: #fff4d9;
            color: #a66a00;
        }

        .status-approved {
            background: #e3f7eb;
            color: #187747;
        }

        .status-rejected {
            background: #fde8eb;
            color: #c23545;
        }

        .status-changes {
            background: #ffeddc;
            color: #bd6500;
        }

        .section {
            background: #ffffff;
            border: 1px solid #e5ebe7;
            border-radius: 14px;

            padding: 22px;

            margin-bottom: 20px;

            box-shadow:
                0 3px 12px rgba(0, 0, 0, 0.035);
        }

        .section h3 {
            margin: 0 0 18px;

            font-size: 18px;
            font-weight: 800;

            padding-bottom: 12px;

            border-bottom: 1px solid #edf1ee;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        .info-item {
            background: #f8faf9;

            border: 1px solid #edf1ee;
            border-radius: 9px;

            padding: 13px 14px;
        }

        .info-label {
            display: block;

            margin-bottom: 5px;

            color: #718078;

            font-size: 11px;
            font-weight: 800;

            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .info-value {
            font-size: 14px;
            font-weight: 700;

            color: #253129;

            word-break: break-word;
        }

        .price-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }

        .price-card {
            border: 1px solid #e5ebe7;
            border-radius: 11px;

            padding: 17px;

            background: #fafcfb;
        }

        .price-card h4 {
            margin: 0 0 8px;

            font-size: 14px;
        }

        .price {
            font-size: 23px;
            font-weight: 800;

            color: #16834b;
        }

        .lpg-table {
            width: 100%;
            border-collapse: collapse;
        }

        .lpg-table th,
        .lpg-table td {
            padding: 12px 14px;
            text-align: left;

            border-bottom: 1px solid #edf1ee;

            font-size: 13px;
        }

        .lpg-table th {
            background: #f7f9f8;

            color: #68766e;

            font-size: 11px;
            text-transform: uppercase;

            font-weight: 800;
        }

        .availability {
            font-weight: 800;
        }

        .available {
            color: #16834b;
        }

        .unavailable {
            color: #c23545;
        }

        .coordinate-box {
            background: #f7f9f8;

            border: 1px solid #e5ebe7;

            border-radius: 10px;

            padding: 16px;

            font-family: monospace;

            font-size: 14px;
        }

        .feedback {
            background: #fff8e8;

            border: 1px solid #f5dfaa;

            color: #765600;

            border-radius: 10px;

            padding: 14px;

            font-size: 14px;

            line-height: 1.5;
        }

        .actions {
            display: flex;
            gap: 10px;

            flex-wrap: wrap;

            margin-top: 25px;
        }

        .action-form {
            display: flex;
            gap: 8px;

            flex-wrap: wrap;

            width: 100%;
        }

        .feedback-input {
            flex: 1;
            min-width: 230px;

            padding: 11px 12px;

            border: 1px solid #d9e1dc;
            border-radius: 8px;

            font-size: 13px;

            outline: none;
        }

        .feedback-input:focus {
            border-color: #16834b;

            box-shadow:
                0 0 0 3px rgba(22, 131, 75, .10);
        }

        .btn {
            border: none;

            padding: 11px 15px;

            border-radius: 8px;

            cursor: pointer;

            font-size: 13px;
            font-weight: 800;
        }

        .approve {
            background: #16834b;
            color: white;
        }

        .changes {
            background: #f2a20b;
            color: white;
        }

        .reject {
            background: #dc3545;
            color: white;
        }

        .map-link {
            display: inline-block;

            margin-top: 12px;

            color: #16834b;

            font-size: 13px;
            font-weight: 800;

            text-decoration: none;
        }

        @media (max-width: 700px) {

            .header-inner {
                padding: 14px 16px;
            }

            .container {
                padding: 20px 16px 40px;
            }

            .station-header {
                flex-direction: column;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .price-grid {
                grid-template-columns: 1fr;
            }

            .section {
                padding: 17px;
            }

        }

    </style>

</head>


<body>

<div class="page">


    <header class="header">

        <div class="header-inner">

            <div class="header-title">

                <h1>
                    Station Request Details
                </h1>

                <p>
                    Review the station information submitted by the manager.
                </p>

            </div>

            <a
                href="dashboard.php"
                class="back-btn"
            >
                ← Back to Dashboard
            </a>

        </div>

    </header>


    <main class="container">


        <!-- =====================================================
             STATION HEADER
        ====================================================== -->

        <div class="station-header">

            <div>

                <h2>
                    <?= e($request['station_name']) ?>
                </h2>

                <p>
                    Submitted by
                    <strong>
                        <?= e($request['manager_name']) ?>
                    </strong>
                </p>

            </div>


            <span
                class="status <?= $statusClass ?>"
            >
                <?= e(ucwords($request['status'] ?? 'Pending')) ?>
            </span>

        </div>


        <!-- =====================================================
             MANAGER INFORMATION
        ====================================================== -->

        <section class="section">

            <h3>
                Manager Information
            </h3>

            <div class="info-grid">

                <div class="info-item">

                    <span class="info-label">
                        Manager Name
                    </span>

                    <div class="info-value">
                        <?= e($request['manager_name']) ?>
                    </div>

                </div>


                <div class="info-item">

                    <span class="info-label">
                        Email
                    </span>

                    <div class="info-value">
                        <?= e($request['manager_email']) ?>
                    </div>

                </div>

            </div>

        </section>


        <!-- =====================================================
             STATION INFORMATION
        ====================================================== -->

        <section class="section">

            <h3>
                Station Information
            </h3>

            <div class="info-grid">

                <div class="info-item">

                    <span class="info-label">
                        Station Name
                    </span>

                    <div class="info-value">
                        <?= e($request['station_name']) ?>
                    </div>

                </div>


                <div class="info-item">

                    <span class="info-label">
                        Address
                    </span>

                    <div class="info-value">
                        <?= displayValue($request['address'] ?? null) ?>
                    </div>

                </div>


                <div class="info-item">

                    <span class="info-label">
                        Barangay
                    </span>

                    <div class="info-value">
                        <?= displayValue($request['barangay'] ?? null) ?>
                    </div>

                </div>


                <div class="info-item">

                    <span class="info-label">
                        Contact Number
                    </span>

                    <div class="info-value">
                        <?= displayValue($request['contact'] ?? null) ?>
                    </div>

                </div>


                <div class="info-item">

                    <span class="info-label">
                        Operating Hours
                    </span>

                    <div class="info-value">
                        <?= displayValue($request['hours'] ?? null) ?>
                    </div>

                </div>


                <div class="info-item">

                    <span class="info-label">
                        Submitted
                    </span>

                    <div class="info-value">
                        <?= displayValue($request['created_at'] ?? null) ?>
                    </div>

                </div>

            </div>

        </section>


        <!-- =====================================================
             FUEL PRICES
        ====================================================== -->

        <section class="section">

            <h3>
                Fuel Prices
            </h3>

            <div class="price-grid">


                <div class="price-card">

                    <h4>
                        Premium
                    </h4>

                    <div class="price">

                        <?= moneyValue(
                            $request['premium_price'] ?? null
                        ) ?>

                    </div>

                </div>


                <div class="price-card">

                    <h4>
                        Regular / Unleaded
                    </h4>

                    <div class="price">

                        <?= moneyValue(
                            $request['unleaded_price'] ?? null
                        ) ?>

                    </div>

                </div>


                <div class="price-card">

                    <h4>
                        Diesel
                    </h4>

                    <div class="price">

                        <?= moneyValue(
                            $request['diesel_price'] ?? null
                        ) ?>

                    </div>

                </div>


            </div>

        </section>


        <!-- =====================================================
             LPG
        ====================================================== -->

        <section class="section">

            <h3>
                LPG Products
            </h3>


            <?php if (empty($lpgProducts)): ?>

                <p style="color:#718078;">
                    No detailed LPG products were submitted.
                </p>

            <?php else: ?>

                <div style="overflow-x:auto;">

                    <table class="lpg-table">

                        <thead>

                            <tr>

                                <th>
                                    Size
                                </th>

                                <th>
                                    Price
                                </th>

                                <th>
                                    Availability
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($lpgProducts as $lpg): ?>

                            <tr>

                                <td>
                                    <strong>
                                        <?= e($lpg['kg']) ?> kg
                                    </strong>
                                </td>

                                <td>
                                    <?= moneyValue($lpg['price']) ?>
                                </td>

                                <td>

                                    <?php if ((int)$lpg['available'] === 1): ?>

                                        <span class="availability available">
                                            Available
                                        </span>

                                    <?php else: ?>

                                        <span class="availability unavailable">
                                            Unavailable
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>


        <!-- =====================================================
             LOCATION
        ====================================================== -->

        <section class="section">

            <h3>
                Station Location
            </h3>

            <div class="coordinate-box">

                Latitude:
                <?= displayValue(
                    $request['latitude'] ?? null
                ) ?>

                <br>

                Longitude:
                <?= displayValue(
                    $request['longitude'] ?? null
                ) ?>

            </div>


            <?php

            $lat = (float)($request['latitude'] ?? 0);
            $lng = (float)($request['longitude'] ?? 0);

            if ($lat !== 0 && $lng !== 0):

            ?>

                <a
                    class="map-link"
                    target="_blank"
                    rel="noopener"
                    href="https://www.google.com/maps/dir/?api=1&destination=<?= urlencode($lat . ',' . $lng) ?>"
                >
                    📍 Open Location in Google Maps
                </a>

            <?php endif; ?>

        </section>


        <!-- =====================================================
             ADMIN FEEDBACK
        ====================================================== -->

        <?php if (!empty($request['admin_feedback'])): ?>

            <section class="section">

                <h3>
                    Previous Admin Feedback
                </h3>

                <div class="feedback">

                    <?= nl2br(
                        e($request['admin_feedback'])
                    ) ?>

                </div>

            </section>

        <?php endif; ?>


        <!-- =====================================================
             ADMIN ACTIONS
        ====================================================== -->

        <?php if ($status !== 'approved'): ?>

            <section class="section">

                <h3>
                    Review Station Request
                </h3>


                <form
                    method="post"
                    action="request_action.php"
                    class="action-form"
                >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int)$request['id'] ?>"
                    >


                    <input
                        type="text"
                        name="feedback"
                        class="feedback-input"
                        placeholder="Optional admin feedback"
                    >


                    <button
                        type="submit"
                        name="action"
                        value="approve"
                        class="btn approve"
                        onclick="return confirm('Approve this station and make it public?');"
                    >
                        ✓ Approve Station
                    </button>


                    <button
                        type="submit"
                        name="action"
                        value="changes"
                        class="btn changes"
                    >
                        Needs Changes
                    </button>


                    <button
                        type="submit"
                        name="action"
                        value="reject"
                        class="btn reject"
                        onclick="return confirm('Reject this station request?');"
                    >
                        Reject
                    </button>

                </form>

            </section>

        <?php endif; ?>


    </main>

</div>

</body>

</html>