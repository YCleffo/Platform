<?php

declare(strict_types=1);

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
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

require dirname(__DIR__) . '/bootstrap.php';

$accountId = $argv[1] ?? throw new RuntimeException('Account ID is required.');
$container = (new HttpApplicationRunner(
    rootPath: dirname(__DIR__, 2),
    environment: Environment::appEnv(),
))->getContainer();
$context = new ClientContext(
    ipAddress: '127.0.0.1',
    userAgent: 'Settings Race Worker',
    acceptLanguage: 'en-US',
    locale: new LocaleContext(new Locale('en-US'), 'test'),
    reportedTimezone: 'UTC',
    timezone: new ResolvedTimeZone(new TimeZone('UTC'), ResolvedTimeZone::DEFAULT),
    clientHints: new ClientHints(null, 'Test', false),
    device: new DeviceInfo('Test', '1', 'Test', '1', 'desktop', null, 'test', 'Settings Race Worker'),
    clientInstanceId: null,
    fingerprintHashHex: null,
    geoLocation: new GeoLocation(null, null, null, null, null),
    requestId: '018f2892-bbbb-7bbb-8bbb-eeeeeeeeeeee',
);
$container->get(AccountSettingsService::class)->patch(
    $accountId,
    ['notifications' => ['sound' => false]],
    $context,
);
