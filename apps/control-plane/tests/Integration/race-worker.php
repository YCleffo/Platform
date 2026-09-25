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
use ControlPlane\Identity\Application\AuthFailure;
use ControlPlane\Identity\Application\AuthService;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Subscription\Application\ActivateSubscription;
use ControlPlane\Subscription\Application\CancelSubscription;
use ControlPlane\Subscription\Application\ExpireSubscription;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use ControlPlane\Payment\Application\BeginPaymentAttempt;
use ControlPlane\Payment\Application\BindPaymentAttemptProviderReference;
use ControlPlane\Payment\Application\CompletePaymentAttempt;
use ControlPlane\Payment\Application\CreatePayment;
use ControlPlane\Payment\Application\ProcessAuthoritativeProviderPayment;
use ControlPlane\Payment\Application\SettlePayment;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderPayment;
use ControlPlane\Usage\Application\ApplyUsageDelta;
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
while (microtime(true) < (float) $start) {
    usleep(5_000);
}

$connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
try {
    switch ($mode) {
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
            authService($connection)->register($value, 'correct horse battery staple', '198.51.100.10', $extra);
            break;
        case 'verify':
            authService($connection)->verifyEmail($value, $extra);
            break;
        case 'refresh':
            authService($connection)->refresh($value, '198.51.100.11', 'race-worker', $extra);
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
        case 'create_payment':
            [$userId, $accountId, $idempotencyKey, $correlationId] = explode('|', $extra);
            paymentCreator($connection)->create($userId, $accountId, $value, $idempotencyKey, $correlationId);
            break;
        case 'begin_payment_attempt':
            [$providerAccountId, $methodType, $correlationId] = explode('|', $extra);
            (new BeginPaymentAttempt($connection))->begin($value, $providerAccountId, $methodType, $correlationId);
            break;
        case 'complete_payment_attempt':
            [$outcome, $failureCode, $settlementInstant, $correlationId] = explode('|', $extra);
            (new CompletePaymentAttempt($connection, paymentSettlement($connection)))->complete($value, $outcome, $failureCode === '' ? null : $failureCode, new DateTimeImmutable($settlementInstant), $correlationId);
            break;
        case 'bind_payment_attempt':
            (new BindPaymentAttemptProviderReference($connection))->bind($value, $extra);
            break;
        case 'process_provider_payment':
            [$providerPaymentId, $status, $capturedAt, $correlationId] = explode('|', $extra);
            $row = $connection->fetchAssociative(<<<'SQL'
SELECT pa.payment_id, pa.provider_account_id, p.invoice_id, p.amount_minor, p.currency,
       ppa.provider_code, ppa.external_account_id, ppa.secret_ref
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
            $providerAccount = new ProviderAccount($row['provider_account_id'], $row['provider_code'], $row['external_account_id'], $row['secret_ref']);
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
                new CompletePaymentAttempt($connection, paymentSettlement($connection)),
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
        default:
            exit(3);
    }
    exit(0);
} catch (UniqueConstraintViolationException) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    exit(2);
} catch (AuthFailure|DbalException|DomainException) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    exit(2);
} catch (Throwable) {
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
    exit(3);
}

function authService(\Doctrine\DBAL\Connection $connection): AuthService
{
    $tokens = new TokenCodec('test-only-control-token-secret-at-least-32-chars');
    $factory = new PasswordHasherFactory([ControlUserPrincipal::class => ['algorithm' => 'auto']]);

    return new AuthService($connection, new UserPasswordHasher($factory), $tokens, new DatabaseRateLimiter($connection, $tokens), 3600, 900, 2592000);
}

function paymentSettlement(\Doctrine\DBAL\Connection $connection): SettlePayment
{
    return new SettlePayment(new ActivateSubscription($connection, new SubscriptionPeriodCalculator()));
}

function paymentCreator(\Doctrine\DBAL\Connection $connection): CreatePayment
{
    return new CreatePayment($connection, new AccountAuthorization($connection), paymentSettlement($connection));
}
