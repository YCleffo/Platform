<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Application\BusinessCalendarInterface;
use App\Core\Privacy\Application\DestructionEvidencePolicy;
use App\Core\Privacy\Application\PrivacyDeadlinePolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DestructionEvidencePolicyTest extends TestCase
{
    public function testShortDestructionEvidencePolicyIsUnavailable(): void
    {
        $calendar = new PrivacyDeadlinePolicy(new class implements BusinessCalendarInterface {
            public function isWorkingDay(DateTimeImmutable $date): bool { return true; }
            public function addWorkingDays(DateTimeImmutable $from, int $days): DateTimeImmutable {
                return $from->modify('+' . $days . ' days');
            }
        });
        foreach ([
            ['duration' => 1, 'unit' => 'hours'],
            ['duration' => 1095, 'unit' => 'calendar_days'],
        ] as $short) {
            self::assertFalse((new DestructionEvidencePolicy($calendar, [
                'code' => 'evidence.test', 'hmacKey' => str_repeat('x', 32), ...$short,
            ]))->available());
        }
        self::assertTrue((new DestructionEvidencePolicy($calendar, [
            'code' => 'evidence.test', 'hmacKey' => str_repeat('x', 32),
            'duration' => 26304, 'unit' => 'hours',
        ]))->available());
    }
}
