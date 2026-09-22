<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Client\Domain\ClientContext;
use App\Core\Client\Domain\ClientHints;
use App\Core\Client\Domain\DeviceInfo;
use App\Core\Geo\Domain\GeoLocation;
use App\Core\Internationalization\Application\LocaleContext;
use App\Core\Internationalization\Domain\Locale;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Time\Application\ResolvedTimeZone;
use App\Core\Time\Domain\TimeZone;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class SecurityAuditCest
{
    public function recordsSessionHistoryWithoutSecrets(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();

        try {
            $accountId = $db->createCommand(
                "INSERT INTO core.accounts (account_type, label) VALUES ('service', 'security audit') RETURNING id::text",
            )->queryScalar();
            assertTrue(is_string($accountId));
            $sessionId = '018f2892-aaaa-7aaa-8aaa-cccccccccccc';
            $audit = $container->get(SecurityAuditService::class);
            $context = $this->context();
            $audit->sessionCreated($accountId, $sessionId, $context);
            $audit->sessionIpChanged($accountId, $sessionId, '198.51.100.10', $context);

            $rows = $db->createCommand(
                <<<'SQL'
                SELECT action, host(ip_address) AS ip_address, metadata::text AS metadata
                FROM core.audit_events
                WHERE actor_account_id = CAST(:account_id AS uuid)
                ORDER BY id
                SQL,
                [':account_id' => $accountId],
            )->queryAll();
            assertSame(2, count($rows));
            assertSame('auth.session.created', $rows[0]['action']);
            assertSame('auth.session.ip_changed', $rows[1]['action']);
            assertSame('8.8.8.8', $rows[1]['ip_address']);
            assertTrue(!str_contains((string) $rows[0]['metadata'], 'token'));
            assertTrue(!str_contains((string) $rows[0]['metadata'], 'secret'));
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }
    }

    private function context(): ClientContext
    {
        return new ClientContext(
            ipAddress: '8.8.8.8',
            userAgent: 'Security Audit Test',
            acceptLanguage: 'en-US',
            locale: new LocaleContext(new Locale('en-US'), 'account'),
            reportedTimezone: 'Europe/Helsinki',
            timezone: new ResolvedTimeZone(new TimeZone('Europe/Helsinki'), 'client'),
            clientHints: new ClientHints(null, 'Windows', false),
            device: new DeviceInfo('Chrome', '131', 'Windows', '11', 'desktop', null, 'browser', 'Security Audit Test'),
            clientInstanceId: '018f2892-aaaa-7aaa-8aaa-dddddddddddd',
            fingerprintHashHex: str_repeat('ab', 32),
            geoLocation: new GeoLocation('FI', 'Finland', 'Uusimaa', 'Helsinki', 'Europe/Helsinki'),
            requestId: '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
        );
    }
}
