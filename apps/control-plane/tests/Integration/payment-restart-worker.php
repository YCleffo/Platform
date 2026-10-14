<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Fiscalization\Application\ApplyAuthoritativeReceipt;
use ControlPlane\Fiscalization\Application\CreatePaymentReceiptObligation;
use ControlPlane\Fiscalization\Application\ReceiptSnapshot;
use ControlPlane\Fiscalization\Application\SubmitYooKassaReceipt;
use ControlPlane\Payment\Application\BeginPaymentAttempt;
use ControlPlane\Payment\Application\BindPaymentAttemptProviderReference;
use ControlPlane\Payment\Application\CompletePaymentAttempt;
use ControlPlane\Payment\Application\ProcessAuthoritativeProviderPayment;
use ControlPlane\Payment\Application\RecordSavedPaymentMethod;
use ControlPlane\Payment\Application\SettlePayment;
use ControlPlane\Payment\Application\StartYooKassaPayment;
use ControlPlane\Payment\Provider\PaymentProviderGateway;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderPayment;
use ControlPlane\Payment\Provider\ProviderPaymentRequest;
use ControlPlane\Payment\Provider\YooKassa\YooKassaProviderAccountLocator;
use ControlPlane\Subscription\Application\ActivateSubscription;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use ControlPlane\Tests\Support\FakeFiscalReceiptProviderGateway;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

$url = getenv('CONTROL_DATABASE_URL');
$parts = is_string($url) ? parse_url($url) : false;
if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1'
    || !is_array($parts) || ($parts['host'] ?? '') !== 'control-postgres-test'
    || ($parts['path'] ?? '') !== '/platform_commercial_test' || $argc !== 8
    || !in_array($argv[7], ['kill_after_success', 'recover'], true)) {
    exit(3);
}
[, $userId, $accountId, $paymentId, $idempotencyKey, $statePath, $correlationId, $mode] = $argv;
if (!is_file($statePath)) {
    exit(3);
}
$connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
$gateway = new class($statePath, $mode) implements PaymentProviderGateway {
    public function __construct(private string $path, private string $mode) {}

    public function createPayment(ProviderAccount $account, ProviderPaymentRequest $request): ProviderPayment
    {
        $state = json_decode((string) file_get_contents($this->path), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($state) || !isset($state['calls']) || !is_array($state['calls'])) {
            throw new LogicException('Payment fault state is invalid.');
        }
        $state['calls'][] = $request->providerRequestKey;
        if (!isset($state['provider_payment_id'])) {
            $state['provider_payment_id'] = 'yk-' . str_replace('-', '', $request->attemptId);
            $state['payment_id'] = $request->paymentId;
            $state['attempt_id'] = $request->attemptId;
            $state['invoice_id'] = $request->invoiceId;
            $state['amount_minor'] = $request->amountMinor;
            $state['currency'] = $request->currency;
        } elseif ($state['calls'][0] !== $request->providerRequestKey
            || $state['payment_id'] !== $request->paymentId
            || $state['attempt_id'] !== $request->attemptId
            || $state['invoice_id'] !== $request->invoiceId
            || $state['amount_minor'] !== $request->amountMinor
            || $state['currency'] !== $request->currency) {
            throw new LogicException('Retry changed the provider payment contract.');
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
        return $this->payment($state);
    }

    public function getPayment(ProviderAccount $account, string $providerPaymentId): ProviderPayment
    {
        $state = json_decode((string) file_get_contents($this->path), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['provider_payment_id'] ?? null) !== $providerPaymentId) {
            throw new LogicException('Provider payment is unavailable.');
        }
        return $this->payment($state);
    }

    /** @param array<string, mixed> $state */
    private function payment(array $state): ProviderPayment
    {
        return new ProviderPayment((string) $state['provider_payment_id'], 'succeeded', true,
            (int) $state['amount_minor'], (string) $state['currency'],
            ['payment_id' => $state['payment_id'], 'attempt_id' => $state['attempt_id'],
                'invoice_id' => $state['invoice_id']], null, new DateTimeImmutable('now'));
    }
};
$binder = new BindPaymentAttemptProviderReference($connection);
$completer = new CompletePaymentAttempt($connection,
    new SettlePayment(new ActivateSubscription($connection, new SubscriptionPeriodCalculator())),
    new CreatePaymentReceiptObligation());
$processor = new ProcessAuthoritativeProviderPayment($connection, $binder, $completer,
    new SubmitYooKassaReceipt($connection, new ReceiptSnapshot(), new FakeFiscalReceiptProviderGateway(),
        new ApplyAuthoritativeReceipt($connection)), new RecordSavedPaymentMethod($connection));
(new StartYooKassaPayment($connection, new AccountAuthorization($connection),
    new YooKassaProviderAccountLocator($connection), new BeginPaymentAttempt($connection),
    $binder, $completer, $processor, $gateway))->start($userId, $accountId, $paymentId,
        'yookassa', 'sbp', false, $idempotencyKey, $correlationId);
