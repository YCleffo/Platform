<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Environment;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\TestReset;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class ExternalIdentityConcurrencyCest
{
    public function competingSameAccountLinksAreBothIdempotent(FunctionalTester $tester): void
    {
        $db = $this->db();
        try {
            [$provider, $accountA, , $sessionA] = $this->fixture($db);
            $subject = 'race-same-' . bin2hex(random_bytes(8));
            $this->installInsertDelay($db);
            $results = $this->compete($accountA, $accountA, $sessionA, $sessionA, $provider, $subject);
            assertTrue($results[0]['ok']);
            assertTrue($results[1]['ok']);
            assertSame($results[0]['id'], $results[1]['id']);
            assertSame(1, (int) $db->createCommand(
                'SELECT count(*) FROM core.auth_external_identities WHERE provider_subject = :subject',
                [':subject' => $subject],
            )->queryScalar());
        } finally {
            $this->removeInsertDelay($db);
            TestReset::reset($db);
        }
    }

    public function competingDifferentAccountLinksHaveOneOwnerAndOneConflict(FunctionalTester $tester): void
    {
        $db = $this->db();
        try {
            [$provider, $accountA, $accountB, $sessionA, $sessionB] = $this->fixture($db, true);
            $subject = 'race-different-' . bin2hex(random_bytes(8));
            $this->installInsertDelay($db);
            $results = $this->compete($accountA, $accountB, $sessionA, $sessionB, $provider, $subject);
            assertCount(1, array_filter($results, static fn(array $result): bool => $result['ok']));
            assertCount(1, array_filter($results, static fn(array $result): bool => !$result['ok'] && $result['error'] === 'conflict'));
            assertSame(1, (int) $db->createCommand(
                'SELECT count(*) FROM core.auth_external_identities WHERE provider_subject = :subject',
                [':subject' => $subject],
            )->queryScalar());
        } finally {
            $this->removeInsertDelay($db);
            TestReset::reset($db);
        }
    }

    /** @return array{string,string,?string,string,?string} */
    private function fixture(ConnectionInterface $db, bool $second = false): array
    {
        $provider = 'race-provider-' . bin2hex(random_bytes(5));
        $db->createCommand("INSERT INTO core.auth_providers (code, protocol, display_name, enabled) VALUES (:code, 'mini_app', :code, true)", [':code' => $provider])->execute();
        $account = $this->account($db);
        $session = $this->session($db, $account);
        $accountB = $second ? $this->account($db) : null;
        $sessionB = $accountB === null ? null : $this->session($db, $accountB);
        return [$provider, $account, $accountB, $session, $sessionB];
    }

    private function account(ConnectionInterface $db): string
    {
        $person = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Race', 'Identity') RETURNING id::text")->queryScalar();
        return (string) $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text", [':person' => $person])->queryScalar();
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

    private function installInsertDelay(ConnectionInterface $db): void
    {
        $db->createCommand(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.external_identity_insert_delay() RETURNS trigger AS $$
            BEGIN PERFORM pg_sleep(0.35); RETURN NEW; END;
            $$ LANGUAGE plpgsql;
            SQL)->execute();
        $db->createCommand('CREATE TRIGGER external_identity_insert_delay BEFORE INSERT ON core.auth_external_identities FOR EACH ROW EXECUTE FUNCTION public.external_identity_insert_delay()')->execute();
    }

    private function removeInsertDelay(ConnectionInterface $db): void
    {
        $db->createCommand('DROP TRIGGER IF EXISTS external_identity_insert_delay ON core.auth_external_identities')->execute();
        $db->createCommand('DROP FUNCTION IF EXISTS public.external_identity_insert_delay()')->execute();
    }

    /** @return list<array{ok:bool,id?:string,error?:string}> */
    private function compete(string $accountA, string $accountB, string $sessionA, ?string $sessionB, string $provider, string $subject): array
    {
        $workers = [];
        foreach ([[$accountA, $sessionA], [$accountB, $sessionB]] as [$account, $session]) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/external-identity-race-worker.php', $account, $provider, $subject, $session], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Could not start external identity race worker.');
            }
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0 || !is_string($stdout)) {
                throw new RuntimeException('External identity race worker failed: ' . $stderr);
            }
            $result = json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($result)) {
                throw new RuntimeException('External identity race worker returned invalid JSON.');
            }
            $results[] = $result;
        }
        return $results;
    }

    private function db(): ConnectionInterface
    {
        return (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer()->get(ConnectionInterface::class);
    }
}
