<?php

declare(strict_types=1);

namespace App\Core\Communication\Application;

final class EmailEventType
{
    public const EMAIL_REQUESTED = 'communication.email.requested';
    public const PASSWORD_RESET_REQUESTED = 'auth.password.reset.requested';
    public const EMAIL_VERIFICATION_REQUESTED = 'auth.email.verification.requested';
    public const ACCOUNT_CREATED = 'auth.account.created';

    private const TEMPLATE_BY_EVENT = [
        self::PASSWORD_RESET_REQUESTED => 'auth.password.reset',
        self::EMAIL_VERIFICATION_REQUESTED => 'auth.email.verification',
        self::ACCOUNT_CREATED => 'auth.account.created',
    ];

    /** @return non-empty-list<string> */
    public static function supported(): array
    {
        return [
            self::EMAIL_REQUESTED,
            self::PASSWORD_RESET_REQUESTED,
            self::EMAIL_VERIFICATION_REQUESTED,
            self::ACCOUNT_CREATED,
        ];
    }

    public static function templateCode(string $eventType, ?string $requestedCode): ?string
    {
        if ($eventType === self::EMAIL_REQUESTED) {
            return $requestedCode;
        }

        return self::TEMPLATE_BY_EVENT[$eventType] ?? null;
    }
}
