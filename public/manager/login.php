<?php
session_start();

require_once __DIR__ . '/../../config/db_connect.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    $admin_username = 'admin123';
    $admin_password = getenv('ADMIN_PASSWORD') ?: 'admin123';

    if ($username === $admin_username && $pass === $admin_password) {

        session_regenerate_id(true);

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $admin_username;

        header('Location: ../admin/dashboard.php');
        exit;
    }


    $email = filter_var($username, FILTER_VALIDATE_EMAIL);

    if ($email) {

        $q = $pdo->prepare("
            SELECT *
            FROM managers
            WHERE email = ?
            LIMIT 1
        ");

        $q->execute([$email]);

        $m = $q->fetch();

        if ($m && password_verify($pass, $m['password'])) {

            session_regenerate_id(true);

            $_SESSION['manager_id'] = $m['id'];

            header('Location: dashboard.php');
            exit;
        }
    }

    $error = 'Invalid username/email or password.';
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

    <title>Login — GasGo</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <style>

        .login-wrapper {
            max-width: 500px;
            margin: 0 auto;
        }

        .back-btn {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 16px;
            background: #f8fbfa;
            color: #334155;
            text-decoration: none;
            border: 1px solid #23554a;
            border-radius: 8px;
            font-weight: 600;
            transition: 0.2s ease;
        }

        .back-btn:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .login-note {
            margin-top: 15px;
            padding: 12px;
            background: #f9fafa;
            border-radius: 8px;
            font-size: 14px;
            color: #64748b;
            line-height: 1.5;
        }

    </style>

</head>

<body>

<main class="section">

    <div class="container login-wrapper">

    
        <a
            href="../../index.php"
            class="back-btn"
        >
            ← Back to Home
        </a>


        <div class="card">

            <h1>GasGo Login</h1>

            <?php if ($error): ?>

                <div class="alert">
                    <?= htmlspecialchars($error) ?>
                </div>

            <?php endif; ?>


            <form
                class="form"
                method="post"
            >

                <input
                    name="username"
                    type="text"
                    required
                    placeholder="Username or Email"
                    autocomplete="username"
                >


                <input
                    name="password"
                    type="password"
                    required
                    placeholder="Password"
                    autocomplete="current-password"
                >


                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Login
                </button>

            </form>


            <div class="login-note">
                Manager accounts are created and managed by the
                GasGo Administrator. If you need a manager account,
                please contact the administrator.
            </div>

        </div>

    </div>

</main>

</body>

</html>