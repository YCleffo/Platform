<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use ControlPlane\Fiscalization\Application\ApplyAuthoritativeReceipt;
use ControlPlane\Fiscalization\Application\CreateRefundReceiptObligation;
use ControlPlane\Fiscalization\Application\ReceiptSnapshot;
use ControlPlane\Fiscalization\Application\SubmitYooKassaReceipt;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderRefund;
use ControlPlane\Payment\Provider\RefundProviderGateway;
use ControlPlane\Payment\Provider\RefundProviderRequest;
use ControlPlane\Refund\Application\ApplyAuthoritativeRefund;
use ControlPlane\Refund\Application\RefundSnapshot;
use ControlPlane\Refund\Application\SubmitYooKassaRefund;
use ControlPlane\Tests\Support\FakeFiscalReceiptProviderGateway;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

$url = getenv('CONTROL_DATABASE_URL');
$parts = is_string($url) ? parse_url($url) : false;
if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1'
    || !is_array($parts) || ($parts['host'] ?? '') !== 'control-postgres-test'
    || ($parts['path'] ?? '') !== '/platform_commercial_test') {
    exit(3);
}
if ($argc !== 5 || !in_array($argv[3], ['lose_response', 'kill_after_success', 'recover'], true)) {
    exit(3);
}
[, $refundId, $statePath, $mode, $correlationId] = $argv;
if (!is_file($statePath)) {
    exit(3);
}
$connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
$gateway = new class($statePath, $mode) implements RefundProviderGateway {
    public function __construct(private string $path, private string $mode) {}

    public function createRefund(ProviderAccount $account, RefundProviderRequest $request): ProviderRefund
    {
        $state = json_decode((string) file_get_contents($this->path), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($state) || !isset($state['calls']) || !is_array($state['calls'])) {
            throw new LogicException('Provider fault state is invalid.');
        }
        $state['calls'][] = $request->providerRequestKey;
        if (!isset($state['provider_refund_id'])) {
            $state['provider_refund_id'] = 'yk-refund-' . str_replace('-', '', $request->refundId);
            $state['payment_id'] = $request->providerPaymentId;
            $state['amount_minor'] = $request->amountMinor;
            $state['currency'] = $request->currency;
            $state['local_refund_id'] = $request->refundId;
        } elseif ($state['calls'][0] !== $request->providerRequestKey
            || $state['payment_id'] !== $request->providerPaymentId
            || $state['amount_minor'] !== $request->amountMinor
            || $state['currency'] !== $request->currency
            || $state['local_refund_id'] !== $request->refundId) {
            throw new LogicException('Retry changed the provider refund contract.');
        }
        file_put_contents($this->path, json_encode($state, JSON_THROW_ON_ERROR));
        if ($this->mode === 'kill_after_success') {
            $pid = getmypid();
            if (!is_int($pid)) {
                throw new RuntimeException('Worker process ID is unavailable.');
            }
            posix_kill($pid, 9);
            exit(3);
        }
        if ($this->mode === 'lose_response') {
            throw PaymentProviderException::uncertain('lost_refund_response');
        }
        return $this->refund($state);
    }

    public function getRefund(ProviderAccount $account, string $providerRefundId): ProviderRefund
    {
        $state = json_decode((string) file_get_contents($this->path), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['provider_refund_id'] ?? null) !== $providerRefundId) {
            throw PaymentProviderException::uncertain('provider_refund_unavailable');
        }
        return $this->refund($state);
    }

    /** @param array<string, mixed> $state */
    private function refund(array $state): ProviderRefund
    {
        return new ProviderRefund((string) $state['provider_refund_id'], (string) $state['payment_id'],
            'succeeded', (int) $state['amount_minor'], (string) $state['currency'],
            new DateTimeImmutable('now'), (string) $state['local_refund_id']);
    }
};
$submission = new SubmitYooKassaRefund($connection, new RefundSnapshot(), $gateway,
    new ApplyAuthoritativeRefund($connection, new CreateRefundReceiptObligation()),
    new SubmitYooKassaReceipt($connection, new ReceiptSnapshot(), new FakeFiscalReceiptProviderGateway(),
        new ApplyAuthoritativeReceipt($connection)));
try {
    $submission->submit($refundId, $correlationId);
    exit(0);
} catch (PaymentProviderException $exception) {
    fwrite(STDERR, $exception->errorCode . PHP_EOL);
    exit(2);
}
