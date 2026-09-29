<?php

declare(strict_types=1);

use App\Core\Consent\Application\ConsentDefinition;
use App\Core\Privacy\Domain\ProcessingPurposeDefinition;
use App\Core\Privacy\Domain\RetentionPolicyDefinition;

return [
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
        new ConsentDefinition('test.processing', 'test-v1', str_repeat('a', 64), true, 'test.processing'),
        new ConsentDefinition('test.required', 'test-v1', str_repeat('b', 64), false, 'test.required'),
    ],
    'retentionPolicyDefinitions' => [new RetentionPolicyDefinition('test.consent', 'withdrawal', null, 'custom_handler')],
    'processingPurposeDefinitions' => [
        new ProcessingPurposeDefinition('test.processing', 'consent', true, true, ['identity'], ['person'], 'test.consent', 'operator'),
        new ProcessingPurposeDefinition('test.required', 'consent', true, false, ['identity'], ['person'], 'test.consent', 'operator'),
    ],
];
