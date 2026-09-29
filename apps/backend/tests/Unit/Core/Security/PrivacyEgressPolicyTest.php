<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Application\CrossBorderTransferDefinition;
use App\Core\Privacy\Application\CrossBorderTransferPolicy;
use App\Core\Privacy\Application\CrossBorderTransferRegistry;
use App\Core\Privacy\Application\DataRecipientRegistry;
use App\Core\Privacy\Application\ProcessorDefinition;
use App\Core\Privacy\Application\ProcessorRegistry;
use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class PrivacyEgressPolicyTest extends TestCase
{
    public function testUnknownProviderAndUnknownTransferBlockEgress(): void
    {
        $now = new DateTimeImmutable('2026-09-29T10:00:00Z');
        $clock = new class($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}
            public function now(): DateTimeImmutable { return $this->now; }
        };
        $provider = new ProcessorDefinition('smtp', 'contract.smtp', 'processor_by_instruction',
            ['security_email'], ['contact'], 'US', 'contract.smtp', true, true,
            $now->modify('-1 day'));
        $unknown = new CrossBorderTransferPolicy(new ProcessorRegistry(),
            new DataRecipientRegistry(), new CrossBorderTransferRegistry(), $clock);
        try {
            $unknown->assertEgressAllowed('smtp', 'security_email', ['contact']);
            self::fail('Unknown provider was allowed.');
        } catch (DomainException) { self::assertTrue(true); }
        $missingTransfer = new CrossBorderTransferPolicy(new ProcessorRegistry([$provider]),
            new DataRecipientRegistry(), new CrossBorderTransferRegistry(), $clock);
        try {
            $missingTransfer->assertEgressAllowed('smtp', 'security_email', ['contact']);
            self::fail('Unknown transfer was allowed.');
        } catch (DomainException) { self::assertTrue(true); }
        $approved = new CrossBorderTransferPolicy(new ProcessorRegistry([$provider]),
            new DataRecipientRegistry(), new CrossBorderTransferRegistry([
                new CrossBorderTransferDefinition('smtp', 'US', 'security_email', ['contact'],
                    'notified', true, 'notification.1', $now->modify('-1 day')),
            ]), $clock);
        $approved->assertEgressAllowed('smtp', 'security_email', ['contact']);
        self::assertTrue(true);
    }
}
