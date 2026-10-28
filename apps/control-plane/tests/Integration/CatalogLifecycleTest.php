<?php
declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Catalog\Application\PlanCatalog;

/** CAT-01/02, BILL-01/02: catalog publication is an immutable contract. */
final class CatalogLifecycleTest extends InvoiceTestCase
{
    public function testDraftIsHiddenAndPublishedCatalogPreservesPriceAndFeatureTypes(): void
    {
        $suffix = 'catalog-' . bin2hex(random_bytes(5));
        $values = [];
        foreach (['enabled' => ['boolean', true], 'quota' => ['integer', 100],
            'tier' => ['string', 'standard'], 'options' => ['json', ['export' => true]]] as $name => [$type, $value]) {
            $values[$this->feature($suffix . '.' . $name, $type)] = $value;
        }
        [$plan] = $this->planPrice($suffix, status: 'draft', featureValues: $values);
        $this->connection->executeStatement("INSERT INTO commercial.plan_prices(plan_id,currency,amount_minor,billing_period) VALUES (?,'RUB',0,'month'),(?,'EUR',120000,'year')", [$plan, $plan]);
        $catalog = new PlanCatalog($this->connection);
        self::assertNotContains($suffix, array_column($catalog->published(), 'code'));
        $this->connection->executeStatement("UPDATE commercial.plans SET status='published',published_at=now() WHERE id=?", [$plan]);
        $published = array_values(array_filter($catalog->published(), static fn(array $p): bool => $p['code'] === $suffix));
        self::assertCount(1, $published);
        self::assertSame(1, $published[0]['version']);
        self::assertCount(3, $published[0]['prices']);
        foreach ($published[0]['prices'] as $price) {
            self::assertIsInt($price['amount_minor']);
            self::assertGreaterThanOrEqual(0, $price['amount_minor']);
        }
        self::assertTrue($published[0]['features'][$suffix . '.enabled']);
        self::assertSame(100, $published[0]['features'][$suffix . '.quota']);
        self::assertSame('standard', $published[0]['features'][$suffix . '.tier']);
        self::assertSame(['export' => true], $published[0]['features'][$suffix . '.options']);
    }

    public function testPublishedPricesAndFeaturesCannotBeRewrittenOrDeleted(): void
    {
        $suffix = 'catalog-lock-' . bin2hex(random_bytes(5));
        $feature = $this->feature($suffix . '.enabled', 'boolean');
        [$plan, $price] = $this->planPrice($suffix, featureValues: [$feature => true]);
        $this->expectDatabaseRejection(fn() => $this->connection->executeStatement('UPDATE commercial.plan_prices SET amount_minor=2000 WHERE id=?', [$price]));
        $this->expectDatabaseRejection(fn() => $this->connection->executeStatement('DELETE FROM commercial.plan_prices WHERE id=?', [$price]));
        $this->expectDatabaseRejection(fn() => $this->connection->executeStatement("UPDATE commercial.plan_features SET value='false'::jsonb WHERE plan_id=?", [$plan]));
        $this->expectDatabaseRejection(fn() => $this->connection->executeStatement('DELETE FROM commercial.plan_features WHERE plan_id=?', [$plan]));
        $this->expectDatabaseRejection(fn() => $this->connection->executeStatement('UPDATE commercial.plans SET version=2 WHERE id=?', [$plan]));
    }

    public function testRetiringAndReplacingAPlanCannotRewriteIssuedInvoiceHistory(): void
    {
        $suffix = 'catalog-history-' . bin2hex(random_bytes(5));
        [$owner, $account, $profile] = $this->readyAccount($suffix);
        [$plan, $price] = $this->planPrice($suffix . '.plan');
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $invoice = $this->issuer()->issue($owner, $account, $subscription, $suffix . '.invoice', $this->uuid());
        $before = $this->connection->fetchAllAssociative('SELECT * FROM commercial.invoice_lines WHERE invoice_id=? ORDER BY id', [$invoice['id']]);
        self::assertNotEmpty($before);
        $replacement = $this->connection->fetchOne('INSERT INTO commercial.plans(code,version,name) VALUES (?,2,?) RETURNING id', [$suffix . '.plan', 'New terms']);
        $this->connection->executeStatement("INSERT INTO commercial.plan_prices(plan_id,currency,amount_minor,billing_period) VALUES (?,'USD',2000,'month')", [$replacement]);
        $this->connection->executeStatement("UPDATE commercial.plans SET status='published',published_at=now() WHERE id=?", [$replacement]);
        $this->connection->executeStatement("UPDATE commercial.plans SET status='retired',retired_at=now() WHERE id=?", [$plan]);
        $after = $this->connection->fetchAllAssociative('SELECT * FROM commercial.invoice_lines WHERE invoice_id=? ORDER BY id', [$invoice['id']]);
        self::assertSame($before, $after);
        self::assertSame(1000, (int) $this->connection->fetchOne('SELECT total_minor FROM commercial.invoices WHERE id=?', [$invoice['id']]));
        $published = array_values(array_filter((new PlanCatalog($this->connection))->published(), static fn(array $p): bool => $p['code'] === $suffix . '.plan'));
        self::assertCount(1, $published);
        self::assertSame(2, $published[0]['version']);
    }
}
