<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider\YooKassa;

use ControlPlane\Billing\Domain\Money;
use ControlPlane\Privacy\ControlProviderEgressPolicy;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\PaymentProviderGateway;
use ControlPlane\Payment\Provider\PaymentProviderSecretResolver;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderPayment;
use ControlPlane\Payment\Provider\ProviderPaymentRequest;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class YooKassaGateway implements PaymentProviderGateway
{
    private const API_BASE = 'https://api.yookassa.ru/v3';

    public function __construct(
        private HttpClientInterface $httpClient,
        private PaymentProviderSecretResolver $secrets,
        private string $returnUrl,
        private ControlProviderEgressPolicy $egress,
    ) {
        $parts = parse_url($returnUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('The YooKassa return URL must be absolute.');
        }
    }

    public function createPayment(ProviderAccount $account, ProviderPaymentRequest $request): ProviderPayment
    {
        $this->validateAccount($account);
        if ($request->currency !== 'RUB') {
            throw new \DomainException('YooKassa SBP supports RUB only.');
        }
        if ($request->amountMinor <= 0) {
            throw new \DomainException('YooKassa checkout requires a positive payment amount.');
        }
        $requestKey = IdentifierPolicy::opaqueKey($request->providerRequestKey);
        $payload = [
            'amount' => ['value' => self::formatMinor($request->amountMinor), 'currency' => 'RUB'],
            'capture' => true,
            'payment_method_data' => ['type' => 'sbp'],
            'confirmation' => ['type' => 'redirect', 'return_url' => $this->returnUrl],
            'description' => $request->description,
            'metadata' => [
                'payment_id' => $request->paymentId,
                'attempt_id' => $request->attemptId,
                'invoice_id' => $request->invoiceId,
            ],
        ];

        return $this->request('POST', self::API_BASE . '/payments', $account, [
            'headers' => ['Idempotence-Key' => $requestKey],
            'json' => $payload,
        ], true);
    }

    public function getPayment(ProviderAccount $account, string $providerPaymentId): ProviderPayment
    {
        $this->validateAccount($account);
        $providerPaymentId = IdentifierPolicy::opaqueKey($providerPaymentId);

        return $this->request('GET', self::API_BASE . '/payments/' . rawurlencode($providerPaymentId), $account, [], false);
    }

    /** @param array<string, mixed> $options */
    private function request(string $method, string $url, ProviderAccount $account, array $options, bool $create): ProviderPayment
    {
        $this->egress->assertAllowed('yookassa', 'payment', ['payment_reference', 'billing_contact']);
        $options['auth_basic'] = [$account->externalAccountId, $this->secrets->resolve($account->secretRef)];
        $options['timeout'] = 10.0;
        $options['max_duration'] = 15.0;
        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            if (in_array($status, [408, 409, 425, 429], true)) {
                throw PaymentProviderException::uncertain();
            }
            if ($status >= 400 && $status < 500) {
                throw PaymentProviderException::definitive(match ($status) {
                    401, 403 => 'provider_authentication_failed',
                    400, 422 => 'invalid_provider_request',
                    default => 'provider_rejected',
                });
            }
            if ($status < 200 || $status >= 300) {
                throw PaymentProviderException::uncertain();
            }
            $body = $response->getContent(false);
        } catch (PaymentProviderException $exception) {
            throw $exception;
        } catch (TransportExceptionInterface) {
            throw PaymentProviderException::uncertain();
        }

        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

            return $this->parsePayment($data, $create);
        } catch (PaymentProviderException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw PaymentProviderException::uncertain('invalid_provider_response');
        }
    }

    private function validateAccount(ProviderAccount $account): void
    {
        if ($account->providerCode !== 'yookassa') {
            throw new \DomainException('Unsupported payment provider account.');
        }
        IdentifierPolicy::opaqueKey($account->externalAccountId);
    }

    private static function formatMinor(int $amountMinor): string
    {
        return intdiv($amountMinor, 100) . '.' . str_pad((string) ($amountMinor % 100), 2, '0', STR_PAD_LEFT);
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

    /** @return array<string, string> */
    private static function metadata(mixed $metadata): array
    {
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new \UnexpectedValueException('Invalid provider metadata.');
        }
        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \UnexpectedValueException('Invalid provider metadata.');
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function parsePayment(mixed $data, bool $create): ProviderPayment
    {
        if (!is_array($data) || array_is_list($data) || !is_string($data['id'] ?? null) || !is_string($data['status'] ?? null)
            || !is_bool($data['paid'] ?? null) || !is_array($data['amount'] ?? null)
            || !is_string($data['amount']['currency'] ?? null) || !array_key_exists('value', $data['amount'])
            || !array_key_exists('metadata', $data)) {
            throw new \UnexpectedValueException('Invalid provider payment response.');
        }
        $id = IdentifierPolicy::opaqueKey($data['id']);
        $status = $data['status'];
        if (!in_array($status, ['pending', 'waiting_for_capture', 'succeeded', 'canceled'], true)) {
            throw new \UnexpectedValueException('Unsupported provider payment status.');
        }
        $currency = $data['amount']['currency'];
        if ($currency !== 'RUB') {
            throw new \UnexpectedValueException('Unsupported provider currency.');
        }
        $confirmationUrl = null;
        if (isset($data['confirmation'])) {
            if (!is_array($data['confirmation']) || ($data['confirmation']['type'] ?? null) !== 'redirect'
                || !is_string($data['confirmation']['confirmation_url'] ?? null)) {
                throw new \UnexpectedValueException('Invalid provider confirmation.');
            }
            $confirmationUrl = $data['confirmation']['confirmation_url'];
            $parts = parse_url($confirmationUrl);
            if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || !isset($parts['host'])) {
                throw new \UnexpectedValueException('Invalid provider confirmation URL.');
            }
        }
        if (($create || $status === 'pending') && $status === 'pending' && $confirmationUrl === null) {
            throw new \UnexpectedValueException('Pending provider payment has no confirmation URL.');
        }
        $capturedAt = null;
        if (isset($data['captured_at'])) {
            if (!is_string($data['captured_at']) || preg_match('/(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $data['captured_at']) !== 1) {
                throw new \UnexpectedValueException('Invalid provider settlement timestamp.');
            }
            $capturedAt = (new \DateTimeImmutable($data['captured_at']))->setTimezone(new \DateTimeZone('UTC'));
        }
        if ($status === 'succeeded' && ($data['paid'] !== true || $capturedAt === null)) {
            throw new \UnexpectedValueException('Successful provider payment is incomplete.');
        }

        return new ProviderPayment(
            $id,
            $status,
            $data['paid'],
            self::parseMinor($data['amount']['value']),
            $currency,
            self::metadata($data['metadata']),
            $confirmationUrl,
            $capturedAt,
        );
    }
}
