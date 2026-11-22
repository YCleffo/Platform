<?php

declare(strict_types=1);

use App\Api\Shared\ResponseFactory;
use App\Core\Internationalization\Application\SupportedLocales;
use App\Core\Internationalization\Application\TranslationService;
use App\DeviceManagement\Application\DeviceService;
use App\DeviceManagement\Infrastructure\PostgresDeviceStore;
use App\Storage\Application\StorageService;
use App\Tests\Storage02\ObservedS3Objects;

require_once dirname(__DIR__) . '/Storage02/ObservedS3Objects.php';
use App\Storage\Infrastructure\PostgresStorageStore;
use HttpSoft\Message\ResponseFactory as PsrResponseFactory;
use Yiisoft\DataResponse\Formatter\JsonFormatter;
use Yiisoft\DataResponse\ResponseFactory\JsonResponseFactory;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\RequestProvider\RequestProvider;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

/**
 * Narrow HTTP integration wiring. Uses production services and actions; teacher
 * authentication, audit delivery, privacy enforcement and the app-wide pipeline
 * are outside this agent-protocol scenario and have independent tests.
 * Never mount this router as a production web entry point.
 *
 * @return array<string,mixed>
 */
function classroomHttpRuntime(): array
{
    if (getenv('CLASSROOM_HTTP_TEST') !== '1' || getenv('DB_NAME') !== 'classroom02_test') {
        throw new RuntimeException('The classroom HTTP harness requires the isolated classroom02_test database.');
    }
    $root = dirname(__DIR__, 2);
    /** @var Composer\Autoload\ClassLoader $loader */
    $loader = require $root . '/vendor/autoload.php';
    $loader->addPsr4('App\\', $root . '/src', true);
    App\Environment::prepare();
    $container = (new HttpApplicationRunner(rootPath: $root, environment: App\Environment::appEnv()))->getContainer();
    $db = $container->get(ConnectionInterface::class);
    if ($db->createCommand('SELECT current_database()')->queryScalar() !== 'classroom02_test') {
        throw new RuntimeException('Unexpected database; refusing HTTP fixture operations.');
    }
    $runtime = getenv('CLASSROOM_HTTP_RUNTIME') ?: '/classroom-runtime';
    if (!is_dir($runtime)) {
        throw new RuntimeException('Create the dedicated HTTP harness runtime directory first.');
    }
    $deviceStore = new PostgresDeviceStore($db, allowTestAdapter: true);
    // Agent endpoints do not use teacher mutation or access ports. Resolve the
    // actual production ports, but keep this scenario's binary data isolated.
    $deviceService = new DeviceService(
        $deviceStore,
        $container->get(App\DeviceManagement\Application\Mutation::class),
        $container->get(App\Education\Application\EducationStore::class),
        $container->get(App\Education\Application\EducationAccess::class),
    );
    $files = new ObservedS3Objects();
    $storageStore = new PostgresStorageStore($db);
    $storageService = new StorageService(
        $storageStore,
        $files,
        $container->get(App\Storage\Application\Mutation::class),
        $deviceStore,
    );
    $requestProvider = new RequestProvider();
    $responses = new ResponseFactory(
        new JsonResponseFactory(new PsrResponseFactory(), new JsonFormatter()),
        $container->get(TranslationService::class),
        $container->get(SupportedLocales::class),
        $requestProvider,
    );
    return compact(
        'root',
        'container',
        'db',
        'runtime',
        'deviceStore',
        'deviceService',
        'files',
        'storageStore',
        'storageService',
        'requestProvider',
        'responses',
    );
}
