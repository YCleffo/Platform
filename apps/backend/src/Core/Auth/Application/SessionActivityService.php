<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Time\Application\ClockInterface;
use InvalidArgumentException;

final readonly class SessionActivityService
{
    public function __construct(
        private SessionRepository $sessions,
        private ClockInterface $clock,
        private SecurityAuditService $securityAudit,
        private int $touchIntervalSeconds,
    ) {
        if ($touchIntervalSeconds < 1 || $touchIntervalSeconds > 3600) {
            throw new InvalidArgumentException('Session touch interval must be between 1 and 3600 seconds.');
        }
    }

    public function touch(
        string $sessionId,
        string $accountId,
        ClientContext $context,
    ): ?SessionActivityResult {
        $previous = $this->sessions->findForAccount($sessionId, $accountId);
        if ($previous === null || $previous->revokedAt !== null) {
            return null;
        }

        $current = $this->sessions->touch(new TouchSessionCommand(
            sessionId: $sessionId,
            accountId: $accountId,
            touchBefore: $this->clock->now()
                ->modify(sprintf('-%d seconds', $this->touchIntervalSeconds))
                ->format(DATE_ATOM),
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            browserName: $context->device->browserName,
            browserVersion: $context->device->browserVersion,
            osName: $context->device->osName,
            osVersion: $context->device->osVersion,
            deviceType: $context->device->deviceType,
            deviceModel: $context->device->deviceModel,
            clientLocale: $context->locale->locale->tag,
            clientTimezone: $context->reportedTimezone,
            clientInstanceId: $context->clientInstanceId,
            deviceFingerprintHashHex: $context->fingerprintHashHex,
            geoCountryCode: $context->geoLocation->countryCode,
            geoRegion: $context->geoLocation->region,
            geoCity: $context->geoLocation->city,
            geoTimezone: $context->geoLocation->timezone,
        ));
        if ($current === null) {
            return new SessionActivityResult($previous, $previous, false, false, false);
        }

        $result = new SessionActivityResult(
            previous: $previous,
            current: $current,
            updated: true,
            ipChanged: $previous->ipAddress !== $current->ipAddress,
            deviceChanged: $previous->deviceFingerprintHashHex !== $current->deviceFingerprintHashHex,
        );
        if ($result->ipChanged) {
            $this->securityAudit->sessionIpChanged(
                $accountId,
                $sessionId,
                $previous->ipAddress,
                $context,
            );
        }
        if ($result->deviceChanged) {
            $this->securityAudit->sessionDeviceChanged(
                $accountId,
                $sessionId,
                $previous,
                $context,
            );
        }

        return $result;
    }
}
