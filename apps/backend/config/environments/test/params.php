<?php

declare(strict_types=1);

use App\Core\Consent\Application\ConsentDefinition;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;
use App\Core\Privacy\Application\ProcessorDefinition;

$testConsentDocument = 'Synthetic consent document for automated tests. No production legal text.';
$params = [
    'privacyDestructionEvidence' => [
        'code' => 'test.destruction_evidence',
        'duration' => 26304,
        'unit' => 'hours',
        'hmacKey' => str_repeat('x', 32),
    ],
    'privacyBusinessCalendar' => [
        'coverageFrom' => '2026-01-01',
        'coverageUntil' => '2027-12-31',
        'weekends' => [6, 7],
        'nonWorkingDates' => ['2026-09-28'],
        'workingOverrideDates' => ['2027-01-02'],
    ],
    'consentDefinitions' => [
        new ConsentDefinition('test.processing', 'test-v1', hash('sha256', $testConsentDocument), true, 'test.processing', 'Test consent document', $testConsentDocument),
        new ConsentDefinition('test.required', 'test-v1', str_repeat('b', 64), false, 'test.required'),
    ],
    'retentionPolicyDefinitions' => [new RetentionPolicyDefinition('test.consent', 'withdrawal', null, 'custom_handler')],
    'processingPurposeDefinitions' => [
        new ProcessingPurposeDefinition('test.processing', 'consent', true, true, ['identity'], ['person'], 'test.consent', 'operator'),
        new ProcessingPurposeDefinition('test.required', 'consent', true, false, ['identity'], ['person'], 'test.consent', 'operator'),
    ],
];

// Explicitly allow only the disposable browser suite's internal SMTP sink.
// The default test environment and every production deployment retain their own egress policy.
if (getenv('CORE_AUTH_BROWSER_SMTP') === '1') {
    $nonce = getenv('E2E_ISOLATION_PROOF');
    if (getenv('APP_ENV') !== 'test' || getenv('TEST_ISOLATED_RUNTIME') !== '1'
        || getenv('DB_NAME') !== 'platform_test' || getenv('REDIS_HOST') !== 'redis-test'
        || !is_string($nonce) || strlen($nonce) < 32
        || getenv('SMTP_HOST') !== 'mailpit-core-test' || getenv('SMTP_PORT') !== '1025'
        || getenv('SMTP_ENCRYPTION') !== 'none') {
        throw new RuntimeException('Core browser SMTP requires the nonce-bound isolated Mailpit runtime.');
    }
    $params['privacyProcessorDefinitions'] = [new ProcessorDefinition(
        code: 'smtp',
        nameReference: 'test://core-auth/mailpit',
        role: 'operator',
        purposes: ['communication_email', 'security_email'],
        dataCategories: ['contact', 'message_content'],
        country: 'RU',
        contractReference: 'test://core-auth/mailpit',
        securityRequirementsConfirmed: true,
        localizationCompatible: true,
        activeFrom: new DateTimeImmutable('@0'),
    )];
}

// Synthetic operator copy is enabled only in an explicitly nonce-bound disposable browser runtime.
// ProcessingInformationRegistry additionally checks the actual DB/Redis guard in test DI.
if (getenv('CORE_PRIVACY_BROWSER_FIXTURE') === '1') {
    $nonce = getenv('E2E_ISOLATION_PROOF');
    if (getenv('APP_ENV') !== 'test' || getenv('TEST_ISOLATED_RUNTIME') !== '1'
        || getenv('DB_NAME') !== 'platform_test' || getenv('REDIS_HOST') !== 'redis-test'
        || !is_string($nonce) || strlen($nonce) < 32) {
        throw new RuntimeException('Privacy browser metadata requires an isolated nonce-bound runtime.');
    }
    $params['privacyOperatorIdentity'] = ['name' => 'Synthetic browser fixture operator', 'address' => 'Synthetic test address'];
    $params['privacyProcessingRoleDefinitions'] = array_map(
        static fn(string $purpose): \App\Core\Privacy\Application\ProcessingRoleDefinition => new \App\Core\Privacy\Application\ProcessingRoleDefinition(
            $purpose,
            'operator',
            'test://privacy/operator',
            null,
            ['identity'],
            ['collect', 'store'],
            true,
            true,
            'erase_after_retention',
        ),
        ['test.processing', 'test.required'],
    );
}

return $params;
