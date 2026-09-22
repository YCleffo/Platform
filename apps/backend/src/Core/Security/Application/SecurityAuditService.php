<?php

declare(strict_types=1);

namespace App\Core\Security\Application;

use App\Core\Audit\Application\AuditEventReceipt;
use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Auth\Application\AuthSession;
use App\Core\Client\Domain\ClientContext;
use InvalidArgumentException;

final readonly class SecurityAuditService
{
    private const FORBIDDEN_METADATA_TERMS = [
        'password',
        'token',
        'secret',
        'credential',
        'recoverycode',
        'securitystamp',
        'fingerprint',
    ];

    public function __construct(private AuditService $audit) {}

    public function record(SecurityAuditCommand $command): AuditEventReceipt
    {
        if (!in_array($command->action, SecurityAction::all(), true)) {
            throw new InvalidArgumentException('Security audit action is not standardized.');
        }
        $this->assertSafe($command->metadata);
        $this->assertSafe($command->beforeData ?? []);
        $this->assertSafe($command->afterData ?? []);

        return $this->audit->record(new WriteAuditEventCommand(
            actorType: $command->actorAccountId === null ? 'anonymous' : 'account',
            actorAccountId: $command->actorAccountId,
            action: $command->action,
            resourceType: $command->resourceType,
            resourceId: $command->resourceId,
            requestId: $command->requestId,
            correlationId: $command->correlationId,
            ipAddress: $command->ipAddress,
            userAgent: $command->userAgent,
            success: $command->success,
            errorCode: $command->errorCode,
            beforeData: $command->beforeData,
            afterData: $command->afterData,
            metadata: $command->metadata,
        ));
    }

    public function sessionCreated(
        string $accountId,
        string $sessionId,
        ClientContext $context,
    ): AuditEventReceipt {
        return $this->sessionEvent(
            SecurityAction::SESSION_CREATED,
            $accountId,
            $sessionId,
            $context,
        );
    }

    public function loginSucceeded(
        string $accountId,
        string $sessionId,
        string $identifierType,
        ClientContext $context,
    ): AuditEventReceipt {
        return $this->record(new SecurityAuditCommand(
            action: SecurityAction::LOGIN_SUCCESS,
            actorAccountId: $accountId,
            resourceType: 'core.auth_session',
            resourceId: $sessionId,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            metadata: ['identifierType' => $identifierType],
        ));
    }

    public function loginFailed(
        string $identifierType,
        ?string $identifierDigest,
        ClientContext $context,
    ): AuditEventReceipt {
        return $this->record(new SecurityAuditCommand(
            action: SecurityAction::LOGIN_FAILED,
            actorAccountId: null,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            success: false,
            errorCode: 'invalid_credentials',
            metadata: [
                'identifierType' => $identifierType,
                'identifierDigest' => $identifierDigest,
            ],
        ));
    }

    public function loginRateLimited(
        string $identifierType,
        string $identifierDigest,
        ClientContext $context,
    ): AuditEventReceipt {
        return $this->record(new SecurityAuditCommand(
            action: SecurityAction::LOGIN_FAILED,
            actorAccountId: null,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            success: false,
            errorCode: 'rate_limited',
            metadata: [
                'identifierType' => $identifierType,
                'identifierDigest' => $identifierDigest,
            ],
        ));
    }

    public function sessionRefreshed(
        string $accountId,
        string $sessionId,
        ClientContext $context,
    ): AuditEventReceipt {
        return $this->sessionEvent(
            SecurityAction::SESSION_REFRESHED,
            $accountId,
            $sessionId,
            $context,
        );
    }

    public function sessionIpChanged(
        string $accountId,
        string $sessionId,
        ?string $previousIp,
        ClientContext $context,
    ): AuditEventReceipt {
        return $this->sessionEvent(
            SecurityAction::SESSION_IP_CHANGED,
            $accountId,
            $sessionId,
            $context,
            beforeData: ['ipAddress' => $previousIp],
            afterData: ['ipAddress' => $context->ipAddress],
        );
    }

    public function sessionRevoked(
        string $accountId,
        string $sessionId,
        ClientContext $context,
    ): AuditEventReceipt {
        return $this->sessionEvent(
            SecurityAction::SESSION_REVOKED,
            $accountId,
            $sessionId,
            $context,
        );
    }

    public function sessionDeviceChanged(
        string $accountId,
        string $sessionId,
        AuthSession $previous,
        ClientContext $context,
    ): AuditEventReceipt {
        return $this->sessionEvent(
            SecurityAction::SESSION_DEVICE_CHANGED,
            $accountId,
            $sessionId,
            $context,
            beforeData: [
                'browser' => $previous->browserName,
                'browserVersion' => $previous->browserVersion,
                'os' => $previous->osName,
                'osVersion' => $previous->osVersion,
                'deviceType' => $previous->deviceType,
                'deviceModel' => $previous->deviceModel,
            ],
            afterData: [
                'browser' => $context->device->browserName,
                'browserVersion' => $context->device->browserVersion,
                'os' => $context->device->osName,
                'osVersion' => $context->device->osVersion,
                'deviceType' => $context->device->deviceType,
                'deviceModel' => $context->device->deviceModel,
            ],
        );
    }

    public function sessionsRevokedAll(
        string $accountId,
        string $currentSessionId,
        int $revokedCount,
        ClientContext $context,
    ): AuditEventReceipt {
        return $this->record(new SecurityAuditCommand(
            action: SecurityAction::SESSION_REVOKED_ALL,
            actorAccountId: $accountId,
            resourceType: 'core.account',
            resourceId: $accountId,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            metadata: [
                'currentSessionId' => $currentSessionId,
                'revokedCount' => $revokedCount,
                'deviceType' => $context->device->deviceType,
            ],
        ));
    }

    /**
     * @param array<array-key, mixed>|null $beforeData
     * @param array<array-key, mixed>|null $afterData
     */
    private function sessionEvent(
        string $action,
        string $accountId,
        string $sessionId,
        ClientContext $context,
        ?array $beforeData = null,
        ?array $afterData = null,
    ): AuditEventReceipt {
        return $this->record(new SecurityAuditCommand(
            action: $action,
            actorAccountId: $accountId,
            resourceType: 'core.auth_session',
            resourceId: $sessionId,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            beforeData: $beforeData,
            afterData: $afterData,
            metadata: [
                'browser' => $context->device->browserName,
                'browserVersion' => $context->device->browserVersion,
                'os' => $context->device->osName,
                'osVersion' => $context->device->osVersion,
                'deviceType' => $context->device->deviceType,
                'deviceModel' => $context->device->deviceModel,
                'locale' => $context->locale->locale->tag,
                'timezone' => $context->timezone->timezone->identifier,
                'timezoneSource' => $context->timezone->source,
                'approximateGeo' => [
                    'countryCode' => $context->geoLocation->countryCode,
                    'region' => $context->geoLocation->region,
                    'city' => $context->geoLocation->city,
                    'timezone' => $context->geoLocation->timezone,
                ],
            ],
        ));
    }

    /** @param array<array-key, mixed> $data */
    private function assertSafe(array $data): void
    {
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower(str_replace(['_', '-'], '', (string) $key));
            foreach (self::FORBIDDEN_METADATA_TERMS as $term) {
                if (str_contains($normalizedKey, $term)) {
                    throw new InvalidArgumentException(sprintf(
                        'Security audit metadata key "%s" is forbidden.',
                        (string) $key,
                    ));
                }
            }
            if (is_array($value)) {
                $this->assertSafe($value);
            }
        }
    }
}
