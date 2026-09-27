<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Application;

use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\PaymentProviderGateway;
use ControlPlane\Payment\Provider\RefundProviderGateway;
use ControlPlane\Payment\Provider\YooKassa\YooKassaProviderAccountLocator;
use ControlPlane\Refund\Application\SubmitYooKassaRefund;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class ReceiveYooKassaWebhook
{
    private const MAX_BODY_BYTES = 65536;

    public function __construct(
        private Connection $connection,
        private YooKassaProviderAccountLocator $accounts,
        private PaymentProviderGateway $gateway,
        private ProcessAuthoritativeProviderPayment $processor,
        private ?RefundProviderGateway $refundGateway = null,
        private ?SubmitYooKassaRefund $refunds = null,
    ) {
    }

    /** @return array{status: string} */
    public function receive(string $rawBody, string $contentType, string $correlationId): array
    {
        if (!Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid correlation identifier.');
        }
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException('Webhook request body is too large.');
        }
        if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
            throw new \InvalidArgumentException('Webhook content type must be application/json.');
        }
        $payload = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload) || ($payload['type'] ?? null) !== 'notification'
            || !is_string($payload['event'] ?? null) || !is_array($payload['object'] ?? null)
            || !is_string($payload['object']['id'] ?? null)) {
            throw new \InvalidArgumentException('Invalid YooKassa webhook payload.');
        }
        $eventType = IdentifierPolicy::machineCode($payload['event'], 160);
        $providerObjectId = IdentifierPolicy::opaqueKey($payload['object']['id']);
        $account = $this->accounts->active();
        $eventKey = $eventType . ':' . $providerObjectId;
        $event = $this->record($account->id, $eventKey, $eventType, $providerObjectId, hash('sha256', $rawBody));
        if (in_array($event['status'], ['processed', 'ignored'], true)) {
            return ['status' => (string) $event['status']];
        }
        if (!in_array($eventType, ['payment.succeeded', 'payment.canceled', 'refund.succeeded'], true)) {
            $this->finish((string) $event['id'], 'ignored', null);

            return ['status' => 'ignored'];
        }

        try {
            if ($eventType === 'refund.succeeded') {
                if ($this->refundGateway === null || $this->refunds === null) {
                    throw new \DomainException('Refund webhook processing is unavailable.');
                }
                $providerRefund = $this->refundGateway->getRefund($account, $providerObjectId);
                if ($providerRefund->status === 'pending') {
                    throw PaymentProviderException::uncertain('provider_state_not_final');
                }
                $refundId = $providerRefund->localRefundId;
                if ($refundId === null) {
                    $candidate = $this->connection->fetchOne('SELECT id FROM commercial.refunds WHERE provider_account_id = ? AND provider_refund_id = ?', [$account->id, $providerRefund->id]);
                    $refundId = is_string($candidate) ? $candidate : null;
                }
                if ($refundId === null) {
                    throw new \DomainException('Provider refund cannot be linked to local state.');
                }
                $this->refunds->processAuthoritative($refundId, $providerRefund, $correlationId);
                $this->finish((string) $event['id'], 'processed', null);

                return ['status' => 'processed'];
            }
            $providerPayment = $this->gateway->getPayment($account, $providerObjectId);
            if (in_array($providerPayment->status, ['pending', 'waiting_for_capture'], true)) {
                throw PaymentProviderException::uncertain('provider_state_not_final');
            }
            $this->processor->process($account, $providerPayment, $correlationId);
            $this->finish((string) $event['id'], 'processed', null);

            return ['status' => 'processed'];
        } catch (PaymentProviderException $exception) {
            $this->finish((string) $event['id'], 'failed', $exception->errorCode);
            throw $exception;
        } catch (\DomainException) {
            $this->finish((string) $event['id'], 'failed', 'provider_state_mismatch');
            throw PaymentProviderException::uncertain('provider_state_mismatch');
        }
    }

    /** @return array<string, mixed> */
    private function record(string $providerAccountId, string $eventKey, string $eventType, string $providerObjectId, string $payloadHash): array
    {
        return $this->connection->transactional(function (Connection $connection) use ($providerAccountId, $eventKey, $eventType, $providerObjectId, $payloadHash): array {
            $connection->executeStatement("INSERT INTO commercial.payment_provider_events (provider_account_id, event_key, event_type, provider_object_id, payload_sha256) VALUES (?, ?, ?, ?, decode(?, 'hex')) ON CONFLICT (provider_account_id, event_key) DO NOTHING", [$providerAccountId, $eventKey, $eventType, $providerObjectId, $payloadHash]);
            $event = $connection->fetchAssociative('SELECT id, status FROM commercial.payment_provider_events WHERE provider_account_id = ? AND event_key = ? FOR UPDATE', [$providerAccountId, $eventKey]);
            if ($event === false) {
                throw new \LogicException('Provider event inbox record is unavailable.');
            }

            return $event;
        });
    }

    private function finish(string $eventId, string $status, ?string $errorCode): void
    {
        $this->connection->transactional(function (Connection $connection) use ($eventId, $status, $errorCode): void {
            $current = $connection->fetchOne('SELECT status FROM commercial.payment_provider_events WHERE id = ? FOR UPDATE', [$eventId]);
            if (in_array($current, ['processed', 'ignored'], true)) {
                return;
            }
            if ($status === 'failed') {
                $connection->executeStatement("UPDATE commercial.payment_provider_events SET status = 'failed', processed_at = NULL, last_error_code = ? WHERE id = ?", [$errorCode, $eventId]);
            } else {
                $connection->executeStatement('UPDATE commercial.payment_provider_events SET status = ?, processed_at = now(), last_error_code = NULL WHERE id = ?', [$status, $eventId]);
            }
        });
    }
}
