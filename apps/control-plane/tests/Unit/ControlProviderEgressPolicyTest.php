<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Privacy\ControlProviderEgressPolicy;
use PHPUnit\Framework\TestCase;

final class ControlProviderEgressPolicyTest extends TestCase
{
    public function testUnknownAndUnnotifiedForeignProviderAreDenied(): void
    {
        $unknown = new ControlProviderEgressPolicy([]);
        try {
            $unknown->assertAllowed('smtp', 'control_email_verification', ['contact_email']);
            self::fail('Unknown provider must be blocked.');
        } catch (\DomainException) {
            self::assertTrue(true);
        }
        $policy = new ControlProviderEgressPolicy(['smtp' => [
            'status' => 'allowed', 'country' => 'US',
            'purposes' => ['control_email_verification'],
            'data_categories' => ['contact_email'],
            'evidence_reference' => 'provider-record-1',
            'notification_status' => 'unknown',
        ]]);
        $this->expectException(\DomainException::class);
        $policy->assertAllowed('smtp', 'control_email_verification', ['contact_email']);
    }

    public function testApprovedProviderMatchesExactPurposeAndCategories(): void
    {
        $policy = new ControlProviderEgressPolicy(['smtp' => [
            'status' => 'allowed', 'country' => 'RU',
            'purposes' => ['control_email_verification'],
            'data_categories' => ['contact_email'],
            'evidence_reference' => 'provider-record-1',
            'notification_status' => 'not_required',
        ]]);
        $policy->assertAllowed('smtp', 'control_email_verification', ['contact_email']);
        self::assertTrue(true);
    }
}
