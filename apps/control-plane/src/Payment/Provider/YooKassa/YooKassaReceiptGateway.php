<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider\YooKassa;

use ControlPlane\Billing\Domain\Money;
use ControlPlane\Privacy\ControlProviderEgressPolicy;
use ControlPlane\Payment\Provider\FiscalReceiptItem;
use ControlPlane\Payment\Provider\FiscalReceiptProviderGateway;
use ControlPlane\Payment\Provider\FiscalReceiptRequest;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\PaymentProviderSecretResolver;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderReceipt;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class YooKassaReceiptGateway implements FiscalReceiptProviderGateway
{
    private const API_BASE = 'https://api.yookassa.ru/v3/receipts';

    public function __construct(private HttpClientInterface $httpClient, private PaymentProviderSecretResolver $secrets,
        private ControlProviderEgressPolicy $egress)
    {
    }

    public function createReceipt(ProviderAccount $account, FiscalReceiptRequest $request): ProviderReceipt
    {
        $this->validateAccount($account);
        if ($request->currency !== 'RUB' || $request->amountMinor <= 0 || $request->items === []) {
            throw new \DomainException('YooKassa receipt requires positive RUB money and items.');
        }
        $total = 0;
        foreach ($request->items as $item) {
            YooKassaFiscalPolicy::validateValues($item->vatCode, $item->paymentSubject, $item->paymentMode);
            if ($item->currency !== $request->currency || $item->quantity <= 0 || $item->unitAmountMinor < 0
                || $item->totalMinor !== $item->unitAmountMinor * $item->quantity) {
                throw new \DomainException('YooKassa receipt item money is inconsistent.');
            }
            $total += $item->totalMinor;
        }
        if ($total !== $request->amountMinor) {
            throw new \DomainException('YooKassa receipt total is inconsistent.');
        }
        if (!in_array($request->receiptType, ['payment', 'refund'], true)
            || ($request->receiptType === 'refund' && !is_string($request->providerRefundId))) {
            throw new \DomainException('Unsupported YooKassa receipt type.');
        }
        $payload = [
            'type' => $request->receiptType,
            'send' => true,
            'customer' => array_filter(['email' => $request->customerEmail, 'full_name' => $request->customerName], static fn (mixed $value): bool => $value !== null),
            'items' => array_map(static fn (FiscalReceiptItem $item): array => [
                'description' => $item->description,
                'quantity' => number_format($item->quantity, 3, '.', ''),
                'amount' => ['value' => self::formatMinor($item->unitAmountMinor), 'currency' => $item->currency],
                'vat_code' => $item->vatCode,
                'payment_subject' => $item->paymentSubject,
                'payment_mode' => $item->paymentMode,
            ], $request->items),
            'settlements' => [[
                'type' => 'cashless',
                'amount' => ['value' => self::formatMinor($request->amountMinor), 'currency' => $request->currency],
            ]],
        ];
        if ($request->receiptType === 'payment') {
            $payload['payment_id'] = IdentifierPolicy::opaqueKey($request->providerPaymentId);
        } else {
            $payload['refund_id'] = IdentifierPolicy::opaqueKey((string) $request->providerRefundId);
        }

        return $this->request('POST', self::API_BASE, $account, [
            'headers' => ['Idempotence-Key' => IdentifierPolicy::opaqueKey($request->providerRequestKey)],
            'json' => $payload,
        ]);
    }

    public function getReceipt(ProviderAccount $account, string $providerReceiptId): ProviderReceipt
    {
        $this->validateAccount($account);

        return $this->request('GET', self::API_BASE . '/' . rawurlencode(IdentifierPolicy::opaqueKey($providerReceiptId)), $account, []);
    }

    /** @param array<string, mixed> $options */
    private function request(string $method, string $url, ProviderAccount $account, array $options): ProviderReceipt
    {
        $this->egress->assertAllowed('yookassa', 'fiscal_receipt', ['billing_contact', 'fiscal_details']);
        $options['auth_basic'] = [$account->externalAccountId, $this->secrets->resolve($account->secretRef)];
        $options['timeout'] = 10.0;
        $options['max_duration'] = 15.0;
        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            if (in_array($status, [408, 409, 425, 429], true) || $status >= 500) {
                throw PaymentProviderException::uncertain('receipt_provider_unavailable');
            }
            if ($status >= 400) {
                throw PaymentProviderException::definitive(match ($status) {
                    401, 403 => 'provider_authentication_failed',
                    400, 422 => 'invalid_receipt_request',
                    default => 'receipt_provider_rejected',
                });
            }
            $data = json_decode($response->getContent(false), true, 32, JSON_THROW_ON_ERROR);

            return $this->parse($data);
        } catch (PaymentProviderException $exception) {
            throw $exception;
        } catch (TransportExceptionInterface) {
            throw PaymentProviderException::uncertain('receipt_provider_unavailable');
        } catch (\Throwable) {
            throw PaymentProviderException::uncertain('invalid_receipt_provider_response');
        }
    }

    private function validateAccount(ProviderAccount $account): void
    {
        if ($account->providerCode !== 'yookassa') {
            throw new \DomainException('Unsupported fiscal receipt provider account.');
        }
        IdentifierPolicy::opaqueKey($account->externalAccountId);
    }

    private function parse(mixed $data): ProviderReceipt
    {
        if (!is_array($data) || array_is_list($data) || !is_string($data['id'] ?? null) || !in_array($data['type'] ?? null, ['payment', 'refund'], true)
            || !is_string($data['status'] ?? null)
            || !is_array($data['items'] ?? null) || !array_is_list($data['items'])
            || !is_array($data['settlements'] ?? null) || !array_is_list($data['settlements']) || count($data['settlements']) !== 1) {
            throw new \UnexpectedValueException('Invalid provider receipt response.');
        }
        if (!in_array($data['status'], ['pending', 'succeeded', 'canceled'], true)) {
            throw new \UnexpectedValueException('Unsupported provider receipt status.');
        }
        $type = $data['type'];
        if (($type === 'payment' && !is_string($data['payment_id'] ?? null)) || ($type === 'refund' && !is_string($data['refund_id'] ?? null))) {
            throw new \UnexpectedValueException('Invalid provider receipt source reference.');
        }
        $items = [];
        foreach ($data['items'] as $item) {
            if (!is_array($item) || !is_string($item['description'] ?? null) || !isset($item['quantity'])
                || !is_array($item['amount'] ?? null) || !isset($item['amount']['value']) || !is_string($item['amount']['currency'] ?? null)
                || !is_int($item['vat_code'] ?? null) || !is_string($item['payment_subject'] ?? null) || !is_string($item['payment_mode'] ?? null)) {
                throw new \UnexpectedValueException('Invalid provider receipt item.');
            }
            $quantity = self::parseQuantity($item['quantity']);
            $unit = self::parseMinor($item['amount']['value']);
            $items[] = new FiscalReceiptItem($item['description'], $quantity, $unit, $unit * $quantity, $item['amount']['currency'], $item['vat_code'], $item['payment_subject'], $item['payment_mode']);
        }
        $settlement = $data['settlements'][0];
        if (!is_array($settlement) || ($settlement['type'] ?? null) !== 'cashless' || !is_array($settlement['amount'] ?? null)
            || !isset($settlement['amount']['value']) || !is_string($settlement['amount']['currency'] ?? null)) {
            throw new \UnexpectedValueException('Invalid provider receipt settlement.');
        }
        $completedAt = null;
        $registeredAt = is_array($data['fiscal_document_attribute'] ?? null) ? ($data['fiscal_document_attribute']['registered_at'] ?? null) : null;
        if ($registeredAt !== null) {
            if (!is_string($registeredAt) || preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $registeredAt) !== 1) {
                throw new \UnexpectedValueException('Invalid receipt completion timestamp.');
            }
            $completedAt = (new \DateTimeImmutable($registeredAt))->setTimezone(new \DateTimeZone('UTC'));
        }

        return new ProviderReceipt(
            IdentifierPolicy::opaqueKey($data['id']),
            $type,
            $type === 'payment' ? IdentifierPolicy::opaqueKey($data['payment_id']) : '',
            $data['status'],
            self::parseMinor($settlement['amount']['value']),
            $settlement['amount']['currency'],
            $items,
            $completedAt,
            $type === 'refund' ? IdentifierPolicy::opaqueKey($data['refund_id']) : null,
        );
    }

    private static function parseQuantity(mixed $quantity): int
    {
        if (is_int($quantity) && $quantity > 0) {
            return $quantity;
        }
        if (is_string($quantity) && preg_match('/\A([1-9][0-9]*)(?:\.0{1,3})?\z/D', $quantity, $matches) === 1) {
            return Money::nonNegativeBigInt($matches[1]);
        }
        throw new \UnexpectedValueException('Invalid provider receipt quantity.');
    }

    private static function parseMinor(mixed $value): int
    {
        if (!is_string($value) || preg_match('/\A(0|[1-9][0-9]*)\.([0-9]{2})\z/D', $value, $matches) !== 1) {
            throw new \UnexpectedValueException('Invalid provider amount.');
        }
        $major = Money::nonNegativeBigInt($matches[1]);
        if ($major > intdiv(PHP_INT_MAX - (int) $matches[2], 100)) {
            throw new \UnexpectedValueException('Provider amount is too large.');
        }

        return $major * 100 + (int) $matches[2];
    }

    private static function formatMinor(int $amountMinor): string
    {
        return intdiv($amountMinor, 100) . '.' . str_pad((string) ($amountMinor % 100), 2, '0', STR_PAD_LEFT);
    }
}
