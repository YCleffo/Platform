<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Time\Application\ClockInterface;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Operator console only: reset the credential and revoke every old session/challenge, preserving MFA. */
final readonly class RotateAccountPassword
{
    public function __construct(private ConnectionInterface $db, private AuthChallengeRepository $challenges,
        private OpaqueTokenService $tokens, private CompletePasswordReset $reset,
        private PasswordCredentialRepository $passwords, private PasswordHasherInterface $hasher,
        private ClockInterface $clock) {}

    public function execute(string $accountId, #[\SensitiveParameter] string $password, ClientContext $context): void
    {
        if (mb_strlen($password) < 12 || mb_strlen($password) > 1024) throw new DomainException('Invalid password length.');
        $this->db->transaction(function () use ($accountId, $password, $context): void {
            $account = $this->db->createCommand("SELECT id FROM core.accounts WHERE id=CAST(:id AS uuid) AND account_type='human' AND status='active' FOR UPDATE", [':id' => $accountId])->queryScalar();
            $credential = $this->passwords->findByAccountId($accountId);
            $email = $this->db->createCommand("SELECT normalized_value FROM core.auth_identifiers WHERE account_id=CAST(:id AS uuid) AND identifier_type='email' AND can_recover AND verified_at IS NOT NULL ORDER BY is_primary DESC, id LIMIT 1", [':id' => $accountId])->queryScalar();
            if ($account === false || $account === null || $credential === null || !is_string($email)
                || $this->hasher->verify($password, $credential->passwordHash)) {
                throw new DomainException('Account is not eligible for password rotation.');
            }
            $token = $this->tokens->issue();
            $this->challenges->storeChallenge(new CreateAuthChallengeCommand(
                accountId: $accountId, challengeType: 'password_reset', secretHashHex: $token->hashHex,
                targetNormalized: $email, payload: ['purpose' => 'password_reset'], maxAttempts: 1,
                expiresAt: $this->clock->now()->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z'),
            ));
            if (!$this->reset->execute($token->raw, $password, $context)) throw new DomainException('Password rotation was rejected.');
            $this->challenges->cancelAllForAccount($accountId);
        });
    }
}
