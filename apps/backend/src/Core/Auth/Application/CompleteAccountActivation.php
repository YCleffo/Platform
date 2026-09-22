<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class CompleteAccountActivation
{
    public function __construct(
        private CredentialLifecycleRepository $credentials,
        private OpaqueTokenService $tokens,
        private PasswordHasherInterface $passwords,
        private SecurityAuditService $audit,
        private ConnectionInterface $db,
    ) {}

    public function execute(string $rawToken, string $password, ClientContext $context): bool
    {
        if (!$this->tokens->hasValidFormat($rawToken) || mb_strlen($password) < 12) {
            return false;
        }
        $hash = $this->passwords->hash($password);
        $algorithm = password_get_info($hash)['algoName'] ?? 'argon2id';
        return $this->db->transaction(function () use ($rawToken, $hash, $algorithm, $context): bool {
            $accountId = $this->credentials->completeActivation(
                $this->tokens->hashHex($rawToken),
                $hash,
                (string) $algorithm,
            );
            if ($accountId === null) {
                return false;
            }
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::ACCOUNT_ACTIVATED,
                actorAccountId: null,
                resourceType: 'core.account',
                resourceId: $accountId,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
            ));

            return true;
        });
    }
}
