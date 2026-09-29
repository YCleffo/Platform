<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Account\Application\AccountSecurityRepository;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\IdentifierDigestService;
use App\Core\Security\Application\RateLimiterInterface;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Time\Application\ClockInterface;
use InvalidArgumentException;

final readonly class Login
{
    private const string DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$eXFmVEx6bE1YeVRlWk9ZUQ$OKLKA1CoaQqRz5mZDalB3BtO8KqGxg6taHBXiaNYA6o';

    public function __construct(
        private IdentifierRepository $identifiers,
        private PasswordCredentialRepository $passwords,
        private AccountSecurityRepository $accounts,
        private AuthIdentifierNormalizer $normalizer,
        private PasswordHasherInterface $hasher,
        private ClockInterface $clock,
        private SecurityAuditService $securityAudit,
        private IdentifierDigestService $identifierDigests,
        private RateLimiterInterface $rateLimiter,
        private AuthRateLimitPolicy $rateLimitPolicy,
        private AuthenticationFlowService $authenticationFlow,
    ) {}

    public function execute(LoginCommand $command, ClientContext $context): AuthenticationFlowResult
    {
        $normalized = null;
        try {
            $normalized = $this->normalizer->normalize($command->identifierType, $command->identifier);
        } catch (InvalidArgumentException) {
            // Public failures intentionally do not distinguish malformed or unknown identifiers.
        }

        $identifierDigest = $this->identifierDigests->digest(
            $normalized ?? mb_strtolower(trim($command->identifier), 'UTF-8'),
        );
        $ipDigest = $this->identifierDigests->digest($context->ipAddress ?? 'unknown');
        $allowed = $this->rateLimiter->consume(
            'login:ip',
            $ipDigest,
            $this->rateLimitPolicy->loginIpLimit,
            $this->rateLimitPolicy->loginWindowSeconds,
        );
        $allowed = $this->rateLimiter->consume(
            'login:identifier',
            $identifierDigest,
            $this->rateLimitPolicy->loginIdentifierLimit,
            $this->rateLimitPolicy->loginWindowSeconds,
        ) && $allowed;
        $allowed = $this->rateLimiter->consume(
            'login:combined',
            hash('sha256', $ipDigest . ':' . $identifierDigest),
            $this->rateLimitPolicy->loginCombinedLimit,
            $this->rateLimitPolicy->loginWindowSeconds,
        ) && $allowed;
        if (!$allowed) {
            $this->securityAudit->loginRateLimited($command->identifierType, $identifierDigest, $context);
            throw new LoginRateLimitedException();
        }

        $identifier = $normalized === null
            ? null
            : $this->identifiers->findByNormalized($command->identifierType, $normalized);
        $account = $identifier === null
            ? null
            : $this->accounts->findSecurityState($identifier->accountId);
        $credential = $identifier === null
            ? null
            : $this->passwords->findByAccountId($identifier->accountId);
        $passwordMatches = $this->hasher->verify(
            $command->password,
            $credential?->passwordHash ?? self::DUMMY_PASSWORD_HASH,
        );
        $verificationRequired = in_array($command->identifierType, ['email', 'phone'], true);
        $valid = $identifier !== null
            && $identifier->canLogin
            && (!$verificationRequired || $identifier->verifiedAt !== null)
            && $account !== null
            && $account->allowsHumanAuthentication($this->clock->now())
            && $credential !== null
            && $passwordMatches;
        if (!$valid) {
            $this->securityAudit->loginFailed($command->identifierType, $identifierDigest, $context);
            throw new InvalidCredentialsException();
        }

        if ($this->hasher->needsRehash($credential->passwordHash)) {
            $replacementHash = $this->hasher->hash($command->password);
            $algorithm = password_get_info($replacementHash)['algoName'] ?? 'argon2id';
            $this->passwords->saveHash(
                $account->accountId,
                $replacementHash,
                is_string($algorithm) ? $algorithm : 'argon2id',
                $credential->mustChangePassword,
            );
        }

        return $this->authenticationFlow->completeFirstFactor(
            $account->accountId,
            AuthenticationMethod::PASSWORD,
            $context,
        );
    }
}
