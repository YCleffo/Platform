<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\CreateSessionCommand;
use App\Core\Auth\Application\ListOwnSessions;
use App\Core\Auth\Application\RevokeOtherSessions;
use App\Core\Auth\Application\RevokeOwnSession;
use App\Core\Auth\Application\SessionActivityService;
use App\Core\Auth\Application\SessionRepository;
use App\Core\Client\Domain\ClientContext;
use App\Core\Client\Domain\ClientHints;
use App\Core\Client\Domain\DeviceInfo;
use App\Core\Geo\Domain\GeoLocation;
use App\Core\Internationalization\Application\LocaleContext;
use App\Core\Internationalization\Domain\Locale;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Time\Application\ClockInterface;
use App\Core\Time\Application\ResolvedTimeZone;
use App\Core\Time\Domain\TimeZone;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class SessionLifecycleCest
{
    public function persistsMetadataThrottlesTouchAndRevokes(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();

        try {
            $account = $db->createCommand(
                <<<'SQL'
                INSERT INTO core.accounts (account_type, label)
                VALUES ('service', 'session lifecycle integration')
                RETURNING id::text AS id, security_stamp::text AS security_stamp
                SQL,
            )->queryOne();
            assertTrue(is_array($account));
            $accountId = (string) $account['id'];
            $securityStamp = (string) $account['security_stamp'];
            $sessions = $container->get(SessionRepository::class);

            $current = $sessions->storeSession($this->command($accountId, $securityStamp, 'current'));
            $other = $sessions->storeSession($this->command($accountId, $securityStamp, 'other'));

            assertSame('Chrome', $current->browserName);
            assertSame('en-US', $current->clientLocale);
            assertSame('US', $current->geoCountryCode);
            assertCount(2, $sessions->findActiveForAccount($accountId));
            assertNotNull($sessions->findForAccount($current->id, $accountId));

            $ownSessions = $container->get(ListOwnSessions::class)->execute($accountId, $current->id);
            assertCount(2, $ownSessions);
            assertTrue($ownSessions[0]->current || $ownSessions[1]->current);
            $safePayload = json_encode($ownSessions[0]->toArray(), JSON_THROW_ON_ERROR);
            assertTrue(!str_contains($safePayload, 'sessionTokenHash'));
            assertTrue(!str_contains($safePayload, 'refreshTokenHash'));
            assertTrue(!str_contains($safePayload, 'securityStamp'));
            assertTrue(!str_contains($safePayload, 'fingerprintHashHex'));

            $clock = new FixedClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $activity = new SessionActivityService(
                $sessions,
                $clock,
                $container->get(SecurityAuditService::class),
                300,
            );
            $notTouched = $activity->touch($current->id, $accountId, $this->context());
            assertNotNull($notTouched);
            assertFalse($notTouched->updated);

            $db->createCommand(
                "UPDATE core.auth_sessions SET last_seen_at = now() - interval '10 minutes' WHERE id = CAST(:id AS uuid)",
                [':id' => $current->id],
            )->execute();
            $touched = $activity->touch($current->id, $accountId, $this->context(
                ipAddress: '8.8.8.8',
                fingerprintHashHex: str_repeat('cd', 32),
                browserName: 'Firefox',
                osName: 'Linux',
            ));
            assertNotNull($touched);
            assertTrue($touched->updated);
            assertTrue($touched->ipChanged);
            assertTrue($touched->deviceChanged);

            assertSame(1, $container->get(RevokeOtherSessions::class)->execute(
                $accountId,
                $current->id,
                $this->context(),
            ));
            assertNotNull($sessions->findForAccount($other->id, $accountId)?->revokedAt);
            assertCount(1, $sessions->findActiveForAccount($accountId));
            assertTrue($container->get(RevokeOwnSession::class)->execute(
                $accountId,
                $current->id,
                $this->context(),
            ));
            assertCount(0, $sessions->findActiveForAccount($accountId));

            $actions = $db->createCommand(
                'SELECT array_agg(action ORDER BY id) FROM core.audit_events WHERE actor_account_id = CAST(:id AS uuid)',
                [':id' => $accountId],
            )->queryScalar();
            assertSame(
                '{auth.session.ip_changed,auth.session.device_changed,auth.session.revoked_all,auth.session.revoked}',
                $actions,
            );
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }
    }

    private function command(string $accountId, string $securityStamp, string $suffix): CreateSessionCommand
    {
        return new CreateSessionCommand(
            accountId: $accountId,
            sessionTokenHashHex: hash('sha256', 'session-' . $suffix . random_bytes(8)),
            refreshTokenHashHex: null,
            securityStamp: $securityStamp,
            authenticationMethod: 'password',
            mfaVerifiedAt: null,
            clientType: 'browser',
            deviceName: 'Workstation',
            ipAddress: '198.51.100.20',
            userAgent: 'Integration User Agent',
            accessExpiresAt: (new DateTimeImmutable('+15 minutes'))->format(DATE_ATOM),
            expiresAt: (new DateTimeImmutable('+1 hour'))->format(DATE_ATOM),
            browserName: 'Chrome',
            browserVersion: '131.0.0.0',
            osName: 'Windows',
            osVersion: '11',
            deviceType: 'desktop',
            deviceModel: null,
            clientLocale: 'en-US',
            clientTimezone: 'America/New_York',
            clientInstanceId: '018f2892-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
            deviceFingerprintHashHex: str_repeat('ab', 32),
            geoCountryCode: 'US',
            geoRegion: 'New York',
            geoCity: 'New York',
            geoTimezone: 'America/New_York',
        );
    }

    private function context(
        string $ipAddress = '198.51.100.20',
        string $fingerprintHashHex = '',
        string $browserName = 'Chrome',
        string $osName = 'Windows',
    ): ClientContext {
        $fingerprintHashHex = $fingerprintHashHex === '' ? str_repeat('ab', 32) : $fingerprintHashHex;
        $device = new DeviceInfo(
            $browserName,
            '131.0.0.0',
            $osName,
            '11',
            'desktop',
            null,
            'browser',
            'Integration User Agent',
        );

        return new ClientContext(
            ipAddress: $ipAddress,
            userAgent: 'Integration User Agent',
            acceptLanguage: 'en-US',
            locale: new LocaleContext(new Locale('en-US'), 'account'),
            reportedTimezone: 'America/New_York',
            timezone: new ResolvedTimeZone(new TimeZone('America/New_York'), 'user'),
            clientHints: new ClientHints(null, 'Windows', false),
            device: $device,
            clientInstanceId: '018f2892-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
            fingerprintHashHex: $fingerprintHashHex,
            geoLocation: new GeoLocation('US', 'United States', 'New York', 'New York', 'America/New_York'),
            requestId: '018f2892-aaaa-7aaa-8aaa-bbbbbbbbbbbb',
        );
    }
}

final readonly class FixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
