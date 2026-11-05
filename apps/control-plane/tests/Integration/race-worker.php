<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Tools\DsnParser;
use ControlPlane\Account\Application\MembershipService;
use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\IssueInvoice;
use ControlPlane\Billing\Application\IssueRenewalInvoice;
use ControlPlane\Billing\Domain\ProrationCalculator;
use ControlPlane\Billing\Application\CollectRenewalInvoice;
use ControlPlane\Billing\Application\RenewalCollectionLifecycle;
use ControlPlane\Billing\Application\RenewalDunningPolicy;
use ControlPlane\Billing\Application\ProcessRenewalDunning;
use ControlPlane\Fiscalization\Application\ApplyAuthoritativeReceipt;
use ControlPlane\Fiscalization\Application\CreatePaymentReceiptObligation;
use ControlPlane\Fiscalization\Application\CreateRefundReceiptObligation;
use ControlPlane\Fiscalization\Application\ReceiptSnapshot;
use ControlPlane\Fiscalization\Application\SubmitYooKassaReceipt;
use ControlPlane\Identity\Application\AuthFailure;
use ControlPlane\Identity\Application\AuthService;
use ControlPlane\Identity\Application\AuthSecurityService;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Identity\Security\TotpSecretCipher;
use ControlPlane\Subscription\Application\ActivateSubscription;
use ControlPlane\Subscription\Application\AdvanceSubscriptionPeriod;
use ControlPlane\Subscription\Application\CancelSubscription;
use ControlPlane\Subscription\Application\ExpireSubscription;
use ControlPlane\Subscription\Application\ExternalPlanChangeCompensation;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use ControlPlane\Subscription\Application\ApplySubscriptionPlanChange;
use ControlPlane\Subscription\Application\CancelSubscriptionPlanChange;
use ControlPlane\Subscription\Application\ProcessScheduledPlanChanges;
use ControlPlane\Billing\Application\PlanChangeInvoiceIssuer;
use ControlPlane\Subscription\Application\RequestSubscriptionPlanChange;
use ControlPlane\Payment\Application\BeginPaymentAttempt;
use ControlPlane\Payment\Application\BindPaymentAttemptProviderReference;
use ControlPlane\Payment\Application\CompletePaymentAttempt;
use ControlPlane\Payment\Application\CreatePayment;
use ControlPlane\Payment\Application\ProcessAuthoritativeProviderPayment;
use ControlPlane\Payment\Application\PaymentCreator as InternalPaymentCreator;
use ControlPlane\Payment\Application\RecordSavedPaymentMethod;
use ControlPlane\Payment\Application\ReceiveYooKassaWebhook;
use ControlPlane\Payment\Application\ReconcileYooKassaPayments;
use ControlPlane\Payment\Application\SettlePayment;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderPayment;
use ControlPlane\Payment\Provider\ProviderRefund;
use ControlPlane\Payment\Provider\YooKassa\YooKassaProviderAccountLocator;
use ControlPlane\Refund\Application\ApplyAuthoritativeRefund;
use ControlPlane\Refund\Application\ReconcileYooKassaRefunds;
use ControlPlane\Refund\Application\RefundSnapshot;
use ControlPlane\Refund\Application\SubmitYooKassaRefund;
use ControlPlane\Usage\Application\ApplyUsageDelta;
use ControlPlane\Usage\Application\IngestDeploymentUsage;
use ControlPlane\M2m\DeploymentIdentity;
use ControlPlane\Tests\Support\FakeFiscalReceiptProviderGateway;
use ControlPlane\Tests\Support\FakePaymentProviderGateway;
use ControlPlane\Tests\Support\FakeRefundProviderGateway;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;

$url = getenv('CONTROL_DATABASE_URL');
$parts = is_string($url) ? parse_url($url) : false;
if (getenv('APP_ENV') !== 'test' || getenv('CONTROL_TEST_ISOLATED') !== '1'
    || !is_array($parts) || ($parts['host'] ?? '') !== 'control-postgres-test'
    || ($parts['path'] ?? '') !== '/platform_commercial_test') {
    exit(3);
}

