<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class PaymentCreationTest extends PaymentTestCase
{
    public function testIssuedInvoiceCreatesServerDerivedPendingPaymentAndSafeReadModel(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedInvoiceContext('payment-create', 3);

        $created = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'payment-create-key', $this->uuid());

        self::assertSame($invoice['id'], $created['invoice_id']);
        self::assertSame('pending', $created['status']);
        self::assertSame('USD', $created['currency']);
        self::assertSame(3000, $created['amount_minor']);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'payment.created' AND resource_id = ?", [$created['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'payment.created' AND aggregate_id = ?", [$created['id']]));
        $view = $this->paymentReader()->get($owner, $account, $created['id']);
        self::assertSame([], $view['attempts']);
        self::assertArrayNotHasKey('provider_request_key', $view);
    }

    public function testCreationIsIdempotentNaturalDuplicateSafeAndAuthorized(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedInvoiceContext('payment-idempotency');
        $creator = $this->paymentCreator();
        $first = $creator->create($owner, $account, $invoice['id'], 'payment-same-key', $this->uuid());
        self::assertSame($first, $creator->create($owner, $account, $invoice['id'], 'payment-same-key', $this->uuid()));
        self::assertSame($first, $creator->create($owner, $account, $invoice['id'], 'payment-other-key', $this->uuid()));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payments WHERE invoice_id = ?', [$invoice['id']]));

        $technical = $this->user('payment-technical@example.test');
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'technical')", [$technical, $account]);
        try {
            $creator->create($technical, $account, $invoice['id'], 'payment-denied', $this->uuid());
            self::fail('Payment creation without billing.manage must be denied.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }

        [$otherOwner, $otherAccount, , , , , $otherInvoice] = $this->issuedInvoiceContext('payment-key-reuse');
        try {
            $creator->create($otherOwner, $otherAccount, $otherInvoice['id'], 'payment-same-key', $this->uuid());
            self::fail('A payment idempotency key must not cross resources.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
    }

    public function testVoidInvoiceCannotCreatePayment(): void
    {
        [$owner, $account, , , , $subscription, $invoice] = $this->issuedInvoiceContext('payment-void');
        (new \ControlPlane\Subscription\Application\CancelSubscription($this->connection, new \ControlPlane\Account\Authorization\AccountAuthorization($this->connection)))
            ->cancel($owner, $account, $subscription, 'payment-void-cancel', $this->uuid());

        try {
            $this->paymentCreator()->create($owner, $account, $invoice['id'], 'payment-void-create', $this->uuid());
            self::fail('A void invoice must not create a payment.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
    }
}
