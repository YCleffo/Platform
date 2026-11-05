<?php

declare(strict_types=1);

use App\Core\Privacy\Application\ProcessingInformationDefinition;
use App\Core\Privacy\Application\ProcessingInformationRegistry;
use App\Core\Shared\Infrastructure\TestRuntimeGuard;
use Yiisoft\Db\Connection\ConnectionInterface;

if (getenv('CORE_PRIVACY_BROWSER_FIXTURE') !== '1') return [];

return [ProcessingInformationRegistry::class => static function (ConnectionInterface $db): ProcessingInformationRegistry {
    TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
    $proof = getenv('E2E_ISOLATION_PROOF');
    if (!is_string($proof) || strlen($proof) < 32) throw new RuntimeException('A privacy browser nonce is required.');
    return new ProcessingInformationRegistry(array_map(
        static fn(string $purpose): ProcessingInformationDefinition => new ProcessingInformationDefinition(
            $purpose, ['automated'], ['subject'], 'test://privacy/rights', ['access_control', 'audit'],
            'Synthetic rights procedure for an isolated browser fixture.',
            ['access_control' => 'Synthetic access control fixture.', 'audit' => 'Synthetic audit fixture.']),
        ['test.processing', 'test.required'],
    ));
}];