[$script, $mode, $value, $extra, $start] = $argv;
$connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
$connection->executeQuery('SELECT 1');
if (str_starts_with($start, 'barrier|')) {
    [, $barrier, $lane] = explode('|', $start, 3);
    file_put_contents($barrier . '/ready-' . $lane . '.tmp', (string) $connection->fetchOne('SELECT pg_backend_pid()'));
    rename($barrier . '/ready-' . $lane . '.tmp', $barrier . '/ready-' . $lane);
    $deadline = microtime(true) + 15;
    while (!is_file($barrier . '/release-' . $lane) && !is_file($barrier . '/release-all')) {
        if (microtime(true) > $deadline) { fwrite(STDERR, "Worker barrier timed out: $mode\n"); exit(3); }
        usleep(5_000);
    }
} else {
    while (microtime(true) < (float) $start) { usleep(5_000); }
}
try {
    switch ($mode) {
        case 'apply_receipt_lock_order':
            $connection->executeQuery("SELECT set_config('application_name', ?, false)", [$extra]);
            [, , $request] = (new ReceiptSnapshot())->load($connection, $value);
            $receipt = new \ControlPlane\Payment\Provider\ProviderReceipt('lock-order-' . $value, $request->receiptType, $request->providerPaymentId,
                'succeeded', $request->amountMinor, $request->currency, $request->items, new \DateTimeImmutable(), $request->providerRefundId);
            (new ApplyAuthoritativeReceipt($connection))->apply($value, $receipt, $request, \Symfony\Component\Uid\Uuid::v7()->toRfc4122());
            break;
        case 'idempotency_scoped':
            $connection->executeStatement("INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status) VALUES (?, ?, decode(repeat('01',32),'hex'), 'completed')", [$extra, $value]);
            break;
        case 'plan_change_request':
            [$userId, $accountId, $priceId, $key, $correlationId] = explode('|', $extra, 5);
            $change = (new RequestSubscriptionPlanChange($connection,
                new AccountAuthorization($connection), new ProrationCalculator(),
                new ApplySubscriptionPlanChange(new SubscriptionPeriodCalculator())))
                ->request($userId, $accountId, $value, $priceId, 1, $key, $correlationId);
            fwrite(STDOUT, $change['id']);
            break;
        case 'plan_change_cancel':
            [$userId, $accountId, $key, $correlationId] = explode('|', $extra, 4);
            (new CancelSubscriptionPlanChange($connection, new AccountAuthorization($connection)))
                ->cancel($userId, $accountId, $value, $key, $correlationId);
            break;
        case 'process_scheduled_plan_changes':
            [$instant, $correlationId] = explode('|', $extra, 2);
            (new ProcessScheduledPlanChanges($connection, new PlanChangeInvoiceIssuer(),
                new InternalPaymentCreator(paymentSettlement($connection)),
                new ApplySubscriptionPlanChange(new SubscriptionPeriodCalculator())))
                ->runBatch(new DateTimeImmutable($instant), 100, $correlationId);
            break;
        case 'account':
            $connection->beginTransaction();
            $email = $value . '-' . bin2hex(random_bytes(4)) . '@example.test';
            $user = $connection->fetchOne("INSERT INTO commercial.control_users (email, email_normalized, password_hash, status, email_verified_at, password_changed_at) VALUES (?, ?, '!race-hash!', 'active', now(), now()) RETURNING id", [$email, $email]);
            $account = $connection->fetchOne("INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, 'Race Test') RETURNING id", [$value]);
            $connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$user, $account]);
            $connection->commit();
            break;
        case 'plan':
            $connection->executeStatement("INSERT INTO commercial.plans (code, version, name) VALUES (?, 1, 'Race Test')", [$value]);
            break;
        case 'slug':
            $connection->beginTransaction();
            $deployment = $connection->fetchOne("INSERT INTO commercial.deployments (commercial_account_id, installation_id, mode, system_slug) VALUES (?, uuidv7(), 'shared', ?) RETURNING id", [$extra, $value]);
            $connection->executeStatement("INSERT INTO commercial.deployment_domains (deployment_id, kind, hostname) VALUES (?, 'system', ?)", [$deployment, $value . '.platform.example']);
            $connection->commit();
            break;
        case 'hostname':
            $connection->executeStatement("INSERT INTO commercial.deployment_domains (deployment_id, kind, hostname) VALUES (?, 'custom', ?)", [$extra, $value]);
            break;
        case 'idempotency':
            $connection->executeStatement("INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status) VALUES ('test.race', ?, decode(repeat('00',32),'hex'), 'completed')", [$value]);
            break;
        case 'register':
            [$correlationId, $ip] = explode('|', $extra);
            authService($connection)->register($value, 'correct horse battery staple', $ip, $correlationId);
            break;
        case 'verify':
            authService($connection)->verifyEmail($value, $extra);
            break;
        case 'refresh':
            authService($connection)->refresh($value, '198.51.100.11', 'race-worker', $extra);
            break;
        case 'mfa_verify':
            [$code, $correlationId] = explode('|', $extra, 2);
            authSecurityService($connection)->verifyMfaLogin($value, $code, null, '198.51.100.12', 'race-worker', $correlationId);
            break;
        case 'mfa_recovery_verify':
            [$code, $correlationId] = explode('|', $extra, 2);
            authSecurityService($connection)->verifyMfaLogin($value, null, $code, '198.51.100.12', 'race-worker', $correlationId);
            break;
        case 'reauth_recovery':
            [$userId, $email, $password, $code, $correlationId] = explode('|', $extra, 5);
            $session = $connection->fetchAssociative('SELECT primary_authenticated_at, mfa_authenticated_at FROM commercial.control_sessions WHERE id = ?', [$value]);
            if ($session === false) { exit(3); }
            authSecurityService($connection)->reauthenticate(new ControlUserPrincipal($userId, $email, passwordHash($connection, $userId), $value, new DateTimeImmutable((string) $session['primary_authenticated_at']), $session['mfa_authenticated_at'] === null ? null : new DateTimeImmutable((string) $session['mfa_authenticated_at'])), $password, null, $code, $correlationId);
            break;
        case 'reset_confirm':
            [$password, $correlationId] = explode('|', $extra, 2);
            authSecurityService($connection)->confirmPasswordReset($value, $password, $correlationId);
            break;
        case 'membership':
            [$actorId, $accountId, $targetId, $correlationId] = explode('|', $extra);
            (new MembershipService($connection, new AccountAuthorization($connection)))->add($actorId, $accountId, $targetId, 'technical', $correlationId);
            break;
        case 'owner_change':
            $connection->beginTransaction();
            $connection->executeStatement("UPDATE commercial.commercial_account_memberships SET role = 'admin' WHERE id = ?", [$value]);
            $connection->commit();
            break;
        case 'activate_subscription':
            [$accountId, $correlationId, $activationInstant] = explode('|', $extra);
            (new ActivateSubscription($connection, new SubscriptionPeriodCalculator()))->activate($accountId, $value, new DateTimeImmutable($activationInstant), $correlationId);
            break;
        case 'cancel_subscription':
            [$userId, $accountId, $idempotencyKey, $correlationId] = explode('|', $extra);
            (new CancelSubscription($connection, new AccountAuthorization($connection)))->cancel($userId, $accountId, $value, $idempotencyKey, $correlationId);
            break;
        case 'issue_invoice':
            [$userId, $accountId, $idempotencyKey, $correlationId] = explode('|', $extra);
            (new IssueInvoice($connection, new AccountAuthorization($connection)))->issue($userId, $accountId, $value, $idempotencyKey, $correlationId);
            break;
        case 'issue_renewal_invoice':
            [$accountId, $correlationId] = explode('|', $extra, 2);
            (new IssueRenewalInvoice($connection, new SubscriptionPeriodCalculator()))->issue($accountId, $value, $correlationId);
            break;
        case 'create_payment':
            [$userId, $accountId, $idempotencyKey, $correlationId] = explode('|', $extra);
            paymentCreator($connection)->create($userId, $accountId, $value, $idempotencyKey, $correlationId);
            break;
        case 'collect_renewal':
            [$correlationId, $instant] = explode('|', $extra, 2);
            $gateway = new FakePaymentProviderGateway();
            renewalCollector($connection, $gateway)->collect($value, $correlationId, new DateTimeImmutable($instant));
            fwrite(STDOUT, json_encode(['create_count' => count($gateway->createCalls)], JSON_THROW_ON_ERROR));
            break;
        case 'process_dunning':
            [$correlationId, $instant] = explode('|', $extra, 2);
            $policy = new RenewalDunningPolicy(3, '60,300', 3600, 30);
            $gateway = new FakePaymentProviderGateway();
            (new ProcessRenewalDunning($connection, $policy, renewalCollector($connection, $gateway),
                new ExpireSubscription($connection, new AdvanceSubscriptionPeriod($connection))))->process(new DateTimeImmutable($instant), 10, $correlationId);
            fwrite(STDOUT, json_encode(['create_count' => count($gateway->createCalls)], JSON_THROW_ON_ERROR));
            break;
        case 'webhook_renewal':
        case 'reconcile_renewal':
            [$correlationId, $capturedAt] = explode('|', $extra, 2);
            $gateway = new FakePaymentProviderGateway();
            $providerPayment = renewalProviderPayment($connection, $value, new DateTimeImmutable($capturedAt));
            $gateway->payments[$providerPayment->id] = $providerPayment;
            $lifecycle = new RenewalCollectionLifecycle($connection, new RenewalDunningPolicy(3, '60,300', 3600, 30));
            $settlement = new SettlePayment(new ActivateSubscription($connection, new SubscriptionPeriodCalculator()), new AdvanceSubscriptionPeriod($connection), $lifecycle);
            $processor = new ProcessAuthoritativeProviderPayment($connection, new BindPaymentAttemptProviderReference($connection),
                new CompletePaymentAttempt($connection, $settlement, new CreatePaymentReceiptObligation()),
                new SubmitYooKassaReceipt($connection, new ReceiptSnapshot(), new FakeFiscalReceiptProviderGateway(), new ApplyAuthoritativeReceipt($connection)),
                new RecordSavedPaymentMethod($connection), $lifecycle);
            if ($mode === 'webhook_renewal') {
                $body = json_encode(['type' => 'notification', 'event' => 'payment.succeeded', 'object' => ['id' => $providerPayment->id]], JSON_THROW_ON_ERROR);
                (new ReceiveYooKassaWebhook($connection, new YooKassaProviderAccountLocator($connection), $gateway, $processor))->receive($body, 'application/json', $correlationId);
            } else {
                (new ReconcileYooKassaPayments($connection, $gateway, $processor))->reconcile(100, $correlationId);
            }
            break;
        case 'webhook_refund':
        case 'reconcile_refund':
            [$correlationId, $succeededAt] = explode('|', $extra, 2);
            $row = $connection->fetchAssociative(<<<'SQL'
SELECT r.id, r.provider_refund_id, r.amount_minor, r.currency, pa.provider_payment_id
FROM commercial.refunds r
JOIN commercial.payments p ON p.id=r.payment_id
JOIN commercial.payment_attempts pa ON pa.payment_id=p.id AND pa.status='succeeded'
WHERE r.id=?
SQL, [$value]);
            if ($row === false || !is_string($row['provider_refund_id']) || !is_string($row['provider_payment_id'])) {
                throw new LogicException('Refund race fixture is incomplete.');
            }
            $gateway = new FakeRefundProviderGateway();
            $gateway->refunds[$row['provider_refund_id']] = new ProviderRefund($row['provider_refund_id'],
                $row['provider_payment_id'], 'succeeded', (int) $row['amount_minor'], (string) $row['currency'],
                new DateTimeImmutable($succeededAt), $value);
            $receiptSubmission = new SubmitYooKassaReceipt($connection, new ReceiptSnapshot(),
                new FakeFiscalReceiptProviderGateway(), new ApplyAuthoritativeReceipt($connection));
            $submission = new SubmitYooKassaRefund($connection, new RefundSnapshot(), $gateway,
                new ApplyAuthoritativeRefund($connection, new CreateRefundReceiptObligation()), $receiptSubmission);
            if ($mode === 'webhook_refund') {
                $lifecycle = new RenewalCollectionLifecycle($connection, new RenewalDunningPolicy(3, '60,300', 3600, 30));
                $processor = new ProcessAuthoritativeProviderPayment($connection,
                    new BindPaymentAttemptProviderReference($connection),
                    new CompletePaymentAttempt($connection,
                        new SettlePayment(new ActivateSubscription($connection, new SubscriptionPeriodCalculator()),
                            new AdvanceSubscriptionPeriod($connection), $lifecycle),
                        new CreatePaymentReceiptObligation()), $receiptSubmission,
                    new RecordSavedPaymentMethod($connection), $lifecycle);
                $body = json_encode(['type' => 'notification', 'event' => 'refund.succeeded',
                    'object' => ['id' => $row['provider_refund_id']]], JSON_THROW_ON_ERROR);
                (new ReceiveYooKassaWebhook($connection, new YooKassaProviderAccountLocator($connection),
                    new FakePaymentProviderGateway(), $processor, $gateway, $submission))
                    ->receive($body, 'application/json', $correlationId);
            } else {
                (new ReconcileYooKassaRefunds($connection, $submission))->reconcile(100, $correlationId);
            }
            break;
        case 'approve_external_compensation':
            [$actorId, $transactionReference, $documentReference, $documentSha256, $correlationId] = explode('|', $extra, 5);
            try {
                (new ExternalPlanChangeCompensation($connection))->review($actorId, $value,
                    $transactionReference, $documentReference, $documentSha256, true, null, $correlationId);
                fwrite(STDOUT, 'approved');
            } catch (DomainException $exception) {
                fwrite(STDOUT, 'conflict');
            }
            break;
        case 'authoritative_plan':
            [$correlationId, $capturedAt] = explode('|', $extra, 2);
            $providerPayment = renewalProviderPayment($connection, $value, new DateTimeImmutable($capturedAt));
            $accountRow = $connection->fetchAssociative(<<<'SQL'
SELECT ppa.id, ppa.provider_code, ppa.external_account_id, ppa.secret_ref,
 ppa.receipt_mode, ppa.receipt_vat_code, ppa.receipt_payment_subject, ppa.receipt_payment_mode
FROM commercial.payment_attempts a
JOIN commercial.payment_provider_accounts ppa ON ppa.id=a.provider_account_id
WHERE a.id=?
SQL, [$value]);
            if ($accountRow === false) throw new LogicException('Plan payment provider is unavailable.');
            $lifecycle = new RenewalCollectionLifecycle($connection, new RenewalDunningPolicy(3, '60,300', 3600, 30));
            $processor = new ProcessAuthoritativeProviderPayment($connection, new BindPaymentAttemptProviderReference($connection),
                new CompletePaymentAttempt($connection,
                    new SettlePayment(new ActivateSubscription($connection, new SubscriptionPeriodCalculator()),
                        new AdvanceSubscriptionPeriod($connection), $lifecycle),
                    new CreatePaymentReceiptObligation()),
                new SubmitYooKassaReceipt($connection, new ReceiptSnapshot(), new FakeFiscalReceiptProviderGateway(), new ApplyAuthoritativeReceipt($connection)),
                new RecordSavedPaymentMethod($connection), $lifecycle);
            $processor->process(YooKassaProviderAccountLocator::fromRow($accountRow), $providerPayment, $correlationId);
            break;
        case 'restore_renewal_method':
            $source = $connection->fetchAssociative('SELECT commercial_account_id, provider_account_id, created_from_payment_attempt_id FROM commercial.saved_payment_methods WHERE id=?', [$value]);
            if ($source === false) throw new LogicException('Saved method restoration fixture is unavailable.');
            $connection->executeStatement("INSERT INTO commercial.saved_payment_methods (commercial_account_id, provider_account_id, provider_method_reference, method_type, status, is_default, created_from_payment_attempt_id) VALUES (?, ?, ?, 'bank_card', 'active', true, ?)",
                [$source['commercial_account_id'], $source['provider_account_id'], 'restored-' . str_replace('-', '', $value), $source['created_from_payment_attempt_id']]);
            break;
        case 'begin_payment_attempt':
            [$providerAccountId, $methodType, $correlationId] = explode('|', $extra);
            (new BeginPaymentAttempt($connection))->begin($value, $providerAccountId, $methodType, $correlationId);
            break;
        case 'complete_payment_attempt':
            [$outcome, $failureCode, $settlementInstant, $correlationId] = explode('|', $extra);
            (new CompletePaymentAttempt($connection, paymentSettlement($connection), new CreatePaymentReceiptObligation()))->complete($value, $outcome, $failureCode === '' ? null : $failureCode, new DateTimeImmutable($settlementInstant), $correlationId);
            break;
        case 'bind_payment_attempt':
            (new BindPaymentAttemptProviderReference($connection))->bind($value, $extra);
            break;
        case 'process_provider_payment':
            [$providerPaymentId, $status, $capturedAt, $correlationId] = explode('|', $extra);
            $row = $connection->fetchAssociative(<<<'SQL'
SELECT pa.payment_id, pa.provider_account_id, p.invoice_id, p.amount_minor, p.currency,
       ppa.provider_code, ppa.external_account_id, ppa.secret_ref, ppa.receipt_mode, ppa.receipt_vat_code,
       ppa.receipt_payment_subject, ppa.receipt_payment_mode
FROM commercial.payment_attempts pa
JOIN commercial.payments p ON p.id = pa.payment_id
JOIN commercial.payment_provider_accounts ppa ON ppa.id = pa.provider_account_id
WHERE pa.id = ?
SQL, [$value]);
            if ($row === false || !is_string($row['payment_id']) || !is_string($row['provider_account_id'])
                || !is_string($row['invoice_id']) || !is_string($row['provider_code'])
                || !is_string($row['external_account_id']) || !is_string($row['secret_ref'])) {
                throw new LogicException('Provider race fixture unavailable.');
            }
            $providerAccount = new ProviderAccount($row['provider_account_id'], $row['provider_code'], $row['external_account_id'], $row['secret_ref'], (string) $row['receipt_mode'], $row['receipt_vat_code'] === null ? null : (int) $row['receipt_vat_code'], is_string($row['receipt_payment_subject']) ? $row['receipt_payment_subject'] : null, is_string($row['receipt_payment_mode']) ? $row['receipt_payment_mode'] : null);
            $providerPayment = new ProviderPayment(
                $providerPaymentId,
                $status,
                $status === 'succeeded',
                (int) $row['amount_minor'],
                (string) $row['currency'],
                ['payment_id' => $row['payment_id'], 'attempt_id' => $value, 'invoice_id' => $row['invoice_id']],
                null,
                $capturedAt === '' ? null : new DateTimeImmutable($capturedAt),
            );
            (new ProcessAuthoritativeProviderPayment(
                $connection,
                new BindPaymentAttemptProviderReference($connection),
                new CompletePaymentAttempt($connection, paymentSettlement($connection), new CreatePaymentReceiptObligation()),
                new SubmitYooKassaReceipt($connection, new ReceiptSnapshot(), new FakeFiscalReceiptProviderGateway(), new ApplyAuthoritativeReceipt($connection)),
                new RecordSavedPaymentMethod($connection),
            ))->process($providerAccount, $providerPayment, $correlationId);
            break;
        case 'expire_subscription':
            [$accountId, $correlationId, $effectiveInstant] = explode('|', $extra);
            (new ExpireSubscription($connection))->expire($accountId, $value, new DateTimeImmutable($effectiveInstant), $correlationId);
            break;
        case 'apply_usage':
            [$accountId, $featureCode, $delta, $idempotencyKey] = explode('|', $extra);
            (new ApplyUsageDelta($connection))->apply($accountId, $featureCode, (int) $delta, $idempotencyKey);
            break;
        case 'ingest_usage':
            [$deploymentId, $featureCode, $delta, $idempotencyKey] = explode('|', $extra);
            (new IngestDeploymentUsage($connection, new ApplyUsageDelta($connection)))->apply(
                new DeploymentIdentity($deploymentId, $deploymentId, ['usage.write']),
                $featureCode, (int) $delta, $idempotencyKey,
            );
            break;
        default:
            exit(3);
    }
    exit(0);
} catch (UniqueConstraintViolationException $exception) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    fwrite(STDERR, $mode . ': ' . $exception . "\n");
    exit(2);
} catch (AuthFailure|DbalException|DomainException $exception) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    fwrite(STDERR, $mode . ': ' . $exception . "\n");
    exit(2);
} catch (Throwable $exception) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    fwrite(STDERR, $mode . ': ' . $exception . "\n");
    exit(3);
}

