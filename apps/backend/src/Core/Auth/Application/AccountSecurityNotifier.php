<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Account\Application\AccountSecurityRepository;
use App\Core\Communication\Application\EmailService;
use App\Core\Communication\Application\QueueEmailCommand;
use App\Core\Internationalization\Application\SupportedLocales;

final readonly class AccountSecurityNotifier
{
    public function __construct(
        private IdentifierRepository $identifiers,
        private EmailService $email,
        private AccountSecurityRepository $accounts,
        private SupportedLocales $supportedLocales,
    ) {}

    public function notify(string $accountId, string $templateCode, ?string $locale = null): void
    {
        $target = $this->identifiers->findRecoveryEmailByAccount($accountId);
        if ($target === null) {
            return;
        }
        $accountLocale = $this->accounts->findSecurityState($accountId)?->locale;
        $resolvedLocale = $this->supportedLocales->resolve($locale ?? $accountLocale ?? '')->tag;
        $this->email->queue(new QueueEmailCommand(
            producerModule: 'core.auth',
            recipientAddress: $target->value,
            recipientName: null,
            locale: $resolvedLocale,
            templateCode: $templateCode,
            variables: [],
            aggregateType: 'core.account',
            aggregateId: $accountId,
        ));
    }
}
