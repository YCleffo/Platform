<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\ExternalAuthenticationException;
use App\Core\Auth\Application\ExternalIdentityLastMethodException;
use App\Core\Auth\Application\VerifiedExternalIdentity;
use App\Core\Auth\Application\VerifiedExternalIdentityService;
use App\Core\Client\Application\ClientContextResolver;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use HttpSoft\Message\ServerRequest;
use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class VerifiedExternalIdentityFoundationCest
{
    public function verifiedIdentityLifecycleUsesAccountAndExistingSessionFlow(FunctionalTester $tester): void
    {
        $container = $this->container();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $suffix = bin2hex(random_bytes(6));
            $provider = 'miniapp-' . $suffix;
            $otherProvider = 'miniapp-other-' . $suffix;
            $this->provider($db, $provider);
            $this->provider($db, $otherProvider);
            $accountA = $this->account($db, 'verified identity A');
            $accountB = $this->account($db, 'verified identity B');
            $sessionA = $this->session($db, $accountA);
            $sessionB = $this->session($db, $accountB);
            $subject = 'subject-' . bin2hex(random_bytes(8));
            $identity = new VerifiedExternalIdentity($provider, $subject);
            $service = $container->get(VerifiedExternalIdentityService::class);
            $context = $container->get(ClientContextResolver::class)->resolve(new ServerRequest(
                serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
                headers: ['User-Agent' => 'verified external identity test'],
            ));

            $linked = $service->linkExternalIdentity($accountA, $identity, $sessionA, $context);
            assertSame($accountA, $linked->accountId);
            assertSame($linked->id, $service->linkExternalIdentity($accountA, $identity, $sessionA, $context)->id);
            assertSame($accountA, $service->findAccountByExternalIdentity($identity));

            try {
                $service->linkExternalIdentity($accountA, new VerifiedExternalIdentity($provider, 'subject-other'), $sessionA, $context);
                $tester->fail('A second subject for the same account/provider must conflict.');
            } catch (\App\Core\Shared\Application\ConflictException) {
                // Expected provider/account ownership conflict.
            }

            try {
                $service->linkExternalIdentity($accountB, $identity, $sessionB, $context);
                $tester->fail('A verified identity must not move between accounts.');
            } catch (\App\Core\Shared\Application\ConflictException) {
                // Expected: the database unique provider/subject relationship is authoritative.
            }

            $secondProviderIdentity = new VerifiedExternalIdentity($otherProvider, $subject);
            assertSame($accountA, $service->linkExternalIdentity($accountA, $secondProviderIdentity, $sessionA, $context)->accountId);
            $rawCount = (int) $db->createCommand(
                'SELECT count(*) FROM core.auth_external_identities WHERE provider_subject = :subject',
                [':subject' => $subject],
            )->queryScalar();
            assertSame(2, $rawCount);

            $this->password($db, $accountA);
            $result = $service->authenticate($identity, $context)->toArray();
            assertSame('authenticated', $result['status']);
            assertSame('external', $db->createCommand(
                'SELECT authentication_method FROM core.auth_sessions WHERE id = CAST(:id AS uuid)',
                [':id' => $result['sessionId']],
            )->queryScalar());
            $service->unlinkExternalIdentity($accountA, $identity, $sessionA, $context);
            assertSame(null, $service->findAccountByExternalIdentity($identity));

            $audit = (string) $db->createCommand(
                "SELECT coalesce(string_agg(metadata::text, ' '), '') FROM core.audit_events WHERE actor_account_id = CAST(:account AS uuid)",
                [':account' => $accountA],
            )->queryScalar();
            assertTrue(!str_contains($audit, $subject));

            try {
                $service->linkExternalIdentity('00000000-0000-4000-8000-000000000001', $identity, $sessionA, $context);
                $tester->fail('A missing account must be rejected.');
            } catch (ExternalAuthenticationException) {
                // Expected.
            }
        } finally {
            $transaction->rollBack();
        }
    }

    public function unlinkNeverRemovesTheLastAuthenticationMethodAndUnknownLoginIsAudited(FunctionalTester $tester): void
    {
        $container = $this->container();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $provider = 'miniapp-' . bin2hex(random_bytes(6));
            $this->provider($db, $provider);
            $account = $this->account($db, 'external only');
            $session = $this->session($db, $account);
            $identity = new VerifiedExternalIdentity($provider, 'external-only-' . bin2hex(random_bytes(8)));
            $service = $container->get(VerifiedExternalIdentityService::class);
            $context = $container->get(ClientContextResolver::class)->resolve(new ServerRequest());
            $service->linkExternalIdentity($account, $identity, $session, $context);

            try {
                $service->unlinkExternalIdentity($account, $identity, $session, $context);
                $tester->fail('The final usable authentication method must be retained.');
            } catch (ExternalIdentityLastMethodException) {
                // Expected.
            }

            $unknown = new VerifiedExternalIdentity($provider, 'unknown-' . bin2hex(random_bytes(8)));
            try {
                $service->authenticate($unknown, $context);
                $tester->fail('Unknown identities must not provision accounts.');
            } catch (ExternalAuthenticationException) {
                // Expected.
            }
            $failed = (string) $db->createCommand(
                "SELECT metadata::text FROM core.audit_events WHERE action = 'auth.external_login.failed' ORDER BY id DESC LIMIT 1",
            )->queryScalar();
            assertTrue(!str_contains($failed, $unknown->externalUserId));
        } finally {
            $transaction->rollBack();
        }
    }

    private function container(): ContainerInterface
    {
        return (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
    }

    private function provider(ConnectionInterface $db, string $code): void
    {
        $db->createCommand(
            "INSERT INTO core.auth_providers (code, protocol, display_name, enabled, allow_login) VALUES (:code, 'mini_app', :code, true, true)",
            [':code' => $code],
        )->execute();
    }

    private function account(ConnectionInterface $db, string $label): string
    {
        $person = (string) $db->createCommand(
            "INSERT INTO core.persons (given_name, family_name) VALUES ('Verified', 'Identity') RETURNING id::text",
        )->queryScalar();
        return (string) $db->createCommand(
            "INSERT INTO core.accounts (person_id, account_type, status, label) VALUES (CAST(:person AS uuid), 'human', 'active', :label) RETURNING id::text",
            [':person' => $person, ':label' => $label],
        )->queryScalar();
    }

    private function password(ConnectionInterface $db, string $accountId): void
    {
        $db->createCommand(
            "INSERT INTO core.auth_password_credentials (account_id, password_hash, password_algorithm) VALUES (CAST(:account AS uuid), :hash, 'argon2id')",
            [':account' => $accountId, ':hash' => password_hash('functional password', PASSWORD_ARGON2ID)],
        )->execute();
    }

    private function session(ConnectionInterface $db, string $accountId): string
    {
        return (string) $db->createCommand(<<<'SQL'
            INSERT INTO core.auth_sessions (account_id, session_token_hash, refresh_token_hash, security_stamp,
                authentication_method, client_type, access_expires_at, expires_at)
            SELECT id, decode(:a, 'hex'), decode(:r, 'hex'), security_stamp, 'password', 'test',
                now() + interval '15 minutes', now() + interval '1 hour'
            FROM core.accounts WHERE id = CAST(:id AS uuid) RETURNING id::text
            SQL, [':id' => $accountId, ':a' => hash('sha256', random_bytes(32)), ':r' => hash('sha256', random_bytes(32))])->queryScalar();
    }
}