function renewalProviderPayment(\Doctrine\DBAL\Connection $connection, string $attemptId, DateTimeImmutable $capturedAt): ProviderPayment
{
    $row = $connection->fetchAssociative('SELECT a.provider_payment_id,a.payment_id,p.invoice_id,p.amount_minor,p.currency FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE a.id=?', [$attemptId]);
    if ($row === false) throw new LogicException('Renewal provider payment is unavailable.');
    $providerId = is_string($row['provider_payment_id']) ? $row['provider_payment_id'] : 'yk-' . str_replace('-', '', $attemptId);
    return new ProviderPayment($providerId, 'succeeded', true, (int) $row['amount_minor'], (string) $row['currency'],
        ['payment_id' => $row['payment_id'], 'attempt_id' => $attemptId, 'invoice_id' => $row['invoice_id']], null, $capturedAt);
}

function authService(\Doctrine\DBAL\Connection $connection): AuthService
{
    $tokens = new TokenCodec('test-only-control-token-secret-at-least-32-chars');
    $factory = new PasswordHasherFactory([ControlUserPrincipal::class => ['algorithm' => 'auto']]);

    return new AuthService($connection, new UserPasswordHasher($factory), $tokens, new DatabaseRateLimiter($connection, $tokens), 3600, 900, 2592000);
}

