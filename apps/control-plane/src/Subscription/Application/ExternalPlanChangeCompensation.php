<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/** Two independent financial operators attest an already executed external full refund. */
final readonly class ExternalPlanChangeCompensation
{
    public function __construct(private Connection $connection) {}

    /** @return array<string, mixed> */
    public function propose(string $actorId, string $changeId, string $transactionReference,
        string $documentReference, string $documentSha256, string $correlationId): array
    {
        $this->validateIds($actorId, $changeId, $correlationId);
        $transactionReference = trim($transactionReference);
        if ($transactionReference === '' || strlen($transactionReference) > 200
            || preg_match('/[[:cntrl:]]/', $transactionReference) === 1
            || preg_match('/\Aurn:[^\s]{1,496}\z/D', $documentReference) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $documentSha256) !== 1) {
            throw new \InvalidArgumentException('Invalid external refund evidence.');
        }
        return $this->connection->transactional(function (Connection $db) use ($actorId, $changeId,
            $transactionReference, $documentReference, $documentSha256, $correlationId): array {
            $this->authorize($db, $actorId);
            $change = $this->lockChange($db, $changeId);
            $existing = $db->fetchAssociative("SELECT * FROM commercial.external_compensation_attestations WHERE plan_change_id=? AND status IN ('proposed','approved') FOR UPDATE", [$changeId]);
            if ($existing !== false) {
                if ($existing['proposed_by'] === $actorId
                    && $existing['external_transaction_reference'] === $transactionReference
                    && $existing['document_reference'] === $documentReference
                    && $existing['document_sha256'] === $documentSha256) {
                    return $this->result($existing);
                }
                throw new \DomainException('An external compensation attestation is already open.');
            }
            if ($change['status'] !== 'paid_unapplied' || $change['compensation_status'] !== 'recovery_required') {
                throw new \DomainException('Plan change does not require external compensation.');
            }
            $payment = $this->settledPaymentWithoutProviderRefund($db, $change);
            $row = $db->fetchAssociative(<<<'SQL'
INSERT INTO commercial.external_compensation_attestations
 (plan_change_id, payment_id, amount_minor, currency, external_transaction_reference,
  document_reference, document_sha256, proposed_by)
VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING *
SQL, [$changeId, $payment['id'], $payment['amount_minor'], $payment['currency'],
                $transactionReference, $documentReference, $documentSha256, $actorId]);
            if ($row === false) throw new \LogicException('External compensation attestation was not persisted.');
            $this->audit($db, $actorId, (string) $change['commercial_account_id'],
                'subscription.external_compensation_proposed', $correlationId, (string) $row['id']);
            return $this->result($row);
        });
    }

    /** @return array<string, mixed> */
    public function review(string $actorId, string $attestationId, string $transactionReference,
        string $documentReference, string $documentSha256, bool $approve, ?string $rejectionReason,
        string $correlationId): array
    {
        $this->validateIds($actorId, $attestationId, $correlationId);
        if ($approve && $rejectionReason !== null) throw new \InvalidArgumentException('Approval cannot have a rejection reason.');
        if (!$approve && ($rejectionReason === null || trim($rejectionReason) === '' || strlen($rejectionReason) > 500)) {
            throw new \InvalidArgumentException('Rejection reason is required.');
        }
        return $this->connection->transactional(function (Connection $db) use ($actorId, $attestationId,
            $transactionReference, $documentReference, $documentSha256, $approve, $rejectionReason, $correlationId): array {
            $this->authorize($db, $actorId);
            $changeId = $db->fetchOne('SELECT plan_change_id FROM commercial.external_compensation_attestations WHERE id=?', [$attestationId]);
            if (!is_string($changeId)) throw new \DomainException('External compensation attestation was not found.');
            $change = $this->lockChange($db, $changeId);
            $row = $db->fetchAssociative('SELECT * FROM commercial.external_compensation_attestations WHERE id=? FOR UPDATE', [$attestationId]);
            if ($row === false || $row['plan_change_id'] !== $changeId) throw new \DomainException('External compensation attestation was not found.');
            if ($row['proposed_by'] === $actorId) throw new AccessDeniedException('A second financial operator is required.');
            if ($row['external_transaction_reference'] !== $transactionReference
                || $row['document_reference'] !== $documentReference
                || $row['document_sha256'] !== $documentSha256) {
                throw new \DomainException('Reviewed evidence does not match the immutable proposal.');
            }
            if ($row['status'] !== 'proposed') {
                if ($row['reviewed_by'] === $actorId && $row['status'] === ($approve ? 'approved' : 'rejected')
                    && ($approve || $row['rejection_reason'] === trim((string) $rejectionReason))) return $this->result($row);
                throw new \DomainException('External compensation attestation was already reviewed.');
            }
            if ($change['status'] !== 'paid_unapplied' || $change['compensation_status'] !== 'recovery_required'
                || $row['plan_change_id'] !== $changeId) {
                throw new \DomainException('Plan change is no longer awaiting external compensation.');
            }
            $payment = $this->settledPaymentWithoutProviderRefund($db, $change);
            if ($row['payment_id'] !== $payment['id'] || (int) $row['amount_minor'] !== (int) $payment['amount_minor']
                || $row['currency'] !== $payment['currency']) {
                throw new \DomainException('External compensation amount no longer matches the settled payment.');
            }
            $status = $approve ? 'approved' : 'rejected';
            $reviewed = $db->fetchAssociative("UPDATE commercial.external_compensation_attestations SET status=?, reviewed_by=?, reviewed_at=now(), rejection_reason=? WHERE id=? RETURNING *",
                [$status, $actorId, $approve ? null : trim((string) $rejectionReason), $attestationId]);
            if ($reviewed === false) throw new \LogicException('External compensation review was not persisted.');
            if ($approve) {
                $db->executeStatement("UPDATE commercial.subscriptions SET status='cancelled', updated_at=now() WHERE id=? AND status='draft'", [$change['target_subscription_id']]);
                $db->executeStatement("UPDATE commercial.subscription_plan_changes SET status='refunded', compensation_status='succeeded', compensation_reason=NULL, updated_at=now() WHERE id=?", [$changeId]);
            }
            $this->audit($db, $actorId, (string) $change['commercial_account_id'],
                $approve ? 'subscription.external_compensation_approved' : 'subscription.external_compensation_rejected',
                $correlationId, $attestationId);
            return $this->result($reviewed);
        });
    }

    /** @return array<string, mixed> */
    public function current(string $actorId, string $changeId): array
    {
        if (!Uuid::isValid($actorId) || !Uuid::isValid($changeId)) throw new \InvalidArgumentException('Invalid identifier.');
        $this->authorize($this->connection, $actorId);
        $row = $this->connection->fetchAssociative("SELECT * FROM commercial.external_compensation_attestations WHERE plan_change_id=? AND status IN ('proposed','approved')", [$changeId]);
        if ($row === false) throw new AccessDeniedException('External compensation attestation was not found.');
        return $this->result($row);
    }

    private function validateIds(string $actorId, string $resourceId, string $correlationId): void
    {
        if (!Uuid::isValid($actorId) || !Uuid::isValid($resourceId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid identifier.');
        }
    }

    private function authorize(Connection $db, string $actorId): void
    {
        if ($db->fetchOne(<<<'SQL'
SELECT 1 FROM commercial.financial_operators o JOIN commercial.control_users u ON u.id=o.control_user_id
WHERE o.control_user_id=? AND o.revoked_at IS NULL AND u.status='active' FOR SHARE OF o, u
SQL, [$actorId]) === false) throw new AccessDeniedException('Active financial operator authorization required.');
    }

    /** @return array<string, mixed> */
    private function lockChange(Connection $db, string $changeId): array
    {
        $sourceId = $db->fetchOne('SELECT source_subscription_id FROM commercial.subscription_plan_changes WHERE id=?', [$changeId]);
        if (!is_string($sourceId)) throw new \DomainException('Plan change was not found.');
        $db->executeQuery('SELECT id FROM commercial.subscriptions WHERE id=? FOR UPDATE', [$sourceId]);
        $change = $db->fetchAssociative('SELECT id, status, compensation_status, commercial_account_id, invoice_id, target_subscription_id FROM commercial.subscription_plan_changes WHERE id=? FOR UPDATE', [$changeId]);
        if ($change === false) throw new \DomainException('Plan change was not found.');
        return $change;
    }

    /**
     * @param array<string, mixed> $change
     * @return array<string, mixed>
     */
    private function settledPaymentWithoutProviderRefund(Connection $db, array $change): array
    {
        $payments = $db->fetchAllAssociative("SELECT id, amount_minor, currency FROM commercial.payments WHERE invoice_id=? AND status='succeeded' FOR UPDATE", [$change['invoice_id']]);
        if (count($payments) !== 1 || (int) $payments[0]['amount_minor'] <= 0) {
            throw new \DomainException('Exactly one positive settled payment is required.');
        }
        $payment = $payments[0];
        if ($db->fetchOne("SELECT 1 FROM commercial.refunds WHERE payment_id=? AND status IN ('pending','succeeded') LIMIT 1", [$payment['id']]) !== false) {
            throw new \DomainException('Provider refund is pending or already succeeded.');
        }
        return $payment;
    }

    private function audit(Connection $db, string $actorId, string $accountId,
        string $action, string $correlationId, string $attestationId): void
    {
        $db->executeStatement(<<<'SQL'
INSERT INTO commercial.audit_events
 (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome)
VALUES ('control_user', ?, ?, ?, 'external_compensation_attestation', ?, ?, 'success')
SQL, [$actorId, $accountId, $action, $attestationId, $correlationId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function result(array $row): array
    {
        return ['id' => $row['id'], 'plan_change_id' => $row['plan_change_id'],
            'payment_id' => $row['payment_id'], 'amount_minor' => (int) $row['amount_minor'],
            'currency' => $row['currency'], 'external_transaction_reference' => $row['external_transaction_reference'],
            'document_reference' => $row['document_reference'], 'document_sha256' => $row['document_sha256'],
            'proposed_by' => $row['proposed_by'], 'status' => $row['status'],
            'reviewed_by' => $row['reviewed_by'], 'rejection_reason' => $row['rejection_reason']];
    }
}
