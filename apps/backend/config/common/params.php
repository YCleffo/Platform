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
$env = static function (string ...$names): string {
    foreach ($names as $name) {
        $value = getenv($name);

        if ($value !== false && $value !== '') {
            return $value;
        }

        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return (string) $_ENV[$name];
        }
    }

    throw new \RuntimeException(
        sprintf(
            'Required environment variable is missing: %s',
            implode(' or ', $names),
        ),
    );
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
            getenv('DB_HOST') ?: '127.0.0.1',
            $env('DB_NAME', 'POSTGRES_DB'),
            $env('DB_PORT', 'POSTGRES_PORT'),
        ),

        'username' => $env('DB_USER', 'POSTGRES_USER'),
        'password' => $env('DB_PASSWORD', 'POSTGRES_PASSWORD'),
    ],
];