function authSecurityService(\Doctrine\DBAL\Connection $connection): AuthSecurityService
{
    $tokens = new TokenCodec('test-only-control-token-secret-at-least-32-chars');
    $factory = new PasswordHasherFactory([ControlUserPrincipal::class => ['algorithm' => 'auto']]);
    return new AuthSecurityService($connection, new UserPasswordHasher($factory), $tokens,
        new TotpSecretCipher('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='), new DatabaseRateLimiter($connection, $tokens),
        authService($connection), 3600, 300, 600, 900);
}

function passwordHash(\Doctrine\DBAL\Connection $connection, string $userId): string
{
    $hash = $connection->fetchOne('SELECT password_hash FROM commercial.control_users WHERE id = ?', [$userId]);
    if (!is_string($hash)) { throw new RuntimeException('User unavailable.'); }
    return $hash;
}

function paymentSettlement(\Doctrine\DBAL\Connection $connection): SettlePayment
{
    return new SettlePayment(new ActivateSubscription($connection, new SubscriptionPeriodCalculator()));
}

function paymentCreator(\Doctrine\DBAL\Connection $connection): CreatePayment
{
    return new CreatePayment($connection, new AccountAuthorization($connection), paymentSettlement($connection));
}

function renewalCollector(\Doctrine\DBAL\Connection $connection, ?FakePaymentProviderGateway $gateway = null): CollectRenewalInvoice
{
    $policy = new RenewalDunningPolicy(3, '60,300', 3600, 30);
    $lifecycle = new RenewalCollectionLifecycle($connection, $policy);
    $settlement = new SettlePayment(
        new ActivateSubscription($connection, new SubscriptionPeriodCalculator()),
        new AdvanceSubscriptionPeriod($connection),
        $lifecycle,
    );
    $completer = new CompletePaymentAttempt($connection, $settlement, new CreatePaymentReceiptObligation());
    $processor = new ProcessAuthoritativeProviderPayment(
        $connection,
        new BindPaymentAttemptProviderReference($connection),
        $completer,
        new SubmitYooKassaReceipt($connection, new ReceiptSnapshot(), new FakeFiscalReceiptProviderGateway(), new ApplyAuthoritativeReceipt($connection)),
        new RecordSavedPaymentMethod($connection),
        $lifecycle,
    );

    return new CollectRenewalInvoice(
        $connection,
        new BeginPaymentAttempt($connection),
        new BindPaymentAttemptProviderReference($connection),
        $completer,
        $processor,
        $gateway ?? new FakePaymentProviderGateway(),
        new InternalPaymentCreator($settlement),
        $lifecycle,
    );
}
