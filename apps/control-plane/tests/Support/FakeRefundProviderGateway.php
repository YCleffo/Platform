<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Support;

use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderRefund;
use ControlPlane\Payment\Provider\RefundProviderGateway;
use ControlPlane\Payment\Provider\RefundProviderRequest;

final class FakeRefundProviderGateway implements RefundProviderGateway
{
    /** @var list<array{ProviderAccount, RefundProviderRequest}> */
    public array $createCalls = [];
    /** @var list<array{ProviderAccount, string}> */
    public array $getCalls = [];
    /** @var array<string, ProviderRefund|PaymentProviderException> */
    public array $refunds = [];
    public ?PaymentProviderException $createFailure = null;

    public function createRefund(ProviderAccount $account, RefundProviderRequest $request): ProviderRefund
    {
        $this->createCalls[] = [$account, $request];
        if ($this->createFailure !== null) {
            $failure = $this->createFailure;
            $this->createFailure = null;
            throw $failure;
        }
        $refund = new ProviderRefund('yk-refund-' . str_replace('-', '', $request->refundId), $request->providerPaymentId, 'pending', $request->amountMinor, $request->currency, null, $request->refundId);
        $this->refunds[$refund->id] = $refund;

        return $refund;
    }

    public function getRefund(ProviderAccount $account, string $providerRefundId): ProviderRefund
    {
        $this->getCalls[] = [$account, $providerRefundId];
        $refund = $this->refunds[$providerRefundId] ?? null;
        if ($refund instanceof PaymentProviderException) {
            throw $refund;
        }
        if (!$refund instanceof ProviderRefund) {
            throw PaymentProviderException::uncertain('provider_refund_unavailable');
        }

        return $refund;
    }
}
