<?php

declare(strict_types=1);

// CLI fixture setup only; no HTTP route, no production autoload and no disabled DB triggers.
use ControlPlane\Kernel;
use ControlPlane\Billing\Application\IssueInvoice;
use ControlPlane\Payment\Application\CreatePayment;
use ControlPlane\Payment\Application\BeginPaymentAttempt;
use ControlPlane\Payment\Application\BindPaymentAttemptProviderReference;
use ControlPlane\Payment\Application\CompletePaymentAttempt;
use ControlPlane\Subscription\Application\ActivateSubscription;
use ControlPlane\Subscription\Application\RequestSubscriptionPlanChange;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1'
    || strlen((string) getenv('CONTROL_E2E_ISOLATION_NONCE')) < 32) throw new LogicException('Isolated browser fixture required.');
$input = json_decode((string) file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($input)) throw new LogicException('Fixture input required.');
if (is_string($input['owner_database_url'] ?? null)) {
    putenv('CONTROL_DATABASE_URL=' . $input['owner_database_url']);
    $_ENV['CONTROL_DATABASE_URL'] = $_SERVER['CONTROL_DATABASE_URL'] = $input['owner_database_url'];
}
$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
if (!$container instanceof ContainerInterface) throw new LogicException('Test container required.');
/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
function browserService(ContainerInterface $container, string $class): object {
    $service = $container->get($class);
    if (!$service instanceof $class) throw new LogicException('Missing fixture service.');
    return $service;
}
$db = browserService($container, Connection::class);
if ($db->fetchOne('SELECT current_database()') !== 'platform_commercial_test') throw new LogicException('Test DB required.');
$suffix = $input['suffix'] ?? null;
if (!is_string($suffix) || preg_match('/\A[a-z0-9-]{1,36}\z/D', $suffix) !== 1) throw new LogicException('Invalid fixture suffix.');
/** @param list<mixed> $params */
function fixtureId(Connection $db, string $sql, array $params): string {
    $value = $db->fetchOne($sql, $params);
    if (!is_string($value)) throw new LogicException('Fixture identity unavailable.');
    return $value;
}
function fixturePrice(Connection $db, string $code, int $amount): string {
    $feature = fixtureId($db, "INSERT INTO commercial.features(code,value_type,usage_mode) VALUES (?, 'boolean', 'none') RETURNING id", [$code]);
    $plan = fixtureId($db, 'INSERT INTO commercial.plans(code,version,name) VALUES (?,1,?) RETURNING id', [$code, $code]);
    $price = fixtureId($db, "INSERT INTO commercial.plan_prices(plan_id,currency,amount_minor,billing_period) VALUES (?, 'RUB', ?, 'month') RETURNING id", [$plan, $amount]);
    $db->executeStatement("INSERT INTO commercial.plan_features(plan_id,feature_id,value) VALUES (?,?,'true'::jsonb)", [$plan, $feature]);
    $db->executeStatement("UPDATE commercial.plans SET status='published',published_at=now() WHERE id=?", [$plan]);
    return $price;
}
$mode = $input['mode'] ?? null;
if ($mode === 'clock') {
    if (getenv('PLATFORM_TEST_CLOCK') !== '1') throw new LogicException('Clock image required.');
    echo json_encode(['php' => microtime(true), 'monotonic' => hrtime(true) / 1e9, 'database' => (float) $db->fetchOne('SELECT extract(epoch FROM clock_timestamp())'), 'preloaded' => getenv('LD_PRELOAD') === '/opt/platform-test/libfaketime.so.1'], JSON_THROW_ON_ERROR);
} elseif ($mode === 'catalog') {
    $prices = [];
    foreach (['initial' => 1000, 'upgrade' => 2000, 'downgrade' => 500] as $name => $amount) $prices[$name] = fixturePrice($db, 'browser.' . $suffix . '.' . $name, $amount);
    if ((int) $db->fetchOne("SELECT count(*) FROM commercial.payment_provider_accounts WHERE provider_code='yookassa' AND status='active'") === 0) {
        $db->executeStatement("INSERT INTO commercial.payment_provider_accounts(provider_code,external_account_id,secret_ref,receipt_mode,receipt_vat_code,receipt_payment_subject,receipt_payment_mode,status) VALUES ('yookassa',?,'env:YOOKASSA_SECRET_KEY','yookassa',1,'service','full_payment','active')", ['shop-' . $suffix]);
    }
    $db->executeStatement("UPDATE commercial.payment_provider_accounts SET receipt_mode='yookassa',receipt_vat_code=1,receipt_payment_subject='service',receipt_payment_mode='full_payment' WHERE provider_code='yookassa' AND status='active'");
    echo json_encode($prices, JSON_THROW_ON_ERROR);
} elseif ($mode === 'recovery') {
    $owner = $input['owner'] ?? null;
    if (!is_string($owner) || !Uuid::isValid($owner) || $db->fetchOne("SELECT 1 FROM commercial.control_users WHERE id=? AND status='active'", [$owner]) === false) throw new LogicException('Active fixture owner required.');
    $account = browserService($container, \ControlPlane\Account\Application\CreateCommercialAccount::class)->create($owner, 'recovery-' . $suffix, 'Recovery ' . $suffix, null, 'recovery-' . $suffix, Uuid::v7()->toRfc4122());
    $profile = fixtureId($db, "INSERT INTO commercial.billing_profiles(commercial_account_id,legal_name,country_code,is_default,contact_name,contact_email) VALUES (?,'Synthetic recovery company','RU',true,'Test Contact','recovery@example.test') RETURNING id", [$account]);
    $db->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state='catalog_selection_pending' WHERE id=?", [$account]);
    $db->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state='ready_for_subscription' WHERE id=?", [$account]);
    $old = fixturePrice($db, 'recovery.' . $suffix . '.old', 1000);
    $new = fixturePrice($db, 'recovery.' . $suffix . '.new', 2000);
    $source = fixtureId($db, "INSERT INTO commercial.subscriptions(commercial_account_id,billing_profile_id,status) VALUES (?,?,'draft') RETURNING id", [$account, $profile]);
    $db->executeStatement("INSERT INTO commercial.subscription_items(subscription_id,plan_price_id,quantity,valid_from) VALUES (?,?,1,now()-interval '1 day')", [$source, $old]);
    $invoice = browserService($container, IssueInvoice::class)->issue($owner, $account, $source, 'source-' . $suffix, Uuid::v7()->toRfc4122());
    $payment = browserService($container, CreatePayment::class)->create($owner, $account, $invoice['id'], 'initial-' . $suffix, Uuid::v7()->toRfc4122());
    $now = (string) $db->fetchOne('SELECT now()');
    $db->executeStatement("UPDATE commercial.payments SET status='succeeded',succeeded_at=?::timestamptz WHERE id=?", [$now, $payment['id']]);
    $db->executeStatement("UPDATE commercial.invoices SET status='paid',paid_at=?::timestamptz WHERE id=?", [$now, $invoice['id']]);
    browserService($container, ActivateSubscription::class)->activate($account, $source, new DateTimeImmutable($now), Uuid::v7()->toRfc4122());
    $change = browserService($container, RequestSubscriptionPlanChange::class)->request($owner, $account, $source, $new, 1, 'change-' . $suffix, Uuid::v7()->toRfc4122());
    $payment = browserService($container, CreatePayment::class)->create($owner, $account, (string) $change['invoice_id'], 'upgrade-' . $suffix, Uuid::v7()->toRfc4122());
    $provider = fixtureId($db, "SELECT id FROM commercial.payment_provider_accounts WHERE provider_code='yookassa' AND status='active' LIMIT 1", []);
    $db->executeStatement("UPDATE commercial.payment_provider_accounts SET receipt_mode='disabled',receipt_vat_code=NULL,receipt_payment_subject=NULL,receipt_payment_mode=NULL WHERE id=?", [$provider]);
    $attempt = browserService($container, BeginPaymentAttempt::class)->begin($payment['id'], $provider, 'sbp', Uuid::v7()->toRfc4122());
    browserService($container, BindPaymentAttemptProviderReference::class)->bind($attempt['attempt_id'], 'recovery-provider-' . $suffix);
    $boundary = new DateTimeImmutable((string) $db->fetchOne('SELECT period_end FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]));
    browserService($container, CompletePaymentAttempt::class)->complete($attempt['attempt_id'], 'succeeded', null, $boundary, Uuid::v7()->toRfc4122());
    if ($db->fetchOne('SELECT compensation_status FROM commercial.subscription_plan_changes WHERE id=?', [$change['id']]) !== 'recovery_required') throw new LogicException('Recovery fixture failed.');
    echo json_encode(['account_id' => $account, 'change_id' => $change['id'], 'payment_id' => $payment['id'], 'source_id' => $source], JSON_THROW_ON_ERROR);
} else throw new LogicException('Unknown fixture mode.');
