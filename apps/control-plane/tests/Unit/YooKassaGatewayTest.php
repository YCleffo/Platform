<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Privacy\ControlProviderEgressPolicy;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderPaymentRequest;
use ControlPlane\Payment\Provider\YooKassa\EnvPaymentProviderSecretResolver;
use ControlPlane\Payment\Provider\YooKassa\YooKassaGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YooKassaGatewayTest extends TestCase
{
    private const SECRET_VARIABLE = 'CONTROL_TEST_YOOKASSA_GATEWAY_SECRET';

    protected function setUp(): void
    {
        putenv(self::SECRET_VARIABLE . '=unit-test-shop-secret');
    }

    protected function tearDown(): void
    {
        putenv(self::SECRET_VARIABLE);
    }

    public function testUnknownEgressDecisionBlocksBeforeHttp(): void
    {
        $client = new MockHttpClient(static function (): never {
            self::fail('HTTP must not be called for unknown egress.');
        });
        $gateway = new YooKassaGateway($client, new EnvPaymentProviderSecretResolver(),
            'https://platform.example/payments/return', new ControlProviderEgressPolicy([]));
        $this->expectException(\DomainException::class);
        $gateway->getPayment($this->account(), 'yk-payment-1');
    }

    public function testCreatesExactSbpRedirectRequestWithoutExposingSecret(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.yookassa.ru/v3/payments', $url);
            self::assertContains('Authorization: Basic ' . base64_encode('shop-123:unit-test-shop-secret'), $options['normalized_headers']['authorization']);
            self::assertContains('Idempotence-Key: provider-request-key', $options['normalized_headers']['idempotence-key']);
            $body = json_decode((string) $options['body'], true, 16, JSON_THROW_ON_ERROR);
            self::assertSame(['value' => '10.50', 'currency' => 'RUB'], $body['amount']);
            self::assertTrue($body['capture']);
            self::assertSame(['type' => 'sbp'], $body['payment_method_data']);
            self::assertSame(['type' => 'redirect', 'return_url' => 'https://platform.example/payments/return'], $body['confirmation']);
            self::assertSame(['payment_id' => 'payment-id', 'attempt_id' => 'attempt-id', 'invoice_id' => 'invoice-id'], $body['metadata']);

            return new MockResponse(json_encode($this->pendingResponse(), JSON_THROW_ON_ERROR));
        });
        $result = $this->gateway($client)->createPayment($this->account(), new ProviderPaymentRequest(
            1050,
            'RUB',
            'provider-request-key',
            'Invoice invoice-id',
            'payment-id',
            'attempt-id',
            'invoice-id',
        ));

        self::assertSame('yk-payment-1', $result->id);
        self::assertSame(1050, $result->amountMinor);
        self::assertSame('https://yookassa.test/confirm/1', $result->confirmationUrl);
        self::assertStringNotContainsString('unit-test-shop-secret', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testGetsAndStrictlyParsesAuthoritativeSuccess(): void
    {
        $response = $this->pendingResponse();
        $response['status'] = 'succeeded';
        $response['paid'] = true;
        $response['captured_at'] = '2026-09-26T10:11:12.123456+03:00';
        unset($response['confirmation']);
        $client = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://api.yookassa.ru/v3/payments/yk-payment-1', $url);

            return new MockResponse(json_encode($response, JSON_THROW_ON_ERROR));
        });
        $payment = $this->gateway($client)->getPayment($this->account(), 'yk-payment-1');

        self::assertSame('succeeded', $payment->status);
        self::assertTrue($payment->paid);
        self::assertSame('2026-09-26T07:11:12+00:00', $payment->capturedAt?->format(DATE_ATOM));
    }

    public function testCreatesBankCardCheckoutWithOnlyHostedProviderFields(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.yookassa.ru/v3/payments', $url);
            $body = json_decode((string) $options['body'], true, 16, JSON_THROW_ON_ERROR);
            self::assertSame(['type' => 'bank_card'], $body['payment_method_data']);
            self::assertTrue($body['save_payment_method']);
            self::assertArrayNotHasKey('payment_method_id', $body);
            self::assertArrayNotHasKey('card', $body);

            return new MockResponse(json_encode($this->pendingResponse(), JSON_THROW_ON_ERROR));
        });
        $this->gateway($client)->createPayment($this->account(), new ProviderPaymentRequest(
            1050, 'RUB', 'provider-request-key', 'Invoice invoice-id', 'payment-id', 'attempt-id', 'invoice-id', 'bank_card', true,
        ));
    }

    public function testParsesOnlySafeSavedCardFields(): void
    {
        $response = $this->pendingResponse();
        $response['payment_method'] = [
            'id' => 'payment-method-1', 'type' => 'bank_card', 'saved' => true, 'title' => 'Bank card *1234', 'status' => 'active',
            'card' => ['first6' => '424242', 'last4' => '1234', 'expiry_month' => '12', 'expiry_year' => '2030', 'card_type' => 'MasterCard'],
        ];
        $client = new MockHttpClient(new MockResponse(json_encode($response, JSON_THROW_ON_ERROR)));
        $payment = $this->gateway($client)->getPayment($this->account(), 'yk-payment-1');

        self::assertSame('payment-method-1', $payment->paymentMethod?->id);
        self::assertSame('1234', $payment->paymentMethod?->last4);
        self::assertSame(12, $payment->paymentMethod?->expiryMonth);
        self::assertSame(2030, $payment->paymentMethod?->expiryYear);
        self::assertStringNotContainsString('424242', json_encode($payment, JSON_THROW_ON_ERROR));
    }

    public function testRejectsUnsupportedCurrencyBeforeNetwork(): void
    {
        $client = new MockHttpClient();
        $this->expectException(\DomainException::class);
        try {
            $this->gateway($client)->createPayment($this->account(), new ProviderPaymentRequest(100, 'USD', 'key', 'Invoice', 'payment', 'attempt', 'invoice'));
        } finally {
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public function testMalformedSuccessAndTimeoutAreUncertain(): void
    {
        foreach ([
            new MockHttpClient(new MockResponse('{"id":', ['http_code' => 200])),
            new MockHttpClient(static function (): never { throw new TransportException('secret transport detail'); }),
            new MockHttpClient(new MockResponse('{}', ['http_code' => 503])),
            new MockHttpClient(new MockResponse('{}', ['http_code' => 429])),
        ] as $client) {
            try {
                $this->gateway($client)->getPayment($this->account(), 'yk-payment-1');
                self::fail('Provider failure was expected.');
            } catch (PaymentProviderException $exception) {
                self::assertTrue($exception->retryable);
                self::assertStringNotContainsString('secret', $exception->getMessage());
            }
        }
    }

    public function testDefinitiveClientErrorIsSanitized(): void
    {
        $client = new MockHttpClient(new MockResponse('{"description":"raw provider detail"}', ['http_code' => 401]));
        try {
            $this->gateway($client)->getPayment($this->account(), 'yk-payment-1');
            self::fail('Provider rejection was expected.');
        } catch (PaymentProviderException $exception) {
            self::assertFalse($exception->retryable);
            self::assertSame('provider_authentication_failed', $exception->errorCode);
            self::assertStringNotContainsString('raw provider detail', $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function pendingResponse(): array
    {
        return [
            'id' => 'yk-payment-1',
            'status' => 'pending',
            'paid' => false,
            'amount' => ['value' => '10.50', 'currency' => 'RUB'],
            'metadata' => ['payment_id' => 'payment-id', 'attempt_id' => 'attempt-id', 'invoice_id' => 'invoice-id'],
            'confirmation' => ['type' => 'redirect', 'confirmation_url' => 'https://yookassa.test/confirm/1'],
        ];
    }

    private function gateway(MockHttpClient $client): YooKassaGateway
    {
        return new YooKassaGateway($client, new EnvPaymentProviderSecretResolver(),
            'https://platform.example/payments/return', new ControlProviderEgressPolicy(['yookassa' => [
                'status' => 'allowed', 'country' => 'RU', 'purposes' => ['payment'],
                'data_categories' => ['payment_reference', 'billing_contact'],
                'evidence_reference' => 'test-provider', 'notification_status' => 'not_required',
            ]]));
    }

    private function account(): ProviderAccount
    {
        return new ProviderAccount('provider-account', 'yookassa', 'shop-123', 'env:' . self::SECRET_VARIABLE);
    }
}
