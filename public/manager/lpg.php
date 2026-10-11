<?php

session_start();

if (empty($_SESSION['manager_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../../config/db_connect.php';

$managerId = (int) $_SESSION['manager_id'];

$message = '';
$error = '';

$q = $pdo->prepare("
    SELECT *
    FROM gas_stations
    WHERE manager_id = ?
    LIMIT 1
");

$q->execute([$managerId]);

$station = $q->fetch(PDO::FETCH_ASSOC);

if (!$station) {
    die('No approved station found.');
}

$stationId = (int) $station['id'];

function syncStationLpgSummary(PDO $pdo, int $stationId): void
{
    $stmt = $pdo->prepare("
        SELECT price
        FROM station_lpg_products
        WHERE station_id = ?
          AND available = 1
        ORDER BY kg ASC
        LIMIT 1
    ");
    $stmt->execute([$stationId]);
    $firstAvailable = $stmt->fetch(PDO::FETCH_ASSOC);

    $available = $firstAvailable ? 1 : 0;
    $price = $firstAvailable['price'] ?? null;

    $stmt = $pdo->prepare("
        UPDATE gas_stations
        SET lpg_available = ?,
            lpg_price = ?
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$available, $price, $stationId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $kg = (float)($_POST['kg'] ?? 0);

    $price = (float)($_POST['price'] ?? 0);

    $available = isset($_POST['available']) ? 1 : 0;


    if ($kg <= 0 || $price < 0) {

        $error =
            'Please enter a valid LPG size and price.';

    } else {

        try {

            $q = $pdo->prepare("
                INSERT INTO station_lpg_products
                (
                    station_id,
                    kg,
                    price,
                    available
                )
                VALUES (?, ?, ?, ?)

                ON DUPLICATE KEY UPDATE

                    price = VALUES(price),
                    available = VALUES(available)
            ");

            $q->execute([
                $stationId,
                $kg,
                $price,
                $available
            ]);

            syncStationLpgSummary($pdo, $stationId);

            $message =
                'LPG product saved successfully.';

        } catch (PDOException $e) {

            $error =
                'Unable to save LPG product.';

        }

    }

}

if (
    isset($_GET['delete'])
) {

    $lpgId =
        (int)$_GET['delete'];

    $q = $pdo->prepare("
        DELETE FROM station_lpg_products
        WHERE id = ?
          AND station_id = ?
    ");

    $q->execute([
        $lpgId,
        $stationId
    ]);

    syncStationLpgSummary($pdo, $stationId);

    header('Location: lpg.php');
    exit;
}

$q = $pdo->prepare("
    SELECT *
    FROM station_lpg_products
    WHERE station_id = ?
    ORDER BY kg ASC
");

$q->execute([$stationId]);

$lpgProducts = $q->fetchAll(PDO::FETCH_ASSOC);

?>

<!doctype html>

<html lang="en">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>LPG Management - GasGo</title>

<link
    rel="stylesheet"
    href="../assets/css/style.css"
>

<style>

body {
    background:#f5f7fa;
}

.page {
    max-width:900px;
    margin:auto;
    padding:30px 20px;
}

.card {
    background:white;
    padding:25px;
    border-radius:14px;
    margin-bottom:20px;
    box-shadow:0 4px 20px rgba(0,0,0,.07);
}

.back {
    display:inline-block;
    margin-bottom:20px;
    color:#333;
    text-decoration:none;
    font-weight:600;
}

.form-grid {
    display:grid;
    grid-template-columns:
        1fr 1fr;

    gap:15px;
}

input {
    width:100%;
    box-sizing:border-box;
    padding:12px;
    border:1px solid #ddd;
    border-radius:8px;
}

.checkbox {
    display:flex;
    align-items:center;
    gap:8px;
}

.checkbox input {
    width:auto;
}

button {
    border:none;
    padding:12px 18px;
    border-radius:8px;
    background:#dc2626;
    color:white;
    font-weight:700;
    cursor:pointer;
}

.lpg-row {
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:15px 0;
    border-bottom:1px solid #eee;
}

.available {
    color:#15803d;
    font-weight:700;
}

.unavailable {
    color:#dc2626;
    font-weight:700;
}

.delete {
    color:#dc2626;
    text-decoration:none;
    font-weight:600;
}

.message {
    padding:12px;
    background:#dcfce7;
    color:#166534;
    border-radius:8px;
}

.error {
    padding:12px;
    background:#fee2e2;
    color:#991b1b;
    border-radius:8px;
}

@media(max-width:600px) {

    .form-grid {
        grid-template-columns:1fr;
    }

    .lpg-row {
        flex-direction:column;
        align-items:flex-start;
        gap:10px;
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
    LPG Products
</h1>

<p>
    Add the LPG sizes sold by your station
    and their current prices.
</p>

</div>


<?php if ($message): ?>

<div class="message card">

<?= htmlspecialchars($message) ?>

</div>

<?php endif; ?>


<?php if ($error): ?>

<div class="error card">

<?= htmlspecialchars($error) ?>

</div>

<?php endif; ?>


<div class="card">

<h2>
    Add / Update LPG
</h2>

<form method="post">

<div class="form-grid">

<div>

<label>
LPG Size (kg)
</label>

<input
    type="number"
    name="kg"
    step="0.01"
    min="0.01"
    placeholder="Example: 11"
    required
>

</div>


<div>

<label>
Price
</label>

<input
    type="number"
    name="price"
    step="0.01"
    min="0"
    placeholder="Example: 850"
    required
>

</div>

</div>


<p>

<label class="checkbox">

<input
    type="checkbox"
    name="available"
    value="1"
    checked
>

LPG size is currently available

</label>

</p>


<button type="submit">

Save LPG Product

</button>

</form>

</div>


<div class="card">

<h2>
    My LPG Sizes
</h2>


<?php if (!$lpgProducts): ?>

<p>
    No LPG products added yet.
</p>

<?php endif; ?>


<?php foreach ($lpgProducts as $lpg): ?>

<div class="lpg-row">

<div>

<strong>
<?= htmlspecialchars($lpg['kg']) ?> kg
</strong>

<br>

₱<?= number_format(
    (float)$lpg['price'],
    2
) ?>

<br>

<?php if ($lpg['available']): ?>

<span class="available">
Available
</span>

<?php else: ?>

<span class="unavailable">
Unavailable
</span>

<?php endif; ?>

</div>


<a
    href="lpg.php?delete=<?= (int)$lpg['id'] ?>"
    class="delete"
    onclick="return confirm('Delete this LPG product?')"
>
Delete
</a>

</div>

<?php endforeach; ?>

</div>

</div>

</body>

</html>