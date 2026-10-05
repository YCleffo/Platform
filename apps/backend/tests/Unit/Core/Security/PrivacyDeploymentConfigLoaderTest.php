<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Infrastructure\PrivacyDeploymentConfigLoader;
use PHPUnit\Framework\TestCase;

final class PrivacyDeploymentConfigLoaderTest extends TestCase
{
    public function testDeploymentDefinitionsAreTypedAndUnknownFieldsFail(): void
    {
        $config = [
            'consentDefinitions' => [['code' => 'example.consent', 'currentDocumentVersion' => 'v1',
                'currentDocumentSha256Hex' => str_repeat('a', 64), 'withdrawable' => true,
                'purposeCode' => 'example.purpose']],
            'privacyDistributionConsentDefinitions' => [['purposeCode' => 'example.purpose',
                'documentVersion' => 'v1', 'documentSha256' => str_repeat('b', 64),
                'allowedCategories' => ['example.contact'], 'allowedFields' => ['display_name']]],
            'processingPurposeDefinitions' => [['code' => 'example.purpose', 'legalBasisCode' => 'consent',
                'requiresConsent' => true, 'representativeAllowed' => false,
                'dataCategories' => ['example.contact'], 'subjectCategories' => ['person'],
                'retentionPolicyCode' => 'example.retention', 'processingRole' => 'operator',
                'distributionAllowed' => true, 'description' => 'Example purpose']],
            'dataCategoryDefinitions' => [['code' => 'example.contact', 'publicDistribution' => true]],
            'subjectCategoryDefinitions' => [['code' => 'person']],
            'retentionPolicyDefinitions' => [['code' => 'example.retention', 'trigger' => 'purpose_completed',
                'duration' => null, 'action' => 'custom_handler', 'durationUnit' => 'event_only']],
            'privacyProcessingRoleDefinitions' => [['purposeCode' => 'example.purpose', 'platformRole' => 'operator',
                'operatorReference' => 'urn:operator:test', 'instructionReference' => null,
                'dataCategories' => ['example.contact'], 'permittedOperations' => ['collect'],
                'securityRequirementsConfirmed' => true, 'incidentNotificationObligation' => true,
                'deletionOrReturnObligation' => 'erase_after_retention']],
            'privacyProcessingInformationDefinitions' => [['purposeCode' => 'example.purpose',
                'methodCodes' => ['automated'], 'sourceCodes' => ['subject'],
                'rightsProcedureReference' => 'urn:rights:test', 'complianceMethodCodes' => ['access_control'],
                'rightsProcedureText' => 'Submit a verified request.',
                'complianceMethodDescriptions' => ['access_control' => 'Approved roles only.']]],
            'privacyProcessorDefinitions' => [['code' => 'example.processor', 'nameReference' => 'urn:processor:test',
                'role' => 'processor_by_instruction', 'purposes' => ['example.purpose'],
                'dataCategories' => ['example.contact'], 'country' => 'RU',
                'contractReference' => 'urn:contract:test', 'securityRequirementsConfirmed' => true,
                'localizationCompatible' => true, 'activeFrom' => '2026-01-01T00:00:00Z',
                'name' => 'Processor', 'address' => 'Address']],
            'privacyRecipientDefinitions' => [['code' => 'example.recipient', 'nameReference' => 'urn:recipient:test',
                'purposes' => ['example.purpose'], 'dataCategories' => ['example.contact'],
                'country' => 'RU', 'contractReference' => 'urn:contract:recipient',
                'securityRequirementsConfirmed' => true, 'localizationCompatible' => true,
                'activeFrom' => '2026-01-01T00:00:00Z', 'name' => 'Recipient', 'address' => 'Address']],
            'privacyTransferDefinitions' => [],
            'privacyBusinessCalendar' => ['coverageFrom' => '2026-01-01', 'coverageUntil' => '2026-12-31',
                'weekends' => [6, 7], 'nonWorkingDates' => [], 'workingOverrideDates' => [],
                'sourceReference' => 'urn:calendar:reviewed', 'verifiedAt' => '2026-09-01T00:00:00Z'],
            'privacyOperatorIdentity' => ['name' => 'Example operator', 'address' => 'Example address'],
            'privacyOperatorNotification' => ['required' => true, 'registeredPurposes' => []],
            'privacySecurityReferences' => [], 'privacyDataResidency' => [],
            'privacyDestructionEvidence' => [], 'privacyLegalEvidence' => [],
            'privacyIncidentDeadlines' => [], 'privacyCleanup' => [],
        ];
        $path = tempnam(sys_get_temp_dir(), 'privacy-config-');
        try {
            file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
            $loaded = (new PrivacyDeploymentConfigLoader())->load($path);
            self::assertSame('Example operator', $loaded['privacyOperatorIdentity']['name']);
            self::assertSame('Processor', $loaded['privacyProcessorDefinitions'][0]->name);
            self::assertSame('v1', $loaded['privacyDistributionConsentDefinitions'][0]->documentVersion);
            $config['processingPurposeDefinitions'][0]['unknownLegalField'] = 'forged';
            file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
            $this->expectException(\RuntimeException::class);
            (new PrivacyDeploymentConfigLoader())->load($path);
        } finally {
            unlink($path);
        }
    }
}
