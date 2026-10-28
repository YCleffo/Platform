<?php
declare(strict_types=1);

// Fixture preparation only: no network provider and no real charge.
require '/app/vendor/autoload.php';

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\IssueInvoice;
use ControlPlane\Payment\Application\CreatePayment;
use ControlPlane\Payment\Application\SettlePayment;
use ControlPlane\Subscription\Application\ActivateSubscription;
use ControlPlane\Subscription\Application\CreateSubscription;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Uid\Uuid;

$url = (string) getenv('CONTROL_DATABASE_URL');
if ((parse_url($url, PHP_URL_HOST) !== 'commercial-db') || parse_url($url, PHP_URL_PATH) !== '/platform_load_commercial') {
    throw new RuntimeException('Refusing fixture writes outside the disposable load database.');
}
$db = DriverManager::getConnection((new DsnParser(['postgresql'=>'pdo_pgsql']))->parse($url));
if ($db->fetchOne('SELECT current_database()') !== 'platform_load_commercial'
    || (int) $db->fetchOne('SELECT count(*) FROM commercial.control_users') !== 0) {
    throw new RuntimeException('Load fixture requires a fresh load database.');
}
$hash = password_hash('Disposable load fixture password 2026', PASSWORD_ARGON2ID);
$db->transactional(static function ($db) use ($hash): void {
    $db->executeStatement("INSERT INTO commercial.control_users(id,email,email_normalized,password_hash,status,email_verified_at,password_changed_at)
      SELECT ('30000000-0000-4000-8000-'||lpad(n::text,12,'0'))::uuid,'load-'||n||'@example.test','load-'||n||'@example.test',?,'active',now(),now() FROM generate_series(1,10000) n", [$hash]);
    $db->executeStatement("INSERT INTO commercial.commercial_accounts(id,code,display_name)
      SELECT ('40000000-0000-4000-8000-'||lpad(n::text,12,'0'))::uuid,'load-'||n,'Load account '||n FROM generate_series(1,10000) n");
    $db->executeStatement("INSERT INTO commercial.commercial_account_memberships(control_user_id,commercial_account_id,role)
      SELECT ('30000000-0000-4000-8000-'||lpad(n::text,12,'0'))::uuid,('40000000-0000-4000-8000-'||lpad(n::text,12,'0'))::uuid,'owner' FROM generate_series(1,10000) n");
    $db->executeStatement("INSERT INTO commercial.billing_profiles(commercial_account_id,legal_name,country_code,contact_email,is_default) SELECT id,'Load fixture','RU','load@example.test',true FROM commercial.commercial_accounts");
    $db->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state='catalog_selection_pending'");
    $db->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state='ready_for_subscription'");
    $db->executeStatement("INSERT INTO commercial.control_sessions(control_user_id,access_token_hash,refresh_token_hash,access_expires_at,expires_at)
      SELECT id,sha256(convert_to('load_control_access_'||lpad(right(id::text,12)::bigint::text,23,'0'),'UTF8')),
      sha256(convert_to('load_control_refresh_'||lpad(right(id::text,12)::bigint::text,22,'0'),'UTF8')),now()+interval '4 hours',now()+interval '1 day' FROM commercial.control_users");
});
$feature = $db->fetchOne("INSERT INTO commercial.features(code,value_type) VALUES ('load.tier','string') RETURNING id");
$prices = [];
foreach (['free'=>0,'standard'=>100000,'premium'=>300000] as $tier=>$amount) {
    $plan = $db->fetchOne('INSERT INTO commercial.plans(code,version,name) VALUES (?,1,?) RETURNING id', ['load.'.$tier,ucfirst($tier)]);
    $prices[] = $db->fetchOne("INSERT INTO commercial.plan_prices(plan_id,currency,amount_minor,billing_period) VALUES (?,'RUB',?,'month') RETURNING id",[$plan,$amount]);
    $db->executeStatement('INSERT INTO commercial.plan_features(plan_id,feature_id,value) VALUES (?,?,?::jsonb)',[$plan,$feature,json_encode($tier,JSON_THROW_ON_ERROR)]);
    $db->executeStatement("UPDATE commercial.plans SET status='published',published_at=now() WHERE id=?",[$plan]);
}
$authorization = new AccountAuthorization($db);
$subscriptions = new CreateSubscription($db,$authorization);
$invoices = new IssueInvoice($db,$authorization);
$settlement = new SettlePayment(new ActivateSubscription($db,new SubscriptionPeriodCalculator()));
$payments = new CreatePayment($db,$authorization,$settlement);
for ($n=1;$n<=10000;$n++) {
    $tail = str_pad((string)$n,12,'0',STR_PAD_LEFT);
    $user='30000000-0000-4000-8000-'.$tail;
    $account='40000000-0000-4000-8000-'.$tail;
    $correlation=Uuid::v7()->toRfc4122();
    $subscription=$subscriptions->create($user,$account,$prices[($n-1)%3],1,'load-sub-'.$n,$correlation);
    $invoice=$invoices->issue($user,$account,$subscription['id'],'load-invoice-'.$n,$correlation);
    $payment=$payments->create($user,$account,$invoice['id'],'load-payment-'.$n,$correlation);
    if ($payment['status'] !== 'succeeded') {
        $db->transactional(static function ($db) use ($settlement,$payment,$invoice,$correlation): void {
            $p=$db->fetchAssociative('SELECT * FROM commercial.payments WHERE id=? FOR UPDATE',[$payment['id']]);
            $i=$db->fetchAssociative('SELECT * FROM commercial.invoices WHERE id=? FOR UPDATE',[$invoice['id']]);
            $settlement->succeed($db,$p,$i,new DateTimeImmutable('now'),$correlation);
        });
    }
    if ($n%1000===0) echo 'Seeded '.$n." active subscriptions\n";
}
$db->executeStatement('ANALYZE');
echo "Prepared 10000 identities, accounts, sessions, subscriptions and paid fixture invoices; no provider calls.\n";
