<?php

declare(strict_types=1);

use App\Core\Privacy\Application\ProcessorDefinition;

$params = [
    'traceLink' => 'phpstorm://open?url=file://{file}&line={line}',
];

if (getenv('LOCAL_MAIL_ENABLED') === 'true') {
    if (getenv('SMTP_HOST') !== 'mailpit' || getenv('SMTP_PORT') !== '1025'
        || getenv('SMTP_ENCRYPTION') !== 'none') {
        throw new RuntimeException('Local mail requires the Compose Mailpit service on port 1025 without TLS.');
    }
    $params['privacyProcessorDefinitions'] = [new ProcessorDefinition(
        code: 'smtp',
        nameReference: 'local://docker-compose/mailpit',
        role: 'operator',
        purposes: ['communication_email', 'security_email'],
        dataCategories: ['contact', 'message_content'],
        country: (string) getenv('LOCAL_MAIL_COUNTRY'),
        contractReference: 'local://docker-compose/mailpit',
        securityRequirementsConfirmed: true,
        localizationCompatible: true,
        activeFrom: new DateTimeImmutable('@0'),
    )];
}

return $params;
