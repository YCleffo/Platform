<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Support;

use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\PaymentProviderGateway;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderPayment;
use ControlPlane\Payment\Provider\ProviderPaymentRequest;

final class FakePaymentProviderGateway implements PaymentProviderGateway
{
    /** @var list<array{ProviderAccount, ProviderPaymentRequest}> */
    public array $createCalls = [];

    /** @var list<array{ProviderAccount, string}> */
    public array $getCalls = [];

    public ?PaymentProviderException $createFailure = null;

    /** @var array<string, ProviderPayment|PaymentProviderException> */
    public array $payments = [];

    public function createPayment(ProviderAccount $account, ProviderPaymentRequest $request): ProviderPayment
    {
        $this->createCalls[] = [$account, $request];
        if ($this->createFailure !== null) {
            $failure = $this->createFailure;
            $this->createFailure = null;
            throw $failure;
        }
        $payment = new ProviderPayment(
            'yk-' . str_replace('-', '', $request->attemptId),
            'pending',
            false,
            $request->amountMinor,
            $request->currency,
            ['payment_id' => $request->paymentId, 'attempt_id' => $request->attemptId, 'invoice_id' => $request->invoiceId],
            'https://yookassa.test/confirmation/' . $request->attemptId,
            null,
        );
        $this->payments[$payment->id] = $payment;

        return $payment;
    }

    public function getPayment(ProviderAccount $account, string $providerPaymentId): ProviderPayment
    {
        $this->getCalls[] = [$account, $providerPaymentId];
        $result = $this->payments[$providerPaymentId] ?? null;
        if ($result instanceof PaymentProviderException) {
            throw $result;
        }
        if (!$result instanceof ProviderPayment) {
            throw PaymentProviderException::uncertain('provider_payment_unavailable');
        }

        return $result;
    }
}
