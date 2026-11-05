<?php

declare(strict_types=1);

use App\Core\Auth\Application\InvalidCredentialsException;
use App\Core\Auth\Application\PasskeyService;
use App\Core\Client\Application\ClientContextResolver;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use App\Environment;
use HttpSoft\Message\ServerRequest;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

require dirname(__DIR__) . '/bootstrap.php';

try {
    $input = json_decode((string) fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new RuntimeException('Invalid race input.');
    }
    $container = (new HttpApplicationRunner(
        rootPath: dirname(__DIR__, 2),
        environment: Environment::appEnv(),
    ))->getContainer();
    $db = $container->get(ConnectionInterface::class);
    TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
    $db->createCommand("SET statement_timeout = '15s'")->execute();
    $pid = $db->createCommand('SELECT pg_backend_pid()')->queryScalar();
    $context = $container->get(ClientContextResolver::class)->resolve(new ServerRequest(
        serverParams: ['REMOTE_ADDR' => $input['ip'], 'HTTP_USER_AGENT' => $input['userAgent']],
        uri: 'http://localhost:5173/api/auth/passkeys',
        method: 'POST',
    ));
    echo 'READY:', $pid, "\n";
    flush();
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Missing start barrier.');
    }
    $service = $container->get(PasskeyService::class);
    try {
        if ($input['operation'] === 'register') {
            $service->completeRegistration($input['account'], $input['session'], $input['response'], 'Race key', $context);
        } elseif ($input['operation'] === 'authenticate') {
            $service->completeAuthentication($input['response'], $context);
        } else {
            throw new RuntimeException('Unknown race operation.');
        }
        echo '{"result":"won"}', "\n";
    } catch (InvalidArgumentException $error) {
        if ($input['operation'] !== 'register'
            || !in_array($error->getMessage(), ['Registration challenge already consumed or expired.',
                'Invalid or expired registration challenge.'], true)) {
            throw $error;
        }
        echo '{"result":"rejected"}', "\n";
    } catch (InvalidCredentialsException $error) {
        if ($input['operation'] !== 'authenticate') {
            throw $error;
        }
        echo '{"result":"rejected"}', "\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Passkey race worker failed: ' . $error::class . "\n");
    exit(1);
}
