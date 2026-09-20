<?php

declare(strict_types=1);

use Yiisoft\Db\Pgsql\Dsn;

/**
 * Read a required environment variable.
 *
 * Works both with:
 * - local .env loaded by phpdotenv;
 * - real process/container environment variables.
 */
$env = static function (string $name): string {
    $value = getenv($name, true);

    if ($value === false) {
        $value = getenv($name);
    }

    if ($value === false) {
        $value = $_ENV[$name] ?? null;
    }

    if (!is_string($value) || $value === '') {
        throw new RuntimeException(
            sprintf('Required environment variable "%s" is not defined.', $name),
        );
    }

    return $value;
};

return [
    'application' => require __DIR__ . '/application.php',

    'yiisoft/aliases' => [
        'aliases' => require __DIR__ . '/aliases.php',
    ],

    /*
     * PostgreSQL connection parameters.
     *
     * No database credentials are hardcoded here.
     * Values come from environment variables.
     */
    'yiisoft/db-pgsql' => [
        'dsn' => new Dsn(
            'pgsql',
            $env('DB_HOST'),
            $env('DB_NAME'),
            $env('DB_PORT'),
        ),
        'username' => $env('DB_USER'),
        'password' => $env('DB_PASSWORD'),
    ],
];
