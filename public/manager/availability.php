<?php

declare(strict_types=1);

session_start();

if (empty($_SESSION['manager_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';

$managerId = (int)$_SESSION['manager_id'];


if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];


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

if (!$station) {
    header(
        'Location: dashboard.php?error=' .
        urlencode('You do not have an approved station yet.')
    );
    exit;
}


$stmt = $pdo->prepare("
    SELECT *
    FROM station_lpg_products
    WHERE station_id = ?
    ORDER BY kg ASC
");

$stmt->execute([
    (int)$station['id']
]);

$lpgProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);


function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
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

    <title>Product Availability - GasGo</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>

        body {
            margin: 0;
            background: #f5f7fa;
        }

        .page {
            min-height: 100vh;
            padding: 30px 20px 50px;
        }

        .container {
            max-width: 800px;
            margin: auto;
        }

        .card {
            background: white;
            border-radius: 14px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,.07);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .header h1 {
            margin: 0;
        }

        .back {
            text-decoration: none;
            color: #555;
            font-weight: 600;
        }

        .station-name {
            color: #666;
            margin-top: 5px;
        }

        .product {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;

            padding: 18px 0;

            border-bottom: 1px solid #eee;
        }

        .product:last-child {
            border-bottom: none;
        }

        .product-info h3 {
            margin: 0 0 5px;
        }

        .price {
            color: #666;
        }

        .switch {
            position: relative;
            width: 54px;
            height: 30px;
            flex-shrink: 0;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            inset: 0;
            cursor: pointer;
            background: #d1d5db;
            border-radius: 999px;
            transition: .2s;
        }

        .slider:before {
            content: "";
            position: absolute;
            height: 22px;
            width: 22px;
            left: 4px;
            top: 4px;
            background: white;
            border-radius: 50%;
            transition: .2s;
        }

        input:checked + .slider {
            background: #16a34a;
        }

        input:checked + .slider:before {
            transform: translateX(24px);
        }

        .save-btn {
            width: 100%;
            border: none;
            background: #16a34a;
            color: white;
            padding: 15px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
        }

        .save-btn:hover {
            background: #15803d;
        }

        .description {
            color: #666;
            line-height: 1.6;
        }

        @media (max-width: 600px) {

            .page {
                padding: 15px 10px;
            }

            .header {
                align-items: flex-start;
                flex-direction: column;
            }

        }

    </style>

</head>

<body>

<main class="page">

<div class="container">


    <div class="card">

        <div class="header">

            <div>

                <h1>
                    Product Availability
                </h1>

                <div class="station-name">
                    <?= e($station['name']) ?>
                </div>

            </div>

            <a
                href="dashboard.php"
                class="back"
            >
                ← Dashboard
            </a>

        </div>

        <p class="description">

            Turn products on when they are currently available
            at your station. Turn them off when the product is
            temporarily unavailable.

        </p>

    </div>


    <form
        action="availability_action.php"
        method="POST"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >


        <div class="card">

            <h2>
                Fuel Products
            </h2>


            <div class="product">

                <div class="product-info">

                    <h3>
                        Premium
                    </h3>

                    <div class="price">

                        ₱<?= number_format(
                            (float)$station['premium_price'],
                            2
                        ) ?>

                    </div>

                </div>

                <label class="switch">

                    <input
                        type="checkbox"
                        name="premium_available"
                        value="1"

                        <?= (int)$station['premium_available'] === 1
                            ? 'checked'
                            : '' ?>
                    >

                    <span class="slider"></span>

                </label>

            </div>


            <div class="product">

                <div class="product-info">

                    <h3>
                        Regular
                    </h3>

                    <div class="price">

                        ₱<?= number_format(
                            (float)$station['unleaded_price'],
                            2
                        ) ?>

                    </div>

                </div>

                <label class="switch">

                    <input
                        type="checkbox"
                        name="regular_available"
                        value="1"

                        <?= (int)$station['regular_available'] === 1
                            ? 'checked'
                            : '' ?>
                    >

                    <span class="slider"></span>

                </label>

            </div>


            <div class="product">

                <div class="product-info">

                    <h3>
                        Diesel
                    </h3>

                    <div class="price">

                        ₱<?= number_format(
                            (float)$station['diesel_price'],
                            2
                        ) ?>

                    </div>

                </div>

                <label class="switch">

                    <input
                        type="checkbox"
                        name="diesel_available"
                        value="1"

                        <?= (int)$station['diesel_available'] === 1
                            ? 'checked'
                            : '' ?>
                    >

                    <span class="slider"></span>

                </label>

            </div>

        </div>


        <div class="card">

            <h2>
                LPG Products
            </h2>

            <?php if (!$lpgProducts): ?>

                <p>
                    No LPG products have been added to this station.
                </p>

            <?php else: ?>

                <?php foreach ($lpgProducts as $lpg): ?>

                    <div class="product">

                        <div class="product-info">

                            <h3>
                                <?= number_format(
                                    (float)$lpg['kg'],
                                    2
                                ) ?> kg LPG
                            </h3>

                            <div class="price">

                                ₱<?= number_format(
                                    (float)$lpg['price'],
                                    2
                                ) ?>

                            </div>

                        </div>


                        <label class="switch">

                            <input
                                type="checkbox"
                                name="lpg_available[]"
                                value="<?= (int)$lpg['id'] ?>"

                                <?= (int)$lpg['available'] === 1
                                    ? 'checked'
                                    : '' ?>
                            >

                            <span class="slider"></span>

                        </label>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>


        <button
            type="submit"
            class="save-btn"
        >
            Save Availability
        </button>

    </form>


</div>

</main>

</body>

</html>