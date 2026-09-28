<?php

declare(strict_types=1);

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Core\Privacy\Application\ProcessingBlockService;
use App\Environment;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

require dirname(__DIR__) . '/bootstrap.php';

[$script, $operation, $accountId, $personId, $targetId] = $argv;
$container = (new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv()))->getContainer();
$actor = new AuthenticatedIdentity($accountId, $personId,
    '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee', ['core.superadmin'], [], null, null);
try {
    $result = match ($operation) {
        'transition' => $container->get(DataSubjectRequestService::class)->transition($actor, $targetId, 'in_review', null),
        'extend' => $container->get(DataSubjectRequestService::class)->extendDeadline($actor, $targetId, 'access_response', 'complex_review')->format(DATE_ATOM),
        'create' => $container->get(ProcessingBlockService::class)->createBlock($actor, $personId,
            'subject', null, null, null, 'accuracy_review', $targetId),
        'release' => $container->get(ProcessingBlockService::class)->releaseBlock($actor, $targetId, 'review_complete'),
        default => throw new DomainException('Unknown operation.'),
    };
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR), "\n";
} catch (DomainException $exception) {
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR), "\n";
}
