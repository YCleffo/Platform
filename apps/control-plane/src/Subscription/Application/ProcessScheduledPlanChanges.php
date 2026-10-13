<?php

declare(strict_types=1);

namespace ControlPlane\Subscription\Application;

use ControlPlane\Billing\Application\PlanChangeInvoiceIssuer;
use ControlPlane\Billing\Domain\Money;
use ControlPlane\Payment\Application\PaymentCreator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/** Resumable materialization of a downgrade at its original period boundary. */
final readonly class ProcessScheduledPlanChanges
{
    public function __construct(private Connection $connection, private PlanChangeInvoiceIssuer $invoices,
        private PaymentCreator $payments, private ApplySubscriptionPlanChange $application) {}

    public function runBatch(\DateTimeImmutable $instant, int $limit, string $correlationId): int
    {
        if ($limit < 1 || $limit > 1000 || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid scheduled plan change batch.');
        }
        $instant = $instant->setTimezone(new \DateTimeZone('UTC'));
        $due = $this->connection->fetchFirstColumn(<<<'SQL'
SELECT id FROM commercial.subscription_plan_changes
WHERE change_kind = 'downgrade' AND status = 'scheduled' AND period_end <= ?::timestamptz
ORDER BY period_end, id LIMIT ?
SQL, [$instant->format('Y-m-d H:i:s.uP'), $limit], [ParameterType::STRING, ParameterType::INTEGER]);
        $processed = 0;
        foreach ($due as $id) {
            if (!is_string($id)) {
                continue;
            }
            $processed += $this->connection->transactional(function (Connection $db) use ($id, $instant, $correlationId): int {
                $change = $db->fetchAssociative('SELECT * FROM commercial.subscription_plan_changes WHERE id = ? FOR UPDATE', [$id]);
                if ($change === false || $change['status'] !== 'scheduled' || $change['change_kind'] !== 'downgrade'
                    || !is_string($change['source_subscription_id']) || !is_string($change['target_subscription_id'])
                    || !is_string($change['commercial_account_id']) || !is_string($change['period_end'])) {
                    return 0;
                }
                if ($instant < new \DateTimeImmutable($change['period_end'])) {
                    return 0;
                }
                $source = $db->fetchAssociative('SELECT status, current_period_end FROM commercial.subscriptions WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$change['source_subscription_id'], $change['commercial_account_id']]);
                $target = $db->fetchAssociative('SELECT status, billing_profile_id FROM commercial.subscriptions WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$change['target_subscription_id'], $change['commercial_account_id']]);
                if ($source === false || $target === false || $target['status'] !== 'draft') {
                    throw new \LogicException('Scheduled replacement is unavailable.');
                }
                if (!in_array($source['status'], ['active', 'expired'], true)
                    || $source['current_period_end'] !== $change['period_end']) {
                    $db->executeStatement("UPDATE commercial.subscription_plan_changes SET status = 'cancelled', updated_at = now() WHERE id = ?", [$id]);
                    $db->executeStatement("UPDATE commercial.subscriptions SET status = 'cancelled', updated_at = now() WHERE id = ?", [$change['target_subscription_id']]);
                    return 1;
                }
                $amount = Money::nonNegativeBigInt($change['amount_due_minor']);
                if ($amount === 0) {
                    $this->application->applyInTransaction($db, $id, $instant, $correlationId);
                    return 1;
                }
                $newUnit = $db->fetchOne('SELECT amount_minor FROM commercial.plan_prices WHERE id = ?', [$change['new_plan_price_id']]);
                if ($newUnit === false || !is_string($target['billing_profile_id'])
                    || Money::multiply(Money::nonNegativeBigInt($newUnit), (int) $change['new_quantity']) !== $amount) {
                    throw new \LogicException('Scheduled plan price no longer matches its immutable calculation.');
                }
                $invoiceId = $this->invoices->issueInTransaction($db, $change['commercial_account_id'],
                    $change['target_subscription_id'], $target['billing_profile_id'], (int) $change['new_quantity'],
                    Money::nonNegativeBigInt($newUnit), $amount, 'Scheduled subscription downgrade', $correlationId);
                $db->executeStatement("UPDATE commercial.subscription_plan_changes SET status = 'payment_required', invoice_id = ?, updated_at = now() WHERE id = ?", [$invoiceId, $id]);
                $invoice = $db->fetchAssociative('SELECT id, subscription_id, commercial_account_id, status, currency, total_minor FROM commercial.invoices WHERE id = ? FOR UPDATE', [$invoiceId]);
                if ($invoice === false) {
                    throw new \LogicException('Scheduled downgrade invoice is unavailable.');
                }
                $this->payments->createInTransaction($db, $invoice, $correlationId, 'system');
                return 1;
            });
        }
        return $processed;
    }
}
