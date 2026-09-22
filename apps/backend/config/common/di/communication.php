<?php

declare(strict_types=1);

use App\Core\Communication\Application\EmailSenderInterface;
use App\Core\Communication\Application\EmailOutboxPolicy;
use App\Core\Communication\Application\TemplateRendererInterface;
use App\Core\Communication\Infrastructure\FileEmailTemplateRenderer;
use App\Core\Communication\Infrastructure\SmtpConfiguration;
use App\Core\Communication\Infrastructure\SmtpEmailSender;

/** @var array<string, mixed> $params */
$communication = $params['communication'];
$smtp = $communication['smtp'];
$templates = $communication['templates'];
$outbox = $communication['outbox'];

return [
    SmtpConfiguration::class => [
        '__construct()' => [
            'host' => $smtp['host'],
            'port' => $smtp['port'],
            'username' => $smtp['username'],
            'password' => $smtp['password'],
            'encryption' => $smtp['encryption'],
            'fromAddress' => $smtp['fromAddress'],
            'fromName' => $smtp['fromName'],
        ],
    ],
    TemplateRendererInterface::class => [
        'class' => FileEmailTemplateRenderer::class,
        '__construct()' => [
            'templateDirectory' => $templates['path'],
        ],
    ],
    EmailSenderInterface::class => [
        'class' => SmtpEmailSender::class,
    ],
    EmailOutboxPolicy::class => [
        '__construct()' => [
            'leaseSeconds' => $outbox['leaseSeconds'],
            'maxAttempts' => $outbox['maxAttempts'],
            'retryBaseSeconds' => $outbox['retryBaseSeconds'],
            'retryMaxSeconds' => $outbox['retryMaxSeconds'],
            'messageIdDomain' => $outbox['messageIdDomain'],
        ],
    ],
];
