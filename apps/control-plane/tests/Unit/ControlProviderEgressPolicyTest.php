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

    public function testForeignReviewAndRestrictionBlock(): void
    {
        $base = ['status' => 'allowed', 'country' => 'US',
            'purposes' => ['control_email_verification'], 'data_categories' => ['contact_email'],
            'evidence_reference' => 'contract.1', 'adequacy_status' => 'non_adequate',
            'notification_submitted_at' => '2026-09-01T00:00:00+00:00',
            'notification_evidence_reference' => 'rkn.1', 'regulator_review_until' => '2099-01-01T00:00:00+00:00',
            'regulator_decision' => 'pending'];
        foreach ([$base, array_replace($base, ['adequacy_status' => 'adequate', 'regulator_decision' => 'restricted']),
            array_replace($base, ['adequacy_status' => 'adequate', 'regulator_decision' => 'prohibited'])] as $decision) {
            try {
                (new ControlProviderEgressPolicy(['smtp' => $decision]))->assertAllowed('smtp', 'control_email_verification', ['contact_email']);
                self::fail('Restricted transfer must be blocked.');
            } catch (\DomainException) { self::assertTrue(true); }
        }
    }
}
