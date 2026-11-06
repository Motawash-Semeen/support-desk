<?php

/*
|--------------------------------------------------------------------------
| Test database selection
|--------------------------------------------------------------------------
|
| phpunit.xml defaults to SQLite in-memory so the suite runs on any clone.
| When DB_TEST_CONNECTION is "mysql", or "auto" (default) and the MySQL test
| database is reachable, the suite runs against MySQL instead, so migrations,
| indexes and queries are exercised on the production engine.
|
| DB_TEST_* values come from the real environment first, then from .env.
| They never affect the application's own DB_* settings outside tests.
|
*/

use Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

$fromDotenv = is_file(__DIR__.'/../.env')
    ? Dotenv::createArrayBacked(dirname(__DIR__))->safeLoad()
    : [];

$setting = static function (string $key, string $default) use ($fromDotenv): string {
    $value = getenv($key);

    return $value !== false && $value !== '' ? $value : ($fromDotenv[$key] ?? $default);
};

$mode = strtolower($setting('DB_TEST_CONNECTION', 'auto'));

$mysql = [
    'DB_HOST' => $setting('DB_TEST_HOST', '127.0.0.1'),
    'DB_PORT' => $setting('DB_TEST_PORT', '3306'),
    'DB_DATABASE' => $setting('DB_TEST_DATABASE', 'support_desk_testing'),
    'DB_USERNAME' => $setting('DB_TEST_USERNAME', 'root'),
    'DB_PASSWORD' => $setting('DB_TEST_PASSWORD', ''),
];

$mysqlReachable = static function () use ($mysql): bool {
    try {
        new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $mysql['DB_HOST'], $mysql['DB_PORT'], $mysql['DB_DATABASE']),
            $mysql['DB_USERNAME'],
            $mysql['DB_PASSWORD'],
            [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        return true;
    } catch (PDOException) {
        return false;
    }
};

$useMysql = match ($mode) {
    'mysql' => true,
    'sqlite' => false,
    default => $mysqlReachable(),
};

if ($useMysql) {
    foreach (['DB_CONNECTION' => 'mysql', 'DB_URL' => ''] + $mysql as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}

fwrite(STDERR, sprintf(
    "Test database: %s\n",
    $useMysql ? "MySQL ({$mysql['DB_DATABASE']}@{$mysql['DB_HOST']}:{$mysql['DB_PORT']})" : 'SQLite (:memory:)',
));
