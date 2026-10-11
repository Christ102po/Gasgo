
<?php
declare(strict_types=1);

function gasgoEnv(string ...$names): ?string
{
    foreach ($names as $name) {
        $value = getenv($name);

        if ($value !== false && $value !== '') {
            return $value;
        }
    }

    return null;
}

/**
 * Railway MySQL Configuration
 *
 * Supports custom DB_* variables and
 * Railway MYSQL* variables.
 */
$host = gasgoEnv('DB_HOST', 'MYSQLHOST');
$port = gasgoEnv('DB_PORT', 'MYSQLPORT') ?? '3306';

$dbname = gasgoEnv(
    'DB_DATABASE',
    'DB_NAME',
    'MYSQLDATABASE'
);

$username = gasgoEnv(
    'DB_USERNAME',
    'DB_USER',
    'MYSQLUSER'
);

$password = gasgoEnv(
    'DB_PASSWORD',
    'MYSQLPASSWORD'
);

/**
 * Validate database configuration.
 */
$missing = [];

if ($host === null) {
    $missing[] = 'DB_HOST';
}

if ($dbname === null) {
    $missing[] = 'DB_DATABASE';
}

if ($username === null) {
    $missing[] = 'DB_USERNAME';
}

if ($password === null) {
    $missing[] = 'DB_PASSWORD';
}

if (!empty($missing)) {

    error_log(
        '[GasGo Database] Missing environment variables: ' .
        implode(', ', $missing)
    );

    http_response_code(503);

    exit(
        'Database is not configured. ' .
        'Please check Gasgo environment variables in Railway.'
    );
}

/**
 * Validate database port.
 */
if (
    !ctype_digit($port) ||
    (int)$port < 1 ||
    (int)$port > 65535
) {

    error_log('[GasGo Database] Invalid MySQL port.');

    http_response_code(503);

    exit('Invalid database configuration.');
}

/**
 * Establish PDO database connection.
 */
try {

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        (int)$port,
        $dbname
    );

    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE =>
                PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE =>
                PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES =>
                false,

            PDO::ATTR_TIMEOUT =>
                10,
        ]
    );

} catch (PDOException $e) {

    error_log(
        '[GasGo Database] Connection failed: ' .
        $e->getMessage()
    );

    http_response_code(503);

    exit(
        'Database connection failed. ' .
        'Please check Railway deployment logs.'
    );
}
