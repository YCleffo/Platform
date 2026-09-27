<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider\YooKassa;

use ControlPlane\Billing\Domain\Money;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\PaymentProviderSecretResolver;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderRefund;
use ControlPlane\Payment\Provider\RefundProviderGateway;
use ControlPlane\Payment\Provider\RefundProviderRequest;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class YooKassaRefundGateway implements RefundProviderGateway
{
    private const API_BASE = 'https://api.yookassa.ru/v3/refunds';

    public function __construct(private HttpClientInterface $httpClient, private PaymentProviderSecretResolver $secrets)
    {
    }

    public function createRefund(ProviderAccount $account, RefundProviderRequest $request): ProviderRefund
    {
        $this->validateAccount($account);
        if ($request->currency !== 'RUB' || $request->amountMinor <= 0) {
            throw new \DomainException('YooKassa refund requires positive RUB money.');
        }

        return $this->request('POST', self::API_BASE, $account, [
            'headers' => ['Idempotence-Key' => IdentifierPolicy::opaqueKey($request->providerRequestKey)],
            'json' => [
                'payment_id' => IdentifierPolicy::opaqueKey($request->providerPaymentId),
                'amount' => ['value' => self::formatMinor($request->amountMinor), 'currency' => 'RUB'],
                'description' => 'Refund ' . $request->refundId,
            ],
        ]);
    }

    public function getRefund(ProviderAccount $account, string $providerRefundId): ProviderRefund
    {
        $this->validateAccount($account);

        return $this->request('GET', self::API_BASE . '/' . rawurlencode(IdentifierPolicy::opaqueKey($providerRefundId)), $account, []);
    }

    /** @param array<string, mixed> $options */
    private function request(string $method, string $url, ProviderAccount $account, array $options): ProviderRefund
    {
        $options['auth_basic'] = [$account->externalAccountId, $this->secrets->resolve($account->secretRef)];
        $options['timeout'] = 10.0;
        $options['max_duration'] = 15.0;
        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            if (in_array($status, [408, 409, 425, 429], true) || $status >= 500) {
                throw PaymentProviderException::uncertain('refund_provider_unavailable');
            }
            if ($status >= 400) {
                throw PaymentProviderException::definitive(match ($status) {
                    401, 403 => 'provider_authentication_failed',
                    400, 422 => 'invalid_refund_request',
                    default => 'refund_provider_rejected',
                });
            }
            $data = json_decode($response->getContent(false), true, 24, JSON_THROW_ON_ERROR);

            return $this->parse($data);
        } catch (PaymentProviderException $exception) {
            throw $exception;
        } catch (TransportExceptionInterface) {
            throw PaymentProviderException::uncertain('refund_provider_unavailable');
        } catch (\Throwable) {
            throw PaymentProviderException::uncertain('invalid_refund_provider_response');
        }
    }

    private function parse(mixed $data): ProviderRefund
    {
        if (!is_array($data) || array_is_list($data) || !is_string($data['id'] ?? null)
            || !is_string($data['payment_id'] ?? null) || !is_string($data['status'] ?? null)
            || !is_array($data['amount'] ?? null) || !isset($data['amount']['value']) || !is_string($data['amount']['currency'] ?? null)) {
            throw new \UnexpectedValueException('Invalid provider refund response.');
        }
        if (!in_array($data['status'], ['pending', 'succeeded', 'canceled'], true) || $data['amount']['currency'] !== 'RUB') {
            throw new \UnexpectedValueException('Unsupported provider refund state.');
        }
        $completedAt = null;
        if ($data['status'] !== 'pending') {
            $timestamp = $data['created_at'] ?? null;
            if (!is_string($timestamp) || preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $timestamp) !== 1) {
                throw new \UnexpectedValueException('Invalid provider refund timestamp.');
            }
            $completedAt = (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone('UTC'));
        }

        $localRefundId = null;
        if (is_string($data['description'] ?? null) && preg_match('/\ARefund ([0-9a-f-]{36})\z/D', $data['description'], $matches) === 1 && \Symfony\Component\Uid\Uuid::isValid($matches[1])) {
            $localRefundId = strtolower($matches[1]);
        }

        return new ProviderRefund(IdentifierPolicy::opaqueKey($data['id']), IdentifierPolicy::opaqueKey($data['payment_id']), $data['status'], self::parseMinor($data['amount']['value']), $data['amount']['currency'], $completedAt, $localRefundId);
    }

    private function validateAccount(ProviderAccount $account): void
    {
        if ($account->providerCode !== 'yookassa') {
            throw new \DomainException('Unsupported refund provider account.');
        }
        IdentifierPolicy::opaqueKey($account->externalAccountId);
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

    private static function formatMinor(int $amount): string
    {
        return intdiv($amount, 100) . '.' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
