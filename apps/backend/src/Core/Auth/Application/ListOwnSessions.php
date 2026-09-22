<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class ListOwnSessions
{
    public function __construct(private SessionRepository $sessions) {}

    /** @return list<OwnSessionView> */
    public function execute(string $authenticatedAccountId, string $currentSessionId): array
    {
        return array_map(
            static fn(AuthSession $session): OwnSessionView => new OwnSessionView(
                id: $session->id,
                current: $session->id === $currentSessionId,
                clientType: $session->clientType,
                deviceName: $session->deviceName,
                browserName: $session->browserName,
                browserVersion: $session->browserVersion,
                osName: $session->osName,
                osVersion: $session->osVersion,
                deviceType: $session->deviceType,
                deviceModel: $session->deviceModel,
                ipAddress: $session->ipAddress,
                clientLocale: $session->clientLocale,
                clientTimezone: $session->clientTimezone,
                geoCountryCode: $session->geoCountryCode,
                geoRegion: $session->geoRegion,
                geoCity: $session->geoCity,
                geoTimezone: $session->geoTimezone,
                createdAt: $session->createdAt,
                lastSeenAt: $session->lastSeenAt,
                expiresAt: $session->expiresAt,
            ),
            $this->sessions->findActiveForAccount($authenticatedAccountId),
        );
    }
}
