<?php

declare(strict_types=1);

session_start();

if (empty($_SESSION['manager_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';

$managerId = (int)$_SESSION['manager_id'];


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}


$submittedToken = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($submittedToken) ||
    !hash_equals(
        $_SESSION['csrf_token'],
        $submittedToken
    )
) {

    header(
        'Location: availability.php?error=' .
        urlencode('Invalid security token.')
    );

    exit;
}


$stmt = $pdo->prepare("
    SELECT id
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
        urlencode('You do not have an approved station.')
    );

    exit;
}

$stationId = (int)$station['id'];


$premiumAvailable =
    isset($_POST['premium_available'])
        ? 1
        : 0;

$regularAvailable =
    isset($_POST['regular_available'])
        ? 1
        : 0;

$dieselAvailable =
    isset($_POST['diesel_available'])
        ? 1
        : 0;


$selectedLpgIds = $_POST['lpg_available'] ?? [];

if (!is_array($selectedLpgIds)) {
    $selectedLpgIds = [];
}

$selectedLpgIds = array_map(
    'intval',
    $selectedLpgIds
);

$selectedLpgIds = array_filter(
    $selectedLpgIds,
    fn($id) => $id > 0
);


try {

    $pdo->beginTransaction();


    $stmt = $pdo->prepare("
        UPDATE gas_stations
        SET
            premium_available = ?,
            regular_available = ?,
            diesel_available = ?,
            availability_updated_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
          AND manager_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $premiumAvailable,
        $regularAvailable,
        $dieselAvailable,
        $stationId,
        $managerId
    ]);


    if ($stmt->rowCount() < 0) {
        throw new RuntimeException(
            'Fuel availability could not be updated.'
        );
    }


    $stmt = $pdo->prepare("
        SELECT id
        FROM station_lpg_products
        WHERE station_id = ?
    ");

    $stmt->execute([
        $stationId
    ]);

    $ownedLpgIds = $stmt->fetchAll(
        PDO::FETCH_COLUMN
    );

    $ownedLpgIds = array_map(
        'intval',
        $ownedLpgIds
    );


    if (!empty($ownedLpgIds)) {

        $updateLpg = $pdo->prepare("
            UPDATE station_lpg_products
            SET
                available = ?,
                updated_at = NOW()
            WHERE id = ?
              AND station_id = ?
        ");

        foreach ($ownedLpgIds as $lpgId) {

            $available =
                in_array(
                    $lpgId,
                    $selectedLpgIds,
                    true
                )
                    ? 1
                    : 0;

            $updateLpg->execute([
                $available,
                $lpgId,
                $stationId
            ]);
        }

    }


    $pdo->commit();


    header(
        'Location: dashboard.php?success=' .
        urlencode('Product availability updated successfully.')
    );

    exit;


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        '[GasGo Availability] ' .
        $e->getMessage()
    );

    header(
        'Location: availability.php?error=' .
        urlencode('Unable to update product availability.')
    );

    exit;
}