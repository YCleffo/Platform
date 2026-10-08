<?php

declare(strict_types=1);

namespace ControlPlane\Billing\Application;

use ControlPlane\Billing\Domain\Money;
use ControlPlane\Identity\Domain\EmailPolicy;
use ControlPlane\Payment\Application\BeginPaymentAttempt;
use ControlPlane\Payment\Application\BindPaymentAttemptProviderReference;
use ControlPlane\Payment\Application\CompletePaymentAttempt;
use ControlPlane\Payment\Application\ProcessAuthoritativeProviderPayment;
use ControlPlane\Payment\Application\PaymentCreator;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\PaymentProviderGateway;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderPaymentRequest;
use ControlPlane\Payment\Provider\ProviderPaymentFailure;
use ControlPlane\Payment\Provider\YooKassa\YooKassaFiscalPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/** Starts (but never locally settles) one saved-card collection for a renewal invoice. */
final readonly class CollectRenewalInvoice
{
    public function __construct(
        private Connection $connection,
        private BeginPaymentAttempt $attempts,
        private BindPaymentAttemptProviderReference $binder,
        private CompletePaymentAttempt $completer,
        private ProcessAuthoritativeProviderPayment $processor,
        private PaymentProviderGateway $gateway,
        private PaymentCreator $paymentCreator,
        private RenewalCollectionLifecycle $lifecycle,
    ) {
    }

    /** @return array{state:string,invoice_id:string,payment_id?:string,attempt_id?:string} */
    public function collect(string $invoiceId, string $correlationId, ?\DateTimeImmutable $instant = null): array
    {
        if (!Uuid::isValid($invoiceId) || !Uuid::isValid($correlationId)) throw new \InvalidArgumentException('Invalid renewal collection identifier.');
        $instant ??= new \DateTimeImmutable('now');
        $prepared = $this->prepare($invoiceId, $correlationId);
        if ($prepared['state'] !== 'ready') return $prepared;
        if (!isset($prepared['payment_id'], $prepared['amount_minor'], $prepared['currency'], $prepared['provider_method_reference'], $prepared['account'])) {
            throw new \LogicException('Renewal collection preparation is incomplete.');
        }

        // The request key is generated once when the attempt is persisted.  Reusing a
        // pending attempt therefore reuses its key, while a terminal attempt creates a
        // different durable key for the next charge.
        $attempt = $this->attempts->beginOrReuse($prepared['payment_id'], $prepared['account']->id, 'bank_card', $correlationId);
        $current = $this->connection->fetchAssociative('SELECT provider_payment_id FROM commercial.payment_attempts WHERE id = ?', [$attempt['attempt_id']]);
        if ($current === false) throw new \LogicException('Payment attempt was not found.');
        $request = new ProviderPaymentRequest($prepared['amount_minor'], $prepared['currency'], $attempt['provider_request_key'],
            'Renewal invoice ' . $invoiceId, $prepared['payment_id'], $attempt['attempt_id'], $invoiceId, 'bank_card', false, $prepared['provider_method_reference']);
        try {
            $providerPayment = is_string($current['provider_payment_id'])
                ? $this->gateway->getPayment($prepared['account'], $current['provider_payment_id'])
                : $this->gateway->createPayment($prepared['account'], $request);
        } catch (PaymentProviderException $exception) {
            if (!$exception->retryable) {
                $this->completer->complete($attempt['attempt_id'], 'failed', $exception->errorCode, $instant, $correlationId);
                $this->lifecycle->terminal($invoiceId, new ProviderPaymentFailure($exception->failureClass, $exception->errorCode), $instant, $correlationId);
            } else {
                $this->lifecycle->uncertain($invoiceId, $instant, $exception->errorCode);
            }
            return ['state' => $exception->retryable ? 'retryable_failure' : 'non_retryable_failure', 'invoice_id' => $invoiceId, 'payment_id' => $prepared['payment_id'], 'attempt_id' => $attempt['attempt_id']];
        }
        $this->binder->bind($attempt['attempt_id'], $providerPayment->id);
        $this->processor->process($prepared['account'], $providerPayment, $correlationId, $instant);

        $state = match ($providerPayment->status) {
            'succeeded' => 'succeeded',
            'canceled' => 'non_retryable_failure',
            default => 'pending',
        };
        return ['state' => $state, 'invoice_id' => $invoiceId, 'payment_id' => $prepared['payment_id'], 'attempt_id' => $attempt['attempt_id']];
    }

    /** Select a bounded, locked batch. Network calls deliberately happen after locks are released. */
    public function collectDue(\DateTimeImmutable $instant, int $limit, string $correlationId): int
    {
        if ($limit < 1 || $limit > 1000 || !Uuid::isValid($correlationId)) throw new \InvalidArgumentException('Invalid renewal collection batch.');
        $until = $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
        $ids = $this->connection->transactional(function (Connection $db) use ($until, $limit): array {
            return $db->fetchFirstColumn(<<<'SQL'
SELECT i.id
FROM commercial.invoices i
JOIN commercial.subscriptions s ON s.id = i.subscription_id AND s.commercial_account_id = i.commercial_account_id
LEFT JOIN commercial.renewal_collection_states r ON r.invoice_id = i.id
WHERE i.billing_reason = 'subscription_renewal' AND i.status = 'issued' AND i.total_minor > 0
  AND i.service_period_start <= ?::timestamptz AND s.status = 'active'
  AND (r.id IS NULL OR r.state = 'ready'
       OR (r.state = 'collecting' AND r.next_attempt_at <= ?::timestamptz))
ORDER BY i.service_period_start, i.id
LIMIT ? FOR UPDATE OF i SKIP LOCKED
SQL, [$until, $until, $limit], [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]);
        });
        foreach ($ids as $id) if (is_string($id)) $this->collect($id, $correlationId);
        return count($ids);
    }

    /** @return array{state:string,invoice_id:string,payment_id?:string,amount_minor?:int,currency?:string,provider_method_reference?:string,account?:ProviderAccount} */
    private function prepare(string $invoiceId, string $correlationId): array
    {
        return $this->connection->transactional(function (Connection $db) use ($invoiceId, $correlationId): array {
            $invoice = $db->fetchAssociative(<<<'SQL'
SELECT i.id, i.commercial_account_id, i.subscription_id, i.status, i.billing_reason, i.total_minor, i.currency, i.bill_to_contact_email, s.status AS subscription_status, r.state AS collection_state
FROM commercial.invoices i JOIN commercial.subscriptions s ON s.id=i.subscription_id LEFT JOIN commercial.renewal_collection_states r ON r.invoice_id=i.id
WHERE i.id=? FOR UPDATE OF i,s
SQL, [$invoiceId]);
            if ($invoice === false || $invoice['billing_reason'] !== 'subscription_renewal' || $invoice['status'] === 'paid') return ['state' => 'ineligible', 'invoice_id' => $invoiceId];
            if ($invoice['status'] !== 'issued' || $invoice['subscription_status'] !== 'active') return ['state' => 'ineligible', 'invoice_id' => $invoiceId];
            if (in_array($invoice['collection_state'], ['succeeded','exhausted'], true)) return ['state' => 'ineligible', 'invoice_id' => $invoiceId];
            $amount = Money::nonNegativeBigInt($invoice['total_minor']);
            if ($amount <= 0 || $invoice['currency'] !== 'RUB' || !is_string($invoice['commercial_account_id']) || !is_string($invoice['subscription_id'])) return ['state' => 'ineligible', 'invoice_id' => $invoiceId];
            $method = $db->fetchAssociative(<<<'SQL'
SELECT m.id, m.provider_method_reference, pa.id AS provider_account_id, pa.provider_code, pa.external_account_id, pa.secret_ref, pa.receipt_mode, pa.receipt_vat_code, pa.receipt_payment_subject, pa.receipt_payment_mode
FROM commercial.saved_payment_methods m JOIN commercial.payment_provider_accounts pa ON pa.id=m.provider_account_id
WHERE m.commercial_account_id=? AND m.status='active' AND m.is_default AND m.method_type='bank_card' AND pa.status='active' AND pa.provider_code='yookassa'
ORDER BY m.id LIMIT 1 FOR UPDATE OF m,pa
SQL, [$invoice['commercial_account_id']]);
            if ($method === false) {
                $changed = $db->executeStatement("INSERT INTO commercial.renewal_collection_states (invoice_id, commercial_account_id, subscription_id, state, next_attempt_at) VALUES (?, ?, ?, 'payment_method_required', NULL) ON CONFLICT (invoice_id) DO UPDATE SET state = 'payment_method_required', next_attempt_at=NULL, updated_at = now() WHERE commercial.renewal_collection_states.state IN ('ready','collecting','retry_wait')", [$invoiceId, $invoice['commercial_account_id'], $invoice['subscription_id']]);
                if ($changed > 0) {
                    $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'renewal.payment_method_required', 'invoice', ?, ?, 'success')", [$invoice['commercial_account_id'], $invoiceId, $correlationId]);
                    $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.payment_method_required', 'invoice', ?, jsonb_build_object('invoice_id', ?::text, 'commercial_account_id', ?::text))", [$invoiceId, $invoiceId, $invoice['commercial_account_id']]);
                }
                return ['state' => 'no_payment_method', 'invoice_id' => $invoiceId];
            }
            if (!is_string($invoice['bill_to_contact_email'])) return ['state' => 'ineligible', 'invoice_id' => $invoiceId];
            EmailPolicy::normalize($invoice['bill_to_contact_email']);
            $restored = $db->executeStatement("UPDATE commercial.renewal_collection_states SET state='ready', updated_at=now() WHERE invoice_id=? AND state='payment_method_required'", [$invoiceId]);
            if ($restored > 0) {
                $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'renewal.payment_method_restored', 'invoice', ?, ?, 'success')", [$invoice['commercial_account_id'], $invoiceId, $correlationId]);
                $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.payment_method_restored', 'invoice', ?, jsonb_build_object('invoice_id', ?::text, 'commercial_account_id', ?::text))", [$invoiceId, $invoiceId, $invoice['commercial_account_id']]);
            }
            $db->executeStatement("INSERT INTO commercial.renewal_collection_states (invoice_id, commercial_account_id, subscription_id, state, next_attempt_at) VALUES (?, ?, ?, 'ready', now()) ON CONFLICT (invoice_id) DO NOTHING", [$invoiceId, $invoice['commercial_account_id'], $invoice['subscription_id']]);
            $payment = $this->paymentCreator->createInTransaction($db, $invoice, $correlationId, 'system');
            if (!is_string($payment['id'] ?? null) || ($payment['status'] ?? null) !== 'pending') return ['state' => 'ineligible', 'invoice_id' => $invoiceId];
            $started = $db->executeStatement("UPDATE commercial.renewal_collection_states SET state='collecting', next_attempt_at=now(), updated_at=now() WHERE invoice_id=? AND state IN ('ready','retry_wait')", [$invoiceId]);
            $account = new ProviderAccount($method['provider_account_id'], $method['provider_code'], $method['external_account_id'], $method['secret_ref'], $method['receipt_mode'], $method['receipt_vat_code'] === null ? null : (int) $method['receipt_vat_code'], $method['receipt_payment_subject'], $method['receipt_payment_mode']);
            YooKassaFiscalPolicy::validate($account);
            if ($started > 0) {
                $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'renewal.collection.started', 'payment', ?, ?, 'success')", [$invoice['commercial_account_id'], $payment['id'], $correlationId]);
                $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('subscription.renewal_collection_started', 'subscription', ?, jsonb_build_object('subscription_id', ?::text, 'invoice_id', ?::text, 'payment_id', ?::text, 'commercial_account_id', ?::text))", [$invoice['subscription_id'], $invoice['subscription_id'], $invoiceId, $payment['id'], $invoice['commercial_account_id']]);
            }
            return ['state' => 'ready', 'invoice_id' => $invoiceId, 'payment_id' => $payment['id'], 'amount_minor' => $amount, 'currency' => $invoice['currency'], 'provider_method_reference' => $method['provider_method_reference'], 'account' => $account];
        });
    }

}
