<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\AccountSettings\Application\AccountSettingsRepository;
use App\Core\AccountSettings\Application\AccountSettingsService;
use App\Core\Client\Domain\ClientContext;
use App\Core\Client\Domain\ClientHints;
use App\Core\Client\Domain\DeviceInfo;
use App\Core\Geo\Domain\GeoLocation;
use App\Core\Internationalization\Application\LocaleContext;
use App\Core\Internationalization\Domain\Locale;
use App\Core\Time\Application\ResolvedTimeZone;
use App\Core\Time\Domain\TimeZone;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class AccountSettingsCest
{
    public function repositoryServiceAuditAndCascadeLifecycle(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        ))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $repository = $container->get(AccountSettingsRepository::class);
        $service = $container->get(AccountSettingsService::class);
        $accountId = $this->createAccount($db, 'settings lifecycle');

        assertNull($repository->find($accountId));
        $defaults = $service->get($accountId);
        assertFalse($defaults->settings['privacy']['show_online_status']);
        assertNull($repository->find($accountId)); // GET remains lazy/read-only.

        $first = $service->patch($accountId, ['privacy' => [
            'show_online_status' => true,
        ]], $this->context());
        assertTrue($first->settings['privacy']['show_online_status']);
        assertFalse(isset($first->settings['privacy']['profile_visibility']));
        assertSame(2, $repository->find($accountId)?->schemaVersion);

        $service->patch($accountId, ['privacy' => ['show_online_status' => null]], $this->context());
        assertFalse($service->get($accountId)->settings['privacy']['show_online_status']);

        $audit = $db->createCommand(
            "SELECT before_data::text, after_data::text, metadata::text FROM core.audit_events WHERE action = 'core.account_settings.updated' AND resource_id = :id ORDER BY occurred_at DESC, id DESC LIMIT 1",
            [':id' => $accountId],
        )->queryOne();
        assertTrue(is_array($audit));
        assertTrue(str_contains((string) $audit['metadata'], 'privacy.show_online_status'));
        assertFalse(str_contains((string) $audit['metadata'], 'profile_visibility'));

        $db->createCommand('DELETE FROM core.accounts WHERE id = CAST(:id AS uuid)', [':id' => $accountId])->execute();
        assertNull($repository->find($accountId));
    }

    public function concurrentPatchesPreserveBothWriters(FunctionalTester $tester): void
    {
        $container = (new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        ))->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $service = $container->get(AccountSettingsService::class);
        $accountId = $this->createAccount($db, 'settings concurrency');
        $transaction = $db->beginTransaction();
        $service->patch($accountId, ['notifications' => ['browser' => false]], $this->context());

        $command = [PHP_BINARY, dirname(__DIR__) . '/Support/account-settings-race-worker.php', $accountId];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        assertTrue(is_resource($process));
        usleep(250_000);
        assertTrue(proc_get_status($process)['running']); // Worker is blocked by account FOR UPDATE.
        $transaction->commit();
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        assertSame(0, proc_close($process), (string) $stdout . (string) $stderr);

        $effective = $service->get($accountId)->settings;
        assertFalse($effective['notifications']['browser']);
        assertFalse($effective['notifications']['sound']);
        $db->createCommand('DELETE FROM core.accounts WHERE id = CAST(:id AS uuid)', [':id' => $accountId])->execute();
    }

    private function createAccount(ConnectionInterface $db, string $label): string
    {
        $id = $db->createCommand(
            "INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', :label) RETURNING id::text",
            [':label' => $label],
        )->queryScalar();
        if (!is_string($id)) {
            throw new \RuntimeException('Account fixture could not be created.');
        }
        return $id;
    }

    private function context(): ClientContext
    {
        return new ClientContext(
            ipAddress: '127.0.0.1',
            userAgent: 'Settings Functional Test',
            acceptLanguage: 'en-US',
            locale: new LocaleContext(new Locale('en-US'), 'test'),
            reportedTimezone: 'UTC',
            timezone: new ResolvedTimeZone(new TimeZone('UTC'), ResolvedTimeZone::DEFAULT),
            clientHints: new ClientHints(null, 'Test', false),
            device: new DeviceInfo('Test', '1', 'Test', '1', 'desktop', null, 'test', 'Settings Functional Test'),
            clientInstanceId: null,
            fingerprintHashHex: null,
            geoLocation: new GeoLocation(null, null, null, null, null),
            requestId: '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
        );
    }
}
