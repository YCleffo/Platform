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
use App\Core\Shared\Application\ConflictException;
use App\Core\Shared\Application\NotFoundException;
use App\Core\Time\Application\ClockInterface;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\Exception\CounterException;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PasskeyService
{
    /**
     * Allowlisted transport values per WebAuthn Level 2/3 and browser ecosystem.
     * Unknown or arbitrary client-supplied transports are silently discarded.
     */
    private const ALLOWED_TRANSPORTS = ['internal', 'usb', 'nfc', 'ble', 'hybrid', 'smart-card'];

    /**
     * Maximum permitted size of a WebAuthn response JSON body (32 KiB).
     * Larger payloads are rejected as invalid before deserialization.
     */
    private const MAX_RESPONSE_BYTES = 32_768;

    public function __construct(
        private PasskeyRepository $passkeys,
        private AuthChallengeRepository $challenges,
        private AccountSecurityRepository $accounts,
        private RecentAuthenticationPolicy $recentAuthentication,
        private AccountSecurityNotifier $notifications,
        private RateLimiterInterface $rateLimiter,
        private AuthRateLimitPolicy $ratePolicy,
        private IdentifierDigestService $digests,
        private SecurityAuditService $audit,
        private ClockInterface $clock,
        private ConnectionInterface $db,
        private WebAuthnPolicy $webAuthnPolicy,
        private CredentialMutationGuard $mutationGuard,
        private AuthenticationFlowService $authFlow,
    ) {}

    /** @return array<string, mixed> */
    public function startRegistration(
        string $accountId,
        string $sessionId,
        ClientContext $context,
    ): array {
        $this->recentAuthentication->require($accountId, $sessionId);

        $challengeBytes = random_bytes(32);
        $challengeBase64Url = self::base64UrlEncode($challengeBytes);
        $secretHashHex = hash('sha256', $challengeBase64Url);

        $userHandleBytes = $this->webAuthnPolicy->makeUserHandle($accountId);
        $userHandleHex = bin2hex($userHandleBytes);

        // Session and client binding are stored in the challenge payload so that
        // completeRegistration() can verify that the ceremony is completed in the
        // same authenticated session and from the same logical client context.
        $challenge = $this->challenges->storeChallenge(new CreateAuthChallengeCommand(
            accountId: $accountId,
            challengeType: 'passkey_registration',
            secretHashHex: $secretHashHex,
            targetNormalized: $accountId,
            payload: [
                'rpId' => $this->webAuthnPolicy->rpId,
                'userHandleHex' => $userHandleHex,
                'sessionId' => $sessionId,
                'clientBinding' => AuthenticationFlowService::clientBinding($context),
            ],
            maxAttempts: 3,
            expiresAt: $this->clock->now()
                ->modify(sprintf('+%d seconds', $this->webAuthnPolicy->challengeTtlSeconds))
                ->format('Y-m-d\TH:i:s\Z'),
        ));

        $this->audit->record(new SecurityAuditCommand(
            action: SecurityAction::PASSKEY_REGISTRATION_STARTED,
            actorAccountId: $accountId,
            resourceType: 'core.auth_challenge',
            resourceId: $challenge->id,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            metadata: ['rpId' => $this->webAuthnPolicy->rpId],
        ));

        $existingPasskeys = $this->passkeys->findActivePasskeysByAccountId($accountId);
        $excludeCredentials = array_map(
            static fn(Passkey $pk): array => [
                'type' => 'public-key',
                'id' => self::base64UrlEncode((string) hex2bin($pk->credentialIdHex)),
                'transports' => $pk->transports,
            ],
            $existingPasskeys,
        );

        return [
            'rp' => [
                'name' => $this->webAuthnPolicy->rpName,
                'id' => $this->webAuthnPolicy->rpId,
            ],
            'user' => [
                'id' => self::base64UrlEncode($userHandleBytes),
                'name' => 'account-' . substr(hash('sha256', $accountId), 0, 16),
                'displayName' => 'Account User',
            ],
            'challenge' => $challengeBase64Url,
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],   // ES256
                ['type' => 'public-key', 'alg' => -257], // RS256
                ['type' => 'public-key', 'alg' => -8],   // EdDSA
            ],
            'authenticatorSelection' => [
                'residentKey' => 'required',
                'requireResidentKey' => true,
                // UV=required: Platform Passkey is a first-factor credential.
                // User presence alone (UP) is insufficient; the authenticator must
                // confirm user verification (biometric/PIN). The server reconstructs
                // options with UV=required; the client cannot downgrade this.
                'userVerification' => 'required',
            ],
            'attestation' => 'none',
            'excludeCredentials' => $excludeCredentials,
            'timeout' => $this->webAuthnPolicy->challengeTtlSeconds * 1000,
        ];
    }

    public function completeRegistration(
        string $accountId,
        string $sessionId,
        string $responseJson,
        ?string $label,
        ClientContext $context,
    ): Passkey {
        // Input size guard — reject oversized payloads before any deserialization.
        if (strlen($responseJson) > self::MAX_RESPONSE_BYTES) {
            throw new InvalidArgumentException('WebAuthn response payload too large.');
        }

        $ipDigest = $this->digests->digest($context->ipAddress ?? 'unknown');
        $accountDigest = $this->digests->digest($accountId);

        $allowed = $this->rateLimiter->consume(
            'passkey:register:ip',
            $ipDigest,
            $this->ratePolicy->passkeyRegisterCompleteLimit,
            $this->ratePolicy->securityWindowSeconds,
        );
        $allowed = $this->rateLimiter->consume(
            'passkey:register:account',
            $accountDigest,
            $this->ratePolicy->passkeyRegisterCompleteLimit,
            $this->ratePolicy->securityWindowSeconds,
        ) && $allowed;

        if (!$allowed) {
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_REGISTRATION_FAILED,
                actorAccountId: $accountId,
                resourceType: 'core.account',
                resourceId: $accountId,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['reason' => 'rate_limited'],
            ));
            throw new PasskeyRateLimitedException('Passkey registration rate limit exceeded.');
        }

        $this->recentAuthentication->require($accountId, $sessionId);

        $responseData = json_decode($responseJson, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($responseData)) {
            throw new InvalidArgumentException('Invalid passkey response.');
        }

        $responseObj = $responseData['response'] ?? null;
        if (!is_array($responseObj) || !isset($responseObj['clientDataJSON'])) {
            throw new InvalidArgumentException('Malformed clientDataJSON.');
        }

        $clientDataRaw = self::base64UrlDecode((string) $responseObj['clientDataJSON']);
        $clientData = json_decode($clientDataRaw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($clientData)) {
            throw new InvalidArgumentException('Invalid client data JSON.');
        }

        $clientType = (string) ($clientData['type'] ?? '');
        if ($clientType !== 'webauthn.create') {
            throw new InvalidArgumentException('Invalid ceremony type.');
        }

        $clientChallenge = (string) ($clientData['challenge'] ?? '');
        $challengeSecretHash = hash('sha256', $clientChallenge);
        $challenge = $this->challenges->findActiveBySecretHashHex($challengeSecretHash);

        if ($challenge === null || $challenge->challengeType !== 'passkey_registration' || $challenge->accountId !== $accountId) {
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_REGISTRATION_FAILED,
                actorAccountId: $accountId,
                resourceType: 'core.account',
                resourceId: $accountId,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['reason' => 'invalid_challenge'],
            ));
            throw new InvalidArgumentException('Invalid or expired registration challenge.');
        }

        // Session binding: ceremony must be completed in the same authenticated session.
        $boundSessionId = (string) ($challenge->payload['sessionId'] ?? '');
        if ($boundSessionId !== $sessionId) {
            $this->challenges->registerFailedAttempt($challenge->id);
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_REGISTRATION_FAILED,
                actorAccountId: $accountId,
                resourceType: 'core.account',
                resourceId: $accountId,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['reason' => 'session_binding_mismatch'],
            ));
            throw new InvalidArgumentException('Registration challenge session mismatch.');
        }

        // Client binding: blocks cross-device/cross-client ceremony replay.
        $boundClient = (string) ($challenge->payload['clientBinding'] ?? '');
        $currentClient = AuthenticationFlowService::clientBinding($context);
        if ($boundClient !== '' && $boundClient !== $currentClient) {
            $this->challenges->registerFailedAttempt($challenge->id);
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_REGISTRATION_FAILED,
                actorAccountId: $accountId,
                resourceType: 'core.account',
                resourceId: $accountId,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['reason' => 'client_binding_mismatch'],
            ));
            throw new InvalidArgumentException('Registration challenge client binding mismatch.');
        }

        $clientOrigin = (string) ($clientData['origin'] ?? '');
        if (!$this->webAuthnPolicy->isValidOrigin($clientOrigin)) {
            $this->challenges->registerFailedAttempt($challenge->id);
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_REGISTRATION_FAILED,
                actorAccountId: $accountId,
                resourceType: 'core.account',
                resourceId: $accountId,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['reason' => 'invalid_origin'],
            ));
            throw new InvalidArgumentException('Invalid client origin.');
        }

        $rpHost = (string) parse_url($clientOrigin, PHP_URL_HOST);
        $userHandleBytes = $this->webAuthnPolicy->makeUserHandle($accountId);
        $rpEntity = new PublicKeyCredentialRpEntity('', $this->webAuthnPolicy->rpId);
        $userEntity = new PublicKeyCredentialUserEntity(
            name: 'account-' . substr(hash('sha256', $accountId), 0, 16),
            id: $userHandleBytes,
            displayName: 'Account User',
        );
        // UV=required: server always reconstructs with required; client cannot downgrade.
        $selection = new AuthenticatorSelectionCriteria(
            authenticatorAttachment: null,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
        );

        $creationOptions = new PublicKeyCredentialCreationOptions(
            rp: $rpEntity,
            user: $userEntity,
            challenge: self::base64UrlDecode($clientChallenge),
            pubKeyCredParams: [
                new PublicKeyCredentialParameters('public-key', -7),
                new PublicKeyCredentialParameters('public-key', -257),
                new PublicKeyCredentialParameters('public-key', -8),
            ],
            authenticatorSelection: $selection,
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: [],
            timeout: max(1, $this->webAuthnPolicy->challengeTtlSeconds * 1000),
        );

        $attestationSupportManager = AttestationStatementSupportManager::create([
            NoneAttestationStatementSupport::create(),
        ]);
        $serializer = (new WebauthnSerializerFactory($attestationSupportManager))->create();

        try {
            /** @var PublicKeyCredential $publicKeyCredential */
            $publicKeyCredential = $serializer->deserialize($responseJson, PublicKeyCredential::class, 'json');
            $attestationResponse = $publicKeyCredential->response;
            if (!$attestationResponse instanceof AuthenticatorAttestationResponse) {
                throw new InvalidArgumentException('Invalid attestation response.');
            }

            $csmFactory = new CeremonyStepManagerFactory();
            $csmFactory->setAllowedOrigins($this->webAuthnPolicy->origins);
            $csmFactory->setAttestationStatementSupportManager($attestationSupportManager);

            $validator = AuthenticatorAttestationResponseValidator::create($csmFactory->creationCeremony());
            $credentialRecord = $validator->check($attestationResponse, $creationOptions, $rpHost);
        } catch (Throwable $e) {
            $this->challenges->registerFailedAttempt($challenge->id);
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_REGISTRATION_FAILED,
                actorAccountId: $accountId,
                resourceType: 'core.account',
                resourceId: $accountId,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['reason' => 'verification_failed'],
            ));
            throw new InvalidArgumentException('WebAuthn registration verification failed.', previous: $e);
        }

        $rawCredentialId = $credentialRecord->publicKeyCredentialId;
        $credentialIdHex = bin2hex($rawCredentialId);
        $publicKeyHex = bin2hex($credentialRecord->credentialPublicKey);
        $aaguid = $credentialRecord->aaguid->toRfc4122();
        if ($aaguid === '00000000-0000-0000-0000-000000000000') {
            $aaguid = null;
        }

        // Transport normalization: use library-validated transports, filter to allowlist.
        // AAGUID and backup flags come from validated authenticator data, not client JSON.
        $rawTransports = $credentialRecord->transports;
        $transportsList = array_values(array_unique(array_filter(
            $rawTransports,
            static fn(string $t): bool => in_array($t, self::ALLOWED_TRANSPORTS, true),
        )));

        $backupEligible = (bool) $credentialRecord->backupEligible;
        $backupState = (bool) $credentialRecord->backupStatus;

        $passkey = $this->db->transaction(function () use (
            $accountId,
            $credentialIdHex,
            $publicKeyHex,
            $credentialRecord,
            $aaguid,
            $transportsList,
            $label,
            $backupEligible,
            $backupState,
            $challenge,
            $context,
        ): Passkey {
            // Atomic compare-and-set gate: consume BEFORE creating the credential.
            // If two concurrent requests reach this point with the same challenge,
            // only the first UPDATE will match (consumed_at IS NULL) and return 1 row.
            // The second receives false and is rejected — preventing double credential creation.
            if (!$this->challenges->consume($challenge->id)) {
                throw new InvalidArgumentException('Registration challenge already consumed or expired.');
            }

            if ($this->passkeys->findByCredentialIdHex($credentialIdHex) !== null) {
                throw new ConflictException('Passkey credential already registered.');
            }

            try {
                $created = $this->passkeys->create(
                    accountId: $accountId,
                    credentialIdHex: $credentialIdHex,
                    publicKeyHex: $publicKeyHex,
                    signCount: $credentialRecord->counter,
                    aaguid: $aaguid,
                    transports: $transportsList,
                    label: $label,
                    backupEligible: $backupEligible,
                    backupState: $backupState,
                );
            } catch (ConflictException) {
                throw new ConflictException('Passkey credential already registered.');
            }

            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_REGISTRATION_COMPLETED,
                actorAccountId: $accountId,
                resourceType: 'core.auth_passkey',
                resourceId: $created->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: [
                    'label' => $label,
                    'backupEligible' => $backupEligible,
                    'backupState' => $backupState,
                ],
            ));

            $this->notifications->notify($accountId, 'auth.passkey.registered', $context->locale->locale->tag);

            return $created;
        });

        return $passkey;
    }

    /** @return array<string, mixed> */
    public function startAuthentication(ClientContext $context): array
    {
        $ipDigest = $this->digests->digest($context->ipAddress ?? 'unknown');
        $allowed = $this->rateLimiter->consume(
            'passkey:auth_start:ip',
            $ipDigest,
            $this->ratePolicy->passkeyAuthStartLimit,
            $this->ratePolicy->securityWindowSeconds,
        );

        if (!$allowed) {
            throw new PasskeyRateLimitedException('Passkey authentication start rate limit exceeded.');
        }

        $challengeBytes = random_bytes(32);
        $challengeBase64Url = self::base64UrlEncode($challengeBytes);
        $secretHashHex = hash('sha256', $challengeBase64Url);

        $this->challenges->storeChallenge(new CreateAuthChallengeCommand(
            accountId: null,
            challengeType: 'passkey_authentication',
            secretHashHex: $secretHashHex,
            targetNormalized: null,
            payload: [
                'rpId' => $this->webAuthnPolicy->rpId,
            ],
            maxAttempts: 3,
            expiresAt: $this->clock->now()
                ->modify(sprintf('+%d seconds', $this->webAuthnPolicy->challengeTtlSeconds))
                ->format('Y-m-d\TH:i:s\Z'),
        ));

        return [
            'challenge' => $challengeBase64Url,
            'rpId' => $this->webAuthnPolicy->rpId,
            // UV=required: the authenticator must confirm user verification.
            // UP (user presence) alone is not sufficient for a Passkey first-factor login.
            'userVerification' => 'required',
            'timeout' => $this->webAuthnPolicy->challengeTtlSeconds * 1000,
            'allowCredentials' => [],
        ];
    }

    public function completeAuthentication(
        string $responseJson,
        ClientContext $context,
    ): AuthenticationFlowResult {
        // Input size guard.
        if (strlen($responseJson) > self::MAX_RESPONSE_BYTES) {
            throw new InvalidCredentialsException();
        }

        $ipDigest = $this->digests->digest($context->ipAddress ?? 'unknown');
        $allowed = $this->rateLimiter->consume(
            'passkey:auth_complete:ip',
            $ipDigest,
            $this->ratePolicy->passkeyAuthCompleteLimit,
            $this->ratePolicy->securityWindowSeconds,
        );

        if (!$allowed) {
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_AUTHENTICATION_FAILED,
                actorAccountId: null,
                resourceType: 'core.auth_passkey',
                resourceId: 'rate_limited',
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['reason' => 'rate_limited'],
            ));
            throw new PasskeyRateLimitedException('Passkey authentication rate limit exceeded.');
        }

        $responseData = json_decode($responseJson, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($responseData)) {
            throw new InvalidCredentialsException();
        }

        $responseObj = $responseData['response'] ?? null;
        if (!is_array($responseObj) || !isset($responseObj['clientDataJSON'])) {
            throw new InvalidCredentialsException();
        }

        $clientDataRaw = self::base64UrlDecode((string) $responseObj['clientDataJSON']);
        $clientData = json_decode($clientDataRaw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($clientData)) {
            throw new InvalidCredentialsException();
        }

        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            throw new InvalidCredentialsException();
        }

        $clientChallenge = (string) ($clientData['challenge'] ?? '');
        $challengeSecretHash = hash('sha256', $clientChallenge);
        $challenge = $this->challenges->findActiveBySecretHashHex($challengeSecretHash);

        if ($challenge === null || $challenge->challengeType !== 'passkey_authentication') {
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_AUTHENTICATION_FAILED,
                actorAccountId: null,
                resourceType: 'core.auth_passkey',
                resourceId: 'unknown',
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['reason' => 'invalid_challenge'],
            ));
            throw new InvalidCredentialsException();
        }

        $clientOrigin = (string) ($clientData['origin'] ?? '');
        if (!$this->webAuthnPolicy->isValidOrigin($clientOrigin)) {
            // Register failed attempt for origin mismatch (challenge found, ceremony failed).
            $this->challenges->registerFailedAttempt($challenge->id);
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_AUTHENTICATION_FAILED,
                actorAccountId: null,
                resourceType: 'core.auth_passkey',
                resourceId: 'unknown',
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['reason' => 'invalid_origin'],
            ));
            throw new InvalidCredentialsException();
        }

        $rawCredentialIdString = (string) ($responseData['rawId'] ?? $responseData['id'] ?? '');
        $rawCredentialIdBytes = self::base64UrlDecode($rawCredentialIdString);
        $credentialIdHex = bin2hex($rawCredentialIdBytes);

        $passkey = $this->passkeys->findByCredentialIdHex($credentialIdHex);
        if ($passkey === null || !$passkey->isActive()) {
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_AUTHENTICATION_FAILED,
                actorAccountId: null,
                resourceType: 'core.auth_passkey',
                resourceId: 'unknown',
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['reason' => 'credential_not_found'],
            ));
            throw new InvalidCredentialsException();
        }

        $account = $this->accounts->findSecurityState($passkey->accountId);
        if ($account === null || !$account->allowsAuthentication($this->clock->now())) {
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_AUTHENTICATION_FAILED,
                actorAccountId: $passkey->accountId,
                resourceType: 'core.account',
                resourceId: $passkey->accountId,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['reason' => 'account_unavailable'],
            ));
            throw new InvalidCredentialsException();
        }

        // Verify userHandle if provided by client/authenticator
        $userHandleProvided = $responseObj['userHandle'] ?? null;
        if (is_string($userHandleProvided) && $userHandleProvided !== '') {
            $userHandleBytes = self::base64UrlDecode($userHandleProvided);
            if (!$this->webAuthnPolicy->verifyUserHandle($userHandleBytes, $passkey->accountId)) {
                $this->challenges->registerFailedAttempt($challenge->id);
                $this->audit->record(new SecurityAuditCommand(
                    action: SecurityAction::PASSKEY_AUTHENTICATION_FAILED,
                    actorAccountId: $passkey->accountId,
                    resourceType: 'core.auth_passkey',
                    resourceId: $passkey->id,
                    requestId: $context->requestId,
                    ipAddress: $context->ipAddress,
                    userAgent: $context->userAgent,
                    metadata: ['reason' => 'user_handle_mismatch'],
                ));
                throw new InvalidCredentialsException();
            }
        }

        $rpHost = (string) parse_url($clientOrigin, PHP_URL_HOST);
        $attestationSupportManager = AttestationStatementSupportManager::create([
            NoneAttestationStatementSupport::create(),
        ]);
        $serializer = (new WebauthnSerializerFactory($attestationSupportManager))->create();

        try {
            /** @var PublicKeyCredential $publicKeyCredential */
            $publicKeyCredential = $serializer->deserialize($responseJson, PublicKeyCredential::class, 'json');
            $assertionResponse = $publicKeyCredential->response;
            if (!$assertionResponse instanceof AuthenticatorAssertionResponse) {
                throw new InvalidArgumentException('Invalid assertion response.');
            }

            $userHandleBytes = $this->webAuthnPolicy->makeUserHandle($passkey->accountId);
            $aaguidUuid = $passkey->aaguid !== null
                ? Uuid::fromString($passkey->aaguid)
                : Uuid::fromString('00000000-0000-0000-0000-000000000000');

            $credentialRecord = new CredentialRecord(
                publicKeyCredentialId: (string) hex2bin($passkey->credentialIdHex),
                type: 'public-key',
                transports: $passkey->transports,
                attestationType: 'none',
                trustPath: new EmptyTrustPath(),
                aaguid: $aaguidUuid,
                credentialPublicKey: (string) hex2bin($passkey->publicKeyHex),
                userHandle: $userHandleBytes,
                counter: $passkey->signCount,
                otherUI: null,
                backupEligible: $passkey->backupEligible,
                backupStatus: $passkey->backupState,
            );

            // UV=required: server always reconstructs with required.
            // The WebAuthn library validates the UV bit in authenticatorData flags.
            $requestOptions = new PublicKeyCredentialRequestOptions(
                challenge: self::base64UrlDecode($clientChallenge),
                rpId: $this->webAuthnPolicy->rpId,
                allowCredentials: [
                    new PublicKeyCredentialDescriptor('public-key', (string) hex2bin($passkey->credentialIdHex)),
                ],
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                timeout: max(1, $this->webAuthnPolicy->challengeTtlSeconds * 1000),
            );

            $csmFactory = new CeremonyStepManagerFactory();
            $csmFactory->setAllowedOrigins($this->webAuthnPolicy->origins);

            // WebAuthn Level 3: Synced/backup-eligible passkeys do not use monotonic counters
            // (counter is always 0). Hardware keys must have a strictly increasing counter.
            // The server never logs the raw counter anomaly as a secret value.
            $csmFactory->setCounterChecker(new class implements CounterChecker {
                public function check(CredentialRecord $credentialRecord, int $currentCounter): void
                {
                    if ($credentialRecord->backupEligible) {
                        return;
                    }

                    if ($currentCounter > 0 && $credentialRecord->counter > 0 && $currentCounter <= $credentialRecord->counter) {
                        throw CounterException::create($currentCounter, $credentialRecord->counter, 'Sign counter anomaly detected.');
                    }
                }
            });

            $assertionValidator = AuthenticatorAssertionResponseValidator::create($csmFactory->requestCeremony());
            $assertionValidator->check(
                $credentialRecord,
                $assertionResponse,
                $requestOptions,
                $rpHost,
                $userHandleBytes,
            );
        } catch (CounterException $e) {
            $this->challenges->registerFailedAttempt($challenge->id);
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_COUNTER_ANOMALY,
                actorAccountId: $passkey->accountId,
                resourceType: 'core.auth_passkey',
                resourceId: $passkey->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: [
                    // Only non-secret counter metadata is audited — not raw credential material.
                    'storedCount' => $e->authenticatorCounter,
                    'newCount' => $e->currentCounter,
                ],
            ));
            throw new InvalidCredentialsException();
        } catch (Throwable) {
            $this->challenges->registerFailedAttempt($challenge->id);
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_AUTHENTICATION_FAILED,
                actorAccountId: $passkey->accountId,
                resourceType: 'core.auth_passkey',
                resourceId: $passkey->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
                metadata: ['reason' => 'assertion_failed'],
            ));
            throw new InvalidCredentialsException();
        }

        // Extract post-verification state from validated authenticator data.
        $newCounter = $assertionResponse->authenticatorData->signCount;
        $authData = $assertionResponse->authenticatorData;
        $newBackupState = $authData->isBackedUp();

        // Atomic gate + usage record in a single transaction.
        // Challenge consume is the CAS gate: only the first of N concurrent requests
        // for the same challenge will receive true. All subsequent receive false and
        // are rejected before any session/MFA challenge is issued.
        $this->db->transaction(function () use (
            $passkey,
            $newCounter,
            $newBackupState,
            $challenge,
            $context,
        ): void {
            // Atomic challenge consumption — this is the race gate.
            if (!$this->challenges->consume($challenge->id)) {
                throw new InvalidCredentialsException();
            }

            // Counter compare-and-set for hardware passkeys.
            // For synced/backup-eligible passkeys the counter stays 0; use unconditional update.
            if ($passkey->backupEligible) {
                $this->passkeys->recordUsage($passkey->id, $newCounter, $newBackupState);
            } else {
                $updated = $this->passkeys->recordUsageCompareSet(
                    $passkey->id,
                    $passkey->signCount,
                    $newCounter,
                    $newBackupState,
                );
                if (!$updated) {
                    // Another concurrent assertion already updated the counter.
                    // Reject to prevent a stale request from issuing a duplicate session.
                    throw new InvalidCredentialsException();
                }
            }

            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_AUTHENTICATION_SUCCEEDED,
                actorAccountId: $passkey->accountId,
                resourceType: 'core.auth_passkey',
                resourceId: $passkey->id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
            ));
        });

        // Delegate to unified AuthenticationFlowService (issues session or MFA challenge).
        return $this->authFlow->completeFirstFactor(
            $passkey->accountId,
            AuthenticationMethod::PASSKEY,
            $context,
        );
    }

    /** @return list<array<string, mixed>> */
    public function listPasskeys(string $accountId): array
    {
        $passkeys = $this->passkeys->findActivePasskeysByAccountId($accountId);

        return array_map(
            static fn(Passkey $pk): array => [
                'id' => $pk->id,
                'label' => $pk->label,
                'createdAt' => $pk->createdAt,
                'lastUsedAt' => $pk->lastUsedAt,
                'transports' => $pk->transports,
                'backupEligible' => $pk->backupEligible,
                'backupState' => $pk->backupState,
            ],
            $passkeys,
        );
    }

    public function rename(
        string $id,
        string $accountId,
        ?string $label,
        ClientContext $context,
    ): Passkey {
        $passkey = $this->passkeys->findById($id);
        if ($passkey === null || $passkey->accountId !== $accountId || !$passkey->isActive()) {
            throw new NotFoundException('Passkey not found.');
        }

        $updated = $this->passkeys->updateLabel($id, $accountId, $label);
        if ($updated === null) {
            throw new NotFoundException('Passkey not found.');
        }

        $this->audit->record(new SecurityAuditCommand(
            action: SecurityAction::PASSKEY_RENAMED,
            actorAccountId: $accountId,
            resourceType: 'core.auth_passkey',
            resourceId: $id,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            metadata: ['label' => $label],
        ));

        return $updated;
    }

    public function revoke(
        string $id,
        string $accountId,
        string $sessionId,
        ClientContext $context,
    ): void {
        $this->recentAuthentication->require($accountId, $sessionId);

        $passkey = $this->passkeys->findById($id);
        if ($passkey === null || $passkey->accountId !== $accountId || !$passkey->isActive()) {
            throw new NotFoundException('Passkey not found.');
        }

        // Last-method invariant enforced inside the transaction with a row lock.
        // This eliminates the TOCTOU race between concurrent revoke and identity unlink.
        $this->db->transaction(function () use ($id, $accountId, $context): void {
            // Acquires SELECT FOR UPDATE on the account row; re-counts methods post-lock.
            $this->mutationGuard->assertCanRemoveOneMethod($accountId);

            if (!$this->passkeys->revokePasskey($id, $accountId)) {
                throw new NotFoundException('Passkey not found.');
            }

            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSKEY_REVOKED,
                actorAccountId: $accountId,
                resourceType: 'core.auth_passkey',
                resourceId: $id,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
            ));

            $this->notifications->notify($accountId, 'auth.passkey.revoked', $context->locale->locale->tag);
        });
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Malformed base64url data.');
        }
        return $decoded;
    }
}
