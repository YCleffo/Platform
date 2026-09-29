<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Account\Application\AccountSecurityRepository;
use App\Core\Client\Domain\ClientContext;
use App\Core\Internationalization\Application\LocaleContext;
use App\Core\Internationalization\Application\LocaleResolver;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class SessionIssuer
{
    public function __construct(
        private SessionRepository $sessions,
        private AccountSecurityRepository $accounts,
        private OpaqueTokenService $tokens,
        private AuthRuntimePolicy $policy,
        private ClockInterface $clock,
        private SecurityAuditService $audit,
        private ConnectionInterface $db,
        private LocaleResolver $localeResolver,
    ) {}

    public function issue(
        string $accountId,
        string $authenticationMethod,
        ClientContext $context,
        bool $mfaVerified,
    ): AuthenticationResult {
        return $this->db->transaction(fn(): AuthenticationResult => $this->issueWithinTransaction(
            $accountId,
            $authenticationMethod,
            $context,
            $mfaVerified,
        ));
    }

    public function issueWithinTransaction(
        string $accountId,
        string $authenticationMethod,
        ClientContext $context,
        bool $mfaVerified,
    ): AuthenticationResult {
        if ($mfaVerified) {
            [$firstFactor] = explode('+', $authenticationMethod, 2);
            AuthenticationMethod::firstFactor($firstFactor);
        } else {
            AuthenticationMethod::firstFactor($authenticationMethod);
        }
        $account = $this->accounts->findSecurityState($accountId);
        $now = $this->clock->now();
        if ($account === null || !$account->allowsHumanAuthentication($now)) {
            throw new InvalidCredentialsException();
        }
        $context = $context->withLocale(LocaleContext::fromResolved(
            $this->localeResolver->resolveAuthenticated(
                $context->locale,
                $account->locale,
                $context->acceptLanguage,
            ),
        ));

        $pair = $this->policy->issuePair($this->tokens);
        $session = $this->sessions->storeSession(new CreateSessionCommand(
            accountId: $accountId,
            sessionTokenHashHex: $pair->accessToken->hashHex,
            refreshTokenHashHex: $pair->refreshToken->hashHex,
            securityStamp: $account->securityStamp,
            authenticationMethod: $authenticationMethod,
            mfaVerifiedAt: $mfaVerified ? self::timestamp($now) : null,
            clientType: $context->device->clientType,
            deviceName: null,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            accessExpiresAt: self::timestamp($now->modify(sprintf('+%d seconds', $this->policy->accessTokenTtlSeconds))),
            expiresAt: self::timestamp($now->modify(sprintf('+%d seconds', $this->policy->sessionTtlSeconds))),
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
        if (!$this->accounts->recordSuccessfulLogin($accountId)) {
            throw new RuntimeException('Account login timestamp could not be updated.');
        }
        $this->audit->sessionCreated($accountId, $session->id, $context);
        $this->audit->loginSucceeded($accountId, $session->id, $authenticationMethod, $context);

        return new AuthenticationResult(
            accessToken: $pair->accessToken->raw,
            refreshToken: $pair->refreshToken->raw,
            accessExpiresAt: $session->accessExpiresAt,
            sessionExpiresAt: $session->expiresAt,
            sessionId: $session->id,
        );
    }

    private static function timestamp(DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d\TH:i:s\Z');
    }
}
