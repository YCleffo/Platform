<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Security;

use App\Core\Privacy\Infrastructure\PrivacyDeploymentConfigLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class PrivacyProductionConfigurationTest extends TestCase
{
    public function testValidConfigurationLoads(): void
    {
        $fixture = dirname(__DIR__, 3) . '/Fixtures/privacy/synthetic-production-policy.json';
        self::assertFileExists($fixture);
        $loaded = (new PrivacyDeploymentConfigLoader())->load($fixture);
        self::assertSame('synthetic.identity', $loaded['processingPurposeDefinitions'][0]->code);
    }

    /** @dataProvider invalidConfigurations */
    public function testInvalidConfigurationIsRejected(string $path, callable $mutate): void
    {
        $configuration = $this->configuration();
        $mutate($configuration);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($path);
        $this->load($configuration);
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'missing required field' => ['processingPurposeDefinitions', static function (array &$c): void {
            unset($c['processingPurposeDefinitions'][0]['description']);
        }];
        yield 'duplicate purpose' => ['duplicate code', static function (array &$c): void {
            $c['processingPurposeDefinitions'][] = $c['processingPurposeDefinitions'][0];
        }];
        yield 'unknown category' => ['dataCategories', static function (array &$c): void {
            $c['processingPurposeDefinitions'][0]['dataCategories'] = ['unknown.category'];
        }];
        yield 'unknown subject category' => ['subjectCategories', static function (array &$c): void {
            $c['processingPurposeDefinitions'][0]['subjectCategories'] = ['unknown.subject'];
        }];
        yield 'missing legal basis' => ['legalBasisCode', static function (array &$c): void {
            $c['processingPurposeDefinitions'][0]['legalBasisCode'] = '';
        }];
        yield 'invalid retention' => ['retentionPolicyDefinitions[0]', static function (array &$c): void {
            $c['retentionPolicyDefinitions'][0]['action'] = 'not-an-action';
        }];
        yield 'invalid processor' => ['privacyProcessorDefinitions[0]', static function (array &$c): void {
            $c['privacyProcessorDefinitions'][] = ['code' => 'processor', 'nameReference' => '', 'role' => 'processor_by_instruction',
                'purposes' => ['production.purpose'], 'dataCategories' => ['contact'], 'country' => 'RUS',
                'contractReference' => 'contract', 'securityRequirementsConfirmed' => true, 'localizationCompatible' => true,
                'activeFrom' => '2026-01-01T00:00:00Z'];
        }];
        yield 'invalid cross-border' => ['privacyTransferDefinitions[0]', static function (array &$c): void {
            $c['privacyTransferDefinitions'][] = ['providerCode' => 'unknown', 'country' => 'USA', 'purposeCode' => 'production.purpose',
                'dataCategories' => ['contact'], 'notificationStatus' => 'notified', 'allowed' => true,
                'evidenceReference' => 'evidence', 'activeFrom' => '2026-01-01T00:00:00Z'];
        }];
        yield 'invalid distribution' => ['privacyDistributionConsentDefinitions[0]', static function (array &$c): void {
            $c['privacyDistributionConsentDefinitions'][] = ['purposeCode' => 'production.purpose', 'documentVersion' => 'v1',
                'documentSha256' => 'bad', 'allowedCategories' => ['contact'], 'allowedFields' => ['display_name']];
        }];
        yield 'unresolved placeholder' => ['unresolved template placeholder', static function (array &$c): void {
            $c['privacyOperatorIdentity']['name'] = '<operator-name>';
        }];
        yield 'missing business-calendar coverage' => ['Invalid business calendar field', static function (array &$c): void {
            unset($c['privacyBusinessCalendar']['coverageUntil']);
        }];
    }

    public function testMissingProductionConfigFailsClosed(): void
    {
        $this->expectExceptionMessage('PRIVACY_CONFIG_FILE is missing or unreadable');
        (new PrivacyDeploymentConfigLoader())->load('');
    }

    public function testProductionConsoleReadinessPassesWithSyntheticDeploymentPolicy(): void
    {
        $fixture = dirname(__DIR__, 3) . '/Fixtures/privacy/synthetic-production-policy.json';
        $process = new Process(['php', 'yii', 'privacy/compliance/check'], dirname(__DIR__, 4), [
            ...getenv(),
            'APP_ENV' => 'prod',
            'PRIVACY_CONFIG_FILE' => $fixture,
        ]);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('technical readiness PASS', $process->getOutput());
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        return [
            'consentDefinitions' => [['code' => 'production.consent', 'currentDocumentVersion' => 'v1',
                'currentDocumentSha256Hex' => str_repeat('a', 64), 'withdrawable' => true, 'purposeCode' => 'production.purpose']],
            'privacyDistributionConsentDefinitions' => [],
            'processingPurposeDefinitions' => [['code' => 'production.purpose', 'legalBasisCode' => 'consent',
                'requiresConsent' => true, 'representativeAllowed' => false, 'dataCategories' => ['contact'],
                'subjectCategories' => ['person'], 'retentionPolicyCode' => 'production.retention', 'processingRole' => 'operator',
                'description' => 'Production contact processing']],
            'dataCategoryDefinitions' => [['code' => 'contact']],
            'subjectCategoryDefinitions' => [['code' => 'person']],
            'retentionPolicyDefinitions' => [['code' => 'production.retention', 'trigger' => 'purpose_completed',
                'duration' => 30, 'action' => 'delete', 'durationUnit' => 'calendar_days']],
            'privacyProcessingRoleDefinitions' => [['purposeCode' => 'production.purpose', 'platformRole' => 'operator',
                'operatorReference' => 'operator.ref', 'instructionReference' => null, 'dataCategories' => ['contact'],
                'permittedOperations' => ['collect'], 'securityRequirementsConfirmed' => true,
                'incidentNotificationObligation' => true, 'deletionOrReturnObligation' => 'delete']],
            'privacyProcessingInformationDefinitions' => [['purposeCode' => 'production.purpose', 'methodCodes' => ['automated'],
                'sourceCodes' => ['subject'], 'rightsProcedureReference' => 'rights.ref', 'complianceMethodCodes' => ['access_control']]],
            'privacyProcessorDefinitions' => [], 'privacyRecipientDefinitions' => [], 'privacyTransferDefinitions' => [],
            'privacyBusinessCalendar' => ['coverageFrom' => '2026-01-01', 'coverageUntil' => '2026-12-31', 'weekends' => [6, 7],
                'nonWorkingDates' => [], 'workingOverrideDates' => [], 'sourceReference' => 'calendar.ref', 'verifiedAt' => '2026-09-01T00:00:00Z'],
            'privacyOperatorIdentity' => ['name' => 'Operator', 'address' => 'Address'],
            'privacyOperatorNotification' => ['required' => true, 'registeredPurposes' => []],
            'privacySecurityReferences' => [], 'privacyDataResidency' => [], 'privacyDestructionEvidence' => [],
            'privacyLegalEvidence' => [], 'privacyIncidentDeadlines' => [], 'privacyCleanup' => [],
        ];
    }

    /** @param array<string, mixed> $configuration */
    private function load(array $configuration): array
    {
        $path = tempnam(sys_get_temp_dir(), 'privacy-production-');
        file_put_contents($path, json_encode($configuration, JSON_THROW_ON_ERROR));
        try { return (new PrivacyDeploymentConfigLoader())->load($path); }
        finally { unlink($path); }
    }
}
