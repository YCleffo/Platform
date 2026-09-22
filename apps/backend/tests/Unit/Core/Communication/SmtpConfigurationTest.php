<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Communication;

use App\Core\Communication\Infrastructure\SmtpConfiguration;
use Codeception\Test\Unit;
use InvalidArgumentException;

use function PHPUnit\Framework\assertSame;

final class SmtpConfigurationTest extends Unit
{
    public function testBuildsEncodedTlsDsn(): void
    {
        $configuration = new SmtpConfiguration(
            host: 'smtp.example.test',
            port: 587,
            username: 'user@example.test',
            password: 'p@ss:/word',
            encryption: 'tls',
            fromAddress: 'no-reply@example.test',
            fromName: 'Platform',
        );

        assertSame(
            'smtp://user%40example.test:p%40ss%3A%2Fword@smtp.example.test:587?require_tls=true',
            $configuration->dsn(),
        );
    }

    public function testRejectsPartialCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SmtpConfiguration(
            host: 'smtp.example.test',
            port: 587,
            username: 'user',
            password: null,
            encryption: 'tls',
            fromAddress: 'no-reply@example.test',
            fromName: 'Platform',
        );
    }
}
