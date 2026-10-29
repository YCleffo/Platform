<?php
declare(strict_types=1);
require '/app/vendor/autoload.php';
use ControlPlane\Account\Application\BillingProfileService;
use ControlPlane\Account\Application\CreateCommercialAccount;
use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\IssueInvoice;
use ControlPlane\Identity\Application\AuthService;
use ControlPlane\Identity\Security\ControlUserPrincipal;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Payment\Application\CreatePayment;
use ControlPlane\Payment\Application\SettlePayment;
use ControlPlane\Subscription\Application\ActivateSubscription;
use ControlPlane\Subscription\Application\CreateSubscription;
use ControlPlane\Subscription\Application\SubscriptionPeriodCalculator;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\Uid\Uuid;
$db=DriverManager::getConnection((new DsnParser(['postgresql'=>'pdo_pgsql']))->parse((string)getenv('CONTROL_DATABASE_URL')));
if ($db->fetchOne("SELECT current_database() || ':' || current_setting('cluster_name')") !== 'platform_commercial_upgrade:platform-upgrade-rehearsal-commercial') throw new RuntimeException('Not an isolated Commercial rehearsal');
$db->beginTransaction();
$checks=[];
try {
    $correlation=Uuid::v7()->toRfc4122();$suffix=bin2hex(random_bytes(6));
    $tokens=new TokenCodec((string)getenv('CONTROL_TOKEN_SECRET'));
    $hasher=new UserPasswordHasher(new PasswordHasherFactory([ControlUserPrincipal::class=>['algorithm'=>'auto']]));
    $auth=new AuthService($db,$hasher,$tokens,new DatabaseRateLimiter($db,$tokens),3600,900,86400);
    $email='rehearsal-'.$suffix.'@example.test';$password='Temporary rehearsal password 2026';
    $registered=$auth->register($email,$password,'192.0.2.201',$correlation);
    $auth->verifyEmail($registered['verification_token'],$correlation);
    $session=$auth->login($email,$password,'192.0.2.201','Rehearsal',$correlation);
    if ($auth->authenticateAccessToken($session['access_token'])->id() !== $registered['user_id']) throw new RuntimeException('Auth identity mismatch');
    $stored=$db->fetchAssociative("SELECT encode(access_token_hash,'hex') AS access_hash,encode(refresh_token_hash,'hex') AS refresh_hash FROM commercial.control_sessions WHERE control_user_id=?",[$registered['user_id']]);
    if ($stored===false || $stored['access_hash']!==$tokens->hash($session['access_token']) || $stored['refresh_hash']!==$tokens->hash($session['refresh_token'])) throw new RuntimeException('Session token hashes mismatch');
    $checks[]='registration/verification/password auth and hash-only session';
    $account=(new CreateCommercialAccount($db))->create($registered['user_id'],'rehearsal-'.$suffix,'Rollback-only account',null,'account-'.$suffix,$correlation);
    $authorization=new AccountAuthorization($db);
    (new BillingProfileService($db,$authorization))->save($registered['user_id'],$account,'Rehearsal','RU',null,$email,null,[],$correlation);
    $db->executeStatement("UPDATE commercial.commercial_accounts SET onboarding_state='ready_for_subscription' WHERE id=?",[$account]);
    $checks[]='owner membership and billing onboarding';
    $plan=$db->fetchOne('INSERT INTO commercial.plans(code,version,name) VALUES (?,1,?) RETURNING id',['rehearsal-'.$suffix,'Rollback-only plan']);
    $price=$db->fetchOne("INSERT INTO commercial.plan_prices(plan_id,currency,amount_minor,billing_period) VALUES (?,'RUB',0,'month') RETURNING id",[$plan]);
    $db->executeStatement("UPDATE commercial.plans SET status='published',published_at=now() WHERE id=?",[$plan]);
    $subscription=(new CreateSubscription($db,$authorization))->create($registered['user_id'],$account,$price,1,'subscription-'.$suffix,$correlation);
    $invoice=(new IssueInvoice($db,$authorization))->issue($registered['user_id'],$account,$subscription['id'],'invoice-'.$suffix,$correlation);
    $paymentCreator=new CreatePayment($db,$authorization,new SettlePayment(new ActivateSubscription($db,new SubscriptionPeriodCalculator())));
    $payment=$paymentCreator->create($registered['user_id'],$account,$invoice['id'],'payment-'.$suffix,$correlation);
    $replay=$paymentCreator->create($registered['user_id'],$account,$invoice['id'],'payment-'.$suffix,$correlation);
    if ($payment['status']!=='succeeded' || $replay['id']!==$payment['id']) throw new RuntimeException('Free settlement/replay failed');
    $checks[]='published plan, subscription, invoice, zero settlement and idempotent replay';
    $db->createSavepoint('immutability_probe');
    try {
        $db->executeStatement('UPDATE commercial.plan_prices SET amount_minor=1 WHERE id=?',[$price]);
        throw new RuntimeException('Published price changed');
    } catch (\Doctrine\DBAL\Exception $e) {
        if (!$e instanceof \Doctrine\DBAL\Exception\DriverException || $e->getSQLState()!=='23514' || !str_contains($e->getMessage(),'published plan')) throw new RuntimeException('Financial immutability guard did not execute');
        $checks[]='SQL financial immutability guard remains active for runtime DML';
    } finally {$db->rollbackSavepoint('immutability_probe');}
    $entitlements=(new \ControlPlane\Entitlement\Application\EffectiveEntitlements($db,$authorization))->forAccount($registered['user_id'],$account);
    if ($entitlements['subscription']===null) throw new RuntimeException('Derived subscription missing');
    $checks[]='derived Commercial entitlements';
    echo json_encode(['status'=>'PASS','role'=>'commercial_runtime','checks'=>$checks,'provider_calls'=>0,'transaction'=>'rolled_back']),"\n";
} finally {
    if ($db->isTransactionActive()) $db->rollBack();
}
