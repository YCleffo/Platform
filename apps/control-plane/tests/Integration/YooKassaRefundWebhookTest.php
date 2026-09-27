<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Payment\Application\ReceiveYooKassaWebhook;
use ControlPlane\Payment\Provider\ProviderRefund;
use ControlPlane\Payment\Provider\YooKassa\YooKassaProviderAccountLocator;
use ControlPlane\Tests\Support\FakePaymentProviderGateway;
use ControlPlane\Tests\Support\FakeRefundProviderGateway;

final class YooKassaRefundWebhookTest extends RefundTestCase
{
    public function testDuplicateRefundWebhookUsesAuthoritativeGetAndSettlesOnce(): void
    {
        [$owner, $account, $payment, , $line, , $providerPayment] = $this->succeededRefundablePayment('refund-webhook');
        $refund = $this->refundCreator()->create($owner, $account, $payment, [['invoice_line_id' => $line, 'quantity' => 1, 'unit_amount_minor' => 1000]], 'refund-webhook-key', $this->uuid());
        $gateway = new FakeRefundProviderGateway();
        $submission = $this->refundSubmission($gateway);
        self::assertFalse($submission->submit($refund['id'], $this->uuid()));
        $providerRefund = (string) $this->connection->fetchOne('SELECT provider_refund_id FROM commercial.refunds WHERE id = ?', [$refund['id']]);
        $gateway->refunds[$providerRefund] = new ProviderRefund($providerRefund, $providerPayment, 'succeeded', 1000, 'RUB', new \DateTimeImmutable('2026-09-27T10:00:00Z'), $refund['id']);
        $webhook = new ReceiveYooKassaWebhook($this->connection, new YooKassaProviderAccountLocator($this->connection), new FakePaymentProviderGateway(), $this->authoritativeProcessor(), $gateway, $submission);
        $body = json_encode(['type' => 'notification', 'event' => 'refund.succeeded', 'object' => ['id' => $providerRefund]], JSON_THROW_ON_ERROR);

        $webhook->receive($body, 'application/json', $this->uuid());
        $webhook->receive($body, 'application/json', $this->uuid());
        self::assertSame('processed', $this->connection->fetchOne("SELECT status FROM commercial.payment_provider_events WHERE event_type = 'refund.succeeded' AND provider_object_id = ?", [$providerRefund]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'refund.succeeded' AND aggregate_id = ?", [$refund['id']]));
        self::assertCount(1, $gateway->getCalls);
    }
}
