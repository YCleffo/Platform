<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Subscription\Application\CancelSubscription;
use ControlPlane\Usage\Application\ApplyUsageDelta;
use ControlPlane\Usage\Application\CurrentUsage;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class UsageAccountingTest extends SubscriptionTestCase
{
    public function testCounterGaugeHardQuotaAndIdempotency(): void
    {
        [, $account, $profile] = $this->readyAccount('usage-accounting');
        $counter = $this->feature('usage.messages.monthly', 'integer', 'counter');
        $gauge = $this->feature('usage.students.max', 'integer', 'gauge');
        $bigint = $this->feature('usage.bigint.max', 'integer', 'gauge');
        [, $price] = $this->planPrice('usage-accounting-plan', featureValues: [$counter => 15, $gauge => 10, $bigint => PHP_INT_MAX]);
        $subscription = $this->draftSubscription($account, $profile, [$price], 5);
        $this->activator()->activate($account, $subscription, $this->activationInstant(), $this->uuid());
        $service = new ApplyUsageDelta($this->connection);

        self::assertSame(10, $service->apply($account, 'usage.messages.monthly', 10, 'usage-counter-10')['used']);
        self::assertSame(15, $service->apply($account, 'usage.messages.monthly', 5, 'usage-counter-5')['used']);
        $this->expectDomainRejection(fn () => $service->apply($account, 'usage.messages.monthly', -1, 'usage-counter-negative'));
        $this->expectDomainRejection(fn () => $service->apply($account, 'usage.messages.monthly', 1, 'usage-counter-over-limit'));

        self::assertSame(10, $service->apply($account, 'usage.students.max', 10, 'usage-gauge-up')['used']);
        self::assertSame(7, $service->apply($account, 'usage.students.max', -3, 'usage-gauge-down')['used']);
        $this->expectDomainRejection(fn () => $service->apply($account, 'usage.students.max', -8, 'usage-gauge-negative'));

        $first = $service->apply($account, 'usage.students.max', 1, 'usage-idempotent');
        $retry = $service->apply($account, 'usage.students.max', 1, 'usage-idempotent');
        self::assertSame($first, $retry);
        self::assertSame(8, $retry['used']);
        $this->expectDomainRejection(fn () => $service->apply($account, 'usage.students.max', 2, 'usage-idempotent'));
        self::assertNull($this->connection->fetchOne("SELECT expires_at FROM commercial.idempotency_keys WHERE idempotency_key = 'usage-idempotent'"));
        self::assertSame(8, (int) $this->connection->fetchOne('SELECT quantity FROM commercial.usage_counters uc JOIN commercial.features f ON f.id = uc.feature_id WHERE f.code = ?', ['usage.students.max']));

        $maximum = $service->apply($account, 'usage.bigint.max', PHP_INT_MAX, 'usage-bigint-maximum');
        self::assertSame(PHP_INT_MAX, $maximum['used']);
        self::assertTrue($maximum['exhausted']);
        $this->expectDomainRejection(fn () => $service->apply($account, 'usage.bigint.max', 1, 'usage-bigint-overflow'));
        try {
            $service->apply($account, 'usage.students.max', 0, 'usage-zero-delta');
            self::fail('Zero delta must be rejected.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        try {
            $service->apply($account, 'Students.Max', 1, 'usage-invalid-code');
            self::fail('Non-canonical feature code must be rejected.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action LIKE 'usage.%'"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type LIKE 'usage.%'"));
    }

    public function testNonMeteredAndUnentitledFeaturesCannotBeMutated(): void
    {
        [, $account, $profile] = $this->readyAccount('usage-rejections');
        $plain = $this->feature('usage.retention.days', 'integer');
        $foreign = $this->feature('usage.foreign.max', 'integer', 'gauge');
        [, $price] = $this->planPrice('usage-rejections-plan', featureValues: [$plain => 365]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $this->activator()->activate($account, $subscription, $this->activationInstant(), $this->uuid());
        $service = new ApplyUsageDelta($this->connection);

        $this->expectDomainRejection(fn () => $service->apply($account, 'usage.retention.days', 1, 'usage-plain'));
        $this->expectDomainRejection(fn () => $service->apply($account, 'usage.foreign.max', 1, 'usage-foreign'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.usage_counters uc JOIN commercial.usage_periods up ON up.id = uc.usage_period_id WHERE up.subscription_id = ?', [$subscription]));
    }

    public function testCurrentUsageIsStableCompleteAndAuthorized(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('usage-current');
        $alpha = $this->feature('usage.alpha.max', 'integer', 'gauge');
        $zero = $this->feature('usage.zero.max', 'integer', 'counter');
        $plain = $this->feature('usage.module.crm', 'boolean');
        [, $price] = $this->planPrice('usage-current-plan', featureValues: [$alpha => 4, $zero => 0, $plain => true]);
        $subscription = $this->draftSubscription($account, $profile, [$price], 50);
        $this->activator()->activate($account, $subscription, $this->activationInstant(), $this->uuid());
        (new ApplyUsageDelta($this->connection))->apply($account, 'usage.alpha.max', 1, 'usage-current-one');
        $reader = new CurrentUsage($this->connection, new AccountAuthorization($this->connection));
        $result = $reader->forAccount($owner, $account);

        self::assertSame($subscription, $result['subscription']['id']);
        self::assertSame($result['subscription']['current_period_start'], $result['period']['starts_at']);
        self::assertSame($result['subscription']['current_period_end'], $result['period']['ends_at']);
        self::assertIsArray($result['counters']);
        self::assertSame(['usage.alpha.max', 'usage.zero.max'], array_keys($result['counters']));
        self::assertSame(['mode' => 'gauge', 'used' => 1, 'limit' => 4, 'remaining' => 3, 'exhausted' => false], $result['counters']['usage.alpha.max']);
        self::assertSame(['mode' => 'counter', 'used' => 0, 'limit' => 0, 'remaining' => 0, 'exhausted' => true], $result['counters']['usage.zero.max']);

        $technical = $this->user('usage-current-technical@example.test');
        $this->connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'technical')", [$technical, $account]);
        self::assertSame($result['counters'], $reader->forAccount($technical, $account)['counters']);
        [$foreignOwner, $foreignAccount] = $this->readyAccount('usage-current-foreign');
        try {
            $reader->forAccount($foreignOwner, $account);
            self::fail('Foreign account usage must not cross the membership boundary.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
        $empty = $reader->forAccount($foreignOwner, $foreignAccount);
        self::assertNull($empty['subscription']);
        self::assertNull($empty['period']);
        self::assertInstanceOf(\stdClass::class, $empty['counters']);

        (new CancelSubscription($this->connection, new AccountAuthorization($this->connection)))->cancel($owner, $account, $subscription, 'usage-current-cancel', $this->uuid());
        self::assertNull($reader->forAccount($owner, $account)['subscription']);
    }

    private function expectDomainRejection(callable $operation): void
    {
        try {
            $operation();
            self::fail('Usage operation must be rejected.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
    }
}
