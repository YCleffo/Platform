<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Privacy\ControlProviderEgressPolicy;

use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\RefundProviderRequest;
use ControlPlane\Payment\Provider\YooKassa\EnvPaymentProviderSecretResolver;
use ControlPlane\Payment\Provider\YooKassa\YooKassaRefundGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YooKassaRefundGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('CONTROL_TEST_REFUND_SECRET=refund-secret');
    }

    protected function tearDown(): void
    {
        putenv('CONTROL_TEST_REFUND_SECRET');
    }

    public function testUsesStableIdempotenceAndStrictlyParsesAuthoritativeRefund(): void
    {
        $localId = '01999d9f-46be-7780-9820-a60123456789';
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($localId): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.yookassa.ru/v3/refunds', $url);
            self::assertContains('Idempotence-Key: stable-refund-key', $options['normalized_headers']['idempotence-key']);
            $body = json_decode((string) $options['body'], true, 16, JSON_THROW_ON_ERROR);
            self::assertSame('provider-payment', $body['payment_id']);
            self::assertSame(['value' => '10.00', 'currency' => 'RUB'], $body['amount']);

            return new MockResponse(json_encode(['id' => 'provider-refund', 'payment_id' => 'provider-payment', 'status' => 'succeeded', 'amount' => ['value' => '10.00', 'currency' => 'RUB'], 'description' => 'Refund ' . $localId, 'created_at' => '2026-09-27T10:00:00Z'], JSON_THROW_ON_ERROR));
        });
        $gateway = new YooKassaRefundGateway($client, new EnvPaymentProviderSecretResolver(),
            new ControlProviderEgressPolicy(['yookassa' => [
                'status' => 'allowed', 'country' => 'RU', 'purposes' => ['refund'],
                'data_categories' => ['payment_reference', 'billing_contact'],
                'evidence_reference' => 'test-provider', 'notification_status' => 'not_required',
            ]]));
        $refund = $gateway->createRefund(new ProviderAccount('account', 'yookassa', 'shop', 'env:CONTROL_TEST_REFUND_SECRET'), new RefundProviderRequest('stable-refund-key', 'provider-payment', 1000, 'RUB', $localId));

        self::assertSame('succeeded', $refund->status);
        self::assertSame($localId, $refund->localRefundId);
        self::assertSame(1000, $refund->amountMinor);
    }
}
