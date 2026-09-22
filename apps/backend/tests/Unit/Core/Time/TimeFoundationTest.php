<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Time;

use App\Core\Internationalization\Domain\Locale;
use App\Core\Time\Application\DateTimeFormatter;
use App\Core\Time\Application\ResolvedTimeZone;
use App\Core\Time\Application\TimeZoneResolver;
use App\Core\Time\Domain\TimeZone;
use Codeception\Test\Unit;
use InvalidArgumentException;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

final class TimeFoundationTest extends Unit
{
    public function testValidatesIanaTimezoneAndResolutionPriority(): void
    {
        $resolver = new TimeZoneResolver('UTC');
        $resolved = $resolver->resolve('Europe/Moscow', 'Asia/Yerevan', 'America/New_York');

        assertSame('Europe/Moscow', $resolved->timezone->identifier);
        assertSame(ResolvedTimeZone::USER, $resolved->source);

        $this->expectException(InvalidArgumentException::class);
        new TimeZone('UTC+3');
    }

    public function testConvertsToRfc3339Utc(): void
    {
        $formatter = new DateTimeFormatter();

        assertSame(
            '2026-09-20T15:42:11Z',
            $formatter->rfc3339Utc('2026-09-20 20:42:11+05'),
        );
    }

    public function testUsesTimezoneDatabaseForDstAndHistoricalChanges(): void
    {
        $formatter = new DateTimeFormatter();
        $helsinki = new TimeZone('Europe/Helsinki');
        $moscow = new TimeZone('Europe/Moscow');

        assertSame('02:30 +02:00', $formatter->inTimeZone('2024-03-31T00:30:00Z', $helsinki)->format('H:i P'));
        assertSame('04:30 +03:00', $formatter->inTimeZone('2024-03-31T01:30:00Z', $helsinki)->format('H:i P'));
        assertNotSame(
            $formatter->inTimeZone('2010-01-01T00:00:00Z', $moscow)->format('P'),
            $formatter->inTimeZone('2012-01-01T00:00:00Z', $moscow)->format('P'),
        );
        assertStringContainsString(
            '2026',
            $formatter->display('2026-09-20T15:42:11Z', $moscow, new Locale('ru-RU'), 'dd.MM.yyyy HH:mm'),
        );
    }
}
