<?php

declare(strict_types=1);

function educationFixtureDatabase(): PDO
{
    foreach (['APP_ENV' => 'test', 'TEST_ISOLATED_RUNTIME' => '1', 'EDUCATION_LOCAL_FIXTURE' => '1',
        'EDUCATION_ENABLED' => '1', 'DB_HOST' => 'postgres-test', 'DB_NAME' => 'platform_test',
        'DB_USER' => 'education_fixture', 'REDIS_HOST' => 'redis-test'] as $key => $expected) {
        if (getenv($key) !== $expected) {
            throw new RuntimeException('Education fixture refuses this environment: ' . $key);
        }
    }
    if (strlen((string) getenv('E2E_ISOLATION_PROOF')) < 32) {
        throw new RuntimeException('Missing isolated runtime proof.');
    }
    $db = new PDO('pgsql:host=postgres-test;port=5432;dbname=platform_test',
        'education_fixture', (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($db->query('SELECT current_database()')->fetchColumn() !== 'platform_test') {
        throw new RuntimeException('Unexpected database.');
    }
    return $db;
}
