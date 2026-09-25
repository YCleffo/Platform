<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class InvoiceIssuanceTest extends InvoiceTestCase
{
    public function testDraftSubscriptionProducesServerCalculatedIssuedSnapshot(): void
    {
        [$owner, $account, $profile, , , $subscription] = $this->invoiceContext('invoice-issue', 3);
        $this->connection->executeStatement("UPDATE commercial.billing_profiles SET legal_name = 'Old Company', contact_name = 'Billing Person', contact_email = 'billing@example.test', tax_identifier = 'TAX-1', address = '{\"city\":\"Old City\"}'::jsonb WHERE id = ?", [$profile]);

        $result = $this->issuer()->issue($owner, $account, $subscription, 'invoice-issue-once', $this->uuid());

        self::assertSame($subscription, $result['subscription_id']);
        self::assertSame('issued', $result['status']);
        self::assertSame('USD', $result['currency']);
        self::assertSame(3000, $result['total_minor']);
        self::assertNotSame('', $result['issued_at']);
        $line = $this->connection->fetchAssociative('SELECT * FROM commercial.invoice_lines WHERE invoice_id = ?', [$result['id']]);
        self::assertIsArray($line);
        self::assertSame(3, (int) $line['quantity']);
        self::assertSame(1000, (int) $line['unit_amount_minor']);
        self::assertSame(3000, (int) $line['total_minor']);
        self::assertSame('invoice-issue-plan', $line['plan_code']);
        self::assertSame(1, (int) $line['plan_version']);
        self::assertSame('Invoice-issue-plan', $line['plan_name']);
        self::assertSame('month', $line['billing_period']);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'invoice.issued' AND resource_id = ?", [$result['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'invoice.issued' AND aggregate_id = ?", [$result['id']]));
    }

    public function testSnapshotReadIsStableAuthorizedAndDeterministic(): void
    {
        [$owner, $account, $profile, , , $subscription] = $this->invoiceContext('invoice-snapshot');
        $this->connection->executeStatement("UPDATE commercial.billing_profiles SET legal_name = 'Old Company', address = '{\"city\":\"Old City\"}'::jsonb WHERE id = ?", [$profile]);
        $issued = $this->issuer()->issue($owner, $account, $subscription, 'invoice-snapshot-issue', $this->uuid());
        $this->connection->executeStatement("UPDATE commercial.billing_profiles SET legal_name = 'New Company', address = '{\"city\":\"New City\"}'::jsonb WHERE id = ?", [$profile]);

        $view = $this->invoiceReader()->get($owner, $account, $issued['id']);
        self::assertSame('Old Company', $view['bill_to']['legal_name']);
        self::assertSame('Old City', $view['bill_to']['address']->city);
        self::assertCount(1, $view['lines']);
        self::assertIsInt($view['total_minor']);
        self::assertIsInt($view['lines'][0]['unit_amount_minor']);

        $technical = $this->user('invoice-reader@example.test');
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'technical')", [$technical, $account]);
        self::assertSame($issued['id'], $this->invoiceReader()->get($technical, $account, $issued['id'])['id']);
        [$foreignOwner, $foreignAccount] = $this->readyAccount('invoice-read-foreign');
        try {
            $this->invoiceReader()->get($foreignOwner, $account, $issued['id']);
            self::fail('Foreign account invoice must not cross the membership boundary.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
        try {
            $this->invoiceReader()->get($foreignOwner, $foreignAccount, $issued['id']);
            self::fail('Invoice must be scoped to its commercial account.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
    }

    public function testIdempotencyNaturalDuplicateAndKeyReuseAreSafe(): void
    {
        [$owner, $account, , , , $subscription] = $this->invoiceContext('invoice-idempotency');
        $service = $this->issuer();
        $first = $service->issue($owner, $account, $subscription, 'invoice-same-key', $this->uuid());
        $retry = $service->issue($owner, $account, $subscription, 'invoice-same-key', $this->uuid());
        $natural = $service->issue($owner, $account, $subscription, 'invoice-other-key', $this->uuid());
        self::assertSame($first, $retry);
        self::assertSame($first, $natural);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.invoices WHERE subscription_id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.invoice_lines WHERE invoice_id = ?', [$first['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'invoice.issued' AND resource_id = ?", [$first['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'invoice.issued' AND aggregate_id = ?", [$first['id']]));

        [$otherOwner, $otherAccount, , , , $otherSubscription] = $this->invoiceContext('invoice-idempotency-other');
        try {
            $service->issue($otherOwner, $otherAccount, $otherSubscription, 'invoice-same-key', $this->uuid());
            self::fail('Idempotency key reuse for another subscription must fail.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.invoices WHERE subscription_id = ?', [$otherSubscription]));
    }

    public function testAuthorizationAccountBoundaryAndDraftStateAreEnforced(): void
    {
        [$owner, $account, , , , $subscription] = $this->invoiceContext('invoice-auth');
        $technical = $this->user('invoice-auth-technical@example.test');
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'technical')", [$technical, $account]);
        [$foreignOwner] = $this->readyAccount('invoice-auth-foreign');

        foreach ([$technical, $foreignOwner] as $index => $actor) {
            try {
                $this->issuer()->issue($actor, $account, $subscription, 'invoice-denied-' . $index, $this->uuid());
                self::fail('Invoice issuance without billing.manage must be denied.');
            } catch (AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }

        $this->activatePaid($account, $subscription, $this->activationInstant(), $this->uuid());
        try {
            $this->issuer()->issue($owner, $account, $subscription, 'invoice-active-denied', $this->uuid());
            self::fail('Initial invoice for an active subscription must be rejected.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.invoices WHERE subscription_id = ?', [$subscription]));
    }

    public function testIncompatibleTermsAndBigintOverflowFailWithoutPartialInvoice(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('invoice-mixed-currency');
        [, $usdMonth] = $this->planPrice('invoice-usd-month', 'month', 'USD');
        [, $eurMonth] = $this->planPrice('invoice-eur-month', 'month', 'EUR');
        $mixedCurrency = $this->draftSubscription($account, $profile, [$usdMonth, $eurMonth]);

        [$periodOwner, $periodAccount, $periodProfile] = $this->readyAccount('invoice-mixed-period');
        [, $periodMonth] = $this->planPrice('invoice-period-month', 'month', 'USD');
        [, $usdYear] = $this->planPrice('invoice-usd-year', 'year', 'USD');
        $mixedPeriod = $this->draftSubscription($periodAccount, $periodProfile, [$periodMonth, $usdYear]);
        foreach ([[$owner, $account, $mixedCurrency, 'invoice-mixed-currency'], [$periodOwner, $periodAccount, $mixedPeriod, 'invoice-mixed-period']] as [$actor, $invoiceAccount, $subscription, $key]) {
            try {
                $this->issuer()->issue($actor, $invoiceAccount, $subscription, $key, $this->uuid());
                self::fail('An incompatible invoice contract must be rejected.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.invoices WHERE subscription_id = ?', [$subscription]));
        }

        [$overflowOwner, $overflowAccount, $overflowProfile] = $this->readyAccount('invoice-overflow');
        $plan = $this->connection->fetchOne("INSERT INTO commercial.plans (code, version, name) VALUES ('invoice-overflow-plan', 1, 'Overflow') RETURNING id");
        self::assertIsString($plan);
        $price = $this->connection->fetchOne("INSERT INTO commercial.plan_prices (plan_id, currency, amount_minor, billing_period) VALUES (?, 'USD', 9223372036854775807, 'month') RETURNING id", [$plan]);
        self::assertIsString($price);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        $overflow = $this->draftSubscription($overflowAccount, $overflowProfile, [$price], 2);
        try {
            $this->issuer()->issue($overflowOwner, $overflowAccount, $overflow, 'invoice-overflow', $this->uuid());
            self::fail('A bigint overflow must fail as a domain error.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.invoices WHERE subscription_id = ?', [$overflow]));
    }
}
