<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Account\Application\AccountSecurityRepository;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\SecurityAction;
use App\Core\Security\Application\SecurityAuditCommand;
use App\Core\Security\Application\SecurityAuditService;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class MfaRecoveryFinalizer
{
    public function __construct(
        private TotpFactorRepository $factors,
        private RecoveryCodeRepository $recoveryCodes,
        private AccountSecurityRepository $accounts,
        private SessionRepository $sessions,
        private AuthChallengeRepository $challenges,
        private SecurityAuditService $audit,
        private AccountSecurityNotifier $notifications,
        private ConnectionInterface $db,
    ) {}

    public function complete(AuthChallenge $proof, ClientContext $context, string $method): void
    {
        $this->db->transaction(fn() => $this->completeWithinTransaction($proof, $context, $method));
    }

    public function completeWithinTransaction(AuthChallenge $proof, ClientContext $context, string $method): void
    {
        if ($proof->accountId === null || !$this->challenges->consume($proof->id)) {
            throw new MfaVerificationException();
        }
        $this->factors->revokeAllFactors($proof->accountId);
        $this->recoveryCodes->revokeAllCodes($proof->accountId);
        $this->accounts->rotateSecurityStamp($proof->accountId)
            ?? throw new RuntimeException('Account security stamp could not be rotated.');
        $this->sessions->revokeAll($proof->accountId, 'mfa_recovery');
        $this->challenges->cancelAllForAccount($proof->accountId, $proof->id);
        $this->audit->record(new SecurityAuditCommand(
            action: SecurityAction::MFA_RECOVERY_COMPLETED,
            actorAccountId: $proof->accountId,
            resourceType: 'core.account',
            resourceId: $proof->accountId,
            requestId: $context->requestId,
            ipAddress: $context->ipAddress,
            userAgent: $context->userAgent,
            metadata: ['severity' => 'high', 'proofMethod' => $method],
        ));
        $this->notifications->notify(
            $proof->accountId,
            'auth.mfa.recovery_completed',
            $context->locale->locale->tag,
        );
    }
}
