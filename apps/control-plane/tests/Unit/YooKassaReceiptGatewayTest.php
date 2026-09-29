<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Privacy\ControlProviderEgressPolicy;

use ControlPlane\Payment\Provider\FiscalReceiptItem;
use ControlPlane\Payment\Provider\FiscalReceiptRequest;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\YooKassa\EnvPaymentProviderSecretResolver;
use ControlPlane\Payment\Provider\YooKassa\YooKassaReceiptGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YooKassaReceiptGatewayTest extends TestCase
{
    private const SECRET = 'CONTROL_TEST_YOOKASSA_RECEIPT_SECRET';

    protected function setUp(): void
    {
        putenv(self::SECRET . '=receipt-test-secret');
    }

    protected function tearDown(): void
    {
        putenv(self::SECRET);
    }

    public function testCreatesExactPaymentReceiptAndStrictlyParsesResponse(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.yookassa.ru/v3/receipts', $url);
            self::assertContains('Idempotence-Key: receipt-request-key', $options['normalized_headers']['idempotence-key']);
            self::assertContains('Authorization: Basic ' . base64_encode('shop-123:receipt-test-secret'), $options['normalized_headers']['authorization']);
            $body = json_decode((string) $options['body'], true, 16, JSON_THROW_ON_ERROR);
            self::assertSame('payment', $body['type']);
            self::assertSame('provider-payment-1', $body['payment_id']);
            self::assertTrue($body['send']);
            self::assertSame(['email' => 'billing@example.test', 'full_name' => 'Billing Contact'], $body['customer']);
            self::assertSame('1.000', $body['items'][0]['quantity']);
            self::assertSame(['value' => '10.50', 'currency' => 'RUB'], $body['items'][0]['amount']);
            self::assertSame('cashless', $body['settlements'][0]['type']);

            return new MockResponse(json_encode($this->response(), JSON_THROW_ON_ERROR));
        });
        $request = new FiscalReceiptRequest('receipt-request-key', 'provider-payment-1', 'billing@example.test', 'Billing Contact', 1050, 'RUB', [$this->item()]);
        $receipt = $this->gateway($client)->createReceipt($this->account(), $request);

        self::assertSame('receipt-1', $receipt->id);
        self::assertSame('pending', $receipt->status);
        self::assertSame(1050, $receipt->amountMinor);
        self::assertCount(1, $receipt->items);
    }

    public function testGetsTerminalReceiptAndRejectsMalformedProviderShape(): void
    {
        $succeeded = $this->response();
        $succeeded['status'] = 'succeeded';
        $succeeded['fiscal_document_attribute'] = ['registered_at' => '2026-09-26T12:00:00+03:00'];
        $gateway = $this->gateway(new MockHttpClient(new MockResponse(json_encode($succeeded, JSON_THROW_ON_ERROR))));
        self::assertSame('2026-09-26T09:00:00+00:00', $gateway->getReceipt($this->account(), 'receipt-1')->completedAt?->format(DATE_ATOM));

        $bad = $this->response();
        unset($bad['settlements']);
        try {
            $this->gateway(new MockHttpClient(new MockResponse(json_encode($bad, JSON_THROW_ON_ERROR))))->getReceipt($this->account(), 'receipt-1');
            self::fail('Malformed provider response was expected.');
        } catch (PaymentProviderException $exception) {
            self::assertTrue($exception->retryable);
            self::assertSame('invalid_receipt_provider_response', $exception->errorCode);
        }
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        return [
            'id' => 'receipt-1',
            'type' => 'payment',
            'payment_id' => 'provider-payment-1',
            'status' => 'pending',
            'items' => [[
                'description' => 'Subscription', 'quantity' => '1.000',
                'amount' => ['value' => '10.50', 'currency' => 'RUB'],
                'vat_code' => 1, 'payment_subject' => 'service', 'payment_mode' => 'full_payment',
            ]],
            'settlements' => [['type' => 'cashless', 'amount' => ['value' => '10.50', 'currency' => 'RUB']]],
        ];
    }

    private function item(): FiscalReceiptItem
    {
        return new FiscalReceiptItem('Subscription', 1, 1050, 1050, 'RUB', 1, 'service', 'full_payment');
    }

    private function gateway(MockHttpClient $client): YooKassaReceiptGateway
    {
        return new YooKassaReceiptGateway($client, new EnvPaymentProviderSecretResolver(),
            new ControlProviderEgressPolicy(['yookassa' => [
                'status' => 'allowed', 'country' => 'RU', 'purposes' => ['fiscal_receipt'],
                'data_categories' => ['billing_contact', 'fiscal_details'],
                'evidence_reference' => 'test-provider', 'notification_status' => 'not_required',
            ]]));
    }

    private function account(): ProviderAccount
    {
        return new ProviderAccount('account', 'yookassa', 'shop-123', 'env:' . self::SECRET, 'yookassa', 1, 'service', 'full_payment');
    }
}
