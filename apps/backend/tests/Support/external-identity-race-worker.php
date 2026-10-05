<?php

declare(strict_types=1);

use App\Core\Auth\Application\VerifiedExternalIdentity;
use App\Core\Auth\Application\VerifiedExternalIdentityService;
use App\Core\Client\Application\ClientContextResolver;
use App\Core\Shared\Application\ConflictException;
use App\Environment;
use HttpSoft\Message\ServerRequest;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

require dirname(__DIR__) . '/bootstrap.php';

[, $accountId, $provider, $subject, $sessionId] = $argv;
$container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
try {
    $context = $container->get(ClientContextResolver::class)->resolve(new ServerRequest(
        serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        headers: ['User-Agent' => 'external identity race worker'],
    ));
    $identity = $container->get(VerifiedExternalIdentityService::class)->linkExternalIdentity(
        $accountId,
        new VerifiedExternalIdentity($provider, $subject),
        $sessionId,
        $context,
    );
    echo json_encode(['ok' => true, 'id' => $identity->id], JSON_THROW_ON_ERROR), "\n";
} catch (ConflictException) {
    echo json_encode(['ok' => false, 'error' => 'conflict'], JSON_THROW_ON_ERROR), "\n";
}
