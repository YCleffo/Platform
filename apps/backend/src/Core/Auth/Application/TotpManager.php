<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Account\Application\AccountSecurityRepository;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\IdentifierDigestService;
use App\Core\Security\Application\RateLimiterInterface;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Time\Application\ClockInterface;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class TotpManager
{
    public function __construct(
        private TotpFactorRepository $factors,
        private RecoveryCodeRepository $recoveryCodes,
        private PasswordCredentialRepository $passwords,
        private PasswordHasherInterface $passwordHasher,
        private TotpSecretCipher $cipher,
        private TotpService $totp,
        private TotpProvisioningPolicy $provisioning,
        private RecoveryCodeService $recovery,
        private RecentAuthenticationPolicy $recentAuthentication,
        private AccountSecurityRepository $accounts,
        private SessionRepository $sessions,
        private AccountSecurityNotifier $notifications,
        private RateLimiterInterface $rateLimiter,
        private AuthRateLimitPolicy $ratePolicy,
        private IdentifierDigestService $digests,
        private AuthRuntimePolicy $runtimePolicy,
        private SecurityAuditService $audit,
        private ClockInterface $clock,
        private ConnectionInterface $db,
    ) {}

    /** @return array{totpEnabled: bool, verifiedAt: ?string, recoveryCodesRemaining: int} */
    public function status(string $accountId): array
    {
        $factor = $this->factors->findActive($accountId);

        return [
            'totpEnabled' => $factor !== null,
            'verifiedAt' => $factor?->verifiedAt,
            'recoveryCodesRemaining' => $this->recoveryCodes->countAvailable($accountId),
        ];
    }

    /** @return array{setupId: string, issuer: string, accountLabel: string, secret: string, provisioningUri: string, expiresAt: string} */
    public function setup(
        string $accountId,
        string $sessionId,
        ?string $label,
        ClientContext $context,
    ): array {
        $this->recentAuthentication->require($accountId, $sessionId);
        if ($this->factors->findActive($accountId) !== null) {
            throw new MfaManagementException();
        }
        if (!$this->rateLimiter->consume(
            'totp:setup',
            $this->digests->digest($accountId),
            $this->ratePolicy->totpSetupConfirmLimit,
            $this->ratePolicy->securityWindowSeconds,
        )) {
            throw new LoginRateLimitedException();
        }

        $secretBytes = $this->totp->generateSecret();
        $base32 = Base32::encode($secretBytes);
        $encrypted = $this->cipher->encrypt($secretBytes);
        // The request label is retained only for backward-compatible input parsing.
        $accountLabel = $this->provisioning->accountLabel($accountId);
        $expiresAt = $this->clock->now()->modify(sprintf(
            '+%d seconds',
            $this->runtimePolicy->totpSetupTtlSeconds,
        ))->format('Y-m-d\TH:i:s\Z');
        $factor = $this->factors->createPending(new CreateTotpFactorCommand(
            accountId: $accountId,
            label: $accountLabel,
            secretEncryptedHex: $encrypted->ciphertextHex,
            algorithm: 'sha1',
            digits: 6,
            periodSeconds: 30,
            secretKeyVersion: $encrypted->keyVersion,
            setupExpiresAt: $expiresAt,
        ));
        $this->audit->record(new SecurityAuditCommand(
            action: SecurityAction::TOTP_SETUP_STARTED,
            actorAccountId: $accountId,
            resourceType: 'core.auth_totp_factor',
            resourceId: $factor->id,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
        ));
        return [
            'setupId' => $factor->id,
            'issuer' => $this->provisioning->issuer,
            'accountLabel' => $accountLabel,
            'secret' => $base32,
            'provisioningUri' => $this->totp->provisioningUri($this->provisioning->issuer, $accountLabel, $base32),
            'expiresAt' => $expiresAt,
        ];
    }

    /** @return array{recoveryCodes: list<string>} */
    public function confirm(
        string $accountId,
        string $sessionId,
        string $setupId,
        string $code,
        ClientContext $context,
    ): array {
        $this->recentAuthentication->require($accountId, $sessionId);
        $this->consumeLimit('totp:confirm', $accountId);
        $factor = $this->factors->findPending($setupId, $accountId);
        if ($factor === null) {
            throw new MfaManagementException();
        }
        $counter = $this->totp->matchingCounter(
            $this->cipher->decrypt($factor->secretEncryptedHex, $factor->secretKeyVersion),
            $code,
            $factor->algorithm,
            $factor->digits,
            $factor->periodSeconds,
        );
        if ($counter === null) {
            throw new MfaManagementException();
        }
        $codes = $this->recovery->generate();
        $this->db->transaction(function () use ($factor, $accountId, $sessionId, $counter, $codes, $context): void {
            if ($this->factors->activate($factor->id, $accountId, $counter) === null) {
                throw new MfaManagementException();
            }
            $this->recoveryCodes->revokeAllCodes($accountId);
            $this->recoveryCodes->store($accountId, array_map($this->recovery->hashHex(...), $codes));
            $stamp = $this->accounts->rotateSecurityStamp($accountId)
                ?? throw new RuntimeException('Account security stamp could not be rotated.');
            $this->sessions->secureCurrentAndRevokeOthers(
                $accountId,
                $sessionId,
                $stamp,
                'totp_enabled',
                true,
            );
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::TOTP_ENABLED,
                actorAccountId: $accountId,
                resourceType: 'core.auth_totp_factor',
                resourceId: $factor->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
            ));
            $this->notifications->notify($accountId, 'auth.totp.enabled', $context->locale->locale->tag);
        });

        return ['recoveryCodes' => $codes];
    }

    /** @return array{recoveryCodes: list<string>} */
    public function regenerateRecoveryCodes(
        string $accountId,
        string $sessionId,
        string $code,
        ClientContext $context,
    ): array {
        $this->recentAuthentication->require($accountId, $sessionId, true);
        $this->consumeLimit('recovery-code:regenerate', $accountId, $this->ratePolicy->recoveryCodeLimit);
        $codes = $this->recovery->generate();
        $this->db->transaction(function () use ($accountId, $code, $codes, $context): void {
            $factor = $this->verifyAndAccept($accountId, $code);
            $this->recoveryCodes->revokeAllCodes($accountId);
            $this->recoveryCodes->store($accountId, array_map($this->recovery->hashHex(...), $codes));
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::RECOVERY_CODES_REGENERATED,
                actorAccountId: $accountId,
                resourceType: 'core.auth_totp_factor',
                resourceId: $factor->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
            ));
            $this->notifications->notify($accountId, 'auth.recovery_codes.regenerated', $context->locale->locale->tag);
        });

        return ['recoveryCodes' => $codes];
    }

    public function disable(
        string $accountId,
        string $sessionId,
        ?string $password,
        string $code,
        ClientContext $context,
    ): void {
        $this->recentAuthentication->require($accountId, $sessionId, true);
        $this->consumeLimit('totp:disable', $accountId, $this->ratePolicy->recoveryCodeLimit);
        $credential = $this->passwords->findByAccountId($accountId);
        if ($credential !== null
            && ($password === null || !$this->passwordHasher->verify($password, $credential->passwordHash))
        ) {
            throw new MfaManagementException();
        }
        $this->db->transaction(function () use ($accountId, $sessionId, $code, $context): void {
            $factor = $this->verifyAndAccept($accountId, $code);
            $this->factors->revokeAllFactors($accountId);
            $this->recoveryCodes->revokeAllCodes($accountId);
            $stamp = $this->accounts->rotateSecurityStamp($accountId)
                ?? throw new RuntimeException('Account security stamp could not be rotated.');
            $this->sessions->secureCurrentAndRevokeOthers($accountId, $sessionId, $stamp, 'totp_disabled');
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::TOTP_DISABLED,
                actorAccountId: $accountId,
                resourceType: 'core.auth_totp_factor',
                resourceId: $factor->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
            ));
            $this->notifications->notify($accountId, 'auth.totp.disabled', $context->locale->locale->tag);
        });
    }

    private function verifyAndAccept(string $accountId, string $code): TotpFactor
    {
        $factor = $this->factors->findActive($accountId);
        $counter = $factor === null ? null : $this->totp->matchingCounter(
            $this->cipher->decrypt($factor->secretEncryptedHex, $factor->secretKeyVersion),
            $code,
            $factor->algorithm,
            $factor->digits,
            $factor->periodSeconds,
        );
        if ($factor === null || $counter === null || !$this->factors->acceptCounter($factor->id, $counter)) {
            throw new MfaManagementException();
        }

        return $factor;
    }

    private function consumeLimit(string $bucket, string $accountId, ?int $limit = null): void
    {
        if (!$this->rateLimiter->consume(
            $bucket,
            $this->digests->digest($accountId),
            $limit ?? $this->ratePolicy->totpSetupConfirmLimit,
            $this->ratePolicy->securityWindowSeconds,
        )) {
            throw new LoginRateLimitedException();
        }
    }
}
