<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Client\Domain\ClientContext;
use App\Core\Communication\Application\EmailService;
use App\Core\Communication\Application\QueueEmailCommand;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class CompletePasswordReset
{
    public function __construct(
        private CredentialLifecycleRepository $credentials,
        private OpaqueTokenService $tokens,
        private PasswordHasherInterface $passwords,
        private SecurityAuditService $audit,
        private EmailService $email,
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
            $target = $this->credentials->completePasswordReset(
                $this->tokens->hashHex($rawToken),
                $hash,
                (string) $algorithm,
            );
            if ($target === null) {
                return false;
            }
            $this->audit->record(new SecurityAuditCommand(
                action: SecurityAction::PASSWORD_RESET,
                actorAccountId: null,
                resourceType: 'core.account',
                resourceId: $target->accountId,
                requestId: $context->requestId,
                ipAddress: $context->ipAddress,
                userAgent: $context->userAgent,
            ));
            $this->email->queue(new QueueEmailCommand(
                producerModule: 'core.auth',
                recipientAddress: $target->email,
                recipientName: $target->displayName,
                locale: $context->locale->locale->tag,
                templateCode: 'auth.password.changed',
                variables: ['displayName' => $target->displayName],
                aggregateType: 'core.account',
                aggregateId: $target->accountId,
            ));

            return true;
        });
    }
}
