<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Entitlement\Application\EffectiveEntitlements;
use ControlPlane\Subscription\Application\DisableSubscriptionAutoRenewal;
use ControlPlane\Subscription\Application\ExpireSubscription;
use ControlPlane\Subscription\Application\GetSubscription;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SubscriptionAutoRenewCancellationTest extends RenewalTestCase
{
    public function testDisablingStopsFutureInvoicesButKeepsRightsUntilPaidPeriodEnd(): void
    {
        [$owner, $account, $subscription] = $this->futureActive('auto-renew-end');
        $before = $this->entitlements()->forAccount($owner, $account);
        self::assertNotNull($before['subscription']);
        $service = $this->disabler();
        $result = $service->disable($owner, $account, $subscription, 'auto-renew-end', $this->uuid());
        self::assertSame(['id' => $subscription, 'auto_renew_enabled' => false], $result);
        self::assertSame($result, $service->disable($owner, $account, $subscription, 'auto-renew-end', $this->uuid()));
        self::assertSame($result, $service->disable($owner, $account, $subscription, 'auto-renew-end-new-key', $this->uuid()));
        self::assertSame(false, (new GetSubscription($this->connection, new AccountAuthorization($this->connection)))->get($owner, $account, $subscription)['auto_renew_enabled']);
        $this->renewalIssuer()->prepareDue(new \DateTimeImmutable('now +2 months'), 0, 10, $this->uuid());
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.invoices WHERE subscription_id=? AND billing_reason='subscription_renewal'", [$subscription]));
        try {
            $this->renewalIssuer()->issue($account, $subscription, $this->uuid());
            self::fail('A direct issuer call must respect the customer choice.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertNotNull($this->entitlements()->forAccount($owner, $account)['subscription']);
        $end = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT current_period_end FROM commercial.subscriptions WHERE id=?', [$subscription]));
        self::assertGreaterThanOrEqual(1, (new ExpireSubscription($this->connection, null, 900))->expireDue($end, 100, $this->uuid()));
        self::assertSame('expired', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id=?', [$subscription]));
        self::assertSame($result, $service->disable($owner, $account, $subscription, 'auto-renew-end', $this->uuid()));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action='subscription.auto_renew_disabled' AND resource_id=?", [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.auto_renew_disabled' AND aggregate_id=?", [$subscription]));
        self::assertNull($this->entitlements()->forAccount($owner, $account)['subscription']);
    }

    public function testUnpaidIssuedRenewalIsVoidedWithoutLosingCurrentRights(): void
    {
        [$owner, $account, $subscription] = $this->futureActive('auto-renew-void');
        $invoice = $this->renewalIssuer()->issue($account, $subscription, $this->uuid());
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'auto-renew-payment', $this->uuid());
        $this->connection->executeStatement("INSERT INTO commercial.renewal_collection_states (invoice_id, commercial_account_id, subscription_id, state) VALUES (?, ?, ?, 'ready')", [$invoice['id'], $account, $subscription]);

        $this->disabler()->disable($owner, $account, $subscription, 'auto-renew-void', $this->uuid());
        self::assertSame('void', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoice['id']]));
        self::assertSame('cancelled', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
        self::assertSame('exhausted', $this->connection->fetchOne('SELECT state FROM commercial.renewal_collection_states WHERE invoice_id=?', [$invoice['id']]));
        self::assertNotNull($this->entitlements()->forAccount($owner, $account)['subscription']);
        $this->renewalIssuer()->prepareDue(new \DateTimeImmutable('now +2 months'), 0, 10, $this->uuid());
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.invoices WHERE subscription_id=? AND billing_reason='subscription_renewal'", [$subscription]));
    }

    public function testPendingProviderAttemptMustBeReconciledBeforeDisabling(): void
    {
        [$owner, $account, $subscription] = $this->futureActive('auto-renew-pending');
        $invoice = $this->renewalIssuer()->issue($account, $subscription, $this->uuid());
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'auto-renew-pending-payment', $this->uuid());
        $attempt = $this->attemptBeginner()->begin($payment['id'], $this->providerAccount('auto-renew-pending'), 'bank_transfer', $this->uuid());

        try {
            $this->disabler()->disable($owner, $account, $subscription, 'auto-renew-pending', $this->uuid());
            self::fail('An unresolved provider attempt must block a successful stop response.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('issued', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoice['id']]));
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id=?', [$payment['id']]));
        self::assertTrue((new GetSubscription($this->connection, new AccountAuthorization($this->connection)))->get($owner, $account, $subscription)['auto_renew_enabled']);
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'failed', 'customer_cancelled', new \DateTimeImmutable('now'), $this->uuid());
        $this->disabler()->disable($owner, $account, $subscription, 'auto-renew-pending', $this->uuid());
        self::assertSame('void', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id=?', [$invoice['id']]));
    }

    public function testPaidRenewalExtendsAccessThenExpiresWithoutAnotherCharge(): void
    {
        [$owner, $account, $subscription] = $this->futureActive('auto-renew-paid');
        $invoice = $this->renewalIssuer()->issue($account, $subscription, $this->uuid());
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'auto-renew-paid-payment', $this->uuid());
        $attempt = $this->attemptBeginner()->begin($payment['id'], $this->providerAccount('auto-renew-paid'), 'bank_transfer', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'auto-renew-paid-provider-payment');
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('now'), $this->uuid());

        $this->disabler()->disable($owner, $account, $subscription, 'auto-renew-paid', $this->uuid());
        $firstEnd = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT current_period_end FROM commercial.subscriptions WHERE id=?', [$subscription]));
        self::assertSame('active', (new ExpireSubscription($this->connection, $this->advancement(), 900))->expire($account, $subscription, $firstEnd, $this->uuid())['status']);
        $this->renewalIssuer()->prepareDue($firstEnd, 40, 10, $this->uuid());
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.invoices WHERE subscription_id=? AND billing_reason='subscription_renewal'", [$subscription]));
        $lastEnd = new \DateTimeImmutable((string) $this->connection->fetchOne('SELECT current_period_end FROM commercial.subscriptions WHERE id=?', [$subscription]));
        self::assertSame('expired', (new ExpireSubscription($this->connection, null, 900))->expire($account, $subscription, $lastEnd, $this->uuid())['status']);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.invoices WHERE subscription_id=? AND billing_reason='subscription_renewal' AND status='paid'", [$subscription]));
    }

    public function testBillingPermissionIsRequired(): void
    {
        [$owner, $account, $subscription] = $this->futureActive('auto-renew-auth');
        $technical = $this->user('auto-renew-technical@example.test');
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'technical')", [$technical, $account]);
        [$foreignOwner] = $this->readyAccount('auto-renew-foreign');
        foreach ([$technical, $foreignOwner] as $index => $actor) {
            try {
                $this->disabler()->disable($actor, $account, $subscription, 'auto-renew-denied-' . $index, $this->uuid());
                self::fail('A non-billing or foreign actor must not change renewal preference.');
            } catch (AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertTrue((new GetSubscription($this->connection, new AccountAuthorization($this->connection)))->get(
            $owner, $account, $subscription,
        )['auto_renew_enabled']);
    }

    /** @return array{string, string, string} */
    private function futureActive(string $suffix): array
    {
        [$owner, $account, $profile] = $this->readyAccount($suffix);
        $feature = $this->feature($suffix . '.feature', 'boolean');
        [, $price] = $this->planPrice($suffix . '-plan', featureValues: [$feature => true]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $this->activatePaid($account, $subscription, $this->activationInstant(), $this->uuid());
        return [$owner, $account, $subscription];
    }

    private function disabler(): DisableSubscriptionAutoRenewal
    {
        return new DisableSubscriptionAutoRenewal($this->connection, new AccountAuthorization($this->connection));
    }

    private function entitlements(): EffectiveEntitlements
    {
        return new EffectiveEntitlements($this->connection, new AccountAuthorization($this->connection));
    }
}
