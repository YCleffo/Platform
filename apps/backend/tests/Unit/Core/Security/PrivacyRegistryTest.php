<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Application\DataCategoryRegistry;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Domain\DataCategoryDefinition;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;
use Codeception\Test\Unit;
use InvalidArgumentException;

final class PrivacyRegistryTest extends Unit
{
    public function testRejectsUnknownRetentionAndContradictoryPurpose(): void
    {
        $categories = new DataCategoryRegistry();
        $definition = new ProcessingPurposeDefinition('test.purpose', 'consent', true, false, ['identity'], ['person'], 'test.unknown', 'operator');
        $this->expectException(InvalidArgumentException::class);
        new ProcessingPurposeRegistry([$definition], $categories, new RetentionPolicyRegistry());
    }

    public function testDuplicateCategoriesAndInvalidCodesFail(): void
    {
        try {
            new DataCategoryRegistry([new DataCategoryDefinition('identity')]);
            self::fail('Duplicate core category was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        new RetentionPolicyDefinition('Invalid Code', 'withdrawal', null, 'delete');
    }

    public function testConsentBasisRequiresConsentAndProcessingRoleIsExplicit(): void
    {
        foreach ([
            ['consent', false, 'operator'],
            ['contract', true, 'operator'],
            ['contract', false, 'unknown_role'],
        ] as [$basis, $requiresConsent, $role]) {
            try {
                new ProcessingPurposeDefinition('test.purpose', $basis, $requiresConsent, false, ['identity'], ['person'], 'test.retention', $role);
                self::fail('Contradictory purpose was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
