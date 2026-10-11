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


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $premium = $_POST['premium_price'] !== ''
        ? (float)$_POST['premium_price']
        : null;

    $regular = $_POST['regular_price'] !== ''
        ? (float)$_POST['regular_price']
        : null;

    $diesel = $_POST['diesel_price'] !== ''
        ? (float)$_POST['diesel_price']
        : null;


    if (
        ($premium !== null && $premium < 0) ||
        ($regular !== null && $regular < 0) ||
        ($diesel !== null && $diesel < 0)
    ) {

        $error =
            'Prices cannot be negative.';

    } else {

        try {

            $q = $pdo->prepare("
                UPDATE gas_stations

                SET
                    premium_price = ?,
                    unleaded_price = ?,
                    diesel_price = ?,
                    fuel_prices_updated_at = NOW()

                WHERE id = ?
                  AND manager_id = ?
            ");

            $q->execute([
                $premium,
                $regular,
                $diesel,
                $station['id'],
                $managerId
            ]);

            $message =
                'Fuel prices updated successfully.';


            $station['premium_price'] = $premium;
            $station['unleaded_price'] = $regular;
            $station['diesel_price'] = $diesel;

        } catch (PDOException $e) {

            $error =
                'Unable to update fuel prices.';

        }

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

<title>Fuel Prices - GasGo</title>

<link
    rel="stylesheet"
    href="../assets/css/style.css"
>

<style>

body {
    background:#f5f7fa;
}

.page {
    max-width:700px;
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
    text-decoration:none;
    color:#333;
    font-weight:600;
}

.form-group {
    margin-bottom:20px;
}

label {
    display:block;
    margin-bottom:7px;
    font-weight:700;
}

input {
    width:100%;
    box-sizing:border-box;
    padding:13px;
    border:1px solid #ddd;
    border-radius:8px;
    font-size:16px;
}

button {
    width:100%;
    border:none;
    padding:14px;
    background:#dc2626;
    color:white;
    border-radius:8px;
    font-weight:700;
    font-size:16px;
    cursor:pointer;
}

.message {
    background:#dcfce7;
    color:#166534;
    padding:12px;
    border-radius:8px;
}

.error {
    background:#fee2e2;
    color:#991b1b;
    padding:12px;
    border-radius:8px;
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
    Fuel Prices
</h1>

<p>
    Update the current selling price of your fuel products.
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

<form method="post">


<div class="form-group">

<label>
Premium Price
</label>

<input
    type="number"
    name="premium_price"
    step="0.01"
    min="0"
    value="<?= htmlspecialchars(
        $station['premium_price'] ?? ''
    ) ?>"
    placeholder="₱0.00"
>

</div>


<div class="form-group">

<label>
Regular Price
</label>

<input
    type="number"
    name="regular_price"
    step="0.01"
    min="0"
    value="<?= htmlspecialchars(
        $station['unleaded_price'] ?? ''
    ) ?>"
    placeholder="₱0.00"
>

</div>


<div class="form-group">

<label>
Diesel Price
</label>

<input
    type="number"
    name="diesel_price"
    step="0.01"
    min="0"
    value="<?= htmlspecialchars(
        $station['diesel_price'] ?? ''
    ) ?>"
    placeholder="₱0.00"
>

</div>


<button type="submit">

Save Fuel Prices

</button>

</form>

</div>

</div>

</body>

</html>