<?php

declare(strict_types=1);

session_start();

if (empty($_SESSION['manager_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';

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
| GET MANAGER'S APPROVED STATION
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT *
    FROM gas_stations
    WHERE manager_id = ?
    LIMIT 1
");

$stmt->execute([$managerId]);

$station = $stmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| GET STATION REQUESTS
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT *
    FROM station_requests
    WHERE manager_id = ?
    ORDER BY created_at DESC
");

$stmt->execute([$managerId]);

$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| REQUEST STATUS COUNTS
|--------------------------------------------------------------------------
*/
$totalRequests    = count($requests);
$pendingRequests  = 0;
$approvedRequests = 0;
$rejectedRequests = 0;
$needsChanges     = 0;

$hasPendingRequest = false;

foreach ($requests as $request) {

    $requestStatus = strtolower(
        trim((string)($request['status'] ?? ''))
    );

    if ($requestStatus === 'pending') {
        $pendingRequests++;
        $hasPendingRequest = true;
    }

    elseif ($requestStatus === 'approved') {
        $approvedRequests++;
    }

    elseif ($requestStatus === 'rejected') {
        $rejectedRequests++;
    }

    elseif ($requestStatus === 'needs changes') {
        $needsChanges++;
        $hasPendingRequest = true;
    }
}

/*
|--------------------------------------------------------------------------
| GET LPG PRODUCTS
|--------------------------------------------------------------------------
*/
$lpgProducts = [];

if ($station) {

    $stmt = $pdo->prepare("
        SELECT
            id,
            station_id,
            kg,
            price,
            available
        FROM station_lpg_products
        WHERE station_id = ?
        ORDER BY kg ASC
    ");

    $stmt->execute([
        (int)$station['id']
    ]);

    $lpgProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| AVAILABILITY COUNTS
|--------------------------------------------------------------------------
*/
$availableFuelCount = 0;
$totalFuelCount = 3;

if ($station) {

    if ((int)($station['premium_available'] ?? 0) === 1) {
        $availableFuelCount++;
    }

    if ((int)($station['regular_available'] ?? 0) === 1) {
        $availableFuelCount++;
    }

    if ((int)($station['diesel_available'] ?? 0) === 1) {
        $availableFuelCount++;
    }
}

$availableLpg = 0;

foreach ($lpgProducts as $lpg) {

    if ((int)($lpg['available'] ?? 0) === 1) {
        $availableLpg++;
    }
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

    <title>Manager Dashboard - GasGo</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            background: #f6f7f9;
            color: #181616;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        /*
        |--------------------------------------------------------------------------
        | APP LAYOUT
        |--------------------------------------------------------------------------
        */

        .app-shell {
            min-height: 100vh;
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
            width: 260px;
            height: 100vh;

            background: #ffffff;

            border-right: 1px solid #e5e7eb;

            display: flex;
            flex-direction: column;

            z-index: 1000;

            transition:
                transform .25s ease,
                box-shadow .25s ease;
        }

        .sidebar-brand {
            height: 82px;

            display: flex;
            align-items: center;

            padding: 0 24px;

            border-bottom: 1px solid #eeeeee;
        }

        .brand-icon {
            width: 42px;
            height: 42px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 11px;

            background: #dc2626;
            color: #ffffff;

            font-size: 21px;
            font-weight: 800;

            margin-right: 11px;
        }

        .brand-text {
            line-height: 1.1;
        }

        .brand-text strong {
            display: block;
            font-size: 19px;
            color: #171717;
        }

        .brand-text span {
            display: block;
            margin-top: 4px;

            font-size: 11px;
            color: #777777;
            font-weight: 600;
        }

        .sidebar-nav {
            flex: 1;

            padding: 22px 14px;
        }

        .nav-label {
            padding: 0 12px;
            margin-bottom: 9px;

            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;

            letter-spacing: .08em;

            color: #999999;
        }

        .nav-item {
            width: 100%;

            border: 0;
            background: transparent;

            display: flex;
            align-items: center;

            padding: 13px 13px;

            margin-bottom: 5px;

            border-radius: 10px;

            color: #555555;

            font-size: 14px;
            font-weight: 700;

            cursor: pointer;

            text-align: left;

            transition: .2s;
        }

        .nav-item:hover {
            background: #f5f5f5;
            color: #222222;
        }

        .nav-item.active {
            background: #fef2f2;
            color: #dc2626;
        }

        .nav-icon {
            width: 27px;
            margin-right: 9px;

            font-size: 17px;
            text-align: center;
        }

        .sidebar-bottom {
            padding: 14px;

            border-top: 1px solid #eeeeee;
        }

        .manager-mini-profile {
            padding: 12px;

            margin-bottom: 9px;

            background: #f8f9fb;

            border-radius: 10px;
        }

        .manager-mini-profile strong {
            display: block;

            font-size: 13px;

            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .manager-mini-profile span {
            display: block;

            margin-top: 4px;

            font-size: 11px;

            color: #777777;

            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .logout-btn {
            width: 100%;

            display: flex;
            align-items: center;

            padding: 12px 13px;

            border-radius: 10px;

            text-decoration: none;

            color: #dc2626;

            font-size: 14px;
            font-weight: 700;

            transition: .2s;
        }

        .logout-btn:hover {
            background: #fef2f2;
        }

        /*
        |--------------------------------------------------------------------------
        | MAIN AREA
        |--------------------------------------------------------------------------
        */

        .main-area {
            margin-left: 260px;

            min-height: 100vh;
        }

        .desktop-header {
            height: 82px;

            background: #ffffff;

            border-bottom: 1px solid #e5e7eb;

            display: flex;
            align-items: center;
            justify-content: space-between;

            padding: 0 32px;
        }

        .page-heading h1 {
            margin: 0;

            font-size: 24px;
            line-height: 1.2;
        }

        .page-heading p {
            margin: 5px 0 0;

            color: #777777;

            font-size: 13px;
        }

        .header-manager {
            display: flex;
            align-items: center;
            gap: 10px;

            font-size: 13px;
            font-weight: 700;
        }

        .header-avatar {
            width: 38px;
            height: 38px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 50%;

            background: #fef2f2;
            color: #dc2626;

            font-weight: 800;
        }

        .mobile-header {
            display: none;
        }

        .content {
            padding: 30px;
            max-width: 1450px;
            margin: 0 auto;
        }

        /*
        |--------------------------------------------------------------------------
        | PAGE SECTIONS
        |--------------------------------------------------------------------------
        */

        .page-section {
            display: none;
        }

        .page-section.active {
            display: block;
        }

        /*
        |--------------------------------------------------------------------------
        | WELCOME
        |--------------------------------------------------------------------------
        */

        .welcome-card {
            background: #ffffff;

            border-radius: 14px;

            padding: 25px;

            margin-bottom: 20px;

            border: 1px solid #eeeeee;

            box-shadow:
                0 4px 18px rgba(0, 0, 0, .05);

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 20px;
        }

        .welcome-card h2 {
            margin: 0;

            font-size: 21px;
        }

        .welcome-card p {
            margin: 7px 0 0;

            color: #6b7280;

            font-size: 14px;
        }

        .welcome-badge {
            padding: 9px 13px;

            border-radius: 999px;

            background: #fef2f2;
            color: #dc2626;

            font-size: 12px;
            font-weight: 800;

            white-space: nowrap;
        }

        /*
        |--------------------------------------------------------------------------
        | STAT CARDS
        |--------------------------------------------------------------------------
        */

        .stats-grid {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 16px;

            margin-bottom: 20px;
        }

        .stat-card {
            background: #ffffff;

            border-radius: 14px;

            padding: 20px;

            border: 1px solid #eeeeee;

            box-shadow:
                0 4px 18px rgba(0, 0, 0, .05);

            display: flex;

            align-items: center;

            gap: 14px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;

            flex: 0 0 48px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 12px;

            background: #f3f4f6;

            font-size: 21px;
        }

        .stat-info span {
            display: block;

            font-size: 12px;

            color: #777777;

            margin-bottom: 4px;
        }

        .stat-info strong {
            display: block;

            font-size: 23px;

            line-height: 1;
        }

        /*
        |--------------------------------------------------------------------------
        | CARDS
        |--------------------------------------------------------------------------
        */

        .section-card {
            background: #ffffff;

            border-radius: 14px;

            padding: 24px;

            margin-bottom: 20px;

            border: 1px solid #eeeeee;

            box-shadow:
                0 4px 18px rgba(0, 0, 0, .05);
        }

        .section-heading {
            display: flex;

            align-items: center;
            justify-content: space-between;

            gap: 15px;

            margin-bottom: 20px;
        }

        .section-heading h2 {
            margin: 0;

            font-size: 19px;
        }

        .section-heading p {
            margin: 5px 0 0;

            color: #777777;

            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | STATION MANAGEMENT
        |--------------------------------------------------------------------------
        */

        .notice {
            padding: 15px 17px;

            border-radius: 10px;

            margin-bottom: 18px;

            background: #fff7ed;

            border: 1px solid #fed7aa;

            color: #9a3412;

            font-size: 13px;

            line-height: 1.5;
        }

        .success-notice {
            background: #f0fdf4;

            border-color: #bbf7d0;

            color: #166534;
        }

        .info-notice {
            background: #eff6ff;

            border-color: #bfdbfe;

            color: #1d4ed8;
        }

        .button-grid {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 12px;
        }

        .dashboard-button {
            display: flex;

            align-items: center;
            justify-content: center;

            min-height: 48px;

            padding: 12px 16px;

            border-radius: 10px;

            text-decoration: none;

            text-align: center;

            font-size: 13px;

            font-weight: 800;

            background: #f3f4f6;

            color: #222222;

            border: 1px solid #dddddd;

            transition: .2s;

            cursor: pointer;
        }

        .dashboard-button:hover {
            transform: translateY(-1px);

            box-shadow:
                0 5px 14px rgba(0, 0, 0, .08);
        }

        .dashboard-button.primary {
            background: #dc2626;

            color: #ffffff;

            border-color: #dc2626;
        }

        .dashboard-button.green {
            background: #16a34a;

            color: #ffffff;

            border-color: #16a34a;
        }

        .dashboard-button.blue {
            background: #2563eb;

            color: #ffffff;

            border-color: #2563eb;
        }

        /*
        |--------------------------------------------------------------------------
        | STATION INFORMATION
        |--------------------------------------------------------------------------
        */

        .station-title {
            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            margin-bottom: 20px;
        }

        .station-title h2 {
            margin: 0;

            font-size: 19px;
        }

        .station-status {
            display: inline-flex;

            align-items: center;

            padding: 6px 12px;

            border-radius: 999px;

            background: #dcfce7;

            color: #166534;

            font-size: 12px;

            font-weight: 800;
        }

        .station-info {
            display: grid;

            grid-template-columns:
                repeat(2, minmax(0, 1fr));

            gap: 13px;
        }

        .info-box {
            background: #f8f9fb;

            border-radius: 10px;

            padding: 15px;

            border: 1px solid #f0f0f0;

            min-width: 0;
        }

        .info-box strong {
            display: block;

            margin-bottom: 6px;

            color: #666666;

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: .04em;
        }

        .info-box span {
            font-size: 14px;

            word-break: break-word;
        }

        .info-box.full {
            grid-column: 1 / -1;
        }

        /*
        |--------------------------------------------------------------------------
        | AVAILABILITY
        |--------------------------------------------------------------------------
        */

        .availability-grid {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 13px;
        }

        .fuel-card {
            border: 1px solid #e5e7eb;

            border-radius: 12px;

            padding: 18px;

            background: #ffffff;
        }

        .fuel-card h3 {
            margin: 0 0 10px;

            font-size: 15px;
        }

        .fuel-card-price {
            margin-top: 9px;

            font-size: 18px;

            font-weight: 800;
        }

        .available {
            color: #15803d;

            font-size: 12px;

            font-weight: 800;
        }

        .unavailable {
            color: #dc2626;

            font-size: 12px;

            font-weight: 800;
        }

        .lpg-list {
            margin-top: 20px;

            border-top: 1px solid #eeeeee;
        }

        .lpg-list h3 {
            margin: 18px 0 0;

            font-size: 15px;
        }

        .lpg-row {
            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            padding: 15px 5px;

            border-bottom: 1px solid #eeeeee;
        }

        .lpg-details strong {
            display: block;

            margin-bottom: 4px;

            font-size: 14px;
        }

        .lpg-details span {
            font-size: 12px;

            color: #777777;
        }

        /*
        |--------------------------------------------------------------------------
        | SEARCH
        |--------------------------------------------------------------------------
        */

        .table-toolbar {
            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;

            margin-bottom: 15px;
        }

        .search-box {
            position: relative;

            width: 320px;

            max-width: 100%;
        }

        .search-box input {
            width: 100%;

            height: 43px;

            border: 1px solid #d9dce1;

            border-radius: 9px;

            padding:
                0 13px 0 39px;

            outline: none;

            font-size: 13px;

            background: #ffffff;

            transition: .2s;
        }

        .search-box input:focus {
            border-color: #dc2626;

            box-shadow:
                0 0 0 3px rgba(220, 38, 38, .08);
        }

        .search-icon {
            position: absolute;

            left: 13px;

            top: 50%;

            transform: translateY(-50%);

            color: #888888;

            font-size: 14px;
        }

        /*
        |--------------------------------------------------------------------------
        | TABLE
        |--------------------------------------------------------------------------
        */

        .table-wrap {
            width: 100%;

            overflow-x: auto;
        }

        .table {
            width: 100%;

            border-collapse: collapse;

            min-width: 650px;
        }

        .table th,
        .table td {
            padding: 13px 12px;

            border-bottom: 1px solid #eeeeee;

            text-align: left;

            vertical-align: middle;

            font-size: 13px;
        }

        .table th {
            background: #f8f9fb;

            color: #555555;

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: .03em;
        }

        .table tbody tr:hover {
            background: #fafafa;
        }

        .status {
            display: inline-block;

            padding: 5px 9px;

            border-radius: 999px;

            font-size: 11px;

            font-weight: 800;
        }

        .status-pending {
            background: #fff7ed;

            color: #c2410c;
        }

        .status-approved {
            background: #f0fdf4;

            color: #15803d;
        }

        .status-rejected {
            background: #fef2f2;

            color: #dc2626;
        }

        .status-needs {
            background: #eff6ff;

            color: #2563eb;
        }

        .table-action {
            display: inline-flex;

            align-items: center;

            justify-content: center;

            padding: 7px 11px;

            border-radius: 7px;

            background: #f3f4f6;

            color: #333333;

            text-decoration: none;

            border: 1px solid #dddddd;

            font-size: 12px;

            font-weight: 700;

            transition: .2s;
        }

        .table-action:hover {
            background: #e5e7eb;
        }

        .empty-state {
            text-align: center;

            padding: 35px 15px;

            color: #777777;

            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | MOBILE OVERLAY
        |--------------------------------------------------------------------------
        */

        .sidebar-overlay {
            display: none;

            position: fixed;

            inset: 0;

            background: rgba(0, 0, 0, .35);

            z-index: 900;
        }

        /*
        |--------------------------------------------------------------------------
        | RESPONSIVE
        |--------------------------------------------------------------------------
        */

        @media (max-width: 1100px) {

            .stats-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .button-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .availability-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

        }

        @media (max-width: 800px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);

                box-shadow:
                    8px 0 30px rgba(0, 0, 0, .12);
            }

            .sidebar-overlay.open {
                display: block;
            }

            .main-area {
                margin-left: 0;
            }

            .desktop-header {
                display: none;
            }

            .mobile-header {
                height: 64px;

                background: #ffffff;

                border-bottom: 1px solid #e5e7eb;

                display: flex;

                align-items: center;

                justify-content: space-between;

                padding: 0 15px;

                position: sticky;

                top: 0;

                z-index: 500;
            }

            .mobile-menu-btn {
                width: 40px;
                height: 40px;

                border: 1px solid #dddddd;

                background: #ffffff;

                border-radius: 9px;

                cursor: pointer;

                font-size: 20px;
            }

            .mobile-title {
                font-size: 16px;

                font-weight: 800;
            }

            .mobile-user {
                width: 36px;
                height: 36px;

                display: flex;

                align-items: center;
                justify-content: center;

                border-radius: 50%;

                background: #fef2f2;

                color: #dc2626;

                font-size: 12px;

                font-weight: 800;
            }

            .content {
                padding: 20px 15px 35px;
            }
}

        @media (max-width: 600px) {

            .welcome-card {
                flex-direction: column;

                align-items: flex-start;

                padding: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .button-grid {
                grid-template-columns: 1fr;
            }

            .availability-grid {
                grid-template-columns: 1fr;
            }

            .station-info {
                grid-template-columns: 1fr;
            }

            .info-box.full {
                grid-column: auto;
            }

            .station-title {
                align-items: flex-start;

                flex-direction: column;
            }

            .section-card {
                padding: 18px;
            }

            .section-heading {
                align-items: flex-start;

                flex-direction: column;
            }

            .table-toolbar {
                align-items: stretch;

                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .lpg-row {
                align-items: flex-start;

                flex-direction: column;
            }

        }

    </style>

</head>

<body>

<div class="app-shell">


    <!-- ==============================================================
         SIDEBAR
    ============================================================== -->

    <aside
        class="sidebar"
        id="sidebar"
    >

        <div class="sidebar-brand">

            <div class="brand-icon">
                ⛽
            </div>

            <div class="brand-text">

                <strong>
                    GasGo
                </strong>

                <span>
                    Manager Panel
                </span>

            </div>

        </div>


        <nav class="sidebar-nav">

            <div class="nav-label">
                Navigation
            </div>


            <button
                type="button"
                class="nav-item active"
                data-section="dashboard-section"
            >

                <span class="nav-icon">
                    ▦
                </span>

                Dashboard

            </button>


            <button
                type="button"
                class="nav-item"
                data-section="station-section"
            >

                <span class="nav-icon">
                    ⛽
                </span>

                My Station

            </button>


            <button
                type="button"
                class="nav-item"
                data-section="requests-section"
            >

                <span class="nav-icon">
                    ◷
                </span>

                Station Requests

            </button>

        </nav>


        <div class="sidebar-bottom">


            <div class="manager-mini-profile">

                <strong>
                    <?= e($manager['full_name']) ?>
                </strong>

                <span>
                    <?= e($manager['email']) ?>
                </span>

            </div>


            <a
                href="logout.php"
                class="logout-btn"
            >

                <span class="nav-icon">
                    ↪
                </span>

                Logout

            </a>

        </div>

    </aside>


    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>


    <!-- ==============================================================
         MAIN AREA
    ============================================================== -->

    <div class="main-area">


        <!-- DESKTOP HEADER -->

        <header class="desktop-header">

            <div class="page-heading">

                <h1>
                    Manager Dashboard
                </h1>

                <p>
                    Manage your gasoline station and availability.
                </p>

            </div>


            <div class="header-manager">

                <div class="header-avatar">
                    <?= e(
                        strtoupper(
                            substr(
                                (string)$manager['full_name'],
                                0,
                                1
                            )
                        )
                    ) ?>
                </div>

                <span>
                    <?= e($manager['full_name']) ?>
                </span>

            </div>

        </header>


        <!-- MOBILE HEADER -->

        <header class="mobile-header">

            <button
                type="button"
                class="mobile-menu-btn"
                id="sidebarToggle"
                aria-label="Open menu"
            >
                ☰
            </button>


            <div class="mobile-title">
                GasGo Manager
            </div>


            <div class="mobile-user">

                <?= e(
                    strtoupper(
                        substr(
                            (string)$manager['full_name'],
                            0,
                            1
                        )
                    )
                ) ?>

            </div>

        </header>


        <main class="content">


            <!-- ======================================================
                 DASHBOARD SECTION
            ======================================================= -->

            <section
                class="page-section active"
                id="dashboard-section"
            >


                <!-- WELCOME -->

                <div class="welcome-card">

                    <div>

                        <h2>
                            Welcome back,
                            <?= e($manager['full_name']) ?>!
                        </h2>

                        <p>
                            Monitor your station, fuel availability,
                            and station requests from one place.
                        </p>

                    </div>


                    <?php if ($station): ?>

                        <div class="welcome-badge">
                            Station Active
                        </div>

                    <?php elseif ($hasPendingRequest): ?>

                        <div class="welcome-badge">
                            Request Processing
                        </div>

                    <?php else: ?>

                        <div class="welcome-badge">
                            No Station Yet
                        </div>

                    <?php endif; ?>

                </div>


                <!-- STATS -->

                <div class="stats-grid">


                    <div class="stat-card">

                        <div class="stat-icon">
                            ⛽
                        </div>

                        <div class="stat-info">

                            <span>
                                My Station
                            </span>

                            <strong>
                                <?= $station ? '1' : '0' ?>
                            </strong>

                        </div>

                    </div>


                    <div class="stat-card">

                        <div class="stat-icon">
                            ◷
                        </div>

                        <div class="stat-info">

                            <span>
                                Total Requests
                            </span>

                            <strong>
                                <?= $totalRequests ?>
                            </strong>

                        </div>

                    </div>


                    <div class="stat-card">

                        <div class="stat-icon">
                            !
                        </div>

                        <div class="stat-info">

                            <span>
                                Pending
                            </span>

                            <strong>
                                <?= $pendingRequests ?>
                            </strong>

                        </div>

                    </div>


                    <div class="stat-card">

                        <div class="stat-icon">
                            ✓
                        </div>

                        <div class="stat-info">

                            <span>
                                Approved
                            </span>

                            <strong>
                                <?= $approvedRequests ?>
                            </strong>

                        </div>

                    </div>

                </div>


                <!-- STATION MANAGEMENT -->

                <div class="section-card">

                    <div class="section-heading">

                        <div>

                            <h2>
                                Station Management
                            </h2>

                            <p>
                                Manage your station information,
                                prices, location, and availability.
                            </p>

                        </div>

                    </div>


                    <?php if ($station): ?>


                        <div class="notice success-notice">

                            <strong>
                                Your station is approved.
                            </strong>

                            You can now manage its information,
                            availability, prices, LPG products,
                            and location.

                        </div>


                        <div class="button-grid">


                            <a
                                href="station.php"
                                class="dashboard-button primary"
                            >
                                ⛽ Manage Station
                            </a>


                            <a
                                href="availability.php"
                                class="dashboard-button green"
                            >
                                ✓ Manage Availability
                            </a>


                            <a
                                href="prices.php"
                                class="dashboard-button blue"
                            >
                                ₱ Manage Prices
                            </a>


                            <a
                                href="lpg.php"
                                class="dashboard-button"
                            >
                                LPG Products
                            </a>


                            <a
                                href="location.php"
                                class="dashboard-button"
                            >
                                📍 Station Location
                            </a>

                        </div>


                    <?php elseif ($hasPendingRequest): ?>


                        <div class="notice">

                            <strong>
                                Your station request is being processed.
                            </strong>

                            You already have a pending or
                            "Needs changes" request. Please wait
                            for the administrator to review it.

                        </div>


                    <?php else: ?>


                        <div class="notice">

                            You do not have an approved station yet.
                            Submit a station request to get started.

                        </div>


                        <a
                            href="request.php"
                            class="dashboard-button primary"
                            style="max-width:260px;"
                        >
                            + Submit Station
                        </a>


                    <?php endif; ?>

                </div>


                <!-- DASHBOARD AVAILABILITY SUMMARY -->

                <?php if ($station): ?>

                    <div class="section-card">

                        <div class="section-heading">

                            <div>

                                <h2>
                                    Current Availability
                                </h2>

                                <p>
                                    Quick overview of your current
                                    fuel and LPG availability.
                                </p>

                            </div>


                            <a
                                href="availability.php"
                                class="dashboard-button green"
                                style="
                                    min-height:40px;
                                    padding:8px 14px;
                                "
                            >
                                Manage
                            </a>

                        </div>


                        <div class="availability-grid">


                            <!-- PREMIUM -->

                            <div class="fuel-card">

                                <h3>
                                    Premium
                                </h3>

                                <?php if (
                                    (int)($station['premium_available'] ?? 0) === 1
                                ): ?>

                                    <span class="available">
                                        ● Available
                                    </span>

                                <?php else: ?>

                                    <span class="unavailable">
                                        ● Unavailable
                                    </span>

                                <?php endif; ?>


                                <div class="fuel-card-price">

                                    ₱<?= number_format(
                                        (float)($station['premium_price'] ?? 0),
                                        2
                                    ) ?>

                                </div>

                            </div>


                            <!-- REGULAR -->

                            <div class="fuel-card">

                                <h3>
                                    Regular
                                </h3>

                                <?php if (
                                    (int)($station['regular_available'] ?? 0) === 1
                                ): ?>

                                    <span class="available">
                                        ● Available
                                    </span>

                                <?php else: ?>

                                    <span class="unavailable">
                                        ● Unavailable
                                    </span>

                                <?php endif; ?>


                                <div class="fuel-card-price">

                                    ₱<?= number_format(
                                        (float)($station['unleaded_price'] ?? 0),
                                        2
                                    ) ?>

                                </div>

                            </div>


                            <!-- DIESEL -->

                            <div class="fuel-card">

                                <h3>
                                    Diesel
                                </h3>

                                <?php if (
                                    (int)($station['diesel_available'] ?? 0) === 1
                                ): ?>

                                    <span class="available">
                                        ● Available
                                    </span>

                                <?php else: ?>

                                    <span class="unavailable">
                                        ● Unavailable
                                    </span>

                                <?php endif; ?>


                                <div class="fuel-card-price">

                                    ₱<?= number_format(
                                        (float)($station['diesel_price'] ?? 0),
                                        2
                                    ) ?>

                                </div>

                            </div>


                            <!-- LPG -->

                            <div class="fuel-card">

                                <h3>
                                    LPG
                                </h3>


                                <?php if ($lpgProducts): ?>


                                    <?php if ($availableLpg > 0): ?>

                                        <span class="available">
                                            ● <?= $availableLpg ?>
                                            available size(s)
                                        </span>

                                    <?php else: ?>

                                        <span class="unavailable">
                                            ● All unavailable
                                        </span>

                                    <?php endif; ?>


                                <?php else: ?>

                                    <span class="unavailable">
                                        ● No LPG products
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>


                        <!-- LPG LIST -->

                        <?php if ($lpgProducts): ?>

                            <div class="lpg-list">

                                <h3>
                                    LPG Product Availability
                                </h3>


                                <?php foreach ($lpgProducts as $lpg): ?>

                                    <div class="lpg-row">


                                        <div class="lpg-details">

                                            <strong>

                                                <?= e(
                                                    number_format(
                                                        (float)$lpg['kg'],
                                                        2
                                                    )
                                                ) ?>

                                                kg LPG

                                            </strong>


                                            <span>

                                                ₱<?= number_format(
                                                    (float)$lpg['price'],
                                                    2
                                                ) ?>

                                            </span>

                                        </div>


                                        <div>

                                            <?php if (
                                                (int)$lpg['available'] === 1
                                            ): ?>

                                                <span class="available">
                                                    ● Available
                                                </span>

                                            <?php else: ?>

                                                <span class="unavailable">
                                                    ● Unavailable
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>


            </section>


            <!-- ======================================================
                 MY STATION SECTION
            ======================================================= -->

            <section
                class="page-section"
                id="station-section"
            >


                <div class="section-card">


                    <div class="section-heading">

                        <div>

                            <h2>
                                My Gasoline Station
                            </h2>

                            <p>
                                View your registered gasoline station
                                information.
                            </p>

                        </div>


                        <?php if ($station): ?>

                            <span class="station-status">
                                Approved
                            </span>

                        <?php endif; ?>

                    </div>


                    <?php if (!$station): ?>


                        <div class="empty-state">

                            <p>
                                You currently do not have an
                                approved gasoline station.
                            </p>


                            <?php if (!$hasPendingRequest): ?>

                                <a
                                    href="request.php"
                                    class="dashboard-button primary"
                                    style="
                                        max-width:240px;
                                        margin:15px auto 0;
                                    "
                                >
                                    + Submit Station
                                </a>

                            <?php else: ?>

                                <div class="notice">
                                    Your station request is
                                    currently being processed.
                                </div>

                            <?php endif; ?>

                        </div>


                    <?php else: ?>


                        <div class="station-info">


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
                                    Contact Number
                                </strong>

                                <span>
                                    <?= e(
                                        $station['contact_number']
                                    ) ?: 'Not provided' ?>
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


                            <div class="info-box full">

                                <strong>
                                    Description
                                </strong>

                                <span>
                                    <?= e(
                                        $station['description']
                                    ) ?: 'No description provided.' ?>
                                </span>

                            </div>


                            <div class="info-box">

                                <strong>
                                    Working Days
                                </strong>

                                <span>
                                    <?= e(
                                        $station['working_days']
                                        ?? ''
                                    ) ?: 'Not specified' ?>
                                </span>

                            </div>


                            <div class="info-box">

                                <strong>
                                    Operating Hours
                                </strong>

                                <span>
                                    <?= e(
                                        $station['operating_hours']
                                    ) ?: 'Not specified' ?>
                                </span>

                            </div>


                            <div class="info-box">

                                <strong>
                                    Location
                                </strong>

                                <span>

                                    <?= number_format(
                                        (float)$station['latitude'],
                                        6
                                    ) ?>,

                                    <?= number_format(
                                        (float)$station['longitude'],
                                        6
                                    ) ?>

                                </span>

                            </div>


                            <div class="info-box">

                                <strong>
                                    Station Status
                                </strong>

                                <span>
                                    <?= e(
                                        $station['status']
                                        ?? 'Approved'
                                    ) ?>
                                </span>

                            </div>


                        </div>


                        <div
                            style="
                                display:flex;
                                flex-wrap:wrap;
                                gap:10px;
                                margin-top:20px;
                            "
                        >

                            <a
                                href="station.php"
                                class="dashboard-button primary"
                                style="max-width:220px;"
                            >
                                ⛽ Manage Station
                            </a>


                            <a
                                href="location.php"
                                class="dashboard-button"
                                style="max-width:220px;"
                            >
                                📍 Manage Location
                            </a>

                        </div>


                    <?php endif; ?>

                </div>


                <?php if ($station): ?>


                    <div class="section-card">

                        <div class="section-heading">

                            <div>

                                <h2>
                                    Product Availability
                                </h2>

                                <p>
                                    Current fuel and LPG availability.
                                </p>

                            </div>


                            <a
                                href="availability.php"
                                class="dashboard-button green"
                                style="
                                    min-height:40px;
                                    padding:8px 14px;
                                "
                            >
                                Manage
                            </a>

                        </div>


                        <div class="availability-grid">


                            <div class="fuel-card">

                                <h3>
                                    Premium
                                </h3>

                                <?php if (
                                    (int)($station['premium_available'] ?? 0) === 1
                                ): ?>

                                    <span class="available">
                                        ● Available
                                    </span>

                                <?php else: ?>

                                    <span class="unavailable">
                                        ● Unavailable
                                    </span>

                                <?php endif; ?>


                                <div class="fuel-card-price">

                                    ₱<?= number_format(
                                        (float)($station['premium_price'] ?? 0),
                                        2
                                    ) ?>

                                </div>

                            </div>


                            <div class="fuel-card">

                                <h3>
                                    Regular
                                </h3>

                                <?php if (
                                    (int)($station['regular_available'] ?? 0) === 1
                                ): ?>

                                    <span class="available">
                                        ● Available
                                    </span>

                                <?php else: ?>

                                    <span class="unavailable">
                                        ● Unavailable
                                    </span>

                                <?php endif; ?>


                                <div class="fuel-card-price">

                                    ₱<?= number_format(
                                        (float)($station['unleaded_price'] ?? 0),
                                        2
                                    ) ?>

                                </div>

                            </div>


                            <div class="fuel-card">

                                <h3>
                                    Diesel
                                </h3>

                                <?php if (
                                    (int)($station['diesel_available'] ?? 0) === 1
                                ): ?>

                                    <span class="available">
                                        ● Available
                                    </span>

                                <?php else: ?>

                                    <span class="unavailable">
                                        ● Unavailable
                                    </span>

                                <?php endif; ?>


                                <div class="fuel-card-price">

                                    ₱<?= number_format(
                                        (float)($station['diesel_price'] ?? 0),
                                        2
                                    ) ?>

                                </div>

                            </div>


                            <div class="fuel-card">

                                <h3>
                                    LPG
                                </h3>

                                <?php if ($lpgProducts): ?>

                                    <?php if ($availableLpg > 0): ?>

                                        <span class="available">
                                            ● <?= $availableLpg ?>
                                            available size(s)
                                        </span>

                                    <?php else: ?>

                                        <span class="unavailable">
                                            ● All unavailable
                                        </span>

                                    <?php endif; ?>

                                <?php else: ?>

                                    <span class="unavailable">
                                        ● No LPG products
                                    </span>

                                <?php endif; ?>

                            </div>


                        </div>


                        <?php if ($lpgProducts): ?>

                            <div class="lpg-list">

                                <h3>
                                    LPG Product Availability
                                </h3>


                                <?php foreach ($lpgProducts as $lpg): ?>

                                    <div class="lpg-row">


                                        <div class="lpg-details">

                                            <strong>

                                                <?= e(
                                                    number_format(
                                                        (float)$lpg['kg'],
                                                        2
                                                    )
                                                ) ?>

                                                kg LPG

                                            </strong>

                                            <span>

                                                ₱<?= number_format(
                                                    (float)$lpg['price'],
                                                    2
                                                ) ?>

                                            </span>

                                        </div>


                                        <div>

                                            <?php if (
                                                (int)$lpg['available'] === 1
                                            ): ?>

                                                <span class="available">
                                                    ● Available
                                                </span>

                                            <?php else: ?>

                                                <span class="unavailable">
                                                    ● Unavailable
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>


                    </div>


                <?php endif; ?>


            </section>


            <!-- ======================================================
                 STATION REQUESTS SECTION
            ======================================================= -->

            <section
                class="page-section"
                id="requests-section"
            >


                <div class="section-card">


                    <div class="section-heading">

                        <div>

                            <h2>
                                My Station Requests
                            </h2>

                            <p>
                                Track the status and feedback of
                                your submitted station requests.
                            </p>

                        </div>

                    </div>


                    <div class="table-toolbar">


                        <div class="search-box">

                            <span class="search-icon">
                                🔍
                            </span>

                            <input
                                type="text"
                                id="requestSearch"
                                placeholder="Search station requests..."
                                autocomplete="off"
                            >

                        </div>


                        <div
                            style="
                                font-size:12px;
                                color:#777;
                            "
                        >

                            <?= $totalRequests ?>
                            request(s)

                        </div>

                    </div>


                    <div class="table-wrap">

                        <table
                            class="table"
                            id="requestsTable"
                        >

                            <thead>

                                <tr>

                                    <th>
                                        Station
                                    </th>

                                    <th>
                                        Status
                                    </th>

                                    <th>
                                        Submitted
                                    </th>

                                    <th>
                                        Feedback
                                    </th>

                                    <th>
                                        Action
                                    </th>

                                </tr>

                            </thead>


                            <tbody>


                            <?php if (!$requests): ?>


                                <tr>

                                    <td colspan="5">

                                        <div class="empty-state">

                                            No station requests yet.

                                        </div>

                                    </td>

                                </tr>


                            <?php else: ?>


                                <?php foreach ($requests as $request): ?>


                                    <?php

                                    $status = strtolower(
                                        trim(
                                            (string)(
                                                $request['status']
                                                ?? ''
                                            )
                                        )
                                    );

                                    $statusClass =
                                        'status';

                                    if (
                                        $status === 'pending'
                                    ) {

                                        $statusClass =
                                            'status status-pending';

                                    }

                                    elseif (
                                        $status === 'approved'
                                    ) {

                                        $statusClass =
                                            'status status-approved';

                                    }

                                    elseif (
                                        $status === 'rejected'
                                    ) {

                                        $statusClass =
                                            'status status-rejected';

                                    }

                                    elseif (
                                        $status === 'needs changes'
                                    ) {

                                        $statusClass =
                                            'status status-needs';

                                    }

                                    ?>


                                    <tr class="request-row">


                                        <td>

                                            <strong>
                                                <?= e(
                                                    $request['station_name']
                                                ) ?>
                                            </strong>

                                        </td>


                                        <td>

                                            <span
                                                class="<?= $statusClass ?>"
                                            >
                                                <?= e(
                                                    $request['status']
                                                ) ?>
                                            </span>

                                        </td>


                                        <td>

                                            <?= !empty(
                                                $request['created_at']
                                            )
                                                ? e(
                                                    date(
                                                        'M d, Y',
                                                        strtotime(
                                                            (string)$request['created_at']
                                                        )
                                                    )
                                                )
                                                : '—'
                                            ?>

                                        </td>


                                        <td>

                                            <?= e(
                                                $request['admin_feedback']
                                                ?? ''
                                            ) ?: '—' ?>

                                        </td>


                                        <td>


                                            <?php if (
                                                $status === 'pending' ||
                                                $status === 'needs changes'
                                            ): ?>


                                                <a
                                                    href="edit_request.php?id=<?= (int)$request['id'] ?>"
                                                    class="table-action"
                                                >
                                                    Edit
                                                </a>


                                            <?php else: ?>


                                                <span
                                                    style="
                                                        color:#999;
                                                    "
                                                >
                                                    —
                                                </span>


                                            <?php endif; ?>


                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                            <?php endif; ?>


                            <tr
                                id="requestNoMatch"
                                style="display:none;"
                            >

                                <td colspan="5">

                                    <div class="empty-state">

                                        No station requests
                                        match your search.

                                    </div>

                                </td>

                            </tr>


                            </tbody>

                        </table>

                    </div>


                </div>


                <!-- REQUEST SUMMARY -->

                <div class="stats-grid">


                    <div class="stat-card">

                        <div class="stat-icon">
                            ◷
                        </div>

                        <div class="stat-info">

                            <span>
                                Total
                            </span>

                            <strong>
                                <?= $totalRequests ?>
                            </strong>

                        </div>

                    </div>


                    <div class="stat-card">

                        <div class="stat-icon">
                            !
                        </div>

                        <div class="stat-info">

                            <span>
                                Pending
                            </span>

                            <strong>
                                <?= $pendingRequests ?>
                            </strong>

                        </div>

                    </div>


                    <div class="stat-card">

                        <div class="stat-icon">
                            ✓
                        </div>

                        <div class="stat-info">

                            <span>
                                Approved
                            </span>

                            <strong>
                                <?= $approvedRequests ?>
                            </strong>

                        </div>

                    </div>


                    <div class="stat-card">

                        <div class="stat-icon">
                            ×
                        </div>

                        <div class="stat-info">

                            <span>
                                Rejected
                            </span>

                            <strong>
                                <?= $rejectedRequests ?>
                            </strong>

                        </div>

                    </div>

                </div>


                <?php if ($needsChanges > 0): ?>

                    <div class="notice info-notice">

                        <strong>
                            <?= $needsChanges ?>
                            request(s) need changes.
                        </strong>

                        Open the request and update the required
                        information before submitting it again.

                    </div>

                <?php endif; ?>


            </section>


        </main>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| SIDEBAR
|--------------------------------------------------------------------------
*/

const sidebar = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebarOverlay = document.getElementById('sidebarOverlay');


function openSidebar() {

    if (!sidebar) {
        return;
    }

    sidebar.classList.add('open');

    if (sidebarOverlay) {
        sidebarOverlay.classList.add('open');
    }
}


function closeSidebar() {

    if (!sidebar) {
        return;
    }

    sidebar.classList.remove('open');

    if (sidebarOverlay) {
        sidebarOverlay.classList.remove('open');
    }
}


if (sidebarToggle) {

    sidebarToggle.addEventListener(
        'click',
        function () {

            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }

        }
    );

}


if (sidebarOverlay) {

    sidebarOverlay.addEventListener(
        'click',
        closeSidebar
    );

}


/*
|--------------------------------------------------------------------------
| SECTION NAVIGATION
|--------------------------------------------------------------------------
*/

const navItems =
    document.querySelectorAll('.nav-item');

const sections =
    document.querySelectorAll('.page-section');


navItems.forEach(function (navItem) {

    navItem.addEventListener(
        'click',
        function () {

            const targetId =
                navItem.getAttribute('data-section');

            if (!targetId) {
                return;
            }


            /*
            | Remove active state from all buttons
            */

            navItems.forEach(function (item) {

                item.classList.remove('active');

            });


            /*
            | Hide all sections
            */

            sections.forEach(function (section) {

                section.classList.remove('active');

            });


            /*
            | Activate selected section
            */

            navItem.classList.add('active');


            const targetSection =
                document.getElementById(targetId);

            if (targetSection) {

                targetSection.classList.add('active');

            }


            /*
            | Close mobile sidebar
            */

            closeSidebar();


            /*
            | Scroll to top
            */

            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });

        }
    );

});


/*
|--------------------------------------------------------------------------
| STATION REQUEST SEARCH
|--------------------------------------------------------------------------
*/

const requestSearch =
    document.getElementById('requestSearch');

const requestRows =
    document.querySelectorAll('.request-row');

const requestNoMatch =
    document.getElementById('requestNoMatch');


if (requestSearch) {

    requestSearch.addEventListener(
        'input',
        function () {

            const query =
                requestSearch.value
                    .toLowerCase()
                    .trim();

            let visibleRows = 0;


            requestRows.forEach(
                function (row) {

                    const text =
                        row.textContent
                            .toLowerCase();

                    if (
                        query === '' ||
                        text.includes(query)
                    ) {

                        row.style.display = '';

                        visibleRows++;

                    } else {

                        row.style.display = 'none';

                    }

                }
            );


            if (requestNoMatch) {

                if (
                    visibleRows === 0 &&
                    requestRows.length > 0
                ) {

                    requestNoMatch.style.display = '';

                } else {

                    requestNoMatch.style.display = 'none';

                }

            }

        }
    );

}


/*
|--------------------------------------------------------------------------
| CLOSE SIDEBAR WHEN ESC IS PRESSED
|--------------------------------------------------------------------------
*/

document.addEventListener(
    'keydown',
    function (event) {

        if (event.key === 'Escape') {

            closeSidebar();

        }

    }
);


/*
|--------------------------------------------------------------------------
| PREVENT BACKGROUND SCROLL WHEN MOBILE SIDEBAR IS OPEN
|--------------------------------------------------------------------------
*/

function updateBodyOverflow() {

    if (
        window.innerWidth <= 800 &&
        sidebar &&
        sidebar.classList.contains('open')
    ) {

        document.body.style.overflow = 'hidden';

    } else {

        document.body.style.overflow = '';

    }

}


if (sidebarToggle) {

    sidebarToggle.addEventListener(
        'click',
        updateBodyOverflow
    );

}


if (sidebarOverlay) {

    sidebarOverlay.addEventListener(
        'click',
        updateBodyOverflow
    );

}


navItems.forEach(function (item) {

    item.addEventListener(
        'click',
        function () {

            document.body.style.overflow = '';

        }
    );

});


window.addEventListener(
    'resize',
    function () {

        if (window.innerWidth > 800) {

            closeSidebar();

            document.body.style.overflow = '';

        }

    }
);

</script>

</body>

</html>