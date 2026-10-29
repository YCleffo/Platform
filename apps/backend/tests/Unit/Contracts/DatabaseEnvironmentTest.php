<?php

declare(strict_types=1);

namespace App\Tests\Unit\Contracts;

use Codeception\Test\Unit;

final class DatabaseEnvironmentTest extends Unit
{
    public function testInfrastructureVariablesCannotSupplyMissingApplicationSettings(): void
    {
        $keys = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_PASSWORD_FILE',
            'POSTGRES_HOST', 'POSTGRES_PORT', 'POSTGRES_DB', 'POSTGRES_USER', 'POSTGRES_PASSWORD'];
        $process = $local = [];
        foreach ($keys as $key) {
            $process[$key] = getenv($key);
            $local[$key] = $_ENV[$key] ?? null;
        }
        try {
            foreach ($keys as $key) {
                putenv($key . '=' . ($key === 'DB_PORT' ? '5432' : 'fixture-value'));
                unset($_ENV[$key]);
            }
            putenv('DB_PASSWORD_FILE');
            foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $required) {
                $configured = getenv($required);
                putenv($required);
                try {
                    require dirname(__DIR__, 3) . '/config/common/params.php';
                    $this->fail('Missing application setting accepted: ' . $required);
                } catch (\RuntimeException $error) {
                    $this->assertSame('Required environment variable is missing: ' . $required, $error->getMessage());
                } finally {
                    putenv($required . '=' . $configured);
                }
            }
        } finally {
            foreach ($keys as $key) {
                putenv($process[$key] === false ? $key : $key . '=' . $process[$key]);
                if ($local[$key] === null) { unset($_ENV[$key]); }
                else { $_ENV[$key] = $local[$key]; }
            }
        }
    }
}
