<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\GetBillingState;
use ControlPlane\Billing\Application\IssueInvoice;
use ControlPlane\Payment\Application\CreatePayment;
use ControlPlane\Payment\Application\SettlePayment;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class BillingStateTest extends SubscriptionTestCase
{
    public function testRestoresDraftInvoicePaymentAndProfileWithPaginatedScopedHistory(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('billing-state');
        [, $price] = $this->planPrice('billing-state-price');
        $sub = $this->draftSubscription($account, $profile, [$price]);
        $auth = new AccountAuthorization($this->connection);
        $invoice = (new IssueInvoice($this->connection, $auth))->issue($owner, $account, $sub, 'state-invoice', $this->uuid());
        $payment = (new CreatePayment($this->connection, $auth, new SettlePayment($this->activator())))->create($owner, $account, $invoice['id'], 'state-payment', $this->uuid());
        $reader = new GetBillingState($this->connection, $auth);
        $state = $reader->get($owner, $account, 1);
        self::assertSame($sub, $state['subscription_id']);
        self::assertSame($invoice['id'], $state['pending_invoice_id']); self::assertSame($payment['id'], $state['pending_payment_id']);
        self::assertSame('Example LLC', $state['billing_profile']['legal_name']);
        self::assertSame(2, $state['history']['total']); self::assertCount(1, $state['history']['items']);
        self::assertNotSame($state['history']['items'][0]['id'], $reader->get($owner, $account, 1, 1)['history']['items'][0]['id']);
        self::assertSame(1, $reader->get($owner, $account, 25, 0, substr($payment['id'], -8))['history']['total']);
        [$foreignOwner, $foreignAccount] = $this->readyAccount('billing-state-foreign');
        self::assertSame(0, $reader->get($foreignOwner, $foreignAccount)['history']['total']);
        $this->expectException(AccessDeniedException::class); $reader->get($foreignOwner, $account);
    }

    public function testRejectsUnboundedOrMalformedHistoryQuery(): void
    {
        [$owner, $account] = $this->readyAccount('billing-state-invalid');
        $reader = new GetBillingState($this->connection, new AccountAuthorization($this->connection));
        foreach ([[101, 0, ''], [25, -1, ''], [25, 0, '%'], [25, 0, 'DROP TABLE']] as [$limit, $offset, $query]) {
            try { $reader->get($owner, $account, $limit, $offset, $query); self::fail('Invalid query must be rejected.'); }
            catch (\InvalidArgumentException) { self::addToAssertionCount(1); }
        }
    }
}
