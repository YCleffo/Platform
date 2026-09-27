<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Support;

use ControlPlane\Payment\Provider\FiscalReceiptProviderGateway;
use ControlPlane\Payment\Provider\FiscalReceiptRequest;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderReceipt;

final class FakeFiscalReceiptProviderGateway implements FiscalReceiptProviderGateway
{
    /** @var list<array{ProviderAccount, FiscalReceiptRequest}> */
    public array $createCalls = [];
    /** @var list<array{ProviderAccount, string}> */
    public array $getCalls = [];
    public ?PaymentProviderException $createFailure = null;
    /** @var array<string, ProviderReceipt|PaymentProviderException> */
    public array $receipts = [];

    public function createReceipt(ProviderAccount $account, FiscalReceiptRequest $request): ProviderReceipt
    {
        $this->createCalls[] = [$account, $request];
        if ($this->createFailure !== null) {
            $failure = $this->createFailure;
            $this->createFailure = null;
            throw $failure;
        }
        $receipt = new ProviderReceipt('yr-' . str_replace('-', '', $request->providerRequestKey), $request->receiptType, $request->providerPaymentId, 'pending', $request->amountMinor, $request->currency, $request->items, null, $request->providerRefundId);
        $this->receipts[$receipt->id] = $receipt;

        return $receipt;
    }

    public function getReceipt(ProviderAccount $account, string $providerReceiptId): ProviderReceipt
    {
        $this->getCalls[] = [$account, $providerReceiptId];
        $receipt = $this->receipts[$providerReceiptId] ?? null;
        if ($receipt instanceof PaymentProviderException) {
            throw $receipt;
        }
        if (!$receipt instanceof ProviderReceipt) {
            throw PaymentProviderException::uncertain('provider_receipt_unavailable');
        }

        return $receipt;
    }
}
