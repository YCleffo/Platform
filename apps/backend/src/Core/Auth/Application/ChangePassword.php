<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ChangePassword
{
    public function __construct(
        private ConnectionInterface $db,
        private PasswordCredentialRepository $credentials,
        private PasswordHasherInterface $hasher,
        private RecentAuthenticationPolicy $recentAuthentication,
        private SessionRepository $sessions,
        private AuthChallengeRepository $challenges,
        private OpaqueTokenService $tokens,
        private AuthenticationFlowService $authenticationFlow,
        private SecurityAuditService $audit,
        private AccountSecurityNotifier $notifier,
    ) {}

    public function authenticated(
        string $accountId,
        string $sessionId,
        string $currentPassword,
        string $newPassword,
        ClientContext $context,
    ): void {
        self::validateNewPassword($newPassword);
        $this->db->transaction(function () use ($accountId, $sessionId, $currentPassword, $newPassword, $context): void {
            $this->recentAuthentication->require($accountId, $sessionId);
            $stamp = $this->replaceCredential($accountId, $currentPassword, $newPassword);
            $this->sessions->secureCurrentAndRevokeOthers($accountId, $sessionId, $stamp, 'password changed');
            if ($this->sessions->findForAccount($sessionId, $accountId)?->securityStamp !== $stamp) {
                throw new \LogicException('Current session could not be retained.');
            }
            $this->record($accountId, $context);
        });
    }

    public function required(
        string $challengeToken,
        string $currentPassword,
        string $newPassword,
        ClientContext $context,
    ): AuthenticationFlowResult {
        self::validateNewPassword($newPassword);
        if (!$this->tokens->hasValidFormat($challengeToken)) {
            throw new PasswordChangeRejectedException();
        }
        $hash = $this->tokens->hashHex($challengeToken);
        $challenge = $this->challenges->findActiveBySecretHashHex($hash);
        if ($challenge === null || $challenge->challengeType !== 'password_change_required'
            || $challenge->accountId === null
            || ($challenge->payload['purpose'] ?? null) !== 'password_change'
            || ($challenge->payload['clientBinding'] ?? null) !== AuthenticationFlowService::clientBinding($context)) {
            throw new PasswordChangeRejectedException();
        }
        $method = $challenge->payload['firstFactor'] ?? null;
        if (!is_string($method)) {
            throw new PasswordChangeRejectedException();
        }
        try {
            $this->db->transaction(function () use ($hash, $challenge, $currentPassword, $newPassword, $context): void {
                $this->lockAccount($challenge->accountId);
                $current = $this->challenges->findActiveBySecretHashHex($hash);
                if ($current === null || $current->id !== $challenge->id) {
                    throw new PasswordChangeRejectedException();
                }
                $this->replaceCredential($challenge->accountId, $currentPassword, $newPassword);
                if (!$this->challenges->consume($challenge->id)) {
                    throw new PasswordChangeRejectedException();
                }
                $this->sessions->revokeAll($challenge->accountId, 'password changed');
                $this->record($challenge->accountId, $context);
            });
        } catch (PasswordChangeRejectedException $exception) {
            $this->challenges->registerFailedAttempt($challenge->id);
            throw $exception;
        }
        return $this->authenticationFlow->completeFirstFactor($challenge->accountId, $method, $context);
    }

    private static function validateNewPassword(string $newPassword): void
    {
        if (mb_strlen($newPassword) < 12 || mb_strlen($newPassword) > 1024) {
            throw new PasswordChangeRejectedException();
        }
    }

    private function replaceCredential(string $accountId, string $currentPassword, string $newPassword): string
    {
        $this->lockAccount($accountId);
        $credential = $this->credentials->findByAccountId($accountId);
        if ($credential === null || !$this->hasher->verify($currentPassword, $credential->passwordHash)
            || $this->hasher->verify($newPassword, $credential->passwordHash)) {
            throw new PasswordChangeRejectedException();
        }
        $hash = $this->hasher->hash($newPassword);
        if ((password_get_info($hash)['algoName'] ?? '') !== 'argon2id') {
            throw new \LogicException('Password hasher must produce Argon2id.');
        }
        $this->credentials->saveHash($accountId, $hash, 'argon2id', false);
        $stamp = $this->db->createCommand(<<<'SQL'
            UPDATE core.accounts SET security_stamp = uuidv4(), updated_at = now()
            WHERE id = CAST(:id AS uuid) RETURNING security_stamp::text
            SQL, [':id' => $accountId])->queryScalar();
        if (!is_string($stamp)) {
            throw new \LogicException('Account security stamp could not be rotated.');
        }
        return $stamp;
    }

    private function lockAccount(string $accountId): void
    {
        $account = $this->db->createCommand('SELECT id FROM core.accounts WHERE id = CAST(:id AS uuid) FOR UPDATE', [':id' => $accountId])->queryScalar();
        if ($account === false || $account === null) {
            throw new PasswordChangeRejectedException();
        }
    }

    private function record(string $accountId, ClientContext $context): void
    {
        $this->audit->record(new SecurityAuditCommand(
            action: SecurityAction::PASSWORD_CHANGED,
            actorAccountId: $accountId,
            resourceType: 'core.account',
            resourceId: $accountId,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
        ));
        $this->notifier->notify($accountId, 'auth.password.changed', $context->locale->locale->tag);
    }
}
